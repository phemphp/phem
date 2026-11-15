// The number helpers that `phem-number-field` and `phem-slider` share: the format of a value with
// `Intl.NumberFormat`, the steps, and the events of a native input.

// The formats, by their locale and their options: a new `Intl.NumberFormat` is slow.
const formats = new Map()

// The `Intl.NumberFormat` of a locale (an empty locale is the locale of the browser) and of its
// options.
export function numberFormat(locale = "", options = {}) {
  const key = `${locale}|${JSON.stringify(options)}`
  if (!formats.has(key)) formats.set(key, new Intl.NumberFormat(locale || undefined, options))

  return formats.get(key)
}

// A value with no error of the floating point: 0.1 + 0.2 is 0.3.
export function clean(value) {
  return Number.parseFloat(value.toPrecision(12))
}

export function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value))
}

// The value on the nearest step from `origin`.
export function roundToStep(value, step, origin) {
  if (!(step > 0)) return value

  return clean(Math.round((value - origin) / step) * step + origin)
}

// The next step after `value` in the `direction` (1 or -1), from `origin`. A value that is not on
// a step goes to the nearest step in the direction.
export function nextStep(value, step, origin, direction) {
  const steps = (value - origin) / step
  const at = Math.abs(steps - Math.round(steps)) < 1e-9 ? Math.round(steps) : direction > 0 ? Math.floor(steps) : Math.ceil(steps)

  return clean((at + direction) * step + origin)
}

// Sends the events of a change of the value of a native input (for example `input` and
// `change`). They bubble, so a phem event and a bound field see the value.
export function sendEvents(el, ...types) {
  for (const type of types) el.dispatchEvent(new Event(type, { bubbles: true }))
}

// Sets a boolean attribute of `el` from a value of the controller. An attribute that the markup
// gives stays: the controller removes only an attribute that it added (in `added`, a WeakMap).
export function flag(el, name, on, added) {
  let names = added.get(el)
  if (!names) added.set(el, (names = new Set()))

  if (on && !el.hasAttribute(name)) {
    el.setAttribute(name, "")
    names.add(name)
  } else if (!on && names.has(name)) {
    el.removeAttribute(name)
    names.delete(name)
  }
}

// Points an ARIA attribute of `el` to `to`: with its id, or with an element reference when `to` has
// no id. A value that the server render gives stays.
export function link(el, attribute, property, to) {
  const given = el.getAttribute(attribute)
  if (given && given !== to.id) return

  if (to.id) el.setAttribute(attribute, to.id)
  else if (property in el) el[property] = [to]
}
