import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { link } from "./phem_aria.js"
import { isAndroid } from "./phem_platform.js"
import { OtpCodeInput } from "./phem_otp_code_input.js"

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
// it sends `phem-otp-field:complete` (when a typed character fills the last empty slot, or at
// each paste after which all the slots have a value, also with the same code), and with the
// `autoSubmit` value it submits the form (`requestSubmit()`). A typed character in a complete code
// does not send it. A character that `validationType` refuses sends `phem-otp-field:invalid` with
// the text in `detail.value`.
//
// The `validationType` value is `numeric` (the default, the digits), `alpha` (the letters A to
// Z), `alphanumeric` or `none` (all the characters, except the spaces). The `normalize` value
// (`uppercase` or `lowercase`) changes the accepted characters, and the validation type filters
// the result again. The slots get the
// `inputmode` of the type (`numeric` or `text`), or the `inputMode` value, and the `pattern` of one
// character. The slots are `type="text"`, and with the `mask` value `type="password"`. Each slot
// gets `autocorrect="off"` and `enterkeyhint` (`done` on the last slot, else `next`). The first
// slot gets `autocomplete="one-time-code"` (the `autocomplete` value) and `maxlength` (the number
// of slots), so the browser can fill the code from a message: all the characters then go in the
// slots. The `disabled`, `readOnly` and `required` values go on the slots (and `disabled` on the
// hidden input).
//
// A character that the user types goes in the slot and the focus goes to the next slot. When a
// slot has two characters (its character and a new one), the two characters go in the slots from
// it on, in the order of the text of the slot. A paste fills the slots from the
// slot with the focus. During an IME composition, the slot keeps the text of the IME and the keys
// are for the IME: the end of the composition puts the text in the slots one time. The value has
// no gaps: the focus can go to a slot after the first empty slot, and the controller then focuses
// the first empty slot.
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
// The controller element gets `role="group"` and, with no `aria-label`, `aria-labelledby` to the
// label of the first slot. A slot after the first one with no `aria-label` or `aria-labelledby`
// gets `aria-label="Character 2 of 6"`; the first slot uses the label of the field. The controller
// element and the slots get `data-disabled`, `data-readonly`, `data-required`, `data-complete` (all
// the slots have a value) and `data-focused` (a slot has the focus). The controller element gets
// `data-filled` when the value is not empty, and a slot when it has a character. A `separator`
// target gets `role="separator"` and `data-orientation="horizontal"`.
//
// One input for the whole code: a `codeInput` target in place of the `input` targets.
//
//     <label for="code">Verification code</label>
//     <div data-controller="phem-otp-field" data-phem-otp-field-length-value="6">
//         <input id="code" name="code" data-phem-otp-field-target="codeInput" />
//     </div>
//
// The browser edits its text, and the controller keeps the characters that the validation type
// accepts (phem_otp_code_input.js). The input gets `autocomplete="one-time-code"`, `inputmode`,
// `maxlength` (the `length` value), and a `pattern` of the full code (`[0-9]{6}`). The `length`
// value is by default the number of `slot` targets, else 6. The `slot` targets are decorative
// (`aria-hidden="true"`): each one shows one character (`•` with `mask`), and gets `data-filled`
// and `data-active` (the slot of the caret or of the selection when the input has the focus). A
// press on a slot puts the caret before its character. The values, the events, the
// `hiddenInput` target and the auto submit are the same. The controller element does not get
// `role="group"`: the input has the label of the field.
export default class PhemOtpFieldController extends Controller {
  static targets = ["input", "codeInput", "slot", "hiddenInput", "separator"]
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
    normalize: String,
  }

  connect() {
    // A slot with the focus at connect (autofocus, or a slot that a morph keeps) is the tab stop.
    const focusedIndex = this.#slots.indexOf(document.activeElement)
    this.focused = focusedIndex >= 0 || (this.hasCodeInputTarget && document.activeElement === this.codeInputTarget)
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
    this.element.addEventListener("compositionstart", this.onCompositionstart, options)
    this.element.addEventListener("compositionend", this.onCompositionend, options)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.code?.disconnect()
    this.code = undefined
    this.rendered = undefined
  }

  // A server render with a new value, or a change of the value by a script.
  valueValueChanged() {
    if (this.rendered !== undefined && this.rendered !== this.#value) this.#render()
  }

  // A change of `mask` changes the type of the slots.
  maskValueChanged() {
    if (this.rendered !== undefined) this.#render()
  }

  inputTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  codeInputTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  slotTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  hiddenInputTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  separatorTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  // The slot keeps the text of an IME composition up to its end.
  onInput = (event) => {
    const index = this.#slots.indexOf(event.target)
    if (index < 0 || this.composing === event.target || this.composed === event.target) return

    if (this.#locked) {
      event.target.value = [...this.#value][index] ?? ""

      return
    }

    this.#commit(index, event.target.value, "input-change")
  }

  // An Android keyboard makes a composition of each word: the controller reads each `input` event.
  onCompositionstart = (event) => {
    if (this.#slots.includes(event.target) && !isAndroid()) this.composing = event.target
  }

  // WebKit sends the last `input` event of the composition after `compositionend`, in the same
  // task: the controller does not read it.
  onCompositionend = (event) => {
    if (this.composing !== event.target) return

    this.composing = null
    this.composed = event.target
    setTimeout(() => (this.composed = null), 0)
    if (this.#locked) {
      this.#render()

      return
    }

    this.#commit(this.#slots.indexOf(event.target), event.target.value, "input-change")
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
    // The keys of a composition are for the IME.
    if (this.composing) return

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
    if (this.composing === event.target) this.composing = null
    if (!this.#slots.includes(event.target) || this.#slots.includes(event.relatedTarget)) return

    this.focused = false
    this.focusedIndex = undefined
    this.#render()
  }

  // The `text` of the slot `index`: all its characters go in the slots from it on. An empty text
  // removes the character of the slot.
  #commit(index, text, reason) {
    const slot = this.#slots[index]
    const chars = [...this.#value]

    if (text === "") {
      chars.splice(index, 1)
      this.#change(chars.join(""), "input-clear")
      this.#focus(Math.min(index, this.#active))

      return
    }

    const accepted = this.#accept(text)
    if (!accepted) {
      slot.value = chars[index] ?? ""
      slot.select?.()

      return
    }

    this.#insert(index, accepted, reason)
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

  // The characters of `text` that the validation type accepts, with the `normalize` value
  // (`uppercase` or `lowercase`), and then filtered by the validation type again. A character that
  // it refuses sends `phem-otp-field:invalid`. The spaces go.
  #accept(text) {
    const accepted = this.#filter(text)
    if ([...accepted].length < [...text.replace(/\s+/g, "")].length) this.dispatch("invalid", { detail: { value: text } })

    return accepted
  }

  // The accepted characters of `text`, with no event.
  #filter(text) {
    const chars = [...text.replace(/\s+/g, "")].filter((c) => this.#accepts(c))

    return [...this.#normalized(chars.join(""))].filter((c) => this.#accepts(c)).join("")
  }

  #normalized(text) {
    if (this.normalizeValue === "uppercase") return text.toUpperCase()
    if (this.normalizeValue === "lowercase") return text.toLowerCase()

    return text
  }

  #accepts(char) {
    const pattern = PATTERNS[this.validationTypeValue]

    return pattern ? new RegExp(`^${pattern}$`).test(char) : !/\s/.test(char)
  }

  // A typed character that fills the last empty slot completes the code, and each paste that
  // fills all the slots (also with the same code). A change of a complete code does not.
  #change(value, reason) {
    const full = this.#length > 0 && [...value].length === this.#length
    const completes = full && (reason === "input-paste" || (reason === "input-change" && !this.#complete))

    if (value === this.#value) {
      this.#render()
      if (completes) this.#completed(value)

      return
    }

    this.valueValue = value
    this.#render()

    for (const el of this.hiddenInputTargets) {
      el.dispatchEvent(new Event("input", { bubbles: true }))
      el.dispatchEvent(new Event("change", { bubbles: true }))
    }

    this.dispatch("change", { detail: { value, reason } })
    if (completes) this.#completed(value)
  }

  // `phem-otp-field:complete`, and the submit of the form with `autoSubmit`.
  #completed(value) {
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

    // One input for the whole code.
    const code = this.#code
    if (code) {
      code.render({
        value,
        length: this.#length,
        pattern,
        inputMode,
        autocomplete: this.autocompleteValue,
        mask: this.maskValue,
        disabled: this.disabledValue,
        readOnly: this.readOnlyValue,
        required: this.requiredValue,
        focused: this.focused,
        state,
      })
      this.#renderValue(value)

      // The separators are decorative, as the slots.
      for (const el of this.separatorTargets) el.setAttribute("aria-hidden", "true")

      return
    }

    if (!this.element.hasAttribute("role")) this.element.setAttribute("role", "group")

    // The label of the first slot names the group.
    const label = slots[0]?.labels?.[0]
    if (label && !this.element.hasAttribute("aria-label")) link(this.element, "aria-labelledby", "ariaLabelledByElements", label)

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
      slot.type = this.maskValue ? "password" : "text"
      slot.setAttribute("autocorrect", "off")
      slot.setAttribute("enterkeyhint", i === slots.length - 1 ? "done" : "next")
      if (i === 0) slot.setAttribute("maxlength", String(this.#length))
      else slot.removeAttribute("maxlength")
      if (pattern && !slot.hasAttribute("pattern")) slot.setAttribute("pattern", pattern)
      if (i > 0 && !slot.hasAttribute("aria-label") && !slot.hasAttribute("aria-labelledby")) slot.setAttribute("aria-label", `Character ${i + 1} of ${slots.length}`)
    })

    this.#renderValue(value)

    for (const el of this.separatorTargets) {
      if (!el.hasAttribute("role")) el.setAttribute("role", "separator")
      if (!el.hasAttribute("data-orientation")) el.setAttribute("data-orientation", "horizontal")
    }
  }

  #renderValue(value) {
    for (const el of this.hiddenInputTargets) {
      el.value = value
      el.setAttribute("value", value)
      el.disabled = this.disabledValue
    }
  }

  // The one input of the code, with a `codeInput` target.
  get #code() {
    const input = this.hasCodeInputTarget ? this.codeInputTarget : null
    if (this.code?.input === input) return this.code

    this.code?.disconnect()
    this.code = input ? new OtpCodeInput(input, this.element, this.#codeHost) : undefined

    return this.code
  }

  get #codeHost() {
    return {
      slots: () => this.slotTargets,
      value: () => this.#value,
      length: () => this.#length,
      locked: () => this.#locked,
      filter: (text) => this.#filter(text),
      accept: (text) => this.#accept(text),
      accepts: (char) => this.#accepts(char),
      change: (value, reason) => this.#change(value, reason),
      focus: (focused) => {
        this.focused = focused
        this.#render()
      },
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
    if (this.hasCodeInputTarget) return this.lengthValue > 0 ? this.lengthValue : this.slotTargets.length || 6

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
    if (this.codeInputTargets.includes(el)) return STATE_ATTRIBUTES.has(name) || ["data-filled", "value"].includes(name)
    if (this.slotTargets.includes(el)) return STATE_ATTRIBUTES.has(name) || ["data-filled", "data-active"].includes(name)
    if (this.hiddenInputTargets.includes(el)) return name === "value"

    return false
  }
}

// The pattern of one character of each validation type.
const PATTERNS = { numeric: "[0-9]", alpha: "[A-Za-z]", alphanumeric: "[A-Za-z0-9]" }

const STATE_ATTRIBUTES = new Set(["data-disabled", "data-readonly", "data-required", "data-complete", "data-focused"])
