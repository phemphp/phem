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
// `value` can be a list of state values (`["open", "triggerId"]`): each one follows these rules.
// `outside(el)` gives true for an element out of the controller element whose attributes the
// controller owns, for example a detached trigger (phem_triggers.js): the morph keeps its owned
// attributes, and its morph runs `morphed()` too.
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

  #outside
  // attribute => the last server value of the state value, and whether the server render of the
  // morph changed it.
  #servers = new Map()
  #serverChanged = new Map()
  // attribute => the time (performance.now()) of the last change of the state value by the client.
  #changedAt = new Map()
  #observer = null

  constructor(controller, { value = null, owns = () => false, morphed = () => {}, outside = () => false } = {}) {
    this.#controller = controller
    this.#value = value
    this.#owns = owns
    this.#morphed = morphed
    this.#outside = outside
  }

  connect() {
    this.#listening?.abort()
    this.#observer?.disconnect()
    this.#listening = new AbortController()

    const element = this.#controller.element
    const options = { signal: this.#listening.signal }
    const attributes = this.#attributes
    for (const attribute of attributes) {
      this.#servers.set(attribute, element.getAttribute(attribute))
      this.#serverChanged.set(attribute, false)
    }

    // The changes of the state values by the client.
    if (attributes.length > 0 && typeof MutationObserver === "function") {
      this.#observer = new MutationObserver((records) => this.#record(records))
      this.#observer.observe(element, { attributes: true, attributeFilter: attributes })
      states.add(this)
    }

    // The morph of the controller element: a new server value sets the state, unless the client
    // changed the value after the request of the answer left (`phemSentAt` of the phem root while
    // the streams of the answer render, see phem_controller.js). A push has no request.
    element.addEventListener("turbo:before-morph-element", (event) => {
      if (event.target !== element || attributes.length === 0) return

      this.flush()
      const sentAt = element.closest("[data-controller~='phem']")?.phemSentAt ?? null
      for (const attribute of attributes) {
        const next = event.detail.newElement?.getAttribute(attribute) ?? null
        const changedAt = this.#changedAt.get(attribute) ?? -Infinity
        this.#serverChanged.set(attribute, next !== this.#servers.get(attribute) && !(sentAt !== null && changedAt > sentAt))
        this.#servers.set(attribute, next)
      }
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

    // The elements out of the controller element that it owns (the detached triggers).
    const outside = (target) => target instanceof Element && !element.contains(target) && this.#outside(target)
    document.addEventListener("turbo:before-morph-attribute", (event) => {
      if (outside(event.target) && this.#owns(event.target, event.detail.attributeName)) event.preventDefault()
    }, options)
    document.addEventListener("turbo:morph-element", (event) => {
      if (outside(event.target)) this.#later()
    }, options)
  }

  disconnect() {
    this.#listening?.abort()
    this.#listening = null
    this.#observer?.disconnect()
    this.#observer = null
    states.delete(this)
  }

  flush() {
    this.#record(this.#observer?.takeRecords() ?? [])
  }

  // The time of the last change of each state value by the client.
  #record(records) {
    const now = performance.now()
    for (const record of records) this.#changedAt.set(record.attributeName, now)
  }

  #later() {
    if (this.#pending) return

    this.#pending = true
    queueMicrotask(() => {
      this.#pending = false
      if (this.#listening) this.#morphed()
    })
  }

  // The last value of the first state value attribute in a server render, or null.
  get server() {
    return this.#servers.get(this.#attributes[0]) ?? null
  }

  // The names of the attributes of the state values, as Stimulus reads them.
  get #attributes() {
    if (!this.#value) return []

    const values = Array.isArray(this.#value) ? this.#value : [this.#value]

    return values.map((value) => `data-${this.#controller.identifier}-${value.replace(/([A-Z])/g, "-$1").toLowerCase()}-value`)
  }

  #keeps(el, name) {
    if (el === this.#controller.element && this.#serverChanged.has(name)) return !this.#serverChanged.get(name)

    return this.#owns(el, name)
  }
}

// The attributes of the open state of a part (see phem_transition.js).
export const OPEN_ATTRIBUTES = new Set(["hidden", "data-open", "data-closed", "data-starting-style", "data-ending-style", "data-instant"])
