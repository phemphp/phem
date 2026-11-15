// The `phem-toggle` items of a group controller (`phem-checkbox-group`, `phem-toggle-group`).
//
// An item is a `phem-toggle` element, or a native checkbox. The state of a `phem-toggle` element
// is its `checked` value. When its controller is connected, the group reads and sets the state
// through the controller. Before that (Stimulus can connect the group first), the group reads and
// writes the value attributes, and the `phem-toggle` controller reads them when it connects.

const IDENTIFIER = "phem-toggle"

// Whether `el` is a `phem-toggle` element.
export function isToggle(el) {
  return (el.getAttribute("data-controller") ?? "").split(/\s+/).includes(IDENTIFIER)
}

// The `phem-toggle` controller of `el`, or null.
export function toggleOf(controller, el) {
  if (!isToggle(el)) return null

  return controller.application.getControllerForElementAndIdentifier(el, IDENTIFIER)
}

// Whether the item is checked (or pressed).
export function isChecked(controller, el) {
  if (!isToggle(el)) return el.checked === true

  const toggle = toggleOf(controller, el)
  if (toggle) return toggle.checked

  const value = el.getAttribute(`data-${IDENTIFIER}-checked-value`)
  if (value !== null) return booleanOf(value)

  return el.getAttribute("aria-checked") === "true" || el.getAttribute("aria-pressed") === "true"
}

// Whether the user cannot change the item: a disabled item, or a read-only `phem-toggle` element.
export function isFixed(controller, el) {
  if (!isToggle(el)) return el.disabled === true

  const toggle = toggleOf(controller, el)
  if (toggle) return toggle.disabled || toggle.readOnly

  const flag = (name) => booleanOf(el.getAttribute(`data-${IDENTIFIER}-${name}-value`) ?? "false")

  return flag("disabled") || flag("read-only") || el.hasAttribute("disabled")
}

// Sets the state of the item. With `notify`, the item dispatches the events of a change by the
// user: `input` and `change` (on a native checkbox, or on the input of a `phem-toggle` element),
// and `phem-toggle:change`.
export function setChecked(controller, el, checked, { indeterminate = false, notify = false } = {}) {
  if (!isToggle(el)) {
    const changed = el.checked !== checked
    el.checked = checked
    el.indeterminate = indeterminate
    if (changed && notify) {
      el.dispatchEvent(new Event("input", { bubbles: true }))
      el.dispatchEvent(new Event("change", { bubbles: true }))
    }

    return
  }

  const toggle = toggleOf(controller, el)
  if (toggle) {
    toggle.setChecked(checked, { indeterminate, notify })

    return
  }

  el.setAttribute(`data-${IDENTIFIER}-checked-value`, String(checked))
  el.setAttribute(`data-${IDENTIFIER}-indeterminate-value`, String(indeterminate))
}

// Gives the item the state of the group. A `phem-toggle` element that is not connected yet gets
// its `checked` value also when it looks checked: its controller takes the value when it
// connects, and not the state of its input or of its `aria-checked`.
export function syncChecked(controller, el, checked, options = {}) {
  const pending = isToggle(el) && !toggleOf(controller, el)
  if (pending || isChecked(controller, el) !== checked) setChecked(controller, el, checked, options)
}

// The value of an item in a group: its `data-value`, the `value` of a native checkbox or of a
// `phem-toggle` element, its key (`data-phem-key`), or its index.
export function itemValue(el, index) {
  return el.getAttribute("data-value")
    ?? (isToggle(el) ? el.getAttribute(`data-${IDENTIFIER}-value-value`) : el.getAttribute("value"))
    ?? el.getAttribute("data-phem-key")
    ?? String(index)
}

// A Boolean value of Stimulus: "false" and "0" are false.
function booleanOf(value) {
  return !(value === "false" || value === "0")
}
