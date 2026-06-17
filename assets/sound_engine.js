/**
 * Web-Audio-API-basierte Sound-Engine für Doppelkopf.
 * Keine externen Audiodateien — alle Sounds werden synthetisch erzeugt.
 */
export class SoundEngine {
  constructor() {
    this._ctx = null
    this._enabled = localStorage.getItem('dk_sound') !== 'false'
  }

  get enabled() {
    return this._enabled
  }

  toggle() {
    this._enabled = !this._enabled
    localStorage.setItem('dk_sound', this._enabled ? 'true' : 'false')
    return this._enabled
  }

  /**
   * Spielt einen von mehreren vordefinierten Sounds.
   * @param {'karte'|'karte_eigene'|'stich'|'stich_gewonnen'|'mein_zug'|'ansage'|'gewonnen'|'verloren'|'karten_ausgeteilt'|'vorbehalt'} typ
   */
  play(typ) {
    if (!this._enabled) return
    try {
      const ctx = this._getCtx()
      if (!ctx) return
      switch (typ) {
        case 'karte':           return this._karteSound(ctx)
        case 'karte_eigene':    return this._karteEigeneSound(ctx)
        case 'stich':           return this._stichSound(ctx)
        case 'stich_gewonnen':  return this._stichGewonnenSound(ctx)
        case 'mein_zug':        return this._meinZugSound(ctx)
        case 'ansage':          return this._ansageSound(ctx)
        case 'gewonnen':        return this._gewonnenSound(ctx)
        case 'verloren':        return this._verlorenSound(ctx)
        case 'karten_ausgeteilt': return this._kartenAusgeteiltSound(ctx)
        case 'vorbehalt':       return this._vorbehaltSound(ctx)
      }
    } catch {
      // Web Audio API nicht verfügbar oder blockiert
    }
  }

  // ── Einzelne Sound-Definitionen ────────────────────────────────────────

  _karteSound(ctx) {
    // Kurzes dumpfes Geräusch: eine Karte fällt auf den Tisch
    this._tone(ctx, 180, 0, 80, 0.8, 0.25, 'triangle')
  }

  _karteEigeneSound(ctx) {
    // Etwas heller — eigene Karte ausgespielt
    this._tone(ctx, 260, 0, 70, 0.6, 0.2, 'triangle')
  }

  _stichSound(ctx) {
    // Kurze absteigende Terz: Stich geht an jemand anderen
    this._tone(ctx, 440, 0,   80, 0.6, 0.15, 'sine')
    this._tone(ctx, 350, 0.09, 80, 0.4, 0.12, 'sine')
  }

  _stichGewonnenSound(ctx) {
    // Aufsteigende kleine Terz: ich gewinne den Stich
    this._tone(ctx, 440, 0,    80, 0.5, 0.18, 'sine')
    this._tone(ctx, 550, 0.09, 80, 0.4, 0.18, 'sine')
    this._tone(ctx, 660, 0.18, 80, 0.3, 0.18, 'sine')
  }

  _meinZugSound(ctx) {
    // Sanftes Ping: ich bin dran
    this._tone(ctx, 880, 0,   50, 0.3, 0.14, 'sine')
    this._tone(ctx, 1100, 0.06, 50, 0.2, 0.1, 'sine')
  }

  _ansageSound(ctx) {
    // Kräftiger Ding-Ton: jemand hat angesagt
    this._tone(ctx, 660, 0,  120, 0.6, 0.22, 'square')
    this._tone(ctx, 440, 0.13, 80, 0.3, 0.12, 'sine')
  }

  _gewonnenSound(ctx) {
    // Fröhliches aufsteigendes Arpeggio: C5 E5 G5 C6
    const noten = [523, 659, 784, 1047]
    noten.forEach((freq, i) => this._tone(ctx, freq, i * 0.1, 100, 0.5, 0.2, 'sine'))
  }

  _verlorenSound(ctx) {
    // Absteigendes Moll-Arpeggio: C5 B4 A♭4 G4
    const noten = [523, 494, 415, 392]
    noten.forEach((freq, i) => this._tone(ctx, freq, i * 0.13, 120, 0.5, 0.18, 'sine'))
  }

  _kartenAusgeteiltSound(ctx) {
    // 4 kurze rasche Klicks: Karten werden ausgeteilt
    for (let i = 0; i < 4; i++) {
      this._tone(ctx, 200 + i * 30, i * 0.07, 60, 0.7, 0.15, 'triangle')
    }
  }

  _vorbehaltSound(ctx) {
    // Sanfte Bestätigung: Vorbehalt deklariert
    this._tone(ctx, 520, 0, 80, 0.5, 0.13, 'sine')
  }

  // ── Primitive ──────────────────────────────────────────────────────────

  /**
   * Erzeugt einen einfachen Ton mit Fade-Out.
   * @param {AudioContext} ctx
   * @param {number} freq Frequenz in Hz
   * @param {number} delay Startverzögerung in Sekunden
   * @param {number} durationMs Dauer in Millisekunden
   * @param {number} freqRatio Endfrequenz als Vielfaches von freq (< 1 = fallend)
   * @param {number} volume Lautstärke 0–1
   * @param {OscillatorType} type Wellenform
   */
  _tone(ctx, freq, delay, durationMs, freqRatio, volume, type) {
    const duration = durationMs / 1000
    const t0 = ctx.currentTime + delay
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()

    osc.connect(gain)
    gain.connect(ctx.destination)

    osc.type = type
    osc.frequency.setValueAtTime(freq, t0)
    osc.frequency.exponentialRampToValueAtTime(Math.max(freq * freqRatio, 10), t0 + duration)

    gain.gain.setValueAtTime(volume, t0)
    gain.gain.exponentialRampToValueAtTime(0.001, t0 + duration)

    osc.start(t0)
    osc.stop(t0 + duration + 0.01)
  }

  _getCtx() {
    if (!this._ctx) {
      try {
        this._ctx = new (window.AudioContext || window.webkitAudioContext)()
      } catch {
        return null
      }
    }
    // Browser-Autoplay-Policy: Kontext muss durch User-Geste entsperrt werden
    if (this._ctx.state === 'suspended') {
      this._ctx.resume().catch(() => {})
    }
    return this._ctx
  }
}
