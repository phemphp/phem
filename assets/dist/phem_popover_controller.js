import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { Hover } from "./phem_hover.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"

// The `phem-popover` controller: a popup next to its trigger, for a share box, a filter form or
// a date picker. The popup stays open after the morph of an event.
//
//     <div data-controller="phem-popover">
//         <button data-phem-popover-target="trigger">Share</button>
//         <div data-phem-popover-target="popup" hidden>
//             <div data-phem-popover-target="arrow"></div>
//             <h2 data-phem-popover-target="title" id="share-title">Share</h2>
//             <button onClick={{ copyLink }}>Copy the link</button>
//             <button data-phem-popover-target="close">Close</button>
//         </div>
//     </div>
//
// A click on a trigger opens and closes the popup, and a click on a `close` target closes it.
// With the `openOnHover` value, the pointer on the trigger opens it after the `delay` value (300
// ms), and the pointer out of the trigger and of the popup closes it after the `closeDelay` value
// (at least 100 ms, so the pointer can go to the popup).
//
// The popup gets `role="dialog"`, `aria-labelledby` (the `title` target) and `aria-describedby`
// (the `description` target). A trigger gets `aria-haspopup="dialog"`, `aria-expanded`,
// `aria-controls` and `data-popup-open`. The open popup gets `data-open`, `data-side`,
// `data-align` and the CSS variables of phem_position.js; see phem_transition.js for the
// animations. Open moves the focus into the popup: to its `[autofocus]` element, or to the popup.
// Escape gives the focus back to the trigger.
//
// The `open` value is the state: the server render gives its first value, and a server render
// with a new `open` value sets it. When the `dismiss` value is true (the default), Escape, a press
// outside and the focus out of the popover close it.
export default class PhemPopoverController extends Controller {
  static targets = ["trigger", "popup", "arrow", "close", "title", "description"]
  static values = {
    open: Boolean,
    dismiss: { type: Boolean, default: true },
    openOnHover: Boolean,
    delay: { type: Number, default: 300 },
    closeDelay: { type: Number, default: 0 },
    ...positionValues("bottom", "center"),
  }

  initialize() {
    this.popup = new Popup({
      anchor: () => this.#trigger,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => positionOptions(this),
      inside: () => [this.element, ...this.popupTargets],
      dismiss: (reason) => {
        if (!this.dismissValue) return false

        this.#close({ focus: reason === "escape" })
      },
    })
    this.hover = new Hover({
      enabled: () => this.openOnHoverValue,
      triggers: () => this.triggerTargets,
      popups: () => this.popupTargets,
      isOpen: () => this.openValue,
      open: () => this.#open({ focus: false }),
      close: () => this.#close({ focus: false }),
      delay: () => this.delayValue,
      closeDelay: () => this.closeDelayValue,
    })
  }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        this.#renderAria()
        this.popup.reposition()
      },
    })
    this.state.connect()

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("focusout", this.onFocusOut)
    this.element.addEventListener("pointerover", this.onPointerOver)
    this.element.addEventListener("pointerout", this.onPointerOut)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("focusout", this.onFocusOut)
    this.element.removeEventListener("pointerover", this.onPointerOver)
    this.element.removeEventListener("pointerout", this.onPointerOut)
    this.hover.cancel()
    this.popup.destroy()
    this.rendered = undefined
  }

  toggle() {
    this.openValue ? this.close() : this.open()
  }

  open() {
    this.#open({ focus: true })
  }

  close() {
    this.#close({ focus: false })
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    this.#apply({ focus: false })
  }

  triggerTargetConnected() {
    this.#renderAria()
  }

  popupTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  titleTargetConnected() {
    this.#renderAria()
  }

  descriptionTargetConnected() {
    this.#renderAria()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    if (this.closeTargets.some((el) => el.contains(event.target))) this.#close({ focus: true })
    else if (this.#triggerOf(event.target)) this.toggle()
  }

  // The focus goes out of the trigger and of the popup, to another element of the page.
  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (!this.openValue || !this.dismissValue || !(to instanceof Node)) return
    if (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to))) return

    this.#close({ focus: false })
  }

  onPointerOver = (event) => this.hover.over(event)

  onPointerOut = (event) => this.hover.out(event)

  #open({ focus }) {
    this.hover.cancel()
    if (this.openValue) return

    this.openValue = true
    this.#apply({ focus })
  }

  #close({ focus }) {
    this.hover.cancel()
    if (!this.openValue) return

    // The focus goes back to the trigger, so it does not stay on a hidden element.
    const inside = this.popupTargets.some((el) => el.contains(document.activeElement))
    this.openValue = false
    this.#apply({ focus: false })
    if (focus || inside) this.#trigger?.focus()
  }

  // Shows the state with its animations.
  #apply({ focus }) {
    this.rendered = this.openValue
    this.#renderAria()

    if (!this.openValue) {
      this.popup.hide()

      return
    }

    this.popup.show()
    if (focus) this.#focusPopup()
  }

  // The state with no animation.
  #render() {
    this.rendered = this.openValue
    this.popup.render(this.openValue)
    this.#renderAria()
  }

  #focusPopup() {
    const popup = this.popupTargets[0]
    if (!popup) return

    const target = popup.querySelector("[autofocus]")
    if (target) {
      target.focus()

      return
    }

    if (!popup.hasAttribute("tabindex")) popup.tabIndex = -1
    popup.focus({ preventScroll: true })
  }

  // The attributes that are not the state stay when the server render gives them.
  #renderAria() {
    const popup = this.popupTargets[0]

    if (popup) {
      if (!popup.hasAttribute("role")) popup.setAttribute("role", "dialog")
      const title = this.titleTargets[0]
      const description = this.descriptionTargets[0]
      if (title?.id && !popup.hasAttribute("aria-labelledby")) popup.setAttribute("aria-labelledby", title.id)
      if (description?.id && !popup.hasAttribute("aria-describedby")) popup.setAttribute("aria-describedby", description.id)
    }

    for (const trigger of this.triggerTargets) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "dialog")
      if (popup?.id && !trigger.hasAttribute("aria-controls")) trigger.setAttribute("aria-controls", popup.id)
      trigger.setAttribute("aria-expanded", String(this.openValue))
      trigger.toggleAttribute("data-popup-open", this.openValue)
    }
  }

  // The attributes of the state: the open state of the popup and its position, `aria-expanded`
  // and `data-popup-open` on a trigger.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-popup-open"

    return false
  }

  get #trigger() {
    return this.triggerTargets[0] ?? null
  }

  #triggerOf(el) {
    return this.triggerTargets.find((trigger) => trigger.contains(el))
  }
}
