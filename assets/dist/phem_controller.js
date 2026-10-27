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

// The start of the id that an element with a `key` or a fiber id gets while a morph runs (see
// keyIds() and componentIds()).
const KEY_ID = "phem-key:"

// The attribute of the fiber id of a component: on its root element, or on its `<phem-c>` when
// the component renders a text, more than one element or another component.
const FIBER = "data-phem-c"

// The wait before a refresh: the refreshes that come in this time go in one event.
const REFRESH_WAIT = 50

// The maximum number of stale components of an event (EventPayload::MAX_STALE on the server).
// With more, the controller marks the content of the root element: one id renders all of them.
const MAX_STALE = 100

// The wait before a new connection to the hub after it refused one (an expired token).
const STREAM_RETRY = 5000

// The number of tokens of the last events that a page keeps (see `sent`).
const MAX_SENT = 20

// The DOM event of a refresh: an event with no handler.
const REFRESH_EVENT = { type: "refresh", value: null, checked: null, key: null, form: null, refresh: true }

// Gives each element with a `key` (`data-phem-key`) and no id in `root` an id from its key: the id
// of its closest ancestor with an id (or of `target`), its tag and its key. The parents come
// first, so the id of a nested item has the ids of its parents.
function keyIds(root, target) {
  for (const el of root.querySelectorAll("[data-phem-key]")) {
    if (el.hasAttribute("id")) continue

    const scope = el.parentElement?.closest("[id]") ?? target
    el.id = `${KEY_ID}${scope.id}/${el.localName}:${el.getAttribute("data-phem-key")}`
  }
}

function dropKeyIds(root) {
  for (const el of root.querySelectorAll(`[id^="${KEY_ID}"]`)) el.removeAttribute("id")
}

// Gives each component with no id in `root` (and `root` itself) an id from its fiber id, so the
// morph matches it by id.
function componentIds(root) {
  const selector = `[${FIBER}]:not([id])`
  const components = [...root.querySelectorAll(selector)]
  if (root.matches?.(selector)) components.push(root)

  for (const el of components) el.id = `${KEY_ID}${el.getAttribute(FIBER)}`
}

// Replaces the placeholder of each component that the server did not render again (a
// `<template data-phem-keep>`, valid in each parent, also in a `<table>` and a `<select>`) with
// an empty copy of its element in the page, with `data-phem-keep`: the morph matches the copy with
// the element by its id, and keeps the element as it is.
function keptElements(content, page) {
  for (const placeholder of content.querySelectorAll("template[data-phem-keep]")) {
    const el = page.querySelector(`[${FIBER}="${placeholder.getAttribute("data-phem-keep")}"]`)
    if (!el) continue

    const copy = el.cloneNode(false)
    copy.setAttribute("data-phem-keep", "")
    placeholder.replaceWith(copy)
  }
}

// The element of the component around an element: its root element with the fiber id, or its
// `<phem-c>`.
function componentOf(el) {
  return el?.closest?.(`[${FIBER}]`) ?? null
}

// Before an `append` or a `prepend` of a push: removes each element of the target with the id of
// a new element. Turbo removes only the children of the target, and the items of a list are often
// in a component.
function removeDuplicates(target, stream) {
  for (const el of stream.querySelector("template")?.content.children ?? []) {
    const old = el.id ? document.getElementById(el.id) : null
    if (old && target.contains(old)) old.remove()
  }
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

// The state is a JSON object with one entry on each line, in the order of the keys (see
// Phem\State\StateJson). An event response has only the entries that changed: key => the JSON text
// of the value, or null for an entry that the state does not have any more. The controller sets
// them and joins the lines again, with no JSON encoder, so the text is the text that the server
// signed. A state with another form counts as empty: then the response has all the entries.
function applyStateChanges(state, changes) {
  const entries = new Map()

  if (state.startsWith("{\n") && state.endsWith("\n}")) {
    for (const line of state.slice(2, -2).split(",\n")) {
      const end = line.indexOf('"', 1)
      entries.set(line.slice(1, end), line.slice(end + 2))
    }
  }

  for (const [key, value] of Object.entries(changes)) {
    if (value === null) entries.delete(key)
    else entries.set(key, value)
  }

  const keys = [...entries.keys()].sort()
  return keys.length ? `{\n${keys.map((key) => `"${key}":${entries.get(key)}`).join(",\n")}\n}` : "{}"
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

// With the Symfony profiler (debug mode), logs each event with the link to its Phem panel. The
// server sends the `X-Debug-Token-Link` header only when the profiler is on.
function logProfile(handler, event, response) {
  const link = response.headers.get("X-Debug-Token-Link")
  if (!link) return

  const url = new URL(link, window.location.href)
  url.searchParams.set("panel", "phem")
  console.debug(`[phem] ${event.live ? "phem:live" : event.refresh ? "phem:refresh" : handler} → ${response.status}`, url.href)
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
    guardMessage: { type: String, default: "You have unsaved changes. Leave the page?" },
    query: Object,
    stream: String,
    // An event sends a state shorter than this with its signature: the server does not cache it.
    // With no value, the server has no cache, and each event sends the state.
    stateInline: { type: Number, default: Infinity },
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
    // The id of the last DOM event that makes an event (see schedule())
    this.lastId = 0

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
    // A pushed morph runs with no event: it keeps a bound field that the user changed.
    this.keepEdited = (event) => {
      if (!["value", "checked", "selected"].includes(event.detail.attributeName)) return

      const field = event.target instanceof HTMLOptionElement ? event.target.closest("select") : event.target
      const key = field?.getAttribute?.("data-phem-bind")
      if (!key || !this.element.contains(field)) return

      if (!this.sentBound) {
        if (this.pushing && this.boundFields(key).some(isDirty)) event.preventDefault()
        return
      }

      if (!(key in this.sentBound)) return
      if (!sameValue(boundValue(this.boundFields(key)), this.sentBound[key])) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-attribute", this.keepEdited)

    // The server did not render a component again (its placeholder, see keptElements()): keep its
    // element and its content as they are. The same for a `data-phem-ignore` element that the
    // server renders again, because JS owns it. The morph removes it when the server render does
    // not have it.
    this.keepKept = (event) => {
      const next = event.detail.newElement
      if (next?.hasAttribute?.("data-phem-keep")) event.preventDefault()
      else if (next?.hasAttribute?.("data-phem-ignore") && event.target.hasAttribute("data-phem-ignore")) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-element", this.keepKept)

    // The attributes in the `data-phem-client` list of an element belong to the client (a Stimulus
    // controller): the morph does not change them.
    this.keepClient = (event) => {
      const names = event.target.getAttribute?.("data-phem-client")
      if (names && names.split(/\s+/).includes(event.detail.attributeName)) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-attribute", this.keepClient)

    // The morph matches the elements by their id, and else by their position. While the morph of a
    // stream runs, each component and each element with a `key` and no id has an id from its fiber
    // id or its key, in the page and in the server render. So a component or a list item keeps its
    // element (its client attributes, its focus, its JS library) when an item before it goes away.
    // Two lists with the same keys and no id between them and their component get the same ids: the
    // morph ignores these ids, and matches their items by position. The placeholders of the
    // components that the server did not render again become copies of their elements. Before the
    // `take` of renderStreams(), which runs this render.
    this.keyMorph = (event) => {
      const stream = event.detail.newStream
      if (stream.getAttribute("method") !== "morph") return

      const id = stream.getAttribute("target")
      const selector = stream.getAttribute("targets")
      const target = id ? document.getElementById(id) : selector ? document.querySelector(selector) : null
      if (!target || !this.element.contains(target)) return

      const render = event.detail.render
      event.detail.render = async (element) => {
        const content = stream.templateElement.content
        componentIds(target)
        keptElements(content, this.element)
        componentIds(content)
        keyIds(target, target)
        keyIds(content, target)
        try {
          await render(element)
        } finally {
          dropKeyIds(this.element)
        }
      }
    }
    document.addEventListener("turbo:before-stream-render", this.keyMorph)

    // The first render can drop a value of the URL that does not fit its property: never a new
    // history entry here.
    this.updateUrl(this.queryValue, false)

    this.showOffline = () => this.element.classList.toggle("phem-offline", !navigator.onLine)
    this.showOffline()
    window.addEventListener("online", this.showOffline)
    window.addEventListener("offline", this.showOffline)

    // The `phem-dirty` class of the bound fields that the user changed.
    this.showDirty = () => this.markDirty()
    this.element.addEventListener("input", this.showDirty)
    this.element.addEventListener("change", this.showDirty)
    this.showDirty()

    // True when the controller itself leaves the page (a redirect or a reload): no question.
    this.leaving = false
    this.guardUnload = (event) => {
      if (this.leaving || !this.dirtyGuard()) return

      event.preventDefault()
      event.returnValue = ""
    }
    this.guardVisit = (event) => {
      if (this.leaving) return
      const guard = this.dirtyGuard()
      if (!guard) return

      if (window.confirm(guard.getAttribute("data-phem-guard") || this.guardMessageValue)) this.leaving = true
      else event.preventDefault()
    }
    window.addEventListener("beforeunload", this.guardUnload)
    document.addEventListener("turbo:before-visit", this.guardVisit)

    // The handlers of the `<Suspense>` boundaries that loaded, or that load now: one load each.
    this.loads = new Set()
    // The boundaries that start to load in this task: {el, handler} (see sendLoads())
    this.loadBatch = []
    // A `lazy` boundary loads when it comes into view, or a little before.
    this.boundaryObserver =
      typeof IntersectionObserver === "function"
        ? new IntersectionObserver(
            (entries) => entries.forEach((entry) => entry.isIntersecting && entry.target.isConnected && this.loadBoundary(entry.target)),
            { rootMargin: "200px" },
          )
        : null
    this.loadBoundaries()

    // The ids of the stale components (see the header): the next event sends them.
    this.stale = new Set()
    // The tokens of the last events of this page (`sender` in the answer): a refresh after one of
    // these events does not render again the component that the event rendered.
    this.sent = []
    // The number of events that run now, and the pushes that wait for them.
    this.busy = 0
    this.held = []
    // The number of pushes that Turbo renders now (see keepEdited).
    this.pushing = 0
    this.refreshTimer = null
    this.streamTimer = null
    this.openStream()
  }

  disconnect() {
    this.source?.close()
    this.source = null
    clearTimeout(this.refreshTimer)
    clearTimeout(this.streamTimer)
    this.element.removeEventListener("turbo:morph-element", this.keepLoading)
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepEdited)
    this.element.removeEventListener("turbo:before-morph-element", this.keepKept)
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepClient)
    document.removeEventListener("turbo:before-stream-render", this.keyMorph)
    this.element.removeEventListener("focusout", this.flushOnBlur)
    for (const pending of this.pending.values()) pending.forEach(({ timer }) => clearTimeout(timer))
    this.pending.clear()
    window.removeEventListener("online", this.showOffline)
    window.removeEventListener("offline", this.showOffline)
    this.element.removeEventListener("input", this.showDirty)
    this.element.removeEventListener("change", this.showDirty)
    window.removeEventListener("beforeunload", this.guardUnload)
    document.removeEventListener("turbo:before-visit", this.guardVisit)
    this.boundaryObserver?.disconnect()
    this.hideError()
  }

  // Opens the EventSource of a page with #[Subscribe] (see the header).
  openStream() {
    if (!this.streamValue || typeof EventSource !== "function") return

    const source = new EventSource(this.streamValue, { withCredentials: true })
    let lost = false

    source.addEventListener("message", (event) => this.receive(event.data))
    source.addEventListener("error", () => {
      lost = true
      if (source.readyState !== EventSource.CLOSED || this.source !== source) return

      // The hub refused the connection (an expired token): EventSource does not connect again.
      // A refresh renews the token (an event sets the cookie), then the controller connects again.
      this.source = null
      clearTimeout(this.streamTimer)
      this.streamTimer = setTimeout(() => {
        this.markContentStale()
        this.queue = this.queue
          .then(() => this.send(this.element, null, REFRESH_EVENT, () => {}))
          .catch((e) => console.error("[phem]", e))
          .then(() => this.element.isConnected && !this.source && this.openStream())
      }, STREAM_RETRY)
    })

    source.addEventListener("open", () => {
      if (!lost) return
      lost = false
      // The `local` transport of the hub has no history: the updates of the lost time are lost.
      if (this.markContentStale()) this.scheduleRefresh()
    })

    this.source = source
  }

  // A push: it waits while an event runs, so it comes after the morph of the event.
  receive(data) {
    if (this.busy) this.held.push(data)
    else this.applyPush(data)
  }

  // Renders the Turbo Streams of a push. In the root element, each stream marks the component
  // whose own HTML it changes: the component around the target, or around its parent for an
  // action that changes the parent (remove, replace, before, after).
  applyPush(data) {
    const message = document.createElement("template")
    message.innerHTML = data
    let refresh = false

    for (const stream of message.content.querySelectorAll("turbo-stream")) {
      const action = stream.getAttribute("action")
      const target = document.getElementById(stream.getAttribute("target") ?? "")
      const inRoot = target !== null && this.element.contains(target)

      if (action === "phem-refresh") {
        stream.remove()
        refresh = this.markRefresh(stream, inRoot ? target : null) || refresh
        continue
      }

      if (!inRoot) continue

      this.markStale(["remove", "replace", "before", "after"].includes(action) ? target.parentElement : target)
      if (action === "append" || action === "prepend") removeDuplicates(target, stream)
    }

    if (message.content.querySelector("turbo-stream")) {
      console.debug("[phem] push", data)
      this.pushing++
      Turbo.renderStreamMessage(message.innerHTML)
      afterStreams().then(() => {
        this.pushing--
        this.markDirty()
      })
    }

    if (refresh) this.scheduleRefresh()
  }

  // Marks the components of a refresh as stale: the component around the target, the components of
  // the `targets` selector (the instances of a component with #[Broadcast]), or the content of the
  // root element for a refresh with neither. A refresh after a handler of this page skips the
  // component of the handler (`data-phem-skip`): the event rendered it. Returns whether one is stale.
  markRefresh(stream, target) {
    const selector = stream.getAttribute("targets")
    if (selector === null) return stream.hasAttribute("target") ? this.markStale(target) : this.markContentStale()

    const [token, skip] = (stream.getAttribute("data-phem-skip") ?? "").split(" ")
    const own = token !== "" && this.sent.includes(token)
    let refresh = false
    for (const component of this.element.querySelectorAll(selector)) {
      if (own && component.getAttribute(FIBER) === skip) continue
      refresh = this.markStale(component) || refresh
    }

    return refresh
  }

  // Marks the component around an element as stale. Returns false when the element is not in a component.
  markStale(el) {
    const component = componentOf(el)
    if (!component || !this.element.contains(component)) return false

    this.stale.add(component.getAttribute(FIBER))

    // Too many for one event: the content of the root element renders all of them.
    if (this.stale.size > MAX_STALE) {
      this.stale.clear()
      this.markContentStale()
    }

    return true
  }

  // Marks the content of the root element (its first component) as stale. Returns false when it has none.
  markContentStale() {
    const content = this.element.querySelector(`[${FIBER}]`)
    if (content) this.stale.add(content.getAttribute(FIBER))
    return content !== null
  }

  // Sends one refresh after REFRESH_WAIT, for all the refreshes until then. It goes in the queue
  // of the events, so it waits for the event that runs.
  scheduleRefresh() {
    clearTimeout(this.refreshTimer)
    this.refreshTimer = setTimeout(() => {
      this.queue = this.queue
        .then(() => (this.stale.size ? this.send(this.element, null, REFRESH_EVENT, () => {}) : null))
        .catch((e) => console.error("[phem]", e))
    }, REFRESH_WAIT)
  }

  // Loads each `<Suspense>` boundary that shows its fallback (`data-phem-load`, the id of its load
  // handler): at once, or with `data-phem-lazy` when it comes into view. The load is a normal
  // event in the queue, so it sends the newest state. A boundary loads one time: after a failed
  // load, it keeps its fallback.
  loadBoundaries() {
    for (const el of this.element.querySelectorAll("[data-phem-load]")) {
      if (this.loads.has(el.getAttribute("data-phem-load"))) continue
      if (el.hasAttribute("data-phem-lazy") && this.boundaryObserver) this.boundaryObserver.observe(el)
      else this.loadBoundary(el)
    }
  }

  // The boundaries that start to load in the same task (when the page starts, after a morph, or
  // when they come into view together) go in one load event (see sendLoads()).
  loadBoundary(el) {
    this.boundaryObserver?.unobserve(el)
    const handler = el.getAttribute("data-phem-load")
    if (!handler || this.loads.has(handler)) return

    this.loads.add(handler)
    this.loadBatch.push({ el, handler })
    if (this.loadBatch.length === 1) queueMicrotask(() => this.sendLoads())
  }

  // Sends one load event for the boundaries of loadBoundary(): one request, and the server renders
  // them in one pass. The event goes from the first boundary, so its `phem:send` and `phem:done`
  // have the first handler id. Each boundary shows the loading state.
  sendLoads() {
    const batch = this.loadBatch.splice(0)
    const load = { type: "load", value: null, checked: null, key: null, form: null }
    if (batch.length < 2) {
      for (const { el, handler } of batch) this.enqueue(el, "data-phem-load", handler, load)
      return
    }

    const [first, ...rest] = batch
    const ends = rest.map(({ el, handler }) => this.startLoading(el, handler))
    this.enqueue(first.el, "data-phem-load", first.handler, { ...load, loads: batch.map(({ handler }) => handler) })
    this.queue = this.queue.then((ok) => {
      ends.forEach((end) => end())

      // A boundary with no error slot that throws fails the event of all of them (a server
      // error): load each one alone, so the others show their children.
      if (ok === false) for (const { el, handler } of batch) if (el.isConnected) this.enqueue(el, "data-phem-load", handler, load)
    })
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
    if (!this.allowed(el, handler, event)) return

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

  // Dispatches the cancelable `phem:before-queue` on the element of a handler, before its event goes.
  // A listener that cancels it (as `phem-confirm` does) stops the event: the DOM event does not do
  // its default action, and a field that changed gets the value of the server render again.
  allowed(el, handler, event) {
    const before = new CustomEvent("phem:before-queue", { detail: { handler, type: event.type }, bubbles: true, cancelable: true })
    if (el.dispatchEvent(before)) return true

    event.preventDefault()
    if (event.type === "input" || event.type === "change") {
      // All the radio buttons of the group: the radio button that was checked is checked again.
      const radios = el.type === "radio" && el.name ? [...(el.form ?? this.element).querySelectorAll('input[type="radio"]')].filter((r) => r.name === el.name) : [el]
      radios.forEach(resetField)
      this.markDirty()
    }

    return false
  }

  // Sends an event after its wait (debounce or throttle), or at once. The DOM event gets an id:
  // `phem:queue` gives it in the dispatch of the DOM event, so a Stimulus action of the element
  // knows its event. The event that carries it gives it in `ids` (more than one when the DOM event
  // replaced or joined an event that waits).
  schedule(el, attr, handler, type, payload) {
    const id = ++this.lastId
    const send = (ids) => this.enqueue(el, attr, handler, payload, ids)
    const wait = this.timing(el, type)
    this.notify(el, handler, "queue", { id })

    if (wait) {
      this.defer(el, attr, wait, id, send)
    } else {
      // The waiting events first, so the server gets all the input before this event.
      this.flush()
      send(new Set([id]))
    }
  }

  // Puts an event in the queue. An input or change event that waits in the queue for the same
  // handler and element takes the new value, and the new event does not go in the queue.
  enqueue(el, attr, handler, payload, ids = new Set()) {
    const coalesce = payload.type === "input" || payload.type === "change"
    const waiting = coalesce ? (this.waiting.get(el) ?? new Map()) : null
    const old = waiting?.get(attr)
    if (old && old.handler === handler) {
      old.payload = payload
      for (const id of ids) old.ids.add(id)
      return
    }

    const job = { handler, payload, ids }
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

        return this.send(el, job.handler, job.payload, unlock, onProgress, job.ids)
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
  // element replaces the event that waits, and the event carries the ids of both DOM events.
  defer(el, attr, wait, id, send) {
    const pending = this.pending.get(el) ?? new Map()
    this.pending.set(el, pending)
    const entry = pending.get(attr)

    if (wait.throttle) {
      if (entry) {
        // Keep the time of the throttle: send the newest event then.
        entry.send = send
        entry.ids.add(id)
        return
      }

      const left = (this.sentAt.get(el)?.get(attr) ?? -Infinity) + wait.throttle - performance.now()
      pending.set(attr, { send, ids: new Set([id]), timer: left > 0 ? setTimeout(() => this.release(el, attr), left) : null })
      if (left <= 0) this.release(el, attr)
      return
    }

    clearTimeout(entry?.timer)
    const timer = wait.debounce === "blur" ? null : setTimeout(() => this.release(el, attr), wait.debounce)
    pending.set(attr, { send, ids: (entry?.ids ?? new Set()).add(id), timer })
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
    entry.send(entry.ids)
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
    const targets = new Set([el, componentOf(el) ?? this.element])
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

  // Sends the event of the element `el`. `beforeRender` runs when the last response arrives,
  // before the page changes. `onProgress` gets the progress of an upload. The pushes that come
  // while it runs wait for its end (see receive()). `ids` are the ids of the DOM events that it
  // carries (see schedule()). Resolves with false after a server error.
  async send(el, handler, event, beforeRender, onProgress = null, ids = new Set()) {
    this.busy++
    try {
      return await this.sendNow(el, handler, event, beforeRender, onProgress, ids)
    } finally {
      this.busy--
      if (!this.busy) this.held.splice(0).forEach((data) => this.applyPush(data))
    }
  }

  async sendNow(el, handler, event, beforeRender, onProgress, ids) {
    const notify = (name, detail = {}) => this.notify(el, handler, name, { ids: [...ids], ...detail })

    // The bound fields when the event starts: the newest values. The request sends the fields
    // that changed.
    const fields = this.boundValues()
    const bound = Object.fromEntries(Object.entries(fields).filter(([, { dirty }]) => dirty).map(([key, { value }]) => [key, value]))

    if (event.live && !Object.keys(bound).length) {
      // The field has its server value again: nothing to send.
      beforeRender()
      notify("done", { ok: true, status: null })
      return
    }

    // The stale components when the event starts: the server renders them again.
    const stale = [...this.stale]
    if (stale.length) event = { ...event, stale }
    notify("send")

    // The end of an event that the server did not take: no morph.
    const failed = (status) => notify("done", { ok: false, status })
    let response
    try {
      response = await this.fetchEvent(handler, event, bound, onProgress)
    } catch (e) {
      beforeRender()
      console.error("[phem] event failed (network error)", e)
      this.showError(handler, null)
      failed(null)
      return
    }

    beforeRender()
    logProfile(handler, event, response)

    if (response.status === 409) {
      // The state is not valid any more (a new build, or a new secret): load the page again.
      this.leaving = true
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
      failed(429)
      return
    }

    if (!response.ok) {
      const text = await response.text()
      console.error(`[phem] event failed (${response.status})`, text)
      this.showError(handler, response.status)
      failed(response.status)
      return false
    }

    // A PHP error that PHP prints before the handler (an HTML page with a 200) is not an answer.
    const text = await response.text()
    let data
    try {
      data = JSON.parse(text)
    } catch {
      console.error(`[phem] event failed (${response.status}): the response is not JSON`, text)
      this.showError(handler, response.status)
      failed(response.status)
      return
    }

    this.hideError()

    // The dev-mode check of the server: a component with a render() that is not pure.
    for (const warning of data.warnings ?? []) console.warn("[phem]", warning)

    if (data.redirect) {
      // The handler returned a RedirectResponse. Turbo sets window.location for a URL that it
      // cannot visit, so a javascript: URL would run: visit only http(s) URLs.
      const url = new URL(data.redirect, window.location.href)
      if (url.protocol === "http:" || url.protocol === "https:") {
        // The server got the bound values with the event.
        this.leaving = true
        Turbo.visit(url.href)
      } else console.error("[phem] redirect to a URL that is not http(s) was blocked", data.redirect)
      return
    }

    for (const id of stale) this.stale.delete(id)
    this.stateValue = applyStateChanges(this.stateValue, data.changes ?? {})
    this.signatureValue = data.signature
    // Before the pushes that wait for this event (see receive()).
    if (data.sender) this.sent = [data.sender, ...this.sent].slice(0, MAX_SENT)
    if (data.query) this.updateUrl(data.query, true)
    this.sentBound = Object.fromEntries(Object.entries(fields).map(([key, { value }]) => [key, value]))

    // Before the morph, so a listener can stop its own changes of the page.
    notify("receive")
    const rendered = data.streams ? this.renderStreams(data.streams, handler) : afterStreams()
    // The render of the answer failed: the event ends as an event that the server did not take.
    const renderFailed = (e) => {
      failed(response.status)
      throw e
    }

    return rendered.then(() => {
      this.sentBound = null
      this.resetRejected(bound)
      this.markDirty()

      // After the morph, so a listener sees the new page.
      const target = this.componentRoot(handler)
      for (const { name, detail } of data.events ?? []) target.dispatchEvent(new CustomEvent(`phem:${name}`, { detail, bubbles: true }))
      notify("done", { ok: true, status: response.status })

      // The morph can show a new boundary (also in the children of a boundary that loaded).
      this.loadBoundaries()
    }, renderFailed)
  }

  // Dispatches `phem:<name>` (`queue`, `send`, `receive` or `done`) for an event, with the `handler` id and `detail`.
  // It starts on the element that sent the event, or on the root of its component when the morph
  // removed that element, and it bubbles.
  notify(el, handler, name, detail = {}) {
    // A refresh has no element that sent it.
    if (handler === null) return

    const target = el.isConnected && this.element.contains(el) ? el : this.componentRoot(handler)
    target.dispatchEvent(new CustomEvent(`phem:${name}`, { detail: { handler, ...detail }, bubbles: true }))
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

  // Posts the event with only the signature of a large state. When the server does not have the
  // state (a 409 with `{"state": "required"}`), posts it again with the state. A small state (see
  // `stateInline`) and an event with files always go with the event.
  async fetchEvent(handler, event, bound = {}, onProgress = null) {
    const withState = Boolean(event.files?.length) || this.stateValue.length < this.stateInlineValue
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
    const { files = [], stale = [], loads = null, ...data } = event

    // A `phem:live` event has no handler: the server only sets the bound values. A refresh has
    // no handler either: the server renders the stale components. A load of many boundaries has
    // the list of their handlers.
    const state = withState ? { state: this.stateValue } : {}
    const json = JSON.stringify({ ...state, signature: this.signatureValue, handler: data.live ? null : (loads ?? handler), event: data, bound, ...(stale.length ? { stale } : {}) })

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

  // Gives the `phem-dirty` class to each bound field that the user changed, and to each component,
  // form, guard and root element with one. The server render has no such class, so it runs after each morph.
  markDirty() {
    const fields = [...this.element.querySelectorAll("[data-phem-bind]")]
    const dirty = fields.filter(isDirty)

    for (const field of fields) field.classList.toggle("phem-dirty", dirty.includes(field))
    for (const el of [this.element, ...this.element.querySelectorAll(`[${FIBER}], form, [data-phem-guard]`)]) {
      el.classList.toggle("phem-dirty", dirty.some((field) => el.contains(field)))
    }
  }

  // The closest `data-phem-guard` element of a dirty bound field, or null.
  dirtyGuard() {
    for (const field of this.element.querySelectorAll("[data-phem-bind]")) {
      const guard = field.closest("[data-phem-guard]")
      if (guard && this.element.contains(guard) && isDirty(field)) return guard
    }

    return null
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

  // The element of the component of a handler: the element with the fiber id at the start of the
  // handler id. The root element when the component is the page, or when the morph removed it.
  componentRoot(handler) {
    if (handler === null) return this.element
    const el = this.element.querySelector(`[${FIBER}="${handler.slice(0, handler.indexOf("."))}"]`)
    return el ?? this.element
  }
}
