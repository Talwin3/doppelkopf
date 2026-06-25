import { Controller } from '@hotwired/stimulus'
import { toast } from '../toast.js'

/**
 * Tisch-Chat: empfängt CHAT_NACHRICHT-Events über das tischbezogene Mercure-Topic
 * und hängt sie an; Senden per POST (die eigene Nachricht kommt ebenfalls über
 * Mercure zurück, daher kein lokales Anhängen). Nachrichtentext wird per
 * textContent gesetzt → kein XSS.
 */
export default class extends Controller {
  static targets = ['messages', 'input', 'body', 'toggleIcon', 'leer']
  static values = {
    mercureUrl: String,
    topic: String,
    sendUrl: String,
    csrf: String,
    meinUserId: String,
  }

  connect() {
    this.scrollToBottom()

    if (this.mercureUrlValue && this.topicValue) {
      const url = new URL(this.mercureUrlValue)
      url.searchParams.append('topic', this.topicValue)
      this.eventSource = new EventSource(url.toString())
      this.eventSource.onmessage = (event) => {
        let daten
        try { daten = JSON.parse(event.data) } catch { return }
        if (daten.typ === 'CHAT_NACHRICHT') this.anhaengen(daten)
      }
      this.eventSource.onerror = () => {}
    }
  }

  disconnect() {
    this.eventSource?.close()
  }

  umschalten() {
    const offen = !this.bodyTarget.classList.toggle('hidden')
    if (this.hasToggleIconTarget) this.toggleIconTarget.textContent = offen ? '▾' : '▸'
    if (offen) this.scrollToBottom()
  }

  async senden(event) {
    event.preventDefault()
    const text = this.inputTarget.value.trim()
    if (text === '') return

    const body = new FormData()
    body.append('_token', this.csrfValue)
    body.append('text', text)

    try {
      const response = await fetch(this.sendUrlValue, { method: 'POST', body })
      if (!response.ok) {
        const json = await response.json().catch(() => ({}))
        toast(json.fehler ?? 'Nachricht konnte nicht gesendet werden.', 'error', 3000)
        return
      }
      this.inputTarget.value = ''
    } catch {
      toast('Verbindungsfehler.', 'error', 3000)
    }
  }

  anhaengen(daten) {
    if (this.hasLeerTarget) this.leerTarget.remove()

    const eigen = this.meinUserIdValue !== '' && daten.absenderId === this.meinUserIdValue

    const zeile = document.createElement('div')
    zeile.className = `flex flex-col ${eigen ? 'items-end' : 'items-start'}`

    const bubble = document.createElement('div')
    bubble.className = `max-w-[85%] rounded-lg px-2.5 py-1.5 ${eigen ? 'bg-tisch-600 text-white' : 'bg-tisch-700 text-tisch-100'}`

    if (!eigen) {
      const name = document.createElement('p')
      name.className = 'text-[11px] font-semibold text-tisch-300 leading-tight'
      name.textContent = daten.absender
      bubble.appendChild(name)
    }

    const text = document.createElement('p')
    text.className = 'whitespace-pre-wrap break-words leading-snug'
    text.textContent = daten.text
    bubble.appendChild(text)

    const zeit = document.createElement('p')
    zeit.className = 'text-[10px] text-tisch-400 text-right leading-none mt-0.5'
    zeit.textContent = daten.zeit
    bubble.appendChild(zeit)

    zeile.appendChild(bubble)
    this.messagesTarget.appendChild(zeile)
    this.scrollToBottom()
  }

  scrollToBottom() {
    if (this.hasMessagesTarget) this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight
  }
}
