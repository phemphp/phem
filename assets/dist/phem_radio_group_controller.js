import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { nextItem } from "./phem_roving.js"
import { hide, render, show } from "./phem_transition.js"

// The attributes of the checked state of a radio and of its indicator.
const CHECKED_ATTRIBUTES = new Set(["aria-checked", "tabindex", "data-checked", "data-unchecked"])

// The `phem-radio-group` controller: a group of radio buttons with one checked radio, on elements
// that are not native radio inputs.
//
//     <div data-controller="phem-radio-group" data-phem-radio-group-value-value="fuji" aria-label="Apple">
//         <label>
//             <span data-phem-radio-group-target="radio" data-value="fuji">
//                 <span data-phem-radio-group-target="indicator"></span>
//             </span>
//             Fuji
//         </label>
//         <label>
//             <span data-phem-radio-group-target="radio" data-value="gala">
//                 <span data-phem-radio-group-target="indicator"></span>
//             </span>
//             Gala
//         </label>
//         <input type="hidden" name="apple" data-phem-radio-group-target="input">
//     </div>
//
// The value of a radio is its `data-value` attribute, its key (`data-phem-key`), or its index. The
// `value` value is the state: the value of the checked radio. With no `value` value, no radio is
// checked. The server render gives its first value, and a server render with a new value sets it.
//
// A click on a radio, or on a `<label>` around it, checks it. The label also names the radio.
// Space checks the radio with the focus. The arrow keys move the focus to the next or to the
// previous radio, and check it (Down and Right go to the next radio, Up and Left to the previous
// one, and the last radio goes to the first one). The arrow keys skip a disabled radio. Only one
// radio is in the tab order: the checked radio, else the first radio that is not disabled.
//
// With the `disabled` value, or on a radio with `disabled` or `aria-disabled="true"`, a click does
// nothing. With the `readOnly` value, the arrow keys move the focus but do not check a radio. The
// `required` value gives the group `aria-required`.
//
// The group gets `role="radiogroup"`, and `aria-disabled`, `aria-readonly`, `aria-required`,
// `data-disabled`, `data-readonly` and `data-required` for these values. A radio gets
// `role="radio"`, `aria-checked`, `tabindex`, and `data-checked` or `data-unchecked`, with the
// `data-disabled`, `data-readonly` and `data-required` attributes of the group. An `indicator`
// target in a radio gets the same data attributes, and shows only when its radio is checked; see
// phem_transition.js for its animations.
//
// An `input` target keeps the value for a form: a hidden input gets the value of the checked
// radio, and a native radio input is checked when its `value` is the value of the checked radio.
// When the user checks a radio, the input that changes sends `input` and `change` events, and the
// controller dispatches `phem-radio-group:change` with the `value` in its detail.
export default class PhemRadioGroupController extends Controller {
  static targets = ["radio", "indicator", "input"]
  static values = {
    value: String,
    disabled: Boolean,
    readOnly: Boolean,
    required: Boolean,
  }

  connect() {
    // radio => whether the server render gives it `aria-disabled="true"`.
    this.given = new WeakMap()
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render({ animate: false }),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)

    // The morph sets the `aria-disabled` of the server render again.
    this.element.addEventListener("turbo:morph-element", (event) => this.given.delete(event.target), options)

    this.#render({ animate: false })
    this.#sync()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.rendered = undefined
  }

  // A server render with a new `value` value, or a change of the value by a script.
  valueValueChanged() {
    if (this.rendered === undefined || this.rendered === this.#checked) return

    this.#render({ animate: true })
    this.#sync()
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

  // A target that the morph adds gets the state.
  radioTargetConnected() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  indicatorTargetConnected() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  inputTargetConnected() {
    if (this.rendered !== undefined) this.#sync()
  }

  onClick = (event) => {
    const radio = this.#radioOf(event.target) ?? this.#labelled(event.target)
    if (!radio) return

    // A label does not click the input in it, and a button does not send its form.
    event.preventDefault()
    if (this.#disabled(radio)) return

    if (document.activeElement !== radio) radio.focus()
    if (!this.readOnlyValue) this.#check(radio)
  }

  onKeydown = (event) => {
    const radio = this.#radioOf(event.target)
    if (!radio || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return

    if (event.key === " ") {
      event.preventDefault()
      if (!this.#disabled(radio) && !this.readOnlyValue) this.#check(radio)

      return
    }

    const orientation = event.key === "ArrowLeft" || event.key === "ArrowRight" ? "horizontal" : "vertical"
    if (!["ArrowDown", "ArrowUp", "ArrowLeft", "ArrowRight"].includes(event.key)) return

    const enabled = this.radioTargets.filter((el) => !this.#disabled(el))
    const next = nextItem(enabled, radio, event.key, { orientation, loop: true, rtl: this.#rtl })
    event.preventDefault()
    if (!next) return

    next.focus()
    if (!this.readOnlyValue) this.#check(next)
  }

  #check(radio) {
    const index = this.radioTargets.indexOf(radio)
    if (index === this.#checked) return

    const value = valueOf(radio, index)
    this.valueValue = value
    this.#render({ animate: true })
    this.#sync({ events: true })
    this.dispatch("change", { detail: { value } })
  }

  // The index of the checked radio, or -1.
  get #checked() {
    if (!this.hasValueValue) return -1

    return this.radioTargets.findIndex((radio, i) => valueOf(radio, i) === this.valueValue)
  }

  #render({ animate }) {
    const radios = this.radioTargets
    const checked = this.#checked
    this.rendered = checked

    this.element.setAttribute("role", "radiogroup")
    flag(this.element, "aria-disabled", this.disabledValue)
    flag(this.element, "aria-readonly", this.readOnlyValue)
    flag(this.element, "aria-required", this.requiredValue)
    this.#common(this.element, this.disabledValue)

    // The tab stop: the checked radio, else the first radio that is not disabled.
    const enabled = radios.filter((radio) => !this.#disabled(radio))
    const stop = enabled.includes(radios[checked]) ? radios[checked] : enabled[0]

    radios.forEach((radio, i) => {
      const disabled = this.#disabled(radio)
      radio.setAttribute("role", "radio")
      radio.setAttribute("aria-checked", String(i === checked))
      radio.tabIndex = radio === stop ? 0 : -1
      if (disabled) radio.setAttribute("aria-disabled", "true")
      else if (!this.#givenDisabled(radio)) radio.removeAttribute("aria-disabled")
      this.#common(radio, disabled)
      checkedState(radio, i === checked)
      this.#name(radio)
    })

    for (const indicator of this.indicatorTargets) {
      const radio = radios.find((el) => el.contains(indicator))
      const open = radio !== undefined && radios.indexOf(radio) === checked
      this.#common(indicator, radio !== undefined && this.#disabled(radio))
      checkedState(indicator, open)

      if (!animate) render(indicator, open)
      else if (open) show(indicator)
      else if (!indicator.hidden) hide(indicator)
    }
  }

  // A `<label>` names the radio in it: a label names only a native control. The controller links
  // them with an element reference, and gives them no id. A name that the markup gives stays.
  #name(radio) {
    const label = radio.closest("label")
    if (!label || !this.element.contains(label) || radio.hasAttribute("aria-label")) return
    if (radio.getAttribute("aria-labelledby") || !("ariaLabelledByElements" in radio)) return

    radio.ariaLabelledByElements = [label]
  }

  // The data attributes of the values of the group.
  #common(el, disabled) {
    el.toggleAttribute("data-disabled", disabled)
    el.toggleAttribute("data-readonly", this.readOnlyValue)
    el.toggleAttribute("data-required", this.requiredValue)
  }

  // Gives the inputs the value of the checked radio. With `events`, an input that changes sends
  // `input` and `change`.
  #sync({ events = false } = {}) {
    const checked = this.#checked
    const value = checked === -1 ? "" : valueOf(this.radioTargets[checked], checked)

    for (const input of this.inputTargets) {
      let changed

      if (input.type === "radio") {
        const on = checked !== -1 && input.value === value
        changed = on && !input.checked
        input.checked = on
      } else {
        changed = input.value !== value
        input.value = value
      }

      if (events && changed) {
        input.dispatchEvent(new Event("input", { bubbles: true }))
        input.dispatchEvent(new Event("change", { bubbles: true }))
      }
    }
  }

  #disabled(radio) {
    // It reads the server render first: the controller can add `aria-disabled` after.
    return this.#givenDisabled(radio) || this.disabledValue || radio.hasAttribute("disabled")
  }

  #givenDisabled(radio) {
    if (!this.given.has(radio)) this.given.set(radio, radio.getAttribute("aria-disabled") === "true")

    return this.given.get(radio)
  }

  // The attributes of the state.
  #owns(el, name) {
    if (this.radioTargets.includes(el)) return CHECKED_ATTRIBUTES.has(name)
    if (this.indicatorTargets.includes(el)) return OPEN_ATTRIBUTES.has(name) || CHECKED_ATTRIBUTES.has(name)
    if (this.inputTargets.includes(el)) return name === "value" || name === "checked"

    return false
  }

  #radioOf(el) {
    return this.radioTargets.find((radio) => radio.contains(el))
  }

  // The radio of the `<label>` of `el`: the radio in the label, or the radio of its `for`.
  #labelled(el) {
    const label = el instanceof Element ? el.closest("label") : null
    if (!label || !this.element.contains(label)) return undefined

    return this.radioTargets.find((radio) => label.contains(radio) || (label.htmlFor !== "" && label.htmlFor === radio.id))
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }
}

// The value of a radio: its `data-value`, its key, or its index.
function valueOf(el, index) {
  return el.getAttribute("data-value") ?? el.getAttribute("data-phem-key") ?? String(index)
}

function flag(el, name, on) {
  if (on) el.setAttribute(name, "true")
  else el.removeAttribute(name)
}

function checkedState(el, checked) {
  el.toggleAttribute("data-checked", checked)
  el.toggleAttribute("data-unchecked", !checked)
}
