// The keys of a list of items, for `phem-tabs`, `phem-menu` and `phem-combobox`: the arrow keys,
// Home, End, and a typeahead.
//
// With the `vertical` orientation, ArrowDown and ArrowUp move. With `horizontal`, ArrowRight and
// ArrowLeft move (the other way in a right-to-left page). With `loop`, the last item goes to the
// first one. A disabled item (`disabled`, or `aria-disabled="true"`) does not get the focus. A
// list can give its own `skip` test: `phem-menu` skips only an item with `disabled`.

export function isDisabled(el) {
  return el.disabled === true || el.getAttribute("aria-disabled") === "true"
}

// The item that `key` moves to from `current`, or undefined when the key does not move.
export function nextItem(items, current, key, { orientation = "vertical", loop = true, rtl = false, skip = isDisabled } = {}) {
  const enabled = items.filter((el) => !skip(el))
  if (enabled.length === 0) return undefined

  const forward = orientation === "vertical" ? "ArrowDown" : rtl ? "ArrowLeft" : "ArrowRight"
  const backward = orientation === "vertical" ? "ArrowUp" : rtl ? "ArrowRight" : "ArrowLeft"

  if (key === "Home") return enabled[0]
  if (key === "End") return enabled.at(-1)
  if (key !== forward && key !== backward) return undefined

  const step = key === forward ? 1 : -1
  const at = items.indexOf(current)

  // No current item: the first or the last one.
  if (at === -1) return step === 1 ? enabled[0] : enabled.at(-1)

  for (let i = 1; i <= items.length; i++) {
    let next = at + step * i
    if (next < 0 || next >= items.length) {
      if (!loop) return undefined

      next = (next + items.length) % items.length
    }

    if (!skip(items[next])) return items[next]
  }

  return undefined
}

// The keys that nextItem() reads, for an orientation.
export function movesFocus(key, orientation = "vertical") {
  if (key === "Home" || key === "End") return true

  return orientation === "vertical" ? key === "ArrowDown" || key === "ArrowUp" : key === "ArrowLeft" || key === "ArrowRight"
}

// The typeahead: the letters that the user types in a short time find the next item whose label
// starts with them. The text clears `resetMs` (500) after the last letter, and also after a letter
// that matches no item, as in Base UI: the next letter starts a new text. Space stays in the text.
//
// A new text starts the search from the `from` item of find() (the selected item of a select), and
// a text that continues starts it from `current` (the item with the focus).
export class Typeahead {
  #query = ""
  #timer = null
  #resetMs

  constructor({ resetMs = 500 } = {}) {
    this.#resetMs = resetMs
  }

  // The item for the key of `event`, or undefined when the key is not a letter.
  find(items, current, event, label = (el) => el.textContent, skip = isDisabled, from = current) {
    if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) return undefined
    if (event.key === " " && this.#query === "") return undefined

    // A new text starts from `from`.
    const origin = this.#query === "" ? from : current

    clearTimeout(this.#timer)
    this.#timer = setTimeout(() => (this.#query = ""), this.#resetMs)
    this.#query += event.key.toLowerCase()

    const enabled = items.filter((el) => !skip(el))
    const at = enabled.indexOf(origin)

    // The same letter again moves to the next item with it.
    const repeated = [...this.#query].every((c) => c === this.#query[0])
    const query = repeated ? this.#query[0] : this.#query
    const start = repeated ? at + 1 : Math.max(at, 0)
    const ordered = [...enabled.slice(start), ...enabled.slice(0, start)]

    const match = ordered.find((el) => label(el).trim().toLowerCase().startsWith(query))

    // No item matches: the next letter starts a new text. Space stays in the text.
    if (!match && event.key !== " ") this.reset()

    return match
  }

  reset() {
    clearTimeout(this.#timer)
    this.#query = ""
  }
}

// The pointer goes out of an item, and not to another item (to a separator, a label, or out of
// the list): `onLeave(event)` runs, for a `pointerout` event of the list (Base UI). A touch does
// not leave.
export function leaveList(event, items, onLeave) {
  if (event.pointerType === "touch") return

  const itemOf = (node) => (node instanceof Node ? items.find((item) => item.contains(node)) : undefined)
  if (!itemOf(event.target) || itemOf(event.relatedTarget)) return

  onLeave(event)
}
