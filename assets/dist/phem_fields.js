// The value of a form field, from all of its elements: a group of checkboxes or of radio buttons
// has more than one. phem_controller.js sends it for a bound field (`phem:bind`), and
// `phem-depends` reads it to show its panels.
//
// - a checkbox: `true` or `false`, or with `list` the checked values of the group;
// - a group of radio buttons: the value of the checked button, or `null`;
// - a `<select multiple>`: the selected values;
// - another field: its value.
//
// A bound group of checkboxes (`data-phem-bind-list`) is a list, also with one checkbox.
export function fieldsValue(fields, { list = fields[0]?.hasAttribute("data-phem-bind-list") ?? false } = {}) {
  const [first] = fields
  if (first instanceof HTMLInputElement && first.type === "checkbox") return list ? fields.filter((f) => f.checked).map((f) => f.value) : first.checked
  if (first instanceof HTMLInputElement && first.type === "radio") return fields.find((f) => f.checked)?.value ?? null
  if (first instanceof HTMLSelectElement && first.multiple) return [...first.selectedOptions].map((o) => o.value)

  return first.value
}
