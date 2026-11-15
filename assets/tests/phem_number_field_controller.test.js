import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemNumberFieldController from "../dist/phem_number_field_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-number-field", PhemNumberFieldController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A number field with the `attributes` of the controller element, and a "Save" button that sends
// an event. The server render has no ARIA attributes.
function field(attributes = 'data-phem-number-field-value-value="5" data-phem-number-field-locale-value="en-US"') {
  return `<div id="field" data-controller="phem-number-field" ${attributes}>
      <span id="area" data-phem-number-field-target="scrubArea"><label for="amount">Amount</label>
        <span id="cursor" data-phem-number-field-target="scrubAreaCursor" hidden>↔</span></span>
      <div id="group" data-phem-number-field-target="group">
        <button id="dec" data-phem-number-field-target="decrement">−</button>
        <input id="amount" data-phem-number-field-target="input">
        <button id="inc" data-phem-number-field-target="increment">+</button>
      </div>
      <input id="raw" type="hidden" name="amount" data-phem-number-field-target="hiddenInput">
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

const input = () => document.getElementById("amount")
const raw = () => document.getElementById("raw")
const state = () => document.getElementById("field").getAttribute("data-phem-number-field-value-value")

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  input().dispatchEvent(event)

  return event
}

function pointer(el, type, init = {}) {
  el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init }))
}

function type(text) {
  input().value = text
  input().dispatchEvent(new Event("input", { bubbles: true }))
}

// The events of the controller, in order.
function record() {
  const events = []
  for (const name of ["phem-number-field:change", "phem-number-field:commit"]) {
    document.addEventListener(name, (e) => events.push([name.split(":")[1], e.detail.value, e.detail.reason]))
  }

  return events
}

async function morphOnSave() {
  const done = nextDone()
  click("#save")
  await done
}

describe("phem-number-field", () => {
  it("formats the value and sets the attributes", async () => {
    await mount(field('data-phem-number-field-value-value="1234.5" data-phem-number-field-locale-value="en-US" data-phem-number-field-required-value'))

    expect(input().value).toBe("1,234.5")
    expect(raw().value).toBe("1234.5")
    expect(input().getAttribute("inputmode")).toBe("decimal")
    expect(input().getAttribute("aria-roledescription")).toBe("Number field")
    expect(input().getAttribute("autocomplete")).toBe("off")
    expect(input().required).toBe(true)
    expect(document.getElementById("group").getAttribute("role")).toBe("group")
    expect(document.getElementById("inc").getAttribute("aria-label")).toBe("Increase")
    expect(document.getElementById("dec").getAttribute("aria-label")).toBe("Decrease")
    expect(document.getElementById("inc").tabIndex).toBe(-1)
    expect(document.getElementById("inc").getAttribute("aria-controls")).toBe("amount")
    expect(document.getElementById("field").hasAttribute("data-required")).toBe(true)
    expect(input().hasAttribute("data-required")).toBe(true)
  })

  it("steps with the arrow keys, Alt, Shift, PageUp, Home and End", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-locale-value="en-US" data-phem-number-field-min-value="0" data-phem-number-field-max-value="50"'))
    const events = record()
    input().focus()

    expect(press("ArrowUp").defaultPrevented).toBe(true)
    expect(input().value).toBe("6")
    press("ArrowDown", { altKey: true })
    expect(input().value).toBe("5.9")
    press("ArrowUp", { shiftKey: true })
    expect(input().value).toBe("15.9")
    press("PageDown")
    expect(state()).toBe("5.9")
    press("End")
    expect(input().value).toBe("50")
    expect(document.getElementById("inc").disabled).toBe(true)
    press("ArrowUp")
    expect(input().value).toBe("50")
    press("Home")
    expect(raw().value).toBe("0")
    expect(document.getElementById("dec").disabled).toBe(true)
    expect(events.slice(0, 2)).toEqual([["change", 6, "keyboard"], ["commit", 6, "keyboard"]])
  })

  it("snaps to the step with snapOnStep", async () => {
    await mount(field('data-phem-number-field-value-value="2.5" data-phem-number-field-locale-value="en-US" data-phem-number-field-snap-on-step-value'))
    input().focus()

    press("ArrowUp")
    expect(input().value).toBe("3")
    press("ArrowDown", { shiftKey: true })
    expect(input().value).toBe("0")
  })

  it("reads the text in the format of the locale, and formats it on blur", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="de-DE" data-phem-number-field-max-value="5000"'))
    const events = record()
    input().focus()

    type("1.234,5")
    expect(state()).toBe("1234.5")
    expect(raw().value).toBe("1234.5")
    expect(input().value).toBe("1.234,5")

    type("1.234,5x")
    expect(state()).toBe("1234.5")

    type("9999")
    input().blur()
    expect(input().value).toBe("5.000")
    expect(state()).toBe("5000")
    expect(events.at(-1)).toEqual(["commit", 5000, "input-blur"])

    input().focus()
    type("")
    input().blur()
    expect(state()).toBe("")
    expect(events.at(-1)).toEqual(["commit", null, "input-clear"])
  })

  it("keeps a value out of the limits with allowOutOfRange", async () => {
    await mount(field('data-phem-number-field-value-value="1" data-phem-number-field-locale-value="en-US" data-phem-number-field-max-value="10" data-phem-number-field-allow-out-of-range-value'))
    input().focus()

    type("20")
    input().blur()
    expect(input().value).toBe("20")

    input().focus()
    press("ArrowUp")
    expect(input().value).toBe("10")
  })

  it("formats with the format value", async () => {
    await mount(field(`data-phem-number-field-value-value="0.25" data-phem-number-field-locale-value="en-US" data-phem-number-field-step-value="0.01" data-phem-number-field-format-value='{"style":"percent"}'`))
    input().focus()

    expect(input().value).toBe("25%")
    type("30%")
    expect(state()).toBe("0.3")
    press("ArrowUp")
    expect(input().value).toBe("31%")
  })

  it("refuses a character that no number has", async () => {
    await mount(field())
    const letter = new InputEvent("beforeinput", { data: "a", inputType: "insertText", bubbles: true, cancelable: true })
    const digit = new InputEvent("beforeinput", { data: "7", inputType: "insertText", bubbles: true, cancelable: true })
    input().dispatchEvent(letter)
    input().dispatchEvent(digit)

    expect(letter.defaultPrevented).toBe(true)
    expect(digit.defaultPrevented).toBe(false)
  })

  it("repeats the step while the user holds a button, and keeps the focus in the input", async () => {
    await mount(field())
    vi.useFakeTimers()
    const events = record()
    const changes = vi.fn()
    raw().addEventListener("change", changes)
    input().focus()
    const inc = document.getElementById("inc")

    pointer(inc, "pointerdown")
    expect(input().value).toBe("6")
    vi.advanceTimersByTime(399)
    expect(input().value).toBe("6")
    vi.advanceTimersByTime(1 + 60 * 3)
    expect(input().value).toBe("9")
    pointer(window, "pointerup")
    pointer(inc, "click")
    vi.advanceTimersByTime(1000)

    expect(input().value).toBe("9")
    expect(document.activeElement).toBe(input())
    expect(changes).toHaveBeenCalledTimes(1)
    expect(events.filter(([name]) => name === "commit")).toEqual([["commit", 9, "increment-press"]])

    // Shift gives the large step.
    pointer(document.getElementById("dec"), "pointerdown", { shiftKey: true })
    pointer(window, "pointerup")
    expect(input().value).toBe("-1")
  })

  it("steps on a click with no press of the pointer", async () => {
    await mount(field())

    document.getElementById("inc").click()
    expect(input().value).toBe("6")
    document.getElementById("dec").click()
    document.getElementById("dec").click()
    expect(input().value).toBe("4")
  })

  it("starts at the min value from no value", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-min-value="3"'))

    document.getElementById("inc").click()
    expect(input().value).toBe("3")
  })

  it("changes the value with the wheel when the input has the focus", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-allow-wheel-scrub-value'))
    const wheel = (deltaY) => {
      const event = new WheelEvent("wheel", { deltaY, bubbles: true, cancelable: true })
      input().dispatchEvent(event)

      return event
    }

    expect(wheel(-100).defaultPrevented).toBe(false)
    expect(input().value).toBe("5")

    input().focus()
    expect(wheel(-100).defaultPrevented).toBe(true)
    expect(input().value).toBe("6")
    wheel(100)
    wheel(100)
    expect(input().value).toBe("4")
  })

  it("scrubs with the horizontal movement of the pointer", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-pixel-sensitivity-value="4"'))
    const events = record()
    const area = document.getElementById("area")

    pointer(area, "pointerdown", { clientX: 100, clientY: 10 })
    expect(document.getElementById("field").hasAttribute("data-scrubbing")).toBe(true)
    expect(area.hasAttribute("data-scrubbing")).toBe(true)
    expect(document.activeElement).toBe(input())

    pointer(document, "pointermove", { clientX: 110, clientY: 10 })
    expect(input().value).toBe("7")
    // The 2 pixels that are left go with the next movement: 2 - 8 is -6, one step.
    pointer(document, "pointermove", { clientX: 102, clientY: 10 })
    expect(input().value).toBe("6")
    pointer(document, "pointerup")

    expect(area.hasAttribute("data-scrubbing")).toBe(false)
    expect(document.getElementById("cursor").hidden).toBe(true)
    expect(events.at(-1)).toEqual(["commit", 6, "scrub"])
  })

  it("shows the virtual cursor while the pointer is locked", async () => {
    await mount(field())
    const area = document.getElementById("area")
    let locked = null
    Object.defineProperty(document, "pointerLockElement", { configurable: true, get: () => locked })
    area.requestPointerLock = () => {
      locked = area
      return Promise.resolve()
    }
    document.exitPointerLock = () => (locked = null)

    pointer(area, "pointerdown", { clientX: 100, clientY: 10 })
    document.dispatchEvent(new Event("pointerlockchange"))
    const move = new MouseEvent("pointermove", { bubbles: true, clientX: 0, clientY: 0 })
    Object.defineProperty(move, "movementX", { value: 6 })
    Object.defineProperty(move, "movementY", { value: 0 })
    document.dispatchEvent(move)

    const cursor = document.getElementById("cursor")
    expect(cursor.hidden).toBe(false)
    expect(cursor.style.transform).toBe("translate3d(106px, 10px, 0)")
    expect(input().value).toBe("8")

    pointer(document, "pointerup")
    expect(locked).toBe(null)
    expect(cursor.hidden).toBe(true)
    delete document.pointerLockElement
  })

  it("ignores the user when disabled or read-only", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-disabled-value'))

    expect(input().disabled).toBe(true)
    expect(raw().disabled).toBe(true)
    expect(document.getElementById("inc").disabled).toBe(true)
    expect(document.getElementById("area").hasAttribute("data-disabled")).toBe(true)

    document.body.innerHTML = ""
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-read-only-value'))
    input().focus()
    press("ArrowUp")
    document.getElementById("inc").click()
    expect(input().value).toBe("5")
    expect(input().readOnly).toBe(true)
    expect(input().hasAttribute("data-readonly")).toBe(true)
  })

  it("sends input and change on the inputs", async () => {
    await mount(field())
    const seen = []
    for (const el of [input(), raw()]) for (const name of ["input", "change"]) el.addEventListener(name, () => seen.push(`${el.id}:${name}`))
    input().focus()

    press("ArrowUp")
    expect(seen).toEqual(["amount:input", "raw:input", "amount:change", "raw:change"])
  })

  it("keeps the value after the morph of an event", async () => {
    await mount(field())
    input().focus()
    press("ArrowUp")
    press("ArrowUp")
    respond(field())

    await morphOnSave()
    await Promise.resolve()

    expect(state()).toBe("7")
    expect(input().value).toBe("7")
    expect(raw().value).toBe("7")
    expect(input().getAttribute("aria-roledescription")).toBe("Number field")
    expect(document.getElementById("inc").getAttribute("aria-label")).toBe("Increase")
  })

  it("takes a new value of the server render", async () => {
    await mount(field())
    input().focus()
    press("ArrowUp")
    respond(field('data-phem-number-field-value-value="42" data-phem-number-field-locale-value="en-US"'))

    await morphOnSave()
    await Promise.resolve()

    expect(state()).toBe("42")
    expect(input().value).toBe("42")
    expect(raw().value).toBe("42")
  })
})
