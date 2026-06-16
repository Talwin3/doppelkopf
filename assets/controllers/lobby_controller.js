import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static values = {
    mercureUrl: String,
    topic: String,
    partialUrl: String,
  }

  connect() {
    const url = new URL(this.mercureUrlValue)
    url.searchParams.append('topic', this.topicValue)

    this.eventSource = new EventSource(url.toString())
    this.eventSource.onmessage = () => this.aktualisieren()
    this.eventSource.onerror = () => {
      // Browserseitiges Auto-Reconnect greift – kein manueller Retry nötig
    }
  }

  disconnect() {
    this.eventSource?.close()
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
