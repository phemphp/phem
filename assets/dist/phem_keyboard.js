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
// `<html>`, outside `<body>`: the body swap of a Turbo visit does not remove it. In an open
// `<dialog>`, it is a child of the dialog: a modal dialog makes the rest of the page inert. When no
// field gets the focus, the stand-in goes, the keyboard closes, and the element that had the focus
// before the tap gets it again.
//
// phem_controller.js imports this module, so it listens on each page.

const TRIGGER = "[data-phem-autofocus-keyboard]"

const AUTOFOCUS = '[data-controller~="phem-autofocus"]'

// The input types that open the keyboard. A date or a time field opens a picker.
const KEYBOARD_INPUTS = new Set(["text", "search", "email", "url", "tel", "password", "number"])

// The time (ms) for the lazy module of `phem-autofocus` to load and connect after the render.
const SETTLE = 1000

// The longest time (ms) of a visit: Turbo has no timeout of its own.
const MAX_VISIT = 30000

// The stand-in waits for the start of a visit: Turbo starts it in the task of the tap, or of the
// redirect of an event.
const VISIT_PROPOSED = "proposed"

// The stand-in waits for the end of a visit.
const VISITING = "visiting"

// The hidden field that has the focus from the tap to the focus of the real field, and the element
// that had the focus before it.
let standIn = null
let previous = null
let timer = null

// What ends the wait of the stand-in: a visit (VISIT_PROPOSED or VISITING), the id of an event of
// `phem:queue`, or null after the end (the stand-in waits for the focus of the real field).
let ending = null

// The `phem-autofocus` elements that connected: an element that did not connect yet waits for its
// lazy module.
const connected = new WeakSet()

export function isStandIn(el) {
  return el !== null && el === standIn
}

export function autofocusConnected(el) {
  connected.add(el)
}

// In the tap: a link that Turbo visits, or an element that sends an event.
document.addEventListener("turbo:click", (event) => {
  if (open(event.target)) proposeVisit()
})
addEventListener("phem:queue", (event) => {
  if (open(event.target)) ending = event.detail.id
})

document.addEventListener("turbo:visit", () => {
  if (ending !== VISIT_PROPOSED) return

  ending = VISITING
  wait(MAX_VISIT)
})
document.addEventListener("turbo:load", () => ending === VISITING && settle())
document.addEventListener("turbo:fetch-request-error", () => ending === VISITING && release())

addEventListener("phem:done", (event) => {
  if (!standIn || !event.detail.ids?.includes(ending)) return

  // The handler returned a redirect: Turbo visits the page next.
  if (event.detail.redirect) proposeVisit()
  else settle()
})

// The real field (or another element) has the focus.
document.addEventListener("focusin", (event) => event.target === standIn || release())

// A morph does not remove the stand-in from its dialog.
document.addEventListener("turbo:before-morph-element", (event) => event.target === standIn && event.preventDefault())

// Focuses a stand-in for a tap on `el`. Returns false when `el` does not opt in, or when the
// keyboard is open.
function open(el) {
  if (standIn || !(el instanceof Element) || !el.closest(TRIGGER)) return false

  // The field that has the focus keeps it, and its keyboard stays open.
  const active = document.activeElement
  if (active && opensKeyboard(active)) return false

  previous = active === document.body ? null : active
  const parent = el.closest("dialog[open]") ?? document.documentElement
  standIn = document.createElement("input")
  standIn.setAttribute("aria-hidden", "true")
  standIn.tabIndex = -1
  // 16px: iOS zooms in on a smaller field. Fixed in the view: the page does not scroll.
  standIn.style.cssText = "position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;border:0;padding:0;caret-color:transparent;pointer-events:none"
  parent.append(standIn)
  standIn.focus()

  return true
}

// A listener of `turbo:before-visit` can cancel the visit: then no `turbo:visit` comes in the task.
function proposeVisit() {
  ending = VISIT_PROPOSED
  wait(0, () => ending === VISIT_PROPOSED && release())
}

// The visit or the event ended. `phem-autofocus` connects after the render (Stimulus connects in a
// mutation observer callback), or after its lazy module loads.
function settle() {
  ending = null
  wait(0, () => {
    const waiting = [...document.querySelectorAll(AUTOFOCUS)].some((el) => !connected.has(el))
    if (waiting) wait(SETTLE)
    else release()
  })
}

function wait(ms, then = release) {
  clearTimeout(timer)
  timer = setTimeout(then, ms)
}

export function release() {
  clearTimeout(timer)
  if (!standIn) return

  const el = standIn
  const restore = document.activeElement === el && previous?.isConnected ? previous : null
  standIn = previous = ending = null
  el.blur()
  el.remove()

  restore?.focus({ preventScroll: true })
}

function opensKeyboard(el) {
  if (el.isContentEditable) return true
  if (el.disabled || el.readOnly) return false

  return el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && KEYBOARD_INPUTS.has(el.type))
}
