import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { isChecked, isFixed, isToggle, itemValue, setChecked, syncChecked } from "./phem_toggle_items.js"

// The attributes of the state of the parent checkbox.
const PARENT_ATTRIBUTES = new Set(["data-checked", "data-unchecked", "data-indeterminate", "aria-controls"])

// The `phem-checkbox-group` controller: a group of checkboxes with one shared state, and a parent
// checkbox that checks and unchecks the items, for a "select all" box of a list or of a table.
//
//     <div data-controller="phem-checkbox-group">
//         <input type="checkbox" data-phem-checkbox-group-target="parent"> All
//         <input type="checkbox" phem:bind={{ selected }} value="1" data-phem-checkbox-group-target="item">
//         <input type="checkbox" phem:bind={{ selected }} value="2" data-phem-checkbox-group-target="item">
//     </div>
//
// An item (and the parent) is a native checkbox, or a `phem-toggle` checkbox
// (`role="checkbox"`, see phem_toggle_controller.js). The value of an item is its `data-value`,
// its `value` (the `value` attribute of a native checkbox, the `value` value of a `phem-toggle`
// element), its key (`data-phem-key`), or its index.
//
// The `value` value is the state of the group: the array of the values of the checked items. The
// controller writes it after each change. When the server render gives it, the group owns the
// state of the items: the value sets them on connect and after the morph of an event, and a
// server render with a new value sets it. With no `value` in the server render, the state of the
// items belongs to the server render and to the user (for example with `phem:bind`).
//
// The parent checks and unchecks the items of the `allValues` value (all the items when there is
// no `allValues`). A disabled or read-only item keeps its state. A click on the parent checks all
// the items, or unchecks all of them when all are checked. When some items are checked, the
// parent remembers them: the clicks go from this mixed state to all the items, to no item, and
// back to the mixed state. Each item that changes dispatches `input` and `change` (on a
// `phem-toggle` item, on its input), so a `phem:bind` field and `phem-count` see the change.
//
// The parent shows the state of its items: it is checked when all the items are checked, and
// indeterminate when some of them are checked. It gets `data-checked`, `data-unchecked` or
// `data-indeterminate` (a `phem-toggle` parent also gets `aria-checked="mixed"`), and
// `aria-controls` (the items with an id). The parent shows the state again after the morph of an
// event.
//
// Each change by the user dispatches `phem-checkbox-group:change` with `{ value }`.
//
// A group can be an item of the group around it: give the element of the inner group a
// `data-value`. The inner group is checked when all its items are checked, and makes the parent of
// the outer group indeterminate when some of its items are checked. The parent of the outer group
// checks and unchecks all the items of the inner group, and a change in the inner group shows on
// the outer parent, as the Nested parent checkbox of Base UI.
export default class PhemCheckboxGroupController extends Controller {
  static targets = ["parent", "item"]
  static values = {
    value: Array,
    allValues: Array,
  }

  connect() {
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.parentTargets.includes(el) && (PARENT_ATTRIBUTES.has(name) || name === "checked"),
      morphed: () => this.#morphed(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("change", this.onChange, options)
    this.element.addEventListener("phem-toggle:change", this.onToggleChange, options)
    this.element.addEventListener("phem-checkbox-group:change", this.onGroupChange, options)

    // The values that the parent gives back in the mixed state.
    this.saved = null
    this.ownsItems = this.hasValueValue
    if (this.ownsItems) this.#apply(this.valueValue.map(String), { notify: false })
    this.connected = true
    this.render()

    // A group inside a group: the outer group shows its state.
    const outer = this.element.hasAttribute("data-value") && this.element.parentElement?.closest(`[data-controller~="${this.identifier}"]`)
    if (outer) this.application.getControllerForElementAndIdentifier(outer, this.identifier)?.render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.connected = false
  }

  // A server render with a new value, or a change of the value by a script.
  valueValueChanged() {
    if (!this.connected || JSON.stringify(this.valueValue) === this.written) return

    this.#apply(this.valueValue.map(String), { notify: false })
    this.render()
  }

  allValuesValueChanged() {
    if (this.connected) this.render()
  }

  itemTargetConnected() {
    if (this.connected) this.render()
  }

  itemTargetDisconnected() {
    if (this.connected) this.render()
  }

  parentTargetConnected() {
    if (this.connected) this.render()
  }

  // After the morph. With a `value` in the server render, the value sets the items again.
  #morphed() {
    if (this.ownsItems) this.#apply(this.valueValue.map(String), { notify: false })

    this.render()
  }

  // A native checkbox.
  onChange = (event) => {
    if (this.applying || isToggle(event.target)) return

    if (this.parentTargets.includes(event.target)) this.#toggle(event.target)
    else if (this.itemTargets.includes(event.target)) this.#changed()
  }

  // A `phem-toggle` checkbox.
  onToggleChange = (event) => {
    if (this.applying) return

    if (this.parentTargets.includes(event.target)) this.#toggle(event.target)
    else if (this.itemTargets.includes(event.target)) this.#changed()
  }

  // A change in a group inside this group.
  onGroupChange = (event) => {
    if (this.applying || event.target === this.element || !this.#groups().includes(event.target)) return

    this.#changed()
  }

  // Whether all the items of the parent are checked: the state of this group as an item of the
  // group around it.
  get allChecked() {
    const children = this.#children()

    return children.length > 0 && children.every((item) => this.#isOn(item))
  }

  // Whether some items of the parent are checked, but not all of them.
  get someChecked() {
    const children = this.#children()

    return !this.allChecked && children.some((item) => this.#isOn(item) || this.#isSome(item))
  }

  // Checks or unchecks all the items of the parent, for the parent of the group around it.
  setAll(on) {
    const editable = this.#children().filter((item) => !this.#isFixed(item))

    this.applying = true
    try {
      for (const item of editable) {
        if (this.#isOn(item) !== on || this.#isSome(item)) this.#set(item, on)
      }
    } finally {
      this.applying = false
    }

    this.saved = null
    this.render()
    this.dispatch("change", { detail: { value: this.valueValue } })
  }

  // The user changed an item: the parent remembers the checked items.
  #changed() {
    this.saved = this.#checkedValues(this.#children())
    this.render()
    this.dispatch("change", { detail: { value: this.valueValue } })
  }

  // Checks all the items, unchecks all of them, or gives back the mixed state.
  #toggle(parent) {
    const editable = this.#children().filter((item) => !this.#isFixed(item))
    const checked = this.#checkedValues(editable)
    const values = editable.map((item) => this.#valueOf(item))
    const saved = this.saved?.filter((value) => values.includes(value)) ?? []

    let next
    if (checked.length > 0 && checked.length < values.length) {
      this.saved = checked
      next = values
    } else if (checked.length === values.length && values.length > 0) {
      next = []
    } else {
      next = saved.length > 0 && saved.length < values.length ? saved : values
    }

    this.applying = true
    try {
      for (const item of editable) {
        const on = next.includes(this.#valueOf(item))
        if (this.#isOn(item) !== on || this.#isSome(item)) this.#set(item, on)
      }
    } finally {
      this.applying = false
    }

    this.render()
    parent.focus()
    this.dispatch("change", { detail: { value: this.valueValue } })
  }

  // Sets the items to the values.
  #apply(values, { notify }) {
    this.applying = true
    try {
      for (const item of this.#items()) {
        const on = values.includes(this.#valueOf(item))
        if (this.#groupOf(item)) {
          if (this.#isOn(item) !== on) this.#groupOf(item).setAll(on)
        } else {
          syncChecked(this, item, on, { notify })
        }
      }
    } finally {
      this.applying = false
    }
  }

  // Writes the value, and shows the state of the items on the parents.
  render() {
    const value = this.#checkedValues(this.#items())
    this.written = JSON.stringify(value)
    if (JSON.stringify(this.valueValue) !== this.written) this.valueValue = value

    const children = this.#children()
    const checked = children.filter((item) => this.#isOn(item)).length
    const all = children.length > 0 && checked === children.length
    const some = !all && (checked > 0 || children.some((item) => this.#isSome(item)))

    // A form field gets no id from the controller: the morph matches a bound field by its id.
    const ids = children.map((item) => item.id).filter(Boolean)

    for (const parent of this.parentTargets) {
      if (isToggle(parent)) {
        setChecked(this, parent, all, { indeterminate: some })
      } else {
        parent.checked = all
        parent.indeterminate = some
        parent.toggleAttribute("data-checked", all)
        parent.toggleAttribute("data-unchecked", checked === 0)
        parent.toggleAttribute("data-indeterminate", some)
      }

      if (ids.length > 0) parent.setAttribute("aria-controls", ids.join(" "))
      else parent.removeAttribute("aria-controls")
    }
  }

  // The items of the parent: the items of the `allValues` value, or all the items.
  #children() {
    const items = this.#items()
    if (!this.hasAllValuesValue) return items

    const values = this.allValuesValue.map(String)

    return items.filter((item) => values.includes(this.#valueOf(item)))
  }

  // The items and the groups with a `data-value` that are directly in this group.
  #items() {
    return [...this.itemTargets, ...this.#groups()]
  }

  #groups() {
    return [...this.element.querySelectorAll(`[data-controller~="${this.identifier}"][data-value]`)]
      .filter((el) => el.parentElement.closest(`[data-controller~="${this.identifier}"]`) === this.element)
  }

  // The controller of a group inside this group, or null.
  #groupOf(item) {
    if (this.itemTargets.includes(item)) return null

    return this.application.getControllerForElementAndIdentifier(item, this.identifier)
  }

  #isOn(item) {
    const group = this.#groupOf(item)

    return group ? group.allChecked : this.itemTargets.includes(item) && isChecked(this, item)
  }

  #isSome(item) {
    return this.#groupOf(item)?.someChecked ?? false
  }

  #isFixed(item) {
    return this.itemTargets.includes(item) ? isFixed(this, item) : !this.#groupOf(item)
  }

  #set(item, on) {
    const group = this.#groupOf(item)
    if (group) group.setAll(on)
    else setChecked(this, item, on, { notify: true })
  }

  #checkedValues(items) {
    return items.filter((item) => this.#isOn(item)).map((item) => this.#valueOf(item))
  }

  #valueOf(item) {
    return itemValue(item, this.#items().indexOf(item))
  }
}
