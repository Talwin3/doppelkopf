import { Controller } from '@hotwired/stimulus'
import { SoundEngine } from '../sound_engine.js'
import { toast } from '../toast.js'
import { confirmDialog } from '../dialog.js'

export default class extends Controller {
  static targets = [
    'zustand', 'dranIndikator', 'spielGewonnen', 'spielVerloren', 'soundToggle',
    'abrechnungDialog', 'abrechnungInhalt', 'vorbehaltDialog', 'vorbehaltInhalt',
  ]
  static values = {
    mercureUrl: String,
    topic: String,
    zustandUrl: String,
    meinSitzplatz: Number,
    phase: String,
  }

  connect() {
    this.sound = new SoundEngine()
    this._warMeinZug = false
    this._letzteEigeneSpieleZeit = 0
    // Abgeschlossenen Stich (inkl. vierter Karte) mindestens so lange zeigen,
    // bevor der nächste Zug die Tischmitte überschreibt.
    this._STICH_ANZEIGE_MS = 1500
    this._stichAbschlussGezeigtAm = null

    this._soundToggleAktualisieren()

    // Vorbehalts-Dialog beim Laden mitten in der Vorbehaltsrunde direkt öffnen.
    if (this.phaseValue === 'VORBEHALT' && this.hasVorbehaltDialogTarget && !this.vorbehaltDialogTarget.open) {
      this.vorbehaltDialogTarget.showModal()
    }

    if (this.mercureUrlValue && this.topicValue) {
      const url = new URL(this.mercureUrlValue)
      url.searchParams.append('topic', this.topicValue)

      this.eventSource = new EventSource(url.toString())
      this.eventSource.onmessage = (event) => {
        const daten = JSON.parse(event.data)
        // Chat läuft über dasselbe Topic, betrifft aber den Spielzustand nicht.
        if (daten.typ === 'CHAT_NACHRICHT') return
        this.aktualisieren(daten)
      }
      this.eventSource.onerror = () => {}
    }
  }

  disconnect() {
    this.eventSource?.close()
  }

  async aktualisieren(daten) {
    // Gerade abgeschlossenen Stich noch eine Mindestzeit stehen lassen,
    // bevor das nächste Event die Tischmitte (und die vierte Karte) ersetzt.
    if (this._stichAbschlussGezeigtAm !== null) {
      const rest = this._STICH_ANZEIGE_MS - (Date.now() - this._stichAbschlussGezeigtAm)
      this._stichAbschlussGezeigtAm = null
      if (rest > 0) {
        await new Promise((resolve) => setTimeout(resolve, rest))
      }
    }

    // Sofort-Sounds (bevor DOM-Update)
    this._spieleEreignisSound(daten)

    try {
      const response = await fetch(this.zustandUrlValue, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (response.ok) {
        const phase = this._verteileZustand(await response.text())
        this._dialogeSteuern(phase, daten)
      }

      // Zustands-Sounds (nach DOM-Update)
      this._spieleZustandsSound(daten)

      if (daten.typ === 'STICH_ABGESCHLOSSEN') {
        this._stichAbschlussGezeigtAm = Date.now()
      }
      // Verbindung bei SPIEL_BEENDET bewusst offen lassen: Das Topic ist
      // tischbezogen, daher kommt über dieselbe EventSource auch der Auto-Start
      // des nächsten Spiels (KARTEN_AUSGETEILT) an und der Zustand wird neu geladen.
    } catch {
      // Nächstes Event versucht es erneut
    }
  }

  // ── Zustands-Verteilung & Dialoge ──────────────────────────────────────

  /**
   * Verteilt die Antwort der Zustands-Route auf Tisch-Kulisse (#zustand) und die
   * Inhalte der persistenten Overlay-Dialoge. Die <dialog>-Elemente selbst werden
   * NICHT angefasst – nur ihr Inhalt –, damit ein offener Dialog beim Reload nicht
   * geschlossen wird. Gibt die aktuelle Phase zurück.
   */
  _verteileZustand(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html')
    const root = doc.querySelector('[data-zustand-root]')
    if (!root) {
      // Fallback: ganze Antwort als Kulisse einsetzen.
      this.zustandTarget.innerHTML = html
      return ''
    }

    const inhalt = (bereich) => root.querySelector(`template[data-bereich="${bereich}"]`)?.innerHTML ?? ''

    this.zustandTarget.innerHTML = inhalt('tisch')
    if (this.hasVorbehaltInhaltTarget) this.vorbehaltInhaltTarget.innerHTML = inhalt('vorbehalt')
    if (this.hasAbrechnungInhaltTarget) this.abrechnungInhaltTarget.innerHTML = inhalt('abrechnung')

    return root.dataset.phase ?? ''
  }

  /** Öffnet/schließt Vorbehalts- und Abrechnungs-Dialog passend zur Phase/zum Event. */
  _dialogeSteuern(phase, daten) {
    this.phaseValue = phase

    // Vorbehalts-Dialog folgt der Phase.
    if (phase === 'VORBEHALT') {
      if (this.hasVorbehaltDialogTarget && !this.vorbehaltDialogTarget.open) {
        this._abrechnungSchliessen() // keine zwei gestapelten Modals
        this.vorbehaltDialogTarget.showModal()
      }
    } else if (this.hasVorbehaltDialogTarget && this.vorbehaltDialogTarget.open) {
      this.vorbehaltDialogTarget.close()
    }

    // Abrechnung nur beim Live-Event SPIEL_BEENDET automatisch öffnen; sie bleibt
    // dann offen (auch über den Reload des nächsten Spiels), bis der Nutzer sie
    // schließt oder die nächste Vorbehaltsrunde beginnt.
    if (daten.typ === 'SPIEL_BEENDET' && this.hasAbrechnungDialogTarget
        && this.abrechnungInhaltTarget?.innerHTML.trim()) {
      if (!this.abrechnungDialogTarget.open) this.abrechnungDialogTarget.showModal()
    }
  }

  // ── Dialog-Aktionen ────────────────────────────────────────────────────

  abrechnungOeffnen() {
    if (this.hasAbrechnungDialogTarget && !this.abrechnungDialogTarget.open) {
      this.abrechnungDialogTarget.showModal()
    }
  }

  abrechnungSchliessen() {
    this._abrechnungSchliessen()
  }

  abrechnungBackdrop(event) {
    if (event.target === this.abrechnungDialogTarget) this._abrechnungSchliessen()
  }

  /** Verhindert das Schließen per ESC (cancel-Event) – für Pflicht-Dialoge. */
  dialogSchliessenVerhindern(event) {
    event.preventDefault()
  }

  _abrechnungSchliessen() {
    if (this.hasAbrechnungDialogTarget && this.abrechnungDialogTarget.open) {
      this.abrechnungDialogTarget.close()
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
    const form = event.currentTarget

    // Trägt das Formular eine Warnung (stille Hochzeit), erst rückfragen.
    // Der Text steht im Markup, damit hier keine Spiellogik dupliziert wird.
    if (form.dataset.warnung) {
      const weiter = await confirmDialog({
        title: form.dataset.warnungTitel || 'Bist du sicher?',
        text: form.dataset.warnung,
        bestaetigen: form.dataset.warnungBestaetigen || 'Ja, weiter',
        abbrechen: 'Zurück',
      })
      if (!weiter) return
    }

    const ok = await this.#postForm(form, 'Vorbehalt nicht möglich.')
    if (ok) {
      this.sound.play('vorbehalt')
    }
  }

  async armutAntwort(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Armut-Antwort nicht möglich.')
  }

  async armutKartenZurueck(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Kartentausch nicht möglich.')
  }

  async nachSpielVerlassen(event) {
    event.preventDefault()
    const form = event.currentTarget
    const ok = await this.#postForm(form, 'Aktion nicht möglich.')
    if (ok) this.#einstellungenGespeichert(form, 'Einstellung gespeichert.')
  }

  async regelwerkSpeichern(event) {
    event.preventDefault()
    const form = event.currentTarget
    const ok = await this.#postForm(form, 'Regelwerk konnte nicht gespeichert werden.')
    if (ok) this.#einstellungenGespeichert(form, 'Regelwerk gespeichert.')
  }

  async botsAuffuellen(event) {
    event.preventDefault()
    const form = event.currentTarget
    const ok = await this.#postForm(form, 'Bots konnten nicht hinzugefügt werden.')
    if (ok) {
      this.#einstellungenGespeichert(form, 'Bots hinzugefügt.')
      window.location.reload()
    }
  }

  async autoStartToggle(event) {
    event.preventDefault()
    const form = event.currentTarget
    const ok = await this.#postForm(form, 'Auto-Start konnte nicht geändert werden.')
    if (ok) this.#einstellungenGespeichert(form, 'Auto-Start geändert.')
  }

  async steuerungModus(event) {
    event.preventDefault()
    const form = event.currentTarget
    const ok = await this.#postForm(form, 'Steuerung konnte nicht geändert werden.')
    if (ok) {
      toast('Steuerung geändert.')
      window.location.reload()
    }
  }

  soundUmschalten() {
    const aktiv = this.sound.toggle()
    this._soundToggleAktualisieren(aktiv)
    // AudioContext entsperren durch User-Geste
    this.sound.play('mein_zug')
  }

  // ── Fehleranzeige ──────────────────────────────────────────────────────

  fehlerZeigen(text) {
    toast(text, 'error', 4000)
  }

  fehlerHide() {
    // Toast-System übernimmt auto-hide
  }

  // ── Private Helfer ─────────────────────────────────────────────────────

  #einstellungenGespeichert(form, text) {
    toast(text)
    const details = form.closest('details')
    if (details) details.removeAttribute('open')
  }

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
      case 'ARMUT_ANFRAGE':
      case 'ARMUT_ANGENOMMEN':
        this.sound.play('vorbehalt')
        break
      case 'ARMUT_ABGELEHNT':
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
