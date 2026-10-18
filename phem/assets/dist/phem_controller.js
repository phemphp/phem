// The `phem` Stimulus controller: it keeps the component state in the browser, and it morphs
// the page with Turbo Streams. Turbo also gives the navigation (Turbo Drive).
//
// StimulusBundle registers it from the assets/controllers.json of the application (see
// ../package.json). Without StimulusBundle, register it: `application.register("phem", PhemController)`.
//
// The server renders the page with the state in `data-phem-state-value` (signed JSON).
// On an event, the controller posts {state, signature, handler, event} to the URL of the page,
// so the access rules of the page apply. The server runs the PHP handler and answers with
// Turbo Streams (morph of the changed components) and the new state. Events wait in a queue,
// so each one uses the newest state.
//
// While an event runs, the element that sent it and its component (the closest <phem-c>, or the
// root element) get `aria-busy="true"` and the `phem-loading` class. They show after a delay
// (`data-phem-loading-delay-value`, 200 ms by default), so a fast response does not flicker.
//
// While a submit event runs, the form is locked at once: its submit buttons and its other
// controls are disabled, and its text fields are read-only (so they keep the focus). A second
// submit of a locked form is ignored. The lock ends when the response arrives, before the morph,
// so the server render has the last word. `data-phem-disable="false"` on the form turns it off.
//
// A handler can send browser events (`$e->dispatcher->dispatch('toast', ['message' => 'Saved'])`).
// After the morph, the controller dispatches each one as a CustomEvent named `phem:<name>`, with
// the detail. The event starts on the root of the component of the handler (its <phem-c>, or the
// root element) and bubbles up to `window`: `data-action="phem:toast->toast#show"` on an
// ancestor, or `data-action="phem:toast@window->toast#show"` anywhere.

import * as Turbo from "@hotwired/turbo"
import { Controller } from "@hotwired/stimulus"

const TEXT_INPUTS = new Set(["text", "search", "email", "url", "tel", "password", "number", "date", "time", "datetime-local", "month", "week", "color", "range"])

function isTextLike(el) {
  return el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && TEXT_INPUTS.has(el.type))
}

// The fields of a form as [name, value] pairs, so a name can have more than one value
// (`<select multiple>`, checkboxes). The button that submits the form is a field too. A file
// cannot go in the JSON body, so it is left out.
function formFields(form, submitter) {
  return [...new FormData(form, submitter ?? null)].filter(([, value]) => typeof value === "string")
}

// Resolves after Turbo renders the streams of renderStreamMessage(): a <turbo-stream> waits for
// the next repaint (an animation frame, or a task when the page is hidden), then it renders.
// A callback of the same kind that starts later runs after it.
function afterStreams() {
  return new Promise((resolve) => (document.visibilityState === "hidden" ? setTimeout(resolve, 0) : requestAnimationFrame(() => setTimeout(resolve, 0))))
}

function showLoading(el, on) {
  if (on) el.setAttribute("aria-busy", "true")
  else el.removeAttribute("aria-busy")
  el.classList.toggle("phem-loading", on)
}

// Locks a form control: a text field becomes read-only, any other control becomes disabled.
// Returns the property that it set, or null when the control was already locked by the page.
function lockControl(control) {
  // A range or color input ignores `readonly`.
  const prop = isTextLike(control) && control.type !== "range" && control.type !== "color" ? "readOnly" : "disabled"
  if (control[prop] || control.disabled) return null
  control[prop] = true
  return prop
}

export default class PhemController extends Controller {
  static values = { state: String, signature: String, loadingDelay: { type: Number, default: 200 } }

  connect() {
    this.queue = Promise.resolve()
    // element => the number of its events that show the loading state
    this.loading = new Map()
    // form => (control => the property that the lock set), for each locked form
    this.locked = new Map()
    // The morph of an earlier event sets the attributes of the server render: show the loading
    // state again on an element with an event that still runs, and lock a locked control again.
    this.keepLoading = (event) => {
      const t = event.target
      if (this.loading.has(t)) showLoading(t, true)
      for (const controls of this.locked.values()) {
        const prop = controls.get(t)
        if (prop) t[prop] = true
      }
    }
    this.element.addEventListener("turbo:morph-element", this.keepLoading)
  }

  disconnect() {
    this.element.removeEventListener("turbo:morph-element", this.keepLoading)
  }

  // data-action="click->phem#dispatch input->phem#dispatch ..." on the root element.
  dispatch(event) {
    const el = this.handlerElement(event)
    if (!el) return
    if (event.type === "submit") {
      event.preventDefault()
      // The first submit of this form still runs.
      if (this.locked.has(el)) return
    }
    const handler = el.getAttribute(el._phemAttr)
    const payload = {
      type: event.type,
      value: "value" in el ? el.value : null,
      checked: "checked" in el ? el.checked : null,
      key: event.key ?? null,
      form: el instanceof HTMLFormElement ? formFields(el, event.submitter) : null,
    }
    const done = this.startLoading(el)
    // After formFields(): a disabled control is not in the form data.
    const unlock = event.type === "submit" ? this.lockForm(el) : () => {}
    this.queue = this.queue
      .then(() => this.send(handler, payload, unlock))
      .catch((e) => console.error("[phem]", e))
      .finally(() => {
        unlock()
        done()
      })
  }

  // Locks the controls of a form while its submit event runs. Returns the function that ends it.
  lockForm(form) {
    if (form.dataset.phemDisable === "false") return () => {}
    const controls = new Map()
    for (const control of form.elements) {
      const prop = lockControl(control)
      if (prop) controls.set(control, prop)
    }
    this.locked.set(form, controls)
    return () => {
      if (this.locked.get(form) !== controls) return
      this.locked.delete(form)
      controls.forEach((prop, control) => (control[prop] = false))
    }
  }

  // Shows the loading state of an event after the delay. Returns the function that ends it.
  startLoading(el) {
    const targets = new Set([el, el.closest("phem-c") ?? this.element])
    let shown = false
    const timer = setTimeout(() => {
      shown = true
      targets.forEach((t) => this.markLoading(t, 1))
    }, this.loadingDelayValue)
    return () => {
      clearTimeout(timer)
      if (shown) targets.forEach((t) => this.markLoading(t, -1))
    }
  }

  markLoading(el, delta) {
    const count = (this.loading.get(el) ?? 0) + delta
    if (count > 0) this.loading.set(el, count)
    else this.loading.delete(el)
    showLoading(el, count > 0)
  }

  handlerElement(event) {
    const find = (name) => {
      const el = event.target.closest?.(`[data-phem-on-${name}]`)
      if (el && this.element.contains(el)) {
        el._phemAttr = `data-phem-on-${name}`
        return el
      }
      return null
    }
    switch (event.type) {
      // React's onChange fires on each key for text inputs.
      case "input": {
        const el = find("input")
        if (el) return el
        const change = find("change")
        return change && isTextLike(change) ? change : null
      }
      case "change": {
        const el = find("change")
        return el && !isTextLike(el) ? el : null
      }
      default:
        return find(event.type)
    }
  }

  // `beforeRender` runs when the response arrives, before the page changes.
  async send(handler, event, beforeRender) {
    // The URL of the page, from the browser: always the same origin.
    const response = await fetch(window.location.href, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-Phem-Event": "1" },
      body: JSON.stringify({ state: this.stateValue, signature: this.signatureValue, handler, event }),
    })
    beforeRender()
    if (response.status === 409) {
      // The state is not valid any more (a new build, or a new secret): load the page again.
      Turbo.visit(window.location.href, { action: "replace" })
      return
    }
    if (!response.ok) {
      const text = await response.text()
      console.error(`[phem] event failed (${response.status})`, text)
      return
    }
    const data = await response.json()
    if (data.redirect) {
      // The handler returned a RedirectResponse. Turbo sets window.location for a URL that it
      // cannot visit, so a javascript: URL would run: visit only http(s) URLs.
      const url = new URL(data.redirect, window.location.href)
      if (url.protocol === "http:" || url.protocol === "https:") Turbo.visit(url.href)
      else console.error("[phem] redirect to a URL that is not http(s) was blocked", data.redirect)
      return
    }
    this.stateValue = data.state
    this.signatureValue = data.signature
    if (data.streams) Turbo.renderStreamMessage(data.streams)
    if (data.events?.length) {
      // After the morph, so a listener sees the new page.
      afterStreams().then(() => {
        const target = this.componentRoot(handler)
        for (const { name, detail } of data.events) target.dispatchEvent(new CustomEvent(`phem:${name}`, { detail, bubbles: true }))
      })
    }
  }

  // The root of the component of a handler: the <phem-c> with the fiber id at the start of the
  // handler id. The root element when the component is the page, or when the morph removed it.
  componentRoot(handler) {
    const el = document.getElementById(handler.slice(0, handler.indexOf(".")))
    return el?.localName === "phem-c" && this.element.contains(el) ? el : this.element
  }
}
