// The number helpers that `phem-number-field` and `phem-slider` share: the format of a value with
// `Intl.NumberFormat`, the digits of other scripts, the steps, and the events of a native input.

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

// The digits of the numeral systems that a number field accepts: ASCII, Arabic-Indic, Persian,
// fullwidth and Han.
const DIGIT = /[0-9٠-٩۰-۹０-９零〇一二三四五六七八九]/
// Han numerals in the order of the digits, with the two forms of zero first.
const HAN = "零〇一二三四五六七八九"

export function isDigit(char) {
  return DIGIT.test(char)
}

// The text with ASCII digits. Arabic-Indic (U+0660), Persian (U+06F0) and fullwidth (U+FF10)
// digits start at a multiple of 16, so the code modulo 16 is the digit.
export function toAsciiDigits(text) {
  return text
    .replace(/[٠-٩۰-۹０-９]/g, (c) => String(c.charCodeAt(0) % 16))
    .replace(/[零〇一二三四五六七八九]/g, (c) => String(Math.max(HAN.indexOf(c) - 1, 0)))
}

export const MINUS_SIGNS = ["-", "−", "－", "‒", "–", "—", "﹣"]
export const PLUS_SIGNS = ["+", "＋", "﹢"]

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
