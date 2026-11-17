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

// A pointer lock that the browser gives at once. Returns the cleanup.
function fakePointerLock() {
  let locked = null
  Object.defineProperty(document, "pointerLockElement", { configurable: true, get: () => locked })
  document.body.requestPointerLock = () => {
    locked = document.body
    return Promise.resolve()
  }
  document.exitPointerLock = vi.fn(() => (locked = null))

  return () => {
    delete document.pointerLockElement
    delete document.body.requestPointerLock
    delete document.exitPointerLock
  }
}

const nextTask = () => new Promise((resolve) => setTimeout(resolve, 0))

function touchLike(pointerType) {
  return (el, type, init = {}) => {
    const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init })
    Object.defineProperty(event, "pointerType", { value: pointerType })
    el.dispatchEvent(event)

    return event
  }
}

function beforeInput(data) {
  const event = new InputEvent("beforeinput", { data, inputType: "insertText", bubbles: true, cancelable: true })
  input().dispatchEvent(event)

  return event.defaultPrevented
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
    expect(input().getAttribute("inputmode")).toBe("numeric")
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

  it("steps with the arrow keys, Alt, Shift, Home and End", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-locale-value="en-US" data-phem-number-field-min-value="0" data-phem-number-field-max-value="50"'))
    const events = record()
    input().focus()

    expect(press("ArrowUp").defaultPrevented).toBe(true)
    expect(input().value).toBe("6")
    press("ArrowDown", { altKey: true })
    expect(input().value).toBe("5.9")
    press("ArrowUp", { shiftKey: true })
    expect(input().value).toBe("15.9")
    // PageUp and PageDown are keys of the browser, as in Base UI.
    expect(press("PageDown").defaultPrevented).toBe(false)
    expect(press("PageUp").defaultPrevented).toBe(false)
    expect(state()).toBe("15.9")
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

    // A movement of less than the sensitivity changes nothing.
    pointer(document, "pointermove", { clientX: 103, clientY: 10 })
    expect(input().value).toBe("5")
    // The sensitivity is reached: one step for each pixel of this movement, as in Base UI.
    pointer(document, "pointermove", { clientX: 110, clientY: 10 })
    expect(input().value).toBe("12")
    pointer(document, "pointermove", { clientX: 104, clientY: 10 })
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
    document.body.requestPointerLock = () => {
      locked = document.body
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
    // The center of the cursor is at the place of the pointer.
    expect(cursor.style.transform).toBe("translate3d(106px, 10px, 0) scale(1)")
    expect(input().value).toBe("11")

    pointer(document, "pointerup")
    expect(locked).toBe(null)
    expect(cursor.hidden).toBe(true)
    delete document.pointerLockElement
    delete document.body.requestPointerLock
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

describe("phem-number-field: the hold and the scrub of Base UI", () => {
  it("stops the repeat while the pointer is out of the button, and at the limit", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-max-value="9"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "setInterval", "clearInterval"] })
    const inc = document.getElementById("inc")

    pointer(inc, "pointerdown")
    expect(input().value).toBe("6")
    vi.advanceTimersByTime(400 + 60)
    expect(input().value).toBe("7")

    inc.dispatchEvent(new MouseEvent("mouseleave"))
    vi.advanceTimersByTime(1000)
    expect(input().value).toBe("7")

    // The pointer comes back: one step at once, then the repeat, up to the limit.
    inc.dispatchEvent(new MouseEvent("mouseenter"))
    expect(input().value).toBe("8")
    vi.advanceTimersByTime(400 + 60 * 10)
    expect(input().value).toBe("9")
    expect(vi.getTimerCount()).toBe(0)

    pointer(window, "pointerup")
  })

  it("gives the scrub area the presentation role", async () => {
    await mount(field())

    expect(document.getElementById("area").getAttribute("role")).toBe("presentation")
  })

  it("keeps the virtual cursor near the scrub area with teleportDistance", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-teleport-distance-value="20"'))
    const area = document.getElementById("area")
    area.getBoundingClientRect = () => ({ left: 100, top: 0, right: 140, bottom: 20, width: 40, height: 20 })
    let locked = null
    Object.defineProperty(document, "pointerLockElement", { configurable: true, get: () => locked })
    document.body.requestPointerLock = () => {
      locked = document.body
      return Promise.resolve()
    }
    document.exitPointerLock = () => (locked = null)

    pointer(area, "pointerdown", { clientX: 140, clientY: 10 })
    document.dispatchEvent(new Event("pointerlockchange"))
    const move = new MouseEvent("pointermove", { bubbles: true })
    Object.defineProperty(move, "movementX", { value: 15 })
    Object.defineProperty(move, "movementY", { value: 0 })
    document.dispatchEvent(move)

    // 155 is after the right edge (140 + 10): the cursor goes to the left edge (100 - 10).
    expect(document.getElementById("cursor").style.transform).toBe("translate3d(90px, 10px, 0) scale(1)")

    pointer(document, "pointerup")
    delete document.pointerLockElement
    delete document.body.requestPointerLock
  })

  it("steps from zero when the field has no value", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US"'))
    input().focus()

    press("ArrowUp")
    expect(state()).toBe("0")
  })

  it("steps down from zero, in the limits, when the field has no value", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-max-value="-4"'))
    input().focus()

    press("ArrowDown")
    expect(state()).toBe("-4")
  })

  it("does not format the text on Enter, only on blur", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US"'))
    input().focus()

    type("1234")
    press("Enter")
    expect(input().value).toBe("1234")
    expect(state()).toBe("1234")

    input().blur()
    expect(input().value).toBe("1,234")
  })

  it("allows one sign and one decimal separator, except when they replace a selection", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US"'))
    input().focus()
    const before = (data) => {
      const event = new InputEvent("beforeinput", { data, inputType: "insertText", bubbles: true, cancelable: true })
      input().dispatchEvent(event)

      return event.defaultPrevented
    }

    type("-1.5")
    input().setSelectionRange(4, 4)
    expect(before("-")).toBe(true)
    expect(before("+")).toBe(true)
    expect(before(".")).toBe(true)
    expect(before("7")).toBe(false)

    // The selection has the sign or the separator: the new character replaces it.
    input().setSelectionRange(0, 1)
    expect(before("+")).toBe(false)
    input().setSelectionRange(2, 3)
    expect(before(".")).toBe(false)
    input().setSelectionRange(0, 4)
    expect(before("-")).toBe(false)
  })

  it("allows one percent sign with the percent style", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-format-value=\'{"style":"percent"}\''))
    input().focus()
    const before = (data) => {
      const event = new InputEvent("beforeinput", { data, inputType: "insertText", bubbles: true, cancelable: true })
      input().dispatchEvent(event)

      return event.defaultPrevented
    }

    type("50%")
    input().setSelectionRange(3, 3)
    expect(before("%")).toBe(true)
  })

  it("accepts and normalizes the digits of other scripts", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US"'))
    input().focus()
    const before = (data) => {
      const event = new InputEvent("beforeinput", { data, inputType: "insertText", bubbles: true, cancelable: true })
      input().dispatchEvent(event)

      return event.defaultPrevented
    }

    for (const digit of ["٥", "۵", "５", "五"]) expect(before(digit)).toBe(false)

    type("٤٢")
    expect(state()).toBe("42")
    type("۱۲")
    expect(state()).toBe("12")
    type("３７")
    expect(state()).toBe("37")
    type("一二")
    expect(state()).toBe("12")
  })

  it("uses the text or the decimal keyboard on iOS", async () => {
    Object.defineProperty(navigator, "userAgent", { configurable: true, get: () => "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1" })
    Object.defineProperty(navigator, "platform", { configurable: true, get: () => "iPhone" })

    try {
      await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-locale-value="en-US"'))
      expect(input().getAttribute("inputmode")).toBe("text")

      document.body.innerHTML = ""
      await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-locale-value="en-US" data-phem-number-field-min-value="0"'))
      expect(input().getAttribute("inputmode")).toBe("decimal")
    } finally {
      delete navigator.userAgent
      delete navigator.platform
    }
  })

  it("locks the pointer on the body during a scrub", async () => {
    await mount(field())
    const area = document.getElementById("area")
    const onBody = vi.fn(() => Promise.resolve())
    const onArea = vi.fn(() => Promise.resolve())
    document.body.requestPointerLock = onBody
    area.requestPointerLock = onArea

    try {
      pointer(area, "pointerdown", { clientX: 100, clientY: 10 })
      pointer(document, "pointerup")

      expect(onArea).not.toHaveBeenCalled()
      expect(onBody).toHaveBeenCalledTimes(1)
    } finally {
      delete document.body.requestPointerLock
    }
  })

  it("does not lock the pointer on WebKit", async () => {
    vi.stubGlobal("CSS", { supports: (rule) => rule === "-webkit-backdrop-filter:none" })
    const onBody = vi.fn(() => Promise.resolve())
    document.body.requestPointerLock = onBody

    try {
      await mount(field())
      const area = document.getElementById("area")
      area.requestPointerLock = onBody

      pointer(area, "pointerdown", { clientX: 100, clientY: 10 })
      pointer(document, "pointerup")

      expect(onBody).not.toHaveBeenCalled()
    } finally {
      delete document.body.requestPointerLock
    }
  })

  it("exits the pointer lock 20 ms after the release on Firefox", async () => {
    Object.defineProperty(navigator, "userAgent", { configurable: true, get: () => "Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0" })
    let locked = null
    Object.defineProperty(document, "pointerLockElement", { configurable: true, get: () => locked })
    document.body.requestPointerLock = () => {
      locked = document.body
      return Promise.resolve()
    }
    const exit = vi.fn(() => (locked = null))
    document.exitPointerLock = exit

    try {
      await mount(field())
      vi.useFakeTimers()
      const area = document.getElementById("area")

      pointer(area, "pointerdown", { clientX: 100, clientY: 10 })
      expect(locked).toBe(document.body)
      pointer(document, "pointerup")
      expect(exit).not.toHaveBeenCalled()
      expect(area.hasAttribute("data-scrubbing")).toBe(false)

      vi.advanceTimersByTime(20)
      expect(exit).toHaveBeenCalledTimes(1)
      expect(locked).toBe(null)
    } finally {
      delete navigator.userAgent
      delete document.pointerLockElement
      delete document.body.requestPointerLock
      delete document.exitPointerLock
    }
  })

  it("sends a click to the target of a scrub press with no movement", async () => {
    const unlock = fakePointerLock()

    try {
      await mount(field())
      const label = document.querySelector("#area label")
      const clicks = vi.fn()
      label.addEventListener("click", clicks)

      pointer(label, "pointerdown", { clientX: 100, clientY: 10 })
      pointer(document, "pointerup", { clientX: 100, clientY: 10 })
      await nextTask()
      expect(clicks).toHaveBeenCalledTimes(1)

      // A movement gives no click.
      pointer(label, "pointerdown", { clientX: 100, clientY: 10 })
      pointer(document, "pointermove", { clientX: 120, clientY: 10, movementX: 20 })
      pointer(document, "pointerup", { clientX: 120, clientY: 10 })
      await nextTask()
      expect(clicks).toHaveBeenCalledTimes(1)

      // A cancel gives no click.
      pointer(label, "pointerdown", { clientX: 100, clientY: 10 })
      pointer(document, "pointercancel", { clientX: 100, clientY: 10 })
      await nextTask()
      expect(clicks).toHaveBeenCalledTimes(1)
    } finally {
      unlock()
    }
  })

  it("sends no second click when the native click of the scrub press comes", async () => {
    const unlock = fakePointerLock()

    try {
      await mount(field())
      const label = document.querySelector("#area label")
      const clicks = vi.fn()
      label.addEventListener("click", clicks)

      pointer(label, "pointerdown", { clientX: 100, clientY: 10 })
      pointer(document, "pointerup", { clientX: 100, clientY: 10 })
      pointer(label, "click")
      await nextTask()
      expect(clicks).toHaveBeenCalledTimes(1)
    } finally {
      unlock()
    }
  })

  it("sends no click when the pointer was not locked: the browser sends it", async () => {
    await mount(field())
    const label = document.querySelector("#area label")
    const clicks = vi.fn()
    label.addEventListener("click", clicks)

    const event = new MouseEvent("pointerdown", { bubbles: true, cancelable: true, button: 0, clientX: 100, clientY: 10 })
    Object.defineProperty(event, "pointerType", { value: "touch" })
    label.dispatchEvent(event)
    pointer(document, "pointerup", { clientX: 100, clientY: 10 })
    await nextTask()
    expect(clicks).not.toHaveBeenCalled()
  })

  it("waits 50 ms before the repeat of a touch press", async () => {
    await mount(field())
    vi.useFakeTimers()
    const inc = document.getElementById("inc")
    const touch = (el, type, init = {}) => {
      const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init })
      Object.defineProperty(event, "pointerType", { value: "touch" })
      el.dispatchEvent(event)
    }

    touch(inc, "pointerdown")
    expect(input().value).toBe("5")
    vi.advanceTimersByTime(50)
    expect(input().value).toBe("6")
    touch(window, "pointerup")
    pointer(inc, "click")
    expect(input().value).toBe("6")
  })

  it("steps one time on the click of a short touch press", async () => {
    await mount(field())
    vi.useFakeTimers()
    const inc = document.getElementById("inc")
    const touch = (el, type, init = {}) => {
      const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init })
      Object.defineProperty(event, "pointerType", { value: "touch" })
      el.dispatchEvent(event)
    }

    touch(inc, "pointerdown")
    vi.advanceTimersByTime(20)
    touch(window, "pointerup")
    pointer(inc, "click")
    vi.advanceTimersByTime(1000)
    expect(input().value).toBe("6")
  })

  it("cancels the repeat of a touch press after three moves", async () => {
    await mount(field())
    vi.useFakeTimers()
    const inc = document.getElementById("inc")
    const touch = (el, type, init = {}) => {
      const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init })
      Object.defineProperty(event, "pointerType", { value: "touch" })
      el.dispatchEvent(event)
    }

    touch(inc, "pointerdown")
    touch(inc, "pointermove", { clientX: 1 })
    touch(inc, "pointermove", { clientX: 2 })
    touch(inc, "pointermove", { clientX: 3 })
    vi.advanceTimersByTime(1000)
    expect(input().value).toBe("5")
  })
})

describe("phem-number-field: the characters, the parse and the press", () => {
  it("refuses a minus sign when the value cannot be negative", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-min-value="0"'))
    input().focus()
    expect(beforeInput("-")).toBe(true)
    expect(beforeInput("−")).toBe(true)

    document.body.innerHTML = ""
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-min-value="0" data-phem-number-field-allow-out-of-range-value'))
    input().focus()
    expect(beforeInput("-")).toBe(false)

    document.body.innerHTML = ""
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-min-value="-5"'))
    input().focus()
    expect(beforeInput("-")).toBe(false)
  })

  it("allows one currency sign with the currency style", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-format-value=\'{"style":"currency","currency":"USD"}\''))
    input().focus()
    expect(beforeInput("$")).toBe(false)

    type("$5")
    input().setSelectionRange(2, 2)
    expect(beforeInput("$")).toBe(true)
    expect(state()).toBe("5")

    // The selection has the currency sign: the new one replaces it.
    input().setSelectionRange(0, 1)
    expect(beforeInput("$")).toBe(false)
  })

  it("allows one percent or permille sign with the percent style", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-format-value=\'{"style":"percent"}\''))
    input().focus()
    expect(beforeInput("‰")).toBe(false)

    type("5‰")
    input().setSelectionRange(2, 2)
    expect(beforeInput("‰")).toBe(true)
    expect(beforeInput("%")).toBe(true)

    document.body.innerHTML = ""
    await mount(field())
    input().focus()
    expect(beforeInput("%")).toBe(true)
    expect(beforeInput("‰")).toBe(true)
  })

  it("counts the decimal separators of other scripts as the decimal separator", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US"'))
    input().focus()

    type("1.5")
    input().setSelectionRange(3, 3)
    expect(beforeInput("٫")).toBe(true)
    expect(beforeInput("．")).toBe(true)
  })

  it("parses the separators of other scripts in the format of the locale", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US"'))

    type("٣٫٥")
    expect(state()).toBe("3.5")
    type("１，２３４")
    expect(state()).toBe("1234")
    type("١٬٢٣٤")
    expect(state()).toBe("1234")
    type("１２．５")
    expect(state()).toBe("12.5")

    document.body.innerHTML = ""
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="de-DE"'))

    type("1٫5")
    expect(state()).toBe("1.5")
    type("1，5")
    expect(state()).toBe("1.5")
    type("1.234")
    expect(state()).toBe("1234")
    type("1٬234,5")
    expect(state()).toBe("1234.5")
  })

  it("accepts the format characters of a right-to-left text", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="ar-EG"'))
    input().focus()

    expect(beforeInput("\u200f٥")).toBe(false)
    type("\u200f-٥")
    expect(state()).toBe("-5")
  })

  it("steps from no value to the min value when the min value is more than 0", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US" data-phem-number-field-min-value="5" data-phem-number-field-max-value="10"'))
    input().focus()

    press("ArrowUp")
    expect(state()).toBe("5")
  })

  it("scrubs from no value to 0", async () => {
    await mount(field('data-phem-number-field-value-value="" data-phem-number-field-locale-value="en-US"'))
    const area = document.getElementById("area")

    pointer(area, "pointerdown", { clientX: 100, clientY: 10 })
    pointer(document, "pointermove", { clientX: 110, clientY: 10 })
    pointer(document, "pointerup", { clientX: 110, clientY: 10 })
    expect(state()).toBe("0")
  })

  it("puts the value in the limits and commits on Enter, with the typed text", async () => {
    await mount(field('data-phem-number-field-value-value="5" data-phem-number-field-locale-value="en-US" data-phem-number-field-max-value="100"'))
    const events = record()
    input().focus()

    type("500")
    press("Enter")
    expect(input().value).toBe("500")
    expect(raw().value).toBe("100")
    expect(events).toEqual([
      ["change", 500, "input-change"],
      ["change", 100, "keyboard"],
      ["commit", 100, "keyboard"],
    ])

    input().blur()
    expect(input().value).toBe("100")
    expect(events).toHaveLength(3)
  })

  it("keeps the default action of a touch press, for the scroll of the page", async () => {
    await mount(field())
    vi.useFakeTimers()
    const touch = touchLike("touch")

    const down = touch(document.getElementById("inc"), "pointerdown")
    expect(down.defaultPrevented).toBe(false)
    touch(window, "pointerup")
  })

  it("cancels the repeat of a touch press after a movement of more than 8 px", async () => {
    await mount(field())
    vi.useFakeTimers()
    const inc = document.getElementById("inc")
    const touch = touchLike("touch")

    touch(inc, "pointerdown", { clientX: 0, clientY: 0 })
    touch(inc, "pointermove", { clientX: 20, clientY: 0 })
    vi.advanceTimersByTime(1000)
    expect(input().value).toBe("5")
  })

  it("waits 50 ms before the repeat of a pen press, and keeps the focus where it is", async () => {
    await mount(field())
    vi.useFakeTimers()
    const inc = document.getElementById("inc")
    const pen = touchLike("pen")

    pen(inc, "pointerdown")
    expect(input().value).toBe("5")
    expect(document.activeElement).not.toBe(input())
    vi.advanceTimersByTime(50)
    expect(input().value).toBe("6")
    pen(window, "pointerup")
  })

  it("exits the pointer lock when the field goes during a scrub", async () => {
    const unlock = fakePointerLock()

    try {
      await mount(field())
      pointer(document.getElementById("area"), "pointerdown", { clientX: 100, clientY: 10 })
      expect(document.pointerLockElement).toBe(document.body)

      document.getElementById("field").remove()
      await nextTask()
      expect(document.pointerLockElement).toBe(null)
    } finally {
      unlock()
    }
  })

  it("exits the pointer lock when the field goes during the wait of Firefox", async () => {
    Object.defineProperty(navigator, "userAgent", { configurable: true, get: () => "Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0" })
    const unlock = fakePointerLock()

    try {
      await mount(field())
      vi.useFakeTimers()
      const label = document.querySelector("#area label")
      const clicks = vi.fn()
      label.addEventListener("click", clicks)

      pointer(label, "pointerdown", { clientX: 100, clientY: 10 })
      pointer(document, "pointerup", { clientX: 100, clientY: 10 })
      expect(document.pointerLockElement).toBe(document.body)

      document.getElementById("field").remove()
      await vi.advanceTimersByTimeAsync(0)
      expect(document.pointerLockElement).toBe(null)

      vi.advanceTimersByTime(20)
      expect(document.exitPointerLock).toHaveBeenCalledTimes(1)
      expect(clicks).not.toHaveBeenCalled()
    } finally {
      delete navigator.userAgent
      unlock()
    }
  })

  it("uses the text keyboard on iPadOS, and keeps the inputmode of the markup", async () => {
    Object.defineProperty(navigator, "platform", { configurable: true, get: () => "MacIntel" })
    Object.defineProperty(navigator, "maxTouchPoints", { configurable: true, get: () => 5 })

    try {
      await mount(field())
      expect(input().getAttribute("inputmode")).toBe("text")
    } finally {
      delete navigator.platform
      delete navigator.maxTouchPoints
    }

    document.body.innerHTML = ""
    await mount(field().replace('<input id="amount"', '<input id="amount" inputmode="tel"'))
    expect(input().getAttribute("inputmode")).toBe("tel")
  })
})
