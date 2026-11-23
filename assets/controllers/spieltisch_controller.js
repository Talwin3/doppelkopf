import { Controller } from '@hotwired/stimulus'
import { SoundEngine } from '../sound_engine.js'
import { toast } from '../toast.js'
import { confirmDialog } from '../dialog.js'
import { abonnieren } from '../mercure.js'

/** Das Regelwerk-Formular im Einstellungs-Panel. */
const REGELWERK_FORM = 'form[data-action*="regelwerkSpeichern"]'

/**
 * Ereignisse, nach denen sich die Punkteleiste geändert haben kann (Spielende,
 * neue Besetzung, Einstellungen). Bei allen anderen – Züge, Ansagen, Vorbehalt –
 * wird die Leiste nicht neu berechnet.
 */
const PUNKTE_EREIGNISSE = new Set([
  // SPIEL_BEENDET fehlt bewusst: Nach jedem Spielende folgt TISCH_ZUSTAND, erst nach
  // Rotation und Nachrücken – das lädt Punkte und Panel einmal mit dem richtigen Stand.
  'TISCH_ZUSTAND', 'TISCH_EINSTELLUNGEN', 'TISCH_BESETZUNG', 'KARTEN_AUSGETEILT',
])

export default class extends Controller {
  static targets = [
    'zustand', 'dranIndikator', 'spielGewonnen', 'spielVerloren', 'soundToggle',
    'abrechnungDialog', 'abrechnungInhalt', 'vorbehaltDialog', 'vorbehaltInhalt', 'punkte',
    'einstellungen',
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

    // Ungespeicherte Änderungen im Einstellungs-Panel merken, damit ein Live-Reload
    // sie nicht stillschweigend verwirft (siehe _einstellungenErsetzen).
    this._einstellungenGeaendert = false
    // Zählt Nutzereingaben im Regelwerk-Formular: Eine Speicherung macht den Entwurf nur
    // dann „sauber“, wenn seit dem Abschicken nichts mehr geändert wurde.
    this._regelwerkEingaben = 0
    // Kennungen eigener Regelwerk-Speicherungen; das zugehörige Mercure-Event ist keine
    // fremde Änderung, egal ob es vor oder nach der Antwort ankommt.
    this._eigeneAenderungen = new Set()
    if (this.hasEinstellungenTarget) {
      this.einstellungenTarget.addEventListener('input', (event) => {
        // Nur das Regelwerk-Formular sammelt Eingaben vor dem Speichern; Select und
        // Schalter im Panel speichern sofort.
        if (event.target.closest(REGELWERK_FORM)) {
          this._einstellungenGeaendert = true
          this._regelwerkEingaben++
        }
      })
    }

    // Vorbehalts-Dialog beim Laden mitten in der Vorbehaltsrunde direkt öffnen.
    if (this.phaseValue === 'VORBEHALT' && this.hasVorbehaltDialogTarget && !this.vorbehaltDialogTarget.open) {
      this.vorbehaltDialogTarget.showModal()
    }

    // Teilt sich die Verbindung mit dem Chat (dasselbe Topic), siehe mercure.js.
    this.abbestellen = abonnieren(this.mercureUrlValue, this.topicValue, (daten) => {
      // Chat läuft über dasselbe Topic, betrifft aber den Spielzustand nicht.
      if (daten.typ === 'CHAT_NACHRICHT') return
      this.aktualisieren(daten)
    })
  }

  disconnect() {
    this.abbestellen?.()
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

    // Die Punkteleiste nur mitladen, wenn sie sich geändert haben kann.
    const url = new URL(this.zustandUrlValue, window.location.origin)
    if (PUNKTE_EREIGNISSE.has(daten.typ)) url.searchParams.set('punkte', '1')

    try {
      const response = await fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (response.ok) {
        const phase = this._verteileZustand(await response.text(), daten)
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
  _verteileZustand(html, daten) {
    const doc = new DOMParser().parseFromString(html, 'text/html')
    const root = doc.querySelector('[data-zustand-root]')
    if (!root) {
      // Fallback: ganze Antwort als Kulisse einsetzen.
      this.zustandTarget.innerHTML = html
      return ''
    }

    const inhalt = (bereich) => root.querySelector(`template[data-bereich="${bereich}"]`)?.innerHTML ?? ''

    this.zustandTarget.innerHTML = inhalt('tisch')
    // Fehlt der Bereich (Spielzug ohne ?punkte=1), bleibt die Leiste unverändert.
    const punkte = root.querySelector('template[data-bereich="punkte"]')
    if (punkte && this.hasPunkteTarget) this.punkteTarget.innerHTML = punkte.innerHTML
    const einstellungen = root.querySelector('template[data-bereich="einstellungen"]')
    if (einstellungen) this._einstellungenErsetzen(einstellungen.innerHTML, daten)
    if (this.hasVorbehaltInhaltTarget) this.vorbehaltInhaltTarget.innerHTML = inhalt('vorbehalt')
    if (this.hasAbrechnungInhaltTarget) this.abrechnungInhaltTarget.innerHTML = inhalt('abrechnung')

    return root.dataset.phase ?? ''
  }

  /**
   * Ersetzt das Einstellungs-Panel durch den aktuellen Server-Stand (Rechte, Anzeige,
   * Spielphase …); ein offenes Panel bleibt offen. Ungespeicherte Regelwerk-Häkchen
   * (Panel offen) werden danach wieder eingesetzt – außer das Regelwerk wurde von jemand
   * anderem geändert (sonst würde man dessen Änderung mit alten Häkchen zurückdrehen)
   * oder ein Spiel beginnt (dann ist das Regelwerk gesperrt). Dann gibt es einen Hinweis.
   */
  _einstellungenErsetzen(html, daten) {
    if (!this.hasEinstellungenTarget) return
    const warOffen = this.einstellungenTarget.querySelector('details')?.open ?? false

    // Ungespeichertes zählt nur, solange das Panel offen ist; zugeklappt = verworfen.
    let entwurf = null
    if (this._einstellungenGeaendert && warOffen) {
      if (daten.typ === 'TISCH_EINSTELLUNGEN' && daten.regelwerk
          && !this._eigeneAenderungen.has(daten.aenderung)) {
        toast('Das Regelwerk wurde gerade von jemand anderem geändert – bitte prüfen.', 'info', 5000)
      } else if (daten.typ === 'KARTEN_AUSGETEILT') {
        toast('Das Spiel hat begonnen – das Regelwerk ist erst danach wieder änderbar.', 'info', 5000)
      } else {
        entwurf = this.#regelwerkEntwurf()
      }
    }

    this.einstellungenTarget.innerHTML = html
    this._einstellungenGeaendert = false
    const details = this.einstellungenTarget.querySelector('details')
    if (details && warOffen) details.open = true

    // Entwurf zurückspielen – nur wenn das neue Panel das Regelwerk-Formular noch hat
    // (fehlt es, darf man es gerade nicht ändern; der Entwurf ist dann hinfällig).
    const form = this.einstellungenTarget.querySelector(REGELWERK_FORM)
    if (entwurf && form) {
      this.#regelwerkAnzeigen(form, entwurf)
      this._einstellungenGeaendert = true
    }
  }

  /** Aktuelle (ungespeicherte) Häkchen des Regelwerk-Formulars im Format der Server-Antwort. */
  #regelwerkEntwurf() {
    const form = this.einstellungenTarget.querySelector(REGELWERK_FORM)
    if (!form) return null
    const entwurf = { soli_erlaubt: [] }
    for (const feld of form.querySelectorAll('input[type="checkbox"]')) {
      if (feld.name === 'soli_erlaubt[]') {
        if (feld.checked) entwurf.soli_erlaubt.push(feld.value)
      } else {
        entwurf[feld.name] = feld.checked
      }
    }
    return entwurf
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
    const aenderung = Math.random().toString(36).slice(2, 12) || 'x'
    this._eigeneAenderungen.add(aenderung)
    const eingabenBeimSenden = this._regelwerkEingaben
    const antwort = await this.#postForm(form, 'Regelwerk konnte nicht gespeichert werden.', { aenderung })
    // Fehlgeschlagen: Die Häkchen bleiben als ungespeichert markiert.
    if (!antwort) return

    if (this._regelwerkEingaben !== eingabenBeimSenden) {
      // Während der Anfrage weiter geändert: Diese neueren Häkchen bleiben stehen und
      // ungespeichert, das Panel bleibt offen.
      toast('Regelwerk gespeichert – neuere Änderungen sind noch nicht gespeichert.')
      return
    }

    // Formular auf die tatsächlich gespeicherten Regeln bringen (der Server korrigiert
    // z. B. Superschweinchen bei „Ohne Neuner“ und hält am DDV-Tisch alles fest). Kam das
    // eigene Mercure-Event schon vorher, ist das Panel bereits ersetzt.
    const aktuellesForm = this.hasEinstellungenTarget
      ? (this.einstellungenTarget.querySelector(REGELWERK_FORM) ?? form)
      : form
    if (antwort.regelwerk) this.#regelwerkAnzeigen(aktuellesForm, antwort.regelwerk)
    this._einstellungenGeaendert = false
    this.#einstellungenGespeichert(form, 'Regelwerk gespeichert.')
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

  async punkteAnzeige(event) {
    event.preventDefault()
    const form = event.currentTarget
    // Die Leiste selbst aktualisiert sich über das Mercure-Event TISCH_EINSTELLUNGEN.
    const ok = await this.#postForm(form, 'Anzeige konnte nicht geändert werden.')
    if (ok) this.#einstellungenGespeichert(form, 'Punkteanzeige geändert.')
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

  #regelwerkAnzeigen(form, regelwerk) {
    for (const feld of form.querySelectorAll('input[type="checkbox"]')) {
      if (feld.name === 'soli_erlaubt[]') {
        feld.checked = (regelwerk.soli_erlaubt ?? []).includes(feld.value)
      } else if (feld.name in regelwerk) {
        feld.checked = Boolean(regelwerk[feld.name])
      }
    }
    // Superschweinchen ist nur mit Schweinchen wählbar (am DDV-Tisch ohnehin gesperrt).
    const sup = form.querySelector('input[name="superschweinchen"]')
    const schwein = form.querySelector('input[name="schweinchen"]')
    if (sup && schwein && !schwein.disabled) {
      sup.disabled = !schwein.checked
      sup.closest('label')?.querySelector('span')?.classList.toggle('opacity-40', sup.disabled)
    }
  }

  #einstellungenGespeichert(form, text) {
    toast(text)
    // Panel nur schließen, wenn dabei keine ungespeicherten Regelwerk-Häkchen verloren gehen.
    if (this._einstellungenGeaendert) return
    form.closest('details')?.removeAttribute('open')
    // Kam das eigene Mercure-Event vor der Antwort, ist das Panel schon ersetzt:
    // dann das neue schließen.
    if (this.hasEinstellungenTarget) {
      this.einstellungenTarget.querySelector('details')?.removeAttribute('open')
    }
  }

  async #postForm(form, standardFehler, zusatz = {}) {
    this.fehlerHide()
    const body = new FormData(form)
    for (const [name, wert] of Object.entries(zusatz)) body.set(name, wert)
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body,
      })
      const json = await response.json()
      if (!response.ok) {
        this.fehlerZeigen(json.fehler ?? standardFehler)
        return false
      }
      // Antwort-JSON (immer ein Objekt, also truthy) für Aufrufer, die sie brauchen.
      return json ?? {}
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
