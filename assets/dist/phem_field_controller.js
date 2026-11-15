import { Controller } from "@hotwired/stimulus"
import { link } from "./phem_aria.js"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { realId } from "./phem_ids.js"
import { hide, render, show } from "./phem_transition.js"
import { isDirty, isDisabledControl, isFilled, isNative, validityOf } from "./phem_validation.js"

// The `phem-field` controller: a label, a description and the errors of a form control, with the
// constraint validation of the browser. The state of the field stays after the morph of an event.
//
//     <div data-controller="phem-field">
//         <label data-phem-field-target="label">Name</label>
//         <input name="name" required data-phem-field-target="control" />
//         <p data-phem-field-target="description">Visible on your profile</p>
//         <p data-phem-field-target="error" data-match="valueMissing" hidden>Enter your name.</p>
//         <p data-phem-field-target="error" hidden></p>
//     </div>
//
// The `control` target is an `<input>`, a `<select>`, a `<textarea>` or a custom control. A field
// can have more than one control (for example the slots of `phem-otp-field`): the field is
// invalid when one of them is invalid. The controller reads the validity of the browser
// (`validity`, `validationMessage`) of the controls. A custom control with no `validity` is valid.
//
// The `validationMode` value sets when the field validates: `onSubmit` (the default) when the
// `phem-form` around it submits, and on each change after this submit; `onBlur` when the control
// loses the focus; `onChange` on each change, after `validationDebounceTime` milliseconds (0 by
// default). With no `validationMode` value, the field uses the `validationMode` value of the
// `phem-form` around it. In all the modes, an invalid field validates again on each change, and
// Enter in an `<input>` control validates the field (in a `phem-form`, the submit does it).
//
// An `error` target with `data-match="<key>"` (a key of `ValidityState`, such as `valueMissing`,
// `typeMismatch` or `customError`) shows when the validation finds this error. With
// `data-match="valid"` it shows when the field is valid, and with `data-match="true"` it always
// shows. An `error` target with no `data-match` shows for all the errors. When it has no text in
// the markup, it gets the `validationMessage` of the control. The errors show and hide with the
// open and the close attributes of phem_transition.js (`data-open`, `data-starting-style`…).
//
// A server error (see docs/forms.md) is a control that the server renders with
// `aria-invalid="true"`, as `phem:bind` and the Symfony form themes do. The field is invalid until
// the user changes the value of this control: a `phem-form` does not submit it, and the control
// gets the message as its custom validity. An `error` target whose id is in the `aria-describedby`
// of the server render (the element of `phem:error`) is the message of the server: it shows while
// the server error stays.
//
// The label links to the control: a `<label>` gets `for` when the control has an id, else the
// control gets `aria-labelledby` (an element reference when the label has no id). A click on a
// label that is not a native label of the control focuses the control. The control gets
// `aria-describedby` with the descriptions and the visible errors, after the ids of the server
// render, and `aria-invalid="true"` when the field is invalid. The `disabled` value disables the
// controls.
//
// All the parts (the controller element, the labels, the controls, the descriptions and the
// errors) get `data-disabled`, `data-valid` or `data-invalid` (after a validation), `data-dirty`
// (the value is not the value of the server render), `data-touched` (the control lost the focus
// one time), `data-filled` (the control has a value) and `data-focused`.
//
// A `<fieldset>` with a `<legend>` (Base UI Fieldset) needs no controller: a `disabled` fieldset
// disables its controls, and the fields in it get `data-disabled`.
export default class PhemFieldController extends Controller {
  static targets = ["label", "control", "description", "error"]
  static values = {
    validationMode: String,
    validationDebounceTime: Number,
    disabled: Boolean,
  }

  connect() {
    this.valid = null
    this.keys = new Set()
    this.message = ""
    this.submitted = false
    this.touched = false
    this.focused = this.controlTargets.some((el) => el.contains(document.activeElement))
    this.custom = new Set()
    this.disabledByField = new Set()

    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render({ animate: false }),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("focusin", this.onFocusin, options)
    this.element.addEventListener("focusout", this.onFocusout, options)
    this.element.addEventListener("input", this.onChange, options)
    this.element.addEventListener("change", this.onChange, options)
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("invalid", this.onInvalid, { ...options, capture: true })
    this.element.addEventListener("turbo:before-morph-element", this.onBeforeMorph, options)

    for (const el of this.controlTargets) readControl(el)
    for (const el of this.errorTargets) readError(el)

    this.rendered = true
    this.#render({ animate: false })
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    clearTimeout(this.timer)
    this.rendered = false
  }

  // Validates the field and shows its errors. Returns whether it is valid. `submit` is the submit
  // of the form (`phem-form`): in the `onSubmit` mode, the field then validates on each change.
  validate({ submit = false } = {}) {
    clearTimeout(this.timer)
    if (submit) this.submitted = true

    const server = this.#serverError
    this.#syncCustomValidity(server)

    const result = validityOf(this.controlTargets)
    // The custom validity of a server error is not an error of the client.
    if (server) result.keys.delete("customError")

    this.keys = result.keys
    this.valid = server ? result.keys.size === 0 : result.valid
    this.message = result.message
    this.invalidControl = result.control ?? (server ? this.controlTargets.find((el) => serverOf.get(el)?.invalid) : null) ?? null
    this.#render({ animate: true })

    return !server && this.valid
  }

  disabledValueChanged() {
    if (this.rendered) this.#render({ animate: false })
  }

  // A target that the morph adds gets the state.
  controlTargetConnected(el) {
    readControl(el)
    if (this.rendered) this.#render({ animate: false })
  }

  errorTargetConnected(el) {
    readError(el)
    if (this.rendered) this.#render({ animate: false })
  }

  labelTargetConnected() {
    if (this.rendered) this.#render({ animate: false })
  }

  descriptionTargetConnected() {
    if (this.rendered) this.#render({ animate: false })
  }

  // The server render of a control or of an error, before the morph changes it.
  onBeforeMorph = (event) => {
    const next = event.detail?.newElement
    if (!next) return

    if (this.controlTargets.includes(event.target)) serverOf.set(event.target, serverControl(next))
    else if (this.errorTargets.includes(event.target)) serverTextOf.set(event.target, next.textContent.trim())
  }

  onFocusin = (event) => {
    if (!this.#controlOf(event.target) || this.focused) return

    this.focused = true
    this.#render({ animate: false })
  }

  // The focus that goes to another control of the field (a slot of `phem-otp-field`) stays in the
  // field.
  onFocusout = (event) => {
    if (!this.#controlOf(event.target) || this.#controlOf(event.relatedTarget)) return

    this.focused = false
    this.touched = true
    if (this.#mode === "onBlur") this.validate()
    else this.#render({ animate: false })
  }

  // A change of the user ends the server error of the control.
  onChange = (event) => {
    const control = this.#controlOf(event.target)
    if (!control) return

    const server = serverOf.get(control)
    if (server) server.invalid = false

    if (this.#mode === "onChange") this.#later()
    else if (this.submitted || this.valid === false) this.validate()
    else this.#render({ animate: true })
  }

  // Enter in an `<input>` control validates the field, in all the modes: the user ends the value.
  // In a `phem-form`, Enter submits the form, and the submit validates the field.
  onKeydown = (event) => {
    if (event.key !== "Enter" || event.defaultPrevented || event.isComposing) return
    if (event.target.tagName !== "INPUT" || !this.controlTargets.includes(event.target)) return

    this.touched = true
    if (event.target.form?.matches("[data-controller~='phem-form']")) return

    this.validate()
  }

  // The interactive validation of a form with no `phem-form` (no `novalidate`).
  onInvalid = (event) => {
    if (!this.#controlOf(event.target)) return

    this.submitted = true
    this.validate()
  }

  onClick = (event) => {
    const label = this.labelTargets.find((el) => el.contains(event.target))
    const [control] = this.controlTargets
    if (!label || !control || isDisabledControl(control)) return
    if (label instanceof HTMLLabelElement && label.control) return
    if (event.target.closest("a, button, input, select, textarea, [contenteditable]")) return

    control.focus()
  }

  #later() {
    clearTimeout(this.timer)
    const wait = this.validationDebounceTimeValue
    if (wait <= 0) {
      this.validate()

      return
    }

    this.#render({ animate: false })
    this.timer = setTimeout(() => this.validate(), wait)
  }

  // The mode of the field, else of the form around it.
  get #mode() {
    if (this.validationModeValue) return this.validationModeValue

    const form = this.element.closest("[data-phem-form-validation-mode-value]")

    return form?.getAttribute("data-phem-form-validation-mode-value") || "onSubmit"
  }

  // Whether a control has the error of the server render, and the user did not change it.
  get #serverError() {
    return this.controlTargets.some((el) => serverOf.get(el)?.invalid)
  }

  // The `error` targets whose id is in the `aria-describedby` of the server render of a control.
  get #serverParts() {
    const ids = new Set(this.controlTargets.flatMap((el) => idsOf(serverOf.get(el)?.describedBy)))

    return this.errorTargets.filter((el) => el.id && ids.has(el.id))
  }

  // The message of the server: the text of its `error` targets, else the text of the elements of
  // the `aria-describedby` of the server render that are not descriptions.
  #serverMessage(parts) {
    const text = (els) => els.map((el) => el.textContent.trim()).filter(Boolean).join(" ")
    const given = text(parts)
    if (given) return given

    const ids = this.controlTargets.flatMap((el) => idsOf(serverOf.get(el)?.describedBy))
    const described = ids.map((id) => document.getElementById(id)).filter((el) => el && !this.descriptionTargets.includes(el))

    return text(described) || "This value is not valid."
  }

  // The controls with a server error get its message as their custom validity, so a form does not
  // submit them.
  #syncCustomValidity(server) {
    const message = server ? this.#serverMessage(this.#serverParts) : ""

    for (const el of this.controlTargets) {
      if (typeof el.setCustomValidity !== "function") continue

      if (serverOf.get(el)?.invalid) {
        el.setCustomValidity(message)
        this.custom.add(el)
      } else if (this.custom.delete(el)) el.setCustomValidity("")
    }
  }

  #render({ animate }) {
    const controls = this.controlTargets
    const server = this.#serverError
    this.#syncCustomValidity(server)
    this.#disable(controls)

    const invalid = server || this.valid === false
    const state = {
      "data-disabled": this.disabledValue || (controls.length > 0 && controls.every(isDisabledControl)),
      "data-valid": !server && this.valid === true,
      "data-invalid": invalid,
      "data-dirty": controls.some(isDirty),
      "data-touched": this.touched,
      "data-filled": controls.some(isFilled),
      "data-focused": this.focused,
    }

    for (const el of this.#parts) {
      for (const [name, on] of Object.entries(state)) el.toggleAttribute(name, on)
    }

    const serverParts = this.#serverParts
    const shown = new Set(this.errorTargets.filter((el) => this.#shows(el, server, serverParts)))
    const message = server ? this.#serverMessage(serverParts) : this.message

    for (const el of this.errorTargets) {
      const open = shown.has(el)
      if (open && !serverParts.includes(el) && !serverTextOf.get(el)) el.textContent = message

      if (!animate) render(el, open)
      else if (open) show(el)
      else if (!el.hidden) hide(el)
    }

    for (const el of controls) {
      if (invalid) el.setAttribute("aria-invalid", "true")
      else el.removeAttribute("aria-invalid")

      this.#describe(el, serverParts, shown)
    }

    if (controls[0]) {
      for (const label of this.labelTargets) linkLabel(controls[0], label)
    }
  }

  // Whether an error shows.
  #shows(el, server, serverParts) {
    if (serverParts.includes(el)) return server

    const match = el.getAttribute("data-match")
    if (match === "true") return true
    if (match === "valid") return !server && this.valid === true
    if (match) return this.valid === false && this.keys.has(match)
    if (server) return serverParts.length === 0

    return this.valid === false
  }

  // The `disabled` value disables the native controls. A control that the field disabled gets its
  // state again when the value is false.
  #disable(controls) {
    for (const el of controls) {
      if (!isNative(el)) continue

      if (this.disabledValue && !el.disabled) {
        el.disabled = true
        this.disabledByField.add(el)
      } else if (!this.disabledValue && this.disabledByField.delete(el)) el.disabled = false
    }
  }

  // `aria-describedby`: the ids of the server render (without a message of the server that is
  // hidden), then the descriptions and the visible errors. With an id on each element, the
  // attribute has the ids. Else the control gets element references.
  #describe(control, serverParts, shown) {
    const items = []
    for (const id of idsOf(serverOf.get(control)?.describedBy)) {
      const el = document.getElementById(id)
      if (el && serverParts.includes(el) && !shown.has(el)) continue

      items.push(el ?? id)
    }

    const parts = [...this.descriptionTargets, ...this.errorTargets.filter((el) => shown.has(el) && !serverParts.includes(el))]
    for (const el of parts) {
      if (!items.includes(el)) items.push(el)
    }

    if (items.every((item) => typeof item === "string" || item.id)) {
      const ids = items.map((item) => (typeof item === "string" ? item : item.id))
      if (ids.length) control.setAttribute("aria-describedby", ids.join(" "))
      else control.removeAttribute("aria-describedby")

      return
    }

    if ("ariaDescribedByElements" in control) control.ariaDescribedByElements = items.filter((item) => typeof item !== "string")
  }

  // The attributes of the state.
  #owns(el, name) {
    if (STATE_ATTRIBUTES.has(name)) return this.#parts.includes(el)
    if (this.errorTargets.includes(el)) return OPEN_ATTRIBUTES.has(name)

    return false
  }

  get #parts() {
    return [this.element, ...this.labelTargets, ...this.controlTargets, ...this.descriptionTargets, ...this.errorTargets]
  }

  #controlOf(el) {
    if (!(el instanceof Node)) return null

    return this.controlTargets.find((control) => control.contains(el)) ?? null
  }
}

// The attributes of the state of the parts.
const STATE_ATTRIBUTES = new Set(["data-disabled", "data-valid", "data-invalid", "data-dirty", "data-touched", "data-filled", "data-focused"])

// control => its server render: `invalid` (`aria-invalid="true"`, until the user changes the
// value) and `describedBy`. error => the text of its server render.
const serverOf = new WeakMap()
const serverTextOf = new WeakMap()

function serverControl(el) {
  return { invalid: el.getAttribute("aria-invalid") === "true", describedBy: el.getAttribute("aria-describedby") }
}

// The first server render of a control or of an error. A morph updates it (see onBeforeMorph).
function readControl(el) {
  if (!serverOf.has(el)) serverOf.set(el, serverControl(el))
}

function readError(el) {
  if (!serverTextOf.has(el)) serverTextOf.set(el, el.textContent.trim())
}

function idsOf(value) {
  return (value ?? "").split(/\s+/).filter(Boolean)
}

// The label of the control: `for` on a `<label>` when the control has an id, else
// `aria-labelledby` on the control. A label that the markup gives stays. A temporary id of the
// morph (phem_ids.js) is no id: the label must not point to it.
function linkLabel(control, label) {
  if (label instanceof HTMLLabelElement) {
    if (label.htmlFor || label.contains(control)) return

    const id = realId(control)
    if (id) {
      label.htmlFor = id

      return
    }
  }

  if (control.hasAttribute("aria-label")) return

  link(control, "aria-labelledby", "ariaLabelledByElements", label)
}
