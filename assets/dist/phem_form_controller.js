import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { isTabbable } from "./phem_focus.js"
import { firstInDocument } from "./phem_validation.js"

// The `phem-form` controller: validates the `phem-field` controllers of a form before its submit.
//
//     <form onSubmit={{ save }} data-controller="phem-form">
//         <div data-controller="phem-field">
//             <label data-phem-field-target="label">Email</label>
//             <input type="email" name="email" required data-phem-field-target="control" />
//             <p data-phem-field-target="error" hidden></p>
//         </div>
//         <button type="submit">Save</button>
//     </form>
//
// The form gets `novalidate`: the fields show the errors in place of the messages of the
// browser. On a submit, each `phem-field` in the form validates and shows its errors. A control
// that is not in a field validates with the message of the browser (`reportValidity()`). When a
// field or a control is invalid, the controller stops the submit before the `phem` controller
// sends its event (an `onSubmit` handler) or the browser sends the form, focuses the first invalid
// control and selects its text, and sends `phem-form:invalid` with the invalid elements in
// `detail.fields`. A submit button with `formnovalidate` submits with no validation.
//
// A server error (a control that the server renders with `aria-invalid="true"`, see
// docs/forms.md) keeps the form from a submit until the user changes the value of the control.
// After the submit event of the form (an `onSubmit` handler) ends with a new render, the first
// control with a server error gets the focus and its text is selected, as in Base UI. An error of
// the browser, a hidden or disabled control, and a redirect do not count.
//
// The `validationMode` value (`onSubmit`, `onBlur` or `onChange`) is the mode of the fields that
// have no `validationMode` value of their own (see phem_field_controller.js).
export default class PhemFormController extends Controller {
  static values = {
    validationMode: { type: String, default: "onSubmit" },
  }

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => el === this.element && name === "novalidate",
      morphed: () => this.#render(),
    })
    this.state.connect()

    // Before the `phem` controller and the other submit listeners of the form.
    this.listening = new AbortController()
    this.element.addEventListener("submit", this.onSubmit, { capture: true, signal: this.listening.signal })
    this.element.addEventListener("phem:send", this.onSend, { signal: this.listening.signal })
    this.element.addEventListener("phem:done", this.onDone, { signal: this.listening.signal })

    // The submit events of the form that went to the server and did not end.
    this.submits = 0

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
  }

  // Validates all the fields of the form. Returns whether they are valid.
  validate() {
    return this.#invalid().length === 0
  }

  onSubmit = (event) => {
    if (event.target !== this.element || event.submitter?.formNoValidate) return

    const invalid = this.#invalid()
    if (invalid.length === 0) return

    event.preventDefault()
    event.stopImmediatePropagation()

    const first = firstInDocument(invalid.map(({ control }) => control))
    const loose = invalid.find(({ field, control }) => !field && control === first)
    this.#focus(first)
    if (loose) first.reportValidity()

    this.dispatch("invalid", { detail: { fields: invalid.map(({ field, control }) => field ?? control) } })
  }

  // The submit event of the form goes: its `phem:send` has the handler of `onSubmit`. Another
  // event of the form (an `onChange` of the form, a `phem:click` button in it) has another
  // handler, and a submit that a listener cancels sends nothing.
  onSend = (event) => {
    if (this.#isSubmit(event)) this.submits++
  }

  // The end of the submit event: the first control with a server error gets the focus.
  onDone = async (event) => {
    if (!this.#isSubmit(event) || this.submits === 0) return

    this.submits--
    if (event.detail.ok !== true || event.detail.redirect) return

    // The `phem-field` controllers read the render of the server first.
    await Promise.resolve()

    // The focus left the form for another element of the page: it stays there.
    const active = document.activeElement
    if (active && active !== document.body && !this.element.contains(active)) return

    this.#focus(firstInDocument(this.#serverErrors().filter(isTabbable)))
  }

  #isSubmit(event) {
    const handler = this.element.getAttribute("data-phem-on-submit")

    return event.target === this.element && handler !== null && event.detail?.handler === handler
  }

  // The controls with an error of the server render: in a `phem-field`, as the field reads it, and
  // out of a field, with `aria-invalid="true"`.
  #serverErrors() {
    const fields = [...this.element.querySelectorAll('[data-controller~="phem-field"]')]
    const controls = fields.map((el) => this.application.getControllerForElementAndIdentifier(el, "phem-field")?.serverErrorControl)
    const loose = [...this.element.elements].filter((el) => el.getAttribute("aria-invalid") === "true" && !fields.some((field) => field.contains(el)))

    return [...controls, ...loose].filter(Boolean)
  }

  // Focuses a control and selects its text.
  #focus(control) {
    control?.focus()
    if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement) control.select()
  }

  // The invalid fields and controls: { field, control }. A field is the element of a
  // `phem-field`, and `control` its first invalid control.
  #invalid() {
    const invalid = []
    const fields = [...this.element.querySelectorAll('[data-controller~="phem-field"]')]

    for (const el of fields) {
      const field = this.application.getControllerForElementAndIdentifier(el, "phem-field")
      if (!field || field.validate({ submit: true })) continue

      invalid.push({ field: el, control: field.invalidControl ?? field.controlTargets[0] ?? el })
    }

    for (const el of this.element.elements) {
      if (fields.some((field) => field.contains(el))) continue
      if (!el.willValidate || el.validity.valid) continue

      invalid.push({ field: null, control: el })
    }

    return invalid
  }

  #render() {
    this.element.noValidate = true
  }
}
