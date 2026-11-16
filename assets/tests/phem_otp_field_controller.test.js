import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemOtpFieldController from "../dist/phem_otp_field_controller.js"
import { mount, nextDone, respond, settle } from "./support.js"

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
})
