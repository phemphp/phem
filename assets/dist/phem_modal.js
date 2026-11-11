// The modal state of a popup, for `phem-menu` and `phem-context-menu` with their `modal` value.
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
// The page and the body are out of the root of Phem: the morph does not change them. The
// snapshot of a Turbo visit gets the styles of the page back: a page from the cache is not
// locked, and a modal popup that it opens locks it again.

// The open modal popups: the page gets its styles back when the last one closes. A new
// generation starts with each snapshot: the unlocks of the old one do not count.
let count = 0
let saved = null
let generation = 0

document.addEventListener("turbo:before-cache", () => {
  if (count === 0) return

  unlock()
  count = 0
  generation++
})

export function modal(popup) {
  if (count++ === 0) lock()

  const locked = generation
  const before = popup.style.getPropertyValue("pointer-events")
  popup.style.setProperty("pointer-events", "auto")
  let done = false

  return () => {
    if (done) return

    done = true
    if (before) popup.style.setProperty("pointer-events", before)
    else popup.style.removeProperty("pointer-events")
    if (popup.getAttribute("style") === "") popup.removeAttribute("style")

    if (locked === generation && --count === 0) unlock()
  }
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
  body.style.pointerEvents = "none"
}

function unlock() {
  const html = document.documentElement
  const body = document.body

  html.style.overflow = saved.overflow
  html.style.paddingRight = saved.paddingRight
  body.style.pointerEvents = saved.pointerEvents
  for (const el of [html, body]) if (el.getAttribute("style") === "") el.removeAttribute("style")
  saved = null
}
