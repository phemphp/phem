// The triggers of a popup that several elements open (`phem-tooltip`), as the triggers and the
// handle of Base UI.
//
// A trigger is a `trigger` target in the controller element, or a detached trigger: an element
// anywhere in the page with `data-<identifier>-for` and the `id` of the controller element.
//
//     <button data-phem-tooltip-for="delete-tip" aria-label="Delete">…</button>
//     <span id="delete-tip" data-controller="phem-tooltip">
//         <span data-phem-tooltip-target="popup" hidden>Delete</span>
//     </span>
//
// With `self: true`, the controller element is the trigger when it has no other trigger. The
// active trigger is the trigger that opened the popup: the anchor of the popup.
export class Triggers {
  #controller
  #self
  #active = null

  constructor(controller, { self = false } = {}) {
    this.#controller = controller
    this.#self = self
  }

  // The name of the attribute of a detached trigger.
  get attribute() {
    return `data-${this.#controller.identifier}-for`
  }

  // All the triggers, in the order of the page.
  get all() {
    const triggers = [...this.#controller.triggerTargets, ...this.#detached()]
    if (triggers.length === 0 && this.#self) return [this.#controller.element]

    return triggers.sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
  }

  // The trigger that opened the popup, or else the first trigger.
  get active() {
    if (this.#active?.isConnected && this.#is(this.#active)) return this.#active

    return this.all[0] ?? null
  }

  // Makes `trigger` the active trigger, and gives the trigger that was active.
  activate(trigger) {
    const previous = this.active
    if (trigger) this.#active = trigger

    return previous
  }

  // The trigger that has the node `el`, or null.
  of(el) {
    const node = el instanceof Element ? el : el?.parentElement
    if (!node) return null

    const target = this.#controller.triggerTargets.find((trigger) => trigger.contains(node))
    if (target) return target

    const detached = this.#detachedOf(node)
    if (detached) return detached

    const element = this.#controller.element
    if (this.#self && this.#controller.triggerTargets.length === 0 && element.contains(node) && this.#detached().length === 0) return element

    return null
  }

  // The trigger with this `id`, or null.
  byId(id) {
    if (!id) return null

    return this.all.find((trigger) => trigger.id === id) ?? null
  }

  // A node of a detached trigger: the listeners of the document get the events of these nodes.
  detached(el) {
    const node = el instanceof Element ? el : el?.parentElement

    return node ? this.#detachedOf(node) !== null : false
  }

  #is(trigger) {
    return this.all.includes(trigger)
  }

  #detachedOf(node) {
    const id = this.#controller.element.id
    const trigger = id ? node.closest(`[${this.attribute}]`) : null

    return trigger?.getAttribute(this.attribute) === id ? trigger : null
  }

  #detached() {
    const id = this.#controller.element.id
    if (!id) return []

    return [...document.querySelectorAll(`[${this.attribute}]`)].filter((el) => el.getAttribute(this.attribute) === id)
  }
}

// The direction of the trigger `to` from the trigger `from`, as the `data-activation-direction` of
// Base UI: up to two words, `left` or `right`, and `up` or `down`. Null when they are at the same
// place.
export function activationDirection(from, to) {
  if (!from || !to || from === to) return null

  const a = from.getBoundingClientRect()
  const b = to.getBoundingClientRect()
  const dx = b.left + b.width / 2 - (a.left + a.width / 2)
  const dy = b.top + b.height / 2 - (a.top + a.height / 2)
  const words = []
  if (Math.abs(dx) > 1) words.push(dx > 0 ? "right" : "left")
  if (Math.abs(dy) > 1) words.push(dy > 0 ? "down" : "up")

  return words.length > 0 ? words.join(" ") : null
}
