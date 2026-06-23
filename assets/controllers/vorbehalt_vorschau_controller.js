import { Controller } from '@hotwired/stimulus'
import { toast } from '../toast.js'

const LABELS = {
  HOCHZEIT: 'Hochzeit ♛♛',
  ARMUT: 'Armut',
  SOLO_BUBEN: 'Solo Buben',
  SOLO_DAMEN: 'Solo Damen',
  SOLO_FLEISCHLOS: 'Solo Fleischlos',
  SOLO_KARO: 'Solo Karo',
  SOLO_HERZ: 'Solo Herz',
  SOLO_PIK: 'Solo Pik',
  SOLO_KREUZ: 'Solo Kreuz',
}

export default class extends Controller {
  static targets = ['kartenContainer', 'bestaetigung', 'vorschauLabel']
  static values = {
    actionUrl: String,
    csrf: String,
    sortierungen: Object,
  }

  connect() {
    this._gewaehlterTyp = null
    this._gewaehlteVariante = null
    this._originalReihenfolge = null
  }

  vorschau({ params: { typ, variante } }) {
    if (!this.hasKartenContainerTarget) return

    if (!this._originalReihenfolge) {
      this._originalReihenfolge = Array.from(
        this.kartenContainerTarget.querySelectorAll('[data-karte-id]')
      ).map(el => el.dataset.karteId)
    }

    this._gewaehlterTyp = typ
    this._gewaehlteVariante = variante || null

    const sortKey = typ === 'SOLO' ? variante : typ
    const sortierung = this.sortierungenValue[sortKey]
    if (sortierung) {
      this._kartenUmsortieren(sortierung)
    }

    const label = typ === 'SOLO' ? LABELS[variante] || variante : LABELS[typ] || typ
    this.vorschauLabelTarget.textContent = label
    this.bestaetigungTarget.classList.remove('hidden')
  }

  async bestaetigen() {
    if (!this._gewaehlterTyp) return

    // Per fetch absenden statt nativer Formular-Submit: Der Endpoint liefert
    // JSON ({"ok":true}); eine echte Navigation würde dieses JSON als Seite
    // anzeigen. Der Spielzustand wird anschließend über Mercure aktualisiert.
    const body = new FormData()
    body.append('_token', this.csrfValue)
    body.append('vorbehalt_typ', this._gewaehlterTyp)
    if (this._gewaehlteVariante) {
      body.append('solo_variante', this._gewaehlteVariante)
    }

    try {
      const response = await fetch(this.actionUrlValue, { method: 'POST', body })
      const json = await response.json()
      if (!response.ok) {
        toast(json.fehler ?? 'Vorbehalt nicht möglich.', 'error', 4000)
        return
      }
      this.bestaetigungTarget.classList.add('hidden')
    } catch {
      toast('Verbindungsfehler.', 'error', 4000)
    }
  }

  abbrechen() {
    this._gewaehlterTyp = null
    this._gewaehlteVariante = null
    this.bestaetigungTarget.classList.add('hidden')

    if (this._originalReihenfolge) {
      this._kartenUmsortieren(this._originalReihenfolge)
    }
  }

  _kartenUmsortieren(idsInReihenfolge) {
    const container = this.kartenContainerTarget
    const kartenMap = {}
    container.querySelectorAll('[data-karte-id]').forEach(el => {
      kartenMap[el.dataset.karteId] = el
    })

    for (const id of idsInReihenfolge) {
      if (kartenMap[id]) {
        container.appendChild(kartenMap[id])
      }
    }
  }
}
