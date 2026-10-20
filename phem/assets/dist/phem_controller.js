// The `phem` Stimulus controller: it keeps the component state in the browser, and it morphs
// the page with Turbo Streams. Turbo also gives the navigation (Turbo Drive).
//
// StimulusBundle registers it from the assets/controllers.json of the application (see
// ../package.json). Without StimulusBundle, register it: `application.register("phem", PhemController)`.
//
// The server renders the page with the state in `data-phem-state-value` (signed JSON).
// On an event, the controller posts {signature, handler, event, bound} to the URL of the page,
// so the access rules of the page apply. The server finds the state of the signature in its
// cache. When the cache does not have it, the server answers 409 with `{"state": "required"}`
// before the handler runs, and the controller sends the event again with the state, one time.
// An event with files always sends the state, so the files do not upload two times. The server runs the PHP handler and answers with
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
// When an event fails (a status that is not 2xx, for example a 500, or a body that is not JSON),
// the controller dispatches a cancelable `phem:error` event on the root of the component of the
// handler, with the `handler` id and the `status`. It bubbles up to `window`. When no listener
// calls `preventDefault()`, the controller shows a default message: a `.phem-error` element with
// `role="alert"` at the end of the body (`data-phem-error-message-value` changes its text). A
// click on it, or the next event that succeeds, removes it.
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
//
// `phem:debounce={{ 300 }}` in markup renders `data-phem-debounce="300"`: the controller sends an
// event of the element only when no new event of the same handler comes for 300 ms. With `'blur'`,
// it sends the event when the element loses the focus. `phem:throttle={{ 300 }}` sends the first
// event at once, then at most one event each 300 ms, and always the last one. The events of a text
// input (`onChange`, `onInput`) have a debounce of 150 ms by default (`data-phem-debounce-value`
// on the root element, 0 turns it off). `onSubmit` and `onBlur` never wait. A blur sends the
// waiting event of the element, and any other event (a click, a submit) sends all the waiting
// events first, so no input is lost. An input or change event that is in the queue and did not
// start yet takes the value of a newer event of the same handler and element: one request only.
//
// `phem:bind={{ query }}` in markup renders `data-phem-bind="<fiber>.query"` and the value of the
// #[State] property. A change sends no request: each event sends the bound fields that the user
// changed (`bound`: key => value), and the server sets the properties before the handler. A field
// changed when its value is not its default value (the value of the server render). With
// `phem:live` (`data-phem-live`), a change sends an event with no handler, with the same waits as
// `onChange`. The morph keeps a field that the user changed after the request left. A value that
// the server did not take (the wrong type) goes back to the value of the server.
//
// A #[Url] property is in the query string of the page URL. The page renders its query in
// `data-phem-query-value` ({values, push}), and each event answers with the new query. The
// controller writes each key into the URL (null removes it), and keeps the other keys. A list is
// `key[]=a`, and an object is `key[a]=1` (a nested value of an array with keys). It
// replaces the history entry, or it adds one when a changed key is in `push`. It uses the history
// of Turbo, so back and forward are Turbo restoration visits: the server renders the state of
// that URL again (or Turbo shows its snapshot of that URL).
//
// The files of a form go with its event: the request is `multipart/form-data`, with the JSON of
// the event in the `_phem` part and each file under the name of its field. An event with no files
// is JSON. A file input with no file sends nothing.
//
// An event with files goes with XHR, not `fetch`, so the controller knows how much of the body
// has left. `phem:progress={{ save }}` in markup renders `data-phem-progress="<fiber>.<fingerprint>"
// hidden`, as `phem:loading`: the element shows only while the files of a `save` event upload (and
// until the response arrives), after the same delay. Its `--phem-progress` CSS property is the
// progress, from 0 to 1. A `<progress>` element also gets it as its `value` (times its `max`). An
// event with no files does not show it. On each step of the upload, the form dispatches a
// `phem:upload-progress` event that bubbles, with the `handler`, the `loaded` and `total` bytes of
// the body, the `progress`, and the `files`: {field, file, loaded, progress} for each file. The
// body has more than the files (the JSON of the event): the controller shares the progress
// between the files by their size, in their order.
//
// An event with no files and a JSON larger than 1 KiB goes compressed (`Content-Encoding: gzip`,
// with `CompressionStream`): an event sent again with the state has all the state of the page,
// and a browser does not compress a request body. A browser without `CompressionStream` sends the
// JSON as it is.

import * as Turbo from "@hotwired/turbo"
import { Controller } from "@hotwired/stimulus"

const TEXT_INPUTS = new Set(["text", "search", "email", "url", "tel", "password", "number", "date", "time", "datetime-local", "month", "week", "color", "range"])

function isTextLike(el) {
  return el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && TEXT_INPUTS.has(el.type))
}

// The fields of a form as [name, value] pairs, so a name can have more than one value
// (`<select multiple>`, checkboxes). The button that submits the form is a field too. The files
// are [name, File] pairs: a file input with no file gives an empty File, which is left out.
function formFields(form, submitter) {
  const fields = []
  const files = []
  for (const [name, value] of new FormData(form, submitter ?? null)) {
    if (typeof value === "string") fields.push([name, value])
    else if (value.name !== "" || value.size > 0) files.push([name, value])
  }
  return { fields, files }
}

// Resolves after Turbo renders the streams of renderStreamMessage(): a <turbo-stream> waits for
// the next repaint (an animation frame, or a task when the page is hidden), then it renders.
// A callback of the same kind that starts later runs after it.
function afterStreams() {
  return new Promise((resolve) => (document.visibilityState === "hidden" ? setTimeout(resolve, 0) : requestAnimationFrame(() => setTimeout(resolve, 0))))
}

// The bound fields (`phem:bind`): the value of a key, from all of its fields, as the server takes it.
function boundValue(fields) {
  const [first] = fields
  if (first instanceof HTMLInputElement && first.type === "checkbox") {
    return first.hasAttribute("data-phem-bind-list") ? fields.filter((f) => f.checked).map((f) => f.value) : first.checked
  }
  if (first instanceof HTMLInputElement && first.type === "radio") return fields.find((f) => f.checked)?.value ?? null
  if (first instanceof HTMLSelectElement && first.multiple) return [...first.selectedOptions].map((o) => o.value)
  return first.value
}

// The option that a single <select> shows with no change: the default one, or the first one.
function defaultOption(select) {
  const options = [...select.options]
  return options.findLast((o) => o.defaultSelected) ?? options.find((o) => !o.disabled) ?? null
}

// Whether the user changed a bound field: its value is not the value of the server render.
function isDirty(field) {
  if (field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")) return field.checked !== field.defaultChecked
  if (field instanceof HTMLSelectElement) {
    if (field.multiple) return [...field.options].some((o) => o.selected !== o.defaultSelected)
    return field.selectedOptions[0] !== defaultOption(field)
  }
  return field.value !== field.defaultValue
}

// Gives a bound field the value of the server render again.
function resetField(field) {
  if (field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")) field.checked = field.defaultChecked
  else if (field instanceof HTMLSelectElement && field.multiple) for (const o of field.options) o.selected = o.defaultSelected
  else if (field instanceof HTMLSelectElement) {
    const option = defaultOption(field)
    if (option) option.selected = true
  } else field.value = field.defaultValue
}

function sameValue(a, b) {
  return JSON.stringify(a) === JSON.stringify(b)
}

// The name of a query pair ("tags%5B%5D=a" is "tags"), or null when it is not valid.
function queryName(pair) {
  try {
    const name = decodeURIComponent(pair.split("=")[0].replace(/\+/g, " "))
    const bracket = name.indexOf("[")
    return bracket > 0 ? name.slice(0, bracket) : name
  } catch {
    return null
  }
}

// The query pairs of a value: none for null, `key[]=v` for each item of a list, and `key[k]=v`
// for each key of an object (an array with keys on the server).
function queryPairs(name, value) {
  return nestedPairs(encodeURIComponent(name), value)
}

function nestedPairs(key, value) {
  if (value === null || value === undefined) return []
  if (Array.isArray(value)) return value.map((v) => `${key}[]=${encodeURIComponent(v)}`)
  if (typeof value === "object") return Object.entries(value).flatMap(([k, v]) => nestedPairs(`${key}[${encodeURIComponent(k)}]`, v))
  return [`${key}=${encodeURIComponent(value)}`]
}

// The smallest JSON body that the controller compresses: the gzip header and the time to compress
// cost more than they save for a smaller body.
const GZIP_MIN_SIZE = 1024

// The JSON body of an event, compressed when it is large (see GZIP_MIN_SIZE). Resolves with the
// body and its headers. A Blob can go again with each new try.
async function jsonBody(json) {
  const headers = { "Content-Type": "application/json" }
  const blob = new Blob([json], { type: "application/json" })
  if (blob.size < GZIP_MIN_SIZE || typeof CompressionStream !== "function") return { body: json, headers }
  const body = await new Response(blob.stream().pipeThrough(new CompressionStream("gzip"))).blob()
  return { body, headers: { ...headers, "Content-Encoding": "gzip" } }
}

// Posts a body with XHR, so `onProgress(loaded, total)` gets the progress of the upload (`fetch`
// cannot give it). Resolves with a Response, as `fetch()`, or rejects with a TypeError for a
// network error.
function post(url, headers, body, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    const fail = () => reject(new TypeError("Network request failed"))
    xhr.open("POST", url)
    for (const [name, value] of Object.entries(headers)) xhr.setRequestHeader(name, value)
    xhr.responseType = "blob"
    // Before send(): a listener that comes later gets no progress.
    xhr.upload.addEventListener("progress", (e) => onProgress(e.loaded, e.lengthComputable ? e.total : 0))
    xhr.addEventListener("load", () => {
      if (xhr.status < 200) return fail()
      const responseHeaders = new Headers()
      for (const line of xhr.getAllResponseHeaders().split(/\r?\n/)) {
        const colon = line.indexOf(":")
        if (colon > 0) responseHeaders.append(line.slice(0, colon).trim(), line.slice(colon + 1).trim())
      }
      const body = [204, 205, 304].includes(xhr.status) ? null : xhr.response
      resolve(new Response(body, { status: xhr.status, statusText: xhr.statusText, headers: responseHeaders }))
    })
    xhr.addEventListener("error", fail)
    xhr.addEventListener("abort", fail)
    xhr.addEventListener("timeout", fail)
    xhr.send(body)
  })
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
    debounce: { type: Number, default: 150 },
    query: Object,
  }

  connect() {
    this.queue = Promise.resolve()
    // element => (handler attribute => {send, timer}): the events that wait for a debounce or a throttle
    this.pending = new Map()
    // element => (handler attribute => the time of the last event that the throttle let go)
    this.sentAt = new WeakMap()
    // element => (handler attribute => {handler, payload}): the input and change events in the
    // queue that did not start yet
    this.waiting = new Map()
    this.flushOnBlur = (event) => this.flush(event.target)
    this.element.addEventListener("focusout", this.flushOnBlur)
    // element => the number of its events that show the loading state
    this.loading = new Map()
    // The events that show the loading state now: {handler, el, upload}, where upload is
    // {progress} for an event with files, or null
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
      if (t.hasAttribute("data-phem-progress")) this.showProgress(t)
      if (t === this.element) this.showOffline()
      for (const controls of this.locked.values()) {
        const prop = controls.get(t)
        if (prop) t[prop] = true
      }
    }
    this.element.addEventListener("turbo:morph-element", this.keepLoading)
    // The values of all the bound fields when the request of the event that the morph renders
    // now left.
    this.sentBound = null
    // The morph sets the value of each field of the server render. Keep a bound field that the
    // user changed after the request left: its value is not the value that it had then. The
    // morph sets the attributes first, so the field can look changed here: compare with the
    // values when the request left, not with the default value.
    this.keepEdited = (event) => {
      if (!this.sentBound || !["value", "checked", "selected"].includes(event.detail.attributeName)) return
      const field = event.target instanceof HTMLOptionElement ? event.target.closest("select") : event.target
      const key = field?.getAttribute?.("data-phem-bind")
      if (!key || !this.element.contains(field) || !(key in this.sentBound)) return
      if (!sameValue(boundValue(this.boundFields(key)), this.sentBound[key])) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-attribute", this.keepEdited)
    // The server did not render a component again (a <phem-c data-phem-keep> placeholder): keep
    // its element and its content as they are.
    this.keepKept = (event) => {
      if (event.detail.newElement?.hasAttribute?.("data-phem-keep")) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-element", this.keepKept)
    // The first render can drop a value of the URL that does not fit its property: never a new
    // history entry here.
    this.updateUrl(this.queryValue, false)
    this.showOffline = () => this.element.classList.toggle("phem-offline", !navigator.onLine)
    this.showOffline()
    window.addEventListener("online", this.showOffline)
    window.addEventListener("offline", this.showOffline)
  }

  disconnect() {
    this.element.removeEventListener("turbo:morph-element", this.keepLoading)
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepEdited)
    this.element.removeEventListener("turbo:before-morph-element", this.keepKept)
    this.element.removeEventListener("focusout", this.flushOnBlur)
    for (const pending of this.pending.values()) pending.forEach(({ timer }) => clearTimeout(timer))
    this.pending.clear()
    window.removeEventListener("online", this.showOffline)
    window.removeEventListener("offline", this.showOffline)
    this.hideError()
  }

  // data-action="click->phem#dispatch input->phem#dispatch ..." on the root element.
  dispatch(event) {
    const el = this.handlerElement(event)
    if (!el) {
      const live = this.liveElement(event)
      // Its "handler" is the key of the field: the server gets null (see fetchWithRetry()).
      if (live) this.schedule(live, "data-phem-live", live.getAttribute("data-phem-bind"), event.type, { type: event.type, live: true })
      return
    }
    if (event.type === "submit") {
      event.preventDefault()
      // The first submit of this form still runs.
      if (this.locked.has(el)) return
    }
    const attr = el._phemAttr
    const handler = el.getAttribute(attr)
    const { fields, files } = el instanceof HTMLFormElement ? formFields(el, event.submitter) : { fields: null, files: [] }
    const payload = {
      type: event.type,
      value: "value" in el ? el.value : null,
      checked: "checked" in el ? el.checked : null,
      key: event.key ?? null,
      form: fields,
    }
    // Not in the JSON: fetchWithRetry() sends them as parts of a multipart body.
    if (files.length) payload.files = files
    this.schedule(el, attr, handler, event.type, payload)
  }

  // Sends an event after its wait (debounce or throttle), or at once.
  schedule(el, attr, handler, type, payload) {
    const send = () => this.enqueue(el, attr, handler, payload)
    const wait = this.timing(el, type)
    if (wait) {
      this.defer(el, attr, wait, send)
    } else {
      // The waiting events first, so the server gets all the input before this event.
      this.flush()
      send()
    }
  }

  // Puts an event in the queue. An input or change event that waits in the queue for the same
  // handler and element takes the new value, and the new event does not go in the queue.
  enqueue(el, attr, handler, payload) {
    const coalesce = payload.type === "input" || payload.type === "change"
    const waiting = coalesce ? (this.waiting.get(el) ?? new Map()) : null
    const old = waiting?.get(attr)
    if (old && old.handler === handler) {
      old.payload = payload
      return
    }
    const job = { handler, payload }
    if (coalesce) {
      waiting.set(attr, job)
      this.waiting.set(el, waiting)
    }
    const upload = payload.files ? { progress: 0 } : null
    const done = this.startLoading(el, handler, upload)
    // After formFields(): a disabled control is not in the form data.
    const unlock = payload.type === "submit" ? this.lockForm(el) : () => {}
    const onProgress = upload && ((loaded, total) => this.uploadProgress(el, handler, payload.files, upload, loaded, total))
    this.queue = this.queue
      .then(() => {
        if (waiting?.get(attr) === job) {
          waiting.delete(attr)
          if (!waiting.size) this.waiting.delete(el)
        }
        return this.send(job.handler, job.payload, unlock, onProgress)
      })
      .catch((e) => console.error("[phem]", e))
      .finally(() => {
        unlock()
        done()
      })
  }

  // The wait of an event: {debounce: ms or "blur"}, {throttle: ms}, or null to send it at once.
  timing(el, type) {
    if (type === "submit" || type === "focusout") return null
    const { phemDebounce: debounce, phemThrottle: throttle } = el.dataset
    if (throttle !== undefined) {
      const ms = Number(throttle)
      return ms > 0 ? { throttle: ms } : null
    }
    if (debounce === "blur") return { debounce }
    if (debounce !== undefined) {
      const ms = Number(debounce)
      return ms > 0 ? { debounce: ms } : null
    }
    // An `input` event comes from a text input (see handlerElement()).
    return type === "input" && this.debounceValue > 0 ? { debounce: this.debounceValue } : null
  }

  // Makes an event wait for its debounce or its throttle. A new event of the same handler and
  // element replaces the event that waits.
  defer(el, attr, wait, send) {
    const pending = this.pending.get(el) ?? new Map()
    this.pending.set(el, pending)
    const entry = pending.get(attr)
    if (wait.throttle) {
      if (entry) {
        // Keep the time of the throttle: send the newest event then.
        entry.send = send
        return
      }
      const left = (this.sentAt.get(el)?.get(attr) ?? -Infinity) + wait.throttle - performance.now()
      pending.set(attr, { send, timer: left > 0 ? setTimeout(() => this.release(el, attr), left) : null })
      if (left <= 0) this.release(el, attr)
      return
    }
    clearTimeout(entry?.timer)
    const timer = wait.debounce === "blur" ? null : setTimeout(() => this.release(el, attr), wait.debounce)
    pending.set(attr, { send, timer })
  }

  // Sends the waiting event of a handler of an element.
  release(el, attr) {
    const pending = this.pending.get(el)
    const entry = pending?.get(attr)
    if (!entry) return
    clearTimeout(entry.timer)
    pending.delete(attr)
    if (!pending.size) this.pending.delete(el)
    const sent = this.sentAt.get(el) ?? new Map()
    sent.set(attr, performance.now())
    this.sentAt.set(el, sent)
    entry.send()
  }

  // Sends the waiting events of an element, or of all the elements, in the order they came.
  flush(target = null) {
    for (const [el, pending] of [...this.pending]) {
      if (target === null || el === target) for (const attr of [...pending.keys()]) this.release(el, attr)
    }
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
  startLoading(el, handler, upload = null) {
    const targets = new Set([el, el.closest("phem-c") ?? this.element])
    const event = { handler, el, upload }
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

  // Shows each `phem:loading` and `phem:progress` element with an event that runs, and hides the
  // others.
  showLoadingElements() {
    for (const t of this.element.querySelectorAll("[data-phem-loading]")) t.hidden = !this.isLoading(t)
    for (const t of this.element.querySelectorAll("[data-phem-progress]")) this.showProgress(t)
  }

  // The event that runs for the handler of a `phem:loading` or `phem:progress` element, or null.
  // The scope of the element is the fiber of its component and the fingerprint of the handler. A
  // handler id is `<fiber>.<fingerprint>`, with `~<n>` for the same handler two times. The event
  // matches when it has the same fingerprint, and one component is in the other: a child can get
  // the handler as a prop, and send the event or show the element. With `upload`, only an event
  // with files matches.
  runningEvent(el, scope, upload = false) {
    const fiber = scope.slice(0, scope.indexOf("."))
    const fingerprint = scope.slice(fiber.length + 1)
    for (const event of this.running) {
      const { handler, el: source } = event
      if ((upload && !event.upload) || handler.slice(handler.indexOf(".") + 1).split("~")[0] !== fingerprint) continue
      if (handler.startsWith(`${fiber}.`) || this.componentRoot(scope).contains(source) || this.componentRoot(handler).contains(el)) return event
    }
    return null
  }

  isLoading(el) {
    return this.runningEvent(el, el.dataset.phemLoading) !== null
  }

  // Shows a `phem:progress` element with the progress of its upload, or hides it.
  showProgress(el) {
    const event = this.runningEvent(el, el.dataset.phemProgress, true)
    el.hidden = !event
    if (!event) return
    const { progress } = event.upload
    el.style.setProperty("--phem-progress", String(progress))
    if (el instanceof HTMLProgressElement) el.value = progress * el.max
  }

  // A step of an upload: show it on the `phem:progress` elements, and dispatch
  // `phem:upload-progress` on the element that sent the event.
  uploadProgress(el, handler, files, upload, loaded, total) {
    upload.progress = total > 0 ? Math.min(loaded / total, 1) : 0
    for (const t of this.element.querySelectorAll("[data-phem-progress]")) this.showProgress(t)
    // The body has the JSON of the event and the headers of the parts too: share the progress
    // between the files by their size, in their order.
    let sent = upload.progress * files.reduce((sum, [, file]) => sum + file.size, 0)
    const detail = {
      handler,
      loaded,
      total,
      progress: upload.progress,
      files: files.map(([field, file]) => {
        const part = Math.min(sent, file.size)
        sent -= part
        return { field, file, loaded: Math.round(part), progress: file.size > 0 ? part / file.size : Math.floor(upload.progress) }
      }),
    }
    const target = el.isConnected ? el : this.componentRoot(handler)
    target.dispatchEvent(new CustomEvent("phem:upload-progress", { detail, bubbles: true }))
  }

  markLoading(el, delta) {
    const count = (this.loading.get(el) ?? 0) + delta
    if (count > 0) this.loading.set(el, count)
    else this.loading.delete(el)
    showLoading(el, count > 0)
  }

  // A bound field with `phem:live`, when no handler gets the event: a text field sends on
  // `input`, the other fields on `change`.
  liveElement(event) {
    const el = event.target
    if (!(el instanceof Element) || !el.hasAttribute("data-phem-live") || !el.hasAttribute("data-phem-bind") || !this.element.contains(el)) return null
    if (event.type !== (isTextLike(el) ? "input" : "change")) return null
    return el
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

  // `beforeRender` runs when the last response arrives, before the page changes. `onProgress`
  // gets the progress of an upload.
  async send(handler, event, beforeRender, onProgress = null) {
    // The bound fields when the event starts: the newest values. The request sends the fields
    // that changed.
    const fields = this.boundValues()
    const bound = Object.fromEntries(Object.entries(fields).filter(([, { dirty }]) => dirty).map(([key, { value }]) => [key, value]))
    if (event.live && !Object.keys(bound).length) {
      // The field has its server value again: nothing to send.
      beforeRender()
      return
    }
    let response
    try {
      response = await this.fetchEvent(handler, event, bound, onProgress)
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
    // A PHP error that PHP prints before the handler (an HTML page with a 200) is not an answer.
    const text = await response.text()
    let data
    try {
      data = JSON.parse(text)
    } catch {
      console.error(`[phem] event failed (${response.status}): the response is not JSON`, text)
      this.showError(handler, response.status)
      return
    }
    this.hideError()
    // The dev-mode check of the server: a component with a render() that is not pure.
    for (const warning of data.warnings ?? []) console.warn("[phem]", warning)
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
    if (data.query) this.updateUrl(data.query, true)
    this.sentBound = Object.fromEntries(Object.entries(fields).map(([key, { value }]) => [key, value]))
    const rendered = data.streams ? this.renderStreams(data.streams, handler) : afterStreams()
    rendered.then(() => {
      this.sentBound = null
      this.resetRejected(bound)
    })
    if (data.events?.length) {
      // After the morph, so a listener sees the new page.
      rendered.then(() => {
        const target = this.componentRoot(handler)
        for (const { name, detail } of data.events) target.dispatchEvent(new CustomEvent(`phem:${name}`, { detail, bubbles: true }))
      })
    }
  }

  // Writes the query of the #[Url] properties into the URL. A key keeps its place, a new key goes
  // at the end, and the other keys do not change. With `canPush`, a changed key in `push` adds a
  // history entry.
  updateUrl({ values = {}, push = [] } = {}, canPush) {
    const keys = Object.keys(values)
    if (!keys.length) return
    const url = new URL(window.location.href)
    const out = []
    const before = {}
    for (const pair of url.search.slice(1).split("&")) {
      if (!pair) continue
      const name = queryName(pair)
      if (name === null || !keys.includes(name)) {
        out.push(pair)
        continue
      }
      if (!(name in before)) out.push(...queryPairs(name, values[name]))
      ;(before[name] ??= []).push(pair)
    }
    for (const name of keys) if (!(name in before)) out.push(...queryPairs(name, values[name]))
    const search = out.length ? `?${out.join("&")}` : ""
    if (search === url.search) return
    const changed = (name) => (before[name] ?? []).join("&") !== queryPairs(name, values[name]).join("&")
    const pushes = canPush && push.some((name) => keys.includes(name) && changed(name))
    url.search = search
    const history = Turbo.navigator?.history
    if (history) {
      if (pushes) history.push(url)
      else history.replace(url, history.restorationIdentifier)
    } else {
      window.history[pushes ? "pushState" : "replaceState"](window.history.state, "", url)
    }
    // Turbo keeps the snapshot of the page for the URL of its last render: the snapshot of this
    // state is for the new URL, not for the URL that loaded the page.
    const view = Turbo.session?.view
    if (view) view.lastRenderedLocation = url
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

  // Posts the event with only the signature of the state. When the server does not have the
  // state (a 409 with `{"state": "required"}`), posts it again with the state. An event with files
  // always has the state.
  async fetchEvent(handler, event, bound = {}, onProgress = null) {
    const withState = Boolean(event.files?.length)
    const response = await this.fetchWithRetry(handler, event, bound, onProgress, withState)
    if (withState || response.status !== 409) return response
    const data = await response.clone().json().catch(() => null)
    if (data?.state !== "required") return response
    return this.fetchWithRetry(handler, event, bound, onProgress, true)
  }

  // Posts the event. Sends it again after a network error, a 502 or a 503, with a longer wait
  // each time. The last try returns its response, or throws its network error. An event with
  // files goes with XHR when `onProgress` wants the progress of the upload.
  async fetchWithRetry(handler, event, bound, onProgress, withState) {
    const { files = [], ...data } = event
    // A `phem:live` event has no handler: the server only sets the bound values.
    const state = withState ? { state: this.stateValue } : {}
    const json = JSON.stringify({ ...state, signature: this.signatureValue, handler: data.live ? null : handler, event: data, bound })
    // An event with files is not compressed: the files are most of the body.
    const plain = files.length ? null : await jsonBody(json)
    for (let attempt = 0; ; attempt++) {
      let response = null
      let error = null
      const headers = { Accept: "application/json", "X-Phem-Event": "1", ...plain?.headers }
      let body = plain?.body
      if (files.length) {
        // The browser sets the Content-Type, with the boundary of the parts.
        body = new FormData()
        body.append("_phem", json)
        for (const [name, file] of files) body.append(name, file)
      }
      try {
        // The URL of the page, from the browser: always the same origin.
        response = files.length && onProgress ? await post(window.location.href, headers, body, onProgress) : await fetch(window.location.href, { method: "POST", headers, body })
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

  // The bound fields of a key ("<fiber>.<property>"): a group of checkboxes or radio buttons has more than one.
  boundFields(key) {
    return [...this.element.querySelectorAll("[data-phem-bind]")].filter((f) => f.getAttribute("data-phem-bind") === key)
  }

  // The values of the bound fields: key => {value, dirty}, where dirty tells that the user changed it.
  boundValues() {
    const keys = new Map()
    for (const field of this.element.querySelectorAll("[data-phem-bind]")) {
      const key = field.getAttribute("data-phem-bind")
      keys.set(key, [...(keys.get(key) ?? []), field])
    }
    const values = {}
    for (const [key, fields] of keys) values[key] = { value: boundValue(fields), dirty: fields.some(isDirty) }
    return values
  }

  // After the morph: a field that still has the value that the request sent, and not the value of
  // the server render, has a value that the server did not take. It gets the server value again.
  resetRejected(bound) {
    for (const [key, value] of Object.entries(bound)) {
      const fields = this.boundFields(key)
      if (fields.some(isDirty) && sameValue(boundValue(fields), value)) fields.forEach(resetField)
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
