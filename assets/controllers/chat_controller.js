import { Controller } from '@hotwired/stimulus'
import { toast } from '../toast.js'

/**
 * Tisch-Chat: empfängt CHAT_NACHRICHT-Events über das tischbezogene Mercure-Topic
 * und hängt sie an; Senden per POST (die eigene Nachricht kommt ebenfalls über
 * Mercure zurück, daher kein lokales Anhängen). Nachrichtentext wird per
 * textContent gesetzt → kein XSS.
 */
export default class extends Controller {
  static targets = ['messages', 'input', 'body', 'toggleIcon', 'leer', 'emojiPanel']
  static values = {
    mercureUrl: String,
    topic: String,
    sendUrl: String,
    csrf: String,
    meinUserId: String,
    emojiMap: Object,
  }

  connect() {
    this.scrollToBottom()
    this.emojiRegex = this.baueEmojiRegex()

    // Picker schließen, wenn außerhalb geklickt wird.
    this.ausserhalbKlick = (e) => {
      if (this.hasEmojiPanelTarget && !this.element.contains(e.target)) {
        this.emojiPanelTarget.classList.add('hidden')
      }
    }
    document.addEventListener('click', this.ausserhalbKlick)

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
    document.removeEventListener('click', this.ausserhalbKlick)
  }

  pickerUmschalten() {
    if (this.hasEmojiPanelTarget) this.emojiPanelTarget.classList.toggle('hidden')
  }

  // Emoji aus dem Picker an die aktuelle Eingabe anhängen (Feld behält Fokus).
  emojiEinfuegen(event) {
    const emoji = event.params.emoji
    if (emoji == null) return
    this.inputTarget.value += emoji
    this.inputTarget.focus()
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

  // Schnell-Chatnachricht: füllt das Eingabefeld (Nutzer kann editieren + senden).
  einfuegen(event) {
    const phrase = event.params.phrase
    if (phrase == null) return
    this.inputTarget.value = phrase
    this.inputTarget.focus()
  }

  anhaengen(daten) {
    if (this.hasLeerTarget) this.leerTarget.remove()

    if (daten.system) {
      this.anhaengenSystem(daten)
      return
    }

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
    text.appendChild(this.renderText(daten.text))
    bubble.appendChild(text)

    const zeit = document.createElement('p')
    zeit.className = 'text-[10px] text-tisch-400 text-right leading-none mt-0.5'
    zeit.textContent = daten.zeit
    bubble.appendChild(zeit)

    zeile.appendChild(bubble)
    this.messagesTarget.appendChild(zeile)
    this.scrollToBottom()
  }

  anhaengenSystem(daten) {
    const zeile = document.createElement('div')
    zeile.className = 'flex justify-center'

    const p = document.createElement('p')
    p.className = 'max-w-[90%] text-center text-[11px] italic text-tisch-400 leading-snug px-2 py-0.5'

    const text = document.createElement('span')
    text.className = 'break-words'
    text.textContent = daten.text
    p.appendChild(text)

    const zeit = document.createElement('span')
    zeit.className = 'text-tisch-500 not-italic'
    zeit.textContent = ` · ${daten.zeit}`
    p.appendChild(zeit)

    zeile.appendChild(p)
    this.messagesTarget.appendChild(zeile)
    this.scrollToBottom()
  }

  // Regex aus den bekannten Emoji-Sequenzen (längste zuerst, damit z. B.
  // Kartenfarben mit Variations-Selektor vollständig matchen).
  baueEmojiRegex() {
    const keys = Object.keys(this.emojiMapValue ?? {})
    if (keys.length === 0) return null
    keys.sort((a, b) => b.length - a.length)
    const escaped = keys.map((k) => k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    return new RegExp('(' + escaped.join('|') + ')', 'gu')
  }

  // Wandelt Nachrichtentext in einen DocumentFragment: bekannte Emojis werden
  // durch <img> ersetzt, alles andere bleibt Textknoten (XSS-sicher).
  renderText(str) {
    const fragment = document.createDocumentFragment()
    if (!this.emojiRegex) {
      fragment.appendChild(document.createTextNode(str))
      return fragment
    }

    for (const teil of str.split(this.emojiRegex)) {
      if (teil === '') continue
      const url = this.emojiMapValue[teil]
      if (url) {
        const img = document.createElement('img')
        img.className = 'chat-emoji'
        img.src = url
        img.alt = teil
        img.draggable = false
        fragment.appendChild(img)
      } else {
        fragment.appendChild(document.createTextNode(teil))
      }
    }
    return fragment
  }

  scrollToBottom() {
    if (this.hasMessagesTarget) this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight
  }
}
