// The state of a built-in controller that belongs to the client, and the morph of an event.
//
// The morph sets the attributes of the server render. A controller keeps its state through it:
//
// - its state value (for example `data-phem-popover-open-value`) keeps the value of the client,
//   unless the server render gives a new value: then the new value sets the state;
// - the attributes that it `owns` (for example `hidden` on a popup, `data-open`, `aria-expanded`)
//   keep the value of the client;
// - after the morph of the controller element or of elements in it, `morphed()` lets the
//   controller render its state again: the morph removes the attributes that the server render
//   does not have, and the attributes that the controller does not own. It runs one time for all
//   the elements of a morph, in a microtask, so a large morph does not render the state again for
//   each element.
//
//     this.state = new ClientState(this, { value: "open", owns: (el, name) => …, morphed: () => … })
//     this.state.connect()   // in connect()
//     this.state.disconnect() // in disconnect()
export class ClientState {
  #controller
  #value
  #owns
  #morphed
  #listening = null
  #pending = false

  // The last server value of the state, and whether the server render of the morph changed it.
  #server = null
  #serverChanged = false

  constructor(controller, { value = null, owns = () => false, morphed = () => {} } = {}) {
    this.#controller = controller
    this.#value = value
    this.#owns = owns
    this.#morphed = morphed
  }

  connect() {
    this.#listening?.abort()
    this.#listening = new AbortController()

    const element = this.#controller.element
    const options = { signal: this.#listening.signal }
    this.#server = this.#attribute ? element.getAttribute(this.#attribute) : null

    // The morph of the controller element: a new server value sets the state.
    element.addEventListener("turbo:before-morph-element", (event) => {
      if (event.target !== element || !this.#attribute) return

      const next = event.detail.newElement?.getAttribute(this.#attribute) ?? null
      this.#serverChanged = next !== this.#server
      this.#server = next
    }, options)

    element.addEventListener("turbo:before-morph-attribute", (event) => {
      if (this.#keeps(event.target, event.detail.attributeName)) event.preventDefault()
    }, options)

    element.addEventListener("turbo:morph-element", () => this.#later(), options)
  }

  disconnect() {
    this.#listening?.abort()
    this.#listening = null
  }

  #later() {
    if (this.#pending) return

    this.#pending = true
    queueMicrotask(() => {
      this.#pending = false
      if (this.#listening) this.#morphed()
    })
  }

  // The name of the attribute of the state value, as Stimulus reads it.
  get #attribute() {
    if (!this.#value) return null

    const name = this.#value.replace(/([A-Z])/g, "-$1").toLowerCase()

    return `data-${this.#controller.identifier}-${name}-value`
  }

  #keeps(el, name) {
    if (el === this.#controller.element && name === this.#attribute) return !this.#serverChanged

    return this.#owns(el, name)
  }
}

// The attributes of the open state of a part (see phem_transition.js).
export const OPEN_ATTRIBUTES = new Set(["hidden", "data-open", "data-closed", "data-starting-style", "data-ending-style", "data-instant"])
