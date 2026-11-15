// The constraint validation of the browser for `phem-field` and `phem-form`: the validity of the
// controls of a field, whether a control has a value, and whether the user changed it.

// The keys of a `ValidityState` that make a control invalid. An `error` part of a field with
// `data-match="<key>"` shows for this key only.
export const VALIDITY_KEYS = ["badInput", "customError", "patternMismatch", "rangeOverflow", "rangeUnderflow", "stepMismatch", "tooLong", "tooShort", "typeMismatch", "valueMissing"]

// The validity of `controls`: `valid`, the `keys` that are true, the `message` of the first invalid
// control and this `control`. A control that the browser does not validate (a disabled control, a
// control with no constraint validation) is valid.
export function validityOf(controls) {
  const keys = new Set()
  let message = ""
  let control = null

  for (const el of controls) {
    if (!el.validity || el.willValidate === false || el.validity.valid) continue

    control ??= el
    message ||= el.validationMessage ?? ""
    for (const key of VALIDITY_KEYS) {
      if (el.validity[key]) keys.add(key)
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
