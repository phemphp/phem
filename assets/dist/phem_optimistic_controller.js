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

    // The DOM event of a trigger makes an event: `phem:queue` comes in its dispatch, after the
    // actions. A DOM event that makes no event (a submit of a form that is locked) keeps its
    // changes until the last event ends.
    this.onQueue = (event) => {
      const batch = this.batches.findLast((b) => b.id === null && b.trigger === event.target)
      if (batch) batch.id = event.detail.id
    }
    this.element.addEventListener("phem:queue", this.onQueue)

    // The answer of an event came: its morph shows the server render, so its changes stop. On the
    // document, as `phem:done`.
    this.onReceive = (event) => {
      for (const batch of this.batchesOf(event)) {
        batch.received = true
        batch.morphed = new Set()
      }
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
      for (const batch of this.batches) batch.morphed?.add(event.target)
    }
    this.element.addEventListener("turbo:morph-element", this.onMorph)

    // The server render does not have the inserted nodes: remove them before the render of a
    // stream, so a morph does not match a new item of the server render with them, and add them
    // again after it.
    this.onStream = (event) => {
      const stream = event.detail.newStream
      const targets = stream.hasAttribute("target") || stream.hasAttribute("targets") ? stream.targetElements : []
      const batches = this.batches.filter((b) => b.inserted.some(({ template }) => targets.some((t) => t.contains(template))))
      if (!batches.length) return

      const render = event.detail.render
      event.detail.render = async (element) => {
        for (const batch of batches) remove(batch)

        try {
          await render(element)
        } finally {
          for (const batch of batches) if (!batch.received) this.restore(batch)
        }
      }
    }
    document.addEventListener("turbo:before-stream-render", this.onStream)

    // On the document: when the morph removed the trigger, `phem:done` starts on the root of its
    // component.
    this.onDone = (event) => {
      const ended = this.batchesOf(event)
      if (!ended.length) return

      this.batches = this.batches.filter((b) => !ended.includes(b))

      // The last event ended: the DOM events that made no event end too.
      if (!this.batches.some((b) => b.id !== null)) ended.push(...this.batches.splice(0))

      // Show the server values of the elements of the ended changes, with the changes of the
      // events that wait. After a failed event this reverts its changes. An element that the
      // morph of the event wrote shows them already.
      const changed = new Set()
      for (const batch of ended) {
        remove(batch)
        for (const { el } of batch.ops) if (!batch.morphed?.has(el)) changed.add(el)
      }

      for (const el of changed) {
        for (const { kind, name, value } of this.server.get(el)?.values() ?? []) KINDS[kind].write(el, name, value)
        this.replay(el)
      }

      for (const batch of ended) {
        for (const { el } of batch.ops) if (!this.batches.some((b) => b.ops.some((op) => op.el === el))) this.server.delete(el)
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
  // form in its `data-phem-optimistic-field` elements (the values of a field joined with ", "). The
  // morph of the event shows the item of the server render, and the clone goes away when the event
  // ends.
  insert(event) {
    const trigger = event.currentTarget
    const form = trigger instanceof HTMLFormElement ? trigger : (trigger.form ?? trigger.closest("form"))
    const data = form ? new FormData(form, event.submitter ?? null) : null
    const field = `data-${this.identifier}-field`
    const name = this.param(event, "insert", "target")
    const templates = this.elements(event, "insert")
    if (!templates.every((template) => template instanceof HTMLTemplateElement)) throw new Error("[phem] phem-optimistic#insert needs a <template>: set data-phem-optimistic-target-param")

    const batch = this.batch(event)

    for (const [index, template] of templates.entries()) {
      const content = template.content.cloneNode(true)
      for (const el of content.querySelectorAll(`[${field}]`)) el.textContent = (data?.getAll(el.getAttribute(field)) ?? []).map(text).join(", ")

      const nodes = [...content.childNodes]
      template.before(content)
      batch.inserted.push({ name, index, template, nodes })
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

  // The changes of a DOM event: its actions add to one batch. `id` is the id of the DOM event in
  // `phem:queue`, and `morphed` the elements that the morph of its answer wrote.
  batch(event) {
    const last = this.batches.at(-1)
    if (last?.event === event) return last

    const batch = { event, trigger: event.currentTarget, id: null, received: false, morphed: null, ops: [], inserted: [] }
    this.batches.push(batch)

    return batch
  }

  // The batches of the DOM events that the event of a `phem:receive` or `phem:done` carries.
  batchesOf(event) {
    const ids = event.detail?.ids ?? []

    return this.batches.filter((b) => ids.includes(b.id))
  }

  // Makes the changes of the element again, for the events that did not get their answer.
  replay(el) {
    for (const { received, ops } of this.batches) {
      if (!received) for (const op of ops) if (op.el === el) op.apply(el)
    }
  }

  // Adds the inserted nodes of a batch again, before their template. A render that replaced the
  // template gives the new template of the same target.
  restore({ inserted }) {
    for (const entry of inserted) {
      if (!entry.template.isConnected && entry.name !== null) entry.template = this.targets.findAll(entry.name)[entry.index] ?? entry.template
      if (entry.template.isConnected) entry.template.before(...entry.nodes)
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
