import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The attributes of the state of the parent checkbox.
const PARENT_ATTRIBUTES = new Set(["data-checked", "data-unchecked", "data-indeterminate", "aria-controls"])

// The `phem-checkbox-group` controller: a parent checkbox that checks and unchecks all the items,
// for a "select all" box of a list or of a table.
//
//     <div data-controller="phem-checkbox-group">
//         <input type="checkbox" data-phem-checkbox-group-target="parent"> All
//         <input type="checkbox" phem:bind={{ selected }} value="1" data-phem-checkbox-group-target="item">
//         <input type="checkbox" phem:bind={{ selected }} value="2" data-phem-checkbox-group-target="item">
//     </div>
//
// A click on the parent checks all the items, or unchecks all of them when all are checked. A
// disabled item keeps its state. Each item that changes dispatches `input` and `change`, so a
// `phem:bind` field and `phem-count` see the change.
//
// The parent shows the state of the items: it is checked when all the items are checked, and
// indeterminate when some of them are checked. It gets `data-checked`, `data-unchecked` or
// `data-indeterminate`, and `aria-controls` (the items with an id). The state of the items belongs
// to the server render and to the user, and the parent shows it again after the morph of an event.
export default class PhemCheckboxGroupController extends Controller {
  static targets = ["parent", "item"]

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.parentTargets.includes(el) && (PARENT_ATTRIBUTES.has(name) || name === "checked"),
      morphed: () => this.render(),
    })
    this.state.connect()

    this.element.addEventListener("change", this.onChange)
    this.render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("change", this.onChange)
  }

  itemTargetConnected() {
    if (this.state) this.render()
  }

  itemTargetDisconnected() {
    if (this.state) this.render()
  }

  parentTargetConnected() {
    if (this.state) this.render()
  }

  onChange = (event) => {
    if (this.parentTargets.includes(event.target)) this.#toggle(event.target)
    else if (this.itemTargets.includes(event.target)) this.render()
  }

  // Checks all the items, or unchecks all of them when all are checked.
  #toggle(parent) {
    const items = this.itemTargets.filter((item) => !item.disabled)
    const checked = !items.every((item) => item.checked)
    const changed = items.filter((item) => item.checked !== checked)

    for (const item of changed) item.checked = checked

    for (const item of changed) {
      item.dispatchEvent(new Event("input", { bubbles: true }))
      item.dispatchEvent(new Event("change", { bubbles: true }))
    }

    this.render()
    parent.focus()
  }

  // Shows the state of the items on the parents.
  render() {
    const items = this.itemTargets
    const checked = items.filter((item) => item.checked).length
    const all = items.length > 0 && checked === items.length
    const some = checked > 0 && !all

    // A form field gets no id from the controller: the morph matches a bound field by its id.
    const ids = items.map((item) => item.id).filter(Boolean)

    for (const parent of this.parentTargets) {
      parent.checked = all
      parent.indeterminate = some
      parent.toggleAttribute("data-checked", all)
      parent.toggleAttribute("data-unchecked", checked === 0)
      parent.toggleAttribute("data-indeterminate", some)
      if (ids.length > 0) parent.setAttribute("aria-controls", ids.join(" "))
      else parent.removeAttribute("aria-controls")
    }
  }
}
