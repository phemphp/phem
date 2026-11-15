import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { takesText } from "./phem_fields.js"

// The `phem-toolbar` controller: a row of controls with one tab stop. The arrow keys move the
// focus between the `item` targets.
//
//     <div data-controller="phem-toolbar" aria-label="Format">
//         <div data-phem-toolbar-target="group" aria-label="Alignment">
//             <button data-phem-toolbar-target="item">Left</button>
//             <button data-phem-toolbar-target="item" aria-disabled="true">Right</button>
//         </div>
//         <div data-phem-toolbar-target="separator"></div>
//         <a href="/help" data-phem-toolbar-target="item">Help</a>
//         <input data-phem-toolbar-target="item" aria-label="Zoom">
//     </div>
//
// An item is a button, a link, an input or another element that can get the focus. A `group`
// target holds some of the items. Only one item is in the tab order (`tabindex="0"`): the item
// that had the focus last, else the first item. The arrow keys of the `orientation` value
// (`horizontal` by default) move the focus to the next or to the previous item, and Home and End
// go to the first and to the last item. With the `loopFocus` value (the default), the last item
// goes to the first one. In a text input, ArrowLeft and ArrowRight move the caret, and move the
// focus only when the caret is at the start or at the end of the text. Home and End move the
// caret.
//
// A disabled item stays in the focus order, so a keyboard user can find it: it gets
// `aria-disabled="true"`, `data-disabled` and `data-focusable`, and a click on it or a text in it
// does nothing. An item is disabled when the markup gives it `aria-disabled="true"`, when its
// group has `aria-disabled="true"`, or with the `disabled` value of the toolbar. An item with the
// native `disabled` attribute gets `data-disabled`, but it does not get the focus.
//
// The toolbar gets `role="toolbar"` and `aria-orientation`, a group gets `role="group"`, and a
// `separator` target gets `role="separator"` and the other orientation in `aria-orientation`. All
// the parts get `data-orientation` (a separator gets the other orientation), and the toolbar and a
// disabled group get `data-disabled`.
export default class PhemToolbarController extends Controller {
  static targets = ["item", "group", "separator"]
  static values = {
    orientation: { type: String, default: "horizontal" },
    loopFocus: { type: Boolean, default: true },
    disabled: Boolean,
  }

  connect() {
    // item => whether the server render gives it `aria-disabled="true"`.
    this.given = new WeakMap()
    this.state = new ClientState(this, {
      owns: (el, name) => name === "tabindex" && this.itemTargets.includes(el),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusin", this.onFocusin, options)
    this.element.addEventListener("click", this.onBlocked, { ...options, capture: true })
    this.element.addEventListener("beforeinput", this.onBlocked, { ...options, capture: true })

    // The morph sets the `aria-disabled` of the server render again.
    this.element.addEventListener("turbo:morph-element", (event) => this.given.delete(event.target), options)

    this.rendered = true
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.rendered = false
  }

  orientationValueChanged() {
    if (this.rendered) this.#render()
  }

  disabledValueChanged() {
    if (this.rendered) this.#render()
  }

  itemTargetConnected() {
    if (this.rendered) this.#render()
  }

  itemTargetDisconnected() {
    if (this.rendered) this.#render()
  }

  groupTargetConnected() {
    if (this.rendered) this.#render()
  }

  separatorTargetConnected() {
    if (this.rendered) this.#render()
  }

  onFocusin = (event) => {
    const item = this.#itemOf(event.target)
    if (!item || item === this.active) return

    this.active = item
    this.#renderTabIndex()
  }

  onKeydown = (event) => {
    const item = this.#itemOf(event.target)
    if (!item || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return

    const vertical = this.orientationValue === "vertical"
    const rtl = getComputedStyle(this.element).direction === "rtl"
    const forward = vertical ? "ArrowDown" : rtl ? "ArrowLeft" : "ArrowRight"
    const backward = vertical ? "ArrowUp" : rtl ? "ArrowRight" : "ArrowLeft"
    if (![forward, backward, "Home", "End"].includes(event.key)) return

    // A text input keeps the keys that move its caret.
    if (takesText(item) && !caretLeaves(item, event.key, vertical, forward)) return

    const next = this.#next(item, event.key, forward)
    event.preventDefault()
    if (!next) return

    this.active = next
    this.#renderTabIndex()
    next.focus()
  }

  // A click on a disabled item, or a text in it, does nothing.
  onBlocked = (event) => {
    const item = this.#itemOf(event.target)
    if (!item || !this.#disabled(item)) return

    event.preventDefault()
    event.stopImmediatePropagation()
  }

  // The item that `key` moves the focus to.
  #next(item, key, forward) {
    const items = this.#focusable
    if (key === "Home") return items[0]
    if (key === "End") return items.at(-1)

    const next = items.indexOf(item) + (key === forward ? 1 : -1)
    if (next >= 0 && next < items.length) return items[next]

    return this.loopFocusValue ? items.at(next < 0 ? -1 : 0) : undefined
  }

  #render() {
    const orientation = this.orientationValue
    const other = orientation === "vertical" ? "horizontal" : "vertical"

    this.element.setAttribute("role", "toolbar")
    this.element.setAttribute("aria-orientation", orientation)
    this.element.setAttribute("data-orientation", orientation)
    this.element.toggleAttribute("data-disabled", this.disabledValue)
    if (this.disabledValue) this.element.setAttribute("aria-disabled", "true")
    else this.element.removeAttribute("aria-disabled")

    for (const group of this.groupTargets) {
      if (!group.hasAttribute("role")) group.setAttribute("role", "group")
      group.setAttribute("data-orientation", orientation)
      group.toggleAttribute("data-disabled", this.disabledValue || groupDisabled(group))
    }

    for (const separator of this.separatorTargets) {
      separator.setAttribute("role", "separator")
      separator.setAttribute("aria-orientation", other)
      separator.setAttribute("data-orientation", other)
    }

    for (const item of this.itemTargets) {
      const disabled = this.#disabled(item)
      // A native `disabled` item does not get the focus.
      const focusable = disabled && !item.disabled

      item.setAttribute("data-orientation", orientation)
      item.toggleAttribute("data-disabled", disabled)
      item.toggleAttribute("data-focusable", focusable)
      if (focusable) item.setAttribute("aria-disabled", "true")
      else if (!this.#givenDisabled(item)) item.removeAttribute("aria-disabled")
    }

    this.#renderTabIndex()
  }

  // One item in the tab order: the item of the last focus, else the first item.
  #renderTabIndex() {
    const items = this.#focusable
    if (!items.includes(this.active)) this.active = items[0]

    for (const item of this.itemTargets) item.tabIndex = item === this.active ? 0 : -1
  }

  // The items that get the focus.
  get #focusable() {
    return this.itemTargets.filter((item) => !item.disabled)
  }

  #disabled(item) {
    // It reads the server render first: the controller can add `aria-disabled` after.
    if (this.#givenDisabled(item) || this.disabledValue || item.disabled) return true

    return this.groupTargets.some((group) => group.contains(item) && groupDisabled(group))
  }

  #givenDisabled(item) {
    if (!this.given.has(item)) this.given.set(item, item.getAttribute("aria-disabled") === "true")

    return this.given.get(item)
  }

  #itemOf(el) {
    return this.itemTargets.find((item) => item.contains(el))
  }
}

function groupDisabled(group) {
  return group.getAttribute("aria-disabled") === "true" || group.hasAttribute("disabled")
}

// Whether a key moves the focus out of a text input. In a horizontal toolbar, the arrow keys move
// the focus only when the caret is at the start or at the end of the text, and Home and End always
// move the caret. An input with no caret (for example a number input) lets the arrow keys go.
function caretLeaves(input, key, vertical, forward) {
  if (key === "Home" || key === "End") return false
  if (vertical) return true

  const { selectionStart: start, selectionEnd: end } = input
  if (start === null || start === undefined) return true

  // The next item is at the end of the text, also in a right-to-left page.
  const length = (input.value ?? "").length
  if (key === forward) return start === length && end === length

  return start === 0 && end === 0
}
