// The state of a built-in controller that belongs to the client, and the morph of an event.
//
// The morph sets the attributes of the server render. A controller keeps its state through it:
//
// - its state value (for example `data-phem-popover-open-value`) keeps the value of the client,
//   unless the server render gives a new value: then the new value sets the state. A value that
//   the client changed after the request of the answer left stays, as a bound field does: the
//   server did not know it (a radio group with `phem:live`, and a second arrow key before the
//   answer of the first one);
// - the attributes that it `owns` (for example `hidden` on a popup, `data-open`, `aria-expanded`)
//   keep the value of the client;
// - after the morph of the controller element or of elements in it, `morphed()` lets the
//   controller render its state again: the morph removes the attributes that the server render
//   does not have, and the attributes that the controller does not own. It runs one time for all
//   the elements of a morph, in a microtask, so a large morph does not render the state again for
//   each element. It runs again when phem gives a field in the controller element the server
//   value after the morph (`phem:reset`, a value that the server did not take).
//
//     this.state = new ClientState(this, { value: "open", owns: (el, name) => …, morphed: () => … })
//     this.state.connect()   // in connect()
//     this.state.disconnect() // in disconnect()
// The connected states with a state value: phem_controller.js flushes their changes before a
// request leaves (see flushClientChanges()).
const states = new Set()

// Takes the changes of the state values that the observers did not report yet. A controller can
// change its state in the same click that sends an event: the change is before the request.
export function flushClientChanges() {
  for (const state of states) state.flush()
}

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
  // The time (performance.now()) of the last change of the state value by the client.
  #changedAt = -Infinity
  #observer = null

  constructor(controller, { value = null, owns = () => false, morphed = () => {} } = {}) {
    this.#controller = controller
    this.#value = value
    this.#owns = owns
    this.#morphed = morphed
  }

  connect() {
    this.#listening?.abort()
    this.#observer?.disconnect()
    this.#listening = new AbortController()

    const element = this.#controller.element
    const options = { signal: this.#listening.signal }
    this.#server = this.#attribute ? element.getAttribute(this.#attribute) : null

    // The changes of the state value by the client.
    if (this.#attribute && typeof MutationObserver === "function") {
      this.#observer = new MutationObserver(() => (this.#changedAt = performance.now()))
      this.#observer.observe(element, { attributes: true, attributeFilter: [this.#attribute] })
      states.add(this)
    }

    // The morph of the controller element: a new server value sets the state, unless the client
    // changed the value after the request of the answer left (`phemSentAt` of the phem root while
    // the streams of the answer render, see phem_controller.js). A push has no request.
    element.addEventListener("turbo:before-morph-element", (event) => {
      if (event.target !== element || !this.#attribute) return

      if (this.#observer?.takeRecords().length) this.#changedAt = performance.now()
      const sentAt = element.closest("[data-controller~='phem']")?.phemSentAt ?? null
      const next = event.detail.newElement?.getAttribute(this.#attribute) ?? null
      this.#serverChanged = next !== this.#server && !(sentAt !== null && this.#changedAt > sentAt)
      this.#server = next
    }, options)

    // The morph sets the attribute itself: not a change of the client.
    element.addEventListener("turbo:morph-element", (event) => {
      if (event.target === element) this.#observer?.takeRecords()
    }, options)

    element.addEventListener("turbo:before-morph-attribute", (event) => {
      if (this.#keeps(event.target, event.detail.attributeName)) event.preventDefault()
    }, options)

    element.addEventListener("turbo:morph-element", () => this.#later(), options)
    element.addEventListener("phem:reset", () => this.#later(), options)
  }

  disconnect() {
    this.#listening?.abort()
    this.#listening = null
    this.#observer?.disconnect()
    this.#observer = null
    states.delete(this)
  }

  flush() {
    if (this.#observer?.takeRecords().length) this.#changedAt = performance.now()
  }

  #later() {
    if (this.#pending) return

    this.#pending = true
    queueMicrotask(() => {
      this.#pending = false
      if (this.#listening) this.#morphed()
    })
  }

  // The last value of the state value attribute in a server render, or null.
  get server() {
    return this.#server
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
