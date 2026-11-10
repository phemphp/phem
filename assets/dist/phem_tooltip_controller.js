import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"

// The time (ms) after the close of a tooltip in which another tooltip opens at once, as in a
// toolbar: the pointer moves from one button to the next.
const GROUP_TIMEOUT = 400

// The time of the last close of a tooltip of the page.
let lastClose = -Infinity

// Each tooltip with no id gets one, for `aria-describedby`.
let count = 0

// The `phem-tooltip` controller: a short text next to an element, on hover and on the focus of
// the keyboard.
//
//     <button data-controller="phem-tooltip" aria-label="Archive" onClick={{ archive }}>
//         <svg aria-hidden="true">…</svg>
//         <span data-phem-tooltip-target="popup" hidden>Archive</span>
//     </button>
//
// The trigger is the `trigger` target, or the controller element. The pointer on the trigger
// opens the popup after the `delay` value (600 ms), and the pointer out of the trigger closes it
// after the `closeDelay` value (0 ms). The pointer can go on the popup (with the `hoverable`
// value, the default). A focus of the keyboard (`:focus-visible`) opens it at once, and the blur
// closes it. Escape and a click on the trigger close it (`closeOnClick`). A touch shows no tooltip.
//
// A tooltip that opens soon after another tooltip closed opens at once, with `data-instant="delay"`.
//
// The popup gets `role="tooltip"` and an `id`, and the trigger gets `aria-describedby` and
// `data-popup-open`. A tooltip only helps the users of a pointer and of a keyboard: the trigger
// still needs its own label, for a touch screen and for a screen reader.
export default class PhemTooltipController extends Controller {
  static targets = ["trigger", "popup", "arrow"]
  static values = {
    delay: { type: Number, default: 600 },
    closeDelay: { type: Number, default: 0 },
    closeOnClick: { type: Boolean, default: true },
    hoverable: { type: Boolean, default: true },
    ...positionValues("top", "center"),
  }

  initialize() {
    this.popup = new Popup({
      anchor: () => this.#trigger,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => positionOptions(this),
      inside: () => [this.#trigger],
      dismiss: () => this.#close({ instant: "dismiss" }),
      outside: () => false,
    })
  }

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.popup.owns(el, name) || (el === this.#trigger && name === "data-popup-open"),
      morphed: () => {
        this.#renderAria()
        this.popup.reposition()
      },
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("pointerover", this.onPointerOver, options)
    this.element.addEventListener("pointerout", this.onPointerOut, options)
    this.element.addEventListener("focusin", this.onFocusIn, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("click", this.onClick, options)

    this.popup.render(false)
    this.#renderAria()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    clearTimeout(this.timer)
    if (this.popup.open) lastClose = performance.now()
    this.popup.destroy()
  }

  popupTargetConnected() {
    this.#renderAria()
  }

  show() {
    this.#open({ instant: false })
  }

  hide() {
    this.#close({ instant: false })
  }

  onPointerOver = (event) => {
    if (event.pointerType === "touch" || !this.#over(event.target)) return

    clearTimeout(this.timer)
    if (this.popup.open) return

    // Another tooltip closed a short time ago: this one opens at once.
    if (performance.now() - lastClose < GROUP_TIMEOUT) this.#open({ instant: "delay" })
    else this.timer = setTimeout(() => this.#open({ instant: false }), this.delayValue)
  }

  onPointerOut = (event) => {
    if (event.pointerType === "touch") return

    const to = event.relatedTarget
    if (to instanceof Node && this.#over(to)) return

    clearTimeout(this.timer)
    if (!this.popup.open) return

    if (this.closeDelayValue > 0) this.timer = setTimeout(() => this.#close({ instant: false }), this.closeDelayValue)
    else this.#close({ instant: false })
  }

  onFocusIn = (event) => {
    if (event.target !== this.#trigger || !matchesFocusVisible(event.target)) return

    clearTimeout(this.timer)
    this.#open({ instant: "focus" })
  }

  onFocusOut = (event) => {
    if (event.target === this.#trigger) this.#close({ instant: false })
  }

  onClick = (event) => {
    if (this.closeOnClickValue && this.#trigger?.contains(event.target) && !this.popupTargets.some((el) => el.contains(event.target))) this.#close({ instant: "dismiss" })
  }

  #open({ instant }) {
    clearTimeout(this.timer)
    if (this.popup.open || !this.hasPopupTarget) return

    this.#renderAria()
    this.popup.show({ instant })
    this.#trigger?.setAttribute("data-popup-open", "")
  }

  #close({ instant }) {
    clearTimeout(this.timer)
    if (!this.popup.open) return

    lastClose = performance.now()
    this.popup.hide({ instant })
    this.#trigger?.removeAttribute("data-popup-open")
  }

  // The pointer is on the trigger, or on the popup when it is hoverable.
  #over(el) {
    if (this.popupTargets.some((popup) => popup.contains(el))) return this.hoverableValue

    return this.#trigger?.contains(el) ?? false
  }

  #renderAria() {
    const popup = this.popupTargets[0]
    const trigger = this.#trigger
    if (!popup || !trigger) return

    if (!popup.hasAttribute("role")) popup.setAttribute("role", "tooltip")
    // The same id after the morph, which removes an id that the server render does not have.
    if (!popup.id) popup.id = this.generatedId ??= `phem-tooltip-${++count}`

    const described = (trigger.getAttribute("aria-describedby") ?? "").split(/\s+/).filter(Boolean)
    if (!described.includes(popup.id)) trigger.setAttribute("aria-describedby", [...described, popup.id].join(" "))
    trigger.toggleAttribute("data-popup-open", this.popup.open)
  }

  get #trigger() {
    return this.triggerTargets[0] ?? this.element
  }
}

// jsdom does not know `:focus-visible`.
function matchesFocusVisible(el) {
  try {
    return el.matches(":focus-visible")
  } catch {
    return true
  }
}
