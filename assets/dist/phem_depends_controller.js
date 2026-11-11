import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { fieldsValue } from "./phem_fields.js"
import { hide, render, show } from "./phem_transition.js"

// The form controls in a panel that the controller disables.
const CONTROLS = "input, select, textarea, button, fieldset"

// The mark of a control that the controller disabled. It is an attribute, so it stays when the
// controller connects again (a move of the element) and in a copy of the page.
const DISABLED = "data-phem-depends-disabled"

// The `phem-depends` controller: shows a panel from the value of a field, with no round trip. For
// an "Other" option that asks for a text, or a checkbox that shows more fields.
//
//     <div data-controller="phem-depends">
//         <select name="reason" data-phem-depends-target="field">
//             <option value="price">Price</option>
//             <option value="other">Other</option>
//         </select>
//         <div data-phem-depends-target="panel" data-phem-depends-match="other" hidden>
//             <input name="reasonOther">
//         </div>
//     </div>
//
// The `field` targets are one field: a `<select>`, a text field, a group of radio buttons, a
// checkbox, or a group of checkboxes. A panel shows when the value of the field is one of the
// words of its `data-phem-depends-match` attribute. The value of a checkbox is `checked` or
// `unchecked`. For a group of checkboxes, a panel shows when one of its words is a checked value:
// more than one checkbox, a bound list (`data-phem-bind-list`), or a `name` that ends with `[]`.
// With `data-phem-depends-match` and no value, a panel shows when the field has a value.
//
// A hidden panel disables its form controls, so a form submit does not send them. A control that
// the server render disables stays disabled. A panel gets `data-open` or `data-closed`; see
// phem_transition.js for the animations. The controller element gets the value of the field in
// `data-field-value`. The state comes from the field, so it stays right after the morph of an
// event.
export default class PhemDependsController extends Controller {
  static targets = ["field", "panel"]

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.element.addEventListener("input", this.onChange)
    this.element.addEventListener("change", this.onChange)
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("input", this.onChange)
    this.element.removeEventListener("change", this.onChange)
    this.rendered = undefined
  }

  panelTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  onChange = (event) => {
    if (this.fieldTargets.includes(event.target)) this.#apply()
  }

  // The state with its animations.
  #apply() {
    const values = this.#values
    this.rendered = values

    for (const panel of this.panelTargets) {
      const open = matches(panel, values)
      if (panel.hasAttribute("data-open") === open) continue

      if (open) show(panel)
      else hide(panel)
      this.#lock(panel, !open)
    }

    this.#renderRoot(values)
  }

  // The state with no animation: for the first render, and after the morph.
  #render() {
    const values = this.#values
    this.rendered = values

    for (const panel of this.panelTargets) {
      const open = matches(panel, values)
      render(panel, open)
      this.#lock(panel, !open)
    }

    this.#renderRoot(values)
  }

  #renderRoot(values) {
    this.element.setAttribute("data-field-value", values.join(" "))
  }

  // Disables the controls of a hidden panel, and enables the ones that the controller disabled.
  #lock(panel, locked) {
    for (const control of [panel, ...panel.querySelectorAll(CONTROLS)]) {
      if (!control.matches(CONTROLS)) continue

      if (locked && !control.disabled) {
        control.disabled = true
        control.setAttribute(DISABLED, "")
      } else if (!locked && control.hasAttribute(DISABLED)) {
        control.disabled = false
        control.removeAttribute(DISABLED)
      }
    }
  }

  // The values of the field, as words: the checked values of a group, `checked` or `unchecked`
  // for a checkbox, or one value.
  get #values() {
    const fields = this.fieldTargets
    const [first] = fields
    if (!first) return []

    const list = fields.length > 1 || first.hasAttribute("data-phem-bind-list") || (first.name ?? "").endsWith("[]")
    const value = fieldsValue(fields, { list })
    if (typeof value === "boolean") return [value ? "checked" : "unchecked"]
    if (Array.isArray(value)) return value

    return value === null || value === "" ? [] : [value]
  }

  // The attributes of the state: the open state of a panel, `data-field-value` on the controller
  // element, and `disabled` and its mark on a control that the controller disabled.
  #owns(el, name) {
    if (el === this.element) return name === "data-field-value"
    if (this.panelTargets.includes(el) && OPEN_ATTRIBUTES.has(name)) return true

    return (name === "disabled" || name === DISABLED) && el.hasAttribute(DISABLED)
  }
}

// Whether a panel shows for the values of the field.
function matches(panel, values) {
  const words = (panel.getAttribute("data-phem-depends-match") ?? "").split(/\s+/).filter(Boolean)
  if (words.length === 0) return values.length > 0 && !(values.length === 1 && values[0] === "unchecked")

  return words.some((word) => values.includes(word))
}
