import { Controller } from '@hotwired/stimulus'

/**
 * Stich-für-Stich-Replay eines beendeten Spiels.
 *
 * Steppt karten- oder stichweise durch die Daten (data-replay-data-value).
 * Die Karten-SVGs liegen serverseitig gerendert im svgPool-Target und werden
 * per Klon in die Sitzplatz-Slots gehängt. Stichgewinner, laufender Punktestand
 * und Ansagen werden clientseitig aus den Stichdaten abgeleitet.
 */
export default class extends Controller {
  static values = { data: Object }
  static targets = [
    'panel', 'cardSlot', 'teamBadge', 'winnerBadge',
    'trickLabel', 'scoreRe', 'scoreKontra', 'ansagen',
    'slider', 'playBtn', 'status', 'endabrechnung', 'svgPool',
  ]

  connect() {
    const d = this.dataValue
    this.stiche = d.stiche || []
    this.ansagen = d.ansagen || []
    this.teamBySeat = {}
    for (const s of d.spieler || []) this.teamBySeat[s.sitzplatz] = s.team

    // Flache Liste aller Karten in Spielreihenfolge.
    this.plays = []
    this.stiche.forEach((stich, ti) => {
      for (const k of stich.karten) {
        this.plays.push({ ti, nr: stich.nr, seat: k.seat, karteId: k.karteId })
      }
    })
    this.total = this.plays.length

    // Augen-Offsets je Stich (für laufenden Punktestand).
    this.trickEnd = []
    let acc = 0
    this.stiche.forEach((stich) => { acc += stich.karten.length; this.trickEnd.push(acc) })

    this.index = 0
    this.playing = false

    if (this.hasSliderTarget) this.sliderTarget.max = String(this.total)

    // Teams im Replay dauerhaft sichtbar.
    this.teamBadgeTargets.forEach((b) => { if (b.textContent.trim() !== '?') b.classList.remove('opacity-0') })

    this.render()
  }

  disconnect() { this.stop() }

  // ── Navigation ──────────────────────────────────────────────────────────
  first() { this.stop(); this.index = 0; this.render() }
  last() { this.stop(); this.index = this.total; this.render() }
  prev() { this.stop(); this.setIndex(this.index - 1) }
  next() { this.setIndex(this.index + 1) }

  prevTrick() {
    this.stop()
    // Zum Anfang des aktuellen bzw. vorherigen Stichs springen.
    const starts = this.trickStarts()
    const prior = starts.filter((s) => s < this.index)
    this.setIndex(prior.length ? prior[prior.length - 1] : 0)
  }

  nextTrick() {
    this.stop()
    const ends = this.trickEnd
    const nextEnd = ends.find((e) => e > this.index)
    this.setIndex(nextEnd !== undefined ? nextEnd : this.total)
  }

  seek() { this.stop(); this.setIndex(parseInt(this.sliderTarget.value, 10)) }

  togglePlay(e) {
    if (e && e.preventDefault) e.preventDefault()
    this.playing ? this.stop() : this.play()
  }

  play() {
    if (this.index >= this.total) this.index = 0
    this.playing = true
    if (this.hasPlayBtnTarget) this.playBtnTarget.textContent = '⏸'
    this.timer = setInterval(() => {
      if (this.index >= this.total) { this.stop(); return }
      this.setIndex(this.index + 1)
    }, 850)
  }

  stop() {
    this.playing = false
    if (this.timer) { clearInterval(this.timer); this.timer = null }
    if (this.hasPlayBtnTarget) this.playBtnTarget.textContent = '▶'
  }

  setIndex(i) {
    this.index = Math.max(0, Math.min(this.total, i))
    this.render()
  }

  trickStarts() {
    const starts = [0]
    for (let i = 0; i < this.trickEnd.length - 1; i++) starts.push(this.trickEnd[i])
    return starts
  }

  // ── Rendering ───────────────────────────────────────────────────────────
  render() {
    const idx = this.index

    // Aktiver Stich (Array-Index) anhand der zuletzt aufgedeckten Karte.
    const activeTi = idx === 0 ? 0 : this.plays[idx - 1].ti
    const activeStich = this.stiche[activeTi]
    const activeNr = activeStich ? activeStich.nr : 0

    // Karten-Slots leeren.
    this.cardSlotTargets.forEach((slot) => { slot.innerHTML = ''; slot.classList.add('opacity-0') })
    this.panelTargets.forEach((p) => p.classList.remove('ring-2', 'ring-yellow-400', 'rounded-xl', 'animate-pulse'))
    this.winnerBadgeTargets.forEach((b) => b.classList.add('hidden'))

    // Karten des aktiven Stichs einsetzen (nur bereits aufgedeckte).
    for (let i = 0; i < idx; i++) {
      const play = this.plays[i]
      if (play.ti !== activeTi) continue
      const slot = this.slotForSeat(play.seat)
      if (slot) { slot.innerHTML = this.svgFor(play.karteId); slot.classList.remove('opacity-0') }
    }

    // Stich vollständig aufgedeckt? → Gewinner markieren.
    const trickComplete = activeStich && idx >= this.trickEnd[activeTi]
    if (trickComplete && activeStich.winnerSeat) {
      const panel = this.panelForSeat(activeStich.winnerSeat)
      if (panel) panel.classList.add('ring-2', 'ring-yellow-400', 'rounded-xl')
      const badge = this.winnerBadgeForSeat(activeStich.winnerSeat)
      if (badge) badge.classList.remove('hidden')
    } else if (idx < this.total) {
      // Nächster Spieler dezent hervorheben.
      const panel = this.panelForSeat(this.plays[idx].seat)
      if (panel) panel.classList.add('ring-2', 'ring-tisch-400', 'rounded-xl')
    }

    // Laufender Punktestand: alle vollständig aufgedeckten Stiche.
    let re = 0, kontra = 0
    this.stiche.forEach((stich, ti) => {
      if (idx < this.trickEnd[ti]) return
      const team = this.teamBySeat[stich.winnerSeat]
      if (team === 'RE') re += stich.augen
      else if (team === 'KONTRA') kontra += stich.augen
    })
    if (this.hasScoreReTarget) this.scoreReTarget.textContent = String(re)
    if (this.hasScoreKontraTarget) this.scoreKontraTarget.textContent = String(kontra)

    // Beschriftungen.
    const totalTricks = this.stiche.length
    if (this.hasTrickLabelTarget) {
      this.trickLabelTarget.textContent = idx === 0 ? 'Start' : `Stich ${activeNr}/${totalTricks}`
    }
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = idx === 0
        ? 'Spielbeginn'
        : (idx >= this.total ? 'Spielende' : `Karte ${idx} von ${this.total}`)
    }
    if (this.hasSliderTarget) this.sliderTarget.value = String(idx)

    // Ansagen bis zum aktuellen Stich.
    if (this.hasAnsagenTarget) {
      this.ansagenTarget.innerHTML = ''
      const gezeigt = idx === 0 ? [] : this.ansagen.filter((a) => a.trick <= activeNr)
      const seen = new Set()
      for (const a of gezeigt) {
        if (seen.has(a.typ)) continue
        seen.add(a.typ)
        const re2 = a.typ === 'RE'
        const span = document.createElement('span')
        span.className = `inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ${re2 ? 'bg-blue-600 text-white' : 'bg-red-600 text-white'}`
        span.textContent = a.typ.replace(/_/g, ' ')
        this.ansagenTarget.appendChild(span)
      }
    }

    // Endabrechnung am Spielende hervorheben.
    if (this.hasEndabrechnungTarget) {
      this.endabrechnungTarget.classList.toggle('opacity-60', idx < this.total)
    }
  }

  // ── Helfer ──────────────────────────────────────────────────────────────
  svgFor(karteId) {
    const tpl = this.svgPoolTarget.querySelector(`template[data-karte-id="${karteId}"]`)
    return tpl ? tpl.innerHTML : ''
  }

  slotForSeat(seat) { return this.cardSlotTargets.find((s) => s.dataset.seat === String(seat)) }
  panelForSeat(seat) { return this.panelTargets.find((p) => p.dataset.seat === String(seat)) }
  winnerBadgeForSeat(seat) { return this.winnerBadgeTargets.find((b) => b.dataset.seat === String(seat)) }
}
