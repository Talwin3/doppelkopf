import { Controller } from '@hotwired/stimulus'
import { confirmDialog } from '../dialog.js'

/**
 * Hängt eine Bestätigungsabfrage (programmatischer Dialog) an ein Formular.
 * Ersetzt das native window.confirm().
 *
 *   <form data-controller="confirm" data-action="submit->confirm#abfragen"
 *         data-confirm-text-value="Wirklich löschen?"
 *         data-confirm-bestaetigen-value="Löschen"
 *         data-confirm-gefahr-value="true">
 */
export default class extends Controller {
  static values = {
    title: { type: String, default: 'Bist du sicher?' },
    text: String,
    bestaetigen: { type: String, default: 'OK' },
    abbrechen: { type: String, default: 'Abbrechen' },
    gefahr: { type: Boolean, default: false },
  }

  async abfragen(event) {
    event.preventDefault()
    const form = event.currentTarget

    const ok = await confirmDialog({
      title: this.titleValue,
      text: this.textValue,
      bestaetigen: this.bestaetigenValue,
      abbrechen: this.abbrechenValue,
      gefahr: this.gefahrValue,
    })

    // form.submit() löst keine erneuten submit-Listener aus → keine Schleife.
    if (ok) form.submit()
  }
}
