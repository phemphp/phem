import { Controller } from "@hotwired/stimulus"
import { linkWhile } from "./phem_aria.js"
import { ClientState } from "./phem_client_state.js"
import { Hover } from "./phem_hover.js"
import { Popup } from "./phem_popup.js"
import { PopupSize } from "./phem_popup_size.js"
import { PopupViewport } from "./phem_popup_viewport.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { Triggers, activationDirection } from "./phem_triggers.js"

// The time (ms) after the close of a tooltip in which another tooltip opens at once, as in a
// toolbar: the pointer moves from one button to the next.
const GROUP_TIMEOUT = 400

// The popups that got `role="tooltip"` from the controller, not from the server render.
const roles = new WeakSet()

// The time of the last close of a tooltip of the page.
let lastClose = -Infinity

// The popup of the last tooltip that closes with its animation.
let closing = null

// The `phem-tooltip` controller: a short text next to an element, on hover and on the focus of
// the keyboard.
//
//     <button data-controller="phem-tooltip" aria-label="Archive" onClick={{ archive }}>
//         <svg aria-hidden="true">…</svg>
//         <span data-phem-tooltip-target="popup" hidden>Archive</span>
//     </button>
//
// The trigger is a `trigger` target, a detached trigger (phem_triggers.js), or the controller
// element. The pointer on the trigger opens the popup after the `delay` value (600 ms), and the
// pointer out of the trigger closes it after the `closeDelay` value (0 ms). The pointer can go on
// the popup (with the `hoverable` value, the default): it can cross the gap between the trigger
// and the popup, as for a popover that opens on hover (phem_hover.js). A focus of the keyboard (`:focus-visible`)
// opens it at once, and the blur closes it. Escape and a press on the trigger close it
// (`closeOnClick`): the `pointerdown` of the pointer, or the `click` of Enter and Space. A touch
// shows no tooltip.
//
// One popup can have several triggers: `trigger` targets, and elements anywhere in the page with
// `data-phem-tooltip-for` and the `id` of the controller element. The popup goes next to the
// trigger that opened it: the active trigger. The pointer or the focus on another trigger while
// the popup is open makes it the active trigger at once, and the popup moves (a CSS transition of
// `top` and `left`). The `viewport` part shows the `data-phem-tooltip-payload` text of the active
// trigger, and animates the change (phem_popup_viewport.js). The popup gets the size of its
// content in `--popup-width` and `--popup-height` for a transition (phem_popup_size.js).
//
// The `open` value is the state, and the `triggerId` value is the `id` of the active trigger: a
// server render with new values opens the popup next to that trigger, or closes it. The `show`
// action opens it, with a `triggerId` param for another trigger than the first one.
//
// A tooltip that opens soon after another tooltip closed opens with no delay, and gets
// `data-instant="delay"`. The other tooltip, if its close animation runs, also gets
// `data-instant="delay"`, so a `[data-instant]` rule shows one tooltip at a time. A focus that
// moves from one trigger to the next (Tab) also ends the close of the other tooltip.
//
// The active trigger gets `data-popup-open`. A tooltip with a text that is not the accessible name
// of its trigger is a description: the popup gets `role="tooltip"`, and the trigger gets
// `aria-describedby`, also while the popup is closed (a hidden description is valid). A tooltip
// with the name of its trigger (an icon button with `aria-label`) gets nothing: a screen reader
// reads the name one time. With a `viewport` part, the text changes with the trigger: only the
// active trigger of the open popup gets `aria-describedby`. A `role` and an `aria-describedby` of
// the server render stay. A tooltip only helps the users of a pointer and of a keyboard: the
// trigger needs its own label (`aria-label`), for a touch screen and for a screen reader.
export default class PhemTooltipController extends Controller {
  static targets = ["trigger", "popup", "arrow", "viewport"]
  static values = {
    open: Boolean,
    triggerId: String,
    delay: { type: Number, default: 600 },
    closeDelay: { type: Number, default: 0 },
    closeOnClick: { type: Boolean, default: true },
    hoverable: { type: Boolean, default: true },
    ...positionValues("top", "center"),
  }

  initialize() {
    this.triggers = new Triggers(this, { self: true })
    this.popup = new Popup({
      anchor: () => this.triggers.active,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => positionOptions(this),
      inside: () => this.triggers.all,
      dismiss: () => this.#close({ instant: "dismiss" }),
      outside: () => false,
    })
    // The pointer can go from the trigger to a hoverable popup (phem_hover.js). The controller opens
    // the popup itself: the hover only follows the pointer to the popup, and closes the popup when
    // the pointer leaves the space between them.
    this.hover = new Hover({
      enabled: () => this.hoverableValue && this.popup.open,
      triggers: () => (this.triggers.active ? [this.triggers.active] : []),
      popups: () => this.popupTargets,
      isOpen: () => this.popup.open,
      open: () => {},
      close: () => this.#close({ instant: false }),
      delay: () => 0,
      closeDelay: () => this.closeDelayValue,
    })
    // Only a popup with a viewport changes its size between triggers.
    this.size = new PopupSize({ popup: () => (this.hasPopupTarget && this.hasViewportTarget ? this.popupTarget : null) })
    this.viewport = new PopupViewport({
      viewport: () => this.viewportTargets[0] ?? null,
      payload: (trigger) => trigger?.getAttribute("data-phem-tooltip-payload") ?? null,
    })
  }

  connect() {
    this.state = new ClientState(this, {
      value: ["open", "triggerId"],
      owns: (el, name) => this.#owns(el, name),
      outside: (el) => this.triggers.detached(el),
      morphed: () => {
        this.viewport.capture()
        if (this.rendered) this.viewport.render(this.triggers.active)
        this.#renderTriggers()
        this.popup.reposition()
      },
    })
    this.state.connect()

    // The events of the triggers and of the popup in the element, and of the detached triggers.
    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    for (const target of [this.element, document]) {
      const own = target === this.element
      const listen = (type, handler) => target.addEventListener(type, (event) => (own || (!this.element.contains(event.target) && this.triggers.detached(event.target))) && handler(event), options)
      listen("pointerover", this.onPointerOver)
      listen("pointerout", this.onPointerOut)
      listen("focusin", this.onFocusIn)
      listen("focusout", this.onFocusOut)
      listen("pointerdown", this.onPress)
      listen("click", this.onPress)
    }

    this.viewport.capture()
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    clearTimeout(this.timer)
    this.hover.cancel()
    if (this.popup.open) lastClose = performance.now()
    if (closing === this.popup) closing = null
    this.popup.destroy()
    this.rendered = undefined
  }

  popupTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  viewportTargetConnected() {
    this.viewport.capture()
  }

  // Opens the tooltip, next to the trigger of the `triggerId` param, or else the active trigger.
  show(event) {
    const trigger = this.triggers.byId(event?.params?.triggerId)
    this.#open({ instant: false, trigger })
  }

  hide() {
    this.#close({ instant: false })
  }

  // A server render with a new `open` or `triggerId` value, or a change by a script.
  openValueChanged() {
    this.#sync()
  }

  triggerIdValueChanged() {
    this.#sync()
  }

  onPointerOver = (event) => {
    if (event.pointerType === "touch" || !this.#over(event.target)) return

    clearTimeout(this.timer)
    this.hover.cancel()
    const trigger = this.triggers.of(event.target)
    if (this.popup.open) {
      if (trigger && !this.#inPopup(event.target)) this.#switch(trigger)

      return
    }

    // Another tooltip closed a short time ago: this one opens at once, and the close of the other
    // one ends at once, so the page shows one tooltip at a time.
    if (performance.now() - lastClose < GROUP_TIMEOUT) {
      this.#endOtherClose()
      this.#open({ instant: "delay", trigger })
    } else {
      this.timer = setTimeout(() => this.#open({ instant: false, trigger }), this.delayValue)
    }
  }

  onPointerOut = (event) => {
    if (event.pointerType === "touch" || !this.#over(event.target)) return

    const to = event.relatedTarget
    if (to instanceof Node && this.#over(to)) return

    clearTimeout(this.timer)
    if (!this.popup.open) return

    // The pointer that goes toward a hoverable popup can cross the gap to it (phem_hover.js). A
    // pointer that goes away closes it at once, so the next tooltip of a group opens at once.
    if (this.hoverableValue && to instanceof Node && this.hover.toward(event)) {
      this.hover.out(event)

      return
    }

    if (this.closeDelayValue > 0) this.timer = setTimeout(() => this.#close({ instant: false }), this.closeDelayValue)
    else this.#close({ instant: false })
  }

  onFocusIn = (event) => {
    const trigger = this.triggers.of(event.target)
    if (event.target !== trigger || !matchesFocusVisible(event.target)) return

    // The focus moves from another trigger (Tab in a toolbar): the close of the other tooltip
    // ends at once, as for the pointer.
    clearTimeout(this.timer)
    if (this.popup.open) {
      this.#switch(trigger)

      return
    }

    if (performance.now() - lastClose < GROUP_TIMEOUT) this.#endOtherClose()
    this.#open({ instant: "focus", trigger })
  }

  onFocusOut = (event) => {
    if (event.target !== this.triggers.of(event.target)) return

    // The focus goes to another trigger of the same popup: onFocusIn moves the popup.
    const to = event.relatedTarget
    if (to instanceof Node && this.triggers.of(to) === to) return

    this.#close({ instant: false })
  }

  // A press on the trigger closes the tooltip, as in Base UI: at `pointerdown` for the pointer, and
  // at `click` for Enter and Space.
  onPress = (event) => {
    if (this.closeOnClickValue && this.triggers.of(event.target) && !this.#inPopup(event.target)) this.#close({ instant: "dismiss" })
  }

  #open({ instant, trigger = null }) {
    clearTimeout(this.timer)
    if (this.popup.open) {
      if (trigger) this.#switch(trigger)

      return
    }

    if (!this.hasPopupTarget) return

    // A tooltip that opens again during its close animation is not the closing tooltip now.
    if (closing === this.popup) closing = null

    this.triggers.activate(trigger)
    this.rendered = true
    this.openValue = true
    this.triggerIdValue = this.triggers.active?.id ?? ""
    this.viewport.render(this.triggers.active)
    this.size.rest()
    this.popup.show({ instant })
    this.#renderTriggers()
  }

  #close({ instant }) {
    clearTimeout(this.timer)
    this.hover.cancel()
    if (!this.popup.open) return

    lastClose = performance.now()
    const popup = this.popup
    closing = popup
    popup.hide({ instant }).then(() => {
      if (closing === popup && !popup.open) closing = null
    })

    this.rendered = false
    this.openValue = false
    this.#renderTriggers()
  }

  // The open popup goes to another trigger: its position, its size and its content change with
  // their animations.
  #switch(trigger) {
    const from = this.triggers.active
    if (!trigger || trigger === from) return

    const size = this.size.measure()
    this.triggers.activate(trigger)
    this.triggerIdValue = trigger.id ?? ""
    this.popupTarget.removeAttribute("data-instant")
    this.viewport.change(from, trigger, activationDirection(from, trigger))
    this.size.change(size)
    this.popup.reanchor()
    this.#renderTriggers()
  }

  // The state of the `open` and `triggerId` values, after a server render or a script.
  #sync() {
    if (this.rendered === undefined) return

    const trigger = this.triggers.byId(this.triggerIdValue)
    if (this.openValue && !this.popup.open) this.#open({ instant: false, trigger })
    else if (!this.openValue && this.popup.open) this.#close({ instant: false })
    else if (this.openValue && trigger) this.#switch(trigger)
  }

  // The state with no animation: the first render, a popup that the morph adds.
  #render() {
    const open = this.openValue && this.hasPopupTarget
    if (open) this.triggers.activate(this.triggers.byId(this.triggerIdValue))
    this.rendered = open
    if (open) this.viewport.render(this.triggers.active)
    this.popup.render(open)
    this.#renderTriggers()
  }

  // The close animation of another tooltip ends at once, with `data-instant="delay"`.
  #endOtherClose() {
    if (closing && closing !== this.popup) closing.hide({ instant: "delay" })
    closing = null
  }

  // The pointer is on a trigger, or on the popup when it is hoverable.
  #over(el) {
    if (this.#inPopup(el)) return this.hoverableValue

    return this.triggers.of(el) !== null
  }

  #inPopup(el) {
    return this.popupTargets.some((popup) => popup.contains(el))
  }

  #renderTriggers() {
    const active = this.popup.open ? this.triggers.active : null
    for (const trigger of this.triggers.all) trigger.toggleAttribute("data-popup-open", trigger === active)
    this.#renderAria()
  }

  // A tooltip that is not the name of its trigger describes it (see the class comment).
  #renderAria() {
    const popup = this.hasPopupTarget ? this.popupTarget : null
    if (!popup) return

    let describes = false

    for (const trigger of this.triggers.all) {
      const hint = this.#hintOf(trigger, popup)
      const on = hint !== "" && hint !== normalize(accessibleName(trigger, popup))
      linkWhile(trigger, "aria-describedby", "ariaDescribedByElements", popup, on)
      describes ||= on
    }

    if (describes && !popup.hasAttribute("role")) {
      popup.setAttribute("role", "tooltip")
      roles.add(popup)
    } else if (!describes && roles.has(popup)) {
      popup.removeAttribute("role")
      roles.delete(popup)
    }
  }

  // The text of the popup for a trigger. With a viewport, only the active trigger of the open
  // popup has one: the content of a closed popup is the content of the last trigger.
  #hintOf(trigger, popup) {
    if (!this.hasViewportTarget) return normalize(popup.textContent)
    if (!this.popup.open || trigger !== this.triggers.active) return ""

    return normalize(trigger.getAttribute("data-phem-tooltip-payload") ?? this.viewportTargets[0].textContent)
  }

  // The attributes that belong to the client: the open state of the popup, `data-popup-open` of
  // the triggers, and the direction of the viewport.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (name === "data-popup-open") return this.triggers.all.includes(el)

    return name === "data-activation-direction" && this.viewportTargets.includes(el)
  }
}

// The accessible name of a trigger, as the guidance of tooltips: the text of the
// `aria-labelledby` elements, else `aria-label`, else the text of its content (with the `alt` of
// an image, not the popup), else `title`.
function accessibleName(el, popup) {
  const ids = el.getAttribute("aria-labelledby")?.trim()
  if (ids) return ids.split(/\s+/).map((id) => document.getElementById(id)?.textContent ?? "").join(" ")

  const label = el.getAttribute("aria-label")?.trim()
  if (label) return label

  const content = normalize(contentText(el, popup))

  return content || (el.getAttribute("title") ?? "")
}

function contentText(node, popup) {
  if (node === popup) return ""
  if (node.nodeType === Node.TEXT_NODE) return node.data
  if (!(node instanceof Element)) return ""
  if (node.getAttribute("aria-hidden") === "true") return ""
  if (node instanceof HTMLImageElement) return ` ${node.alt} `

  return [...node.childNodes].map((child) => contentText(child, popup)).join(" ")
}

// The text with one space between the words, and no case.
function normalize(text) {
  return String(text ?? "").replace(/\s+/g, " ").trim().toLowerCase()
}

// jsdom does not know `:focus-visible`.
function matchesFocusVisible(el) {
  try {
    return el.matches(":focus-visible")
  } catch {
    return true
  }
}
