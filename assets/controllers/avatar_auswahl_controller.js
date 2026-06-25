import { Controller } from '@hotwired/stimulus'

/**
 * Avatar-Auswahl im Profil: "Neu würfeln" erzeugt nur Vorschau-Seeds im Browser,
 * gespeichert wird erst beim Klick auf "Speichern" (Stil + aktueller Seed über
 * das verstecktes Feld avatar_seed). Bilder werden über ein URL-Template
 * (Platzhalter __SEED__) erzeugt, damit kein Pfad-Präfix hartcodiert ist.
 */
export default class extends Controller {
  static targets = ['bigPreview', 'thumb', 'seedInput', 'radio']
  static values = { seed: String }

  connect() {
    if (!this.seedValue) this.seedValue = this.randomSeed()
    this.render()
  }

  wuerfeln() {
    this.seedValue = this.randomSeed()
    this.render()
  }

  // Stil-Wechsel über die Radio-Buttons → großes Vorschaubild anpassen.
  waehleStil() {
    this.renderBig()
  }

  render() {
    if (this.hasSeedInputTarget) this.seedInputTarget.value = this.seedValue
    this.thumbTargets.forEach((img) => { img.src = this.url(img.dataset.urlTemplate) })
    this.renderBig()
  }

  renderBig() {
    if (!this.hasBigPreviewTarget) return
    const stil = this.selectedStil()
    const thumb = this.thumbTargets.find((t) => t.dataset.stil === stil)
    if (thumb) this.bigPreviewTarget.src = this.url(thumb.dataset.urlTemplate)
  }

  selectedStil() {
    const checked = this.radioTargets.find((r) => r.checked)
    return checked ? checked.value : (this.thumbTargets[0]?.dataset.stil ?? '')
  }

  url(template) {
    return template.replace('__SEED__', this.seedValue)
  }

  randomSeed() {
    return Array.from({ length: 8 }, () => Math.floor(Math.random() * 256).toString(16).padStart(2, '0')).join('')
  }
}
