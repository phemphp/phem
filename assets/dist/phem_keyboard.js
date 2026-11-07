// Opens the on-screen keyboard for a field that gets the focus after a server round trip.
//
// iOS (WebKit) opens the keyboard only for a focus() in a user gesture. `phem-autofocus` gives the
// focus after the answer of the server (a Turbo visit, or the morph of an event): the field gets
// the focus, and no keyboard opens. A link or an element with a handler opts in:
//
//     <a href="/search" data-phem-autofocus-keyboard>Search</a>
//     <button onClick={{ edit }} data-phem-autofocus-keyboard>Edit</button>
//
// In the tap, a hidden stand-in field gets the focus, and the keyboard opens. When the focus moves
// to the real field, iOS keeps the keyboard open, and the stand-in goes. The stand-in is a child of
// `<html>`, outside `<body>`: the body swap of a Turbo visit does not remove it. When no field gets
// the focus, the stand-in goes, and the keyboard closes.
//
// phem_controller.js imports this module, so it listens on each page.

const TRIGGER = "[data-phem-autofocus-keyboard]"

const AUTOFOCUS = '[data-controller~="phem-autofocus"]'

// The input types that open the keyboard.
const KEYBOARD_INPUTS = new Set(["text", "search", "email", "url", "tel", "password", "number", "date", "time", "datetime-local", "month", "week"])

// The time (ms) for `phem-autofocus` to connect after the render: its lazy module can load first.
const SETTLE = 1000

// The longest time (ms) that the stand-in waits: a visit that a listener cancels has no end.
const MAX_WAIT = 10000

// The hidden field that has the focus from the tap to the focus of the real field.
let standIn = null
let timer = null

export function isStandIn(el) {
  return el !== null && el === standIn
}

// In the tap: a link that Turbo visits, or an element that sends an event.
document.addEventListener("turbo:click", (event) => open(event.target))
addEventListener("phem:queue", (event) => open(event.target))

// The end of the visit or of the event. A redirect visits a page next.
document.addEventListener("turbo:load", settle)
document.addEventListener("turbo:fetch-request-error", release)
addEventListener("phem:done", (event) => event.detail?.redirect || settle())

// The real field (or another element) has the focus.
document.addEventListener("focusin", (event) => event.target === standIn || release())

function open(el) {
  if (standIn || !(el instanceof Element) || !el.closest(TRIGGER)) return

  // The keyboard is open, and the field that has the focus keeps it.
  const active = document.activeElement
  if (active && opensKeyboard(active)) return

  standIn = document.createElement("input")
  standIn.setAttribute("aria-hidden", "true")
  standIn.tabIndex = -1
  // 16px: iOS zooms in on a smaller field. Fixed in the view: the page does not scroll.
  standIn.style.cssText = "position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;border:0;padding:0;caret-color:transparent;pointer-events:none"
  document.documentElement.append(standIn)
  standIn.focus()

  wait(MAX_WAIT)
}

function settle() {
  if (!standIn) return

  wait(document.querySelector(AUTOFOCUS) ? SETTLE : 0)
}

function wait(ms) {
  clearTimeout(timer)
  timer = setTimeout(release, ms)
}

function release() {
  clearTimeout(timer)
  if (!standIn) return

  const el = standIn
  standIn = null
  el.blur()
  el.remove()
}

function opensKeyboard(el) {
  if (el.isContentEditable) return true
  if (el.disabled || el.readOnly) return false

  return el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && KEYBOARD_INPUTS.has(el.type))
}
