import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { link } from "./phem_aria.js"
import { MINUS_SIGNS, PLUS_SIGNS, clamp, clean, flag, isDigit, nextStep, numberFormat, sendEvents, toAsciiDigits } from "./phem_number.js"
import { isGecko, isIOS, isWebKit } from "./phem_platform.js"

// The `phem-number-field` controller: a text input for a number, with increment and decrement
// buttons, the keyboard, the mouse wheel, a scrub area and the format of the locale. The value
// stays after the morph of an event.
//
//     <div data-controller="phem-number-field" data-phem-number-field-value-value="100"
//          data-phem-number-field-min-value="0"
//          data-phem-number-field-format-value='{"style":"currency","currency":"EUR"}'>
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
// the formatted value on blur. Enter keeps the text: it puts the value in the limits for the form
// and ends the change. On blur, a value out of the `min` and `max` values goes to the
// nearest limit, unless the `allowOutOfRange` value is set. The steps (the keyboard, the buttons,
// the wheel and the scrub area) always stay in the limits. A character that no number of the
// format has does not go in the input. The input takes one sign, one decimal separator, one
// currency sign and one percent sign, unless the new character replaces the selected one. A minus
// sign goes in only when the value can be negative. The digits of other scripts (Arabic-Indic,
// Persian, fullwidth and Han) are the same as the ASCII digits. The Arabic decimal separator (٫)
// is the decimal separator, and the Arabic group separator (٬) is the group separator.
//
// The keys of the input: ArrowUp and ArrowDown add or remove the `step` value (1 by default), with
// Alt the `smallStep` value (0.1) and with Shift the `largeStep` value (10). Home and End go to the
// `min` and to the `max` values. The first step from no value goes to 0, in the limits (`min` when
// `min` is more than 0, `max` when `max` is less than 0). A press on
// the `increment` or `decrement` button changes the value one time; hold it and the value changes
// again after 400 ms, then each 60 ms. A touch or a pen press waits 50 ms: a movement before then
// is a scroll, and the click does one step. The changes stop at the limit, and while the pointer
// is out of the button: they start again when it comes back. The focus stays in the input. With
// the `snapOnStep` value, a step goes to a multiple of the step. With the `allowWheelScrub` value,
// the mouse wheel changes the value while the input has the focus.
//
// A press in the `scrubArea` target locks the pointer (the Pointer Lock API) on the body, except
// on WebKit (Safari), and a movement in the `direction` value (`horizontal` by default, or
// `vertical`) changes the value, as in Base UI: after a movement of `pixelSensitivity` pixels (2
// by default), the value changes by one step for each pixel of the last movement. While the
// pointer is locked, the `scrubAreaCursor` target shows with its center at the place of the
// pointer, as a virtual cursor. At an edge of the viewport, the cursor goes to the opposite edge.
// With the `teleportDistance` value (px), the edges are those of the scrub area, plus half of the
// distance on each side. With no pointer lock, the native pointer stays. A press with no movement
// is a click on its target: a click on the label focuses the input. While the pointer is locked,
// the browser sends this click to the body, so the controller sends it to the target. The scrub area gets
// `role="presentation"`, and the styles of phem.css stop the scroll of a touch and the selection
// of text in it.
//
// The `disabled`, `readOnly` and `required` values set the attributes of the inputs. All the
// parts get `data-disabled`, `data-readonly`, `data-required` and `data-scrubbing` (while the user
// scrubs). The `group` target gets `role="group"`. The input gets `inputmode` (`numeric`; on iOS
// `decimal` with `min >= 0`, else `text`), `aria-roledescription="Number field"`,
// `autocomplete="off"`, `autocorrect="off"` and `spellcheck="false"`, unless the markup gives them. The buttons get `tabindex="-1"`, `aria-label` ("Increase" or
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
    teleportDistance: { type: Number, default: -1 },
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
    this.element.addEventListener("click", this.onClickCapture, { ...options, capture: true })
    this.element.addEventListener("wheel", this.onWheel, { ...options, passive: false })

    this.#render({ text: true })
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.#stopRepeat()

    // A morph that removes the field during a scrub: the pointer does not stay locked.
    const scrubbing = this.scrubbing
    this.scrub?.abort()
    clearTimeout(this.clickTimer)
    clearTimeout(this.exitTimer)
    if (scrubbing || this.exitTimer) this.#exitLock()

    this.exitTimer = undefined
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

  // A character that no number of the format has does not go in the input, and the input takes
  // one sign, one decimal separator, one currency sign and one percent sign.
  onBeforeInput = (event) => {
    const input = event.target
    if (!this.#isInput(input) || !event.data || !event.inputType?.startsWith("insert")) return

    const allowed = this.#characters
    const known = [...event.data].every((c) => isDigit(c) || /[\s\p{Cf}]/u.test(c) || allowed.has(c))
    if (!known || !this.#once(input, event.data)) event.preventDefault()
  }

  onKeydown = (event) => {
    if (!this.#isInput(event.target) || event.defaultPrevented || event.ctrlKey || event.metaKey) return

    if (event.key === "Enter" && !event.isComposing) {
      this.#commitEnter()

      return
    }

    let next
    if (event.key === "ArrowUp" || event.key === "ArrowDown") next = [event.key === "ArrowUp" ? 1 : -1, this.#amount(event)]
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
      // A touch press can be the start of a scroll: the browser keeps it.
      const touch = isTouchLike(event.pointerType)
      if (!touch) event.preventDefault()
      if (this.#locked || button.disabled) return

      // A touch press waits for its click, unless the hold starts.
      this.pressed = !touch
      this.#focusInput(event)
      this.#hold(button, event)

      return
    }

    const area = this.scrubAreaTargets.find((el) => el.contains(event.target))
    if (area && !this.#locked) this.#startScrub(area, event)
  }

  // A click in the scrub area that the browser sends: the controller sends no click.
  onClickCapture = (event) => {
    if (this.scrubAreaTargets.some((el) => el.contains(event.target))) this.nativeClick = true
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

  // The first step, then the steps while the user holds the button. The steps stop at the limit,
  // and while the pointer is out of the button. A touch or a pen press waits `TOUCH_DELAY` ms: a
  // movement before then is a scroll, and the click does one step.
  #hold(button, event) {
    const reason = this.#reason(button)
    const direction = this.#direction(button)
    const amount = this.#amount(event)
    this.#stopRepeat()

    this.repeat = new AbortController()
    const signal = this.repeat.signal
    const timers = { delay: 0, timeout: 0, interval: 0 }
    const stop = () => {
      clearTimeout(timers.delay)
      clearTimeout(timers.timeout)
      clearInterval(timers.interval)
    }
    const start = () => {
      stop()
      if (!this.#step(direction, amount, reason)) return

      timers.timeout = setTimeout(() => {
        timers.interval = setInterval(() => {
          if (!this.#step(direction, amount, reason)) stop()
        }, 60)
      }, 400)
    }
    signal.addEventListener("abort", stop)

    if (isTouchLike(event.pointerType)) {
      const from = { x: event.clientX, y: event.clientY }
      let moves = 0

      button.addEventListener("pointermove", (move) => {
        moves += 1
        if (Math.hypot(move.clientX - from.x, move.clientY - from.y) > SCROLL_DISTANCE) stop()
      }, { signal })

      // The hold starts: the click after it does nothing.
      timers.delay = setTimeout(() => {
        if (moves >= MAX_TOUCH_MOVES) return

        this.pressed = true
        start()
      }, TOUCH_DELAY)
    } else {
      button.addEventListener("mouseenter", start, { signal })
      start()
    }

    const release = () => {
      this.#stopRepeat()
      this.#commit(reason)
    }
    button.addEventListener("mouseleave", stop, { signal })
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
    // A press of the mouse does not select text, and gives the focus to the input.
    if (!isTouchLike(event.pointerType)) {
      event.preventDefault()
      this.#focusInput(event)
    }

    this.scrubbing = true
    this.scrubArea = area
    this.cursor = { x: event.clientX, y: event.clientY }
    const last = { x: event.clientX, y: event.clientY }
    const vertical = this.directionValue === "vertical"
    const target = event.target
    let delta = 0
    let moved = false

    // The click of the press with no movement.
    this.nativeClick = false
    clearTimeout(this.clickTimer)
    clearTimeout(this.exitTimer)
    this.exitTimer = undefined

    // No pointer lock on WebKit, as in Base UI.
    try {
      if (event.pointerType !== "touch" && !isWebKit()) document.body.requestPointerLock?.()?.catch?.(() => {})
    } catch {
      // No pointer lock: the native pointer stays.
    }

    this.scrub = new AbortController()
    const options = { signal: this.scrub.signal }

    document.addEventListener("pointermove", (move) => {
      const locked = document.pointerLockElement === document.body
      const dx = locked ? move.movementX : move.clientX - last.x
      const dy = locked ? move.movementY : move.clientY - last.y
      last.x = move.clientX
      last.y = move.clientY
      this.#moveCursor(dx, dy)

      // After a movement of `pixelSensitivity` pixels, the value changes by one step for each
      // pixel of this movement, as in Base UI.
      const pixels = vertical ? -dy : dx
      delta += pixels
      if (Math.abs(delta) < this.pixelSensitivityValue) return

      delta = 0
      moved = true
      if (pixels !== 0) this.#step(pixels, this.#amount(move), "scrub")
    }, options)

    const end = (up) => {
      this.scrub.abort()
      this.scrub = null
      this.scrubbing = false
      const locked = document.pointerLockElement === document.body

      // Firefox does not end the lock of a soft click at once.
      if (isGecko()) {
        this.exitTimer = setTimeout(() => {
          this.exitTimer = undefined
          this.#exitLock()
        }, 20)
      } else {
        this.#exitLock()
      }

      // A press with no movement is a click. The browser sends the click of a locked pointer to
      // the body: the controller sends it to the target, unless the native click comes. With no
      // lock (a touch, WebKit), the browser sends the click to the target.
      if (!moved && locked && up.type === "pointerup") {
        this.clickTimer = setTimeout(() => {
          if (!this.nativeClick && target.isConnected) target.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }))
        }, 0)
      }

      this.#render()
      this.#commit("scrub")
    }
    document.addEventListener("pointerup", end, options)
    document.addEventListener("pointercancel", end, options)
    document.addEventListener("pointerlockchange", () => this.#render(), options)

    this.#render()
  }

  // The virtual cursor (its center) goes to the opposite edge when it goes out of the edges: the
  // visual viewport, or the scrub area plus half of the `teleportDistance` value on each side.
  #moveCursor(dx, dy) {
    const { left, top, right, bottom } = this.#cursorBounds()
    const wrap = (at, low, high) => (at < low ? high : at > high ? low : at)
    this.cursor.x = wrap(Math.round(this.cursor.x + dx), left, right)
    this.cursor.y = wrap(Math.round(this.cursor.y + dy), top, bottom)

    this.#renderCursor()
  }

  #exitLock() {
    if (document.pointerLockElement === document.body) document.exitPointerLock?.()
  }

  // Adds `steps` steps of `amount` to the value (a negative number removes them), in the limits.
  // Returns whether the value changed.
  #step(steps, amount, reason) {
    // The first step from no value goes to 0, in the limits.
    if (this.number === null) return this.#set(this.#limit(0), reason)

    const origin = this.hasMinValue ? this.minValue : 0
    const direction = Math.sign(steps)

    let value = this.number
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

  // The text of the input on blur: the formatted value, in the limits. A text that is
  // not a number gives the last value again.
  #commitText(reason) {
    const input = this.inputTargets[0]
    if (!input || this.#locked) return

    const value = this.#typed(input.value)
    const limited = value !== this.number
    if (limited) this.#set(value, reason)
    else this.#render({ text: true })

    this.#commit(input.value.trim() === "" ? "input-clear" : reason, { native: !limited })
  }

  // Enter keeps the text, so a form submit gets the value in the limits, and ends the change. The
  // browser sends `change` on the input on blur.
  #commitEnter() {
    const input = this.inputTargets[0]
    if (!input || this.#locked) return

    const value = this.#typed(input.value)
    if (value !== this.number) {
      this.#change(value, "keyboard")
      this.#send(...this.hiddenInputTargets, "input")
    }

    this.#commit("keyboard", { native: true })
  }

  // The value of a typed text, in the limits. A text that is not a number gives the last value.
  #typed(text) {
    if (text.trim() === "") return null

    const value = this.#parse(text) ?? this.number

    return value === null || this.allowOutOfRangeValue ? value : this.#limit(value)
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
    const { group, decimal } = this.#details
    let s = toAsciiDigits(text)
      .replace(/[\p{Cf}\s]/gu, "")
      .replace(MINUS, "-")
      .replace(PLUS, "+")
      .replace(PERCENT, "")
      .replace(/．/g, ".")
      .replace(/，/g, ",")
      .replace(/٫/g, decimal)
      .replace(/٬/g, /\s/.test(group) ? "" : group)
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

  // The separators of the locale, the currency sign of the format and the characters that a
  // formatted number can have, other than the digits. They change only with the locale and the
  // format.
  get #details() {
    const key = `${this.localeValue}|${JSON.stringify(this.formatValue)}`
    if (this.details?.key === key) return this.details

    const separators = numberFormat(this.localeValue, { useGrouping: true }).formatToParts(11111.1)
    const parts = numberFormat(this.localeValue, this.formatValue).formatToParts(11111.1)
    const group = separators.find((p) => p.type === "group")?.value ?? ""
    const decimal = separators.find((p) => p.type === "decimal")?.value ?? "."
    const currency = parts.find((p) => p.type === "currency")?.value ?? ""
    const decimals = [decimal, "٫", decimal === "," ? "，" : "．"]

    const others = new Set([".", ",", "．", "，", "٫", "٬", decimal, ...PLUS_SIGNS])
    for (const part of parts) {
      if (NUMBER_PARTS.has(part.type)) continue

      if (/\s/.test(part.value)) others.add(" ")
      for (const c of part.value) others.add(c)
    }

    const format = this.formatValue
    const percent = format.style === "percent" || format.unit === "percent"
    if (percent) for (const c of PERCENT_SIGNS) others.add(c)
    if (percent || format.unit === "permille") for (const c of PERMILLE_SIGNS) others.add(c)

    this.details = { key, group, decimal, decimals, currency, others }

    return this.details
  }

  // The characters that a formatted number can have, other than the digits. A minus sign goes in
  // only when the value can be negative.
  get #characters() {
    const { others } = this.#details
    if (this.hasMinValue && this.minValue >= 0 && !this.allowOutOfRangeValue) return others

    return new Set([...others, ...MINUS_SIGNS])
  }

  // Whether the input keeps one sign, one decimal separator, one currency sign and one percent
  // sign after the insert of `data`. A character of the selection goes: the new one replaces it.
  #once(input, data) {
    const text = input.value
    const start = input.selectionStart ?? text.length
    const end = input.selectionEnd ?? start
    const next = text.slice(0, start) + data + text.slice(end)
    const { decimals, currency } = this.#details
    const count = (s, symbol) => (symbol ? s.split(symbol).length - 1 : 0)
    const kinds = [
      (s) => s.match(SIGN)?.length ?? 0,
      (s) => [...s].filter((c) => decimals.includes(c)).length,
      (s) => count(s, currency),
      (s) => s.match(PERCENT)?.length ?? 0,
    ]

    return kinds.every((kind) => kind(data) === 0 || kind(next) <= 1)
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
    if (input && !isTouchLike(event.pointerType) && document.activeElement !== input) input.focus({ preventScroll: true })
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
    for (const el of this.scrubAreaTargets) if (!el.hasAttribute("role")) el.setAttribute("role", "presentation")

    for (const input of this.inputTargets) {
      for (const [name, value] of Object.entries(INPUT_DEFAULTS)) if (!input.hasAttribute(name)) input.setAttribute(name, value)
      if (!input.hasAttribute("inputmode")) input.setAttribute("inputmode", this.#inputMode)

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

  // The keyboard of the input. The numeric keyboard of iOS has no minus and no decimal key.
  get #inputMode() {
    if (!isIOS()) return "numeric"

    return this.hasMinValue && this.minValue >= 0 ? "decimal" : "text"
  }

  #cursorBounds() {
    const distance = this.teleportDistanceValue
    if (distance >= 0 && this.scrubArea) {
      const box = this.scrubArea.getBoundingClientRect()

      return { left: box.left - distance / 2, top: box.top - distance / 2, right: box.right + distance / 2, bottom: box.bottom + distance / 2 }
    }

    const viewport = window.visualViewport
    if (viewport) return { left: viewport.offsetLeft, top: viewport.offsetTop, right: viewport.offsetLeft + viewport.width, bottom: viewport.offsetTop + viewport.height }

    return { left: 0, top: 0, right: document.documentElement.clientWidth || window.innerWidth, bottom: document.documentElement.clientHeight || window.innerHeight }
  }

  // The virtual cursor shows while the pointer is locked, with its center at the place of the
  // pointer. Its size stays the same when the user zooms the page.
  #renderCursor() {
    const locked = this.scrubbing && document.pointerLockElement === document.body

    for (const el of this.scrubAreaCursorTargets) {
      el.hidden = !locked
      if (!locked) continue

      Object.assign(el.style, { position: "fixed", top: "0px", left: "0px", pointerEvents: "none" })
      const x = Math.round(this.cursor.x - el.offsetWidth / 2)
      const y = Math.round(this.cursor.y - el.offsetHeight / 2)
      el.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${1 / (window.visualViewport?.scale ?? 1)})`
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
const INPUT_DEFAULTS = { "aria-roledescription": "Number field", autocomplete: "off", autocorrect: "off", spellcheck: "false" }

// The number of a raw value, or null for an empty value. A value that is not a number is null.
function toNumber(value) {
  if (value === null || value === undefined || String(value).trim() === "") return null

  const number = Number(value)

  return Number.isFinite(number) ? number : null
}

// The parts of a formatted number that are not its digits.
const NUMBER_PARTS = new Set(["integer", "fraction", "exponentInteger", "compact"])

const PERCENT_SIGNS = ["%", "٪", "％", "﹪"]
const PERMILLE_SIGNS = ["‰", "؉"]
const MINUS = anyOf(MINUS_SIGNS)
const PLUS = anyOf(PLUS_SIGNS)
const SIGN = anyOf([...MINUS_SIGNS, ...PLUS_SIGNS])
const PERCENT = anyOf([...PERCENT_SIGNS, ...PERMILLE_SIGNS])

// A global regex that matches one of the characters.
function anyOf(characters) {
  return new RegExp(`[${characters.map((c) => c.replace(/[-\\\]^]/g, "\\$&")).join("")}]`, "g")
}

// A touch or a pen press waits `TOUCH_DELAY` ms before the hold. `MAX_TOUCH_MOVES` moves, or a
// movement of more than `SCROLL_DISTANCE` px, is a scroll.
const TOUCH_DELAY = 50
const MAX_TOUCH_MOVES = 3
const SCROLL_DISTANCE = 8

function isTouchLike(type) {
  return type === "touch" || type === "pen"
}
