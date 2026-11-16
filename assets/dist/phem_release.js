// The release of a press that clicks the item under the pointer, as in Base UI: a press on the
// trigger of a menu, a move to an item and a release run the item (`phem-menu`), and also a press
// of the right button in `phem-context-menu`. Only the next release counts, and only `delay` ms
// after the arm: a quick click on the trigger does not run the item that opens under the pointer.
//
//     this.release = new ReleaseClick({ itemOf: (target) => this.#itemOf(target), isOpen: () => this.openValue })
//     this.release.arm({ delay: 200, accepts: (event) => event.button === 0 })
//     this.release.disarm() // in disconnect()
export class ReleaseClick {
  #itemOf
  #isOpen
  #listening = null
  #timer = null
  #ready = false

  // `itemOf(target)` gives the item to click at the release, or null for no click.
  constructor({ itemOf, isOpen }) {
    this.#itemOf = itemOf
    this.#isOpen = isOpen
  }

  // Waits for the next `pointerup`. `accepts(event)` checks the release (its button, its point).
  arm({ delay, accepts }) {
    this.disarm()
    this.#listening = new AbortController()
    this.#ready = false
    this.#timer = setTimeout(() => (this.#ready = true), delay)

    document.addEventListener("pointerup", (event) => {
      clearTimeout(this.#timer)
      if (!this.#ready || !accepts(event) || !this.#isOpen()) return

      const item = this.#itemOf(event.target)
      if (!item) return

      // A click of the mouse, as in Base UI: the handler of the item runs with the keys and the
      // point of the release.
      const { view, clientX, clientY, ctrlKey, shiftKey, altKey, metaKey } = event
      item.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1, view, clientX, clientY, ctrlKey, shiftKey, altKey, metaKey }))
    }, { once: true, signal: this.#listening.signal })
  }

  disarm() {
    clearTimeout(this.#timer)
    this.#listening?.abort()
    this.#listening = null
  }
}
