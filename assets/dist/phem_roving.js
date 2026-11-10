// The keys of a list of items, for `phem-tabs`, `phem-menu` and `phem-combobox`: the arrow keys,
// Home, End, and a typeahead.
//
// With the `vertical` orientation, ArrowDown and ArrowUp move. With `horizontal`, ArrowRight and
// ArrowLeft move (the other way in a right-to-left page). With `loop`, the last item goes to the
// first one. A disabled item (`disabled`, or `aria-disabled="true"`) does not get the focus.

export function isDisabled(el) {
  return el.disabled === true || el.getAttribute("aria-disabled") === "true"
}

// The item that `key` moves to from `current`, or undefined when the key does not move.
export function nextItem(items, current, key, { orientation = "vertical", loop = true, rtl = false } = {}) {
  const enabled = items.filter((el) => !isDisabled(el))
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

    if (!isDisabled(items[next])) return items[next]
  }

  return undefined
}

// The keys that nextItem() reads, for an orientation.
export function movesFocus(key, orientation = "vertical") {
  if (key === "Home" || key === "End") return true

  return orientation === "vertical" ? key === "ArrowDown" || key === "ArrowUp" : key === "ArrowLeft" || key === "ArrowRight"
}

// The typeahead: the letters that the user types in a short time find the next item whose label
// starts with them.
export class Typeahead {
  #query = ""
  #timer = null

  // The item for the key of `event`, or undefined when the key is not a letter.
  find(items, current, event, label = (el) => el.textContent) {
    if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) return undefined
    if (event.key === " " && this.#query === "") return undefined

    clearTimeout(this.#timer)
    this.#timer = setTimeout(() => (this.#query = ""), 500)
    this.#query += event.key.toLowerCase()

    const enabled = items.filter((el) => !isDisabled(el))
    const at = enabled.indexOf(current)
    // The same letter again moves to the next item with it.
    const repeated = [...this.#query].every((c) => c === this.#query[0])
    const query = repeated ? this.#query[0] : this.#query
    const start = repeated ? at + 1 : Math.max(at, 0)
    const ordered = [...enabled.slice(start), ...enabled.slice(0, start)]

    return ordered.find((el) => label(el).trim().toLowerCase().startsWith(query))
  }

  reset() {
    clearTimeout(this.#timer)
    this.#query = ""
  }
}
