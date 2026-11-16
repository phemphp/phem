import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The `phem-otp-field` controller: a one-time code with one input for each character (a slot). The
// value stays after the morph of an event.
//
//     <label for="code">Verification code</label>
//     <div data-controller="phem-otp-field" data-phem-otp-field-value-value="">
//         <input id="code" data-phem-otp-field-target="input" />
//         <input data-phem-otp-field-target="input" />
//         <input data-phem-otp-field-target="input" />
//         <span data-phem-otp-field-target="separator">-</span>
//         <input data-phem-otp-field-target="input" />
//         <input data-phem-otp-field-target="input" />
//         <input data-phem-otp-field-target="input" />
//         <input type="hidden" name="code" data-phem-otp-field-target="hiddenInput" />
//     </div>
//
// The `input` targets are the slots. The `length` value is the number of slots (by default the
// number of `input` targets). The `value` value is the state: the characters of the slots. The
// server render gives its first value, and a server render with a new value sets it. The
// `hiddenInput` target gets the value, so a form submit and a phem event send it with its
// `name`. When the user changes the value, it gets an `input` and a `change` event, and the
// controller sends `phem-otp-field:change` with `detail.value` and `detail.reason`
// (`input-change`, `input-clear`, `input-paste` or `keyboard`). When all the slots have a value,
// it sends `phem-otp-field:complete`, and with the `autoSubmit` value it submits the form
// (`requestSubmit()`). A character that `validationType` refuses sends `phem-otp-field:invalid`
// with the text in `detail.value`.
//
// The `validationType` value is `numeric` (the default, the digits), `alpha` (the letters A to
// Z), `alphanumeric` or `none` (all the characters, except the spaces). The slots get the
// `inputmode` of the type (`numeric` or `text`), or the `inputMode` value, and the `pattern` of one
// character. With the `mask` value, the slots are `type="password"`. The first slot gets
// `autocomplete="one-time-code"` (the `autocomplete` value), so the browser can fill the code from
// a message: all the characters then go in the slots. The `disabled`, `readOnly` and `required`
// values go on the slots (and `disabled` on the hidden input).
//
// A character that the user types goes in the slot and the focus goes to the next slot. A paste
// fills the slots from the slot with the focus. The value has no gaps: the focus can go to a slot
// after the first empty slot, and the controller then focuses the first empty slot.
//
// | Key        | Action                                                                    |
// |------------|---------------------------------------------------------------------------|
// | Backspace  | Removes the character of the slot, and focuses the slot before        |
// | Ctrl+Backspace | Removes all the characters (Command on a Mac)                         |
// | Delete     | Removes the character of the slot                                         |
// | ArrowLeft  | Focuses the slot before (with Ctrl: the first slot)                       |
// | ArrowRight | Focuses the slot after, up to the first empty slot (with Ctrl: that slot) |
// | Home, ArrowUp / End, ArrowDown | Focuses the first slot / the first empty slot (or the last slot) |
//
// A press of the mouse on a slot selects its character.
//
// The slots have one tab stop: the slot with the focus, or else the first empty slot (the last slot
// when the code is complete). Tab and Shift+Tab go out of the field.
// A slot after the first one with no `aria-label` or `aria-labelledby` gets
// `aria-label="Character 2 of 6"`; the first slot uses the label of the field. The controller
// element and the slots get `data-disabled`, `data-readonly`, `data-required`, `data-complete` (all
// the slots have a value) and `data-focused` (a slot has the focus). The controller element gets
// `data-filled` when the value is not empty, and a slot when it has a character. A `separator`
// target gets `role="separator"` and `data-orientation="horizontal"`.
export default class PhemOtpFieldController extends Controller {
  static targets = ["input", "hiddenInput", "separator"]
  static values = {
    value: String,
    length: Number,
    validationType: { type: String, default: "numeric" },
    inputMode: String,
    mask: Boolean,
    autoSubmit: Boolean,
    autocomplete: { type: String, default: "one-time-code" },
    disabled: Boolean,
    readOnly: Boolean,
    required: Boolean,
  }

  connect() {
    // A slot with the focus at connect (autofocus, or a slot that a morph keeps) is the tab stop.
    const focusedIndex = this.#slots.indexOf(document.activeElement)
    this.focused = focusedIndex >= 0
    this.focusedIndex = this.focused ? focusedIndex : undefined
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("input", this.onInput, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("mousedown", this.onMousedown, options)
    this.element.addEventListener("paste", this.onPaste, options)
    this.element.addEventListener("focusin", this.onFocusin, options)
    this.element.addEventListener("focusout", this.onFocusout, options)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.rendered = undefined
  }

  // A server render with a new value, or a change of the value by a script.
  valueValueChanged() {
    if (this.rendered !== undefined && this.rendered !== this.#value) this.#render()
  }

  inputTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  hiddenInputTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  separatorTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  onInput = (event) => {
    const slots = this.#slots
    const index = slots.indexOf(event.target)
    if (index < 0) return

    const chars = [...this.#value]
    const before = chars[index] ?? ""
    if (this.#locked) {
      event.target.value = before

      return
    }

    // The text of the slot without its old character: a slot with a character gets one more.
    let typed = event.target.value
    if (before && typed.length > before.length) typed = typed.startsWith(before) ? typed.slice(before.length) : typed.endsWith(before) ? typed.slice(0, -before.length) : typed

    if (typed === "") {
      chars.splice(index, 1)
      this.#change(chars.join(""), "input-clear")
      this.#focus(Math.min(index, this.#active))

      return
    }

    const accepted = this.#accept(typed)
    if (!accepted) {
      event.target.value = before
      event.target.select?.()

      return
    }

    this.#insert(index, accepted, "input-change")
  }

  onPaste = (event) => {
    const index = this.#slots.indexOf(event.target)
    if (index < 0) return

    event.preventDefault()
    if (this.#locked) return

    const accepted = this.#accept(event.clipboardData?.getData("text/plain") ?? "")
    if (accepted) this.#insert(index, accepted, "input-paste")
  }

  onKeydown = (event) => {
    const index = this.#slots.indexOf(event.target)
    if (index < 0 || event.defaultPrevented) return

    // Ctrl or Command with a key goes to the first or to the last slot. Another key with a
    // modifier is a shortcut of the browser.
    const boundary = (event.ctrlKey || event.metaKey) && !event.altKey
    if ((event.ctrlKey || event.metaKey || event.altKey) && !boundary) return

    const chars = [...this.#value]
    const rtl = getComputedStyle(this.element).direction === "rtl"
    const back = rtl ? "ArrowRight" : "ArrowLeft"
    const forward = rtl ? "ArrowLeft" : "ArrowRight"

    switch (event.key) {
      case "Backspace": {
        event.preventDefault()
        if (this.#locked) return

        // Ctrl+Backspace removes all the characters.
        if (boundary) {
          this.#change("", "keyboard")
          this.#focus(0)

          return
        }

        // Backspace removes the character of the slot, or of the slot before an empty slot. The
        // focus goes to the slot before.
        const at = chars[index] ? index : index - 1
        if (at >= 0 && chars[at]) {
          chars.splice(at, 1)
          this.#change(chars.join(""), "keyboard")
        }

        this.#focus(Math.max(0, index - 1))

        return
      }
      case "Delete":
        if (boundary) return

        event.preventDefault()
        if (this.#locked || !chars[index]) return

        chars.splice(index, 1)
        this.#change(chars.join(""), "keyboard")
        this.#focus(index)

        return
      case back:
        event.preventDefault()
        this.#focus(boundary ? 0 : index - 1)

        return
      case forward:
        event.preventDefault()
        this.#focus(boundary ? this.#active : index + 1)

        return
      case "Home":
      case "ArrowUp":
        event.preventDefault()
        this.#focus(0)

        return
      case "End":
      case "ArrowDown":
        event.preventDefault()
        this.#focus(this.#active)
    }
  }

  // A press on a slot gives it the focus and selects its character: the click does not put a
  // caret in it.
  onMousedown = (event) => {
    const index = this.#slots.indexOf(event.target)
    if (index < 0 || event.defaultPrevented || this.disabledValue) return

    event.preventDefault()
    this.#focus(index)
  }

  // A slot after the first empty slot gives the focus to the first empty slot. The focus selects
  // the character, so a new character replaces it.
  onFocusin = (event) => {
    const index = this.#slots.indexOf(event.target)
    if (index < 0) return

    if (index > this.#active) {
      this.#slots[this.#active].focus()

      return
    }

    event.target.select?.()
    if (this.focused && this.focusedIndex === index) return

    // The slot with the focus is the tab stop, so Tab goes out of the field.
    this.focused = true
    this.focusedIndex = index
    this.#render()
  }

  onFocusout = (event) => {
    if (!this.#slots.includes(event.target) || this.#slots.includes(event.relatedTarget)) return

    this.focused = false
    this.focusedIndex = undefined
    this.#render()
  }

  // Puts `text` in the slots from the slot `index` (the first empty slot when it is after it), and
  // focuses the slot after it.
  #insert(index, text, reason) {
    const chars = [...this.#value]
    const at = Math.min(index, chars.length)
    const added = [...text]
    const next = [...chars.slice(0, at), ...added, ...chars.slice(at + added.length)].slice(0, this.#length).join("")

    this.#change(next, reason)
    this.#focus(Math.min(at + added.length, this.#length - 1))
  }

  // The characters of `text` that the validation type accepts. A character that it refuses sends
  // `phem-otp-field:invalid`. The spaces go.
  #accept(text) {
    const chars = [...text.replace(/\s+/g, "")]
    const accepted = chars.filter((c) => this.#accepts(c))
    if (accepted.length < chars.length) this.dispatch("invalid", { detail: { value: text } })

    return accepted.join("")
  }

  #accepts(char) {
    const pattern = PATTERNS[this.validationTypeValue]

    return pattern ? new RegExp(`^${pattern}$`).test(char) : !/\s/.test(char)
  }

  #change(value, reason) {
    if (value === this.#value) {
      this.#render()

      return
    }

    this.valueValue = value
    this.#render()

    for (const el of this.hiddenInputTargets) {
      el.dispatchEvent(new Event("input", { bubbles: true }))
      el.dispatchEvent(new Event("change", { bubbles: true }))
    }

    this.dispatch("change", { detail: { value, reason } })
    if (!this.#complete) return

    this.dispatch("complete", { detail: { value } })
    if (this.autoSubmitValue) (this.hiddenInputTargets[0]?.form ?? this.element.closest("form"))?.requestSubmit()
  }

  #focus(index) {
    const slot = this.#slots[Math.max(0, Math.min(index, this.#active))]
    if (!slot) return

    slot.focus()
    slot.select?.()
  }

  #render() {
    const slots = this.#slots
    const value = this.#value
    const chars = [...value]
    const active = this.#active
    const stop = this.focused && this.focusedIndex !== undefined ? Math.min(this.focusedIndex, active) : active
    this.rendered = value

    const state = {
      "data-disabled": this.disabledValue,
      "data-readonly": this.readOnlyValue,
      "data-required": this.requiredValue,
      "data-complete": this.#complete,
      "data-focused": this.focused,
    }

    for (const el of [this.element, ...slots]) {
      for (const [name, on] of Object.entries(state)) el.toggleAttribute(name, on)
    }

    this.element.toggleAttribute("data-filled", value !== "")

    const pattern = PATTERNS[this.validationTypeValue]
    const inputMode = this.inputModeValue || (this.validationTypeValue === "numeric" ? "numeric" : "text")

    slots.forEach((slot, i) => {
      const char = chars[i] ?? ""
      if (slot.value !== char) slot.value = char

      slot.toggleAttribute("data-filled", char !== "")
      slot.tabIndex = i === stop ? 0 : -1
      slot.inputMode = inputMode
      slot.autocomplete = i === 0 ? this.autocompleteValue : "off"
      slot.disabled = this.disabledValue
      slot.readOnly = this.readOnlyValue
      slot.required = this.requiredValue
      slot.spellcheck = false
      if (this.maskValue) slot.type = "password"
      if (pattern && !slot.hasAttribute("pattern")) slot.setAttribute("pattern", pattern)
      if (i > 0 && !slot.hasAttribute("aria-label") && !slot.hasAttribute("aria-labelledby")) slot.setAttribute("aria-label", `Character ${i + 1} of ${slots.length}`)
    })

    for (const el of this.hiddenInputTargets) {
      el.value = value
      el.setAttribute("value", value)
      el.disabled = this.disabledValue
    }

    for (const el of this.separatorTargets) {
      if (!el.hasAttribute("role")) el.setAttribute("role", "separator")
      if (!el.hasAttribute("data-orientation")) el.setAttribute("data-orientation", "horizontal")
    }
  }

  // The value, with the characters that the validation type accepts, up to the number of slots.
  get #value() {
    const chars = [...this.valueValue.replace(/\s+/g, "")].filter((c) => this.#accepts(c))

    return chars.slice(0, this.#length).join("")
  }

  get #slots() {
    return this.inputTargets.slice(0, this.#length)
  }

  get #length() {
    return this.lengthValue > 0 ? Math.min(this.lengthValue, this.inputTargets.length) : this.inputTargets.length
  }

  // The index of the first empty slot, or of the last slot when the value is complete.
  get #active() {
    return Math.max(0, Math.min([...this.#value].length, this.#length - 1))
  }

  get #complete() {
    return this.#length > 0 && [...this.#value].length === this.#length
  }

  get #locked() {
    return this.disabledValue || this.readOnlyValue
  }

  // The attributes of the state.
  #owns(el, name) {
    if (el === this.element) return STATE_ATTRIBUTES.has(name) || name === "data-filled"
    if (this.inputTargets.includes(el)) return STATE_ATTRIBUTES.has(name) || ["data-filled", "value", "tabindex"].includes(name)
    if (this.hiddenInputTargets.includes(el)) return name === "value"

    return false
  }
}

// The pattern of one character of each validation type.
const PATTERNS = { numeric: "[0-9]", alpha: "[A-Za-z]", alphanumeric: "[A-Za-z0-9]" }

const STATE_ATTRIBUTES = new Set(["data-disabled", "data-readonly", "data-required", "data-complete", "data-focused"])
