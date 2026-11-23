import { Controller } from '@hotwired/stimulus'
import { abonnieren } from '../mercure.js'

export default class extends Controller {
  static values = {
    mercureUrl: String,
    topic: String,
    partialUrl: String,
  }

  connect() {
    this.abbestellen = abonnieren(this.mercureUrlValue, this.topicValue, () => this.aktualisieren())
  }

  disconnect() {
    this.abbestellen?.()
  }

  async aktualisieren() {
    try {
      const response = await fetch(this.partialUrlValue, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (response.ok) {
        this.element.innerHTML = await response.text()
      }
    } catch {
      // Netzwerkfehler: nächstes Event wird es erneut versuchen
    }
  }
}
