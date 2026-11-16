// A tap on the backdrop of a modal `<dialog>`. `phem-dialog` and `phem-confirm` close their dialog
// on it.
//
// A click on the backdrop has the dialog as its target, and a point outside the box of the dialog.
// A click on the padding of the dialog is inside the box. The press must start on the backdrop
// too: a text selection that starts in the dialog and ends on the backdrop gives a click on the
// dialog (the element around the two points), outside its box. A click with no press (`click()`
// in a script) is not a tap.
//
// The box is the box that the user sees, with its CSS transforms: while a bottom sheet slides in,
// a tap above the sheet is a tap on the backdrop.
//
// A press on the backdrop does not move the focus: the `mousedown` gets `preventDefault()`. The
// focus stays in the dialog until the close, and then goes back to the trigger with its focus
// ring, after an open with the keyboard.
//
// The browser support of `closedby="any"` is not complete (Safari has none), so the dialog
// listens itself.

// Calls `tap` on a tap on the backdrop of `dialog`. The listeners stop when `signal` aborts.
//
// A `<dialog>` that is a viewport (`phem-dialog`) gives its own tests: `outside` for a press and a
// click outside the popup, and `backdrop` for a press on the viewport itself, which does not move
// the focus. A field in the viewport outside the popup still gets the focus of a press.
export function onBackdropTap(dialog, tap, signal, { outside = (event) => outsideBox(dialog, event), backdrop = outside } = {}) {
  const options = { signal }
  let pressed = false

  dialog.addEventListener("pointerdown", (event) => (pressed = outside(event)), options)
  dialog.addEventListener("mousedown", (event) => {
    if (backdrop(event)) event.preventDefault()
  }, options)
  dialog.addEventListener("click", (event) => {
    const tapped = pressed && outside(event)
    pressed = false
    if (tapped) tap()
  }, options)
}

function outsideBox(dialog, event) {
  if (event.target !== dialog) return false

  const box = dialog.getBoundingClientRect()

  return event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom
}
