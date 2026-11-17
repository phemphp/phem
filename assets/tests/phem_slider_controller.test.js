import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemSliderController from "../dist/phem_slider_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-slider", PhemSliderController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A slider with one thumb for each name, the `attributes` of the controller element, and a "Save"
// button that sends an event. The server render has no ARIA attributes.
function slider(attributes = 'data-phem-slider-value-value="25"', names = ["volume"]) {
  const thumb = (n, i) => `<div id="thumb-${i}" data-phem-slider-target="thumb"><input id="input-${i}" type="range" name="${n}"></div>`

  return `<div id="slider" data-controller="phem-slider" ${attributes}>
      <div id="label" data-phem-slider-target="label">Volume</div>
      <output id="value" data-phem-slider-target="value"></output>
      <div id="control" data-phem-slider-target="control">
        <div id="track" data-phem-slider-target="track">
          <div id="indicator" data-phem-slider-target="indicator"></div>
          ${names.map(thumb).join("")}
        </div>
      </div>
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

const range = (attributes = 'data-phem-slider-value-value="[20, 60]"') => slider(attributes, ["min", "max"])
const el = (id) => document.getElementById(id)
const state = () => el("slider").getAttribute("data-phem-slider-value-value")

function press(target, key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  target.dispatchEvent(event)

  return event
}

function pointer(target, type, init = {}) {
  target.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init }))
}

// The control is 200 px wide: one pixel is half a step.
function layout() {
  el("control").getBoundingClientRect = () => ({ left: 0, top: 0, right: 200, bottom: 20, width: 200, height: 20, x: 0, y: 0 })
}

function record() {
  const events = []
  for (const name of ["phem-slider:change", "phem-slider:commit"]) {
    document.addEventListener(name, (e) => events.push([name.split(":")[1], e.detail.value, e.detail.reason]))
  }

  return events
}

async function morphOnSave() {
  const done = nextDone()
  click("#save")
  await done
}

describe("phem-slider", () => {
  it("sets the positions, the inputs and the attributes", async () => {
    await mount(slider())

    expect(el("thumb-0").style.position).toBe("absolute")
    expect(el("thumb-0").style.getPropertyValue("inset-inline-start") || el("thumb-0").style.insetInlineStart).toBe("25%")
    expect(el("indicator").style.width).toBe("25%")
    expect(el("track").style.position).toBe("relative")
    expect(el("control").style.touchAction).toBe("none")
    expect(el("thumb-0").getAttribute("data-index")).toBe("0")
    expect(el("slider").getAttribute("role")).toBe("group")
    expect(el("slider").getAttribute("aria-labelledby")).toBe("label")
    expect(el("input-0").getAttribute("aria-labelledby")).toBe("label")
    expect(el("input-0").value).toBe("25")
    expect(el("input-0").min).toBe("0")
    expect(el("input-0").max).toBe("100")
    expect(el("input-0").getAttribute("aria-valuenow")).toBe("25")
    expect(el("input-0").getAttribute("aria-orientation")).toBe("horizontal")
    expect(el("input-0").hasAttribute("aria-valuetext")).toBe(false)
    expect(el("value").textContent).toBe("25")
    expect(el("value").getAttribute("aria-live")).toBe("off")
    for (const id of ["slider", "control", "track", "indicator", "thumb-0", "value"]) expect(el(id).getAttribute("data-orientation")).toBe("horizontal")
  })

  it("steps with the keys of a thumb input", async () => {
    await mount(slider())
    const events = record()
    const input = el("input-0")

    expect(press(input, "ArrowRight").defaultPrevented).toBe(true)
    expect(input.value).toBe("26")
    press(input, "ArrowUp", { shiftKey: true })
    expect(input.value).toBe("36")
    press(input, "PageDown")
    press(input, "ArrowLeft")
    expect(state()).toBe("25")
    press(input, "End")
    expect(el("value").textContent).toBe("100")
    press(input, "Home")
    expect(input.getAttribute("aria-valuenow")).toBe("0")
    expect(events.slice(0, 2)).toEqual([["change", 26, "keyboard"], ["commit", 26, "keyboard"]])
  })

  it("moves the nearest thumb to a press on the control, and drags it", async () => {
    await mount(slider())
    layout()
    const events = record()

    pointer(el("track"), "pointerdown", { clientX: 100 })
    expect(state()).toBe("50")
    expect(el("slider").hasAttribute("data-dragging")).toBe(true)
    expect(el("thumb-0").hasAttribute("data-dragging")).toBe(true)
    expect(document.activeElement).toBe(el("input-0"))

    pointer(document, "pointermove", { clientX: 151 })
    expect(state()).toBe("76")
    pointer(document, "pointermove", { clientX: 400 })
    expect(state()).toBe("100")
    pointer(document, "pointerup")

    expect(el("slider").hasAttribute("data-dragging")).toBe(false)
    expect(events.filter(([name]) => name === "commit")).toEqual([["commit", 100, "track-press"]])
  })

  it("keeps the offset of a press on a thumb", async () => {
    await mount(slider())
    layout()

    // The thumb is at 50 px. A press at 56 px does not move it.
    pointer(el("thumb-0"), "pointerdown", { clientX: 56 })
    expect(state()).toBe("25")
    pointer(document, "pointermove", { clientX: 66 })
    expect(state()).toBe("30")
    pointer(document, "pointerup")
  })

  it("moves the thumbs of a range, and they do not cross", async () => {
    await mount(range('data-phem-slider-value-value="[20, 60]" data-phem-slider-min-steps-between-values-value="5"'))
    layout()

    expect(el("indicator").style.getPropertyValue("inset-inline-start") || el("indicator").style.insetInlineStart).toBe("20%")
    expect(el("indicator").style.width).toBe("40%")
    expect(el("input-0").getAttribute("aria-valuetext")).toBe("20 start range")
    expect(el("input-1").getAttribute("aria-valuetext")).toBe("60 end range")
    expect(el("value").textContent).toBe("20 – 60")

    // The press goes to the nearest thumb.
    pointer(el("track"), "pointerdown", { clientX: 100 })
    expect(state()).toBe("[20,50]")
    pointer(document, "pointerup")

    // The keys stop the thumb 5 steps before the other thumb.
    press(el("input-0"), "End")
    expect(state()).toBe("[45,50]")

    // A drag pushes the other thumb.
    pointer(el("thumb-0"), "pointerdown", { clientX: 90 })
    pointer(document, "pointermove", { clientX: 150 })
    expect(state()).toBe("[75,80]")
    expect(el("thumb-0").style.zIndex).toBe("2")
    pointer(document, "pointerup")
    expect(el("thumb-0").style.zIndex).toBe("1")
  })

  it("swaps the thumbs with thumbCollisionBehavior swap", async () => {
    await mount(range('data-phem-slider-value-value="[20, 60]" data-phem-slider-thumb-collision-behavior-value="swap"'))
    layout()

    pointer(el("thumb-0"), "pointerdown", { clientX: 40 })
    pointer(document, "pointermove", { clientX: 160 })
    expect(state()).toBe("[60,80]")

    // The dragged value is now the second thumb: it stays on top.
    expect(el("thumb-1").style.zIndex).toBe("2")
    expect(el("thumb-0").style.zIndex).toBe("")
    pointer(document, "pointermove", { clientX: 170 })
    expect(state()).toBe("[60,85]")
    pointer(document, "pointerup")
    expect(el("thumb-1").style.zIndex).toBe("1")
  })

  it("stops a thumb at the other thumb with thumbCollisionBehavior none", async () => {
    await mount(range('data-phem-slider-value-value="[20, 60]" data-phem-slider-thumb-collision-behavior-value="none"'))
    layout()

    pointer(el("thumb-0"), "pointerdown", { clientX: 40 })
    pointer(document, "pointermove", { clientX: 160 })
    expect(state()).toBe("[60,60]")
    pointer(document, "pointerup")
  })

  it("uses the vertical orientation", async () => {
    await mount(slider('data-phem-slider-value-value="30" data-phem-slider-orientation-value="vertical"'))
    el("control").getBoundingClientRect = () => ({ left: 0, top: 0, right: 20, bottom: 100, width: 20, height: 100, x: 0, y: 0 })

    expect(el("thumb-0").style.bottom).toBe("30%")
    expect(el("indicator").style.height).toBe("30%")
    expect(el("input-0").getAttribute("aria-orientation")).toBe("vertical")

    pointer(el("track"), "pointerdown", { clientY: 10 })
    expect(state()).toBe("90")
    pointer(document, "pointerup")
  })

  it("formats the value with the format and the locale", async () => {
    await mount(slider(`data-phem-slider-value-value="0.5" data-phem-slider-max-value="1" data-phem-slider-step-value="0.01" data-phem-slider-locale-value="en-US" data-phem-slider-format-value='{"style":"percent"}'`))

    expect(el("value").textContent).toBe("50%")
    expect(el("input-0").getAttribute("aria-valuetext")).toBe("50%")
    press(el("input-0"), "ArrowRight")
    expect(el("value").textContent).toBe("51%")
  })

  it("ignores the user when disabled", async () => {
    await mount(slider('data-phem-slider-value-value="25" data-phem-slider-disabled-value'))
    layout()

    pointer(el("track"), "pointerdown", { clientX: 100 })
    press(el("input-0"), "ArrowRight")
    expect(state()).toBe("25")
    expect(el("input-0").disabled).toBe(true)
    expect(el("thumb-0").hasAttribute("data-disabled")).toBe(true)
  })

  it("sends input and change on the inputs that change", async () => {
    await mount(range())
    const seen = []
    for (const id of ["input-0", "input-1"]) for (const name of ["input", "change"]) el(id).addEventListener(name, () => seen.push(`${id}:${name}`))

    press(el("input-1"), "ArrowRight")
    expect(seen).toEqual(["input-1:input", "input-1:change"])
  })

  it("reads a change of a thumb input that the controller did not make", async () => {
    await mount(slider())

    el("input-0").value = "70"
    el("input-0").dispatchEvent(new Event("input", { bubbles: true }))
    expect(state()).toBe("70")
    expect(el("value").textContent).toBe("70")
  })

  it("keeps the values after the morph of an event", async () => {
    await mount(range())
    press(el("input-0"), "ArrowRight")
    respond(range())

    await morphOnSave()
    await Promise.resolve()

    expect(state()).toBe("[21,60]")
    expect(el("input-0").value).toBe("21")
    expect(el("input-0").getAttribute("aria-valuenow")).toBe("21")
    expect(el("thumb-0").style.getPropertyValue("inset-inline-start") || el("thumb-0").style.insetInlineStart).toBe("21%")
    expect(el("value").textContent).toBe("21 – 60")
  })

  it("takes a new value of the server render", async () => {
    await mount(range())
    press(el("input-0"), "ArrowRight")
    respond(range('data-phem-slider-value-value="[5, 95]"'))

    await morphOnSave()
    await Promise.resolve()

    expect(state()).toBe("[5, 95]")
    expect(el("input-1").value).toBe("95")
    expect(el("value").textContent).toBe("5 – 95")
  })
})

describe("phem-slider: the thumb of a press", () => {
  it("gives the focus to the thumb input with no focus ring", async () => {
    await mount(slider())
    layout()
    const focus = vi.spyOn(el("input-0"), "focus")

    pointer(el("track"), "pointerdown", { clientX: 100 })
    pointer(document, "pointerup")

    expect(focus).toHaveBeenCalledWith({ preventScroll: true, focusVisible: false })
  })

  it("puts a single thumb on top only during the press", async () => {
    await mount(slider())
    layout()
    expect(el("thumb-0").style.zIndex).toBe("")

    pointer(el("track"), "pointerdown", { clientX: 100 })
    expect(el("thumb-0").style.zIndex).toBe("1")

    pointer(document, "pointerup")
    expect(el("thumb-0").style.zIndex).toBe("")
  })

  it("puts the active thumb of a range on top, and the last used thumb under it", async () => {
    await mount(range())
    layout()

    pointer(el("thumb-0"), "pointerdown", { clientX: 40 })
    expect(el("thumb-0").style.zIndex).toBe("2")
    expect(el("thumb-1").style.zIndex).toBe("")

    pointer(document, "pointerup")
    expect(el("thumb-0").style.zIndex).toBe("1")
    expect(el("thumb-1").style.zIndex).toBe("")

    pointer(el("thumb-1"), "pointerdown", { clientX: 120 })
    expect(el("thumb-1").style.zIndex).toBe("2")
    expect(el("thumb-0").style.zIndex).toBe("")

    pointer(document, "pointerup")
    expect(el("thumb-1").style.zIndex).toBe("1")
    expect(el("thumb-0").style.zIndex).toBe("")
  })

  it("puts the nearest thumb of a range on top during a press on the track", async () => {
    await mount(range())
    layout()

    pointer(el("track"), "pointerdown", { clientX: 130 })
    expect(el("thumb-1").style.zIndex).toBe("2")
    expect(el("thumb-0").style.zIndex).toBe("")
    pointer(document, "pointerup")
  })

  it("gives no z-index to a single thumb after a key", async () => {
    await mount(slider())
    press(el("input-0"), "ArrowRight")

    expect(state()).toBe("26")
    expect(el("thumb-0").style.zIndex).toBe("")
  })

  it("puts the thumb of a range that a key moved over the other thumb", async () => {
    await mount(range())
    press(el("input-1"), "End")

    expect(el("thumb-1").style.zIndex).toBe("1")
    expect(el("thumb-0").style.zIndex).toBe("")
  })
})
