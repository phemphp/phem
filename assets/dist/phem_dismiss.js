// The dismiss of an open popup: Escape, or a press outside.
//
// The open popups are layers: Escape closes only the last layer that opened (a tooltip in a
// popover closes first, then the popover). A layer whose `dismiss` returns false does not close
// (for example a popover with no dismiss): Escape goes to the layer below it. The keydown of an
// Escape that closed a layer gets `preventDefault()`, so a `<dialog>` around the popup stays open.
// A press outside is a press whose path does not have one of the `inside` elements: the path, not
// contains(), because an action can remove the element of the press.
//
// The `event` option of a layer is the event of a press outside: `"click"` (the default), or
// `"pointerdown"` (each button, the right button too). A layer with `"pointerdown"` also closes at
// a click with no pointer (a click with the keyboard). The layers close from the top down: a press
// in a layer stops the walk, also at the click after a drag out of the layer, and the layers below
// a `"click"` layer wait for the click, so the top layer always closes first.
//
//     const off = dismissable({ inside: [root], dismiss: (reason) => this.close(reason), event: "pointerdown" })
//     off() // when the popup closes
//
// The reason is "escape" or "outside".

const layers = []

document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape" || event.defaultPrevented || event.isComposing) return

  for (const layer of [...layers].reverse()) {
    if (layer.dismiss("escape", event) === false) continue

    event.preventDefault()

    return
  }
})

// The last pointerdown: the layers that it served, the layer that has it in it, and its path. The
// click that follows does not close the served layers again. A drag from a layer ends with a click
// outside it (on the common ancestor): the click stops at that layer too, so it does not close.
// The path also stops the click at a layer that the press opened (a menu opens at the pointerdown
// on its trigger, after this listener): a drag from the trigger to outside keeps the menu open.
const pressed = { served: new Set(), inside: null, path: [] }

document.addEventListener("pointerdown", (event) => {
  pressed.served.clear()
  pressed.inside = null

  const path = event.composedPath()
  pressed.path = path

  // A press in a layer closes the layers that opened after it, not the layer itself.
  for (const layer of [...layers].reverse()) {
    if (layer.inside().some((el) => path.includes(el))) {
      pressed.inside = layer

      return
    }

    // A layer that closes at the click stays above: the layers below it wait for the click.
    if (layer.event !== "pointerdown") return

    pressed.served.add(layer)
    if (layer.outside) layer.dismiss("outside", event)
  }
}, { capture: true })

document.addEventListener("click", (event) => {
  const path = event.composedPath()

  // A click with no pointer (the keyboard, `detail` 0) has no pointerdown before it.
  const pointer = event.detail > 0
  const served = pointer ? new Set(pressed.served) : new Set()
  const stop = pointer ? pressed.inside : null
  const start = pointer ? pressed.path : []
  pressed.served.clear()
  pressed.inside = null
  pressed.path = []

  for (const layer of [...layers].reverse()) {
    if (layer === stop || layer.inside().some((el) => path.includes(el) || start.includes(el))) return

    if (layer.outside && !served.has(layer)) layer.dismiss("outside", event)
  }
})

// Adds a layer. `inside` is a list of elements, or a function that gives it. `outside: false`
// keeps the layer open on a press outside. `event` is the event of a press outside: `"click"` or
// `"pointerdown"`. Returns the function that removes the layer.
export function dismissable({ inside, dismiss, outside = true, event = "click" }) {
  const layer = { inside: typeof inside === "function" ? inside : () => inside, dismiss, outside, event }
  layers.push(layer)

  return () => {
    const at = layers.indexOf(layer)
    if (at !== -1) layers.splice(at, 1)
  }
}
