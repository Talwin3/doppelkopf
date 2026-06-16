import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = ['zustand', 'fehler']
  static values = {
    mercureUrl: String,
    topic: String,
    zustandUrl: String,
  }

  connect() {
    const url = new URL(this.mercureUrlValue)
    url.searchParams.append('topic', this.topicValue)

    this.eventSource = new EventSource(url.toString())
    this.eventSource.onmessage = (event) => {
      const daten = JSON.parse(event.data)
      this.aktualisieren(daten)
    }
    this.eventSource.onerror = () => {}
  }

  disconnect() {
    this.eventSource?.close()
  }

  async aktualisieren(daten) {
    try {
      const response = await fetch(this.zustandUrlValue, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (response.ok) {
        this.zustandTarget.innerHTML = await response.text()
      }

      if (daten.typ === 'SPIEL_BEENDET') {
        this.eventSource?.close()
      }
    } catch {
      // Nächstes Event versucht es erneut
    }
  }

  async karteAusspielen(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Ungültiger Zug.')
  }

  async ansagen(event) {
    event.preventDefault()
    await this.#postForm(event.currentTarget, 'Ansage nicht möglich.')
  }

  async #postForm(form, standardFehler) {
    this.fehlerHide()
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
      })
      const json = await response.json()
      if (!response.ok) {
        this.fehlerZeigen(json.fehler ?? standardFehler)
      }
    } catch {
      this.fehlerZeigen('Verbindungsfehler.')
    }
  }

  fehlerZeigen(text) {
    if (this.hasFehlerTarget) {
      this.fehlerTarget.textContent = text
      this.fehlerTarget.classList.remove('hidden')
      setTimeout(() => this.fehlerHide(), 3000)
    }
  }

  fehlerHide() {
    if (this.hasFehlerTarget) {
      this.fehlerTarget.classList.add('hidden')
    }
  }
}
