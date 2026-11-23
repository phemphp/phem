import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { link } from "./phem_aria.js"
import { hide, render, show } from "./phem_transition.js"
import { linkInput, requiredMessage, unlinkInput } from "./phem_validation.js"

// The attributes of the pressed or checked state.
const STATE_ATTRIBUTES = new Set(["aria-pressed", "aria-checked", "data-pressed", "data-checked", "data-unchecked", "data-indeterminate"])

// The `phem-toggle` controller: a toggle button, a switch or a checkbox. Its state is on the
// client, and it stays after the morph of an event.
//
//     <button type="button" data-controller="phem-toggle" aria-label="Bold">B</button>
//
//     <label>
//         <span role="switch" data-controller="phem-toggle" data-phem-toggle-checked-value="true">
//             <span data-phem-toggle-target="indicator"></span>
//             <input type="checkbox" name="notifications" hidden data-phem-toggle-target="input">
//         </span>
//         Notifications
//     </label>
//
//     <label>
//         <span role="checkbox" data-controller="phem-toggle" data-phem-toggle-name-value="terms">
//             <span data-phem-toggle-target="indicator">✓</span>
//             <input type="checkbox" hidden data-phem-toggle-target="input">
//         </span>
//         I accept the terms
//     </label>
//
// The role of the element gives its kind. An element with `role="switch"` or `role="checkbox"`
// is a switch or a checkbox: it gets `aria-checked`, and `data-checked` or `data-unchecked`.
// Another element (a `<button>` with no role) is a toggle button: it gets `aria-pressed`, and
// `data-pressed` when it is pressed. An element that is not a `<button>` gets `role="button"` when
// it has no role, and `tabindex="0"` when it has no `tabindex`.
//
// The `checked` value is the state (a pressed toggle button is checked). With no `checked` value,
// the first state comes from the `checked` attribute of the input, or from `aria-checked` or
// `aria-pressed`. The server render gives its first value, and a server render with a new
// `checked` value sets it. A click toggles the state, and so does a click on a `<label>` around
// the element or a `<label for>` the input. Space and Enter toggle a toggle button and a switch.
// Only Space toggles a checkbox.
// A checkbox with the `indeterminate` value is in a mixed state (`aria-checked="mixed"`,
// `data-indeterminate`): a click checks it.
//
// A `<label>` around an element that is not a `<button>` also names it: the element gets
// `aria-labelledby` (an element reference when the label has no id). An `aria-label` or an
// `aria-labelledby` of the markup stays. The indicators of a switch and of a checkbox get
// `aria-hidden="true"` (when they have no `aria-hidden`), so the name has no check mark.
//
// The `disabled` value (or the `disabled` attribute of a `<button>`) stops the changes by the
// user, and gives `data-disabled` (and `aria-disabled="true"` for the value). The `readOnly` value
// also stops them, and gives `data-readonly` and `aria-readonly="true"`. The `required` value
// gives `data-required` and `aria-required="true"`.
//
// The `input` target (or the first checkbox in the element) is the field of a form. A checkbox
// input gets the state in `checked`. An `<input type="hidden">` gets the `value` value (`on` by
// default) when it is checked, and the `uncheckedValue` value when it is not checked; with no
// `uncheckedValue`, it is disabled, so a form does not send it. The `name`, `value`, `form`,
// `required` and `disabled` values go on the input. A required checkbox input that is not checked
// stops the submit of its form. The browser does not validate an `<input type="hidden">`: for it,
// the controller gives `phem-form` and `phem-field` a `valueMissing` error while the toggle is
// required and not checked, so they stop the submit (a form with no `phem-form` sends it). The input is linked
// to the element (see linkInput() in phem_validation.js): `phem-form` and `phem-field` give the
// focus to the element, not to the hidden input. When the user changes the state, the input
// dispatches `input` and `change`, so `phem:bind` and the other controllers see the change. An
// input with `phem:bind` belongs to phem: after the morph of an event, its value (the `checked`
// of a checkbox, or the `value` of a hidden input) gives the state.
//
// The `indicator` targets (the check mark of a checkbox, the thumb of a switch) get the same data
// attributes. The indicator of a checkbox shows only when the checkbox is checked or
// indeterminate, with the open and the close animations of phem_transition.js
// (`data-starting-style`, `data-ending-style`). With the `keepMounted` value, it does not get
// `hidden`.
//
// Each change by the user dispatches `phem-toggle:change` with `{ checked, indeterminate, value }`.
export default class PhemToggleController extends Controller {
  static targets = ["indicator", "input"]
  static values = {
    checked: Boolean,
    indeterminate: Boolean,
    disabled: Boolean,
    readOnly: Boolean,
    required: Boolean,
    name: String,
    value: String,
    uncheckedValue: String,
    form: String,
    keepMounted: Boolean,
  }

  connect() {
    this.checkedState = new ClientState(this, {
      value: "checked",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#morphed(),
    })
    this.checkedState.connect()

    // The server render can also give a new `indeterminate` value.
    this.indeterminateState = new ClientState(this, { value: "indeterminate" })
    this.indeterminateState.connect()

    if (!this.hasCheckedValue) this.checkedValue = this.#initial

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    document.addEventListener("click", this.onLabelClick, options)

    this.#render({ animate: false })
  }

  disconnect() {
    this.checkedState.disconnect()
    this.indeterminateState.disconnect()
    this.listening.abort()
    this.rendered = undefined
    if (this.linked) unlinkInput(this.linked, this.element)
    this.linked = null
  }

  // Whether the element is checked or pressed.
  get checked() {
    return this.checkedValue
  }

  get indeterminate() {
    return this.#kind === "checkbox" && this.indeterminateValue
  }

  get disabled() {
    return this.disabledValue || this.element.hasAttribute("disabled")
  }

  get readOnly() {
    return this.readOnlyValue
  }

  // Toggles the state, as a click does. A disabled or read-only element does not change.
  toggle(event) {
    if (this.disabled || this.readOnly) return

    this.setChecked(!this.checkedValue, { event })
  }

  // Sets the state. With `notify` (the default), a change dispatches the events of a change by the
  // user.
  setChecked(checked, { indeterminate = false, notify = true, event = null } = {}) {
    this.#set(checked, indeterminate, { notify, event })
  }

  // A server render with a new value, or a change of a value by a script.
  checkedValueChanged() {
    this.#rerender()
  }

  indeterminateValueChanged() {
    this.#rerender()
  }

  disabledValueChanged() {
    this.#rerender()
  }

  readOnlyValueChanged() {
    this.#rerender()
  }

  requiredValueChanged() {
    this.#rerender()
  }

  indicatorTargetConnected() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  inputTargetConnected() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  onClick = (event) => {
    const input = this.#input

    // A click on the input comes from a label: the browser toggles the input.
    if (input && event.target === input) {
      this.#inputClicked(event)

      return
    }

    // Stop the click of a label around the element: it clicks the input again.
    if (this.element.closest("label")) event.preventDefault()

    this.toggle(event)
  }

  onKeydown = (event) => {
    if (event.target !== this.element || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return

    // A button toggles on its click.
    if (this.element.matches("button")) return

    // Enter toggles a toggle button and a switch, as a button. Only Space toggles a checkbox.
    if (event.key === " " || (event.key === "Enter" && this.#kind !== "checkbox")) {
      event.preventDefault()
      this.element.click()
    }
  }

  // A click on a `<label>` of the element with no control that the browser clicks: a label around
  // the element with no checkbox input, or a `<label for>` an `<input type="hidden">`.
  onLabelClick = (event) => {
    const label = event.target.closest?.("label")
    if (!label || event.defaultPrevented || this.element.contains(event.target)) return

    // The browser clicks the control of the label.
    if (label.control) return

    const input = this.#input
    const ours = label.contains(this.element) || (label.htmlFor !== "" && (label.htmlFor === this.element.id || label.htmlFor === input?.id))
    if (!ours) return

    this.toggle(event)
    this.element.focus()
  }

  // The browser toggled the checkbox input: the state follows it.
  #inputClicked(event) {
    const input = this.#input
    if (this.disabled || this.readOnly) {
      event.preventDefault()

      return
    }

    // The browser dispatches `input` and `change` on the checkbox after the click.
    if (input.type === "checkbox") this.#set(input.checked, false, { event, inputEvents: false })
    else this.toggle(event)

    this.element.focus()
  }

  #set(checked, indeterminate, { notify = true, event = null, inputEvents = true } = {}) {
    const changed = checked !== this.checkedValue || indeterminate !== this.indeterminateValue
    this.checkedValue = checked
    this.indeterminateValue = indeterminate
    this.#render({ animate: true })

    if (changed && notify) this.#notify(event, inputEvents)
  }

  #notify(event, inputEvents) {
    const input = this.#input
    if (input && inputEvents) {
      input.dispatchEvent(new Event("input", { bubbles: true }))
      input.dispatchEvent(new Event("change", { bubbles: true }))
    }

    const value = input ? (input.disabled ? null : input.value) : (this.hasValueValue ? this.valueValue : null)
    this.dispatch("change", { detail: { checked: this.checkedValue, indeterminate: this.indeterminate, value, event } })
  }

  #rerender() {
    if (this.rendered === undefined) return

    // A value that the controller already rendered (Stimulus calls the callback after a change
    // by the controller): the indicator keeps its running animation.
    const changed = this.rendered.checked !== this.checkedValue || this.rendered.indeterminate !== this.indeterminate
    this.#render({ animate: changed, indicator: changed })
  }

  // After the morph. An input with `phem:bind` gives its value: the server sets it.
  #morphed() {
    const input = this.#input
    if (input?.hasAttribute("data-phem-bind")) {
      const checked = input.type === "hidden" ? input.value === (this.hasValueValue ? this.valueValue : "on") : input.checked
      if (checked !== this.checkedValue) this.checkedValue = checked
    }

    this.#render({ animate: false })
  }

  #render({ animate, indicator: openIndicator = true }) {
    const el = this.element
    const kind = this.#kind
    const checked = this.checkedValue
    const indeterminate = this.indeterminate
    const disabled = this.disabled
    const previous = this.rendered
    this.rendered = { checked, indeterminate }

    if (!el.matches("button")) {
      if (!el.hasAttribute("role")) el.setAttribute("role", "button")
      if (!el.hasAttribute("tabindex")) el.tabIndex = 0
      this.#name()
    }

    if (kind === "button") {
      el.setAttribute("aria-pressed", String(checked))
    } else {
      el.setAttribute("aria-checked", indeterminate ? "mixed" : String(checked))
      flag(el, "aria-readonly", this.readOnlyValue)
      flag(el, "aria-required", this.#required)
    }

    // An `aria-disabled` and a `data-disabled` that the controller did not give stay (for example
    // the `data-disabled` of a disabled `phem-toggle-group`).
    if (this.disabledValue) el.setAttribute("aria-disabled", "true")
    else if (this.ariaDisabled) el.removeAttribute("aria-disabled")
    this.ariaDisabled = this.disabledValue

    for (const part of [el, ...this.indicatorTargets]) {
      this.#attributes(part, kind, checked, indeterminate)
      if (disabled) part.setAttribute("data-disabled", "")
      else if (this.dataDisabled) part.removeAttribute("data-disabled")
    }
    this.dataDisabled = disabled

    // The check mark or the thumb is not in the name from the label: `aria-checked` gives the
    // state. The indicator of a toggle button can be its only text, so it stays.
    if (kind !== "button") {
      for (const indicator of this.indicatorTargets) {
        if (!indicator.hasAttribute("aria-hidden")) indicator.setAttribute("aria-hidden", "true")
      }
    }

    this.#renderInput(checked, indeterminate, disabled)

    // The indicator of a checkbox shows when the checkbox is checked or indeterminate.
    if (kind !== "checkbox" || !openIndicator) return

    const open = checked || indeterminate
    const hidden = !this.keepMountedValue
    for (const indicator of this.indicatorTargets) {
      if (!animate || previous === undefined) render(indicator, open, { hidden })
      else if (open) show(indicator, { hidden })
      else hide(indicator, { hidden })
    }
  }

  #attributes(part, kind, checked, indeterminate) {
    if (kind === "button") {
      part.toggleAttribute("data-pressed", checked)
    } else {
      part.toggleAttribute("data-checked", checked && !indeterminate)
      part.toggleAttribute("data-unchecked", !checked && !indeterminate)
      part.toggleAttribute("data-indeterminate", indeterminate)
    }

    part.toggleAttribute("data-readonly", this.readOnlyValue)
    part.toggleAttribute("data-required", this.#required)
  }

  #renderInput(checked, indeterminate, disabled) {
    const input = this.#input
    if (input !== this.linked) {
      if (this.linked) unlinkInput(this.linked, this.element)
      if (input) linkInput(input, this.element, { validity: () => this.#hiddenValidity(input) })
      this.linked = input
    }

    if (!input) return

    if (this.hasNameValue) input.name = this.nameValue
    if (this.hasFormValue) input.setAttribute("form", this.formValue)
    if (!input.hasAttribute("tabindex")) input.tabIndex = -1
    if (!input.hasAttribute("aria-hidden")) input.setAttribute("aria-hidden", "true")

    if (input.type === "hidden") {
      input.value = checked ? (this.hasValueValue ? this.valueValue : "on") : this.uncheckedValueValue
      input.disabled = disabled || (!checked && !this.hasUncheckedValueValue)

      return
    }

    input.checked = checked
    input.indeterminate = indeterminate
    if (this.hasValueValue) input.value = this.valueValue
    if (this.hasRequiredValue) input.required = this.requiredValue
    if (this.hasDisabledValue) input.disabled = disabled
  }

  // A `<label>` around the element names it: a label names only a native control. The controller
  // links them with an element reference, and gives the label no id. A name that the markup gives
  // stays.
  #name() {
    const el = this.element
    const label = el.closest("label")
    if (!label || el.hasAttribute("aria-label")) return

    link(el, "aria-labelledby", "ariaLabelledByElements", label)
  }

  // The attributes of the state. An input with `phem:bind` belongs to phem.
  #owns(el, name) {
    if (el === this.element) return STATE_ATTRIBUTES.has(name)
    if (this.indicatorTargets.includes(el)) return STATE_ATTRIBUTES.has(name) || OPEN_ATTRIBUTES.has(name)
    if (el !== this.#input) return false

    if (el.hasAttribute("data-phem-bind")) return false
    if (el.type === "hidden") return name === "value" || name === "disabled"

    return name === "checked"
  }

  // The first state, when the markup gives no `checked` value.
  get #initial() {
    const input = this.#input
    if (input?.type === "checkbox") return input.checked

    return this.element.getAttribute("aria-checked") === "true" || this.element.getAttribute("aria-pressed") === "true"
  }

  get #kind() {
    const role = this.element.getAttribute("role")

    return role === "switch" || role === "checkbox" ? role : "button"
  }

  get #required() {
    return this.requiredValue || this.#input?.required === true
  }

  // The browser does not validate an `<input type="hidden">`: a required toggle that is not checked
  // gives the `valueMissing` validity of its input itself. A disabled toggle is valid.
  #hiddenValidity(input) {
    if (input.type !== "hidden" || !this.#required || this.checked || this.disabled) return null

    return { valueMissing: true, message: requiredMessage() }
  }

  get #input() {
    if (this.hasInputTarget) return this.inputTarget

    return this.element.querySelector("input[type=checkbox]")
  }
}

function flag(el, name, on) {
  if (on) el.setAttribute(name, "true")
  else el.removeAttribute(name)
}
