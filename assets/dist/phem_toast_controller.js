import { Controller } from "@hotwired/stimulus"
import { isFocusVisible, tabbables } from "./phem_focus.js"
import { onControl, swipe } from "./phem_swipe.js"
import { PausableTimers } from "./phem_timers.js"
import { hide, show } from "./phem_transition.js"

// The types of a toast. A toast with another type, or with no type, has no type.
const TYPES = ["info", "success", "warning", "error"]

// The default message of a failed event, as in the phem controller.
const ERROR_MESSAGE = "Something went wrong. Please try again."

// The events that a phem-toast element showed: one element shows each event, also when the `show`
// action and the `window` listener get the same event.
const shown = new WeakSet()

// Each toast has an id: the morph does not match it with a new element of the server render.
let count = 0

// toast => the height of its content.
const heights = new WeakMap()

// The distance (px) of a swipe that closes the toast.
const SWIPE = 40

// The mark of the attributes of the element that the controller added: the morph keeps them.
const OWNED = "data-phem-toast-owned"

// The `phem-toast` controller: shows a short message (a toast) in its element for the browser
// events of Phem. The toasts stay after the morph of an event.
//
//     <div data-controller="phem-toast"></div>
//
// It listens on `window`:
//
// - `phem:toast`, from `$e->toast('Saved')` or `Phem\Toasts`. The detail is the message, or an
//   object with `message`, and an optional `type`, `duration` and `title`;
// - `phem:error`: it shows the error message in place of the default message of Phem. The message
//   is the `errorMessage` value, or else the `data-phem-error-message-value` of the root element;
// - `phem:rate-limited`: it shows the `rateLimitedMessage` value.
//
// The `show` action shows the detail of another event: `data-action="saved->phem-toast#show"`.
// A toast with the same type, title and message as a toast on the screen does not show again: the
// toast on the screen starts its full time again. A toast with no type and a toast with the `info`
// type are not the same.
//
// A toast goes away after the `duration` value (in ms), or with its close button. A duration of
// `0` keeps it until the close button. The element is a live region (`aria-live="polite"`), so a
// screen reader reads each toast. It is also a landmark, as the viewport of Base UI: it gets
// `role="region"`, `aria-label` (the `label` value, "Notifications"), `tabindex="-1"`,
// `aria-atomic="false"` and `aria-relevant="additions text"`, unless the server render gives them.
//
// While there is a toast, the F6 key moves the focus to the element. Tab then goes to the toasts:
// each toast is a dialog that can have the focus (`role="dialog"`, `tabindex="0"`,
// `aria-modal="false"`, and `aria-labelledby` and `aria-describedby` for its title and its
// description). The newest toast is first in the DOM, as in Base UI, so Tab goes to the newest
// toast first. Escape closes the toast with the focus: the focus goes to the next (older) toast,
// or else to the newer toast, or else back to the element that had it before F6. After a mouse
// click, the focus does not move. Shift+Tab on the element, and Tab after the last toast, also
// give the focus back to that element.
//
// The pointer or the focus of the keyboard on the element expands the stack: the element and the
// toasts get `data-expanded`, and the time of all the toasts stops. The time also stops while the
// browser window does not have the focus. The time that remains goes on after: the full time does
// not start again. Each toast gets `--toast-height` (the height of its content) and
// `--toast-offset-y` (the heights of the newer toasts), and the element gets
// `--toast-frontmost-height` (the height of the newest toast), for a stack that shows only the
// newest toast until the pointer comes. The stack closes when the pointer and the focus go, and
// when its last toast goes. The morph of the element does not remove its state.
//
// A swipe of a toast in a direction of the `swipeDirection` value ("down right" by default; also
// `up` and `left`) closes it. During the swipe, the toast gets `data-swiping`, `transition: none`
// in its `style` (it follows the pointer with no delay), and the distance in
// `--toast-swipe-movement-x` and `--toast-swipe-movement-y` (`0px` with no swipe). The swipe keeps
// the axis of its first move. The toast gets `data-swipe-direction` at its first move in a
// direction of the value. A swipe of 40 px closes the toast: its `[data-ending-style]` transition
// can go on in that direction. A shorter swipe removes `data-swipe-direction`, gives the toast its
// place again, and the time that remained goes on. An empty value turns off the swipe.
//
// Each toast has these parts, as Base UI: the root (`data-phem-toast`), the title
// (`[data-phem-toast-title]`), the description (`[data-phem-toast-description]`, the message) and
// the close button (`[data-phem-toast-close]`). A toast with no title has no title part: the
// controller removes it. A template with no title part does not show the title. A `<template data-phem-toast-target="template">` in the element gives the markup of
// the app, with these parts; else the controller makes them. A template can also have a content
// part (`[data-phem-toast-content]`, around the text and the button): it gets `data-behind` when a
// newer toast is in front of its toast, and `data-expanded` with the stack, so a stack can hide
// the text of the toasts behind the newest one. The root gets `data-type` (the type; a toast with
// no type has no `data-type`), `--toast-index` (0 for the newest toast), and `data-limited` and
// `inert` when more than the `limit` value of newer toasts are on the screen. The title, the
// description and the close button also get `data-type`. The close button gets
// `aria-hidden="true"` while the stack is not expanded and the button has no focus. See
// phem_transition.js for the animations: a toast that goes stays until the end of its
// `[data-ending-style]` transition.
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
    label: { type: String, default: "Notifications" },
    swipeDirection: { type: String, default: "down right" },
  }

  connect() {
    // The timer of each toast, as in Base UI.
    this.timers = new PausableTimers((el) => this.#remove(el))

    // The window has the focus until its first blur, as in Base UI.
    this.windowFocused = true

    // The live region and the landmark exist before the first toast. The controller adds the
    // attributes that the server render does not have, with a mark, and the morph does not
    // remove them.
    const attributes = {
      "aria-live": "polite",
      role: "region",
      "aria-label": this.labelValue,
      tabindex: "-1",
      "aria-atomic": "false",
      "aria-relevant": "additions text",
    }
    const owned = new Set((this.element.getAttribute(OWNED) ?? "").split(" ").filter(Boolean))

    for (const [name, value] of Object.entries(attributes)) {
      if (this.element.hasAttribute(name) && !owned.has(name)) continue

      this.element.setAttribute(name, value)
      owned.add(name)
    }

    if (owned.size > 0) this.element.setAttribute(OWNED, [...owned].join(" "))

    this.keepLive = (event) => {
      const { attributeName, mutationType } = event.detail
      if (event.target !== this.element || mutationType !== "remove") return

      const names = (this.element.getAttribute(OWNED) ?? "").split(" ")
      if (attributeName === OWNED || names.includes(attributeName)) event.preventDefault()
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
    this.element.addEventListener("focusin", this.onCloseFocus, options)
    this.element.addEventListener("focusout", this.onCloseFocus, options)
    this.element.addEventListener("pointerdown", this.onPointerDown, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    window.addEventListener("keydown", this.onWindowKeydown, options)
    window.addEventListener("blur", this.onWindowBlur, options)
    window.addEventListener("focus", this.onWindowFocus, options)

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

    this.timers.clear()
  }

  // The detail is the message, or `{ message, type, duration, title }`.
  show = (event) => {
    const detail = typeof event.detail === "string" ? { message: event.detail } : (event.detail ?? {})
    if (detail.message === undefined || detail.message === null || detail.message === "") return
    if (!this.#take(event)) return

    const type = TYPES.includes(detail.type) ? detail.type : ""
    const duration = Number(detail.duration ?? NaN)
    // A title is a text or a number, as Phem\Toast reads it. Another value is no title.
    const title = typeof detail.title === "string" || typeof detail.title === "number" ? String(detail.title).trim() : ""
    this.#add(String(detail.message), type, Number.isFinite(duration) ? duration : this.durationValue, title)
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

  // The pointer or the focus of the keyboard on the element expands the stack, and stops the
  // time. The focus of a click on a toast does not: the stack closes when the pointer goes.
  onOver = (event) => {
    if (event.type === "focusin" && !isFocusVisible(event.target)) return

    this.#expand(true)
  }

  onOut = (event) => {
    if (event.relatedTarget instanceof Node && this.element.contains(event.relatedTarget)) return

    const held = event.type === "mouseout" ? this.#focused : this.element.matches(":hover")
    if (!held) this.#expand(false)
  }

  // A focus that comes to a close button, or goes from it, shows the button to a screen reader, or
  // hides it again.
  onCloseFocus = (event) => {
    const close = event.target.closest?.("[data-phem-toast-close]")
    const el = close ? this.#toastOf(close) : null
    if (el) this.#renderClose(el, event.type === "focusin")
  }

  // The window that loses the focus stops the time, as the viewport of Base UI. The blur and the
  // focus of an element of the page do not count.
  onWindowBlur = (event) => {
    if (event.target instanceof Node) return

    this.windowFocused = false
    this.#pause()
  }

  onWindowFocus = (event) => {
    if (event.target instanceof Node) return

    this.windowFocused = true
    this.#resume()
  }

  // F6 moves the focus to the element while it has a toast, as the viewport of Base UI.
  onWindowKeydown = (event) => {
    if (event.key !== "F6" || event.target === this.element || this.#toasts().length === 0) return

    event.preventDefault()

    // F6 with the focus on a toast keeps the element that had the focus before the first F6.
    const active = document.activeElement
    if (!this.element.contains(active)) this.returnFocus = active instanceof HTMLElement ? active : null

    this.element.focus({ preventScroll: true })
    this.#expand(true)
  }

  // Escape closes the toast with the focus. Shift+Tab on the element, and Tab after the last
  // toast, give the focus back to the element that had it before F6.
  onKeydown = (event) => {
    if (event.defaultPrevented) return

    if (event.key === "Escape") {
      const el = this.#toastOf(document.activeElement)
      if (!el) return

      event.preventDefault()
      this.#remove(el, { keyboard: true })

      return
    }

    if (event.key !== "Tab" || !this.returnFocus?.isConnected) return

    const last = tabbables(this.element).at(-1) ?? this.element
    if (event.shiftKey ? event.target !== this.element : event.target !== last) return

    event.preventDefault()
    this.returnFocus.focus()
    this.returnFocus = null
  }

  // A swipe: the toast follows the pointer, in the directions of the `swipeDirection` value.
  onPointerDown = (event) => {
    if (!event.isPrimary || event.button !== 0 || this.swiping) return

    const el = this.#toastOf(event.target)
    const directions = this.swipeDirectionValue.split(/\s+/).filter(Boolean)
    if (!el || directions.length === 0 || el.hasAttribute("data-ending-style") || onControl(el, event.target)) return

    // The inline transition of the toast before the swipe: the toast follows the pointer with no
    // transition, and gets it again at the end.
    let before = null

    this.swiping = swipe(event, directions, {
      lockAxis: true,
      end: () => {
        this.swiping = null
        el.removeAttribute("data-swiping")

        if (before === null) return

        if (before === "") el.style.removeProperty("transition")
        else el.style.setProperty("transition", before)

        before = null
      },
      move: (movement, direction) => {
        if (before === null) {
          before = el.style.getPropertyValue("transition")
          el.style.setProperty("transition", "none")
        }

        el.setAttribute("data-swiping", "")
        el.style.setProperty("--toast-swipe-movement-x", `${movement.x}px`)
        el.style.setProperty("--toast-swipe-movement-y", `${movement.y}px`)
        this.#pause([el])
        if (!el.hasAttribute("data-swipe-direction") && directions.includes(direction)) el.setAttribute("data-swipe-direction", direction)
      },
      release: ({ direction, distance }) => {
        if (distance >= SWIPE) {
          el.setAttribute("data-swipe-direction", direction)
          this.#remove(el)

          return
        }

        this.#unswipe(el)
        this.#resume([el])
      },
      cancel: () => {
        this.#unswipe(el)
        this.#resume([el])
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

  // The message and the title are text, not HTML.
  #add(message, type, duration, title = "") {
    // A toast that goes away does not count: it is removed at the end of its animation.
    const same = this.#toasts().find((el) => !el.hasAttribute("data-ending-style") && (el.getAttribute("data-type") ?? "") === type && this.#message(el) === message && this.#title(el) === title)
    if (same) {
      same.dataset.phemToastDuration = String(duration)
      this.#schedule(same)

      return
    }

    const el = this.#make()
    el.id = `phem-toast-${++count}`
    el.setAttribute("data-phem-toast", "")
    if (type) el.setAttribute("data-type", type)
    el.dataset.phemToastDuration = String(duration)
    el.hidden = true
    this.#unswipe(el)

    // Each toast is a dialog that can have the focus, as in Base UI.
    if (!el.hasAttribute("role")) el.setAttribute("role", "dialog")
    if (!el.hasAttribute("tabindex")) el.tabIndex = 0
    el.setAttribute("aria-modal", "false")

    const description = el.querySelector("[data-phem-toast-description]") ?? el
    description.textContent = message
    if (description !== el) {
      description.id ||= `${el.id}-description`
      el.setAttribute("aria-describedby", description.id)
    }

    // Each part has the type of its toast, as the root.
    if (type) {
      for (const part of el.querySelectorAll("[data-phem-toast-title], [data-phem-toast-description], [data-phem-toast-close]")) part.setAttribute("data-type", type)
    }

    // The title is also on the root: a template with no title part does not show it.
    el.dataset.phemToastTitle = title

    const heading = el.querySelector("[data-phem-toast-title]")
    if (title === "") heading?.remove()
    else if (heading) {
      heading.textContent = title
      heading.id ||= `${el.id}-title`
      el.setAttribute("aria-labelledby", heading.id)
    }

    // The newest toast is first, as in Base UI. Another child of the element stays in its place.
    const first = this.#toasts()[0]
    if (first) first.before(el)
    else this.element.append(el)

    show(el)
    this.#stack()
    this.#renderExpanded(el)
    this.#schedule(el)
  }

  // A copy of the root of the template, or the default parts.
  #make() {
    const root = this.hasTemplateTarget ? this.templateTarget.content.firstElementChild : null
    if (root) return root.cloneNode(true)

    const el = document.createElement("div")

    const content = document.createElement("div")
    content.setAttribute("data-phem-toast-content", "")

    const title = document.createElement("strong")
    title.setAttribute("data-phem-toast-title", "")

    const text = document.createElement("span")
    text.setAttribute("data-phem-toast-description", "")
    content.append(title, text)

    const close = document.createElement("button")
    close.type = "button"
    close.setAttribute("data-phem-toast-close", "")
    close.setAttribute("aria-label", this.closeLabelValue)
    close.textContent = "×"

    el.append(content, close)

    return el
  }

  #message(el) {
    return (el.querySelector("[data-phem-toast-description]") ?? el).textContent
  }

  #title(el) {
    return el.dataset.phemToastTitle ?? ""
  }

  // The toast and its content part show the state of the stack.
  #renderExpanded(el) {
    el.toggleAttribute("data-expanded", this.expanded)
    el.querySelector("[data-phem-toast-content]")?.toggleAttribute("data-expanded", this.expanded)
    this.#renderClose(el)
  }

  // The close button is hidden from a screen reader while the stack is not expanded and the
  // button has no focus, as in Base UI.
  #renderClose(el, focused = false) {
    for (const close of el.querySelectorAll("[data-phem-toast-close]")) {
      if (!this.expanded && !focused && !close.contains(document.activeElement)) close.setAttribute("aria-hidden", "true")
      else close.removeAttribute("aria-hidden")
    }
  }

  // The position of each toast in the stack: 0 for the newest toast, and the heights of the newer
  // toasts. The content part of a toast behind the newest one gets `data-behind`.
  #stack() {
    const toasts = this.#toasts().filter((el) => !el.hasAttribute("data-ending-style"))
    let offset = 0

    toasts.forEach((el, i) => {
      const height = this.#heightOf(el)
      el.style.setProperty("--toast-index", String(i))
      el.style.setProperty("--toast-height", `${height}px`)
      el.style.setProperty("--toast-offset-y", `${offset}px`)
      el.toggleAttribute("data-limited", i >= this.limitValue)
      // A limited toast stays in the page, but it is not interactive.
      el.toggleAttribute("inert", i >= this.limitValue)
      el.querySelector("[data-phem-toast-content]")?.toggleAttribute("data-behind", i > 0)
      offset += height
    })

    // With no toast, the element has no height of a last toast: a CSS fallback applies, as in
    // Base UI (`height: var(--toast-frontmost-height, var(--toast-height))`).
    if (toasts.length > 0) this.element.style.setProperty("--toast-frontmost-height", `${this.#heightOf(toasts[0])}px`)
    else this.element.style.removeProperty("--toast-frontmost-height")
  }

  // The height of the content of a toast, as in Base UI: the CSS of the app can give a toast the
  // height of the last toast (`height: var(--toast-frontmost-height)`), which is not its own
  // height. The controller measures a toast one time, so the measure does not stop a transition
  // of the height.
  #heightOf(el) {
    if (heights.has(el)) return heights.get(el)

    const before = el.style.getPropertyValue("height")
    el.style.setProperty("height", "auto")
    const height = el.offsetHeight
    if (before === "") el.style.removeProperty("height")
    else el.style.setProperty("height", before)

    // A toast that is not on the screen has no height yet.
    if (height > 0) heights.set(el, height)

    return height
  }

  // The stack shows all its toasts, and their time stops. The time that remains goes on when it
  // closes.
  #expand(expanded) {
    if (expanded === this.expanded) return

    this.expanded = expanded
    this.element.toggleAttribute("data-expanded", expanded)

    for (const el of this.#toasts()) this.#renderExpanded(el)

    if (expanded) {
      this.#pause()
      this.#stack()
    } else {
      this.#resume()
    }
  }

  // A toast with no swipe has a movement of `0px`: a style can use the variables with no fallback.
  #unswipe(el) {
    el.style.setProperty("--toast-swipe-movement-x", "0px")
    el.style.setProperty("--toast-swipe-movement-y", "0px")
    el.removeAttribute("data-swipe-direction")
  }

  // The time runs while the stack is not expanded and the window has the focus.
  get #running() {
    return !this.expanded && this.windowFocused
  }

  // A new timer with the full time of the toast. It runs only while the time runs.
  #schedule(el) {
    this.timers.set(el, Number(el.dataset.phemToastDuration))
    if (this.#running && !el.hasAttribute("data-swiping")) this.timers.resume(el)
  }

  // Stops the time, and keeps the time that remains.
  #pause(toasts = this.#toasts()) {
    for (const el of toasts) this.timers.pause(el)
  }

  // Runs the time that remains, while the time runs. A toast with no timer (a toast of a cloned
  // page) gets the full time of its duration.
  #resume(toasts = this.#toasts()) {
    if (!this.#running) return

    for (const el of toasts) {
      if (el.hasAttribute("data-swiping") || el.hasAttribute("data-ending-style")) continue

      if (this.timers.has(el)) this.timers.resume(el)
      else this.#schedule(el)
    }
  }

  // `keyboard`: the keyboard closes the toast, so the focus moves also with no visible focus.
  #remove(el, { keyboard = false } = {}) {
    this.timers.delete(el)
    if (el.hasAttribute("data-ending-style")) return

    this.#moveFocus(el, keyboard)
    hide(el, {
      onEnd: () => {
        el.remove()
        this.#release()
      },
    })
    this.#stack()
  }

  // A toast with the focus of the keyboard that goes, or that the keyboard closes: the focus goes
  // to the next (older) toast, or else to the newer toast, or else to the element that had it
  // before F6. After a mouse click, the focus does not move, as in Base UI.
  #moveFocus(el, keyboard) {
    if (!el.contains(document.activeElement)) return
    if (!keyboard && !isFocusVisible(document.activeElement)) return

    const toasts = this.#toasts().filter((toast) => toast === el || (!toast.hasAttribute("data-ending-style") && !toast.inert))
    const at = toasts.indexOf(el)
    const to = toasts[at + 1] ?? toasts[at - 1] ?? (this.returnFocus?.isConnected ? this.returnFocus : null)
    if (to && !toasts.includes(to)) this.returnFocus = null
    to?.focus({ preventScroll: true })
  }

  // A toast that goes does not get a mouseout or a focusout: the stack closes when the pointer
  // and the focus are not on it, or when it has no toast.
  #release() {
    if (!this.expanded) return

    const held = this.element.matches(":hover") || this.#focused
    if (!held || this.#toasts().length === 0) this.#expand(false)
  }

  // The focus of the keyboard is in the element.
  get #focused() {
    return this.element.contains(document.activeElement) && isFocusVisible(document.activeElement)
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

