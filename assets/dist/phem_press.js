// The parts of a press of the pointer that `phem-sortable`, `phem-toast` and the swipe of a
// drawer share.

// A press on these elements does not start a drag or a swipe. An element with
// `data-phem-swipe-ignore` is the opt-out of the app (Base UI `data-base-ui-swipe-ignore`).
export const INTERACTIVE = "input, textarea, select, button, a[href], [contenteditable], label, [data-phem-swipe-ignore]"

// The click after a drag or a swipe does not run a handler, and is not a tap on a backdrop.
export function noClick() {
  const stop = (event) => {
    event.preventDefault()
    event.stopPropagation()
  }
  window.addEventListener("click", stop, { capture: true, once: true })
  setTimeout(() => window.removeEventListener("click", stop, { capture: true }), 0)
}

// The type of the pointer of the last press in the page: `mouse`, `touch` or `pen`. A `click`
// event does not have it in all the browsers.
let pressType = null

document.addEventListener("pointerdown", (event) => (pressType = event.pointerType), { capture: true })

// Whether a touch made the click `event`. A click of the keyboard or of a script (`detail` is 0)
// has no pointer.
export function isTouchClick(event) {
  if (!event || event.detail === 0) return false

  return (event.pointerType || pressType) === "touch"
}
