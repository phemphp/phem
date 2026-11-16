import { Controller } from "@hotwired/stimulus"
import { link } from "./phem_aria.js"
import { ClientState } from "./phem_client_state.js"
import { firstTabbable } from "./phem_focus.js"
import { Hover } from "./phem_hover.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isTouchClick } from "./phem_press.js"

// The links and the fields of the popup of a preview card: they get `tabindex="-1"`.
const FOCUSABLE = "a[href], button, input, select, textarea, [tabindex]"

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
// (0 ms by default: at once). The popup stays open while the pointer is in the space between the
// trigger and the popup (phem_hover.js).
//
// The popup gets `role="dialog"`, `aria-labelledby` (the `title` target, unless the popup has an
// `aria-label`) and `aria-describedby` (the `description` target). A trigger gets
// `aria-haspopup="dialog"`, `aria-expanded`, `aria-controls`, `data-popup-open` and `data-pressed`. A part with
// no id gets no id: the link is an element reference (`ariaLabelledByElements`,
// `ariaDescribedByElements`, `ariaControlsElements`). The open popup gets `data-open`, `data-side`,
// `data-align` and the CSS variables of phem_position.js; see phem_transition.js for the
// animations. Open moves the focus into the popup: to its `[autofocus]` element, or else to the
// first element that Tab goes to, or else to the popup. After an open with a touch, the focus goes
// to the popup, so the keyboard of the device does not show. Escape gives the focus back to the
// trigger when the focus is in the popup.
//
// The `open` value is the state: the server render gives its first value, and a server render
// with a new `open` value sets it. When the `dismiss` value is true (the default), Escape, a press
// outside and the focus out of the popover close it.
//
// A preview card (Base UI PreviewCard) is a popover with `openOnHover` and a link trigger
// (`<a href>`), with a `delay` of 600 ms and a `closeDelay` of 300 ms in Base UI. A click on the
// link opens the link, not the popup. The keyboard focus on the link opens the popup after the
// delay, and the focus out closes it at once. A popup that the focus opened gets
// `data-instant="focus"`, also when the focus out closes it, so a `[data-instant]` rule can stop
// its animation. Escape closes a preview card
// with `data-instant="dismiss"`. The pointer removes the attribute. The link stays the only part for the keyboard and a screen
// reader: the trigger gets no `aria-haspopup`, `aria-expanded` and `aria-controls`, the popup gets
// `aria-hidden="true"` and no role, and its links and fields get `tabindex="-1"`.
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

        // The focus goes back to the trigger only when it is in the popup (`#close`), as in Base UI:
        // Escape on a preview card that a hover opened keeps the focus where it is.
        this.#close({ focus: false, instant: this.#preview && reason === "escape" ? "dismiss" : false })
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
    this.element.addEventListener("focusin", this.onFocusIn)
    this.element.addEventListener("pointerover", this.onPointerOver)
    this.element.addEventListener("pointerout", this.onPointerOut)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("focusout", this.onFocusOut)
    this.element.removeEventListener("focusin", this.onFocusIn)
    this.element.removeEventListener("pointerover", this.onPointerOver)
    this.element.removeEventListener("pointerout", this.onPointerOut)
    this.hover.cancel()
    clearTimeout(this.focusTimer)
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
    else if (this.#triggerOf(event.target) && !this.#preview) {
      if (this.openValue) this.close()
      else this.#open({ focus: true, touch: isTouchClick(event) })
    }
  }

  // A preview card: the keyboard focus on the link opens the popup after the delay.
  onFocusIn = (event) => {
    if (!this.#preview || !this.#triggerOf(event.target) || !event.target.matches?.(":focus-visible")) return

    this.hover.cancel()
    clearTimeout(this.focusTimer)
    this.focusTimer = setTimeout(() => this.#open({ focus: false, instant: "focus" }), this.delayValue)
  }

  // The focus goes out of the trigger and of the popup, to another element of the page.
  onFocusOut = (event) => {
    if (this.#preview && this.#triggerOf(event.target)) {
      clearTimeout(this.focusTimer)
      // The focus out closes the card at once, as in Base UI: the `closeDelay` value is for the pointer.
      if (this.openValue && !this.popupTargets.some((el) => el.contains(event.relatedTarget))) {
        this.focusTimer = setTimeout(() => this.#close({ focus: false, instant: this.focusOpened ? "focus" : false }), 0)
      }

      return
    }

    const to = event.relatedTarget
    if (!this.openValue || !this.dismissValue || !(to instanceof Node)) return
    if (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to))) return

    this.#close({ focus: false })
  }

  onPointerOver = (event) => this.hover.over(event)

  onPointerOut = (event) => this.hover.out(event)

  #open({ focus, touch = false, instant = false }) {
    this.hover.cancel()
    if (this.openValue) return

    this.focusOpened = instant === "focus"
    this.openValue = true
    this.#apply({ focus, touch, instant })
  }

  #close({ focus, instant = false }) {
    this.hover.cancel()
    if (!this.openValue) return

    // The focus goes back to the trigger, so it does not stay on a hidden element.
    const inside = this.popupTargets.some((el) => el.contains(document.activeElement))
    this.openValue = false
    this.#apply({ focus: false, instant })
    if (focus || inside) this.#trigger?.focus()
  }

  // Shows the state with its animations.
  #apply({ focus, touch = false, instant = false }) {
    this.rendered = this.openValue
    this.#renderAria()

    if (!this.openValue) {
      this.popup.hide({ instant })

      return
    }

    this.popup.show({ instant })
    if (focus) this.#focusPopup(touch)
  }

  // The state with no animation.
  #render() {
    this.rendered = this.openValue
    this.popup.render(this.openValue)
    this.#renderAria()
  }

  // The focus of a popup that opens: its `[autofocus]` element, or else its first element that
  // Tab goes to, or else the popup. A touch gives the focus to the popup: a field with the focus
  // shows the keyboard of the device.
  #focusPopup(touch) {
    const popup = this.popupTargets[0]
    if (!popup) return

    const target = touch ? null : (popup.querySelector("[autofocus]") ?? firstTabbable(popup))
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

    if (this.#preview) {
      this.#renderPreview(popup)

      return
    }

    if (popup) {
      if (!popup.hasAttribute("role")) popup.setAttribute("role", "dialog")
      const title = this.titleTargets[0]
      const description = this.descriptionTargets[0]
      // An `aria-label` of the markup names the popup: `aria-labelledby` would replace it.
      if (title && !popup.hasAttribute("aria-label")) link(popup, "aria-labelledby", "ariaLabelledByElements", title)
      if (description) link(popup, "aria-describedby", "ariaDescribedByElements", description)
    }

    for (const trigger of this.triggerTargets) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "dialog")
      if (popup) link(trigger, "aria-controls", "ariaControlsElements", popup)
      trigger.setAttribute("aria-expanded", String(this.openValue))
      trigger.toggleAttribute("data-popup-open", this.openValue)
      trigger.toggleAttribute("data-pressed", this.openValue)
    }
  }

  // A preview card: the link is the only part for the keyboard and a screen reader.
  #renderPreview(popup) {
    for (const trigger of this.triggerTargets) trigger.toggleAttribute("data-popup-open", this.openValue)
    if (!popup) return

    popup.setAttribute("aria-hidden", "true")
    for (const el of popup.querySelectorAll(FOCUSABLE)) el.tabIndex = -1
  }

  // A popover that opens on hover, with a link trigger.
  get #preview() {
    return this.openOnHoverValue && this.triggerTargets.length > 0 && this.triggerTargets.every((el) => el.matches("a[href]"))
  }

  // The attributes of the state: the open state of the popup and its position, `aria-expanded`
  // and `data-popup-open` on a trigger, and the parts of a preview card.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-popup-open" || name === "data-pressed"
    if (this.#preview && this.popupTargets.some((popup) => popup.contains(el))) return name === "tabindex" || (name === "aria-hidden" && this.popupTargets.includes(el))

    return false
  }

  get #trigger() {
    return this.triggerTargets[0] ?? null
  }

  #triggerOf(el) {
    return this.triggerTargets.find((trigger) => trigger.contains(el))
  }
}
