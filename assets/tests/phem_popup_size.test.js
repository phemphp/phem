import { afterEach, beforeEach, describe, expect, it } from "vitest"
import { PopupSize } from "../dist/phem_popup_size.js"

let popup
let positioner
let size

// jsdom has no layout: the size of the popup comes from the test.
function sized(el, width, height) {
  Object.defineProperty(el, "offsetWidth", { value: width, configurable: true })
  Object.defineProperty(el, "offsetHeight", { value: height, configurable: true })
}

// An animation that ends when the test calls `end()`.
function animation(props) {
  let end
  const finished = new Promise((resolve) => (end = resolve))

  return { ...props, finished, end }
}

const variable = (el, name) => el.style.getPropertyValue(name)
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
  document.body.innerHTML = `<div id="positioner"><div id="popup"></div></div>`
  popup = document.getElementById("popup")
  positioner = document.getElementById("positioner")
  size = new PopupSize({ popup: () => popup, positioner: () => positioner })
})

afterEach(() => {
  document.body.innerHTML = ""
})

describe("PopupSize", () => {
  it("sets the variables to auto at rest", () => {
    size.rest()

    expect(variable(popup, "--popup-width")).toBe("auto")
    expect(variable(popup, "--popup-height")).toBe("auto")
    expect(variable(positioner, "--positioner-width")).toBe("auto")
    expect(variable(positioner, "--positioner-height")).toBe("auto")
  })

  it("gives the new size to the positioner at once, and animates the popup to it", async () => {
    sized(popup, 200, 80)
    const width = animation({ transitionProperty: "width" })
    popup.getAnimations = () => [width]

    size.change({ width: 300, height: 120 })
    expect(variable(popup, "--popup-width")).toBe("200px")
    expect(variable(popup, "--popup-height")).toBe("80px")
    expect(variable(positioner, "--positioner-width")).toBe("200px")
    expect(variable(positioner, "--positioner-height")).toBe("80px")

    width.end()
    await flush()
    expect(variable(popup, "--popup-width")).toBe("auto")
    expect(variable(positioner, "--positioner-width")).toBe("auto")
  })

  it("does not wait for an animation that is not of the size", async () => {
    sized(popup, 200, 80)
    // An infinite pulse never ends.
    popup.getAnimations = () => [animation({ animationName: "pulse" }), animation({ transitionProperty: "opacity" })]

    size.change({ width: 300, height: 120 })
    await flush()

    expect(variable(popup, "--popup-width")).toBe("auto")
  })

  it("keeps the px size when a new change stops the first one", async () => {
    sized(popup, 200, 80)
    const first = animation({ transitionProperty: "width" })
    const second = animation({ transitionProperty: "width" })
    const animations = [first]
    popup.getAnimations = () => animations

    size.change({ width: 300, height: 120 })
    animations[0] = second
    size.change({ width: 250, height: 100 })

    // The end of the first transition does not set `auto` during the second one.
    first.end()
    await flush()
    expect(variable(popup, "--popup-width")).toBe("200px")

    second.end()
    await flush()
    expect(variable(popup, "--popup-width")).toBe("auto")
  })

  it("stays at rest with no start size", () => {
    size.change(null)

    expect(variable(popup, "--popup-width")).toBe("auto")
  })
})
