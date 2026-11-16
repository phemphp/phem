import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { dismissable } from "../dist/phem_dismiss.js"

let offs = []
let outside, low, high

// Adds a layer that the test removes in afterEach, and that removes itself when it closes, as
// Popup.hide() does.
function layer(inside, options = {}) {
  let off = null
  const dismiss = vi.fn(() => off())
  off = dismissable({ inside: [inside], dismiss, ...options })
  offs.push(off)

  return dismiss
}

function pointerdown(el, button = 0) {
  const Event = typeof PointerEvent === "function" ? PointerEvent : MouseEvent
  el.dispatchEvent(new Event("pointerdown", { button, bubbles: true, composed: true }))
}

// A pointer event of a touch, on `el`. `timeStamp` replaces the time of the event.
function touch(type, el, { clientX = 0, clientY = 0, timeStamp } = {}) {
  const event = new MouseEvent(type, { bubbles: true, composed: true, clientX, clientY })
  Object.defineProperty(event, "pointerType", { value: "touch" })
  Object.defineProperty(event, "pointerId", { value: 1 })
  if (timeStamp !== undefined) Object.defineProperty(event, "timeStamp", { value: timeStamp })
  el.dispatchEvent(event)
}

// A click of a pointer (`detail` 1), on `el`.
function click(el) {
  el.dispatchEvent(new MouseEvent("click", { bubbles: true, composed: true, detail: 1 }))
}

// A press of the primary button: a pointerdown, then a click.
function press(el) {
  pointerdown(el)
  click(el)
}

beforeEach(() => {
  document.body.innerHTML = `<div id="outside"></div><div id="low"></div><div id="high"></div>`
  outside = document.getElementById("outside")
  low = document.getElementById("low")
  high = document.getElementById("high")
})

afterEach(() => {
  for (const off of offs) off()
  offs = []
})

describe("dismissable()", () => {
  it("closes a pointerdown layer at the pointerdown outside, and not again at the click", () => {
    const dismiss = layer(low, { event: "pointerdown" })

    pointerdown(outside)
    expect(dismiss).toHaveBeenCalledOnce()
    expect(dismiss.mock.calls[0][0]).toBe("outside")

    click(outside)
    expect(dismiss).toHaveBeenCalledOnce()
  })

  it("closes a pointerdown layer at the end of a touch, not at its start", () => {
    const dismiss = layer(low, { event: "pointerdown" })

    touch("pointerdown", outside)
    expect(dismiss).not.toHaveBeenCalled()

    touch("pointerup", outside)
    expect(dismiss).toHaveBeenCalledOnce()

    click(outside)
    expect(dismiss).toHaveBeenCalledOnce()
  })

  it("closes a pointerdown layer when a touch moves more than 10 px, or the browser scrolls", () => {
    const dismiss = layer(low, { event: "pointerdown" })

    touch("pointerdown", outside)
    touch("pointermove", outside, { clientX: 8 })
    expect(dismiss).not.toHaveBeenCalled()

    touch("pointermove", outside, { clientX: 11 })
    expect(dismiss).toHaveBeenCalledOnce()

    const other = layer(high, { event: "pointerdown" })
    touch("pointerdown", outside)
    touch("pointercancel", outside)
    expect(other).toHaveBeenCalledOnce()
  })

  it("keeps a pointerdown layer open after a long touch", () => {
    const dismiss = layer(low, { event: "pointerdown" })

    touch("pointerdown", outside, { timeStamp: 0 })
    touch("pointerup", outside, { timeStamp: 1500 })

    expect(dismiss).not.toHaveBeenCalled()
  })

  it("closes a pointerdown layer at a press of the right button", () => {
    const dismiss = layer(low, { event: "pointerdown" })

    pointerdown(outside, 2)

    expect(dismiss).toHaveBeenCalledOnce()
  })

  it("closes a click layer at the click, not at the pointerdown", () => {
    const dismiss = layer(low)

    pointerdown(outside)
    expect(dismiss).not.toHaveBeenCalled()

    click(outside)
    expect(dismiss).toHaveBeenCalledOnce()
  })

  it.each(["click", "pointerdown"])("closes the layers above a press in a %s layer, not the layer and the layers below it", (event) => {
    const middle = document.createElement("div")
    document.body.append(middle)
    const lowDismiss = layer(low, { event })
    const middleDismiss = layer(middle, { event })
    const highDismiss = layer(high, { event })

    press(middle)

    expect(highDismiss).toHaveBeenCalledOnce()
    expect(middleDismiss).not.toHaveBeenCalled()
    expect(lowDismiss).not.toHaveBeenCalled()
  })

  it("closes the top click layer first, then the pointerdown layer below it at the click", () => {
    const order = []
    const lowDismiss = layer(low, { event: "pointerdown" })
    const highDismiss = layer(high)
    lowDismiss.mockImplementation(() => order.push("low"))
    highDismiss.mockImplementation(() => order.push("high"))

    pointerdown(outside)
    expect(order).toEqual([])

    click(outside)
    expect(order).toEqual(["high", "low"])
  })

  it("closes the top pointerdown layer at the pointerdown, then the click layer below it at the click", () => {
    const order = []
    const lowDismiss = layer(low)
    const highDismiss = layer(high, { event: "pointerdown" })
    lowDismiss.mockImplementation(() => order.push("low"))
    highDismiss.mockImplementation(() => order.push("high"))

    pointerdown(outside)
    expect(order).toEqual(["high"])

    click(outside)
    expect(order).toEqual(["high", "low"])
  })

  it.each(["click", "pointerdown"])("keeps a %s layer open after a drag from it to outside", (event) => {
    const dismiss = layer(low, { event })

    // The click of a drag goes to the common ancestor.
    pointerdown(low)
    click(document.body)

    expect(dismiss).not.toHaveBeenCalled()
  })

  it("closes a layer at a click with the keyboard after a pointerdown with no click", () => {
    const dismiss = vi.fn()
    offs.push(dismissable({ inside: [low], dismiss, event: "pointerdown" }))

    // The dismiss keeps the layer open, and no click follows the pointerdown (a cancelled press).
    pointerdown(outside)
    expect(dismiss).toHaveBeenCalledOnce()

    outside.dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 0 }))
    expect(dismiss).toHaveBeenCalledTimes(2)
  })

  it("closes a layer at a click with the keyboard after a drag from it", () => {
    const dismiss = layer(low)

    pointerdown(low)
    outside.dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 0 }))

    expect(dismiss).toHaveBeenCalledOnce()
  })

  it("closes a pointerdown layer at a click with the keyboard", () => {
    const dismiss = layer(low, { event: "pointerdown" })

    outside.dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 0 }))

    expect(dismiss).toHaveBeenCalledOnce()
  })

  it("keeps a pointerdown layer open with outside: false", () => {
    const dismiss = layer(low, { event: "pointerdown", outside: false })

    press(outside)

    expect(dismiss).not.toHaveBeenCalled()
  })

  it("closes only the last layer on Escape", () => {
    const lowDismiss = layer(low, { event: "pointerdown" })
    const highDismiss = layer(high)

    const event = new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true })
    document.dispatchEvent(event)

    expect(highDismiss).toHaveBeenCalledWith("escape", event)
    expect(lowDismiss).not.toHaveBeenCalled()
    expect(event.defaultPrevented).toBe(true)
  })
})
