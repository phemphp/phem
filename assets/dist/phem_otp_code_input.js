import { isAndroid } from "./phem_platform.js"

// The one input of a `phem-otp-field` for the whole code (the `codeInput` target), and its
// decorative slots (the `slot` targets). The browser edits the text of the input. This class reads
// the text after each change, and gives it to the field: the field filters it, keeps the value and
// sends the events. Then `render()` puts the value in the input, and one character in each slot.
//
//     const code = new OtpCodeInput(input, element, {
//       slots: () => [...],                 // the slot elements
//       value: () => "123",                 // the value of the field
//       length: () => 6,                    // the number of characters
//       locked: () => false,                // disabled or read-only
//       filter: (text) => "123",            // the accepted characters, with no event
//       accept: (text) => "123",            // the accepted characters, with the invalid event
//       accepts: (char) => true,            // the validation type accepts this character
//       change: (value, reason) => {},      // a change of the value by the user
//       focus: (focused) => {},             // the focus goes in or out of the input
//     })
//     code.render({ ... })
//     code.disconnect()
export class OtpCodeInput {
  #host
  #listening = new AbortController()
  #composing = false
  #composed = false

  constructor(input, element, host) {
    this.input = input
    this.#host = host

    const options = { signal: this.#listening.signal }
    input.addEventListener("input", this.#onInput, options)
    input.addEventListener("paste", this.#onPaste, options)
    input.addEventListener("focus", this.#onFocus, options)
    input.addEventListener("blur", this.#onBlur, options)
    input.addEventListener("compositionstart", this.#onCompositionstart, options)
    input.addEventListener("compositionend", this.#onCompositionend, options)
    element.addEventListener("mousedown", this.#onMousedown, options)
    document.addEventListener("selectionchange", this.#onSelectionchange, options)
  }

  disconnect() {
    this.#listening.abort()
  }

  // The attributes of the input, and the characters and the caret of the slots.
  render({ value, length, pattern, inputMode, autocomplete, mask, disabled, readOnly, required, focused, state }) {
    const input = this.input
    if (input.value !== value && !this.#composing) input.value = value

    input.inputMode = inputMode
    input.autocomplete = autocomplete
    input.disabled = disabled
    input.readOnly = readOnly
    input.required = required
    input.spellcheck = false
    input.type = mask ? "password" : "text"
    input.setAttribute("autocorrect", "off")
    input.setAttribute("enterkeyhint", "done")
    input.setAttribute("maxlength", String(length))
    input.toggleAttribute("data-filled", value !== "")
    for (const [name, on] of Object.entries(state)) input.toggleAttribute(name, on)
    if (pattern && !input.hasAttribute("pattern")) input.setAttribute("pattern", `${pattern}{${length}}`)

    this.#renderSlots(value, length, mask, focused, state)
  }

  #renderSlots(value, length, mask, focused, state) {
    const chars = [...value]
    const [start, end] = this.#selection(chars.length, length)

    this.#host.slots().forEach((slot, i) => {
      const char = i < length ? (chars[i] ?? "") : ""
      const shown = mask && char ? "•" : char
      if (slot.textContent !== shown) slot.textContent = shown

      for (const [name, on] of Object.entries(state)) slot.toggleAttribute(name, on)
      slot.toggleAttribute("data-filled", char !== "")
      slot.toggleAttribute("data-active", focused && i >= start && i < end)
      slot.setAttribute("aria-hidden", "true")
    })
  }

  // The slots of the selection of the input: the slot of the caret, or the selected slots. The
  // caret after the last character is on the last slot.
  #selection(filled, length) {
    const start = Math.min(this.input.selectionStart ?? filled, length - 1)
    const end = Math.max(start + 1, Math.min(this.input.selectionEnd ?? filled, length))

    return [start, end]
  }

  #onInput = () => {
    if (this.#composing || this.#composed) return

    this.#commit("input-change")
  }

  // An Android keyboard makes a composition of each word: the input reads each `input` event.
  #onCompositionstart = () => {
    if (!isAndroid()) this.#composing = true
  }

  // WebKit sends the last `input` event of the composition after `compositionend`, in the same
  // task: the input does not read it.
  #onCompositionend = () => {
    if (!this.#composing) return

    this.#composing = false
    this.#composed = true
    setTimeout(() => (this.#composed = false), 0)
    this.#commit("input-change")
  }

  // The text of the input is the code. The caret stays after the same accepted characters.
  #commit(reason) {
    const input = this.input
    const value = this.#host.value()

    if (this.#host.locked()) {
      input.value = value

      return
    }

    const text = input.value
    const caret = input.selectionStart ?? text.length
    const length = this.#host.length()
    const next = [...this.#host.accept(text)].slice(0, length).join("")
    const at = Math.min([...this.#host.filter(text.slice(0, caret))].length, [...next].length)

    this.#host.change(next, next === "" ? "input-clear" : reason)
    this.#place(at, at)
  }

  // A paste without the spaces and the dashes of a formatted code (`123-456`). A full code
  // replaces the value. A part of a code goes in the place of the selection.
  #onPaste = (event) => {
    event.preventDefault()
    if (this.#host.locked()) return

    const pasted = (event.clipboardData?.getData("text/plain") ?? "").replace(/\s+/g, "")
    const accepted = this.#host.accept(this.#host.accepts("-") ? pasted : pasted.replaceAll("-", ""))
    if (!accepted) return

    const length = this.#host.length()
    const chars = [...this.#host.value()]
    const added = [...accepted]
    const start = Math.min(this.input.selectionStart ?? chars.length, chars.length)
    const end = Math.max(start, Math.min(this.input.selectionEnd ?? start, chars.length))
    const next = added.length >= length
      ? added.slice(0, length)
      : [...chars.slice(0, start), ...added, ...chars.slice(end)].slice(0, length)
    const at = Math.min(start + added.length, next.length)

    this.#host.change(next.join(""), "input-paste")
    this.#place(at, at)
  }

  // A press on a slot puts the caret in the input, before the character of the slot, and selects
  // it: a new character replaces it.
  #onMousedown = (event) => {
    const index = this.#host.slots().findIndex((slot) => slot.contains(event.target))
    if (index < 0 || event.defaultPrevented || this.input.disabled) return

    event.preventDefault()

    const filled = [...this.#host.value()].length
    const at = Math.min(index, filled)

    this.input.focus()
    this.#place(at, Math.min(at + 1, filled))
  }

  #onFocus = () => this.#host.focus(true)

  #onBlur = () => {
    this.#composing = false
    this.#host.focus(false)
  }

  // A move of the caret moves the active slot.
  #onSelectionchange = () => {
    if (document.activeElement === this.input) this.#host.focus(true)
  }

  #place(start, end) {
    if (document.activeElement !== this.input) return

    this.input.setSelectionRange?.(start, end)
    this.#host.focus(true)
  }
}
