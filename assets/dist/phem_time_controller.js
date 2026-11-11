import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The units of a relative time, from the largest, with their length in milliseconds.
const UNITS = [
  ["year", 365 * 24 * 3600 * 1000],
  ["month", 30 * 24 * 3600 * 1000],
  ["week", 7 * 24 * 3600 * 1000],
  ["day", 24 * 3600 * 1000],
  ["hour", 3600 * 1000],
  ["minute", 60 * 1000],
  ["second", 1000],
]

// The `Intl.DateTimeFormat` options of each format.
const FORMATS = {
  date: { dateStyle: "medium" },
  time: { timeStyle: "short" },
  datetime: { dateStyle: "medium", timeStyle: "short" },
}

// The longest wait between two updates of a relative time.
const MAX_WAIT = 3600 * 1000

// The Intl formatters by kind, language and options: a page with many times makes each one once.
const formatters = new Map()

// The `phem-time` controller: shows the `datetime` of a `<time>` element in the time zone and the
// language of the user. The server has no session, so it does not know the time zone.
//
//     <time datetime="2026-11-11T14:03:00Z" data-controller="phem-time"
//           data-phem-time-format-value="relative">2026-11-11 14:03 UTC</time>
//
// The `format` value is `relative` (the default: "3 minutes ago", "in 30 seconds"), `date`,
// `time` or `datetime`. The `date-style` and `time-style` values replace the styles of the format
// (`short`, `medium`, `long` or `full`). The language is the `lang` of the element or of the page.
// A Symfony locale such as `fr_FR` is the language tag `fr-FR`, and a language that the browser
// does not know gives the language of the browser.
//
// A relative time updates while the page is open. The element gets `data-past` or `data-future`,
// and the `title` of the full date and time when the server render gives no title. When a future
// time comes, the controller dispatches `phem-time:reached`: for a "Resend the code in 30 seconds"
// button, for example. The text belongs to the client: the controller shows it again after the
// morph of an event, also with a new `datetime`.
export default class PhemTimeController extends Controller {
  static values = {
    format: { type: String, default: "relative" },
    dateStyle: String,
    timeStyle: String,
  }

  connect() {
    // The server render gives no title: the controller sets it. A connect after a move of the
    // element keeps the first answer, because the element has the title of the controller then.
    this.ownsTitle ??= !this.element.hasAttribute("title")

    this.state = new ClientState(this, {
      owns: (_el, name) => name === "data-past" || name === "data-future" || (name === "title" && this.ownsTitle),
      morphed: () => this.render(),
    })
    this.state.connect()

    document.addEventListener("visibilitychange", this.onVisibilityChange)
    this.render()
  }

  disconnect() {
    this.state.disconnect()
    document.removeEventListener("visibilitychange", this.onVisibilityChange)
    clearTimeout(this.timer)
    this.timer = undefined
  }

  formatValueChanged() {
    if (this.state) this.render()
  }

  // A hidden tab waits: the text updates when the tab shows again.
  onVisibilityChange = () => {
    if (document.visibilityState === "visible") this.render()
  }

  render() {
    clearTimeout(this.timer)
    this.timer = undefined

    const date = new Date(this.element.getAttribute("datetime") ?? "")
    if (Number.isNaN(date.getTime())) return

    const now = Date.now()
    const future = date.getTime() > now
    const reached = this.future === true && !future && this.time === date.getTime()
    this.future = future
    this.time = date.getTime()
    this.element.toggleAttribute("data-future", future)
    this.element.toggleAttribute("data-past", !future)

    const lang = this.element.closest("[lang]")?.getAttribute("lang")
    if (this.ownsTitle) this.element.title = formatter("date", lang, { dateStyle: "long", timeStyle: "short" }).format(date)

    const text = this.formatValue === "relative" ? this.#relative(date, now, lang) : this.#absolute(date, lang)
    if (this.element.textContent !== text) this.element.textContent = text

    if (this.formatValue === "relative" || future) this.#schedule(date, now, future)
    if (reached) this.dispatch("reached", { detail: { datetime: date } })
  }

  // The text of a relative time, and the unit that it shows.
  #relative(date, now, lang) {
    const diff = date.getTime() - now
    const [unit, ms] = UNITS.find(([, ms]) => Math.abs(diff) >= ms) ?? UNITS.at(-1)
    this.unit = ms

    return formatter("relative", lang, { numeric: "auto" }).format(Math.trunc(diff / ms), unit)
  }

  #absolute(date, lang) {
    const options = { ...(FORMATS[this.formatValue] ?? FORMATS.datetime) }
    if (this.dateStyleValue) options.dateStyle = this.dateStyleValue
    if (this.timeStyleValue) options.timeStyle = this.timeStyleValue

    return formatter("date", lang, options).format(date)
  }

  // The next update: when the shown number changes, or when a future time comes, at most one
  // hour later. A past time counts up: the number changes at the next multiple of the unit. A
  // future time counts down: the number changes 1 ms after the multiple below, also when the
  // timer comes at the multiple itself (60 s shows "in 1 minute", 59.999 s "in 59 seconds").
  #schedule(date, now, future) {
    if (document.visibilityState === "hidden") return

    const diff = Math.abs(date.getTime() - now)
    const unit = this.formatValue === "relative" ? this.unit : diff + 1
    const wait = future ? (diff % unit) + 1 : unit - (diff % unit)

    this.timer = setTimeout(() => this.render(), Math.min(wait, MAX_WAIT))
  }
}

// A formatter of `kind` ("date" or "relative") for a language. A Symfony locale (`fr_FR`) is a
// language tag (`fr-FR`), and a tag that Intl refuses gives the language of the browser.
function formatter(kind, lang, options) {
  const tag = lang?.replaceAll("_", "-") || undefined
  const key = `${kind}|${tag}|${JSON.stringify(options)}`
  if (!formatters.has(key)) formatters.set(key, make(kind, tag, options))

  return formatters.get(key)
}

function make(kind, tag, options) {
  const Format = kind === "relative" ? Intl.RelativeTimeFormat : Intl.DateTimeFormat

  try {
    return new Format(tag, options)
  } catch (e) {
    if (!(e instanceof RangeError) || tag === undefined) throw e

    return new Format(undefined, options)
  }
}
