import { Controller } from '@hotwired/stimulus'

const MODI  = ['system', 'hell', 'dunkel']
const ICONS = { system: '🌐', hell: '☀️', dunkel: '🌙' }
const TITLES = { system: 'System-Design', hell: 'Helles Design', dunkel: 'Dunkles Design' }

export default class extends Controller {
  static targets = ['icon']

  connect() {
    this._updateIcon()
    // OS-Präferenz beobachten wenn "System" aktiv
    this._mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
    this._onSystemChange = () => {
      if ((localStorage.getItem('dk_theme') || 'system') === 'system') {
        this._apply('system')
      }
    }
    this._mediaQuery.addEventListener('change', this._onSystemChange)
  }

  disconnect() {
    this._mediaQuery?.removeEventListener('change', this._onSystemChange)
  }

  umschalten() {
    const aktuell = localStorage.getItem('dk_theme') || 'system'
    const naechster = MODI[(MODI.indexOf(aktuell) + 1) % MODI.length]
    localStorage.setItem('dk_theme', naechster)
    this._apply(naechster)
    this._updateIcon()
  }

  _apply(modus) {
    const html = document.documentElement
    if (modus === 'dunkel') {
      html.classList.add('dark')
    } else if (modus === 'hell') {
      html.classList.remove('dark')
    } else {
      this._mediaQuery.matches ? html.classList.add('dark') : html.classList.remove('dark')
    }
  }

  _updateIcon() {
    const modus = localStorage.getItem('dk_theme') || 'system'
    if (this.hasIconTarget) {
      this.iconTarget.textContent = ICONS[modus]
      this.iconTarget.title = TITLES[modus] + ' (klicken zum Wechseln)'
    }
  }
}
