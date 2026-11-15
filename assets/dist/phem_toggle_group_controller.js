import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { isDisabled, movesFocus, nextItem } from "./phem_roving.js"
import { isToggle, itemValue, syncChecked } from "./phem_toggle_items.js"

// The `phem-toggle-group` controller: a group of toggle buttons with one shared state, for
// example the alignment buttons of a text editor. The state is on the client, and it stays after
// the morph of an event.
//
//     <div data-controller="phem-toggle-group" data-phem-toggle-group-value-value='["left"]' aria-label="Alignment">
//         <button type="button" data-phem-toggle-group-target="item" data-value="left">Left</button>
//         <button type="button" data-phem-toggle-group-target="item" data-value="center">Center</button>
//         <button type="button" data-phem-toggle-group-target="item" data-value="right">Right</button>
//     </div>
//
// The value of an item is its `data-value` attribute, the `value` of a `phem-toggle` item, its key
// (`data-phem-key`), or its index. The `value` value is the state: the array of the values of the
// pressed items. The server render gives its first value, and a server render with a new `value`
// value sets it. A click on an item presses it, and a click on a pressed item releases it. With
// no `multiple` value, a pressed item releases the other items. With `multiple`, each item has
// its own state.
//
// The items have a roving focus: one item is in the tab order (the item with the last focus, the
// first pressed item, or the first item). The arrow keys of the `orientation` value
// (`horizontal` by default) move the focus between the items and skip a disabled item; Home and
// End go to the first and to the last item. With the `loopFocus` value (the default), the last
// item goes to the first one. Space and Enter press the item with the focus (a native `<button>`
// clicks on them).
//
// In a `phem-toolbar`, the group is a `group` target and its items are also `item` targets of the
// toolbar. Then the toolbar moves the focus with the arrow keys, and gives the tab order and
// `data-orientation` (the `orientation` value of the toolbar). The focus goes from the group to
// the other items of the toolbar:
//
//     <div data-controller="phem-toolbar" aria-label="Format">
//         <div data-controller="phem-toggle-group" data-phem-toolbar-target="group" aria-label="Alignment">
//             <button type="button" data-phem-toggle-group-target="item" data-phem-toolbar-target="item" data-value="left">Left</button>
//             <button type="button" data-phem-toggle-group-target="item" data-phem-toolbar-target="item" data-value="right">Right</button>
//         </div>
//         <button type="button" data-phem-toolbar-target="item">Help</button>
//     </div>
//
// An item can be a `phem-toggle` element (`data-controller="phem-toggle"`). The group sets its
// state, and the toggle gives it `aria-pressed` and `data-pressed`. A click on it changes its
// state, and the group changes the state of the other items.
//
// The group gets `role="group"` when it has no role, `data-orientation`, `data-multiple`, and
// `data-disabled` and `aria-disabled="true"` with the `disabled` value: then no item changes. The
// items get `aria-pressed`, `data-pressed`, `data-orientation`, `tabindex`, and `data-disabled`
// when the group or the item is disabled.
//
// Each change by the user dispatches `phem-toggle-group:change` with `{ value }`.
export default class PhemToggleGroupController extends Controller {
  static targets = ["item"]
  static values = {
    value: Array,
    multiple: Boolean,
    orientation: { type: String, default: "horizontal" },
    loopFocus: { type: Boolean, default: true },
    disabled: Boolean,
  }

  connect() {
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.itemTargets.includes(el) && (["aria-pressed", "data-pressed"].includes(name) || (name === "tabindex" && !inToolbar(el))),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onBlock, { ...options, capture: true })
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusin", this.onFocusin, options)
    this.element.addEventListener("phem-toggle:change", this.onToggleChange, options)

    this.connected = true
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.connected = false
  }

  // A server render with a new value, or a change of a value by a script.
  valueValueChanged() {
    if (this.connected) this.#render()
  }

  disabledValueChanged() {
    if (this.connected) this.#render()
  }

  orientationValueChanged() {
    if (this.connected) this.#render()
  }

  // An item that the morph adds gets the state.
  itemTargetConnected() {
    if (this.connected) this.#render()
  }

  // A disabled group stops the click before a `phem-toggle` item gets it.
  onBlock = (event) => {
    if (!this.disabledValue || !this.#itemOf(event.target)) return

    event.preventDefault()
    event.stopPropagation()
  }

  onClick = (event) => {
    const item = this.#itemOf(event.target)

    // A `phem-toggle` item changes on its click, and dispatches `phem-toggle:change`.
    if (!item || isToggle(item) || isDisabled(item)) return

    this.#press(item, !this.#pressed.includes(this.#valueOf(item)))
  }

  onToggleChange = (event) => {
    if (this.notifying) return

    if (this.itemTargets.includes(event.target)) this.#press(event.target, event.detail.checked)
  }

  onKeydown = (event) => {
    const item = this.#itemOf(event.target)
    if (!item || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return
    if (!movesFocus(event.key, this.orientationValue) || inToolbar(item)) return

    const next = nextItem(this.itemTargets, item, event.key, { orientation: this.orientationValue, loop: this.loopFocusValue, rtl: this.#rtl })
    event.preventDefault()
    if (!next) return

    this.focused = next
    next.focus()
    this.#render()
  }

  onFocusin = (event) => {
    const item = this.#itemOf(event.target)
    if (!item || item === this.focused) return

    this.focused = item
    this.#render()
  }

  #press(item, pressed) {
    const value = this.#valueOf(item)
    const others = this.#pressed.filter((v) => v !== value)
    const next = pressed ? (this.multipleValue ? [...others, value] : [value]) : others

    this.valueValue = next
    this.#render({ notify: true })
    this.dispatch("change", { detail: { value: next } })
  }

  // With `notify` (a press of the user), a `phem-toggle` item that the press changes sends the
  // events of a change, so its bound input sends its new value.
  #render({ notify = false } = {}) {
    const items = this.itemTargets
    const pressed = this.#pressed
    const orientation = this.orientationValue
    const disabled = this.disabledValue
    const tabbable = this.#tabbable(pressed)

    if (!this.element.hasAttribute("role")) this.element.setAttribute("role", "group")
    if (!inToolbar(this.element, "group")) this.element.setAttribute("data-orientation", orientation)
    this.element.toggleAttribute("data-multiple", this.multipleValue)
    this.element.toggleAttribute("data-disabled", disabled)
    if (disabled) this.element.setAttribute("aria-disabled", "true")
    else if (this.ariaDisabled) this.element.removeAttribute("aria-disabled")
    this.ariaDisabled = disabled

    items.forEach((item, i) => {
      const on = pressed.includes(this.#valueOf(item, i))

      // A `phem-toggle` item renders its own `aria-pressed` and `data-pressed`.
      if (isToggle(item)) {
        this.notifying = notify
        try {
          syncChecked(this, item, on, { notify })
        } finally {
          this.notifying = false
        }
      } else {
        item.setAttribute("aria-pressed", String(on))
        item.toggleAttribute("data-pressed", on)
      }

      item.toggleAttribute("data-disabled", disabled || isDisabled(item))

      // In a toolbar, the toolbar gives the orientation and the tab order.
      if (!inToolbar(item)) {
        item.setAttribute("data-orientation", orientation)
        item.tabIndex = item === tabbable ? 0 : -1
      }
    })
  }

  // The item in the tab order: the item with the last focus, the first pressed item, or the first
  // item that is not disabled. The toolbar gives the tab order of its items.
  #tabbable(pressed) {
    const items = this.itemTargets.filter((item) => !isDisabled(item) && !inToolbar(item))
    if (items.includes(this.focused)) return this.focused

    return items.find((item) => pressed.includes(this.#valueOf(item))) ?? items[0]
  }

  // The values of the pressed items.
  get #pressed() {
    return this.valueValue.map(String)
  }

  #valueOf(item, index = this.itemTargets.indexOf(item)) {
    return itemValue(item, index)
  }

  #itemOf(el) {
    return this.itemTargets.find((item) => item.contains(el))
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }
}

// Whether an element is also a `target` (an `item`, or the `group`) of a `phem-toolbar`: the
// toolbar moves the focus, and gives the tab order and the orientation.
function inToolbar(el, target = "item") {
  return el.matches(`[data-phem-toolbar-target~=${target}]`) && el.closest("[data-controller~=phem-toolbar]") !== null
}
