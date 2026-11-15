import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemToggleController from "../dist/phem_toggle_controller.js"
import PhemToggleGroupController from "../dist/phem_toggle_group_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-toggle", PhemToggleController)
  application.register("phem-toggle-group", PhemToggleGroupController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A group of three buttons (left, center, right), the `attributes` of the group, and the
// `attributes` of each item.
function group(attributes = "", items = {}) {
  const item = (value) => `<button id="${value}" type="button" data-phem-toggle-group-target="item" data-value="${value}" ${items[value] ?? ""}>${value}</button>`

  return `<div id="group" data-controller="phem-toggle-group" aria-label="Alignment" ${attributes}>
      ${["left", "center", "right"].map(item).join("")}
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

// A group of `phem-toggle` buttons.
function toggles(attributes = "") {
  return group(attributes, { left: 'data-controller="phem-toggle"', center: 'data-controller="phem-toggle"', right: 'data-controller="phem-toggle"' })
}

function el(id) {
  return document.getElementById(id)
}

function pressed() {
  return ["left", "center", "right"].filter((id) => el(id).getAttribute("aria-pressed") === "true")
}

function tabbable() {
  return ["left", "center", "right"].filter((id) => el(id).tabIndex === 0)
}

function press(key) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
  document.activeElement.dispatchEvent(event)

  return event
}

async function morphOnSave() {
  const done = nextDone()
  click("#save")
  await done
  await settle()
}

describe("phem-toggle-group", () => {
  it("presses one item at a time", async () => {
    await mount(group(`data-phem-toggle-group-value-value='["left"]'`))
    const values = []
    el("group").addEventListener("phem-toggle-group:change", (event) => values.push(event.detail.value))

    expect(el("group").getAttribute("role")).toBe("group")
    expect(el("group").getAttribute("data-orientation")).toBe("horizontal")
    expect(el("group").hasAttribute("data-multiple")).toBe(false)
    expect(pressed()).toEqual(["left"])
    expect(el("left").hasAttribute("data-pressed")).toBe(true)
    expect(el("center").getAttribute("aria-pressed")).toBe("false")
    expect(el("center").getAttribute("data-orientation")).toBe("horizontal")

    click("#center")
    expect(pressed()).toEqual(["center"])
    expect(el("left").hasAttribute("data-pressed")).toBe(false)
    expect(el("group").getAttribute("data-phem-toggle-group-value-value")).toBe('["center"]')

    // A click on the pressed item releases it.
    click("#center")
    expect(pressed()).toEqual([])
    expect(values).toEqual([["center"], []])
  })

  it("presses more than one item with multiple", async () => {
    await mount(group("data-phem-toggle-group-multiple-value"))

    expect(el("group").hasAttribute("data-multiple")).toBe(true)
    click("#right")
    click("#left")
    expect(pressed()).toEqual(["left", "right"])
    expect(el("group").getAttribute("data-phem-toggle-group-value-value")).toBe('["right","left"]')

    click("#right")
    expect(pressed()).toEqual(["left"])
  })

  it("moves the focus with the arrow keys, Home and End", async () => {
    await mount(group(`data-phem-toggle-group-value-value='["center"]'`, { right: "disabled" }))

    // The pressed item is in the tab order.
    expect(tabbable()).toEqual(["center"])
    expect(el("right").hasAttribute("data-disabled")).toBe(true)

    el("center").focus()
    expect(press("ArrowRight").defaultPrevented).toBe(true)
    // The disabled item is skipped, and the focus loops.
    expect(document.activeElement).toBe(el("left"))
    expect(tabbable()).toEqual(["left"])

    press("End")
    expect(document.activeElement).toBe(el("center"))
    press("Home")
    expect(document.activeElement).toBe(el("left"))

    // The vertical keys do nothing in a horizontal group.
    expect(press("ArrowDown").defaultPrevented).toBe(false)
  })

  it("stops at the ends with no loopFocus, and moves with the vertical keys", async () => {
    await mount(group('data-phem-toggle-group-orientation-value="vertical" data-phem-toggle-group-loop-focus-value="false"'))

    expect(tabbable()).toEqual(["left"])
    expect(el("left").getAttribute("data-orientation")).toBe("vertical")

    el("left").focus()
    press("ArrowUp")
    expect(document.activeElement).toBe(el("left"))
    press("ArrowDown")
    expect(document.activeElement).toBe(el("center"))
  })

  it("stops the changes of a disabled group", async () => {
    await mount(toggles(`data-phem-toggle-group-disabled-value="true" data-phem-toggle-group-value-value='["left"]'`))

    expect(el("group").hasAttribute("data-disabled")).toBe(true)
    expect(el("group").getAttribute("aria-disabled")).toBe("true")
    expect(el("center").hasAttribute("data-disabled")).toBe(true)

    click("#center")
    expect(pressed()).toEqual(["left"])
  })

  it("sets the state of phem-toggle items", async () => {
    await mount(toggles(`data-phem-toggle-group-value-value='["left"]'`))
    const values = []
    el("group").addEventListener("phem-toggle-group:change", (event) => values.push(event.detail.value))

    expect(pressed()).toEqual(["left"])
    expect(el("left").getAttribute("data-phem-toggle-checked-value")).toBe("true")
    expect(el("left").hasAttribute("data-pressed")).toBe(true)

    click("#right")
    expect(pressed()).toEqual(["right"])
    expect(el("left").hasAttribute("data-pressed")).toBe(false)
    expect(el("left").getAttribute("data-phem-toggle-checked-value")).toBe("false")
    expect(values).toEqual([["right"]])
  })

  it("keeps the state after the morph of an event", async () => {
    await mount(toggles(`data-phem-toggle-group-value-value='["left"]'`))
    click("#center")
    el("center").focus()
    press("ArrowRight")

    respond(toggles(`data-phem-toggle-group-value-value='["left"]'`))
    await morphOnSave()

    expect(pressed()).toEqual(["center"])
    expect(el("center").hasAttribute("data-pressed")).toBe(true)
    expect(tabbable()).toEqual(["right"])
    expect(el("group").getAttribute("data-phem-toggle-group-value-value")).toBe('["center"]')
  })

  it("takes a new value from a server render", async () => {
    await mount(group(`data-phem-toggle-group-value-value='["left"]'`))
    click("#center")

    respond(group(`data-phem-toggle-group-value-value='["right"]'`))
    await morphOnSave()

    expect(pressed()).toEqual(["right"])
  })
})
