import { Controller } from "@hotwired/stimulus"
import { onControl, swipe } from "./phem_swipe.js"
import { hide, show } from "./phem_transition.js"

// The types of a toast. Another type is `info`.
const TYPES = ["info", "success", "warning", "error"]

// The default message of a failed event, as in the phem controller.
const ERROR_MESSAGE = "Something went wrong. Please try again."

// The events that a phem-toast element showed: one element shows each event, also when the `show`
// action and the `window` listener get the same event.
const shown = new WeakSet()

// Each toast has an id: the morph does not match it with a new element of the server render.
let count = 0

// The distance (px) of a swipe that closes the toast.
const SWIPE = 40

// The `phem-toast` controller: shows a short message (a toast) in its element for the browser
// events of Phem. The toasts stay after the morph of an event.
//
//     <div data-controller="phem-toast"></div>
//
// It listens on `window`:
//
// - `phem:toast`, from `$e->toast('Saved')` or `Phem\Toasts`. The detail is the message, or an
//   object with `message`, and an optional `type` and `duration`;
// - `phem:error`: it shows the error message in place of the default message of Phem. The message
//   is the `errorMessage` value, or else the `data-phem-error-message-value` of the root element;
// - `phem:rate-limited`: it shows the `rateLimitedMessage` value.
//
// The `show` action shows the detail of another event: `data-action="saved->phem-toast#show"`.
// A toast with the same type and message as a toast on the screen does not show again: the toast on
// the screen starts its time again.
//
// A toast goes away after the `duration` value (in ms), or with its close button. A duration of
// `0` keeps it until the close button. The element is a live region (`aria-live="polite"`), so a
// screen reader reads each toast.
//
// The pointer or the focus on the element expands the stack: the element and the toasts get
// `data-expanded`, and the time of all the toasts stops. Each toast gets `--toast-height` (its
// height) and `--toast-offset-y` (the heights of the newer toasts), and the element gets
// `--toast-frontmost-height` (the height of the last toast), for a stack that shows only the last
// toast until the pointer comes. The stack closes when the pointer and the focus go, and when its
// last toast goes. The morph of the element does not remove its state.
//
// A swipe of a toast in a direction of the `swipeDirection` value ("down right" by default; also
// `up` and `left`) closes it. During the swipe, the toast gets `data-swiping`, and the distance in
// `--toast-swipe-movement-x` and `--toast-swipe-movement-y`. A swipe of 40 px closes the toast,
// with `data-swipe-direction`: its `[data-ending-style]` transition can go on in that direction.
// A shorter swipe gives the toast its place again. An empty value turns off the swipe.
//
// Each toast has these parts, as Base UI: the root (`data-phem-toast`), the description
// (`[data-phem-toast-description]`, the message) and the close button (`[data-phem-toast-close]`).
// A `<template data-phem-toast-target="template">` in the element gives the markup of the app,
// with these parts; else the controller makes them. The root gets `data-type` (the type),
// `--toast-index` (0 for the last toast), and `data-limited` when more than the `limit` value of
// newer toasts are on the screen. See phem_transition.js for the animations: a toast that goes
// stays until the end of its `[data-ending-style]` transition.
//
// The toasts and their durations are in the DOM, so a toast that the controller gets again (after a
// move of the element, or in a page from the Turbo cache) has its time and its close button.
export default class PhemToastController extends Controller {
  static targets = ["template"]
  static values = {
    duration: { type: Number, default: 5000 },
    limit: { type: Number, default: 3 },
    errorMessage: String,
    rateLimitedMessage: { type: String, default: "Too many changes. Please wait and try again." },
    closeLabel: { type: String, default: "Close" },
    swipeDirection: { type: String, default: "down right" },
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

    // The morph removes the state of the stack from the element: it gets it again.
    this.morphed = (event) => {
      if (event.target !== this.element) return

      this.element.toggleAttribute("data-expanded", this.expanded)
      this.#stack()
    }
    this.element.addEventListener("turbo:morph-element", this.morphed)

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("mouseover", this.onOver, options)
    this.element.addEventListener("focusin", this.onOver, options)
    this.element.addEventListener("mouseout", this.onOut, options)
    this.element.addEventListener("focusout", this.onOut, options)
    this.element.addEventListener("pointerdown", this.onPointerDown, options)

    window.addEventListener("phem:toast", this.show)
    window.addEventListener("phem:error", this.onError)
    window.addEventListener("phem:rate-limited", this.onRateLimited)

    // The toasts of the element before this connect (a page from the Turbo cache) get their time.
    this.expanded = true
    this.#expand(false)
  }

  // The toasts stay: a move of the element connects it again.
  disconnect() {
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepLive)
    this.element.removeEventListener("turbo:before-morph-element", this.keepToasts)
    this.element.removeEventListener("turbo:morph-element", this.morphed)
    this.listening.abort()
    this.swiping?.()
    this.swiping = null
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
    const close = event.target.closest?.("[data-phem-toast-close]")
    const el = close ? this.#toastOf(close) : null
    if (el) this.#remove(el)
  }

  // The pointer or the focus on the element expands the stack, and stops the time.
  onOver = () => this.#expand(true)

  onOut = (event) => {
    if (event.relatedTarget instanceof Node && this.element.contains(event.relatedTarget)) return

    const held = event.type === "mouseout" ? this.element.contains(document.activeElement) : this.element.matches(":hover")
    if (!held) this.#expand(false)
  }

  // A swipe: the toast follows the pointer, in the directions of the `swipeDirection` value.
  onPointerDown = (event) => {
    if (!event.isPrimary || event.button !== 0 || this.swiping) return

    const el = this.#toastOf(event.target)
    const directions = this.swipeDirectionValue.split(/\s+/).filter(Boolean)
    if (!el || directions.length === 0 || el.hasAttribute("data-ending-style") || onControl(el, event.target)) return

    this.swiping = swipe(event, directions, {
      end: () => {
        this.swiping = null
        el.removeAttribute("data-swiping")
      },
      move: (movement) => {
        el.setAttribute("data-swiping", "")
        el.style.setProperty("--toast-swipe-movement-x", `${movement.x}px`)
        el.style.setProperty("--toast-swipe-movement-y", `${movement.y}px`)
        clearTimeout(this.timers.get(el))
      },
      release: ({ direction, distance }) => {
        if (distance >= SWIPE) {
          el.setAttribute("data-swipe-direction", direction)
          this.#remove(el)

          return
        }

        this.#unswipe(el)
        if (!this.expanded) this.#start(el)
      },
      cancel: () => {
        this.#unswipe(el)
        if (!this.expanded) this.#start(el)
      },
    })
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
    // A toast that goes away does not count: it is removed at the end of its animation.
    const same = this.#toasts().find((el) => !el.hasAttribute("data-ending-style") && el.getAttribute("data-type") === type && this.#message(el) === message)
    if (same) {
      same.dataset.phemToastDuration = String(duration)
      if (!this.expanded) this.#start(same)

      return
    }

    const el = this.#make()
    el.id = `phem-toast-${++count}`
    el.setAttribute("data-phem-toast", "")
    el.setAttribute("data-type", type)
    el.dataset.phemToastDuration = String(duration)
    el.hidden = true

    const description = el.querySelector("[data-phem-toast-description]") ?? el
    description.textContent = message

    this.element.append(el)
    show(el)
    this.#stack()
    el.toggleAttribute("data-expanded", this.expanded)
    if (!this.expanded) this.#start(el)
  }

  // A copy of the root of the template, or the default parts.
  #make() {
    const root = this.hasTemplateTarget ? this.templateTarget.content.firstElementChild : null
    if (root) return root.cloneNode(true)

    const el = document.createElement("div")

    const text = document.createElement("span")
    text.setAttribute("data-phem-toast-description", "")

    const close = document.createElement("button")
    close.type = "button"
    close.setAttribute("data-phem-toast-close", "")
    close.setAttribute("aria-label", this.closeLabelValue)
    close.textContent = "×"

    el.append(text, close)

    return el
  }

  #message(el) {
    return (el.querySelector("[data-phem-toast-description]") ?? el).textContent
  }

  // The position of each toast in the stack: 0 for the last toast, and the heights of the newer
  // toasts.
  #stack() {
    const toasts = this.#toasts().filter((el) => !el.hasAttribute("data-ending-style")).reverse()
    let offset = 0

    toasts.forEach((el, i) => {
      const height = el.offsetHeight
      el.style.setProperty("--toast-index", String(i))
      el.style.setProperty("--toast-height", `${height}px`)
      el.style.setProperty("--toast-offset-y", `${offset}px`)
      el.toggleAttribute("data-limited", i >= this.limitValue)
      offset += height
    })

    this.element.style.setProperty("--toast-frontmost-height", `${toasts[0]?.offsetHeight ?? 0}px`)
  }

  // The stack shows all its toasts, and their time stops. The time starts again when it closes.
  #expand(expanded) {
    if (expanded === this.expanded) return

    this.expanded = expanded
    this.element.toggleAttribute("data-expanded", expanded)

    for (const el of this.#toasts()) {
      el.toggleAttribute("data-expanded", expanded)
      if (expanded) clearTimeout(this.timers.get(el))
      else if (!el.hasAttribute("data-swiping")) this.#start(el)
    }

    if (expanded) this.#stack()
  }

  #unswipe(el) {
    el.style.removeProperty("--toast-swipe-movement-x")
    el.style.removeProperty("--toast-swipe-movement-y")
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
    if (el.hasAttribute("data-ending-style")) return

    hide(el, {
      onEnd: () => {
        el.remove()
        this.#release()
      },
    })
    this.#stack()
  }

  // A toast that goes does not get a mouseout or a focusout: the stack closes when the pointer
  // and the focus are not on it, or when it has no toast.
  #release() {
    if (!this.expanded) return

    const held = this.element.matches(":hover") || this.element.contains(document.activeElement)
    if (!held || this.#toasts().length === 0) this.#expand(false)
  }

  #toasts() {
    return [...this.element.children].filter((el) => this.#isToast(el))
  }

  #toastOf(node) {
    const el = node instanceof Element ? node.closest("[data-phem-toast]") : null

    return this.#isToast(el) ? el : null
  }

  #isToast(el) {
    return el?.parentElement === this.element && el.hasAttribute("data-phem-toast")
  }
}

