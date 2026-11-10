// The live region of `$e->announce('3 results')` (see Phem\Announcements). The server sends the
// `announce` browser event after the morph, and this module puts its message in a hidden live
// region, so a screen reader reads it. There is no toast on the screen.
//
// The region is a child of the body, out of the root: the morph does not touch it. It exists
// before the first message: many screen readers do not read a live region that a page just
// added. A Turbo visit replaces the body: `turbo:load` adds the region again.

// The time between the clear of the region and its new text: a screen reader reads a new text,
// also the same message again.
const DELAY = 100

const REGION = "[data-phem-announcer]"

// The messages of one answer: the region shows them together.
let messages = []

addEventListener("phem:announce", (event) => {
  const message = typeof event.detail === "string" ? event.detail : event.detail?.message
  if (typeof message !== "string" || message.trim() === "") return

  messages.push(message)
  if (messages.length > 1) return

  liveRegion().replaceChildren()

  // The region of the page at this time: a visit can replace the body in the delay.
  setTimeout(() => {
    liveRegion().replaceChildren(...messages.map(paragraph))
    messages = []
  }, DELAY)
})

document.addEventListener("turbo:load", liveRegion)

// The snapshot of the page for the Back button: with no old message.
document.addEventListener("turbo:before-cache", () => document.querySelector(REGION)?.replaceChildren())

if (document.body) liveRegion()
else document.addEventListener("DOMContentLoaded", liveRegion, { once: true })

// The region of the page: the one of the body (also the one of a page from the cache of Turbo),
// or a new one.
function liveRegion() {
  const found = document.querySelector(REGION)
  if (found) return found

  const region = document.createElement("div")
  region.setAttribute("data-phem-announcer", "")
  region.setAttribute("role", "status")
  region.setAttribute("aria-live", "polite")
  // Visually hidden, but read by screen readers. The style of the element, not a style
  // attribute: a Content Security Policy with no 'unsafe-inline' style allows it.
  Object.assign(region.style, { position: "absolute", width: "1px", height: "1px", padding: "0", margin: "-1px", overflow: "hidden", clip: "rect(0, 0, 0, 0)", whiteSpace: "nowrap", border: "0" })
  document.body.append(region)

  return region
}

function paragraph(message) {
  const p = document.createElement("p")
  p.textContent = message

  return p
}
