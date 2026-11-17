import { Controller } from "@hotwired/stimulus"
import { link } from "./phem_aria.js"
import { ClientState } from "./phem_client_state.js"
import { firstTabbable } from "./phem_focus.js"
import { Hover } from "./phem_hover.js"
import { Popup } from "./phem_popup.js"
import { PopupSize } from "./phem_popup_size.js"
import { PopupViewport } from "./phem_popup_viewport.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isTouchClick } from "./phem_press.js"
import { Triggers, activationDirection } from "./phem_triggers.js"

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
// trigger when the focus is in the popup. A press outside gives the focus back to the trigger when
// the focus is on the body or in the popup, except in a popup that the hover opened. The focus goes
// back with `preventScroll`.
//
// One popup can have several triggers: `trigger` targets, and elements anywhere in the page with
// `data-phem-popover-for` and the `id` of the controller element (phem_triggers.js). The popup
// goes next to the trigger that opened it: the active trigger. A click on another trigger (or the
// pointer, with `openOnHover`) while the popup is open makes it the active trigger: the popup moves
// (a CSS transition of `top` and `left`), its `viewport` part shows the
// `data-phem-popover-payload` of the trigger (phem_popup_viewport.js), and it gets the size of its
// content in `--popup-width` and `--popup-height` (phem_popup_size.js).
//
// The `open` value is the state: the server render gives its first value, and a server render
// with a new `open` value sets it. The `triggerId` value is the `id` of the active trigger. The
// `open` action takes a `triggerId` param. When the `dismiss` value is true (the default), Escape, a press
// outside and the focus out of the popover close it.
//
// A preview card (Base UI PreviewCard) is a popover with `openOnHover` and a link trigger
// (`<a href>`), with a `delay` of 600 ms and a `closeDelay` of 300 ms in Base UI. A click on the
// link opens the link, not the popup. The keyboard focus on the link opens the popup after the
// delay, and the focus out closes it at once. A popup that the focus opened gets
// `data-instant="focus"`, also when the focus out closes it, so a `[data-instant]` rule can stop
// its animation. Escape closes a preview card
// with `data-instant="dismiss"`. The pointer removes the attribute. The link stays the only part
// for the keyboard: the trigger gets no `aria-haspopup`, `aria-expanded` and `aria-controls`, the
// popup gets no role, and its links and fields get `tabindex="-1"`. The popup stays in the
// accessibility tree, so a screen reader can read it in the reading order of the page.
export default class PhemPopoverController extends Controller {
  static targets = ["trigger", "popup", "arrow", "close", "title", "description", "viewport"]
  static values = {
    open: Boolean,
    triggerId: String,
    dismiss: { type: Boolean, default: true },
    openOnHover: Boolean,
    delay: { type: Number, default: 300 },
    closeDelay: { type: Number, default: 0 },
    ...positionValues("bottom", "center"),
  }

  initialize() {
    this.triggers = new Triggers(this)
    this.popup = new Popup({
      anchor: () => this.#trigger,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => positionOptions(this),
      inside: () => [this.element, ...this.popupTargets, ...this.triggers.all],
      dismiss: (reason) => {
        if (!this.dismissValue) return false

        // The focus goes back to the trigger when it is in the popup (`#close`), or on the body after a
        // press outside, as in Base UI. Escape on a preview card that a hover opened keeps the focus
        // where it is. A popup that the hover opened never had the focus, so a press outside keeps
        // the focus where it is too.
        const refocus = reason === "outside" && !this.#preview && !this.hoverOpened && isBody(document.activeElement)
        this.#close({ focus: refocus, instant: this.#preview && reason === "escape" ? "dismiss" : false })
      },
    })
    this.hover = new Hover({
      enabled: () => this.openOnHoverValue,
      triggers: () => this.triggers.all,
      popups: () => this.popupTargets,
      isOpen: () => this.openValue,
      open: () => this.#open({ focus: false, hover: true, trigger: this.hovered }),
      close: () => this.#close({ focus: false }),
      delay: () => this.delayValue,
      closeDelay: () => this.closeDelayValue,
    })
    // Only a popup with a viewport changes its size between triggers.
    this.size = new PopupSize({ popup: () => (this.hasPopupTarget && this.hasViewportTarget ? this.popupTarget : null) })
    this.viewport = new PopupViewport({
      viewport: () => this.viewportTargets[0] ?? null,
      payload: (trigger) => trigger?.getAttribute("data-phem-popover-payload") ?? null,
    })
  }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        this.viewport.capture()
        if (this.rendered) this.viewport.render(this.#trigger)
        this.#renderAria()
        this.popup.reposition()
      },
    })
    this.state.connect()

    // The events of the element, and of the detached triggers.
    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    for (const target of [this.element, document]) {
      const own = target === this.element
      const listen = (type, handler) => target.addEventListener(type, (event) => (own || (!this.element.contains(event.target) && this.triggers.detached(event.target))) && handler(event), options)
      listen("click", this.onClick)
      listen("focusout", this.onFocusOut)
      listen("focusin", this.onFocusIn)
      listen("pointerover", this.onPointerOver)
      listen("pointerout", this.onPointerOut)
    }

    this.viewport.capture()
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.hover.cancel()
    clearTimeout(this.focusTimer)
    this.popup.destroy()
    this.rendered = undefined
  }

  toggle() {
    this.openValue ? this.close() : this.open()
  }

  // Opens the popup, next to the trigger of the `triggerId` param, or else the active trigger.
  open(event) {
    this.#open({ focus: true, trigger: this.triggers.byId(event?.params?.triggerId) })
  }

  close() {
    this.#close({ focus: false })
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    if (this.openValue) this.#activate(this.triggers.byId(this.triggerIdValue))
    this.#apply({ focus: false })
  }

  // A server render with a new `triggerId` value: the open popup moves to that trigger.
  triggerIdValueChanged() {
    if (!this.rendered) return

    this.#switch(this.triggers.byId(this.triggerIdValue))
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

  // The old content of the viewport goes: the popup gets the title and the description of the new content.
  titleTargetDisconnected() {
    this.#renderAria()
  }

  descriptionTargetDisconnected() {
    this.#renderAria()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const trigger = this.#triggerOf(event.target)
    if (this.closeTargets.some((el) => el.contains(event.target))) this.#close({ focus: true })
    else if (trigger && !this.#preview) {
      if (this.openValue && trigger !== this.#trigger) this.#switch(trigger)
      else if (this.openValue) this.close()
      else this.#open({ focus: true, touch: isTouchClick(event), trigger })
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
    if (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to)) || this.#triggerOf(to)) return

    this.#close({ focus: false })
  }

  // With `openOnHover`, the pointer on another trigger moves the open popup to it at once.
  onPointerOver = (event) => {
    const trigger = event.pointerType === "touch" ? null : this.#triggerOf(event.target)
    if (trigger) this.hovered = trigger
    if (this.openOnHoverValue && this.openValue && trigger && trigger !== this.#trigger) this.#switch(trigger)
    this.hover.over(event)
  }

  onPointerOut = (event) => this.hover.out(event)

  #open({ focus, touch = false, instant = false, hover = false, trigger = null }) {
    this.hover.cancel()
    if (this.openValue) {
      this.#switch(trigger)

      return
    }

    this.#activate(trigger)
    this.focusOpened = instant === "focus"
    this.hoverOpened = hover
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
    if (focus || inside) this.#trigger?.focus({ preventScroll: true })
  }

  // Makes `trigger` the active trigger of a popup that opens.
  #activate(trigger) {
    this.triggers.activate(trigger)
    const id = this.#trigger?.id ?? ""
    if (this.triggerIdValue !== id) this.triggerIdValue = id
  }

  // The open popup goes to another trigger: its position, its size and its content change with
  // their animations.
  #switch(trigger) {
    const from = this.#trigger
    if (!trigger || !this.openValue || trigger === from) return

    const size = this.size.measure()
    this.#activate(trigger)
    this.popupTarget.removeAttribute("data-instant")
    this.viewport.change(from, trigger, activationDirection(from, trigger))
    this.size.change(size)
    this.popup.reanchor()
    this.#renderAria()
  }

  // Shows the state with its animations.
  #apply({ focus, touch = false, instant = false }) {
    this.rendered = this.openValue
    if (this.openValue) {
      this.viewport.render(this.#trigger)
      this.size.rest()
    }
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
    if (this.openValue) this.triggers.activate(this.triggers.byId(this.triggerIdValue))
    this.rendered = this.openValue
    if (this.openValue) this.viewport.render(this.#trigger)
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

    // The active trigger is the trigger of the open popup.
    const active = this.openValue ? this.#trigger : null
    for (const trigger of this.triggers.all) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "dialog")
      if (popup) link(trigger, "aria-controls", "ariaControlsElements", popup)
      trigger.setAttribute("aria-expanded", String(trigger === active))
      trigger.toggleAttribute("data-popup-open", trigger === active)
      trigger.toggleAttribute("data-pressed", trigger === active)
    }
  }

  // A preview card: the link is the only part for the keyboard: Tab skips the content of the card.
  #renderPreview(popup) {
    const active = this.openValue ? this.#trigger : null
    for (const trigger of this.triggers.all) trigger.toggleAttribute("data-popup-open", trigger === active)
    if (!popup) return

    for (const el of popup.querySelectorAll(FOCUSABLE)) el.tabIndex = -1
  }

  // A popover that opens on hover, with a link trigger.
  get #preview() {
    const triggers = this.triggers.all

    return this.openOnHoverValue && triggers.length > 0 && triggers.every((el) => el.matches("a[href]"))
  }

  // The attributes of the state: the open state of the popup and its position, `aria-expanded`
  // and `data-popup-open` on a trigger, and the parts of a preview card.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (this.triggers.all.includes(el)) return name === "aria-expanded" || name === "data-popup-open" || name === "data-pressed"
    if (name === "data-activation-direction" && this.viewportTargets.includes(el)) return true
    if (this.#preview && this.popupTargets.some((popup) => popup.contains(el))) return name === "tabindex"

    return false
  }

  get #trigger() {
    return this.triggers.active
  }

  #triggerOf(el) {
    return this.triggers.of(el) ?? undefined
  }
}

// No element has the focus.
function isBody(el) {
  return el === null || el === document.body
}
