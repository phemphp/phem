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
// `phem:loading={{ save }}` in markup renders `data-phem-loading="<fiber>.<fingerprint>" hidden`.
// The element shows (no `hidden`) only while a `save` event of that component runs, with the same
// delay. A child component that gets `save` as a prop can send the event or show the element.
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
//
// While the browser is offline, the root element has the `phem-offline` class.
//
// When an event fails (a status that is not 2xx, for example a 500), the controller dispatches a
// cancelable `phem:error` event on the root of the component of the handler, with the `handler`
// id and the `status`. It bubbles up to `window`. When no listener calls `preventDefault()`, the
// controller shows a default message: a `.phem-error` element with `role="alert"` at the end of
// the body (`data-phem-error-message-value` changes its text). A click on it, or the next event
// that succeeds, removes it.
//
// A network error, a 502 or a 503 can be temporary: the controller sends the event again, up to
// `data-phem-retries-value` times (3 by default, 0 turns it off). It waits before each new try,
// and the wait doubles each time (`data-phem-retry-delay-value`, 500 ms by default, then 1 s, 2 s).
// A `Retry-After` header makes the wait longer. While the browser is offline, the next try waits
// for the `online` event. The loading state and the lock of the form stay until the last answer.
// When the last try fails with a network error, `phem:error` has a `status` of null.
//
// `data-phem-transition` on an element opts in to view transitions: when the component of the
// handler is in that element (or is that element), the morph runs in
// `document.startViewTransition()`. The value of the attribute is the type of the transition, for
// CSS `:active-view-transition-type(fade)`. A browser without the View Transitions API, or with
// `prefers-reduced-motion: reduce`, morphs with no transition.

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
  static values = {
    state: String,
    signature: String,
    loadingDelay: { type: Number, default: 200 },
    errorMessage: { type: String, default: "Something went wrong. Please try again." },
    retries: { type: Number, default: 3 },
    retryDelay: { type: Number, default: 500 },
  }

  connect() {
    this.queue = Promise.resolve()
    // element => the number of its events that show the loading state
    this.loading = new Map()
    // The events that show the loading state now: {handler, el}
    this.running = new Set()
    // form => (control => the property that the lock set), for each locked form
    this.locked = new Map()
    // The morph of an earlier event sets the attributes of the server render: show the loading
    // state again on an element with an event that still runs, lock a locked control again, and
    // show the offline state again on the root element.
    this.keepLoading = (event) => {
      const t = event.target
      if (this.loading.has(t)) showLoading(t, true)
      if (t.hasAttribute("data-phem-loading")) t.hidden = !this.isLoading(t)
      if (t === this.element) this.showOffline()
      for (const controls of this.locked.values()) {
        const prop = controls.get(t)
        if (prop) t[prop] = true
      }
    }
    this.element.addEventListener("turbo:morph-element", this.keepLoading)
    this.showOffline = () => this.element.classList.toggle("phem-offline", !navigator.onLine)
    this.showOffline()
    window.addEventListener("online", this.showOffline)
    window.addEventListener("offline", this.showOffline)
  }

  disconnect() {
    this.element.removeEventListener("turbo:morph-element", this.keepLoading)
    window.removeEventListener("online", this.showOffline)
    window.removeEventListener("offline", this.showOffline)
    this.hideError()
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
    const done = this.startLoading(el, handler)
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
  startLoading(el, handler) {
    const targets = new Set([el, el.closest("phem-c") ?? this.element])
    const event = { handler, el }
    let shown = false
    const timer = setTimeout(() => {
      shown = true
      targets.forEach((t) => this.markLoading(t, 1))
      this.running.add(event)
      this.showLoadingElements()
    }, this.loadingDelayValue)
    return () => {
      clearTimeout(timer)
      if (!shown) return
      targets.forEach((t) => this.markLoading(t, -1))
      this.running.delete(event)
      this.showLoadingElements()
    }
  }

  // Shows each `phem:loading` element with an event that runs, and hides the others.
  showLoadingElements() {
    for (const t of this.element.querySelectorAll("[data-phem-loading]")) t.hidden = !this.isLoading(t)
  }

  // Whether an event of the handler of a `phem:loading` element runs. Its value is the fiber of
  // its component and the fingerprint of the handler. A handler id is `<fiber>.<fingerprint>`,
  // with `~<n>` for the same handler two times. The event matches when it has the same
  // fingerprint, and one component is in the other: a child can get the handler as a prop, and
  // send the event or show the element.
  isLoading(el) {
    const scope = el.dataset.phemLoading
    const fiber = scope.slice(0, scope.indexOf("."))
    const fingerprint = scope.slice(fiber.length + 1)
    for (const { handler, el: source } of this.running) {
      if (handler.slice(handler.indexOf(".") + 1).split("~")[0] !== fingerprint) continue
      if (handler.startsWith(`${fiber}.`) || this.componentRoot(scope).contains(source) || this.componentRoot(handler).contains(el)) return true
    }
    return false
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

  // `beforeRender` runs when the last response arrives, before the page changes.
  async send(handler, event, beforeRender) {
    let response
    try {
      response = await this.fetchWithRetry(handler, event)
    } catch (e) {
      beforeRender()
      console.error("[phem] event failed (network error)", e)
      this.showError(handler, null)
      return
    }
    beforeRender()
    if (response.status === 409) {
      // The state is not valid any more (a new build, or a new secret): load the page again.
      Turbo.visit(window.location.href, { action: "replace" })
      return
    }
    if (response.status === 429) {
      // A #[RateLimit] of the page: the server did not run the handler, and the page does not
      // change. `phem:rate-limited` tells the page, with the seconds to wait (`Retry-After`).
      const retryAfter = Number.parseInt(response.headers.get("Retry-After") ?? "", 10)
      const detail = { handler, retryAfter: Number.isNaN(retryAfter) ? null : retryAfter }
      console.warn("[phem] too many events: the event was dropped", detail)
      this.componentRoot(handler).dispatchEvent(new CustomEvent("phem:rate-limited", { detail, bubbles: true }))
      return
    }
    if (!response.ok) {
      const text = await response.text()
      console.error(`[phem] event failed (${response.status})`, text)
      this.showError(handler, response.status)
      return
    }
    this.hideError()
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
    const rendered = data.streams ? this.renderStreams(data.streams, handler) : afterStreams()
    if (data.events?.length) {
      // After the morph, so a listener sees the new page.
      rendered.then(() => {
        const target = this.componentRoot(handler)
        for (const { name, detail } of data.events) target.dispatchEvent(new CustomEvent(`phem:${name}`, { detail, bubbles: true }))
      })
    }
  }

  // Renders the Turbo Streams of a response, in a view transition when the component of the
  // handler opts in. Resolves after the morph.
  renderStreams(streams, handler) {
    const el = this.componentRoot(handler).closest("[data-phem-transition]")
    if (!el || !this.element.contains(el) || !document.startViewTransition || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      Turbo.renderStreamMessage(streams)
      return afterStreams()
    }
    // Turbo renders each stream after the next repaint, but the page must not change before the
    // transition captures it. Take the render of each stream, and run them all in the update of
    // one transition.
    const renders = []
    let transition = null
    const take = (event) => {
      const { newStream, render } = event.detail
      renders.push(() => render(newStream))
      event.detail.render = () => transition.updateCallbackDone
    }
    document.addEventListener("turbo:before-stream-render", take)
    try {
      Turbo.renderStreamMessage(streams)
    } finally {
      document.removeEventListener("turbo:before-stream-render", take)
    }
    const update = () => Promise.all(renders.map((render) => render()))
    const type = el.dataset.phemTransition
    const types = type && type !== "true" ? type.split(/\s+/) : []
    try {
      transition = document.startViewTransition({ update, types })
    } catch {
      // A browser without transition types takes only the update function.
      transition = document.startViewTransition(update)
    }
    return transition.updateCallbackDone
  }

  // Posts the event. Sends it again after a network error, a 502 or a 503, with a longer wait
  // each time. The last try returns its response, or throws its network error.
  async fetchWithRetry(handler, event) {
    for (let attempt = 0; ; attempt++) {
      let response = null
      let error = null
      try {
        // The URL of the page, from the browser: always the same origin.
        response = await fetch(window.location.href, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json", "X-Phem-Event": "1" },
          body: JSON.stringify({ state: this.stateValue, signature: this.signatureValue, handler, event }),
        })
      } catch (e) {
        error = e
      }
      const temporary = error !== null || response.status === 502 || response.status === 503
      if (!temporary || attempt >= this.retriesValue) {
        if (error) throw error
        return response
      }
      let delay = this.retryDelayValue * 2 ** attempt
      const retryAfter = Number.parseInt(response?.headers.get("Retry-After") ?? "", 10)
      if (!Number.isNaN(retryAfter)) delay = Math.max(delay, retryAfter * 1000)
      console.warn(`[phem] event failed (${error ? "network error" : response.status}), new try in ${delay} ms`)
      await new Promise((resolve) => setTimeout(resolve, delay))
      if (!navigator.onLine) await new Promise((resolve) => window.addEventListener("online", resolve, { once: true }))
    }
  }

  // Dispatches `phem:error`, then shows the default message when no listener prevents it.
  showError(handler, status) {
    const event = new CustomEvent("phem:error", { detail: { handler, status }, bubbles: true, cancelable: true })
    if (!this.componentRoot(handler).dispatchEvent(event)) return
    this.hideError()
    // Outside the root element, so the morph does not remove it.
    const el = document.createElement("div")
    el.className = "phem-error"
    el.setAttribute("role", "alert")
    el.textContent = this.errorMessageValue
    el.addEventListener("click", () => this.hideError())
    document.body.append(el)
    this.errorElement = el
  }

  hideError() {
    this.errorElement?.remove()
    this.errorElement = null
  }

  // The root of the component of a handler: the <phem-c> with the fiber id at the start of the
  // handler id. The root element when the component is the page, or when the morph removed it.
  componentRoot(handler) {
    const el = document.getElementById(handler.slice(0, handler.indexOf(".")))
    return el?.localName === "phem-c" && this.element.contains(el) ? el : this.element
  }
}
