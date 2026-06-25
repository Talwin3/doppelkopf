/**
 * Programmatische Dialoge auf Basis des nativen <dialog>-Elements.
 *
 * - openDialog(inhalt, {modal}) → erzeugt einen Dialog mit beliebigem Inhalt.
 * - confirmDialog({...}) → Promise<boolean>, Ersatz für window.confirm().
 * - alertDialog({...})   → Promise<void>.
 *
 * Modal nutzt showModal() (Backdrop, Fokus-Falle, ESC); nicht-modal nutzt show().
 * Für die deklarative Variante (Markup + Trigger) siehe dialog_controller.js.
 */

function basisDialog(modal = true) {
  const dlg = document.createElement('dialog')
  dlg.className = 'dialog backdrop:bg-black/60 bg-transparent p-0 border-0 w-[92vw] max-w-md'
  document.body.appendChild(dlg)

  const schliessen = () => {
    dlg.addEventListener('close', () => dlg.remove(), { once: true })
    if (dlg.open) dlg.close()
    else dlg.remove()
  }

  // Klick auf Backdrop schließt (Ziel ist das <dialog> selbst).
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.dispatchEvent(new Event('backdrop')) })

  return { dlg, schliessen, modal }
}

/**
 * Zeigt einen Dialog mit frei gestaltetem Inhalt (HTMLElement oder HTML-String,
 * vertrauenswürdig). Gibt das <dialog>-Element zurück; close()/remove() erfolgt
 * über den mitgelieferten schliessen()-Callback im Inhalt oder durch ESC.
 */
export function openDialog(inhalt, { modal = true } = {}) {
  const { dlg } = basisDialog(modal)
  const karte = document.createElement('div')
  karte.className = 'bg-tisch-800 rounded-2xl border border-tisch-600 shadow-2xl p-5 text-left text-white'
  if (inhalt instanceof Node) karte.appendChild(inhalt)
  else karte.innerHTML = inhalt
  dlg.appendChild(karte)

  dlg.addEventListener('backdrop', () => dlg.close())
  modal ? dlg.showModal() : dlg.show()
  return dlg
}

/**
 * Bestätigungsdialog. Auflösung: true = bestätigt, false = abgebrochen/ESC/Backdrop.
 *
 * @returns {Promise<boolean>}
 */
export function confirmDialog({
  title = 'Bist du sicher?',
  text = '',
  bestaetigen = 'OK',
  abbrechen = 'Abbrechen',
  gefahr = false,
} = {}) {
  return new Promise((resolve) => {
    const { dlg, schliessen } = basisDialog(true)

    const karte = document.createElement('div')
    karte.className = 'bg-tisch-800 rounded-2xl border border-tisch-600 shadow-2xl p-5 text-left'

    const h = document.createElement('h2')
    h.className = 'text-lg font-bold text-white mb-2'
    h.textContent = title
    karte.appendChild(h)

    if (text) {
      const p = document.createElement('p')
      p.className = 'text-sm text-tisch-200 mb-4 whitespace-pre-wrap'
      p.textContent = text
      karte.appendChild(p)
    }

    const leiste = document.createElement('div')
    leiste.className = 'flex justify-end gap-2 mt-4'

    const neinBtn = document.createElement('button')
    neinBtn.type = 'button'
    neinBtn.className = 'rounded-lg px-4 py-2 text-sm font-medium bg-tisch-700 hover:bg-tisch-600 text-tisch-100'
    neinBtn.textContent = abbrechen

    const jaBtn = document.createElement('button')
    jaBtn.type = 'button'
    jaBtn.className = gefahr
      ? 'rounded-lg px-4 py-2 text-sm font-bold bg-red-700 hover:bg-red-600 text-white'
      : 'rounded-lg px-4 py-2 text-sm font-bold bg-tisch-600 hover:bg-tisch-500 text-white'
    jaBtn.textContent = bestaetigen

    leiste.append(neinBtn, jaBtn)
    karte.appendChild(leiste)
    dlg.appendChild(karte)

    const fertig = (wert) => { schliessen(); resolve(wert) }
    jaBtn.addEventListener('click', () => fertig(true))
    neinBtn.addEventListener('click', () => fertig(false))
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); fertig(false) }) // ESC
    dlg.addEventListener('backdrop', () => fertig(false))

    dlg.showModal()
    neinBtn.focus()
  })
}

/** Einfacher Hinweisdialog mit einem Bestätigungsknopf. @returns {Promise<void>} */
export function alertDialog({ title = 'Hinweis', text = '', ok = 'OK' } = {}) {
  return new Promise((resolve) => {
    const { dlg, schliessen } = basisDialog(true)

    const karte = document.createElement('div')
    karte.className = 'bg-tisch-800 rounded-2xl border border-tisch-600 shadow-2xl p-5 text-left'
    karte.innerHTML = ''

    const h = document.createElement('h2')
    h.className = 'text-lg font-bold text-white mb-2'
    h.textContent = title
    karte.appendChild(h)

    if (text) {
      const p = document.createElement('p')
      p.className = 'text-sm text-tisch-200 mb-4 whitespace-pre-wrap'
      p.textContent = text
      karte.appendChild(p)
    }

    const leiste = document.createElement('div')
    leiste.className = 'flex justify-end mt-4'
    const okBtn = document.createElement('button')
    okBtn.type = 'button'
    okBtn.className = 'rounded-lg px-4 py-2 text-sm font-bold bg-tisch-600 hover:bg-tisch-500 text-white'
    okBtn.textContent = ok
    leiste.appendChild(okBtn)
    karte.appendChild(leiste)
    dlg.appendChild(karte)

    const fertig = () => { schliessen(); resolve() }
    okBtn.addEventListener('click', fertig)
    dlg.addEventListener('cancel', (e) => { e.preventDefault(); fertig() })
    dlg.addEventListener('backdrop', fertig)

    dlg.showModal()
    okBtn.focus()
  })
}
