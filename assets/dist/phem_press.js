// The parts of a press of the pointer that `phem-sortable`, `phem-toast` and the swipe of a
// drawer share.

// A press on these elements does not start a drag or a swipe.
export const INTERACTIVE = "input, textarea, select, button, a[href], [contenteditable], label"

// The click after a drag or a swipe does not run a handler, and is not a tap on a backdrop.
export function noClick() {
  const stop = (event) => {
    event.preventDefault()
    event.stopPropagation()
  }
  window.addEventListener("click", stop, { capture: true, once: true })
  setTimeout(() => window.removeEventListener("click", stop, { capture: true }), 0)
}
