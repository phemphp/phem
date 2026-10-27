import { Controller } from "@hotwired/stimulus"

// The `phem-optimistic` controller: its actions change the page at once, before the server
// answers the event that the same click (or submit) sends. The change stays until the server
// answers the event, also after the morph of another event. The morph of the event shows the
// server render, which confirms the change, and a failed event reverts it. The changes of the
// events that wait in the queue stay.
//
//     <li key={{ id }} data-controller="phem-optimistic">
//         <button onClick={{ delete }} data-action="phem-optimistic#hide">Delete</button>
//     </li>
//
// An action changes the controller element, or the targets that its `target` param names. Put the
// action on the element with the event handler: the controller follows the events of the elements
// that ran an action.
//
// A param of the action element applies to all its actions. A param with the name of the action
// applies to that action only: `data-phem-optimistic-increment-target-param="count"`.
export default class PhemOptimisticController extends Controller {
  static values = { class: String }

  connect() {
    // The changes of each DOM event that ran an action, in order (see batch())
    this.batches = []
    // el => key => {kind, name, value}: the last server value of the changes of the element
    this.server = new Map()

    // The event of a trigger goes in the queue: in the dispatch of the DOM event, or after its
    // debounce. A DOM event that sends no event (a form that is locked) keeps its changes until
    // the last event ends.
    this.onQueue = (event) => {
      const batch = this.batches.find((b) => !b.queued && b.trigger === event.target)
      if (!batch) return

      batch.queued = true
      batch.handler = event.detail.handler
    }
    this.element.addEventListener("phem:queue", this.onQueue)

    // The answer of an event came: its morph shows the server render, so its changes stop. On the
    // document, as `phem:done`.
    this.onReceive = (event) => {
      const batch = this.batches[this.pendingIndex(event)]
      if (batch) batch.received = true
    }
    document.addEventListener("phem:receive", this.onReceive)

    // The morph writes the server render of an element: read its server values, and make the
    // changes of the events that wait again. It runs in the same task as the morph, so the page
    // does not show the server render.
    this.onMorph = (event) => {
      const values = this.server.get(event.target)
      if (!values) return

      for (const entry of values.values()) entry.value = KINDS[entry.kind].read(event.target, entry.name)
      this.replay(event.target)
    }
    this.element.addEventListener("turbo:morph-element", this.onMorph)

    // The server render does not have the inserted nodes: remove them before the morph, so the
    // morph does not match a new item of the server render with them, and add them again after it.
    this.onStream = (event) => {
      const target = document.getElementById(event.detail.newStream.getAttribute("target"))
      const batches = this.batches.filter((b) => b.inserted.some(({ template }) => target?.contains(template)))
      if (!batches.length) return

      const render = event.detail.render
      event.detail.render = async (element) => {
        for (const batch of batches) remove(batch)

        try {
          await render(element)
        } finally {
          for (const { received, inserted } of batches) {
            if (!received) for (const { template, nodes } of inserted) if (template.isConnected) template.before(...nodes)
          }
        }
      }
    }
    document.addEventListener("turbo:before-stream-render", this.onStream)

    // On the document: when the morph removed the trigger, `phem:done` starts on the root of its
    // component.
    this.onDone = (event) => {
      const i = this.pendingIndex(event)
      if (i === -1) return

      remove(this.batches.splice(i, 1)[0])

      // The last event ended: the DOM events that sent no event end too.
      if (!this.batches.some((b) => b.queued)) {
        for (const batch of this.batches) remove(batch)
        this.batches = []
      }

      // Show the server values, with the changes of the events that wait. After a morph the
      // values are on the page already, and after a failed event this reverts its changes.
      for (const [el, values] of this.server) {
        for (const { kind, name, value } of values.values()) KINDS[kind].write(el, name, value)
        this.replay(el)
        if (!this.batches.some((b) => b.ops.some((op) => op.el === el))) this.server.delete(el)
      }
    }
    document.addEventListener("phem:done", this.onDone)
  }

  disconnect() {
    this.element.removeEventListener("phem:queue", this.onQueue)
    document.removeEventListener("phem:receive", this.onReceive)
    this.element.removeEventListener("turbo:morph-element", this.onMorph)
    document.removeEventListener("turbo:before-stream-render", this.onStream)
    document.removeEventListener("phem:done", this.onDone)
  }

  hide(event) {
    this.change(event, "hide", "hidden", "", (el) => (el.hidden = true))
  }

  show(event) {
    this.change(event, "show", "hidden", "", (el) => (el.hidden = false))
  }

  addClass(event) {
    const token = this.token(event, "addClass")
    this.change(event, "addClass", "class", token, (el) => el.classList.add(token))
  }

  removeClass(event) {
    const token = this.token(event, "removeClass")
    this.change(event, "removeClass", "class", token, (el) => el.classList.remove(token))
  }

  toggleClass(event) {
    const token = this.token(event, "toggleClass")
    this.change(event, "toggleClass", "class", token, (el) => el.classList.toggle(token))
  }

  setAttribute(event) {
    const name = this.attribute(event, "setAttribute")
    const value = this.param(event, "setAttribute", "value") ?? ""
    this.change(event, "setAttribute", "attribute", name, (el) => el.setAttribute(name, value))
  }

  removeAttribute(event) {
    const name = this.attribute(event, "removeAttribute")
    this.change(event, "removeAttribute", "attribute", name, (el) => el.removeAttribute(name))
  }

  toggleAttribute(event) {
    const name = this.attribute(event, "toggleAttribute")
    this.change(event, "toggleAttribute", "attribute", name, (el) => el.toggleAttribute(name))
  }

  setText(event) {
    const text = this.param(event, "setText", "text") ?? ""
    this.change(event, "setText", "text", "", (el) => (el.textContent = text))
  }

  increment(event) {
    this.add(event, "increment", 1)
  }

  decrement(event) {
    this.add(event, "decrement", -1)
  }

  // Adds a clone of the content of the `<template>` before the template, with the values of the
  // form in its `data-phem-optimistic-field` elements. The morph of the event shows the item of the
  // server render, and the clone goes away when the event ends.
  insert(event) {
    const trigger = event.currentTarget
    const form = trigger instanceof HTMLFormElement ? trigger : (trigger.form ?? trigger.closest("form"))
    const data = form ? new FormData(form, event.submitter ?? null) : null
    const field = `data-${this.identifier}-field`
    const batch = this.batch(event)

    for (const template of this.elements(event, "insert")) {
      if (!(template instanceof HTMLTemplateElement)) throw new Error("[phem] phem-optimistic#insert needs a <template>: set data-phem-optimistic-target-param")

      const content = template.content.cloneNode(true)
      for (const el of content.querySelectorAll(`[${field}]`)) el.textContent = text(data?.get(el.getAttribute(field)))

      const nodes = [...content.childNodes]
      template.before(content)
      batch.inserted.push({ template, nodes })
    }
  }

  // Adds `by` (1 by default) times `sign` to the first integer in the text of the element.
  add(event, action, sign) {
    const by = Number(this.param(event, action, "by") ?? 1)
    if (!Number.isInteger(by)) throw new Error(`[phem] phem-optimistic#${action} needs an integer: data-phem-optimistic-by-param`)

    this.change(event, action, "text", "", (el) => {
      el.textContent = el.textContent.replace(/-?\d+/, (n) => String(Number(n) + sign * by))
    })
  }

  // The class of the action: its `class` param, or the `class` value.
  token(event, action) {
    const token = this.param(event, action, "class") ?? this.classValue
    if (!token) throw new Error("[phem] phem-optimistic needs a class: set data-phem-optimistic-class-value or data-phem-optimistic-class-param")

    return token
  }

  attribute(event, action) {
    const name = this.param(event, action, "attribute")
    if (!name) throw new Error(`[phem] phem-optimistic#${action} needs an attribute: set data-phem-optimistic-attribute-param`)

    return name
  }

  // A param of the action element, as a string: the param of the action, else the param of all its
  // actions. Not `event.params`, which reads "1.50" as the number 1.5.
  param(event, action, name) {
    const el = event.currentTarget
    const prefix = `data-${this.identifier}-`

    return el.getAttribute(`${prefix}${kebab(action)}-${name}-param`) ?? el.getAttribute(`${prefix}${name}-param`)
  }

  // The elements of the action: the targets that its `target` param names, or the controller
  // element.
  elements(event, action) {
    const name = this.param(event, action, "target")
    if (name === null) return [this.element]

    const targets = this.targets.findAll(name)
    if (!targets.length) throw new Error(`[phem] phem-optimistic has no "${name}" target: set data-phem-optimistic-target="${name}"`)

    return targets
  }

  // The changes of a DOM event: its actions add to one batch.
  batch(event) {
    const last = this.batches.at(-1)
    if (last?.event === event) return last

    const batch = { event, trigger: event.currentTarget, handler: null, queued: false, received: false, ops: [], inserted: [] }
    this.batches.push(batch)

    return batch
  }

  // The index of the batch of the event of a `phem:receive` or `phem:done`, or -1: the oldest
  // batch of the trigger in the queue, as the queue sends the events in order. When the morph
  // removed the trigger, the event starts on the root of its component.
  pendingIndex(event) {
    return this.batches.findIndex(({ trigger, handler, queued }) => queued && (trigger === event.target || (!trigger.isConnected && handler === event.detail.handler)))
  }

  // Makes the changes of the element again, for the events that did not get their answer.
  replay(el) {
    for (const { received, ops } of this.batches) {
      if (!received) for (const op of ops) if (op.el === el) op.apply(el)
    }
  }

  // Keeps the server value of each element before its first change of the key, then makes the
  // change.
  change(event, action, kind, name, apply) {
    const key = `${kind}:${name}`
    const elements = this.elements(event, action)
    const batch = this.batch(event)

    for (const el of elements) {
      const values = this.server.get(el) ?? new Map()
      if (!values.has(key)) values.set(key, { kind, name, value: KINDS[kind].read(el, name) })
      this.server.set(el, values)

      batch.ops.push({ el, apply })
      apply(el)
    }
  }
}

// The kinds of change: read() gives the server value, and write() shows it again.
const KINDS = {
  hidden: {
    read: (el) => el.hidden,
    write: (el, _name, value) => (el.hidden = value),
  },
  class: {
    read: (el, name) => el.classList.contains(name),
    write: (el, name, value) => el.classList.toggle(name, value),
  },
  attribute: {
    read: (el, name) => el.getAttribute(name),
    write: (el, name, value) => (value === null ? el.removeAttribute(name) : el.setAttribute(name, value)),
  },
  // The child nodes, so the element gets its markup back, not only its text.
  text: {
    read: (el) => [...el.childNodes].map((node) => node.cloneNode(true)),
    write: (el, _name, nodes) => {
      if (el.textContent !== nodes.map((node) => node.textContent).join("")) el.replaceChildren(...nodes.map((node) => node.cloneNode(true)))
    },
  },
}

function kebab(name) {
  return name.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)
}

// The text of a form value: the name of a file.
function text(value) {
  return value instanceof File ? value.name : (value ?? "")
}

// Removes the nodes that insert() added for a batch.
function remove({ inserted }) {
  for (const { nodes } of inserted) for (const node of nodes) node.remove()
}
