import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { Hover } from "./phem_hover.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, movesFocus, nextItem, Typeahead } from "./phem_roving.js"

// Each part with no id gets one, for `aria-controls` and `aria-labelledby`.
let count = 0

// The `phem-menu` controller: a list of actions in a popup, with the keyboard of a menu. The menu
// stays open after the morph of an event.
//
//     <div data-controller="phem-menu">
//         <button data-phem-menu-target="trigger">Actions</button>
//         <div data-phem-menu-target="popup" hidden>
//             <button data-phem-menu-target="item" onClick={{ rename }}>Rename</button>
//             <div data-phem-menu-target="separator"></div>
//             <button data-phem-menu-target="item" onClick={{ delete }}>Delete</button>
//         </div>
//     </div>
//
// A click on the trigger opens and closes the menu. ArrowDown on the trigger opens it on the first
// item, and ArrowUp on the last item. A click with the keyboard (Enter or Space) also opens it on
// the first item. In the menu, the arrow keys (of the `orientation` value) move the focus, and go
// from the last item to the first item with the `loopFocus` value (the default). Home and End go
// to the first and to the last item, and a letter goes to the next item that starts with it. The
// keys skip a disabled item (`disabled` or `aria-disabled="true"`). With the
// `highlightItemOnHover` value (the default), the pointer on an item gives it the focus.
//
// A click on an item runs its own handler (`onClick`), and closes the menu, unless the item has
// `data-phem-menu-close-on-click="false"`. Escape closes the menu and gives the focus back to the
// trigger. Tab, a click outside and the focus out of the menu close it.
//
// The trigger gets `aria-haspopup="menu"`, `aria-expanded`, `aria-controls` and `data-popup-open`.
// The popup gets `role="menu"`, the items `role="menuitem"` (an item can have the role
// `menuitemcheckbox` or `menuitemradio` with `aria-checked`: it gets `data-checked`), the groups
// `role="group"` with `aria-labelledby` (its `groupLabel` target), and the separators
// `role="separator"`. The item with the focus gets `data-highlighted`, and a disabled item gets
// `data-disabled`. The popup is positioned as a `phem-popover` (phem_position.js).
export default class PhemMenuController extends Controller {
  static targets = ["trigger", "popup", "arrow", "item", "group", "groupLabel", "separator"]
  static values = {
    open: Boolean,
    openOnHover: Boolean,
    delay: { type: Number, default: 100 },
    closeDelay: { type: Number, default: 0 },
    loopFocus: { type: Boolean, default: true },
    orientation: { type: String, default: "vertical" },
    highlightItemOnHover: { type: Boolean, default: true },
    ...positionValues("bottom", "start"),
  }

  initialize() {
    this.typeahead = new Typeahead()
    this.popup = new Popup({
      anchor: () => this.#trigger,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => positionOptions(this),
      inside: () => [this.element, ...this.popupTargets],
      dismiss: (reason) => this.#close({ focus: reason === "escape" }),
    })
    this.hover = new Hover({
      enabled: () => this.openOnHoverValue,
      triggers: () => this.triggerTargets,
      popups: () => this.popupTargets,
      isOpen: () => this.openValue,
      open: () => this.#open(null),
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
        this.#renderParts()
        this.popup.reposition()
      },
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusin", this.onFocusIn, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("pointerover", this.onPointerOver, options)
    this.element.addEventListener("pointerout", this.onPointerOut, options)

    this.rendered = this.openValue
    this.popup.render(this.openValue)
    this.#renderParts()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.hover.cancel()
    this.typeahead.reset()
    this.popup.destroy()
    this.rendered = undefined
  }

  toggle() {
    this.openValue ? this.close() : this.open()
  }

  open() {
    this.#open(null)
  }

  close() {
    this.#close({ focus: false })
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    this.#apply()
  }

  itemTargetConnected() {
    this.#renderParts()
  }

  groupTargetConnected() {
    this.#renderParts()
  }

  separatorTargetConnected() {
    this.#renderParts()
  }

  popupTargetConnected() {
    if (this.rendered === undefined) return

    this.popup.render(this.openValue)
    this.#renderParts()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const item = this.#itemOf(event.target)
    if (item) {
      if (isDisabled(item)) return
      if (item.getAttribute("data-phem-menu-close-on-click") !== "false") this.#close({ focus: true })

      return
    }

    if (!this.#triggerOf(event.target)) return

    // A click of the keyboard (Enter or Space) has no pointer: the focus goes to the first item.
    if (this.openValue) this.#close({ focus: false })
    else this.#open(event.detail === 0 ? "first" : null)
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return

    if (this.#triggerOf(event.target)) {
      const to = { ArrowDown: "first", ArrowUp: "last" }[event.key]
      if (!to) return

      event.preventDefault()
      this.#open(to)

      return
    }

    if (!this.popupTargets.some((el) => el.contains(event.target))) return

    if (event.key === "Tab") {
      this.#close({ focus: false })

      return
    }

    const item = this.#itemOf(event.target)

    // An item that is not a button or a link: Enter and Space click it.
    if (item && (event.key === "Enter" || event.key === " ") && !item.matches("button, a[href], input")) {
      event.preventDefault()
      item.click()

      return
    }

    const items = this.#items
    const next = movesFocus(event.key, this.orientationValue)
      ? nextItem(items, item, event.key, { orientation: this.orientationValue, loop: this.loopFocusValue, rtl: this.#rtl })
      : this.typeahead.find(items, item, event)
    if (!next) return

    event.preventDefault()
    next.focus()
  }

  onFocusIn = (event) => {
    const item = this.#itemOf(event.target)
    for (const el of this.itemTargets) el.toggleAttribute("data-highlighted", el === item)
  }

  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (!(to instanceof Node) || !this.#itemOf(to)) for (const el of this.itemTargets) el.removeAttribute("data-highlighted")

    if (!this.openValue || !(to instanceof Node)) return
    if (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to))) return

    this.#close({ focus: false })
  }

  onPointerOver = (event) => {
    if (event.pointerType === "touch") return

    const item = this.#itemOf(event.target)
    if (item && this.highlightItemOnHoverValue && !isDisabled(item) && document.activeElement !== item) item.focus({ preventScroll: true })

    this.hover.over(event)
  }

  onPointerOut = (event) => this.hover.out(event)

  // Opens the menu. `to` is the item that gets the focus: "first", "last", or none (the popup).
  #open(to) {
    this.hover.cancel()

    if (!this.openValue) {
      this.openValue = true
      this.#apply()
    }

    const items = this.#items
    const item = to === "first" ? nextItem(items, null, "Home") : to === "last" ? nextItem(items, null, "End") : null
    if (item) {
      item.focus()

      return
    }

    const popup = this.popupTargets[0]
    popup?.focus({ preventScroll: true })
  }

  #close({ focus }) {
    this.hover.cancel()
    if (!this.openValue) return

    const inside = this.popupTargets.some((el) => el.contains(document.activeElement))
    this.openValue = false
    this.#apply()
    this.typeahead.reset()
    if (focus || inside) this.#trigger?.focus()
  }

  #apply() {
    this.rendered = this.openValue
    this.#renderParts()

    if (this.openValue) this.popup.show()
    else this.popup.hide()
  }

  // The attributes that are not the state stay when the server render gives them.
  #renderParts() {
    const popup = this.popupTargets[0]

    if (popup) {
      if (!popup.hasAttribute("role")) popup.setAttribute("role", "menu")
      if (!popup.id) popup.id = this.popupId ??= `phem-menu-${++count}`
      if (!popup.hasAttribute("tabindex")) popup.tabIndex = -1
      if (this.orientationValue === "horizontal") popup.setAttribute("aria-orientation", "horizontal")
    }

    for (const trigger of this.triggerTargets) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "menu")
      if (popup && !trigger.hasAttribute("aria-controls")) trigger.setAttribute("aria-controls", popup.id)
      trigger.setAttribute("aria-expanded", String(this.openValue))
      trigger.toggleAttribute("data-popup-open", this.openValue)
    }

    for (const item of this.itemTargets) {
      if (!item.hasAttribute("role")) item.setAttribute("role", "menuitem")
      item.tabIndex = -1
      item.toggleAttribute("data-disabled", isDisabled(item))
      if (item.hasAttribute("aria-checked")) item.toggleAttribute("data-checked", item.getAttribute("aria-checked") === "true")
    }

    for (const group of this.groupTargets) {
      if (!group.hasAttribute("role")) group.setAttribute("role", "group")

      const label = this.groupLabelTargets.find((el) => group.contains(el))
      if (!label || group.hasAttribute("aria-labelledby")) continue

      if (!label.id) label.id = `phem-menu-label-${++count}`
      group.setAttribute("aria-labelledby", label.id)
    }

    for (const separator of this.separatorTargets) {
      if (!separator.hasAttribute("role")) separator.setAttribute("role", "separator")
    }
  }

  // The attributes of the state: the open state of the popup and its position, `aria-expanded`
  // and `data-popup-open` on a trigger, and `data-highlighted` on an item.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-popup-open"
    if (this.itemTargets.includes(el)) return name === "data-highlighted"

    return false
  }

  get #items() {
    return this.itemTargets
  }

  get #trigger() {
    return this.triggerTargets[0] ?? null
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }

  #triggerOf(el) {
    return this.triggerTargets.find((trigger) => trigger.contains(el))
  }

  #itemOf(el) {
    return this.itemTargets.find((item) => item.contains(el))
  }
}
