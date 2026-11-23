// The modal state of a popup, for `phem-menu` and `phem-context-menu` with their `modal` value,
// and the scroll lock of the page, for a modal `phem-dialog`.
//
// While a modal popup is open, the page does not scroll, and the elements out of the popup get no
// pointer events: the page has `pointer-events: none` and the popup `pointer-events: auto`. A
// press outside goes to the root of the page, so it closes the popup (phem_dismiss.js), and the
// element under the pointer gets no hover and no click. The width of the scroll bar stays, so the
// page does not move.
//
//     const unlock = modal(popup)
//     unlock() // when the popup closes
//
// scrollLock() locks only the scroll of the page: a modal `<dialog>` already makes the page inert.
//
//     const unlock = scrollLock()
//     unlock() // when the dialog closes
//
// dialogScrollLock() is the lock of a modal `<dialog>` (`phem-dialog`). phem.css keeps the scroll
// in the dialog and its `::backdrop` (`overscroll-behavior: contain`), so the page does not scroll
// and keeps its scroll bar. A browser applies it to a dialog with no overflow since Chrome 144 and
// Firefox 150. Safari does not, and no feature detection exists: the browsers with no `closedby`
// on a `<dialog>` (Safari) get the lock of scrollLock().
//
// The page and the body are out of the root of Phem: the morph does not change them. The
// snapshot of a Turbo visit gets the styles of the page back: a page from the cache is not
// locked, and a modal popup that it opens locks it again.

// The open locks: the page gets its styles back when the last one of a kind ends. A new
// generation starts with each snapshot: the unlocks of the old one do not count.
let scrolls = 0
let pointers = 0
let saved = null
let generation = 0

document.addEventListener("turbo:before-cache", () => {
  if (scrolls === 0) return

  scrolls = 0
  pointers = 0
  restore()
  generation++
})

export function modal(popup) {
  const unlockScroll = scrollLock()
  const locked = generation
  if (pointers++ === 0) document.body.style.pointerEvents = "none"

  const before = popup.style.getPropertyValue("pointer-events")
  popup.style.setProperty("pointer-events", "auto")
  let done = false

  return () => {
    if (done) return

    done = true
    if (before) popup.style.setProperty("pointer-events", before)
    else popup.style.removeProperty("pointer-events")
    if (popup.getAttribute("style") === "") popup.removeAttribute("style")

    if (locked === generation && --pointers === 0) document.body.style.pointerEvents = saved?.pointerEvents ?? ""
    unlockScroll()
  }
}

// Locks the scroll of the page. The width of the scroll bar stays, so the page does not move.
export function scrollLock() {
  if (scrolls++ === 0) lock()

  const locked = generation
  let done = false

  return () => {
    if (done) return

    done = true
    if (locked === generation && --scrolls === 0) restore()
  }
}

// Locks the scroll of the page under a modal `<dialog>` only in a browser that does not keep the
// scroll in the dialog with CSS (see above).
export function dialogScrollLock() {
  if ("closedBy" in HTMLDialogElement.prototype) return () => {}

  return scrollLock()
}

function lock() {
  const html = document.documentElement
  const body = document.body
  const scrollbar = window.innerWidth - html.clientWidth

  saved = {
    overflow: html.style.overflow,
    paddingRight: html.style.paddingRight,
    pointerEvents: body.style.pointerEvents,
  }

  html.style.overflow = "hidden"
  if (scrollbar > 0) html.style.paddingRight = `${scrollbar}px`
}

function restore() {
  const html = document.documentElement
  const body = document.body

  html.style.overflow = saved.overflow
  html.style.paddingRight = saved.paddingRight
  body.style.pointerEvents = saved.pointerEvents
  for (const el of [html, body]) if (el.getAttribute("style") === "") el.removeAttribute("style")
  saved = null
}
