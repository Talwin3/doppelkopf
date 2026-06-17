import { Controller } from '@hotwired/stimulus'
import { SoundEngine } from '../sound_engine.js'

export default class extends Controller {
  static targets = ['zustand', 'fehler', 'dranIndikator', 'spielGewonnen', 'spielVerloren', 'soundToggle']
  static values = {
    mercureUrl: String,
    topic: String,
    zustandUrl: String,
    meinSitzplatz: Number,
  }

  connect() {
    this.sound = new SoundEngine()
    this._warMeinZug = false
    this._letzteEigeneSpieleZeit = 0

    this._soundToggleAktualisieren()

    const url = new URL(this.mercureUrlValue)
    url.searchParams.append('topic', this.topicValue)

    this.eventSource = new EventSource(url.toString())
    this.eventSource.onmessage = (event) => {
      const daten = JSON.parse(event.data)
      this.aktualisieren(daten)
    }
    this.eventSource.onerror = () => {}
  }

  disconnect() {
    this.eventSource?.close()
  }

  async aktualisieren(daten) {
    // Sofort-Sounds (bevor DOM-Update)
    this._spieleEreignisSound(daten)

    try {
      const response = await fetch(this.zustandUrlValue, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (response.ok) {
        this.zustandTarget.innerHTML = await response.text()
      }

      // Zustands-Sounds (nach DOM-Update)
      this._spieleZustandsSound(daten)

      if (daten.typ === 'SPIEL_BEENDET') {
        this.eventSource?.close()
      }
    } catch {
      // Nächstes Event versucht es erneut
    }
  }

  // ── Spielaktionen ──────────────────────────────────────────────────────

  async karteAusspielen(event) {
    event.preventDefault()
    const ok = await this.#postForm(event.currentTarget, 'Ungültiger Zug.')
    if (ok) {
      this._letzteEigeneSpieleZeit = Date.now()
      this.sound.play('karte_eigene')
    }
  }

  async ansagen(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Ansage nicht möglich.')
  }

  async vorbehalt(event) {
    event.preventDefault()
    const ok = await this.#postForm(event.currentTarget, 'Vorbehalt nicht möglich.')
    if (ok) {
      this.sound.play('vorbehalt')
    }
  }

  async nachSpielVerlassen(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Aktion nicht möglich.')
  }

  async regelwerkSpeichern(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Regelwerk konnte nicht gespeichert werden.')
  }

  async autoStartToggle(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Auto-Start konnte nicht geändert werden.')
  }

  soundUmschalten() {
    const aktiv = this.sound.toggle()
    this._soundToggleAktualisieren(aktiv)
    // AudioContext entsperren durch User-Geste
    this.sound.play('mein_zug')
  }

  // ── Fehleranzeige ──────────────────────────────────────────────────────

  fehlerZeigen(text) {
    if (this.hasFehlerTarget) {
      this.fehlerTarget.textContent = text
      this.fehlerTarget.classList.remove('hidden')
      setTimeout(() => this.fehlerHide(), 3000)
    }
  }

  fehlerHide() {
    if (this.hasFehlerTarget) {
      this.fehlerTarget.classList.add('hidden')
    }
  }

  // ── Private Helfer ─────────────────────────────────────────────────────

  async #postForm(form, standardFehler) {
    this.fehlerHide()
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
      })
      const json = await response.json()
      if (!response.ok) {
        this.fehlerZeigen(json.fehler ?? standardFehler)
        return false
      }
      return true
    } catch {
      this.fehlerZeigen('Verbindungsfehler.')
      return false
    }
  }

  _spieleEreignisSound(daten) {
    switch (daten.typ) {
      case 'KARTE_GESPIELT': {
        // Eigene Karte: Sound bereits in karteAusspielen() gespielt
        const eigenesSpiel = (Date.now() - this._letzteEigeneSpieleZeit) < 600
        if (!eigenesSpiel) {
          this.sound.play('karte')
        }
        break
      }
      case 'STICH_ABGESCHLOSSEN': {
        const eigenesSpiel = (Date.now() - this._letzteEigeneSpieleZeit) < 600
        if (!eigenesSpiel) {
          this.sound.play('karte') // 4. Karte landet auch auf dem Tisch
        }
        const ichGewonnen = this.meinSitzplatzValue > 0
          && daten.gewinnerSitzplatz === this.meinSitzplatzValue
        this.sound.play(ichGewonnen ? 'stich_gewonnen' : 'stich')
        break
      }
      case 'ANSAGE_GEMACHT':
        this.sound.play('ansage')
        break
      case 'VORBEHALT_DEKLARIERT':
        // Sound wird nur für eigene Deklaration in vorbehalt() gespielt
        break
      case 'KARTEN_AUSGETEILT':
        this.sound.play('karten_ausgeteilt')
        break
      case 'SPIEL_GESTARTET':
        this.sound.play('karten_ausgeteilt')
        break
    }
  }

  _spieleZustandsSound(daten) {
    // "Mein Zug"-Sound nur bei Übergang von nicht-dran → dran
    const istMeinZug = this.hasDranIndikatorTarget
    if (istMeinZug && !this._warMeinZug) {
      this.sound.play('mein_zug')
    }
    this._warMeinZug = istMeinZug

    // Gewonnen/Verloren nach DOM-Update
    if (daten.typ === 'SPIEL_BEENDET') {
      if (this.hasSpielGewonnenTarget) {
        this.sound.play('gewonnen')
      } else if (this.hasSpielVerlorenTarget) {
        this.sound.play('verloren')
      }
    }
  }

  _soundToggleAktualisieren(aktiv = this.sound.enabled) {
    if (!this.hasSoundToggleTarget) return
    const btn = this.soundToggleTarget
    btn.textContent = aktiv ? '🔊' : '🔇'
    btn.title = aktiv ? 'Sound aus' : 'Sound an'
    btn.classList.toggle('opacity-40', !aktiv)
  }
}
