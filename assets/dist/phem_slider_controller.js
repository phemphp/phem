import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { clamp, clean, flag, link, nextStep, numberFormat, roundToStep, sendEvents } from "./phem_number.js"

// The `phem-slider` controller: one thumb, or more thumbs for a range, on a track, with the
// pointer and the keyboard. Each thumb has a native `<input type="range">` for the ARIA and the
// form. The values stay after the morph of an event.
//
//     <div data-controller="phem-slider" data-phem-slider-value-value="[25, 75]">
//         <div data-phem-slider-target="label">Price</div>
//         <output data-phem-slider-target="value"></output>
//         <div data-phem-slider-target="control">
//             <div data-phem-slider-target="track">
//                 <div data-phem-slider-target="indicator"></div>
//                 <div data-phem-slider-target="thumb"><input type="range" name="min" aria-label="Minimum price" class="sr-only"></div>
//                 <div data-phem-slider-target="thumb"><input type="range" name="max" aria-label="Maximum price" class="sr-only"></div>
//             </div>
//         </div>
//     </div>
//
// The `value` value is the state: one number (`25`), or a JSON list with one number for each
// thumb (`[25, 75]`). The server render gives its first value, and a server render with a new
// value sets it. The values go from the `min` value (0) to the `max` value (100) on the steps of
// the `step` value (1), from `min`. In a range, two thumbs stay at least `minStepsBetweenValues`
// steps (0) apart, and they do not cross.
//
// A press on the `control` target moves the nearest thumb to the point, and a drag moves it. A
// press on a thumb does not move it: the drag moves it from there. While the user drags, a thumb
// that meets another thumb pushes it, with the `thumbCollisionBehavior` value `push` (the
// default). With `swap`, the thumbs change places; with `none`, the thumb stops. The keys of a
// thumb input: the arrow keys add or remove one step (ArrowRight and ArrowUp add), with Shift the
// `largeStep` value (10). PageUp and PageDown use the `largeStep` value. Home and End go to the
// lowest and to the highest value that the thumb can have.
//
// The controller gives the positions as inline styles, as Base UI does. The `track` target gets
// `position: relative`, and the `control` target `touch-action: none`. In the `horizontal`
// orientation (the `orientation` value), a thumb gets `position: absolute`,
// `inset-inline-start` (the percent of its value), `top: 50%` and `translate: -50% -50%`, and
// the `indicator` target gets `inset-inline-start` and `width` from the first to the last value
// (from the start to the value with one thumb). In the `vertical` orientation, they get `bottom`
// and `height`. With the `thumbAlignment` value `edge`, a thumb stays in the control at the
// `min` and at the `max` values. The thumb of the last change gets `z-index: 1`.
//
// The thumb inputs get `min`, `max`, `step`, `value`, `aria-valuenow`, `aria-orientation`,
// `aria-valuetext` ("25 start range" and "75 end range" for two thumbs, or the formatted value
// with a `format` value), `disabled`, and a link to the `label` target. The `value` target shows
// the values in the format of the `locale` and `format` values (the options of
// `Intl.NumberFormat`, as JSON), separated by " – ". All the parts get `data-orientation`,
// `data-disabled` and `data-dragging` (while the user drags), and the thumbs `data-index`.
//
// A change of a value dispatches `phem-slider:change` ({ value, values, index, reason }) and sends
// `input` on the inputs that change. The end of a change (the release of the pointer, a key)
// dispatches `phem-slider:commit` and sends `change`.
export default class PhemSliderController extends Controller {
  static targets = ["control", "track", "indicator", "thumb", "value", "label"]
  static values = {
    value: String,
    min: { type: Number, default: 0 },
    max: { type: Number, default: 100 },
    step: { type: Number, default: 1 },
    largeStep: { type: Number, default: 10 },
    minStepsBetweenValues: { type: Number, default: 0 },
    orientation: { type: String, default: "horizontal" },
    disabled: Boolean,
    thumbAlignment: { type: String, default: "center" },
    thumbCollisionBehavior: { type: String, default: "push" },
    format: Object,
    locale: String,
  }

  connect() {
    this.values = this.#read()
    this.committed = [...this.values]
    this.active = -1
    this.dragging = false
    this.added = new WeakMap()
    this.valueTexts = new WeakMap()
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("pointerdown", this.onPointerdown, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("input", this.onInput, options)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.drag?.abort()
    this.rendered = false
  }

  // A server render with a new `value` value, or a change of the value by a script.
  valueValueChanged() {
    if (!this.rendered) return

    const values = this.#read()
    if (same(values, this.values)) return

    this.values = values
    this.committed = [...values]
    this.#render()
  }

  // A target that the morph adds gets the state.
  thumbTargetConnected() {
    if (!this.rendered) return

    this.values = this.#read()
    this.#render()
  }

  indicatorTargetConnected() {
    if (this.rendered) this.#render()
  }

  valueTargetConnected() {
    if (this.rendered) this.#render()
  }

  // A press on the control moves the nearest thumb to the point, and starts a drag.
  onPointerdown = (event) => {
    const control = this.controlTargets.find((el) => el.contains(event.target))
    if (event.button !== 0 || !control || this.disabledValue) return

    event.preventDefault()
    const thumb = this.thumbTargets.find((el) => el.contains(event.target))
    const start = [...this.values]
    let offset = 0

    if (thumb) {
      this.active = this.#indexOf(thumb)
      offset = this.#percentAt(control, event) - this.#percent(this.values[this.active])
    } else {
      const value = this.#valueAt(control, event, 0)
      this.active = this.#nearest(value)
      this.#move(this.active, value, "track-press", this.thumbCollisionBehaviorValue)
    }

    try {
      control.setPointerCapture?.(event.pointerId)
    } catch {
      // A synthetic pointer has no capture.
    }

    this.#inputOf(this.thumbTargets[this.active])?.focus({ preventScroll: true })
    this.dragging = true
    this.#render()

    this.drag = new AbortController()
    const options = { signal: this.drag.signal }
    document.addEventListener("pointermove", (move) => {
      this.#move(this.active, this.#valueAt(control, move, offset), "drag", this.thumbCollisionBehaviorValue)
    }, options)

    const end = () => {
      this.drag.abort()
      this.drag = null
      this.dragging = false
      this.#render()
      this.#commit(start, thumb ? "drag" : "track-press")
    }
    document.addEventListener("pointerup", end, options)
    document.addEventListener("pointercancel", end, options)
  }

  onKeydown = (event) => {
    const index = this.#inputIndex(event.target)
    if (index < 0 || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return

    const value = this.values[index]
    const step = event.shiftKey ? this.largeStepValue : this.stepValue
    const forward = this.#rtl && this.orientationValue === "horizontal" ? "ArrowLeft" : "ArrowRight"
    const backward = forward === "ArrowRight" ? "ArrowLeft" : "ArrowRight"
    let next

    if (event.key === "ArrowUp" || event.key === forward) next = this.#keyStep(value, step, 1)
    else if (event.key === "ArrowDown" || event.key === backward) next = this.#keyStep(value, step, -1)
    else if (event.key === "PageUp") next = this.#keyStep(value, this.largeStepValue, 1)
    else if (event.key === "PageDown") next = this.#keyStep(value, this.largeStepValue, -1)
    else if (event.key === "Home") next = -Infinity
    else if (event.key === "End") next = Infinity
    else return

    event.preventDefault()
    if (this.disabledValue) return

    const start = [...this.values]
    this.active = index
    this.#move(index, next, "keyboard", "none")
    this.#commit(start, "keyboard")
  }

  // A change of a thumb input that is not a key of the controller: an assistive technology.
  onInput = (event) => {
    const index = this.#inputIndex(event.target)
    if (this.sending || index < 0 || this.disabledValue) return

    const start = [...this.values]
    this.active = index
    this.#move(index, Number(event.target.value), "input-change", "none")
    this.#commit(start, "input-change", { native: true })
  }

  // The value of a key: on the next step from `min`, or a large step and then the nearest step.
  #keyStep(value, step, direction) {
    if (step === this.stepValue) return nextStep(value, step, this.minValue, direction)

    return roundToStep(clean(value + direction * step), this.stepValue, this.minValue)
  }

  // Moves the thumb `index` to `value`, with the collision behavior. Returns whether a value
  // changed.
  #move(index, value, reason, behavior) {
    const values = this.#collide(index, value, behavior)
    if (same(values, this.values)) return false

    const changed = this.#inputs.filter((input, i) => input && values[i] !== this.values[i])
    this.values = values
    this.#write()
    this.#render()
    this.#send(changed, "input")

    this.dispatch("change", { detail: { value: this.#external, values: [...values], index: this.active, reason } })

    return true
  }

  // The values after a move of the thumb `index` to `value`. With `push`, the thumb pushes the
  // others; with `swap`, it takes the place of a thumb that it passes; with `none`, it stops at
  // the next thumb. Two thumbs stay at least `minStepsBetweenValues` steps apart.
  #collide(index, value, behavior) {
    const values = [...this.values]
    const gap = this.minStepsBetweenValuesValue * this.stepValue
    const last = values.length - 1
    const limit = (v, i) => clamp(v, clean(this.minValue + i * gap), clean(this.maxValue - (last - i) * gap))

    if (behavior === "swap") {
      const others = values.filter((_, i) => i !== index)
      const up = value > values[index]
      index = others.filter((v) => (up ? v <= value : v < value)).length
      values.splice(0, values.length, ...others.slice(0, index), value, ...others.slice(index))
      this.active = index
      behavior = "push"
    }

    if (behavior === "none") {
      const low = index > 0 ? clean(values[index - 1] + gap) : this.minValue
      const high = index < last ? clean(values[index + 1] - gap) : this.maxValue
      values[index] = clamp(value, low, high)

      return values
    }

    values[index] = limit(value, index)
    for (let i = index + 1; i <= last; i++) values[i] = Math.max(values[i], clean(values[i - 1] + gap))
    for (let i = index - 1; i >= 0; i--) values[i] = Math.min(values[i], clean(values[i + 1] - gap))

    return values
  }

  // The end of a change: `change` on the inputs that changed and `phem-slider:commit`. The browser
  // sends `change` itself after its own `input` (`native`).
  #commit(start, reason, { native = false } = {}) {
    if (same(this.values, this.committed)) return

    const changed = this.#inputs.filter((input, i) => input && this.values[i] !== start[i])
    this.committed = [...this.values]
    if (!native) this.#send(changed, "change")

    this.dispatch("commit", { detail: { value: this.#external, values: [...this.values], reason } })
  }

  // The values of the `value` value, in the limits and on the steps, one for each thumb.
  #read() {
    let parsed

    try {
      parsed = this.valueValue.trim() === "" ? null : JSON.parse(this.valueValue)
    } catch {
      parsed = null
    }

    this.single = !Array.isArray(parsed)
    const list = (Array.isArray(parsed) ? parsed : parsed === null ? [] : [parsed]).map(Number).filter(Number.isFinite)
    const count = Math.max(1, this.thumbTargets.length, list.length)
    while (list.length < count) list.push(list.length === 0 ? this.minValue : this.maxValue)
    if (count > 1) this.single = false

    return list.map((v) => clamp(roundToStep(v, this.stepValue, this.minValue), this.minValue, this.maxValue)).sort((a, b) => a - b)
  }

  #write() {
    this.valueValue = this.#external === null ? "" : JSON.stringify(this.#external)
  }

  // The value of the events: a number for one thumb, or the list.
  get #external() {
    return this.single ? this.values[0] : [...this.values]
  }

  // The percent of a value on the track.
  #percent(value) {
    const range = this.maxValue - this.minValue

    return range > 0 ? ((value - this.minValue) / range) * 100 : 0
  }

  // The percent of the point of a pointer event on the control.
  #percentAt(control, event) {
    const box = control.getBoundingClientRect()
    const inset = this.#edge ? this.#thumbSize / 2 : 0
    let ratio

    if (this.orientationValue === "vertical") ratio = (box.bottom - event.clientY - inset) / (box.height - 2 * inset)
    else ratio = (event.clientX - box.left - inset) / (box.width - 2 * inset)
    if (!Number.isFinite(ratio)) ratio = 0
    if (this.#rtl && this.orientationValue === "horizontal") ratio = 1 - ratio

    return ratio * 100
  }

  // The value of the point of a pointer event, on a step, less the `offset` percent of a press on
  // a thumb.
  #valueAt(control, event, offset) {
    const percent = this.#percentAt(control, event) - offset
    const value = this.minValue + (percent / 100) * (this.maxValue - this.minValue)

    return clamp(roundToStep(value, this.stepValue, this.minValue), this.minValue, this.maxValue)
  }

  // The thumb nearest to a value. With two thumbs at the same value, the thumb in the direction of
  // the value.
  #nearest(value) {
    let best = 0

    this.values.forEach((v, i) => {
      const distance = Math.abs(v - value)
      const bestDistance = Math.abs(this.values[best] - value)
      if (distance < bestDistance || (distance === bestDistance && v === this.values[best] && value > v)) best = i
    })

    return best
  }

  get #thumbSize() {
    const thumb = this.thumbTargets[Math.max(0, this.active)]
    if (!thumb) return 0

    return this.orientationValue === "vertical" ? thumb.offsetHeight : thumb.offsetWidth
  }

  get #edge() {
    return this.thumbAlignmentValue.startsWith("edge")
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }

  get #inputs() {
    return this.thumbTargets.map((thumb) => this.#inputOf(thumb))
  }

  #inputOf(thumb) {
    return thumb?.querySelector('input[type="range"]') ?? null
  }

  #inputIndex(el) {
    return this.#inputs.findIndex((input) => input && input === el)
  }

  #indexOf(thumb) {
    return Math.min(this.thumbTargets.indexOf(thumb), this.values.length - 1)
  }

  // Sends the events on the inputs. The controller does not read its own `input` event.
  #send(inputs, type) {
    this.sending = true

    try {
      for (const input of inputs) sendEvents(input, type)
    } finally {
      this.sending = false
    }
  }

  // The text of the values in the format of the locale.
  #format(value) {
    return numberFormat(this.localeValue, this.formatValue).format(value)
  }

  // A position on the track: the percent, and with the edge alignment the thumb stays in the
  // control.
  #position(percent) {
    if (!this.#edge) return `${percent}%`

    return `calc(${percent}% + ${((0.5 - percent / 100) * this.#thumbSize).toFixed(2)}px)`
  }

  #render() {
    this.rendered = true
    const vertical = this.orientationValue === "vertical"
    const parts = [this.element, ...this.controlTargets, ...this.trackTargets, ...this.indicatorTargets, ...this.thumbTargets, ...this.valueTargets]

    for (const el of parts) {
      el.setAttribute("data-orientation", this.orientationValue)
      el.toggleAttribute("data-disabled", this.disabledValue)
      el.toggleAttribute("data-dragging", this.dragging)
    }

    if (!this.element.hasAttribute("role")) this.element.setAttribute("role", "group")
    if (this.labelTargets[0]) link(this.element, "aria-labelledby", "ariaLabelledByElements", this.labelTargets[0])

    for (const el of this.controlTargets) el.style.touchAction = "none"
    for (const el of this.trackTargets) el.style.position = "relative"

    this.thumbTargets.forEach((thumb, i) => this.#renderThumb(thumb, Math.min(i, this.values.length - 1), vertical))

    this.#renderIndicator(vertical)

    const text = this.values.map((v) => this.#format(v)).join(" – ")
    for (const el of this.valueTargets) {
      if (!el.hasAttribute("aria-live")) el.setAttribute("aria-live", "off")
      if (el.textContent !== text) el.textContent = text
    }
  }

  #renderThumb(thumb, index, vertical) {
    const value = this.values[index]
    const position = this.#position(this.#percent(value))
    thumb.setAttribute("data-index", String(index))
    thumb.style.position = "absolute"
    thumb.style.zIndex = index === this.active ? "1" : ""

    if (vertical) {
      Object.assign(thumb.style, { insetInlineStart: "", top: "", bottom: position, left: "50%", translate: "-50% 50%" })
    } else {
      Object.assign(thumb.style, { bottom: "", left: "", insetInlineStart: position, top: "50%", translate: `${this.#rtl ? "50%" : "-50%"} -50%` })
    }

    const input = this.#inputOf(thumb)
    if (!input) return

    input.min = String(this.minValue)
    input.max = String(this.maxValue)
    input.step = String(this.stepValue)
    input.value = String(value)
    input.setAttribute("value", String(value))
    input.setAttribute("aria-valuenow", String(value))
    input.setAttribute("aria-orientation", this.orientationValue)
    flag(input, "disabled", this.disabledValue, this.added)
    if (this.labelTargets[0] && !input.hasAttribute("aria-label")) link(input, "aria-labelledby", "ariaLabelledByElements", this.labelTargets[0])

    // An `aria-valuetext` that the markup gives stays.
    const given = input.getAttribute("aria-valuetext")
    if (given !== null && given !== this.valueTexts.get(input)) return

    const text = this.#valueText(value, index)
    this.valueTexts.set(input, text ?? null)
    if (text === undefined) input.removeAttribute("aria-valuetext")
    else input.setAttribute("aria-valuetext", text)
  }

  // The `aria-valuetext` of Base UI: the start and the end of a range, or the formatted value.
  #valueText(value, index) {
    if (this.values.length === 2) return `${this.#format(value)} ${index === 0 ? "start" : "end"} range`

    return Object.keys(this.formatValue).length > 0 ? this.#format(value) : undefined
  }

  // The indicator goes from the first value to the last value, or from the start to the value.
  #renderIndicator(vertical) {
    const start = this.values.length > 1 ? this.#percent(this.values[0]) : 0
    const end = this.#percent(this.values.at(-1))
    const from = this.values.length > 1 ? this.#position(start) : "0%"
    const to = this.#position(end)
    const size = this.#edge ? `calc(${to} - ${from})` : `${clean(end - start)}%`

    for (const el of this.indicatorTargets) {
      if (vertical) Object.assign(el.style, { position: "absolute", insetInlineStart: "", width: "inherit", bottom: from, height: size })
      else Object.assign(el.style, { position: "relative", bottom: "", width: size, insetInlineStart: from, height: "inherit" })
    }
  }

  // The attributes of the state.
  #owns(el, name) {
    if (name === "data-dragging") return true
    if (this.#inputs.includes(el)) return ["value", "aria-valuenow", "aria-valuetext"].includes(name)

    return false
  }
}

function same(a, b) {
  return a.length === b.length && a.every((v, i) => v === b[i])
}
