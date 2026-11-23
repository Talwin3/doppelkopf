/**
 * Gemeinsame Mercure-Abos für alle Controller einer Seite.
 *
 * - Pro Topic gibt es genau EINE EventSource, auch wenn mehrere Controller
 *   (z. B. Spieltisch und Chat) dasselbe Topic hören.
 * - Beim Verlassen der Seite (pagehide) werden alle Verbindungen ausdrücklich
 *   geschlossen. Sonst bleiben sie in manchen Browsern offen, bis der Hub sie per
 *   Heartbeat als tot erkennt. Ohne HTTP/2 (6 Verbindungen je Host) staut das nach
 *   ein paar Seitenwechseln alle weiteren Anfragen – Klicks „hängen“ dann bis zu
 *   40 Sekunden.
 * - Kommt die Seite aus dem Back/Forward-Cache zurück (pageshow mit persisted),
 *   wird sie neu geladen, weil in der Zwischenzeit Ereignisse verpasst wurden.
 */

const abos = new Map() // schluessel → { url, quelle: EventSource|null, hoerer: Set<Function> }

function oeffnen(abo) {
  abo.quelle = new EventSource(abo.url)
  abo.quelle.onmessage = (event) => {
    // Nicht-JSON trotzdem weitergeben (als leeres Objekt): ein Ereignis ist ein Ereignis.
    let daten = {}
    try { daten = JSON.parse(event.data) ?? {} } catch { /* leer lassen */ }
    // Ein Fehler in einem Empfänger darf die anderen nicht um das Ereignis bringen.
    for (const hoerer of abo.hoerer) {
      try { hoerer(daten) } catch (e) { console.error(e) }
    }
  }
  // Browserseitiges Auto-Reconnect greift – kein manueller Retry nötig.
  abo.quelle.onerror = () => {}
}

/**
 * Abonniert ein Topic. Gibt eine Funktion zurück, die das Abo wieder beendet
 * (für disconnect() im Stimulus-Controller).
 *
 * @param {string} hubUrl
 * @param {string} topic
 * @param {(daten: object) => void} hoerer
 * @returns {() => void}
 */
export function abonnieren(hubUrl, topic, hoerer) {
  if (!hubUrl || !topic) return () => {}

  const url = new URL(hubUrl)
  url.searchParams.append('match', topic)
  const schluessel = url.toString()

  let abo = abos.get(schluessel)
  if (!abo) {
    abo = { url: schluessel, quelle: null, hoerer: new Set() }
    abos.set(schluessel, abo)
    oeffnen(abo)
  }
  // Nach pagehide ist die Quelle geschlossen; wird danach (wieder) abonniert, neu öffnen.
  if (!abo.quelle) oeffnen(abo)
  abo.hoerer.add(hoerer)

  let beendet = false
  return () => {
    // Mehrfacher Aufruf (z. B. disconnect nach DOM-Verschiebung) darf kein neueres
    // Abo desselben Topics schließen.
    if (beendet) return
    beendet = true
    abo.hoerer.delete(hoerer)
    if (abo.hoerer.size === 0 && abos.get(schluessel) === abo) {
      abo.quelle?.close()
      abos.delete(schluessel)
    }
  }
}

window.addEventListener('pagehide', () => {
  for (const abo of abos.values()) {
    abo.quelle?.close()
    abo.quelle = null
  }
})

// Zurück aus dem Back/Forward-Cache: Ereignisse aus der Zwischenzeit sind verpasst.
// Statt nur neu zu verbinden, die Seite frisch laden – dann stimmen Tisch, Chat und Punkte.
window.addEventListener('pageshow', (event) => {
  if (abos.size === 0) return
  if (event.persisted) {
    window.location.reload()
    return
  }
  // Sicherheitsnetz: falls nach pagehide doch nicht neu geladen wurde, Verbindungen öffnen.
  for (const abo of abos.values()) {
    if (!abo.quelle) oeffnen(abo)
  }
})
