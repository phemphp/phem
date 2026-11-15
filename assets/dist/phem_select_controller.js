import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { modal } from "./phem_modal.js"
import { Popup } from "./phem_popup.js"
import { position, positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, movesFocus, nextItem, Typeahead } from "./phem_roving.js"
import { hide, render, show } from "./phem_transition.js"
import { link } from "./phem_aria.js"

// A release of the pointer on an item selects it after a drag from the trigger: a move of this
// distance (px), or a press of this time (ms).
const DRAG_DISTANCE = 4
const DRAG_TIME = 400

// The scroll (px) of each frame while the pointer is on a scroll arrow.
const SCROLL_STEP = 4

// The `phem-select` controller: a button that opens a list of options, as a native `<select>`.
// The value and the open popup stay after the morph of an event.
//
//     <div data-controller="phem-select" data-phem-select-value-value="fuji">
//         <button data-phem-select-target="trigger" aria-label="Apple">
//             <span data-phem-select-target="value" placeholder="Select apple"></span>
//             <span data-phem-select-target="icon">▾</span>
//         </button>
//         <input data-phem-select-target="input" name="apple" hidden />
//         <div data-phem-select-target="popup" popover="manual" hidden>
//             <div data-phem-select-target="scrollUpArrow">▴</div>
//             <div data-phem-select-target="list">
//                 <div data-phem-select-target="item" data-value="gala">
//                     <span data-phem-select-target="itemIndicator">✓</span>
//                     <span data-phem-select-target="itemText">Gala</span>
//                 </div>
//                 <div data-phem-select-target="item" data-value="fuji">…</div>
//             </div>
//             <div data-phem-select-target="scrollDownArrow">▾</div>
//         </div>
//     </div>
//
// The value of an item is its `data-value` attribute, its key (`data-phem-key`), or its index. The
// `value` value is the state: the value of the selected item, or with the `multiple` value a JSON
// array of the selected values (`["gala","fuji"]`). The server render gives its first value, and a
// server render with a new value sets it. The `value` target shows the label of the selected item
// (its `data-label`, the text of its `itemText` target, or its text), or with `multiple` the
// labels separated by a comma. With no value, it shows its `placeholder` attribute, and gets
// `data-placeholder`.
//
// A press on the trigger opens the popup. A release of the pointer on an item after a drag from
// the trigger selects the item. A click on an item selects it, and closes the popup; with
// `multiple`, a click adds or removes the value, and the popup stays open. On the trigger,
// ArrowDown, ArrowUp, Enter and Space open the popup, and a letter selects the next item that
// starts with it. The focus goes to the selected item, or to the first item (the last item with
// ArrowUp). In the popup, the arrow keys, Home and End move the focus (from the last item to the
// first item with the `loopFocus` value, the default), a letter goes to the next item that starts
// with it, and Enter and Space select the item. The keys skip a disabled item (`disabled` or
// `aria-disabled="true"`). With the `highlightItemOnHover` value (the default), a move of the
// pointer on an item gives it the focus: an item that comes under a pointer that does not move
// (the popup opens over the trigger) does not get it. Escape, Tab, a press outside and the focus
// out of the select close the popup, and Escape and Tab give the focus back to the trigger. The
// `disabled` and `readOnly` values stop the changes.
//
// With the `alignItemWithTrigger` value (the default), the popup goes over the trigger: the text
// of the selected item is on the text of the `value` target, and the popup gets
// `data-side="none"`. When the popup does not fit in the viewport there, or after a touch, the
// popup goes next to the trigger as a `phem-popover`, with the `side` (`bottom`), `align`
// (`center`), `sideOffset`, `alignOffset`, `collisionPadding` and `arrowPadding` values
// (phem_position.js). With the `modal` value (the default), the page does not scroll and the
// elements out of the popup get no pointer events while it is open (phem_modal.js).
//
// The scroll arrows scroll the list while the pointer is on them. Each one shows only when the
// list can scroll in its direction, with `data-visible`, unless it has `data-keep-mounted`. They
// get `data-direction` (`up` or `down`), `data-side` and `position: absolute`.
//
// The `input` target is the field of the form: an `<input>` gets the value (the values separated
// by a comma with `multiple`), and a `<select>` gets its options with the values selected. It
// gets `required` and `disabled` from the values of the controller. When the user changes the
// value, it sends `input` and `change` events, and the controller dispatches `phem-select:change`
// with the `value` in the detail (an array with `multiple`). A focus on the input goes to the
// trigger, for the message of a form that is not valid.
//
// The trigger gets `role="combobox"`, `aria-haspopup="listbox"`, `aria-expanded`,
// `aria-controls`, `data-popup-open` and `data-pressed` while the popup is open, `data-popup-side`,
// `data-placeholder`, `data-disabled`, `data-readonly` and `data-required`. The list (or the
// popup, with no list) gets `role="listbox"`, and `aria-multiselectable` with `multiple`. The
// items get `role="option"`, `aria-selected`, `data-selected`, `data-disabled`, and
// `data-highlighted` while they have the focus. An `itemIndicator` target shows only in a
// selected item (unless it has `data-keep-mounted`), with the transition attributes. The `icon`
// target gets `data-popup-open`. The groups get `role="group"` with the name of their
// `groupLabel`, and the separators `role="separator"`. The popup and the `backdrop` target get
// the open state and the animations of the conventions (phem_transition.js).
export default class PhemSelectController extends Controller {
  static targets = ["trigger", "value", "icon", "popup", "list", "item", "itemText", "itemIndicator", "group", "groupLabel", "separator", "scrollUpArrow", "scrollDownArrow", "arrow", "backdrop", "input"]
  static values = {
    value: String,
    open: Boolean,
    multiple: Boolean,
    alignItemWithTrigger: { type: Boolean, default: true },
    modal: { type: Boolean, default: true },
    disabled: Boolean,
    readOnly: Boolean,
    required: Boolean,
    loopFocus: { type: Boolean, default: true },
    highlightItemOnHover: { type: Boolean, default: true },
    ...positionValues("bottom", "center"),
  }

  initialize() {
    this.typeahead = new Typeahead()
    // The controller places the popup itself: with no anchor, the popup does not.
    this.popup = new Popup({
      anchor: () => null,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => ({}),
      inside: () => [this.element, ...this.popupTargets],
      dismiss: (reason) => this.#dismiss(reason),
    })
  }

  connect() {
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        this.#render()
        this.reposition?.()
      },
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("pointerdown", this.onPointerDown, options)
    this.element.addEventListener("pointerover", this.onPointerOver, options)
    this.element.addEventListener("pointermove", this.onPointerMove, options)
    this.element.addEventListener("pointerout", this.onPointerOut, options)
    this.element.addEventListener("focusin", this.onFocusIn, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("scroll", this.onScroll, { ...options, capture: true, passive: true })

    this.rendered = this.openValue
    this.popup.render(this.openValue)
    for (const el of this.backdropTargets) render(el, this.openValue)
    this.#renderModal()
    this.#render()
    if (this.openValue) this.#place()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.listening = null
    this.placing?.abort()
    this.#stopScroll()
    this.typeahead.reset()
    this.popup.destroy()
    this.unlock?.()
    this.unlock = null
    this.rendered = undefined
  }

  open() {
    this.#open()
  }

  close() {
    this.#close({ focus: false })
  }

  // A server render with a new value, or a change of the value by a script.
  valueValueChanged() {
    if (this.rendered === undefined || this.valueValue === this.renderedValue) return

    this.#render({ animate: true })
  }

  disabledValueChanged() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  readOnlyValueChanged() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  requiredValueChanged() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  // A change of the `open` value by a script. The open state belongs to the client.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    if (this.openValue) {
      this.openValue = false
      this.#open()
    } else {
      this.openValue = true
      this.#close({ focus: false })
    }
  }

  // A target that the morph adds gets the state.
  itemTargetConnected() {
    this.#later()
  }

  itemIndicatorTargetConnected() {
    this.#later()
  }

  valueTargetConnected() {
    this.#later()
  }

  inputTargetConnected() {
    this.#later()
  }

  popupTargetConnected() {
    if (this.rendered === undefined) return

    this.popup.render(this.openValue)
    this.#later()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const item = this.#itemOf(event.target)
    if (item) {
      this.#choose(item)

      return
    }

    const trigger = this.#triggerOf(event.target)
    if (!trigger || this.#disabled || this.pressing) return

    if (this.openValue) this.#close({ focus: true })
    else this.#open({ to: event.detail === 0 ? "first" : null, touch: this.pointerType === "touch" })
  }

  // A press on the trigger opens the popup, or closes it. The click after it does nothing.
  onPointerDown = (event) => {
    this.pointerType = event.pointerType
    const trigger = this.#triggerOf(event.target)
    if (!trigger || event.button !== 0 || event.pointerType === "touch" || this.#disabled) return

    event.preventDefault()
    this.pressing = true

    if (this.openValue) this.#close({ focus: true })
    else {
      this.#open()
      this.press = { x: event.clientX, y: event.clientY, time: Date.now() }
    }

    addEventListener("pointerup", this.onRelease, { capture: true, once: true })
  }

  // The release after the press on the trigger: on an item after a drag, it selects the item.
  onRelease = (event) => {
    setTimeout(() => (this.pressing = false), 0)

    const press = this.press
    this.press = null
    if (!press || !this.openValue) return

    const item = this.#itemOf(event.target)
    const moved = Math.hypot(event.clientX - press.x, event.clientY - press.y) > DRAG_DISTANCE
    if (item && (moved || Date.now() - press.time > DRAG_TIME)) this.#choose(item)
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || event.isComposing || event.ctrlKey || event.metaKey || event.altKey) return

    if (this.#triggerOf(event.target)) {
      this.#triggerKeydown(event)

      return
    }

    if (!this.popupTargets.some((el) => el.contains(event.target))) return

    if (event.key === "Tab") {
      this.#close({ focus: true })

      return
    }

    const item = this.#itemOf(event.target)
    const items = this.#items

    // Space in a typed word goes to the typeahead.
    const typed = event.key === " " ? this.typeahead.find(items, item, event, labelOf) : undefined
    if (event.key === "Enter" || (event.key === " " && !typed)) {
      event.preventDefault()
      if (item) this.#choose(item)

      return
    }

    const next = typed ?? (movesFocus(event.key, "vertical")
      ? nextItem(items, item, event.key, { loop: this.loopFocusValue })
      : this.typeahead.find(items, item, event, labelOf))
    if (movesFocus(event.key, "vertical")) event.preventDefault()
    if (!next) return

    event.preventDefault()
    this.#focusItem(next)
  }

  onPointerOver = (event) => {
    const arrow = this.#scrollArrowOf(event.target)
    if (arrow && event.pointerType !== "touch") this.#startScroll(this.scrollUpArrowTargets.includes(arrow) ? -1 : 1)
  }

  // A move of the pointer highlights the item under it. The browser also sends `pointerover` when
  // an item comes under a pointer that does not move (the popup opens over the trigger, or a key
  // scrolls the list): that item does not take the highlight of the keyboard.
  onPointerMove = (event) => {
    if (!this.openValue || !this.highlightItemOnHoverValue || event.pointerType === "touch") return

    // The moves on the item with the focus: no lookup of the items.
    const last = this.pointerItem
    if (last?.contains(event.target) && document.activeElement === last) return

    const item = this.#itemOf(event.target)
    this.pointerItem = item
    if (!item || isDisabled(item) || document.activeElement === item) return

    item.focus({ preventScroll: true })
  }

  onPointerOut = (event) => {
    const arrow = this.#scrollArrowOf(event.target)
    if (arrow && !(event.relatedTarget instanceof Node && arrow.contains(event.relatedTarget))) this.#stopScroll()
  }

  onFocusIn = (event) => {
    if (this.inputTargets.includes(event.target)) {
      this.#trigger?.focus()

      return
    }

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

  onScroll = (event) => {
    if (event.target === this.#scroller) this.#renderScrollArrows()
  }

  #triggerKeydown(event) {
    if (this.#disabled) return

    if (["ArrowDown", "ArrowUp", "Enter", " "].includes(event.key)) {
      event.preventDefault()
      if (!this.openValue) this.#open({ to: event.key === "ArrowUp" ? "last" : "first" })

      return
    }

    // A letter on a closed select selects the next item that starts with it.
    if (this.openValue || this.multipleValue) return

    const item = this.typeahead.find(this.#items, this.#selectedItems[0] ?? null, event, labelOf)
    if (!item) return

    event.preventDefault()
    this.#choose(item)
  }

  // Opens the popup. The focus goes to the selected item, or to `to` ("first" or "last"), or to
  // the popup.
  #open({ to = null, touch = false } = {}) {
    if (this.openValue || this.#disabled) return

    this.openValue = true
    this.aligned = this.alignItemWithTriggerValue && !touch
    this.#apply()

    const items = this.#items
    const item = this.#selectedItems.find((el) => !isDisabled(el) && !el.hidden) ?? (to === "first" ? nextItem(items, null, "Home") : to === "last" ? nextItem(items, null, "End") : null)
    if (item) this.#focusItem(item, { scroll: !this.aligned })
    else this.popupTargets[0]?.focus({ preventScroll: true })
  }

  #close({ focus }) {
    if (!this.openValue) return

    const inside = this.popupTargets.some((el) => el.contains(document.activeElement))
    this.openValue = false
    this.#apply()
    this.typeahead.reset()
    if (focus || inside) this.#trigger?.focus({ preventScroll: true })
  }

  // Escape and a press outside. The click after the press on the trigger is not a press outside.
  #dismiss(reason) {
    if (reason === "outside" && this.pressing) return false

    this.#close({ focus: reason === "escape" })
  }

  #apply() {
    this.rendered = this.openValue
    this.placing?.abort()
    this.placing = null
    this.reposition = null
    this.#stopScroll()

    if (this.openValue) {
      this.popup.show()
      for (const el of this.backdropTargets) show(el)
    } else {
      this.popup.hide()
      for (const el of this.backdropTargets) hide(el)
    }

    this.#renderModal()
    this.#render()
    if (this.openValue) this.#place()
  }

  // Selects an item: with `multiple`, adds or removes its value.
  #choose(item) {
    if (this.#disabled || isDisabled(item)) return

    const value = this.#valueOf(item)
    let values = [value]

    if (this.multipleValue) {
      values = this.#values.includes(value) ? this.#values.filter((v) => v !== value) : [...this.#values, value]
      this.valueValue = JSON.stringify(values)
    } else {
      this.valueValue = value
    }

    this.#render({ animate: true })

    for (const input of this.inputTargets) {
      input.dispatchEvent(new Event("input", { bubbles: true }))
      input.dispatchEvent(new Event("change", { bubbles: true }))
    }

    this.dispatch("change", { detail: { value: this.multipleValue ? values : value } })

    if (!this.multipleValue) this.#close({ focus: true })
  }

  #focusItem(item, { scroll = true } = {}) {
    item.focus({ preventScroll: true })
    if (scroll) scrollIntoList(this.#scroller, item)
  }

  // A modal select locks the page while it is open.
  #renderModal() {
    const popup = this.popupTargets[0]
    const locked = this.openValue && this.modalValue && popup !== undefined

    if (locked && !this.unlock) this.unlock = modal(popup)
    else if (!locked && this.unlock) {
      this.unlock()
      this.unlock = null
    }
  }

  // Places the open popup over the trigger, or next to it.
  #place() {
    const popup = this.popupTargets[0]
    const trigger = this.#trigger
    if (!popup || !trigger || !this.openValue) return

    this.placing?.abort()
    this.placing = new AbortController()
    const signal = this.placing.signal

    if (this.aligned && this.#placeAligned(popup, trigger)) {
      this.reposition = () => this.#follow(popup, trigger)
      follow(popup, this.reposition, signal)
    } else {
      this.aligned = false
      this.reposition = position(trigger, popup, () => ({ ...positionOptions(this), arrow: this.arrowTargets[0] ?? null }), signal)
    }

    this.#renderSide()
    this.#renderScrollArrows()
  }

  // The text of the selected item (or of the first item) goes on the text of the value part. The
  // list scrolls, so that the popup stays in the viewport. Returns false when the popup does not
  // fit.
  #placeAligned(popup, trigger) {
    const item = this.#selectedItems.find((el) => !el.hidden) ?? nextItem(this.#items, null, "Home")
    if (!item) return false

    const list = this.#scroller
    const pad = this.collisionPaddingValue
    const view = { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight }
    const style = popup.style
    const t = trigger.getBoundingClientRect()

    popup.setAttribute("data-side", "none")
    popup.setAttribute("data-align", "center")
    style.setProperty("position", "fixed")
    style.setProperty("top", "0px")
    style.setProperty("left", "0px")
    style.setProperty("right", "auto")
    style.setProperty("bottom", "auto")
    style.setProperty("--anchor-width", `${t.width}px`)
    style.setProperty("--anchor-height", `${t.height}px`)
    style.setProperty("--available-width", `${Math.max(0, view.width - 2 * pad)}px`)
    style.setProperty("--available-height", `${Math.max(0, view.height - 2 * pad)}px`)

    const anchor = this.valueTargets.find((el) => trigger.contains(el)) ?? trigger
    const text = this.itemTextTargets.find((el) => item.contains(el)) ?? item
    const v = anchor.getBoundingClientRect()
    const p = popup.getBoundingClientRect()
    if (p.height > view.height - 2 * pad + 1 || p.width > view.width - 2 * pad + 1) return false

    const topOf = () => {
      const r = text.getBoundingClientRect()

      return v.top + v.height / 2 - (r.top - p.top + r.height / 2)
    }

    let top = topOf()
    const below = list.scrollHeight - list.clientHeight - list.scrollTop
    if (top < pad) list.scrollTop += Math.min(pad - top, below)
    else if (top + p.height > view.height - pad) list.scrollTop -= Math.min(top + p.height - view.height + pad, list.scrollTop)

    top = topOf()
    if (top < pad - 1 || top + p.height > view.height - pad + 1) return false

    const r = text.getBoundingClientRect()
    const left = clamp(v.left - (r.left - p.left), pad, view.width - pad - p.width)
    style.setProperty("top", `${Math.round(top)}px`)
    style.setProperty("left", `${Math.round(left)}px`)
    style.setProperty("--transform-origin", `${Math.round(v.left + v.width / 2 - left)}px ${Math.round(r.top - p.top + r.height / 2)}px`)
    this.offset = { x: Math.round(left) - t.left, y: Math.round(top) - t.top }

    for (const arrow of this.arrowTargets) {
      arrow.setAttribute("data-side", "none")
      arrow.setAttribute("data-align", "center")
    }

    return true
  }

  // The popup over the trigger follows it.
  #follow(popup, trigger) {
    const t = trigger.getBoundingClientRect()
    popup.style.setProperty("top", `${Math.round(t.top + this.offset.y)}px`)
    popup.style.setProperty("left", `${Math.round(t.left + this.offset.x)}px`)
  }

  #later() {
    if (this.pending || this.rendered === undefined) return

    this.pending = true
    queueMicrotask(() => {
      this.pending = false
      if (this.listening) this.#render()
    })
  }

  // The state of the parts. The attributes that are not the state stay when the server render
  // gives them.
  #render({ animate = false } = {}) {
    const values = this.#values
    const selected = this.#selectedItems
    const open = this.openValue
    const list = this.#scroller
    const placeholder = values.length === 0
    this.renderedValue = this.valueValue

    if (list) {
      if (!list.hasAttribute("role")) list.setAttribute("role", "listbox")
      if (this.multipleValue) list.setAttribute("aria-multiselectable", "true")
      else list.removeAttribute("aria-multiselectable")
    }

    for (const popup of this.popupTargets) if (!popup.hasAttribute("tabindex")) popup.tabIndex = -1

    for (const trigger of this.triggerTargets) {
      if (!trigger.hasAttribute("role")) trigger.setAttribute("role", "combobox")
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "listbox")
      trigger.setAttribute("aria-expanded", String(open))
      if (list) link(trigger, "aria-controls", "ariaControlsElements", list)
      trigger.toggleAttribute("data-popup-open", open)
      trigger.toggleAttribute("data-pressed", open)
      trigger.toggleAttribute("data-placeholder", placeholder)
      trigger.toggleAttribute("data-disabled", this.disabledValue)
      trigger.toggleAttribute("data-readonly", this.readOnlyValue)
      trigger.toggleAttribute("data-required", this.requiredValue)
      const native = trigger.localName === "button"
      given(trigger, native ? "disabled" : "aria-disabled", this.disabledValue, native ? "" : "true")
      given(trigger, "aria-readonly", this.readOnlyValue, "true")
      given(trigger, "aria-required", this.requiredValue, "true")
    }

    const text = selected.map(labelOf).join(", ")
    for (const el of this.valueTargets) {
      const shown = placeholder ? (el.getAttribute("placeholder") ?? "") : text
      if (el.textContent !== shown) el.textContent = shown
      el.toggleAttribute("data-placeholder", placeholder)
    }

    for (const el of this.iconTargets) el.toggleAttribute("data-popup-open", open)

    for (const item of this.itemTargets) {
      const on = selected.includes(item)
      if (!item.hasAttribute("role")) item.setAttribute("role", "option")
      if (!item.hasAttribute("tabindex")) item.tabIndex = -1
      item.setAttribute("aria-selected", String(on))
      item.toggleAttribute("data-selected", on)
      item.toggleAttribute("data-disabled", isDisabled(item))
    }

    for (const indicator of this.itemIndicatorTargets) {
      const on = selected.some((item) => item.contains(indicator))
      const hidden = !indicator.hasAttribute("data-keep-mounted")
      if (!animate) render(indicator, on, { hidden })
      else if (on && !indicator.hasAttribute("data-open")) show(indicator, { hidden })
      else if (!on && indicator.hasAttribute("data-open")) hide(indicator, { hidden })
    }

    for (const group of this.groupTargets) {
      if (!group.hasAttribute("role")) group.setAttribute("role", "group")

      const label = this.groupLabelTargets.find((el) => group.contains(el))
      if (label) link(group, "aria-labelledby", "ariaLabelledByElements", label)
    }

    for (const separator of this.separatorTargets) {
      if (!separator.hasAttribute("role")) separator.setAttribute("role", "separator")
      if (!separator.hasAttribute("data-orientation")) separator.setAttribute("data-orientation", "horizontal")
    }

    this.#renderInputs(values)
    this.#renderSide()
    this.#renderScrollArrows()
  }

  #renderInputs(values) {
    for (const input of this.inputTargets) {
      if (input instanceof HTMLSelectElement) {
        for (const option of input.options) option.selected = values.includes(option.value)
      } else {
        const value = values.join(",")
        if (input.value !== value) input.value = value
        if (input.type !== "hidden" && !input.hasAttribute("tabindex")) input.tabIndex = -1
        if (input.type !== "hidden" && !input.hasAttribute("aria-hidden")) input.setAttribute("aria-hidden", "true")
      }

      given(input, "required", this.requiredValue, "")
      given(input, "disabled", this.disabledValue, "")
    }
  }

  // The side of the open popup on the trigger and on the scroll arrows.
  #renderSide() {
    const side = this.openValue ? this.popupTargets[0]?.getAttribute("data-side") : null

    for (const el of [...this.triggerTargets.map((t) => [t, "data-popup-side"]), ...this.#scrollArrows.map((a) => [a, "data-side"])]) {
      const [part, name] = el
      if (side) part.setAttribute(name, side)
      else part.removeAttribute(name)
    }
  }

  // A scroll arrow shows when the list can scroll in its direction.
  #renderScrollArrows() {
    const list = this.#scroller
    const max = list ? list.scrollHeight - list.clientHeight : 0
    const can = { up: this.openValue && list !== null && list.scrollTop > 0.5, down: this.openValue && list !== null && list.scrollTop < max - 0.5 }

    for (const [arrows, direction] of [[this.scrollUpArrowTargets, "up"], [this.scrollDownArrowTargets, "down"]]) {
      for (const arrow of arrows) {
        const visible = can[direction]
        arrow.setAttribute("data-direction", direction)
        arrow.toggleAttribute("data-visible", visible)
        if (arrow.style.position !== "absolute") arrow.style.position = "absolute"
        render(arrow, visible, { hidden: !arrow.hasAttribute("data-keep-mounted") })
      }

      if (!can[direction] && this.scrollDirection === (direction === "up" ? -1 : 1)) this.#stopScroll()
    }
  }

  #startScroll(direction) {
    if (this.scrollDirection === direction) return

    this.#stopScroll()
    this.scrollDirection = direction
    const step = () => {
      const list = this.#scroller
      if (!list || this.scrollDirection !== direction) return

      list.scrollTop += direction * SCROLL_STEP
      this.#renderScrollArrows()
      if (this.scrollDirection === direction) this.scrolling = requestAnimationFrame(step)
    }
    this.scrolling = requestAnimationFrame(step)
  }

  #stopScroll() {
    cancelAnimationFrame(this.scrolling)
    this.scrolling = 0
    this.scrollDirection = 0
  }

  // The attributes of the state.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (el === this.element) return name === `data-${this.identifier}-open-value`
    if (this.triggerTargets.includes(el)) return ["aria-expanded", "data-popup-open", "data-pressed", "data-popup-side", "data-placeholder"].includes(name)
    if (this.valueTargets.includes(el)) return name === "data-placeholder"
    if (this.iconTargets.includes(el)) return name === "data-popup-open"
    if (this.itemTargets.includes(el)) return ["aria-selected", "data-selected", "data-highlighted"].includes(name)
    if (this.itemIndicatorTargets.includes(el) || this.backdropTargets.includes(el)) return OPEN_ATTRIBUTES.has(name)
    if (this.#scrollArrows.includes(el)) return OPEN_ATTRIBUTES.has(name) || ["data-visible", "data-side", "style"].includes(name)
    if (this.inputTargets.includes(el)) return name === "value"

    return false
  }

  // The values of the state: a list of one value, or the JSON array of `multiple`.
  get #values() {
    const value = this.valueValue
    if (!this.multipleValue) return value === "" ? [] : [value]

    try {
      const values = JSON.parse(value || "[]")

      return Array.isArray(values) ? values.map(String) : [String(values)]
    } catch {
      return value === "" ? [] : [value]
    }
  }

  get #selectedItems() {
    const values = this.#values

    return this.itemTargets.filter((item) => values.includes(this.#valueOf(item)))
  }

  // The value of an item: its `data-value`, its key, or its index.
  #valueOf(item) {
    return item.getAttribute("data-value") ?? item.getAttribute("data-phem-key") ?? String(this.itemTargets.indexOf(item))
  }

  get #disabled() {
    return this.disabledValue || this.readOnlyValue
  }

  get #items() {
    return this.itemTargets.filter((el) => !el.hidden)
  }

  // The element that scrolls: the list, or the popup.
  get #scroller() {
    return this.listTargets[0] ?? this.popupTargets[0] ?? null
  }

  get #scrollArrows() {
    return [...this.scrollUpArrowTargets, ...this.scrollDownArrowTargets]
  }

  get #trigger() {
    return this.triggerTargets[0] ?? null
  }

  #triggerOf(el) {
    return this.triggerTargets.find((trigger) => trigger.contains(el))
  }

  #itemOf(el) {
    return el instanceof Node ? this.itemTargets.find((item) => item.contains(el)) : undefined
  }

  #scrollArrowOf(el) {
    return el instanceof Node ? this.#scrollArrows.find((arrow) => arrow.contains(el)) : undefined
  }
}

// The label of an item: its `data-label`, the text of its `itemText` part, or its text.
function labelOf(item) {
  const text = item.querySelector("[data-phem-select-target~='itemText']")

  return (item.getAttribute("data-label") ?? (text ?? item).textContent).trim()
}

// Calls `update` on a scroll of the page and a resize of the window, until `signal` aborts. A
// scroll in the popup does not move it.
function follow(popup, update, signal) {
  let frame = 0
  const later = (event) => {
    if (frame || (event?.target instanceof Node && popup.contains(event.target))) return

    frame = requestAnimationFrame(() => {
      frame = 0
      if (!signal.aborted) update()
    })
  }

  addEventListener("scroll", later, { capture: true, passive: true, signal })
  addEventListener("resize", later, { signal })
  signal.addEventListener("abort", () => cancelAnimationFrame(frame), { once: true })
}

// Scrolls the list so that the item is in view.
function scrollIntoList(list, item) {
  if (!list) return

  const box = list.getBoundingClientRect()
  const at = item.getBoundingClientRect()
  if (at.top < box.top) list.scrollTop -= box.top - at.top
  else if (at.bottom > box.bottom) list.scrollTop += at.bottom - box.bottom
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(value, Math.max(min, max)))
}

// The attributes that the controller gave: a value that goes back to false removes them, and an
// attribute of the markup stays.
const gave = new WeakMap()

function given(el, name, on, value) {
  const names = gave.get(el) ?? new Set()
  gave.set(el, names)

  if (on) {
    if (!el.hasAttribute(name)) names.add(name)
    el.setAttribute(name, value)
  } else if (names.delete(name)) {
    el.removeAttribute(name)
  }
}
