import { Controller } from "@hotwired/stimulus"

// The `phem-optimistic` controller: its actions change the element at once, before the server
// answers the event that the same click (or submit) sends. The change stays while the event runs,
// also after the morph of another event. When the event ends, the element shows the server
// render: the morph of the event confirms the change, and a failed event reverts it.
//
//     <li key={{ id }} data-controller="phem-optimistic">
//         <button onClick={{ delete }} data-action="phem-optimistic#hide">Delete</button>
//     </li>
//
// The changed element is the controller element. Put the action on the element with the event
// handler: the controller follows the events of the elements that ran an action.
export default class PhemOptimisticController extends Controller {
  static values = { class: String }

  connect() {
    // The changes in order: {key, apply}, where apply(el) makes the change
    this.ops = []
    // key => the last server value of the key ("hidden", or "class:<token>")
    this.server = new Map()
    // The elements that ran an action
    this.triggers = new Set()
    // The events that the triggers sent and that did not end: {el, handler}
    this.sent = []

    this.onSend = (event) => {
      if (this.triggers.has(event.target)) this.sent.push({ el: event.target, handler: event.detail.handler })
    }
    this.element.addEventListener("phem:send", this.onSend)

    // The morph writes the server render: read the server values, and make the changes again.
    // It runs in the same task as the morph, so the page does not show the server render.
    this.onMorph = (event) => {
      if (event.target !== this.element || !this.ops.length) return

      for (const key of this.server.keys()) this.server.set(key, read(this.element, key))
      for (const { apply } of this.ops) apply(this.element)
    }
    this.element.addEventListener("turbo:morph-element", this.onMorph)

    // On the document: when the morph removed the trigger, `phem:done` starts on the root of its
    // component.
    this.onDone = (event) => {
      const i = this.sent.findIndex(({ el, handler }) => el === event.target || (!el.isConnected && handler === event.detail.handler))
      if (i === -1) return

      this.sent.splice(i, 1)
      if (this.sent.length) return

      // The last event ended: show the server values. After a morph they are on the element
      // already, and after a failed event this reverts the changes.
      for (const [key, value] of this.server) write(this.element, key, value)
      this.ops = []
      this.server.clear()
      this.triggers.clear()
    }
    document.addEventListener("phem:done", this.onDone)
  }

  disconnect() {
    this.element.removeEventListener("phem:send", this.onSend)
    this.element.removeEventListener("turbo:morph-element", this.onMorph)
    document.removeEventListener("phem:done", this.onDone)
  }

  hide(event) {
    this.change(event, "hidden", (el) => (el.hidden = true))
  }

  show(event) {
    this.change(event, "hidden", (el) => (el.hidden = false))
  }

  addClass(event) {
    const token = this.token(event)
    this.change(event, `class:${token}`, (el) => el.classList.add(token))
  }

  removeClass(event) {
    const token = this.token(event)
    this.change(event, `class:${token}`, (el) => el.classList.remove(token))
  }

  toggleClass(event) {
    const token = this.token(event)
    this.change(event, `class:${token}`, (el) => el.classList.toggle(token))
  }

  // The class of the action: its `class` param, or the `class` value.
  token(event) {
    const token = event.params?.class ?? this.classValue
    if (!token) throw new Error('[phem] phem-optimistic needs a class: set data-phem-optimistic-class-value or data-phem-optimistic-class-param')

    return token
  }

  // Keeps the server value of the key before its first change, then makes the change.
  change(event, key, apply) {
    if (!this.server.has(key)) this.server.set(key, read(this.element, key))

    this.ops.push({ key, apply })
    this.triggers.add(event.currentTarget)
    apply(this.element)
  }
}

function read(el, key) {
  return key === "hidden" ? el.hidden : el.classList.contains(key.slice("class:".length))
}

function write(el, key, value) {
  if (key === "hidden") el.hidden = value
  else el.classList.toggle(key.slice("class:".length), value)
}
