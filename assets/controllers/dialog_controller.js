import { Controller } from '@hotwired/stimulus'

/**
 * Deklarativer Dialog auf Basis des nativen <dialog>-Elements.
 *
 * Markup:
 *   <div data-controller="dialog">
 *     <button data-action="dialog#open">Öffnen</button>           {# modal #}
 *     <button data-action="dialog#openNonModal">Öffnen</button>   {# nicht-modal #}
 *     <dialog data-dialog-target="dialog" data-action="click->dialog#backdrop">
 *       … <button data-action="dialog#close">Schließen</button>
 *     </dialog>
 *   </div>
 *
 * Alternativ kann das Controller-Element selbst das <dialog> sein.
 * Für rein programmatische Dialoge siehe dialog.js.
 */
export default class extends Controller {
  static targets = ['dialog']
  static values = {
    closeOnBackdrop: { type: Boolean, default: true },
    openInitially: { type: Boolean, default: false },
  }

  connect() {
    if (this.openInitiallyValue) this.open()
  }

  disconnect() {
    // Offenen Dialog beim Entfernen sauber schließen (z. B. bei DOM-Swap).
    if (this.dialog?.open) this.dialog.close()
  }

  get dialog() {
    if (this.hasDialogTarget) return this.dialogTarget
    return this.element.tagName === 'DIALOG' ? this.element : null
  }

  open() {
    this.dialog?.showModal()
  }

  openNonModal() {
    this.dialog?.show()
  }

  close() {
    this.dialog?.close()
  }

  backdrop(event) {
    if (this.closeOnBackdropValue && event.target === this.dialog) this.close()
  }
}
