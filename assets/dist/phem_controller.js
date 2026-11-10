import * as Turbo from "@hotwired/turbo"
import { Controller } from "@hotwired/stimulus"
// Listens on each page: the keyboard of a field that gets the focus after a server round trip.
import "./phem_keyboard.js"
import { DialogQuestion } from "./phem_dialog_question.js"

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

// Whether a submit sends the files of a file input: it has a name and a form. A disabled input
// sends nothing, but a submit locks its form while its files upload.
function isFormFileInput(field) {
  return field instanceof HTMLInputElement && field.type === "file" && field.name !== "" && field.form !== null
}

// The file inputs of a form whose files a submit sends (see formFields()), with a copy of their
// files: the morph does not change them, so the controller empties them when the handler took the
// files (see clearFiles()). A copy, because a browser can change the same FileList when the user
// selects other files.
function sentFileInputs(form) {
  return [...form.elements].filter((f) => isFormFileInput(f) && !f.matches(":disabled") && f.files.length).map((f) => [f, [...f.files]])
}

// The mark of a field of a form that the user touched: the server renders it (see
// Phem\Form\TouchedFieldsExtension), and the controller sets it on each change.
const TOUCHED = "data-phem-touched"

// The names of the touched fields of a form: an event of the form sends them, so the server shows
// the errors of these fields only.
function touchedNames(form) {
  return [...new Set([...form.elements].filter((f) => f.name && f.hasAttribute(TOUCHED)).map((f) => f.name))]
}

// The DOM events that send the fields of the form of their element (see dispatch()).
const FORM_EVENTS = new Set(["submit", "change", "input", "click"])

// The DOM events whose default action a handler replaces: the browser does not open the dropped
// file, and does not show its own context menu.
const CANCELLED_EVENTS = new Set(["drop", "contextmenu"])

// The DOM events that fire many times each second: a new event takes the payload of the event of
// the same handler that waits in the queue (see enqueue()). A poll or a `visible` event that waits
// does not go in the queue two times.
const COALESCED_EVENTS = new Set(["input", "change", "dragover", "scroll", "visible", "poll"])

// The event that JS code dispatches to call the `onCall` handler of its element (or of an
// ancestor), with its detail: the handler gets it in `$e->detail`. The handler event is `call`.
const CALL_EVENT = "phem:call"

// The type of the handler event of a DOM event: `call` for `phem:call`.
function eventType(event) {
  return event.type === CALL_EVENT ? "call" : event.type
}

// The name of the multipart parts of the files of a drop or a paste (Phem\Event::FILES).
const TRANSFERRED_FILES = "_phem_files[]"

// The data of a drop or a paste, or null for another event.
function transferOf(event) {
  return event.type === "drop" ? (event.dataTransfer ?? null) : event.type === "paste" ? (event.clipboardData ?? null) : null
}

// The files of a drop or a paste, as multipart parts (see fetchWithRetry()). The server gives
// them to the handler as a list. A copy: the browser empties the FileList after the event.
function transferredFiles(event) {
  return [...(transferOf(event)?.files ?? [])].map((file) => [TRANSFERRED_FILES, file])
}

// Whether the browser keeps the default action of a cancelled event: a drop of text with no
// files in a text field or an editable element inserts the text.
function keepsDefault(event) {
  const t = event.target
  return event.type === "drop" && !event.dataTransfer?.files?.length && (isTextLike(t) || t.isContentEditable === true)
}

// The modifier keys of a key filter (`phem:key="ctrl+Enter"`).
const KEY_MODIFIERS = ["ctrl", "shift", "alt", "meta"]

// Whether a key event matches a key filter (`phem:key`): a list of keys separated by spaces, each
// with its modifiers (`Enter`, `ctrl+s`, `shift+Enter`, `Space`). The names have no case. The
// modifiers must match: `Enter` does not match Shift+Enter. Shift is free for a character that is
// not a letter, such as `?`, which needs Shift on some keyboards.
function matchesKey(filter, event) {
  const pressed = (event.key ?? "").toLowerCase()

  return filter.trim().split(/\s+/).some((combo) => {
    const parts = combo.toLowerCase().split("+")
    let key = parts.pop()
    // `+` or `ctrl++`: the key is `+`.
    if (key === "" && parts.at(-1) === "") {
      parts.pop()
      key = "+"
    }

    if (key === "space") key = " "
    if (key !== pressed) return false

    const freeShift = key.length === 1 && key.toLowerCase() === key.toUpperCase()

    return KEY_MODIFIERS.every((m) => (m === "shift" && freeShift && !parts.includes(m)) || event[`${m}Key`] === parts.includes(m))
  })
}

// The form of an element: the form itself, the form of a control (also with a `form` attribute),
// or the form around it.
function formOf(el) {
  return el instanceof HTMLFormElement ? el : (el.form ?? el.closest("form"))
}

// Whether the onChange of an element gets each key of a field, as the `input` event: the element is
// its form, the field is a text field, and the field has no `phem:live` (it sends its own events).
function formTakesKeys(el, field) {
  return el instanceof HTMLFormElement && isTextLike(field) && !field.hasAttribute("data-phem-live")
}

// The value of a field of a form, to compare it with a later value.
function fieldValue(field) {
  if (field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")) return field.checked
  if (field instanceof HTMLSelectElement) return [...field.selectedOptions].map((o) => o.value)
  return field.value
}

// The elements with an `onVisible` handler (`data-phem-on-visible`): each one sends its event
// when it comes into view, and again each time it comes into view after it left the view. An
// element that is still in view after the update of its event sends it again, but only when the
// update moved it (new items before it): an infinite scroll loads until the items fill the view,
// and stops when the server has no more items. `send(el, end)` sends the event, and returns false
// when it did not go. `end(ok)` runs after the update of the event.
class VisibleTriggers {
  #root
  #send
  #observer = null
  #stopped = false
  // The elements that the observer watches.
  #watched = new Set()
  // The elements in view.
  #inView = new WeakSet()
  // The elements whose view the observer gives again after the update of their event (see #end()).
  #recheck = new WeakSet()
  // The elements whose event runs.
  #running = new WeakSet()

  constructor(root, send) {
    this.#root = root
    this.#send = send
  }

  // Watches the new `onVisible` elements (in `elements`, the trigger elements of the page), and
  // stops for the elements that left the page or lost their handler. After the first render, a
  // morph or a push. The observer starts with the first element.
  scan(elements) {
    if (this.#stopped) return

    const found = elements.filter((el) => el.hasAttribute("data-phem-on-visible"))
    if (!this.#observer) {
      if (typeof IntersectionObserver !== "function" || !found.length) return

      this.#observer = new IntersectionObserver((entries) => entries.forEach((entry) => this.#change(entry)))
    }

    for (const el of this.#watched) {
      if (el.isConnected && this.#root.contains(el) && el.hasAttribute("data-phem-on-visible")) continue

      this.#observer.unobserve(el)
      this.#watched.delete(el)
      this.#inView.delete(el)
    }

    for (const el of found) {
      if (this.#watched.has(el)) continue

      this.#watched.add(el)
      this.#observer.observe(el)
    }
  }

  disconnect() {
    this.#stopped = true
    this.#observer?.disconnect()
    this.#watched.clear()
  }

  #change(entry) {
    const el = entry.target
    const recheck = this.#recheck.delete(el)
    if (!entry.isIntersecting) {
      this.#inView.delete(el)
      return
    }

    if (this.#inView.has(el) && !recheck) return

    this.#inView.add(el)
    if (!this.#stopped && el.isConnected && !this.#running.has(el)) this.#fire(el)
  }

  // Sends the event of the element. Its place before the event shows if the update moved it.
  #fire(el) {
    const before = el.getBoundingClientRect()
    this.#running.add(el)
    const sent = this.#send(el, (ok) => {
      this.#running.delete(el)
      const after = el.getBoundingClientRect()
      if (ok && (after.top !== before.top || after.left !== before.left)) this.#end(el)
    })
    if (!sent) this.#running.delete(el)
  }

  // The update of the event moved the element: the observer gives its view again, and the element
  // sends its event again when it is still in view.
  #end(el) {
    if (this.#stopped || !el.isConnected || !this.#watched.has(el)) return

    this.#recheck.add(el)
    this.#observer.unobserve(el)
    this.#observer.observe(el)
  }
}

// The elements with an `onPoll` handler and a `phem:poll` interval (`data-phem-poll`, in
// milliseconds): each one sends its event at its interval. The interval starts at the end of the
// last poll, so the polls of a slow server do not wait in the queue. The polls stop while the tab
// is hidden and while the browser is offline. When the tab is visible again, an element whose
// interval passed sends its event at once. `send(el, end)` sends the event, and returns false
// when it did not go. `end()` runs at the end of the event.
class Polls {
  #root
  #send
  #stopped = false
  // element => {interval, last, timer, running}
  #polls = new Map()
  #onVisibility = () => (document.hidden ? this.#pause() : this.#start())

  constructor(root, send) {
    this.#root = root
    this.#send = send
    document.addEventListener("visibilitychange", this.#onVisibility)
  }

  // Starts the polls of the new elements (in `elements`, the trigger elements of the page), and
  // stops the polls of the elements that left the page, or whose interval changed. After the
  // first render, a morph or a push.
  scan(elements) {
    if (this.#stopped) return

    for (const [el, poll] of this.#polls) {
      if (el.isConnected && this.#root.contains(el) && el.hasAttribute("data-phem-on-poll") && this.#interval(el) === poll.interval) continue

      clearTimeout(poll.timer)
      this.#polls.delete(el)
    }

    for (const el of elements) {
      const interval = el.hasAttribute("data-phem-on-poll") ? this.#interval(el) : 0
      if (this.#polls.has(el) || !interval) continue

      const poll = { interval, last: performance.now(), timer: null, running: false }
      this.#polls.set(el, poll)
      if (!document.hidden) this.#wait(el, poll, interval)
    }
  }

  disconnect() {
    this.#stopped = true
    document.removeEventListener("visibilitychange", this.#onVisibility)
    this.#pause()
    this.#polls.clear()
  }

  #interval(el) {
    const ms = Number(el.getAttribute("data-phem-poll"))
    return Number.isInteger(ms) && ms > 0 ? ms : 0
  }

  #wait(el, poll, ms) {
    clearTimeout(poll.timer)
    poll.timer = setTimeout(() => this.#tick(el, poll), ms)
  }

  #tick(el, poll) {
    poll.timer = null
    if (!el.isConnected || this.#polls.get(el) !== poll) {
      if (this.#polls.get(el) === poll) this.#polls.delete(el)
      return
    }

    poll.last = performance.now()
    // The next interval starts at the end of this poll.
    poll.running = navigator.onLine !== false && this.#send(el, () => {
      poll.running = false
      poll.last = performance.now()
      if (!this.#stopped && this.#polls.get(el) === poll && !document.hidden) this.#wait(el, poll, poll.interval)
    })
    if (!poll.running) this.#wait(el, poll, poll.interval)
  }

  #pause() {
    for (const poll of this.#polls.values()) {
      clearTimeout(poll.timer)
      poll.timer = null
    }
  }

  // The tab is visible again: a poll whose interval passed goes at once.
  #start() {
    for (const [el, poll] of this.#polls) {
      if (poll.timer !== null || poll.running) continue

      const left = poll.last + poll.interval - performance.now()
      if (left <= 0) this.#tick(el, poll)
      else this.#wait(el, poll, left)
    }
  }
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

// The fields that can be dirty: the bound fields, and the file inputs of a form. The files go with
// the submit only, so a file input is dirty while it has files.
const DIRTY_FIELDS = '[data-phem-bind], input[type="file"][name]'

// Whether the user changed a bound field: its value is not the value of the server render. A file
// input is dirty while it has files that a submit sends.
function isDirty(field) {
  if (field instanceof HTMLInputElement && field.type === "file") return isFormFileInput(field) && field.files.length > 0
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
// The items of feeds that one event sends at most (see markFeed()): the server takes no more (EventPayload::MAX_FEEDS).
const MAX_FEEDS = 100
// The boundaries that one load event sends at most: the server takes no more (EventPayload::MAX_LOADS).
const MAX_LOADS = 100

// The wait before a new connection to the hub after it refused one (an expired token).
const STREAM_RETRY = 5000

// The connections in a row that the hub refuses before it opens one: then the page stops, because
// it has no hub at its URL, or the hub never accepts its token.
const STREAM_ATTEMPTS = 3

// The number of the last events of a page whose refreshes it knows (see `sent`).
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

// Gives each bound field with no id in `root` an id from its key, and from its value for a checkbox
// or a radio button, so the morph matches the field and its parents by id. The fiber id of a
// component comes from its index: when a component goes away, the next component of its class
// gets its fiber id, and the morph can match the parent of an edited field with another element.
// A key and value that more than one field has get no id.
function bindIds(root) {
  const fields = new Map()
  for (const field of root.querySelectorAll("[data-phem-bind]:not([id])")) {
    const choice = field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")
    const id = `${KEY_ID}bind:${field.getAttribute("data-phem-bind")}${choice ? `=${field.value}` : ""}`
    fields.set(id, fields.has(id) ? null : field)
  }

  for (const [id, field] of fields) if (field) field.id = id
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
// the element by its id, and keeps the element as it is. A placeholder `<first>..<last>` is a run
// of components (see keptRun()). False when the state before the event does not have a run.
function keptElements(content, page, state) {
  for (const placeholder of content.querySelectorAll("template[data-phem-keep]")) {
    const keep = placeholder.getAttribute("data-phem-keep")
    const ids = keep.includes("..") ? keptRun(state, ...keep.split("..")) : [keep]
    if (!ids) return false

    const copies = []
    for (const id of ids) {
      const el = page.querySelector(`[${FIBER}="${id}"]`)
      if (!el) continue

      const copy = el.cloneNode(false)
      copy.setAttribute("data-phem-keep", "")
      copies.push(copy)
    }

    if (copies.length) placeholder.replaceWith(...copies)
  }

  return true
}

// The fiber ids of a run of kept components: `first`, then the next children of its parent up to
// `last`, in the shape of a state (its `_tree` entry, see Phem\State\TreeShape). The shape has the
// fibers from the page down, separated by a comma: each one is 8 base64url characters of its id,
// 8 of its hash or none, its depth, and `c` for a consumer. Null when the shape does not have the run.
function keptRun(state, first, last) {
  const entries = /^"_tree":"([^"]*)"/m.exec(state)?.[1].split(",") ?? []
  const start = entries.findIndex((entry) => shapeId(entry) === first)
  if (start < 0) return null

  const depth = shapeDepth(entries[start])
  const ids = []
  for (const entry of entries.slice(start)) {
    const d = shapeDepth(entry)
    if (d < depth) break
    if (d > depth) continue

    ids.push(shapeId(entry))
    if (ids.at(-1) === last) return ids
  }

  return null
}

function shapeId(entry) {
  const bytes = atob(entry.slice(0, 8).replace(/-/g, "+").replace(/_/g, "/"))
  return `phem${[...bytes].map((c) => c.charCodeAt(0).toString(16).padStart(2, "0")).join("")}`
}

function shapeDepth(entry) {
  const code = entry.endsWith("c") ? entry.slice(0, -1) : entry
  return Number(code.slice(code.length > 16 ? 16 : 8))
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
// of the value, a patch of its JSON text (see applyPatch()), or null for an entry that the state
// does not have any more. The controller sets them and joins the lines again, with no JSON encoder, so the text is the text that the server
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
    else if (Array.isArray(value)) entries.set(key, applyPatch(entries.get(key) ?? "", value))
    else entries.set(key, value)
  }

  const keys = [...entries.keys()].sort()
  return keys.length ? `{\n${keys.map((key) => `"${key}":${entries.get(key)}`).join(",\n")}\n}` : "{}"
}

// A patch of the JSON text of an entry (see Phem\State\TextPatch): splices [index, count, ...parts]
// on the parts between its commas, in the order of their indexes. One pass over the parts, with no
// spread: a splice of a large value can have more new parts than a call can have arguments.
function applyPatch(text, patch) {
  const parts = text.split(",")
  const out = []
  let at = 0

  for (const splice of patch) {
    while (at < splice[0]) out.push(parts[at++])
    for (let i = 2; i < splice.length; i++) out.push(splice[i])
    at = splice[0] + splice[1]
  }

  while (at < parts.length) out.push(parts[at++])

  return out.join(",")
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

// Copies of the scripts of a fragment: a script of a `<template>` does not run when it goes in the
// page, but a copy does, as in the page.
function activateScripts(fragment) {
  for (const script of fragment.querySelectorAll("script")) {
    const copy = document.createElement("script")
    for (const { name, value } of script.attributes) copy.setAttribute(name, value)
    copy.nonce = script.nonce
    copy.textContent = script.textContent
    script.replaceWith(copy)
  }
}

// The end of a streamed page (see Phem\Http\PageStream): the `<Suspense>` boundaries that the
// scripts of the stream did not put in the page (a CSP that blocks the scripts), and the state of
// the page, in the attributes of the root element. False when the stream failed: the page has no
// state.
function finishStream(root) {
  for (const template of document.querySelectorAll("template[data-phem-for]")) {
    const target = root.querySelector(`[data-phem-c="${template.getAttribute("data-phem-for")}"]`)
    if (target) {
      activateScripts(template.content)
      target.replaceWith(template.content)
    }

    if (template.nextElementSibling?.localName === "script") template.nextElementSibling.remove()
    template.remove()
  }

  const state = document.querySelector("template[data-phem-root-state]")
  const failed = !state || state.hasAttribute("data-phem-stream-error")
  if (state && !failed) {
    for (const { name, value } of state.attributes) if (name !== "data-phem-root-state") root.setAttribute(name, value)
  }

  state?.remove()
  root.removeAttribute("data-phem-streaming")

  return !failed
}

// The events that a listener of `phem:before-queue` cancelled, and that its `detail.resume()` can
// send later (see allowed()). A morph or a removal of the element drops its event, and aborts its
// `detail.signal`: the fiber id of a component comes from its index, so after a morph the handler
// can belong to another component, and the event would run on the wrong one. A Turbo visit drops
// all the events when Turbo caches the page (`turbo:before-cache`).
class HeldEvents {
  #held = new Set()

  // Holds the event of `el`: `hold` aborts when it drops. Returns the function that releases it.
  add(el, hold) {
    const entry = { el, hold }
    this.#held.add(entry)

    return () => this.#held.delete(entry)
  }

  // Drops the events whose element passes `test`.
  drop(test) {
    for (const entry of this.#held) {
      if (!test(entry.el)) continue

      this.#held.delete(entry)
      entry.hold.abort()
    }
  }
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
    // A streamed page has its state at its end: Stimulus starts after the parse of the page, but
    // an application can start it before. The actions of the root element do nothing before the
    // state.
    this.inactive = true
    if (this.element.hasAttribute("data-phem-streaming")) {
      if (document.readyState === "loading") {
        this.waitStream = () => {
          this.waitStream = null
          this.connect()
        }
        document.addEventListener("DOMContentLoaded", this.waitStream, { once: true })
        return
      }

      if (!finishStream(this.element)) {
        console.error("[phem] the stream of the page failed: the page has no events")
        return
      }
    }

    this.inactive = false
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
      const field = event.target instanceof HTMLOptionElement ? event.target.closest("select") : event.target
      const key = field?.getAttribute?.("data-phem-bind")
      if (!key) {
        this.keepFormField(event, field)
        return
      }

      if (!["value", "checked", "selected"].includes(event.detail.attributeName) || !this.element.contains(field)) return

      if (!this.sentBound) {
        if (this.pushing && this.boundFields(key).some(isDirty)) event.preventDefault()
        return
      }

      if (!(key in this.sentBound)) return
      if (!sameValue(boundValue(this.boundFields(key)), this.sentBound[key])) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-attribute", this.keepEdited)

    // The values of the fields of the forms with no `phem:bind` when the request of the event that
    // the morph renders now left: {submit, values: field => value} (see keepFormField()).
    this.sentForm = null
    // The fields whose value the morph keeps (see keepFormField()). The server marks them as
    // touched, so they belong to a Symfony form with live validation: the focused text field (not
    // after a submit: the server can give a new form), and a password field that the server
    // renders with no value (a PasswordType). The other fields, for example a controlled input
    // (`value={{ name }}`), get the value of the server render.
    this.keptValues = new WeakSet()
    // The fields that the controller marked as touched, and the server not yet (see dispatch()).
    this.clientMarks = new WeakSet()
    this.keepValue = (event) => {
      const t = event.target
      const next = event.detail.newElement
      if (next?.hasAttribute?.(TOUCHED)) this.clientMarks.delete(t)
      if (!(t instanceof HTMLInputElement || t instanceof HTMLTextAreaElement)) return

      const touched = this.sentForm !== null && next?.localName === t.localName && next.hasAttribute(TOUCHED)
      const focused = touched && !this.sentForm.submit && t === document.activeElement && isTextLike(t)
      const password = touched && t.type === "password" && next.type === "password" && t.value !== "" && !next.hasAttribute("value")
      if (focused || password) this.keptValues.add(t)
      else this.keptValues.delete(t)
    }
    this.element.addEventListener("turbo:before-morph-element", this.keepValue)

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

    // The children of a feed element are the items that the browser keeps (see Phem\Feed): the
    // server renders the element with no items, and gives the changes of the items in their own
    // streams. The morph of the same feed element keeps its children. A feed element with another
    // id is a new feed (a component with another key): the morph replaces its children.
    this.keepFeed = (event) => {
      const t = event.target
      const next = event.detail.newElement
      if (next?.hasAttribute?.("data-phem-feed")) t._phemSameFeed = t.id === next.id
      else if (!next && t.parentElement?.hasAttribute?.("data-phem-feed") && t.parentElement._phemSameFeed) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-element", this.keepFeed)

    // A morph or a removal of the element of a held event drops it (see HeldEvents). A stream that
    // does not morph (a push that removes or replaces an element) drops the events of the
    // elements that left the page.
    this.heldEvents = new HeldEvents()
    this.dropMorphed = (event) => {
      const target = event.target
      const removed = !event.detail.newElement
      // An item that the feed keeps (see keepFeed) stays in the page.
      if (removed && event.defaultPrevented) return
      this.heldEvents.drop((el) => el === target || (removed && target.contains(el)))
    }
    this.element.addEventListener("turbo:before-morph-element", this.dropMorphed)
    this.dropDetached = (event) => {
      const render = event.detail.render
      event.detail.render = async (element) => {
        try {
          await render(element)
        } finally {
          this.heldEvents.drop((el) => !el.isConnected)
        }
      }
    }
    document.addEventListener("turbo:before-stream-render", this.dropDetached)

    // Turbo caches the page before a visit renders a new page: no answer can send a held event
    // now. Its signal aborts before Turbo clones the page, so a question of the event (a
    // `phem-confirm` dialog) closes, and the snapshot does not show it.
    this.dropForCache = () => {
      this.heldEvents.drop(() => true)
      this.guardQuestion?.abort()
    }
    document.addEventListener("turbo:before-cache", this.dropForCache)

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
    // Each bound field gets an id from its key (see bindIds()): the field that the user edits keeps
    // its element, its focus and its value. Two lists with the same keys and no id between them and
    // their component get the same ids: the morph ignores these ids, and matches their items by
    // position. The placeholders of the components that the server did not render again become
    // copies of their elements. Before the `take` of renderStreams(), which runs this render.
    this.keyMorph = (event) => {
      const stream = event.detail.newStream
      if (stream.getAttribute("method") !== "morph") return

      const id = stream.getAttribute("target")
      const selector = stream.getAttribute("targets")
      const target = id ? document.getElementById(id) : selector ? document.querySelector(selector) : null
      if (!target || !this.element.contains(target)) return

      // The state before the event, for the runs of kept components: the controller sets the
      // new state before it renders the streams.
      const state = this.stateBefore ?? this.stateValue
      const render = event.detail.render
      event.detail.render = async (element) => {
        const content = stream.templateElement.content
        componentIds(target)
        if (!keptElements(content, this.element, state)) {
          dropKeyIds(this.element)
          console.error("[phem] the page does not have a run of components that the server kept: the page loads again")
          this.reload()
          return
        }

        componentIds(content)
        keyIds(target, target)
        keyIds(content, target)
        bindIds(target)
        bindIds(content)
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

    // The `phem-dirty` class of the bound fields that the user changed, and of the file inputs with files.
    this.showDirty = () => this.markDirty()
    this.element.addEventListener("input", this.showDirty)
    this.element.addEventListener("change", this.showDirty)
    this.showDirty()

    // True when the controller itself leaves the page (a redirect or a reload): no question.
    this.leaving = false
    // The browser asks before it unloads the page: a page cannot show its own question.
    this.guardUnload = (event) => {
      if (this.leaving || !this.dirtyGuard()) return

      event.preventDefault()
      event.returnValue = ""
    }
    // The question of a guard in a `<dialog>`, while it asks (see askGuardDialog()).
    this.guardQuestion = null
    // A click on a Turbo link asks in the dialog of the guard: the options of its visit are known,
    // so the visit can start again after a confirm.
    this.guardClick = (event) => {
      if (this.leaving) return

      const guard = this.dirtyGuard()
      if (guard && this.askGuardDialog(guard, event.target, event.detail.url)) {
        // Turbo follows no link, and the browser neither.
        event.preventDefault()
        event.detail.originalEvent?.preventDefault()
      }
    }
    // The URL of the link visit that the user confirmed in the dialog: it asks nothing.
    this.passed = null
    // The URL of the visit that the user confirmed: when Turbo starts it, the page leaves. A visit
    // that another listener cancels does not start, so the guard asks again.
    this.confirmed = null
    // Any other visit (a script, a Turbo form) asks with `window.confirm()`: its options are not
    // known, so the guard cannot start it again.
    this.guardVisit = (event) => {
      if (this.leaving) return

      const { url } = event.detail
      const passed = this.passed === url
      this.passed = null
      this.confirmed = passed ? url : null
      if (passed) return

      const guard = this.dirtyGuard()
      if (!guard) return

      if (window.confirm(guard.getAttribute("data-phem-guard") || this.guardMessageValue)) this.confirmed = url
      else event.preventDefault()
    }
    this.guardStart = (event) => {
      if (null !== this.confirmed && event.detail?.url === this.confirmed) this.leaving = true
    }
    window.addEventListener("beforeunload", this.guardUnload)
    document.addEventListener("turbo:click", this.guardClick)
    document.addEventListener("turbo:before-visit", this.guardVisit)
    document.addEventListener("turbo:visit", this.guardStart)

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

    // The `onVisible` and `onPoll` events: the browser sends them itself (see sendOwn()).
    this.visibleTriggers = new VisibleTriggers(this.element, (el, end) => this.sendOwn(el, "visible", end))
    this.polls = new Polls(this.element, (el, end) => this.sendOwn(el, "poll", end))
    this.watchTriggers()

    // The ids of the stale components (see the header): the next event sends them.
    this.stale = new Set()
    // The items that pushes gave to the feeds of the page (see markFeed()): the next event sends them.
    this.feedItems = []
    // The last events of this page with refreshes: the token (`sender` in the answer) => the ids of
    // the fibers that the event rendered (`rendered`). A refresh with the token of one of these
    // events does not render again what the event rendered (see markRefresh()).
    this.sent = new Map()
    // The number of events that run now, and the pushes that wait for them.
    this.busy = 0
    this.held = []
    // The number of pushes that Turbo renders now (see keepEdited).
    this.pushing = 0
    this.refreshTimer = null
    // The refresh that waits or runs: its number, or 0 (see scheduleRefresh()).
    this.refreshing = 0
    this.refreshes = 0
    this.streamTimer = null
    // The connections in a row that the hub refused before it opened one (see openStream()).
    this.streamFailures = 0
    // A page from the Turbo cache can have the mark of its last pushes: it has none now.
    this.showPushPending()
    this.openStream()
  }

  disconnect() {
    if (this.waitStream) document.removeEventListener("DOMContentLoaded", this.waitStream)
    this.waitStream = null
    if (this.inactive) return

    this.source?.close()
    this.source = null
    clearTimeout(this.refreshTimer)
    clearTimeout(this.streamTimer)
    // The refresh that waited does not run: Turbo must not cache the mark with the page.
    this.element.removeAttribute("data-phem-push-pending")
    this.element.removeEventListener("turbo:morph-element", this.keepLoading)
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepEdited)
    this.element.removeEventListener("turbo:before-morph-element", this.keepValue)
    this.element.removeEventListener("turbo:before-morph-element", this.keepKept)
    this.element.removeEventListener("turbo:before-morph-element", this.keepFeed)
    this.element.removeEventListener("turbo:before-morph-element", this.dropMorphed)
    document.removeEventListener("turbo:before-stream-render", this.dropDetached)
    document.removeEventListener("turbo:before-cache", this.dropForCache)
    this.heldEvents.drop(() => true)
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
    document.removeEventListener("turbo:click", this.guardClick)
    document.removeEventListener("turbo:before-visit", this.guardVisit)
    document.removeEventListener("turbo:visit", this.guardStart)
    this.guardQuestion?.abort()
    this.boundaryObserver?.disconnect()
    this.visibleTriggers.disconnect()
    this.polls.disconnect()
    this.hideError()
    // An event that still runs does not start the triggers or the polls again (see watchTriggers()).
    this.inactive = true
  }

  // Opens the EventSource of a page with #[Broadcast] (see the header).
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

      // The hub refused all the connections since the last open one: the URL has no hub (no
      // FrankenPHP, or no `mercure` directive), or the hub does not accept the token. A refresh
      // does not change that: stop.
      if (++this.streamFailures >= STREAM_ATTEMPTS) {
        console.warn(`[phem] the Mercure hub at ${this.streamValue.split("?")[0]} refused ${STREAM_ATTEMPTS} connections: the page gets no pushes. Run the application with FrankenPHP and the \`mercure\` directive in its Caddyfile, or set phem.mercure.hub_url to the URL of your hub. Check the subscriber key and the cookie of the hub.`)
        return
      }

      this.streamTimer = setTimeout(() => {
        this.markContentStale()
        const refresh = this.beginRefresh()
        this.queue = this.queue
          .then(() => this.send(this.element, null, REFRESH_EVENT, () => {}))
          .catch((e) => console.error("[phem]", e))
          .finally(() => this.endRefresh(refresh))
          .then(() => this.element.isConnected && !this.source && this.openStream())
      }, STREAM_RETRY)
    })

    source.addEventListener("open", () => {
      this.streamFailures = 0
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
    this.showPushPending()
  }

  // `data-phem-push-pending` on the root element while the page is not done with its pushes: a
  // push waits for an event, Turbo renders it, or its refresh waits or runs. A test with a real
  // browser waits until the attribute goes away (see Phem\Test\BrowserHub).
  showPushPending() {
    this.element.toggleAttribute("data-phem-push-pending", this.held.length > 0 || this.pushing > 0 || this.refreshing > 0)
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

      if (action === "phem-feed") {
        stream.remove()
        refresh = this.markFeed(stream) || refresh
        continue
      }

      if (!inRoot) continue

      this.markStale(["remove", "replace", "before", "after"].includes(action) ? target.parentElement : target)
      if (action === "append" || action === "prepend") removeDuplicates(target, stream)
    }

    if (message.content.querySelector("turbo-stream")) {
      console.debug("[phem] push", data)
      this.pushing++
      try {
        Turbo.renderStreamMessage(message.innerHTML)
      } catch (e) {
        // The push did not render: the page is not busy with it.
        this.pushing--
        this.showPushPending()
        throw e
      }
      afterStreams().then(() => {
        this.pushing--
        this.markDirty()
        this.showPushPending()
        // The push can show or remove an `onVisible` or an `onPoll` element.
        if (!this.inactive && this.element.isConnected) this.watchTriggers()
      })
    }

    if (refresh) this.scheduleRefresh()
  }

  // Marks the components of a refresh as stale: the component around the target, the components of
  // the `targets` selector (the instances of a component with #[Broadcast]), or the content of the
  // root element for a refresh with neither. A refresh of an event of this page (`data-phem-sender`)
  // skips a component that the event rendered with all the components in it: the refresh would
  // render nothing new. Returns whether one is stale.
  markRefresh(stream, target) {
    const rendered = this.sent.get(stream.getAttribute("data-phem-sender") ?? "")
    const fresh = (el) => rendered !== undefined && el !== null && [el, ...el.querySelectorAll(`[${FIBER}]`)].every((c) => rendered.has(c.getAttribute(FIBER)))

    const selector = stream.getAttribute("targets")
    if (selector === null) {
      if (!stream.hasAttribute("target")) return !fresh(this.element.querySelector(`[${FIBER}]`)) && this.markContentStale()
      return !fresh(componentOf(target)) && this.markStale(target)
    }

    let refresh = false
    for (const component of this.element.querySelectorAll(selector)) {
      if (fresh(component)) continue
      refresh = this.markStale(component) || refresh
    }

    return refresh
  }

  // A change of a feed in another request (see Phem\Stream\FeedPush), for the instances of the
  // `targets` selector that show the feed. A deleted item leaves the page at once. A new or a
  // changed item goes back to the server with the refresh: the server renders it in the feed with
  // the access rules of this user. A push of an event of this page skips a component that the
  // event rendered: its handler changed the feed. Returns whether one is stale.
  markFeed(stream) {
    const rendered = this.sent.get(stream.getAttribute("data-phem-sender") ?? "")
    const name = stream.getAttribute("data-phem-feed")
    const operation = stream.getAttribute("data-phem-feed-op")
    let refresh = false

    for (const component of this.element.querySelectorAll(stream.getAttribute("targets") ?? ":not(*)")) {
      const fiber = component.getAttribute(FIBER)
      const feed = document.getElementById(`${fiber}-${name}`)
      if (rendered?.has(fiber) || !feed || !component.contains(feed)) continue

      if (operation === "delete") {
        document.getElementById(`${feed.id}-${stream.getAttribute("data-phem-feed-key")}`)?.remove()
        continue
      }

      this.feedItems.push({ feed: feed.id, token: stream.getAttribute("data-phem-item"), op: operation })
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
    const refresh = this.beginRefresh()
    this.refreshTimer = setTimeout(() => {
      this.queue = this.queue
        .then(() => (this.stale.size ? this.send(this.element, null, REFRESH_EVENT, () => {}) : null))
        .catch((e) => console.error("[phem]", e))
        .finally(() => this.endRefresh(refresh))
    }, REFRESH_WAIT)
  }

  // A refresh starts to wait: the page shows `data-phem-push-pending` until its end. Returns its number.
  beginRefresh() {
    this.refreshing = ++this.refreshes
    this.showPushPending()
    return this.refreshing
  }

  // A refresh ended. A newer refresh started since then: the page waits for that one still.
  endRefresh(refresh) {
    if (this.refreshing !== refresh) return
    this.refreshing = 0
    this.showPushPending()
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
    // More boundaries go in the next load events.
    const batch = this.loadBatch.splice(0, MAX_LOADS)
    if (this.loadBatch.length > 0) queueMicrotask(() => this.sendLoads())

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

  // Watches the `onVisible` and the `onPoll` elements of the page (see VisibleTriggers and Polls),
  // with one query of the page.
  watchTriggers() {
    if (this.inactive) return

    const elements = [...this.element.querySelectorAll("[data-phem-on-visible], [data-phem-on-poll][data-phem-poll]")]
    this.visibleTriggers.scan(elements)
    this.polls.scan(elements)
  }

  // Sends an event that the browser makes itself, with no DOM event: `visible` (onVisible) or
  // `poll` (onPoll). It goes as the other events: `phem:before-queue`, a debounce or a throttle,
  // and the queue. `end(ok)` runs one time, at the end of the event (after its update). Returns
  // false when it did not go: then `end` does not run. A listener of `phem:before-queue` that
  // cancels it drops it: `detail.resume()` does not send it, and the next view or poll sends a
  // new event.
  sendOwn(el, type, end) {
    const attr = `data-phem-on-${type}`
    const handler = el.getAttribute(attr)
    if (this.inactive || !handler || !el.isConnected || !this.element.contains(el)) return false

    const event = { type, target: el, preventDefault() {} }
    let payload = null
    let dropped = false
    const read = () => (payload ??= this.payload(el, event, type, null))
    const send = () => !dropped && this.schedule(el, attr, handler, type, read(), end)
    if (!this.allowed(el, handler, event, type, read, send)) {
      dropped = true
      return false
    }

    send()
    return true
  }

  // data-action="click->phem#dispatch input->phem#dispatch ..." on the root element.
  dispatch(event) {
    if (this.inactive) {
      if (event.type === CALL_EVENT) console.warn("[phem] a phem:call event came before the page has its state, or on a page with no events: the handler does not run", event.target)
      return
    }

    const type = eventType(event)

    // An element is a drop target only when its dragover is cancelled: the drop must find its
    // handler, also through a `phem:stop` element (see handlerElement()).
    if (event.type === "dragover" && this.handlerElement({ type: "drop", target: event.target })) event.preventDefault()

    // A field of a form that the user changes is touched, also while its event waits.
    const changed = event.target
    if ((event.type === "input" || event.type === "change") && changed.name && changed.form && this.element.contains(changed.form) && !changed.hasAttribute(TOUCHED)) {
      changed.setAttribute(TOUCHED, "")
      this.clientMarks.add(changed)
    }

    const el = this.handlerElement(event)
    if (!el && event.type === CALL_EVENT) {
      const stopped = event.target.closest?.("[data-phem-on-call]")
      console.warn(
        stopped && this.element.contains(stopped)
          ? "[phem] a phem:stop element keeps this phem:call event from the onCall handler around it"
          : "[phem] a phem:call event has no onCall handler: dispatch it on the element of the handler, or on an element in it",
        event.target,
      )
      return
    }

    if (!el) {
      const live = this.liveElement(event)
      // Its "handler" is the key of the field: the server gets null (see fetchWithRetry()).
      if (live) this.schedule(live, "data-phem-live", live.getAttribute("data-phem-bind"), event.type, { type: event.type, live: true })
      return
    }

    // `phem:key="Enter"`: the other keys do not send the event, and keep their default action.
    const filter = el.getAttribute("data-phem-key-filter")
    if (filter !== null && (event.type === "keydown" || event.type === "keyup") && !matchesKey(filter, event)) return

    if (event.type === "submit") {
      event.preventDefault()
      // The first submit of this form still runs.
      if (this.locked.has(el)) return
    }

    if ((CANCELLED_EVENTS.has(event.type) && !keepsDefault(event)) || el.hasAttribute("data-phem-prevent")) event.preventDefault()

    // The detail of a call as it is at the time of the call, as the request sends it: JSON.
    let detail = null
    if (event.type === CALL_EVENT) {
      try {
        detail = JSON.parse(JSON.stringify(event.detail ?? null) ?? "null")
      } catch (e) {
        console.error("[phem] the detail of a phem:call event cannot be JSON: the handler does not run", e, event.target)
        return
      }
    }

    // The payload of the DOM event: after the listeners of `phem:before-queue`, which can change
    // the fields, and before a cancel resets them (see allowed()).
    const attr = el._phemAttr
    const handler = el.getAttribute(attr)
    let payload = null
    const read = () => (payload ??= this.payload(el, event, type, detail))
    const send = () => this.schedule(el, attr, handler, type, read())

    if (!this.allowed(el, handler, event, type, read, send)) {
      // The field has its value again: the user did not touch it.
      if (this.clientMarks.delete(changed)) changed.removeAttribute(TOUCHED)
      return
    }

    send()
  }

  // The payload of the DOM event of a handler element.
  // `type` is the type of the handler event (see eventType()), and `detail` the detail of a call.
  payload(el, event, type, detail) {
    // A submit, a change or a click of an element in a form sends the fields of the form, so its
    // handler can submit a Symfony form with them. The change and the click send the touched
    // fields too. The files go with the submit only.
    const submit = event.type === "submit"
    const form = FORM_EVENTS.has(event.type) ? formOf(el) : null
    const { fields, files } = form && this.element.contains(form) ? formFields(form, submit ? event.submitter : null) : { fields: null, files: [] }
    const payload = {
      type,
      value: "value" in el ? el.value : null,
      checked: "checked" in el ? el.checked : null,
      key: event.key ?? null,
      form: fields,
    }

    if (fields && !submit) payload.touched = touchedNames(form)

    // An element in an item of a feed: the server loads the item again, so its handler is in the
    // render (see Phem\Rendering\FeedBinder). Not in the event data: fetchWithRetry() sends it.
    const item = el.closest("[data-phem-item]")
    const feed = item?.parentElement
    if (item && feed?.hasAttribute("data-phem-feed") && this.element.contains(feed)) {
      payload.item = { feed: feed.id, token: item.getAttribute("data-phem-item") }
    }

    // Not in the JSON: fetchWithRetry() sends the files as parts of a multipart body. The handler
    // can take them: then the controller empties their inputs (see clearFiles()).
    if (submit && files.length) {
      payload.files = files
      payload.fileInputs = sentFileInputs(form)
    }

    // The text of a drop or a paste: the paste fires before the browser inserts it in the field.
    const transfer = transferOf(event)
    if (transfer) payload.text = transfer.getData?.("text/plain") ?? ""

    const transferred = transferredFiles(event)
    if (transferred.length) payload.files = transferred

    // The data of the JS code that calls the handler: it goes as JSON.
    if (type === "call") payload.detail = detail

    return payload
  }

  // Dispatches the cancelable `phem:before-queue` on the element of a handler, before its event goes.
  // A listener that cancels it (as `phem-confirm` does) stops the event: the DOM event does not do
  // its default action, and a field that changed gets the value of the server render again.
  //
  //
  // `detail.resume()` sends the event that the listener cancelled, later (for example after the
  // user confirms in a `<dialog>`): with the payload of the DOM event, and with no new
  // `phem:before-queue`. It sends the event one time only. It does nothing when the event was not
  // cancelled, when it dropped, or when the page has no events. In the dispatch, it undoes the
  // cancel: the event goes as if no listener cancelled it. `detail.signal` aborts when the event
  // drops (see HeldEvents): then resume() does nothing.
  allowed(el, handler, event, type, read, send) {
    const hold = new AbortController()
    let dispatching = true
    let resumed = false
    let release = () => {}
    const resume = () => {
      if (resumed || !before.defaultPrevented || hold.signal.aborted || this.inactive || !el.isConnected) return
      // The first submit of this form still runs.
      if (event.type === "submit" && this.locked.has(el)) return

      resumed = true
      release()
      if (!dispatching) send()
    }

    const before = new CustomEvent("phem:before-queue", { detail: { handler, type, resume, signal: hold.signal }, bubbles: true, cancelable: true })
    const allowed = el.dispatchEvent(before) || resumed
    dispatching = false
    if (allowed) return true

    // resume() sends the values of the DOM event, from before the reset of the field.
    read()
    release = this.heldEvents.add(el, hold)

    event.preventDefault()
    if (event.type === "input" || event.type === "change") {
      // The field that changed: the element, or a field of a form with the handler. All the radio
      // buttons of the group: the radio button that was checked is checked again.
      const field = el instanceof HTMLFormElement ? event.target : el
      const radios = field.type === "radio" && field.name ? [...(field.form ?? this.element).querySelectorAll('input[type="radio"]')].filter((r) => r.name === field.name) : [field]
      radios.forEach(resetField)
      this.markDirty()
    }

    return false
  }

  // Sends an event after its wait (debounce or throttle), or at once. The DOM event gets an id:
  // `phem:queue` gives it in the dispatch of the DOM event, so a Stimulus action of the element
  // knows its event. A held event gets it later, when `detail.resume()` of `phem:before-queue`
  // sends it (see allowed()). The event that carries it gives it in `ids` (more than one when the DOM event
  // replaced or joined an event that waits).
  // `end(ok)` runs at the end of the event (see enqueue()).
  schedule(el, attr, handler, type, payload, end = null) {
    const id = ++this.lastId
    const send = (ids) => this.enqueue(el, attr, handler, payload, ids, end)
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

  // Puts an event in the queue. An event of COALESCED_EVENTS that waits in the queue for the same
  // handler and element takes the new payload, and the new event does not go in the queue.
  // `end(ok)` runs at the end of the event, after its update, also when it failed (ok is false).
  enqueue(el, attr, handler, payload, ids = new Set(), end = null) {
    const coalesce = COALESCED_EVENTS.has(payload.type)
    const waiting = coalesce ? (this.waiting.get(el) ?? new Map()) : null
    const old = waiting?.get(attr)
    if (old && old.handler === handler) {
      old.payload = payload
      for (const id of ids) old.ids.add(id)
      if (end) old.ends.push(end)
      return
    }

    const job = { handler, payload, ids, ends: end ? [end] : [] }
    let ok = false
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

        return this.send(el, job.handler, job.payload, unlock, onProgress, job.ids, (result) => (ok = result))
      })
      .catch((e) => {
        ok = false
        console.error("[phem]", e)
      })
      .finally(() => {
        unlock()
        done()
        for (const end of job.ends) end(ok)
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

    // An event that the browser makes itself has no blur: it goes at once.
    if (debounce === "blur") return type === "visible" || type === "poll" ? null : { debounce }
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

  // Sends the waiting events of an element and of the elements around it (the form of a field), or
  // of all the elements, in the order they came.
  flush(target = null) {
    for (const [el, pending] of [...this.pending]) {
      if (target === null || el.contains(target)) for (const attr of [...pending.keys()]) this.release(el, attr)
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

  // The closest element with a handler for the event. `phem:stop` keeps the events of an element
  // from the handlers around it: the event goes to the element and its content only.
  handlerElement(event) {
    const stop = event.target.closest?.("[data-phem-stop]")
    const find = (name) => {
      const el = event.target.closest?.(`[data-phem-on-${name}]`)
      if (el && this.element.contains(el) && !(stop && stop !== el && el.contains(stop))) {
        el._phemAttr = `data-phem-on-${name}`
        return el
      }

      return null
    }

    switch (event.type) {
      // React's onChange fires on each key for text inputs. The onChange of a form gets each key of
      // its text fields too, but not of a `phem:live` field: it sends its own events, and the form
      // gets its native change.
      case "input": {
        const el = find("input")
        if (el) return el
        const change = find("change")
        return change && (isTextLike(change) || formTakesKeys(change, event.target)) ? change : null
      }
      case "change": {
        const el = find("change")
        return el && !isTextLike(el) && !formTakesKeys(el, event.target) ? el : null
      }
      // A scroll does not bubble: only the element that scrolls gets it.
      case "scroll":
        return event.target.hasAttribute?.("data-phem-on-scroll") ? find("scroll") : null
      case CALL_EVENT:
        return find("call")
      default:
        return find(event.type)
    }
  }

  // Sends the event of the element `el`. `beforeRender` runs when the last response arrives,
  // before the page changes. `onProgress` gets the progress of an upload. The pushes that come
  // while it runs wait for its end (see receive()). `ids` are the ids of the DOM events that it
  // carries (see schedule()). Resolves with false after a server error.
  async send(el, handler, event, beforeRender, onProgress = null, ids = new Set(), onDone = null) {
    this.busy++
    try {
      return await this.sendNow(el, handler, event, beforeRender, onProgress, ids, onDone)
    } finally {
      this.busy--
      if (!this.busy && this.held.length) {
        this.held.splice(0).forEach((data) => this.applyPush(data))
        this.showPushPending()
      }
    }
  }

  // `onDone(ok)` gets the result of the `phem:done` of the event.
  async sendNow(el, handler, event, beforeRender, onProgress, ids, onDone) {
    const notify = (name, detail = {}) => {
      if (name === "done") onDone?.(detail.ok === true)
      this.notify(el, handler, name, { ids: [...ids], ...detail })
    }

    // The bound fields when the event starts: the newest values. The request sends the fields
    // that changed.
    const fields = this.boundValues()
    // The fields of the form of a change or a click when the event starts, as the bound fields:
    // a field that the user changed while the event waited goes with it. A submit locks its form.
    if (event.form && event.type !== "submit") {
      const form = formOf(el)
      if (form && this.element.contains(form)) event = { ...event, form: formFields(form, null).fields, touched: touchedNames(form) }
    }

    const formValues = this.formValues()
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
    const feeds = this.feedItems.slice(0, MAX_FEEDS)
    if (feeds.length) event = { ...event, feeds }
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
      // The state is not valid any more (a new build, or a new secret), or the page that a
      // refresh renders is not there any more (a 404 of its render): load the page again. A
      // refresh comes from a push, not from the user: it does not drop the changes of a
      // `data-phem-guard`, it shows the error, and the user can copy the changes first.
      failed(409)
      if (event.refresh && this.dirtyGuard()) this.showError(handler, 409)
      else this.reload()
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
      if (url.protocol !== "http:" && url.protocol !== "https:") {
        console.error("[phem] redirect to a URL that is not http(s) was blocked", data.redirect)
        failed(response.status)
        return
      }

      // The server got the bound values with the event. `phem:done` comes before the visit, so
      // each `phem:send` gets its `phem:done`.
      this.leaving = true
      notify("done", { ok: true, status: response.status, redirect: url.href })
      Turbo.visit(url.href)
      return
    }

    for (const id of stale) this.stale.delete(id)
    this.feedItems.splice(0, feeds.length)
    // More pushed items than one event takes: the next refresh sends the others.
    if (this.feedItems.length) {
      for (const { feed } of this.feedItems) this.stale.add(feed.slice(0, feed.indexOf("-")))
      this.scheduleRefresh()
    }
    const stateBefore = this.stateValue
    this.stateValue = applyStateChanges(this.stateValue, data.changes ?? {})
    this.signatureValue = data.signature
    // Before the pushes that wait for this event (see receive()).
    if (data.sender) {
      this.sent.set(data.sender, new Set(data.rendered ?? []))
      if (this.sent.size > MAX_SENT) this.sent.delete(this.sent.keys().next().value)
    }
    if (data.query) this.updateUrl(data.query, true)
    this.sentBound = Object.fromEntries(Object.entries(fields).map(([key, { value }]) => [key, value]))
    this.sentForm = { submit: event.type === "submit", values: formValues }

    // Before the morph, so a listener can stop its own changes of the page.
    notify("receive")
    // Turbo dispatches `turbo:before-stream-render` while renderStreams() runs (see keyMorph).
    this.stateBefore = stateBefore
    let rendered
    try {
      rendered = data.streams ? this.renderStreams(data.streams, handler) : afterStreams()
    } finally {
      this.stateBefore = null
    }
    // The render of the answer failed: the event ends as an event that the server did not take.
    const renderFailed = (e) => {
      this.sentBound = null
      this.sentForm = null
      failed(response.status)
      throw e
    }

    return rendered.then(() => {
      this.sentBound = null
      this.sentForm = null
      this.resetRejected(bound)
      if (data.clearFiles) this.clearFiles(event)
      this.markDirty()

      // After the morph, so a listener sees the new page.
      const target = this.componentRoot(handler)
      for (const { name, detail } of data.events ?? []) target.dispatchEvent(new CustomEvent(`phem:${name}`, { detail, bubbles: true }))
      notify("done", { ok: true, status: response.status })

      // The morph can show a new boundary (also in the children of a boundary that loaded), and
      // new `onVisible` and `onPoll` elements.
      this.loadBoundaries()
      this.watchTriggers()
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

  // Loads the page again, with no question of a `data-phem-guard`.
  reload() {
    this.leaving = true
    Turbo.visit(window.location.href, { action: "replace" })
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
    // The file inputs stay in the page (see clearFiles()).
    const { files = [], fileInputs, stale = [], loads = null, item = null, feeds = [], ...data } = event

    // A `phem:live` event has no handler: the server only sets the bound values. A refresh has
    // no handler either: the server renders the stale components. A load of many boundaries has
    // the list of their handlers.
    const state = withState ? { state: this.stateValue } : {}
    const json = JSON.stringify({ ...state, signature: this.signatureValue, handler: data.live ? null : (loads ?? handler), event: data, bound, ...(stale.length ? { stale } : {}), ...(item ? { item } : {}), ...(feeds.length ? { feeds } : {}) })

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

  // The morph of a field of a form with no `phem:bind` (a Symfony form): the value stays in the
  // page. The morph keeps the value that the user changed after the request left, and the values
  // of keepValue(). A field that keeps its value keeps its touched mark.
  keepFormField(event, field) {
    if (!this.sentForm || !field?.name || !field.form || !this.element.contains(field)) return

    const { attributeName, mutationType } = event.detail
    const sent = this.sentForm.values
    const changed = sent.has(field) && !sameValue(fieldValue(field), sent.get(field))
    if (attributeName === TOUCHED) {
      if (mutationType === "remove" && changed) event.preventDefault()
      return
    }

    if (!["value", "checked", "selected"].includes(attributeName)) return

    if (changed || this.keptValues.has(field)) event.preventDefault()
  }

  // The values of the fields of the forms with no `phem:bind`: field => value.
  formValues() {
    const values = new Map()
    for (const form of this.element.querySelectorAll("form")) {
      for (const field of form.elements) if (field.name && !field.hasAttribute("data-phem-bind")) values.set(field, fieldValue(field))
    }

    return values
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

  // Gives the `phem-dirty` class to each bound field that the user changed and to each file input
  // with files, and to each component, form, guard and root element with one. The server render
  // has no such class, so it runs after each morph.
  markDirty() {
    const fields = [...this.element.querySelectorAll(DIRTY_FIELDS)]
    const dirty = fields.filter(isDirty)

    for (const field of fields) field.classList.toggle("phem-dirty", dirty.includes(field))
    for (const el of [this.element, ...this.element.querySelectorAll(`[${FIBER}], form, [data-phem-guard]`)]) {
      el.classList.toggle("phem-dirty", dirty.some((field) => el.contains(field)))
    }
  }

  // Asks the question of a guard in the `<dialog>` of its `data-phem-guard-dialog` selector (see
  // phem_dialog_question.js), with the label `data-phem-guard-accept`, for a click on `link`. When
  // the user confirms, the link loads with the options that Turbo gives it (see Turbo's
  // followedLinkToLocation()), and no second question. False when the guard has no dialog, or
  // when the dialog cannot ask: `window.confirm()` asks on `turbo:before-visit`.
  askGuardDialog(guard, link, url) {
    const question = DialogQuestion.find(guard.getAttribute("data-phem-guard-dialog"), "data-phem-guard")
    if (!question) return false

    const action = ["advance", "replace", "restore"].find((a) => a === link.getAttribute("data-turbo-action")) ?? "advance"
    const acceptsStreamResponse = link.hasAttribute("data-turbo-stream")
    const asking = new AbortController()
    const confirm = () => {
      this.passed = url
      Turbo.visit(url, { action, acceptsStreamResponse })
    }

    const message = guard.getAttribute("data-phem-guard") || this.guardMessageValue
    if (!question.ask(message, guard.getAttribute("data-phem-guard-accept"), { confirm, signal: asking.signal })) return false

    // A new question replaces the open question. The question ends when the dialog closes.
    this.guardQuestion?.abort()
    this.guardQuestion = asking
    question.dialog.addEventListener("close", () => {
      if (this.guardQuestion === asking) this.guardQuestion = null
    }, { once: true })

    return true
  }

  // The closest `data-phem-guard` element of a dirty bound field or file input, or null.
  dirtyGuard() {
    for (const field of this.element.querySelectorAll(DIRTY_FIELDS)) {
      const guard = field.closest("[data-phem-guard]")
      if (guard && this.element.contains(guard) && isDirty(field)) return guard
    }

    return null
  }

  // After the morph of a handler that took the files of its submit (`$e->clearFiles()`): empties
  // the file inputs that sent them, as a full page POST does. An input whose files the user
  // changed after the request left keeps its new files.
  clearFiles(event) {
    for (const [input, files] of event.fileInputs ?? []) {
      if (input.files.length === files.length && files.every((file, i) => input.files[i] === file)) input.value = ""
    }
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
