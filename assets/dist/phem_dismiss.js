// The dismiss of an open popup: Escape, or a press outside.
//
// The open popups are layers: Escape closes only the last layer that opened (a tooltip in a
// popover closes first, then the popover). A layer whose `dismiss` returns false does not close
// (for example a popover with no dismiss): Escape goes to the layer below it. The keydown of an
// Escape that closed a layer gets `preventDefault()`, so a `<dialog>` around the popup stays open.
// A press outside is a click whose path does not have one of the `inside` elements: the path, not
// contains(), because an action can remove the element of the click.
//
//     const off = dismissable({ inside: [root], dismiss: (reason) => this.close(reason) })
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

document.addEventListener("click", (event) => {
  const path = event.composedPath()

  // A click in a layer closes the layers that opened after it, not the layer itself.
  for (const layer of [...layers].reverse()) {
    if (layer.inside().some((el) => path.includes(el))) return

    if (layer.outside) layer.dismiss("outside", event)
  }
})

// Adds a layer. `inside` is a list of elements, or a function that gives it. `outside: false`
// keeps the layer open on a press outside. Returns the function that removes the layer.
export function dismissable({ inside, dismiss, outside = true }) {
  const layer = { inside: typeof inside === "function" ? inside : () => inside, dismiss, outside }
  layers.push(layer)

  return () => {
    const at = layers.indexOf(layer)
    if (at !== -1) layers.splice(at, 1)
  }
}
