const ICONS = {
  success: `<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`,
  error: `<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>`,
}

const STYLES = {
  success: 'bg-green-600 text-white',
  error: 'bg-red-600 text-white',
}

export function toast(text, typ = 'success', dauerMs = 3000) {
  const container = document.getElementById('toast-container')
  if (!container) return

  const el = document.createElement('div')
  el.className = `pointer-events-auto flex items-center gap-2 px-4 py-2.5 rounded-lg shadow-lg text-sm font-medium
    ${STYLES[typ] || STYLES.success}
    transform translate-x-full opacity-0 transition-all duration-300 ease-out`
  el.innerHTML = `${ICONS[typ] || ''}<span>${text}</span>`
  container.appendChild(el)

  requestAnimationFrame(() => {
    el.classList.remove('translate-x-full', 'opacity-0')
  })

  setTimeout(() => {
    el.classList.add('translate-x-full', 'opacity-0')
    el.addEventListener('transitionend', () => el.remove(), { once: true })
    setTimeout(() => el.remove(), 400)
  }, dauerMs)
}
