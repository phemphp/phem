import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { modal } from "./phem_modal.js"
import { Popup } from "./phem_popup.js"
import { position, positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, leaveList, movesFocus, nextItem, Typeahead } from "./phem_roving.js"
import { hide, render, show } from "./phem_transition.js"
import { link } from "./phem_aria.js"
import { isComposing } from "./phem_keys.js"

// A release of the pointer on an item selects it after a drag from the trigger: a move of this
// distance (px), or a press of this time (ms).
const DRAG_DISTANCE = 4
const DRAG_TIME = 400

// The time (ms) between two steps of a scroll arrow. Each step scrolls one item (Base UI).
const SCROLL_DELAY = 40

// The popup over the trigger stays MARGIN (px) from the top and the bottom of the viewport, and
// PADDING from the left and the right. It goes next to the trigger when the trigger is nearer than
// EDGE to the top of the viewport, or nearer than 2 * MARGIN + EDGE to the bottom, or when its
// height is less than MIN_HEIGHT (or the full height of a shorter list). Base UI.
const MARGIN = 10
const PADDING = 5
const EDGE = 20
const MIN_HEIGHT = 100

// A tolerance (px) of the scroll edges (Base UI).
const TOLERANCE = 1

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
// `data-placeholder`. With two values or more, a `data-phem-select-template` attribute on the
// `value` target gives the text: `{first}` (the first label), `{others}` (the number of the other
// values), `{count}` and `{labels}`, for example `{first} (+{others} more)`. With a
// `data-phem-select-markup` attribute, the `value` target shows a copy of the markup of the
// `itemText` of the selected item, for an item with more than a text.
//
// A press on the trigger opens the popup. A release of the pointer on an item after a drag from the
// trigger selects the item. A click on an item selects it, and closes the popup; with `multiple`, a
// click adds or removes the value, and the popup stays open. On the trigger, ArrowDown, ArrowUp,
// Enter and Space open the popup, and a letter selects the next item that starts with it. The focus
// goes to the selected item, or to the first item (the last item with ArrowUp). In the popup, the
// arrow keys, Home and End move the focus (from the last item to the first item with the
// `loopFocus` value, the default), a letter goes to the next item that starts with it, and Enter
// and Space select the item. The typed text stays for 750 ms, and a new text starts after the
// selected item. The keys skip a disabled item (`disabled` or `aria-disabled="true"`). With the
// `highlightItemOnHover` value (the default), a press of the mouse with no value gives the focus to
// the first item, and a move of the pointer on an item gives it the focus: an item that comes under
// a pointer that does not move (the popup opens over the trigger) does not get it. When the pointer
// leaves the items, the highlight goes away and the popup gets the focus. A key that moves the
// focus keeps the highlight of the keyboard, also when the list scrolls under a pointer that does
// not move. Escape, Tab, a press outside and the focus out of the select close the popup. Escape,
// Tab and a press outside (at the press) give the focus back to the trigger. The `disabled` and
// `readOnly` values stop the changes.
//
// With the `alignItemWithTrigger` value (the default), the popup goes over the trigger: the text of
// the selected item is on the text of the `value` target, and the popup gets `data-side="none"`. It
// stays 5 px from the left and the right of the viewport. A popup that is too tall for the viewport
// gets smaller (10 px from the top and the bottom), and its list scrolls: give the popup
// `display: flex; flex-direction: column`, and the list `overflow-y: auto; min-height: 0`.
// The popup goes next to the trigger as a `phem-popover` when the trigger is less than 20 px from
// the top or 40 px from the bottom of the viewport, when the popup would be less than 100 px high
// (or less than the full height of a list that is shorter than 100 px), when the list does not
// scroll, or after a touch. It then uses the `side` (`bottom`), `align` (`center`), `sideOffset`, `alignOffset`,
// `collisionPadding` and `arrowPadding` values (phem_position.js). With the `modal` value (the
// default), the page does not scroll and the elements out of the popup get no pointer events while
// it is open (phem_modal.js).
//
// The scroll arrows scroll the list one item each 40 ms while the pointer is on them. At the end of
// the list the scroll stops, and a move on the arrow starts it again. Each one shows only when the
// list can scroll in its direction, with `data-visible`, unless it has `data-keep-mounted`. They
// get `data-direction` (`up` or `down`), `data-side` and `position: absolute`. The item with the
// focus does not go under a visible arrow, or into the `scroll-padding-top` and
// `scroll-padding-bottom` of the list.
//
// The `input` target is the field of the form: an `<input>` gets the value (the values separated
// by a comma with `multiple`), and a `<select>` gets its options with the values selected. It
// gets `required` and `disabled` from the values of the controller. When the user changes the
// value, it sends `input` and `change` events, and the controller dispatches `phem-select:change`
// with the `value` in the detail (an array with `multiple`). A focus on the input goes to the
// trigger, for the message of a form that is not valid.
//
// The trigger gets `role="combobox"`, `aria-haspopup="listbox"`, `aria-expanded`,
// `aria-controls`, `data-popup-open` and `data-pressed` while the popup is open,
// `data-popup-side` (`bottom` for a popup over the trigger), `data-placeholder`,
// `data-disabled`, `data-readonly` and `data-required`. The list (or the popup, with no list)
// gets `role="listbox"`, and `aria-multiselectable` with `multiple`. The items get
// `role="option"`, `aria-selected`, `data-selected`, `data-disabled`, and `data-highlighted` and
// `tabindex="0"` while they have the focus. The other items get `tabindex="-1"`: the controller
// sets the `tabindex` of each item. An `itemIndicator` target shows only in a selected item
// (unless it has `data-keep-mounted`), with the transition attributes. The `icon` target gets
// `data-popup-open`. A `label` target names the trigger (`aria-labelledby`, or an element
// reference when the label has no id), and a click on it gives the focus to the trigger. The
// groups get `role="group"` with the name of their `groupLabel`, and the separators
// `role="separator"`. The popup and the `backdrop` target get
// the open state and the animations of the conventions (phem_transition.js).
export default class PhemSelectController extends Controller {
  static targets = ["trigger", "label", "value", "icon", "popup", "list", "item", "itemText", "itemIndicator", "group", "groupLabel", "separator", "scrollUpArrow", "scrollDownArrow", "arrow", "backdrop", "input"]
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
    this.typeahead = new Typeahead({ resetMs: 750 })
    // The controller places the popup itself: with no anchor, the popup does not.
    this.popup = new Popup({
      anchor: () => null,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => ({}),
      inside: () => [this.element, ...this.popupTargets],
      dismiss: (reason) => this.#dismiss(reason),
      event: () => "pointerdown",
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
    this.pressed?.abort()
    this.pressed = null
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

    // A click on the label gives the focus to the trigger, and does not open the popup.
    // A native `<label for>` would also click the trigger: the default is off.
    if (this.labelTargets.some((el) => el.contains(event.target))) {
      event.preventDefault()
      if (!this.#disabled) this.triggerTargets[0]?.focus()

      return
    }

    const trigger = this.#triggerOf(event.target)
    if (!trigger || this.#disabled || this.pressing) return

    if (this.openValue) this.#close({ focus: true })
    else this.#open({ to: event.detail === 0 ? "first" : null, touch: this.pointerType === "touch" })
  }

  // A press on the trigger opens the popup, or closes it. The click after it does nothing. The
  // press does not select text.
  onPointerDown = (event) => {
    this.pointerType = event.pointerType
    const trigger = this.#triggerOf(event.target)
    if (!trigger || event.button !== 0 || event.pointerType === "touch" || this.#disabled) return

    this.pressing = true
    this.pressed?.abort()
    this.pressed = new AbortController()
    const options = { capture: true, signal: this.pressed.signal }
    addEventListener("selectstart", preventDefault, options)
    addEventListener("mousedown", this.onPressed, { ...options, once: true })
    addEventListener("pointerup", this.onRelease, options)
    addEventListener("pointercancel", this.onRelease, options)

    if (this.openValue) this.#close({ focus: true })
    else {
      this.#open({ pointer: true })
      this.press = { x: event.clientX, y: event.clientY, time: Date.now() }
    }
  }

  // The mousedown after the press on the trigger gives it a focus of the mouse (no focus ring),
  // as in Base UI. The focus then goes back to the item of the open popup: a focus from the
  // trigger shows no focus ring either.
  onPressed = () => {
    const active = document.activeElement
    if (!this.openValue || !this.popupTargets.some((el) => el.contains(active))) return

    setTimeout(() => {
      if (this.openValue && !this.popupTargets.some((el) => el.contains(document.activeElement))) active.focus({ preventScroll: true })
    }, 0)
  }

  // The end of the press on the trigger (a release or a cancel) removes the listeners of the
  // press. A release on an item after a drag selects the item.
  onRelease = (event) => {
    setTimeout(() => (this.pressing = false), 0)
    this.pressed?.abort()
    this.pressed = null

    const press = this.press
    this.press = null
    if (!press || !this.openValue || event.type !== "pointerup") return

    const item = this.#itemOf(event.target)
    const moved = Math.hypot(event.clientX - press.x, event.clientY - press.y) > DRAG_DISTANCE
    if (item && (moved || Date.now() - press.time > DRAG_TIME)) this.#choose(item)
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || isComposing(event) || event.ctrlKey || event.metaKey || event.altKey) return

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
    const from = this.#selectedItems[0] ?? null
    const typed = event.key === " " ? this.typeahead.find(items, item, event, labelOf, isDisabled, from) : undefined
    if (event.key === "Enter" || (event.key === " " && !typed)) {
      event.preventDefault()
      if (item) this.#choose(item)

      return
    }

    const next = typed ?? (movesFocus(event.key, "vertical")
      ? nextItem(items, item, event.key, { loop: this.loopFocusValue })
      : this.typeahead.find(items, item, event, labelOf, isDisabled, from))
    if (movesFocus(event.key, "vertical")) event.preventDefault()
    if (!next) return

    // The highlight now belongs to the keyboard: an item that scrolls out from under the pointer
    // does not remove it.
    this.pointerItem = null
    event.preventDefault()
    this.#focusItem(next)
  }

  onPointerOver = (event) => {
    this.#scrollOn(event)
  }

  // A move of the pointer highlights the item under it. The browser also sends `pointerover` when
  // an item comes under a pointer that does not move (the popup opens over the trigger, or a key
  // scrolls the list): that item does not take the highlight of the keyboard.
  onPointerMove = (event) => {
    if (this.#scrollOn(event)) return
    if (!this.openValue || !this.highlightItemOnHoverValue || event.pointerType === "touch") return

    // The moves on the item with the focus: no lookup of the items.
    const last = this.pointerItem
    if (last?.contains(event.target) && document.activeElement === last) return

    const item = this.#itemOf(event.target)
    this.pointerItem = item
    if (!item || isDisabled(item) || document.activeElement === item) return

    item.focus({ preventScroll: true })
  }

  // The pointer that leaves the items removes the highlight: the focus goes to the popup. An item
  // that goes out from under a pointer that does not move keeps the highlight of the keyboard.
  onPointerOut = (event) => {
    const arrow = this.#scrollArrowOf(event.target)
    if (arrow && !(event.relatedTarget instanceof Node && arrow.contains(event.relatedTarget))) this.#stopScroll()

    leaveList(event, this.itemTargets, () => {
      if (!this.openValue || !this.highlightItemOnHoverValue || !this.pointerItem) return

      this.pointerItem = null
      this.popupTargets[0]?.focus({ preventScroll: true })
    })
  }

  onFocusIn = (event) => {
    if (this.inputTargets.includes(event.target)) {
      this.#trigger?.focus()

      return
    }

    this.#highlight(this.#itemOf(event.target) ?? null)
  }

  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (!(to instanceof Node) || !this.#itemOf(to)) this.#highlight(null)

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

    const selected = this.#selectedItems[0] ?? null
    const item = this.typeahead.find(this.#items, selected, event, labelOf, isDisabled, selected)
    if (!item) return

    event.preventDefault()
    this.#choose(item)
  }

  // Opens the popup. The focus goes to the selected item, or to `to` ("first" or "last"), or to
  // the popup. A press of the mouse (`pointer`) with no value goes to the first item, with the
  // `highlightItemOnHover` value.
  #open({ to = null, touch = false, pointer = false } = {}) {
    if (this.openValue || this.#disabled) return

    this.openValue = true
    this.aligned = this.alignItemWithTriggerValue && !touch
    this.#apply()

    const items = this.#items
    const first = to === "first" || (to === null && pointer && this.highlightItemOnHoverValue)
    const item = this.#selectedItems.find((el) => !isDisabled(el) && !el.hidden) ?? (first ? nextItem(items, null, "Home") : to === "last" ? nextItem(items, null, "End") : null)
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

  // Escape and a press outside (at the press) close the popup, and the focus goes to the trigger.
  // The trigger is in the select: a press on it is not a press outside. The mousedown on an
  // element that cannot get the focus then moves the focus to the body: the frame after it gives
  // the focus back to the trigger. A press on an element that gets the focus keeps it.
  #dismiss(reason) {
    this.#close({ focus: true })
    if (reason === "escape") return

    requestAnimationFrame(() => {
      if (this.openValue) return

      const active = document.activeElement
      if (active === null || active === document.body) this.#trigger?.focus({ preventScroll: true })
    })
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
      for (const el of this.popupTargets) unsize(el)
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

  // The item gets the focus. The scroll keeps it out from under a visible scroll arrow: a scroll
  // can show an arrow, and the item then scrolls again.
  #focusItem(item, { scroll = true } = {}) {
    item.focus({ preventScroll: true })
    if (!scroll) return

    const visible = () => this.#scrollArrows.filter((arrow) => arrow.hasAttribute("data-visible")).length
    const before = visible()
    scrollIntoList(this.#scroller, item, this.#scrollInsets())
    this.#renderScrollArrows()

    if (visible() > before) scrollIntoList(this.#scroller, item, this.#scrollInsets())
  }

  // The space (px) at the top and at the bottom of the list that an item does not go under: the
  // `scroll-padding` of the list, or the height of a visible scroll arrow.
  #scrollInsets() {
    const list = this.#scroller
    if (!list) return { top: 0, bottom: 0 }

    const style = getComputedStyle(list)
    const height = (arrows) => Math.max(0, ...arrows.filter((el) => el.hasAttribute("data-visible") && !el.hidden).map((el) => el.offsetHeight))

    return {
      top: Math.max(parseFloat(style.scrollPaddingTop) || 0, height(this.scrollUpArrowTargets)),
      bottom: Math.max(parseFloat(style.scrollPaddingBottom) || 0, height(this.scrollDownArrowTargets)),
    }
  }

  // The item with the highlight gets `data-highlighted` and the tab stop (`tabindex="0"`), as in
  // Base UI. null removes the highlight from all the items.
  #highlight(item) {
    for (const el of this.itemTargets) {
      el.toggleAttribute("data-highlighted", el === item)
      el.tabIndex = el === item ? 0 : -1
    }
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
      unsize(popup)
      this.reposition = position(trigger, popup, () => ({ ...positionOptions(this), arrow: this.arrowTargets[0] ?? null }), signal)
    }

    this.#renderSide()
    this.#renderScrollArrows()
  }

  // The text of the selected item (or of the first item) goes on the text of the value part. A
  // popup that is too tall for the viewport gets smaller (MARGIN from the edges), and its list
  // scrolls. Returns false when the popup goes next to the trigger: the trigger is near an edge
  // of the viewport (EDGE), the popup would be less than MIN_HEIGHT high (or less than the full
  // height of a shorter list), or the list does not scroll. Base UI.
  #placeAligned(popup, trigger) {
    const item = this.#selectedItems.find((el) => !el.hidden) ?? nextItem(this.#items, null, "Home")
    if (!item) return false

    const list = this.#scroller
    const view = { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight }
    const style = popup.style
    const t = trigger.getBoundingClientRect()

    unsize(popup)
    list.scrollTop = 0
    popup.setAttribute("data-side", "none")
    popup.setAttribute("data-align", "center")
    style.setProperty("position", "fixed")
    style.setProperty("top", "0px")
    style.setProperty("left", "0px")
    style.setProperty("right", "auto")
    style.setProperty("bottom", "auto")
    style.setProperty("--anchor-width", `${t.width}px`)
    style.setProperty("--anchor-height", `${t.height}px`)
    style.setProperty("--available-width", `${Math.max(0, view.width - 2 * PADDING)}px`)
    style.setProperty("--available-height", `${Math.max(0, view.height - 2 * MARGIN)}px`)

    if (t.top < EDGE || t.bottom > view.height - 2 * MARGIN - EDGE) return false

    const anchor = this.valueTargets.find((el) => trigger.contains(el)) ?? trigger
    const text = this.itemTextTargets.find((el) => item.contains(el)) ?? item
    const v = anchor.getBoundingClientRect()
    const p = popup.getBoundingClientRect()
    const r = text.getBoundingClientRect()

    // The popup is at the top left of the viewport (`top` and `left` 0): the boxes give the
    // `top` and the `left` that put the text on the value. A margin or a transform of the popup
    // (`translate`) moves its box from its `top` by `p.top`.
    const idealTop = v.top + v.height / 2 - (r.top + r.height / 2)
    const top = Math.max(MARGIN - p.top, idealTop)
    const height = Math.min(view.height - MARGIN, idealTop + p.top + p.height) - (top + p.top)

    if (height < p.height) {
      // The list gets the content box of the popup (`--available-height`), in its border and its
      // padding.
      const room = list.scrollHeight - list.clientHeight
      const frame = frameOf(popup)
      const borderBox = getComputedStyle(popup).boxSizing === "border-box"
      style.setProperty("height", `${borderBox ? height : height - frame}px`)
      style.setProperty("max-height", "none")
      style.setProperty("--available-height", `${height - frame}px`)

      // The list must get smaller by the height that the popup lost, and scroll.
      const scrolls = !["visible", "clip"].includes(getComputedStyle(list).overflowY)
      const smaller = list.scrollHeight - list.clientHeight - room >= p.height - height - TOLERANCE
      if (Math.ceil(height) + TOLERANCE < Math.min(list.scrollHeight, MIN_HEIGHT) || !scrolls || !smaller) {
        unsize(popup)

        return false
      }

      list.scrollTop = top - idealTop
    }

    // The text after the scroll gives the top: no rounding of a value that is measured again.
    const at = text.getBoundingClientRect()
    const box = popup.getBoundingClientRect()
    const y = v.top + v.height / 2 - (at.top + at.height / 2)
    const left = clamp(v.left - at.left, PADDING - box.left, view.width - PADDING - box.width - box.left)
    style.setProperty("top", `${y}px`)
    style.setProperty("left", `${left}px`)
    style.setProperty("--transform-origin", `${Math.round(v.left + v.width / 2 - left - box.left)}px ${Math.round(at.top - box.top + at.height / 2)}px`)
    this.offset = { x: left - t.left, y: y - t.top }

    for (const arrow of this.arrowTargets) {
      arrow.setAttribute("data-side", "none")
      arrow.setAttribute("data-align", "center")
    }

    return true
  }

  // The popup over the trigger follows it.
  #follow(popup, trigger) {
    const t = trigger.getBoundingClientRect()
    popup.style.setProperty("top", `${t.top + this.offset.y}px`)
    popup.style.setProperty("left", `${t.left + this.offset.x}px`)
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

      // The `label` target names the trigger, unless the markup names it (`aria-label`, or an
      // `aria-labelledby` that the controller did not write).
      const label = this.labelTargets[0]
      this.labelled ??= new WeakSet()
      if (label && !trigger.hasAttribute("aria-label") && (!trigger.hasAttribute("aria-labelledby") || this.labelled.has(trigger))) {
        link(trigger, "aria-labelledby", "ariaLabelledByElements", label)
        this.labelled.add(trigger)
      }
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

    const labels = selected.map(labelOf)
    for (const el of this.valueTargets) {
      el.toggleAttribute("data-placeholder", placeholder)

      if (!placeholder && el.hasAttribute("data-phem-select-markup")) {
        const markup = markupOf(selected[0])
        if (el.innerHTML !== markup) el.innerHTML = markup

        continue
      }

      const shown = placeholder ? (el.getAttribute("placeholder") ?? "") : textOf(labels, el.getAttribute("data-phem-select-template"))
      if (el.textContent !== shown) el.textContent = shown
    }

    for (const el of this.iconTargets) el.toggleAttribute("data-popup-open", open)

    for (const item of this.itemTargets) {
      const on = selected.includes(item)
      if (!item.hasAttribute("role")) item.setAttribute("role", "option")
      item.tabIndex = open && item.hasAttribute("data-highlighted") ? 0 : -1
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

  // The side of the open popup on the trigger and on the scroll arrows. The trigger of a popup over
  // it gets `bottom`, as in Base UI. The scroll arrows keep `none`.
  #renderSide() {
    const side = this.openValue ? this.popupTargets[0]?.getAttribute("data-side") : null
    const parts = [
      ...this.triggerTargets.map((el) => [el, "data-popup-side", side === "none" ? "bottom" : side]),
      ...this.#scrollArrows.map((el) => [el, "data-side", side]),
    ]

    for (const [part, name, value] of parts) {
      if (value) part.setAttribute(name, value)
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

  // A pointer (not a touch) on a scroll arrow starts its scroll. Returns whether it is on an arrow.
  #scrollOn(event) {
    const arrow = this.#scrollArrowOf(event.target)
    if (!arrow) return false

    if (event.pointerType !== "touch") this.#startScroll(this.scrollUpArrowTargets.includes(arrow) ? -1 : 1)

    return true
  }

  // The pointer on a scroll arrow scrolls the list one item each SCROLL_DELAY ms. The scroll stops
  // at the edge: a move on the arrow starts it again.
  #startScroll(direction) {
    if (this.scrollDirection === direction) return

    this.#stopScroll()
    this.scrollDirection = direction

    const step = () => {
      const list = this.#scroller
      if (!list || this.scrollDirection !== direction) return

      const max = list.scrollHeight - list.clientHeight
      if (direction < 0 ? list.scrollTop <= TOLERANCE : list.scrollTop >= max - TOLERANCE) {
        this.#stopScroll()

        return
      }

      const arrow = (direction < 0 ? this.scrollUpArrowTargets : this.scrollDownArrowTargets)[0]
      list.scrollTop = nextScrollTop(list, this.#items, direction, arrow?.offsetHeight ?? 0)
      this.#renderScrollArrows()

      if (this.scrollDirection === direction) this.scrolling = setTimeout(step, SCROLL_DELAY)
    }

    this.scrolling = setTimeout(step, SCROLL_DELAY)
  }

  #stopScroll() {
    clearTimeout(this.scrolling)
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
    if (this.itemTargets.includes(el)) return ["aria-selected", "data-selected", "data-highlighted", "tabindex"].includes(name)
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

// The text of the `value` target: the labels separated by a comma, or with two labels or more the
// template (`{first}`, `{others}`, `{count}`, `{labels}`).
function textOf(labels, template) {
  if (!template || labels.length < 2) return labels.join(", ")

  const parts = { first: labels[0], others: String(labels.length - 1), count: String(labels.length), labels: labels.join(", ") }

  return template.replace(/\{(first|others|count|labels)\}/g, (_, name) => parts[name])
}

// The markup of the `itemText` part of an item (or of the item), with no id and no target, so the
// copy is not a part of the select.
function markupOf(item) {
  const source = item.querySelector("[data-phem-select-target~='itemText']") ?? item
  const copy = source.cloneNode(true)
  for (const el of [copy, ...copy.querySelectorAll("*")]) {
    el.removeAttribute("id")
    el.removeAttribute("data-phem-select-target")
  }

  return copy.innerHTML.trim()
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

// Scrolls the list so that the item is in view. The `insets` (px) make the box of the list
// smaller at the top and at the bottom.
function scrollIntoList(list, item, { top = 0, bottom = 0 } = {}) {
  if (!list) return

  const box = list.getBoundingClientRect()
  const at = item.getBoundingClientRect()
  if (at.top < box.top + top) list.scrollTop -= box.top + top - at.top
  else if (at.bottom > box.bottom - bottom) list.scrollTop += at.bottom - box.bottom + bottom
}

// The scroll of the next step of a scroll arrow: the next item that is under the arrow (or out of
// the list) comes into view next to the arrow. A port of `getTargetScrollTop` of Base UI. The top
// of each item is measured from the top of the content of the list.
function nextScrollTop(list, items, direction, arrowHeight) {
  const scrollTop = list.scrollTop
  const max = Math.max(0, list.scrollHeight - list.clientHeight)
  const origin = list.getBoundingClientRect().top + list.clientTop - scrollTop
  const boxes = items.map((item) => {
    const r = item.getBoundingClientRect()

    return { top: r.top - origin, bottom: r.bottom - origin }
  })
  const normal = (value) => clamp(value, 0, max)

  if (direction < 0) {
    const visibleTop = scrollTop + arrowHeight - TOLERANCE
    let first = boxes.findIndex((box) => box.top >= visibleTop)
    if (first === -1) first = 0

    const target = Math.max(0, first - 1)

    return target < first ? normal(boxes[target].top - arrowHeight) : 0
  }

  const visibleBottom = scrollTop + list.clientHeight - arrowHeight + TOLERANCE
  const over = boxes.findIndex((box) => box.bottom > visibleBottom)
  const last = over === -1 ? boxes.length - 1 : Math.max(0, over - 1)
  const target = Math.min(boxes.length - 1, last + 1)

  return target > last ? normal(boxes[target].bottom - list.clientHeight + arrowHeight) : max
}

function preventDefault(event) {
  event.preventDefault()
}

// Removes the height that the popup over the trigger gave to the popup.
function unsize(popup) {
  popup.style.removeProperty("height")
  popup.style.removeProperty("max-height")
}

// The vertical padding and border (px) of a popup.
function frameOf(popup) {
  const style = getComputedStyle(popup)

  return ["paddingTop", "paddingBottom", "borderTopWidth", "borderBottomWidth"].reduce((sum, name) => sum + (parseFloat(style[name]) || 0), 0)
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
