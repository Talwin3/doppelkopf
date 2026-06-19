import { Controller } from '@hotwired/stimulus'

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

  bestaetigen() {
    if (!this._gewaehlterTyp) return

    const form = document.createElement('form')
    form.method = 'POST'
    form.action = this.actionUrlValue
    form.style.display = 'none'

    const token = document.createElement('input')
    token.type = 'hidden'
    token.name = '_token'
    token.value = this.csrfValue
    form.appendChild(token)

    const typ = document.createElement('input')
    typ.type = 'hidden'
    typ.name = 'vorbehalt_typ'
    typ.value = this._gewaehlterTyp
    form.appendChild(typ)

    if (this._gewaehlteVariante) {
      const variante = document.createElement('input')
      variante.type = 'hidden'
      variante.name = 'solo_variante'
      variante.value = this._gewaehlteVariante
      form.appendChild(variante)
    }

    document.body.appendChild(form)
    form.requestSubmit()
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
