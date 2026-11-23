// The constraint validation of the browser for `phem-field` and `phem-form`: the validity of the
// controls of a field, whether a control has a value, and whether the user changed it.

// The keys of a `ValidityState` that make a control invalid. An `error` part of a field with
// `data-match="<key>"` shows for this key only.
export const VALIDITY_KEYS = ["badInput", "customError", "patternMismatch", "rangeOverflow", "rangeUnderflow", "stepMismatch", "tooLong", "tooShort", "typeMismatch", "valueMissing"]

// The validity of `controls`: `valid`, the `keys` that are true, the `message` of the first invalid
// control and this `control`. A control that the browser does not validate (a disabled control, a
// control with no constraint validation) is valid, unless its custom control gives its validity
// (see linkInput()).
export function validityOf(controls) {
  const keys = new Set()
  let message = ""
  let control = null

  for (const el of controls) {
    if (!isInvalid(el)) continue

    const custom = customValidityOf(el)
    const validity = custom ?? el.validity
    control ??= el
    message ||= (custom ? custom.message : el.validationMessage) ?? ""
    for (const key of VALIDITY_KEYS) {
      if (validity[key]) keys.add(key)
    }
  }

  return { valid: control === null, keys, message, control }
}

// Whether `el` is a native control of a form.
export function isNative(el) {
  return el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement
}

// Whether the control has a value: a checked checkbox or radio button, a selected option, a text.
// A custom control has a value when its `value` property is not empty, or when it is checked or
// pressed.
export function isFilled(el) {
  if (el instanceof HTMLInputElement && (el.type === "checkbox" || el.type === "radio")) return el.checked
  if (el instanceof HTMLSelectElement) return [...el.selectedOptions].some((option) => option.value !== "")
  if (isNative(el)) return el.value !== ""
  if (el.getAttribute("aria-checked") === "true" || el.getAttribute("aria-pressed") === "true") return true

  return typeof el.value === "string" && el.value !== ""
}

// Whether the value of the control is not the value of the server render (its `value`, `checked`
// or `selected` attribute).
export function isDirty(el) {
  if (el instanceof HTMLInputElement && (el.type === "checkbox" || el.type === "radio")) return el.checked !== el.defaultChecked
  if (el instanceof HTMLSelectElement) return [...el.options].some((option) => option.selected !== option.defaultSelected)
  if (isNative(el)) return el.value !== el.defaultValue

  return false
}

// Whether the control is disabled: its own `disabled`, a disabled `<fieldset>` around it, or
// `aria-disabled="true"`.
export function isDisabledControl(el) {
  return el.matches(":disabled") || el.getAttribute("aria-disabled") === "true"
}

// The first element of `elements` in the order of the document.
export function firstInDocument(elements) {
  return elements.filter(Boolean).sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))[0] ?? null
}

// input => the element that the user sees for it, the function that focuses this element, and the
// function that gives the validity of the input. A custom control (the element of `phem-toggle`,
// a `phem-radio-group`) keeps its value for a form in an input that the user does not see: the
// browser validates the input, but cannot focus it or show its message. The browser does not
// validate an `<input type="hidden">` or a disabled input: the custom control gives its validity.
const customControls = new WeakMap()

// Links an input of a form to the custom control that the user sees. `focus` gives the focus to
// the custom control (by default, its `focus()`). `validity` gives the validity of an input that
// the browser does not validate: null, or `{ valueMissing: true, ... }` with the keys of a
// `ValidityState` that are true and a `message` (see requiredMessage()). The input dispatches
// `phem:link`, so a `phem-field` that connected before the custom control renders it.
export function linkInput(input, control, { focus = () => control.focus(), validity = null } = {}) {
  customControls.set(input, { control, focus, validity })
  input.dispatchEvent(new Event("phem:link", { bubbles: true }))
}

// Whether the control is invalid: for the browser, or for its custom control.
export function isInvalid(el) {
  const custom = customValidityOf(el)
  if (custom) return true

  return Boolean(el.validity) && el.willValidate !== false && !el.validity.valid
}

// The validity that the custom control of an input gives, or null when the input is valid or has
// no such function.
export function customValidityOf(input) {
  return customControls.get(input)?.validity?.() ?? null
}

// The message of the browser for a required checkbox that is not checked, in the language of the
// browser.
let required = null
export function requiredMessage() {
  if (required === null) {
    const checkbox = document.createElement("input")
    checkbox.type = "checkbox"
    checkbox.required = true
    required = checkbox.validationMessage || "Check this box."
  }

  return required
}

export function unlinkInput(input, control) {
  if (customControls.get(input)?.control === control) customControls.delete(input)
}

// The custom control of an input, or null.
export function customControlOf(input) {
  return customControls.get(input)?.control ?? null
}

// Focuses a control, or the custom control of an input.
export function focusControl(control) {
  const custom = customControls.get(control)
  if (custom) custom.focus()
  else control?.focus()
}
