import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { clamp, clean, flag, link, nextStep, numberFormat, sendEvents } from "./phem_number.js"

// The `phem-number-field` controller: a text input for a number, with increment and decrement
// buttons, the keyboard, the mouse wheel, a scrub area and the format of the locale. The value
// stays after the morph of an event.
//
//     <div data-controller="phem-number-field" data-phem-number-field-value-value="100"
//          data-phem-number-field-min-value="0" data-phem-number-field-format-value='{"style":"currency","currency":"EUR"}'>
//         <span data-phem-number-field-target="scrubArea">
//             <label for="amount">Amount</label>
//             <span data-phem-number-field-target="scrubAreaCursor" hidden>↔</span>
//         </span>
//         <div data-phem-number-field-target="group">
//             <button data-phem-number-field-target="decrement">−</button>
//             <input id="amount" data-phem-number-field-target="input">
//             <button data-phem-number-field-target="increment">+</button>
//         </div>
//         <input type="hidden" name="amount" data-phem-number-field-target="hiddenInput">
//     </div>
//
// The `value` value is the state: the raw number (for example `1234.5`), or an empty string for
// no value. The server render gives its first value, and a server render with a new value sets
// it. The `input` target shows the value in the format of the `locale` value (the locale of the
// browser by default) and of the `format` value (the options of `Intl.NumberFormat`, as JSON).
// The `hiddenInput` target gets the raw value for the form. With no `hiddenInput`, give the
// `name` to the `input`: the form then gets the formatted text.
//
// The user types a number in the format of the locale: the controller reads the group and the
// decimal separators of the locale. The value changes while the user types, and the input shows
// the formatted value on blur and on Enter. On blur, a value out of the `min` and `max` values
// goes to the nearest limit, unless the `allowOutOfRange` value is set. The steps (the keyboard,
// the buttons, the wheel and the scrub area) always stay in the limits.
//
// The keys of the input: ArrowUp and ArrowDown add or remove the `step` value (1 by default), with
// Alt the `smallStep` value (0.1) and with Shift the `largeStep` value (10). PageUp and PageDown
// use the `largeStep` value. Home and End go to the `min` and to the `max` values. A press on the
// `increment` or `decrement` button changes the value one time; hold it and the value changes
// again after 400 ms, then each 60 ms. The focus stays in the input. With the `snapOnStep` value,
// a step goes to a multiple of the step. With the `allowWheelScrub` value, the mouse wheel
// changes the value while the input has the focus.
//
// A press in the `scrubArea` target locks the pointer (the Pointer Lock API), and a movement
// changes the value by one step each `pixelSensitivity` pixels (2 by default), in the `direction`
// value (`horizontal` by default, or `vertical`). While the pointer is locked, the
// `scrubAreaCursor` target shows at the place of the pointer, as a virtual cursor. With no
// pointer lock, the native pointer stays.
//
// The `disabled`, `readOnly` and `required` values set the attributes of the inputs. All the
// parts get `data-disabled`, `data-readonly`, `data-required` and `data-scrubbing` (while the user
// scrubs). The `group` target gets `role="group"`. The input gets `inputmode="decimal"`,
// `aria-roledescription="Number field"`, `autocomplete="off"` and `spellcheck="false"`, unless
// the markup gives them. The buttons get `tabindex="-1"`, `aria-label` ("Increase" or
// "Decrease"), a link to the input (`aria-controls`), and `disabled` at the limit.
//
// A change of the value dispatches `phem-number-field:change` ({ value, reason }), and sends
// `input` on the inputs. The end of a change (a blur, a key, the release of a button or of the
// scrub area, a wheel step) dispatches `phem-number-field:commit` and sends `change`.
export default class PhemNumberFieldController extends Controller {
  static targets = ["group", "input", "increment", "decrement", "scrubArea", "scrubAreaCursor", "hiddenInput"]
  static values = {
    value: String,
    min: Number,
    max: Number,
    step: { type: Number, default: 1 },
    smallStep: { type: Number, default: 0.1 },
    largeStep: { type: Number, default: 10 },
    allowOutOfRange: Boolean,
    snapOnStep: Boolean,
    allowWheelScrub: Boolean,
    format: Object,
    locale: String,
    disabled: Boolean,
    readOnly: Boolean,
    required: Boolean,
    pixelSensitivity: { type: Number, default: 2 },
    direction: { type: String, default: "horizontal" },
  }

  connect() {
    this.number = toNumber(this.valueValue)
    this.committed = this.number
    this.scrubbing = false
    this.added = new WeakMap()
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("input", this.onInput, options)
    this.element.addEventListener("beforeinput", this.onBeforeInput, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusout", this.onFocusout, options)
    this.element.addEventListener("pointerdown", this.onPointerdown, options)
    this.element.addEventListener("mousedown", this.onMousedown, options)
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("wheel", this.onWheel, { ...options, passive: false })

    this.#render({ text: true })
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.#stopRepeat()
    this.scrub?.abort()
    // A morph that removes the field during a scrub: the pointer does not stay locked.
    if (this.scrubAreaTargets.includes(document.pointerLockElement)) document.exitPointerLock?.()
    this.scrubbing = false
    this.rendered = false
  }

  // A server render with a new `value` value, or a change of the value by a script.
  valueValueChanged() {
    if (!this.rendered) return

    const number = toNumber(this.valueValue)
    if (number === this.number) return

    this.number = number
    this.committed = number
    this.#render({ text: true })
  }

  // A target that the morph adds gets the state.
  inputTargetConnected() {
    if (this.rendered) this.#render({ text: true })
  }

  hiddenInputTargetConnected() {
    if (this.rendered) this.#render()
  }

  // The user types: a text that is a number changes the value. The text stays as the user types it.
  onInput = (event) => {
    if (this.sending || !this.#isInput(event.target)) return

    const text = event.target.value
    const number = text.trim() === "" ? null : this.#parse(text)
    if (number === undefined || number === this.number) return

    this.#change(number, number === null ? "input-clear" : "input-change")
    this.#send(...this.hiddenInputTargets, "input")
  }

  // A character that no number of the locale has does not go in the input.
  onBeforeInput = (event) => {
    if (!this.#isInput(event.target) || !event.data || !event.inputType?.startsWith("insert")) return

    const allowed = this.#characters
    if (![...event.data].every((c) => /[0-9\s]/.test(c) || allowed.has(c))) event.preventDefault()
  }

  onKeydown = (event) => {
    if (!this.#isInput(event.target) || event.defaultPrevented || event.ctrlKey || event.metaKey) return

    // The browser sends `change` on Enter.
    if (event.key === "Enter") {
      this.#commitText("input-blur")
      return
    }

    let next
    if (event.key === "ArrowUp" || event.key === "ArrowDown") next = [event.key === "ArrowUp" ? 1 : -1, this.#amount(event)]
    else if (event.key === "PageUp" || event.key === "PageDown") next = [event.key === "PageUp" ? 1 : -1, this.largeStepValue]
    else if (event.key === "Home" && this.hasMinValue) next = this.minValue
    else if (event.key === "End" && this.hasMaxValue) next = this.maxValue
    else return

    event.preventDefault()
    if (this.#locked) return

    const changed = Array.isArray(next) ? this.#step(...next, "keyboard") : this.#set(next, "keyboard")
    if (changed) this.#commit("keyboard")
  }

  // The browser sends `change` on blur, when the user typed.
  onFocusout = (event) => {
    if (this.#isInput(event.target)) this.#commitText("input-blur")
  }

  // A press on a button changes the value, and changes it again while the user holds it. A press
  // in the scrub area starts the scrub.
  onPointerdown = (event) => {
    if (event.button !== 0) return

    const button = this.#buttonOf(event.target)
    if (button) {
      event.preventDefault()
      if (this.#locked || button.disabled) return

      this.pressed = true
      this.#focusInput(event)
      this.#hold(button, event)

      return
    }

    const area = this.scrubAreaTargets.find((el) => el.contains(event.target))
    if (area && !this.#locked) this.#startScrub(area, event)
  }

  // The button does not take the focus from the input.
  onMousedown = (event) => {
    if (this.#buttonOf(event.target)) event.preventDefault()
  }

  // A click with no press of the pointer: Enter or Space on the button, or a script.
  onClick = (event) => {
    const button = this.#buttonOf(event.target)
    if (!button) return

    if (this.pressed) {
      this.pressed = false
      return
    }

    const reason = this.#reason(button)
    if (!this.#locked && this.#step(this.#direction(button), this.#amount(event), reason)) this.#commit(reason)
  }

  onWheel = (event) => {
    if (!this.allowWheelScrubValue || !this.#isInput(event.target) || document.activeElement !== event.target) return
    if (this.#locked || event.deltaY === 0) return

    event.preventDefault()
    if (this.#step(event.deltaY < 0 ? 1 : -1, this.#amount(event), "wheel")) this.#commit("wheel")
  }

  // The first step, then the steps while the user holds the button.
  #hold(button, event) {
    const reason = this.#reason(button)
    const direction = this.#direction(button)
    const amount = this.#amount(event)
    this.#stopRepeat()
    this.#step(direction, amount, reason)

    this.repeat = new AbortController()
    const signal = this.repeat.signal
    const timers = { timeout: 0, interval: 0 }
    timers.timeout = setTimeout(() => {
      timers.interval = setInterval(() => this.#step(direction, amount, reason), 60)
    }, 400)
    signal.addEventListener("abort", () => {
      clearTimeout(timers.timeout)
      clearInterval(timers.interval)
    })

    const release = () => {
      this.#stopRepeat()
      this.#commit(reason)
    }
    window.addEventListener("pointerup", release, { signal })
    window.addEventListener("pointercancel", release, { signal })
    window.addEventListener("blur", release, { signal })
  }

  #stopRepeat() {
    this.repeat?.abort()
    this.repeat = null
  }

  // The scrub: the pointer lock, the movement and the virtual cursor.
  #startScrub(area, event) {
    event.preventDefault()
    this.#focusInput(event)
    this.scrubbing = true
    this.cursor = { x: event.clientX, y: event.clientY }
    const last = { x: event.clientX, y: event.clientY }
    const vertical = this.directionValue === "vertical"
    let delta = 0

    try {
      const locking = event.pointerType === "touch" ? null : area.requestPointerLock?.()
      locking?.catch?.(() => {})
    } catch {
      // No pointer lock: the native pointer stays.
    }

    this.scrub = new AbortController()
    const options = { signal: this.scrub.signal }

    document.addEventListener("pointermove", (move) => {
      const locked = document.pointerLockElement === area
      const dx = locked ? move.movementX : move.clientX - last.x
      const dy = locked ? move.movementY : move.clientY - last.y
      last.x = move.clientX
      last.y = move.clientY
      this.#moveCursor(dx, dy)

      delta += vertical ? -dy : dx
      const steps = Math.trunc(delta / this.pixelSensitivityValue)
      if (steps === 0) return

      delta -= steps * this.pixelSensitivityValue
      this.#step(steps, this.#amount(move), "scrub")
    }, options)

    const end = () => {
      this.scrub.abort()
      this.scrub = null
      this.scrubbing = false
      if (document.pointerLockElement === area) document.exitPointerLock?.()

      this.#render()
      this.#commit("scrub")
    }
    document.addEventListener("pointerup", end, options)
    document.addEventListener("pointercancel", end, options)
    document.addEventListener("pointerlockchange", () => this.#render(), options)

    this.#render()
  }

  // The virtual cursor goes round to the other side of the viewport at an edge.
  #moveCursor(dx, dy) {
    const width = window.innerWidth || 1
    const height = window.innerHeight || 1
    this.cursor.x = (((this.cursor.x + dx) % width) + width) % width
    this.cursor.y = (((this.cursor.y + dy) % height) + height) % height

    this.#renderCursor()
  }

  // Adds `steps` steps of `amount` to the value (a negative number removes them), in the limits.
  // The first step from no value goes to the `min` value. Returns whether the value changed.
  #step(steps, amount, reason) {
    const origin = this.hasMinValue ? this.minValue : 0
    let value = this.number ?? origin
    if (this.number === null && steps > 0 && this.hasMinValue) steps -= 1

    const direction = Math.sign(steps)
    for (let i = 0; i < Math.abs(steps); i++) {
      value = this.snapOnStepValue ? nextStep(value, amount, origin, direction) : clean(value + direction * amount)
    }

    return this.#set(this.#limit(value), reason)
  }

  // Sets the value of a step: the input shows the formatted value. Returns whether the value
  // changed.
  #set(value, reason) {
    if (value === this.number) return false

    this.#change(value, reason)
    this.#render({ text: true })
    this.#send(...this.inputTargets, ...this.hiddenInputTargets, "input")

    return true
  }

  #change(value, reason) {
    this.number = value
    this.valueValue = value === null ? "" : String(value)
    this.#render()

    this.dispatch("change", { detail: { value, reason } })
  }

  // The end of a change: `change` on the inputs and `phem-number-field:commit`. The browser sends
  // `change` on the input itself after the user types (`native`).
  #commit(reason, { native = false } = {}) {
    if (this.number === this.committed) return

    this.committed = this.number
    this.#send(...(native ? [] : this.inputTargets), ...this.hiddenInputTargets, "change")

    this.dispatch("commit", { detail: { value: this.number, reason } })
  }

  // The text of the input on blur and on Enter: the formatted value, in the limits. A text that is
  // not a number gives the last value again.
  #commitText(reason) {
    const input = this.inputTargets[0]
    if (!input || this.#locked) return

    const text = input.value
    const empty = text.trim() === ""
    let value = empty ? null : (this.#parse(text) ?? this.number)
    if (value !== null && !this.allowOutOfRangeValue) value = this.#limit(value)

    const limited = value !== this.number
    if (limited) this.#set(value, reason)
    else this.#render({ text: true })

    this.#commit(empty ? "input-clear" : reason, { native: !limited })
  }

  #limit(value) {
    return clamp(value, this.hasMinValue ? this.minValue : -Infinity, this.hasMaxValue ? this.maxValue : Infinity)
  }

  // Sends the event types (the strings) on the elements. The controller does not read its own
  // `input` event.
  #send(...args) {
    const types = args.filter((a) => typeof a === "string")
    this.sending = true

    try {
      for (const el of args.filter((a) => typeof a !== "string")) sendEvents(el, ...types)
    } finally {
      this.sending = false
    }
  }

  // The number of a text in the format of the locale, or undefined when the text is not a number.
  #parse(text) {
    const { group, decimal } = this.#separators
    let s = text.replace(/[−‒–—﹣－]/g, "-").replace(/\s/g, "")
    if (group && !/\s/.test(group)) s = s.split(group).join("")
    if (decimal !== ".") s = s.split(decimal).join(".")
    s = s.replace(/[^0-9.+-]/g, "")
    if (!/^[+-]?(\d+\.?\d*|\.\d+)$/.test(s)) return undefined

    const number = Number(s)

    return clean(this.formatValue.style === "percent" ? number / 100 : number)
  }

  // The text of the value in the format of the locale.
  get #text() {
    if (this.number === null) return ""

    const options = Object.keys(this.formatValue).length > 0 ? this.formatValue : { maximumFractionDigits: 20 }

    return numberFormat(this.localeValue, options).format(this.number)
  }

  // The group and the decimal separators of the locale.
  get #separators() {
    const parts = numberFormat(this.localeValue, { useGrouping: true }).formatToParts(11111.1)

    return { group: parts.find((p) => p.type === "group")?.value ?? "", decimal: parts.find((p) => p.type === "decimal")?.value ?? "." }
  }

  // The characters that a formatted number can have, other than the digits.
  get #characters() {
    const characters = new Set(["-", "+", ".", ",", "−", "%"])
    for (const part of numberFormat(this.localeValue, this.formatValue).formatToParts(-11111.1)) {
      if (part.type !== "integer" && part.type !== "fraction") for (const c of part.value) characters.add(c)
    }

    return characters
  }

  // The step of a key, a press or a movement: Alt gives the small step, Shift the large step.
  #amount(event) {
    if (event.altKey) return this.smallStepValue
    if (event.shiftKey) return this.largeStepValue

    return this.stepValue
  }

  #direction(button) {
    return this.incrementTargets.includes(button) ? 1 : -1
  }

  #reason(button) {
    return this.incrementTargets.includes(button) ? "increment-press" : "decrement-press"
  }

  get #locked() {
    return this.disabledValue || this.readOnlyValue
  }

  #focusInput(event) {
    const input = this.inputTargets[0]
    if (input && event.pointerType !== "touch" && document.activeElement !== input) input.focus({ preventScroll: true })
  }

  #isInput(el) {
    return this.inputTargets.includes(el)
  }

  #buttonOf(el) {
    return [...this.incrementTargets, ...this.decrementTargets].find((button) => button.contains(el))
  }

  // The attributes of the parts. The text of the input changes only with `text`: the text that
  // the user types stays.
  #render({ text = false } = {}) {
    this.rendered = true
    const parts = [this.element, ...this.groupTargets, ...this.inputTargets, ...this.incrementTargets, ...this.decrementTargets, ...this.scrubAreaTargets, ...this.scrubAreaCursorTargets]

    for (const el of parts) {
      el.toggleAttribute("data-disabled", this.disabledValue)
      el.toggleAttribute("data-readonly", this.readOnlyValue)
      el.toggleAttribute("data-required", this.requiredValue)
      el.toggleAttribute("data-scrubbing", this.scrubbing)
    }

    for (const el of this.groupTargets) if (!el.hasAttribute("role")) el.setAttribute("role", "group")

    for (const input of this.inputTargets) {
      for (const [name, value] of Object.entries(INPUT_DEFAULTS)) if (!input.hasAttribute(name)) input.setAttribute(name, value)

      flag(input, "disabled", this.disabledValue, this.added)
      flag(input, "readonly", this.readOnlyValue, this.added)
      flag(input, "required", this.requiredValue, this.added)
      if (!text) continue

      input.value = this.#text
      input.setAttribute("value", input.value)
    }

    for (const input of this.hiddenInputTargets) {
      input.value = this.valueValue
      input.setAttribute("value", this.valueValue)
      flag(input, "disabled", this.disabledValue, this.added)
      flag(input, "required", this.requiredValue, this.added)
    }

    for (const button of [...this.incrementTargets, ...this.decrementTargets]) this.#renderButton(button)

    this.#renderCursor()
  }

  // A button is disabled at its limit.
  #renderButton(button) {
    const up = this.incrementTargets.includes(button)
    const limit = up ? (this.hasMaxValue ? this.maxValue : Infinity) : this.hasMinValue ? this.minValue : -Infinity
    const disabled = this.disabledValue || (this.number !== null && (up ? this.number >= limit : this.number <= limit))

    if (button.localName === "button" && !button.hasAttribute("type")) button.type = "button"
    if (!button.hasAttribute("tabindex")) button.tabIndex = -1
    if (!button.hasAttribute("aria-label")) button.setAttribute("aria-label", up ? "Increase" : "Decrease")
    if (this.inputTargets[0]) link(button, "aria-controls", "ariaControlsElements", this.inputTargets[0])
    button.toggleAttribute("disabled", disabled)
    button.toggleAttribute("data-disabled", disabled)
  }

  // The virtual cursor shows while the pointer is locked.
  #renderCursor() {
    const locked = this.scrubbing && this.scrubAreaTargets.includes(document.pointerLockElement)

    for (const el of this.scrubAreaCursorTargets) {
      el.hidden = !locked
      if (!locked) continue

      Object.assign(el.style, { position: "fixed", top: "0px", left: "0px", pointerEvents: "none" })
      el.style.transform = `translate3d(${this.cursor.x}px, ${this.cursor.y}px, 0)`
    }
  }

  // The attributes of the state.
  #owns(el, name) {
    if (name === "data-scrubbing") return true
    if (this.inputTargets.includes(el) || this.hiddenInputTargets.includes(el)) return name === "value"
    if (this.incrementTargets.includes(el) || this.decrementTargets.includes(el)) return name === "disabled" || name === "data-disabled"
    if (this.scrubAreaCursorTargets.includes(el)) return name === "hidden" || name === "style"

    return false
  }
}

// The attributes of the input, unless the markup gives them.
const INPUT_DEFAULTS = { inputmode: "decimal", "aria-roledescription": "Number field", autocomplete: "off", autocorrect: "off", spellcheck: "false" }

// The number of a raw value, or null for an empty value. A value that is not a number is null.
function toNumber(value) {
  if (value === null || value === undefined || String(value).trim() === "") return null

  const number = Number(value)

  return Number.isFinite(number) ? number : null
}
