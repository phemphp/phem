import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemOtpFieldController from "../dist/phem_otp_field_controller.js"
import { mount, nextDone, respond, settle, stubElementReflection } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-otp-field", PhemOtpFieldController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A code of four slots in a form, a hidden input and a "Save" button that sends an event.
function otp(attributes = "", value = "") {
  const slots = [0, 1, 2, 3].map((i) => `<input id="slot-${i}" data-phem-otp-field-target="input" />`).join("")

  return `<form id="form">
      <div id="otp" data-controller="phem-otp-field" data-phem-otp-field-value-value="${value}" ${attributes}>
        ${slots}
        <span id="dash" data-phem-otp-field-target="separator">-</span>
        <input id="code" type="hidden" name="code" data-phem-otp-field-target="hiddenInput" />
      </div>
    </form>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

const el = (id) => document.getElementById(id)
const slots = () => [0, 1, 2, 3].map((i) => el(`slot-${i}`))
const values = () => slots().map((s) => s.value).join("|")

// The user types `text` in the slot with the focus.
function type(text) {
  for (const char of text) {
    const slot = document.activeElement
    slot.value = char
    slot.dispatchEvent(new InputEvent("input", { bubbles: true, data: char, inputType: "insertText" }))
  }
}

function press(key) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
  document.activeElement.dispatchEvent(event)

  return event
}

function paste(text) {
  const event = new Event("paste", { bubbles: true, cancelable: true })
  event.clipboardData = { getData: () => text }
  document.activeElement.dispatchEvent(event)
}

describe("phem-otp-field", () => {
  it("sets the attributes of the slots", async () => {
    await mount(otp())
    const [first, second] = slots()

    expect(first.autocomplete).toBe("one-time-code")
    expect(second.autocomplete).toBe("off")
    expect(first.inputMode).toBe("numeric")
    expect(first.getAttribute("pattern")).toBe("[0-9]")
    expect(first.hasAttribute("aria-label")).toBe(false)
    expect(second.getAttribute("aria-label")).toBe("Character 2 of 4")
    expect(slots().map((s) => s.tabIndex)).toEqual([0, -1, -1, -1])
    expect(el("dash").getAttribute("role")).toBe("separator")
    expect(el("dash").getAttribute("data-orientation")).toBe("horizontal")
  })

  it("gives the tab stop to the slot with the focus, so Tab goes out of the field", async () => {
    await mount(otp("", "12"))
    expect(slots().map((s) => s.tabIndex)).toEqual([-1, -1, 0, -1])

    slots()[0].focus()
    expect(slots().map((s) => s.tabIndex)).toEqual([0, -1, -1, -1])

    press("ArrowRight")
    expect(document.activeElement).toBe(slots()[1])
    expect(slots().map((s) => s.tabIndex)).toEqual([-1, 0, -1, -1])

    el("save").focus()
    expect(slots().map((s) => s.tabIndex)).toEqual([-1, -1, 0, -1])
  })

  it("gives the tab stop to a slot that has the focus when the controller connects", async () => {
    await mount(otp("", "12").replace('data-controller="phem-otp-field"', ""))
    slots()[0].focus()

    el("otp").setAttribute("data-controller", "phem-otp-field")
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(slots().map((s) => s.tabIndex)).toEqual([0, -1, -1, -1])
  })

  it("moves to the next slot when the user types, and fills the hidden input", async () => {
    await mount(otp())
    const changes = vi.fn()
    const inputs = vi.fn()
    el("otp").addEventListener("phem-otp-field:change", (e) => changes(e.detail))
    el("code").addEventListener("input", inputs)

    slots()[0].focus()
    type("12")

    expect(values()).toBe("1|2||")
    expect(document.activeElement).toBe(slots()[2])
    expect(el("code").value).toBe("12")
    expect(el("otp").getAttribute("data-phem-otp-field-value-value")).toBe("12")
    expect(el("otp").hasAttribute("data-filled")).toBe(true)
    expect(slots()[1].hasAttribute("data-filled")).toBe(true)
    expect(slots()[2].hasAttribute("data-filled")).toBe(false)
    expect(changes).toHaveBeenLastCalledWith({ value: "12", reason: "input-change" })
    expect(inputs).toHaveBeenCalledTimes(2)
  })

  it("refuses a character of another validation type", async () => {
    await mount(otp())
    const invalid = vi.fn()
    el("otp").addEventListener("phem-otp-field:invalid", (e) => invalid(e.detail))

    slots()[0].focus()
    type("a")

    expect(values()).toBe("|||")
    expect(invalid).toHaveBeenCalledWith({ value: "a" })
  })

  it("accepts letters with the alphanumeric type", async () => {
    await mount(otp('data-phem-otp-field-validation-type-value="alphanumeric"'))

    slots()[0].focus()
    type("a1")

    expect(values()).toBe("a|1||")
    expect(slots()[0].inputMode).toBe("text")
  })

  it("normalizes the accepted characters with the normalize value", async () => {
    await mount(otp('data-phem-otp-field-validation-type-value="alphanumeric" data-phem-otp-field-normalize-value="uppercase"'))
    const invalid = vi.fn()
    el("otp").addEventListener("phem-otp-field:invalid", invalid)

    slots()[0].focus()
    type("a1")
    expect(values()).toBe("A|1||")
    expect(el("code").value).toBe("A1")

    type("-")
    expect(invalid).toHaveBeenCalledTimes(1)
    expect(values()).toBe("A|1||")
  })

  it("clears with Backspace and goes back", async () => {
    await mount(otp("", "123"))

    slots()[3].focus()
    expect(press("Backspace").defaultPrevented).toBe(true)
    expect(values()).toBe("1|2||")
    expect(document.activeElement).toBe(slots()[2])

    slots()[0].focus()
    press("Backspace")
    expect(values()).toBe("2|||")
    expect(document.activeElement).toBe(slots()[0])
  })

  it("moves with the arrow keys, Home and End", async () => {
    await mount(otp("", "12"))

    slots()[0].focus()
    press("ArrowRight")
    expect(document.activeElement).toBe(slots()[1])
    press("End")
    expect(document.activeElement).toBe(slots()[2])
    press("ArrowRight")
    expect(document.activeElement).toBe(slots()[2])
    press("ArrowLeft")
    expect(document.activeElement).toBe(slots()[1])
    press("Home")
    expect(document.activeElement).toBe(slots()[0])
  })

  it("focuses the first empty slot", async () => {
    await mount(otp("", "1"))

    slots()[3].focus()
    expect(document.activeElement).toBe(slots()[1])
  })

  it("fills the slots from the focused slot on a paste, and completes", async () => {
    await mount(otp("", "9"))
    const complete = vi.fn()
    el("otp").addEventListener("phem-otp-field:complete", (e) => complete(e.detail))

    slots()[1].focus()
    paste("12 3-4")

    expect(values()).toBe("9|1|2|3")
    expect(el("otp").hasAttribute("data-complete")).toBe(true)
    expect(complete).toHaveBeenCalledWith({ value: "9123" })
    expect(slots().map((s) => s.tabIndex)).toEqual([-1, -1, -1, 0])
  })

  it("submits the form when complete with autoSubmit", async () => {
    await mount(otp('data-phem-otp-field-auto-submit-value="true"'))
    const submit = vi.fn((e) => e.preventDefault())
    el("form").addEventListener("submit", submit)

    slots()[0].focus()
    paste("1234")

    expect(submit).toHaveBeenCalledTimes(1)
  })

  it("masks the slots and sets disabled, readOnly and required", async () => {
    await mount(otp('data-phem-otp-field-mask-value="true" data-phem-otp-field-disabled-value="true" data-phem-otp-field-required-value="true"'))

    expect(slots()[0].type).toBe("password")
    expect(slots()[0].disabled).toBe(true)
    expect(slots()[0].required).toBe(true)
    expect(el("code").disabled).toBe(true)
    expect(el("otp").hasAttribute("data-disabled")).toBe(true)
    expect(el("otp").hasAttribute("data-required")).toBe(true)
  })

  it("keeps the value after the morph", async () => {
    await mount(otp())
    slots()[0].focus()
    type("12")

    respond(otp())
    const done = nextDone()
    el("save").click()
    await done
    await settle()

    expect(values()).toBe("1|2||")
    expect(el("code").value).toBe("12")
    expect(el("otp").getAttribute("data-phem-otp-field-value-value")).toBe("12")
  })

  it("takes a new value of the server render", async () => {
    await mount(otp())
    slots()[0].focus()
    type("12")

    respond(otp("", "4321"))
    const done = nextDone()
    el("save").click()
    await done
    await settle()

    expect(values()).toBe("4|3|2|1")
    expect(el("code").value).toBe("4321")
  })
})

describe("phem-otp-field: the keys of Base UI", () => {
  function key(name, init = {}) {
    const event = new KeyboardEvent("keydown", { key: name, bubbles: true, cancelable: true, ...init })
    document.activeElement.dispatchEvent(event)

    return event
  }

  it("moves the focus to the slot before after Backspace on a slot with a character", async () => {
    await mount(otp("", "1234"))
    el("slot-2").focus()
    key("Backspace")

    expect(values()).toBe("1|2|4|")
    expect(document.activeElement).toBe(el("slot-1"))
  })

  it("removes all the characters with Ctrl+Backspace", async () => {
    await mount(otp("", "1234"))
    el("slot-2").focus()
    expect(key("Backspace", { ctrlKey: true }).defaultPrevented).toBe(true)

    expect(values()).toBe("|||")
    expect(document.activeElement).toBe(el("slot-0"))
  })

  it("goes to the first and to the last slot with ArrowUp, ArrowDown and Ctrl with an arrow", async () => {
    await mount(otp("", "12"))
    el("slot-1").focus()

    key("ArrowUp")
    expect(document.activeElement).toBe(el("slot-0"))
    key("ArrowDown")
    expect(document.activeElement).toBe(el("slot-2"))
    key("ArrowLeft", { metaKey: true })
    expect(document.activeElement).toBe(el("slot-0"))
    key("ArrowRight", { ctrlKey: true })
    expect(document.activeElement).toBe(el("slot-2"))

    // Another shortcut stays with the browser.
    expect(key("a", { ctrlKey: true }).defaultPrevented).toBe(false)
  })

  it("selects the character of a slot on a press of the mouse", async () => {
    await mount(otp("", "12"))
    const event = new MouseEvent("mousedown", { bubbles: true, cancelable: true })
    el("slot-1").dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(el("slot-1"))
    expect([el("slot-1").selectionStart, el("slot-1").selectionEnd]).toEqual([0, 1])
  })

  it("commits the text of an IME composition one time, at its end", async () => {
    await mount(otp())
    const changes = vi.fn()
    el("otp").addEventListener("phem-otp-field:change", (e) => changes(e.detail))
    const [first] = slots()
    first.focus()

    first.dispatchEvent(new CompositionEvent("compositionstart", { bubbles: true }))
    first.value = "1"
    first.dispatchEvent(new InputEvent("input", { bubbles: true, data: "1", inputType: "insertCompositionText", isComposing: true }))
    first.value = "12"
    first.dispatchEvent(new InputEvent("input", { bubbles: true, data: "12", inputType: "insertCompositionText", isComposing: true }))
    expect(el("otp").getAttribute("data-phem-otp-field-value-value")).toBe("")
    expect(changes).not.toHaveBeenCalled()

    // The keys of the composition are for the IME.
    expect(press("Backspace").defaultPrevented).toBe(false)

    first.dispatchEvent(new CompositionEvent("compositionend", { bubbles: true, data: "12" }))
    expect(el("otp").getAttribute("data-phem-otp-field-value-value")).toBe("12")
    expect(changes).toHaveBeenCalledTimes(1)
    expect(changes).toHaveBeenCalledWith({ value: "12", reason: "input-change" })
  })

  it("completes on a paste of the same value", async () => {
    await mount(otp("data-phem-otp-field-auto-submit-value", "1234"))
    const complete = vi.fn()
    const submit = vi.fn((e) => e.preventDefault())
    el("otp").addEventListener("phem-otp-field:complete", (e) => complete(e.detail))
    el("form").addEventListener("submit", submit)
    el("slot-0").focus()

    paste("1234")
    expect(complete).toHaveBeenCalledWith({ value: "1234" })
    expect(submit).toHaveBeenCalledTimes(1)
  })

  it("does not complete again when the user changes a character of a complete code", async () => {
    await mount(otp("", "1234"))
    const complete = vi.fn()
    el("otp").addEventListener("phem-otp-field:complete", (e) => complete(e.detail))
    el("slot-1").focus()

    type("9")
    expect(el("otp").getAttribute("data-phem-otp-field-value-value")).toBe("1934")
    expect(complete).not.toHaveBeenCalled()
  })

  it("puts the two characters of one slot in two slots", async () => {
    await mount(otp("", "1"))
    const first = el("slot-0")
    first.focus()

    // The caret is after the character: the browser gives the slot "12".
    first.value = "12"
    first.dispatchEvent(new InputEvent("input", { bubbles: true, data: "2", inputType: "insertText" }))

    expect(el("otp").getAttribute("data-phem-otp-field-value-value")).toBe("12")
    expect(values()).toBe("1|2||")
  })

  it("gives the root the group role and the label of the first slot", async () => {
    await mount(`<label id="label" for="slot-0">Verification code</label>${otp()}`)

    expect(el("otp").getAttribute("role")).toBe("group")
    expect(el("otp").getAttribute("aria-labelledby")).toBe("label")
  })

  it("uses an element reference for a label with no id", async () => {
    const unstub = stubElementReflection()

    try {
      await mount(`<label for="slot-0">Verification code</label>${otp()}`)
      const label = document.querySelector("label")

      expect(el("otp").getAttribute("aria-labelledby")).toBe("")
      expect(el("otp").ariaLabelledByElements).toEqual([label])
    } finally {
      unstub()
    }
  })

  it("keeps the aria-label of the root", async () => {
    await mount(`<label id="label" for="slot-0">Verification code</label>${otp('aria-label="Code"')}`)

    expect(el("otp").getAttribute("aria-label")).toBe("Code")
    expect(el("otp").hasAttribute("aria-labelledby")).toBe(false)
  })

  it("sets the text type, autocorrect, enterkeyhint and maxlength of the slots", async () => {
    await mount(otp())
    const all = slots()

    expect(all.map((s) => s.getAttribute("type"))).toEqual(["text", "text", "text", "text"])
    expect(all.map((s) => s.getAttribute("autocorrect"))).toEqual(["off", "off", "off", "off"])
    expect(all.map((s) => s.getAttribute("enterkeyhint"))).toEqual(["next", "next", "next", "done"])
    expect(all.map((s) => s.getAttribute("maxlength"))).toEqual(["4", null, null, null])
  })

  it("keeps the password type of the slots with mask", async () => {
    await mount(otp("data-phem-otp-field-mask-value"))

    expect(slots().map((s) => s.getAttribute("type"))).toEqual(["password", "password", "password", "password"])
  })
})

describe("phem-otp-field: the composition, the attributes and the complete event", () => {
  const value = () => el("otp").getAttribute("data-phem-otp-field-value-value")
  const compose = (slot, texts) => {
    slot.dispatchEvent(new CompositionEvent("compositionstart", { bubbles: true }))
    for (const text of texts) {
      slot.value = text
      slot.dispatchEvent(new InputEvent("input", { bubbles: true, data: text, inputType: "insertCompositionText", isComposing: true }))
    }
  }

  it("does not read the last input event of a composition that WebKit sends after its end", async () => {
    await mount(otp())
    const changes = vi.fn()
    el("otp").addEventListener("phem-otp-field:change", (e) => changes(e.detail))
    const first = el("slot-0")
    first.focus()

    compose(first, ["1", "12"])
    first.dispatchEvent(new CompositionEvent("compositionend", { bubbles: true, data: "12" }))
    first.value = "12"
    first.dispatchEvent(new InputEvent("input", { bubbles: true, data: "12", inputType: "insertFromComposition" }))

    expect(value()).toBe("12")
    expect(changes).toHaveBeenCalledTimes(1)
    expect(document.activeElement).toBe(el("slot-2"))
  })

  it("reads each input event of a composition on Android", async () => {
    Object.defineProperty(navigator, "userAgent", { configurable: true, get: () => "Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36" })

    try {
      await mount(otp())
      el("slot-0").focus()

      compose(el("slot-0"), ["1"])
      expect(value()).toBe("1")
    } finally {
      delete navigator.userAgent
    }
  })

  it("gives the old value again at the end of a composition in a read-only field", async () => {
    await mount(otp("data-phem-otp-field-read-only-value", "1"))
    const first = el("slot-0")
    first.focus()

    compose(first, ["9"])
    first.dispatchEvent(new CompositionEvent("compositionend", { bubbles: true, data: "9" }))

    expect(value()).toBe("1")
    expect(first.value).toBe("1")
  })

  it("ends the composition when the focus goes out of the slot", async () => {
    await mount(`${otp()}<input id="other">`)
    const first = el("slot-0")
    first.focus()

    compose(first, ["1"])
    el("other").focus()
    first.focus()
    type("5")

    expect(value()).toBe("5")
  })

  it("replaces the type of the markup, and follows a change of mask", async () => {
    await mount(otp().replace('<input id="slot-0"', '<input id="slot-0" type="tel"'))
    expect(el("slot-0").getAttribute("type")).toBe("text")

    el("otp").setAttribute("data-phem-otp-field-mask-value", "")
    await settle()
    expect(el("slot-0").getAttribute("type")).toBe("password")

    el("otp").setAttribute("data-phem-otp-field-mask-value", "false")
    await settle()
    expect(el("slot-0").getAttribute("type")).toBe("text")
  })

  it("removes the maxlength of the markup from the slots after the first one", async () => {
    await mount(otp().replace('<input id="slot-1"', '<input id="slot-1" maxlength="1"'))

    expect(el("slot-0").getAttribute("maxlength")).toBe("4")
    expect(el("slot-1").hasAttribute("maxlength")).toBe(false)
  })

  it("keeps the role and the aria-labelledby of the markup", async () => {
    await mount(`<label id="label" for="slot-0">Code</label><span id="other">Other</span>${otp('role="region" aria-labelledby="other"')}`)

    expect(el("otp").getAttribute("role")).toBe("region")
    expect(el("otp").getAttribute("aria-labelledby")).toBe("other")
  })

  it("completes again when the user types the missing character of a code", async () => {
    await mount(otp("", "1234"))
    const complete = vi.fn()
    el("otp").addEventListener("phem-otp-field:complete", (e) => complete(e.detail))
    el("slot-3").focus()

    press("Backspace")
    expect(value()).toBe("123")
    expect(complete).not.toHaveBeenCalled()

    el("slot-3").focus()
    type("9")
    expect(complete).toHaveBeenCalledTimes(1)
    expect(complete).toHaveBeenCalledWith({ value: "1239" })
  })

  it("does not complete on a key that keeps the code complete", async () => {
    await mount(otp("", "1234"))
    const complete = vi.fn()
    el("otp").addEventListener("phem-otp-field:complete", (e) => complete(e.detail))
    el("slot-0").focus()

    press("ArrowRight")
    press("End")
    expect(complete).not.toHaveBeenCalled()
  })
})

