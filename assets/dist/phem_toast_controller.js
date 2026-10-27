import { Controller } from "@hotwired/stimulus"

// The types of a toast. Another type is `info`.
const TYPES = ["info", "success", "warning", "error"]

// The default message of a failed event, as in the phem controller.
const ERROR_MESSAGE = "Something went wrong. Please try again."

// The events that a phem-toast element showed: one element shows each event, also when the `show`
// action and the `window` listener get the same event.
const shown = new WeakSet()

// Each toast has an id: the morph does not match it with a new element of the server render.
let count = 0

// The `phem-toast` controller: shows a short message (a toast) in its element for the browser
// events of Phem. The toasts stay after the morph of an event.
//
//     <div data-controller="phem-toast"></div>
//
// It listens on `window`:
//
// - `phem:toast`, from `$e->dispatcher->dispatch('toast', ['message' => 'Saved'])`. The detail is
//   the message, or an object with `message`, and an optional `type` and `duration`;
// - `phem:error`: it shows the error message in place of the default message of Phem. The message
//   is the `errorMessage` value, or else the `data-phem-error-message-value` of the root element;
// - `phem:rate-limited`: it shows the `rateLimitedMessage` value.
//
// The `show` action shows the detail of another event: `data-action="saved->phem-toast#show"`.
// A toast with the same type and message as a toast on the screen does not show again: the toast on
// the screen starts its time again.
//
// A toast goes away after the `duration` value (in ms), or with its close button. A duration of
// `0` keeps it until the close button. The time stops while the pointer or the focus is on the
// toast. The element is a live region (`aria-live="polite"`), so a screen reader reads each toast.
//
// The toasts and their durations are in the DOM, so a toast that the controller gets again (after a
// move of the element, or in a page from the Turbo cache) has its time and its close button.
export default class PhemToastController extends Controller {
  static values = {
    duration: { type: Number, default: 5000 },
    errorMessage: String,
    rateLimitedMessage: { type: String, default: "Too many changes. Please wait and try again." },
    closeLabel: { type: String, default: "Close" },
  }

  connect() {
    // The timer of each toast.
    this.timers = new Map()

    // The live region exists before the first toast. When the server render has no `aria-live`,
    // the controller adds it with a mark, and the morph does not remove them.
    if (!this.element.hasAttribute("aria-live")) {
      this.element.setAttribute("aria-live", "polite")
      this.element.setAttribute("data-phem-toast-live", "")
    }

    this.keepLive = (event) => {
      const { attributeName, mutationType } = event.detail
      const owned = event.target === this.element && this.element.hasAttribute("data-phem-toast-live")
      if (owned && mutationType === "remove" && ["aria-live", "data-phem-toast-live"].includes(attributeName)) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-attribute", this.keepLive)

    // The server render has no toasts: the morph does not change or remove them.
    this.keepToasts = (event) => {
      if (this.#isToast(event.target)) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-element", this.keepToasts)

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("mouseover", this.pause)
    this.element.addEventListener("focusin", this.pause)
    this.element.addEventListener("mouseout", this.resume)
    this.element.addEventListener("focusout", this.resume)

    window.addEventListener("phem:toast", this.show)
    window.addEventListener("phem:error", this.onError)
    window.addEventListener("phem:rate-limited", this.onRateLimited)

    // The toasts of the element before this connect.
    for (const el of this.#toasts()) this.#start(el)
  }

  // The toasts stay: a move of the element connects it again.
  disconnect() {
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepLive)
    this.element.removeEventListener("turbo:before-morph-element", this.keepToasts)
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("mouseover", this.pause)
    this.element.removeEventListener("focusin", this.pause)
    this.element.removeEventListener("mouseout", this.resume)
    this.element.removeEventListener("focusout", this.resume)
    window.removeEventListener("phem:toast", this.show)
    window.removeEventListener("phem:error", this.onError)
    window.removeEventListener("phem:rate-limited", this.onRateLimited)

    for (const timer of this.timers.values()) clearTimeout(timer)
    this.timers.clear()
  }

  // The detail is the message, or `{ message, type, duration }`.
  show = (event) => {
    const detail = typeof event.detail === "string" ? { message: event.detail } : (event.detail ?? {})
    if (detail.message === undefined || detail.message === null || detail.message === "") return
    if (!this.#take(event)) return

    const type = TYPES.includes(detail.type) ? detail.type : "info"
    const duration = Number(detail.duration ?? NaN)
    this.#add(String(detail.message), type, Number.isFinite(duration) ? duration : this.durationValue)
  }

  // A listener before this one can show its own message: it prevents the default.
  onError = (event) => {
    if (event.defaultPrevented || !this.#take(event)) return

    event.preventDefault()
    this.#add(this.#errorMessage(event.target), "error", this.durationValue)
  }

  onRateLimited = (event) => {
    if (!this.#take(event)) return

    this.#add(this.rateLimitedMessageValue, "warning", this.durationValue)
  }

  onClick = (event) => {
    const close = event.target.closest?.(".phem-toast-close")
    const el = close?.parentElement
    if (this.#isToast(el)) this.#remove(el)
  }

  // The time stops while the pointer or the focus is on the toast.
  pause = (event) => {
    const el = this.#toastOf(event.target)
    if (el) clearTimeout(this.timers.get(el))
  }

  resume = (event) => {
    const el = this.#toastOf(event.target)
    if (!el || el.contains(event.relatedTarget)) return

    const held = event.type === "mouseout" ? el.contains(document.activeElement) : el.matches(":hover")
    if (!held) this.#start(el)
  }

  // True for the first phem-toast element that gets the event.
  #take(event) {
    if (shown.has(event)) return false

    shown.add(event)

    return true
  }

  // The `errorMessage` value of the element, or else the message of the root element of Phem.
  #errorMessage(target) {
    if (this.errorMessageValue) return this.errorMessageValue

    const root = target instanceof Element ? target.closest("[data-controller~='phem']") : null

    return root?.getAttribute("data-phem-error-message-value") || ERROR_MESSAGE
  }

  // The message is text, not HTML.
  #add(message, type, duration) {
    const same = this.#toasts().find((el) => el.dataset.phemToastType === type && el.firstChild.textContent === message)
    if (same) {
      same.dataset.phemToastDuration = String(duration)
      this.#start(same)

      return
    }

    const el = document.createElement("div")
    el.id = `phem-toast-${++count}`
    el.className = `phem-toast phem-toast-${type}`
    el.dataset.phemToastType = type
    el.dataset.phemToastDuration = String(duration)

    const text = document.createElement("span")
    text.className = "phem-toast-message"
    text.textContent = message

    const close = document.createElement("button")
    close.type = "button"
    close.className = "phem-toast-close"
    close.setAttribute("aria-label", this.closeLabelValue)
    close.textContent = "×"

    el.append(text, close)
    this.element.append(el)
    this.#start(el)
  }

  #start(el) {
    clearTimeout(this.timers.get(el))

    const duration = Number(el.dataset.phemToastDuration)
    if (!(duration > 0)) return

    this.timers.set(el, setTimeout(() => this.#remove(el), duration))
  }

  #remove(el) {
    clearTimeout(this.timers.get(el))
    this.timers.delete(el)
    el.remove()
  }

  #toasts() {
    return [...this.element.children].filter((el) => this.#isToast(el))
  }

  #toastOf(node) {
    const el = node instanceof Element ? node.closest(".phem-toast") : null

    return this.#isToast(el) ? el : null
  }

  #isToast(el) {
    return el?.parentElement === this.element && el.hasAttribute("data-phem-toast-type")
  }
}
