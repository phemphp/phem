import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemToolbarController from "../dist/phem_toolbar_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-toolbar", PhemToolbarController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A toolbar with a group of two buttons, a separator, a link, an input and a third button, and a
// "Save" button out of the toolbar that sends an event.
function toolbar(attributes = "", { group = "", right = "" } = {}) {
  return `<div id="toolbar" data-controller="phem-toolbar" aria-label="Format" ${attributes}>
      <div id="group" data-phem-toolbar-target="group" aria-label="Alignment" ${group}>
        <button id="left" data-phem-toolbar-target="item" data-phem-on-click="c1.left">Left</button>
        <button id="right" data-phem-toolbar-target="item" ${right}>Right</button>
      </div>
      <div id="separator" data-phem-toolbar-target="separator"></div>
      <a id="help" href="#help" data-phem-toolbar-target="item">Help</a>
      <input id="zoom" data-phem-toolbar-target="item" aria-label="Zoom" value="100">
      <button id="more" data-phem-toolbar-target="item">More</button>
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)

  return event
}

function focus(id) {
  document.getElementById(id).focus()
}

// The ids of the items in the tab order.
function tabStops() {
  return [...document.querySelectorAll('#toolbar [tabindex="0"]')].map((el) => el.id)
}

describe("phem-toolbar", () => {
  it("sets the roles and one tab stop", async () => {
    await mount(toolbar())

    const root = document.getElementById("toolbar")
    expect(root.getAttribute("role")).toBe("toolbar")
    expect(root.getAttribute("aria-orientation")).toBe("horizontal")
    expect(root.getAttribute("data-orientation")).toBe("horizontal")
    expect(document.getElementById("group").getAttribute("role")).toBe("group")
    expect(document.getElementById("separator").getAttribute("role")).toBe("separator")
    expect(document.getElementById("separator").getAttribute("aria-orientation")).toBe("vertical")
    expect(document.getElementById("separator").getAttribute("data-orientation")).toBe("vertical")
    expect(document.getElementById("help").getAttribute("data-orientation")).toBe("horizontal")
    expect(tabStops()).toEqual(["left"])
    expect(document.getElementById("more").tabIndex).toBe(-1)
  })

  it("moves the focus with the arrow keys, Home and End", async () => {
    await mount(toolbar())
    focus("left")

    press("ArrowRight")
    expect(document.activeElement.id).toBe("right")
    expect(tabStops()).toEqual(["right"])

    press("ArrowRight")
    expect(document.activeElement.id).toBe("help")

    press("End")
    expect(document.activeElement.id).toBe("more")

    press("ArrowRight")
    expect(document.activeElement.id).toBe("left")

    press("ArrowLeft")
    expect(document.activeElement.id).toBe("more")

    press("Home")
    expect(document.activeElement.id).toBe("left")

    // The keys of the other orientation do not move.
    expect(press("ArrowDown").defaultPrevented).toBe(false)
    expect(document.activeElement.id).toBe("left")
  })

  it("stops at the ends with no loop", async () => {
    await mount(toolbar('data-phem-toolbar-loop-focus-value="false"'))
    focus("more")

    press("ArrowRight")
    expect(document.activeElement.id).toBe("more")

    focus("left")
    press("ArrowLeft")
    expect(document.activeElement.id).toBe("left")
  })

  it("uses the up and down keys when vertical", async () => {
    await mount(toolbar('data-phem-toolbar-orientation-value="vertical"'))
    focus("left")

    expect(document.getElementById("toolbar").getAttribute("aria-orientation")).toBe("vertical")
    expect(document.getElementById("separator").getAttribute("aria-orientation")).toBe("horizontal")

    press("ArrowRight")
    expect(document.activeElement.id).toBe("left")

    press("ArrowDown")
    expect(document.activeElement.id).toBe("right")

    press("ArrowUp")
    expect(document.activeElement.id).toBe("left")
  })

  it("keeps the arrow keys in an input until the caret is at the edge", async () => {
    await mount(toolbar())
    const zoom = document.getElementById("zoom")
    zoom.focus()

    zoom.setSelectionRange(1, 1)
    expect(press("ArrowRight").defaultPrevented).toBe(false)
    expect(press("ArrowLeft").defaultPrevented).toBe(false)
    expect(press("Home").defaultPrevented).toBe(false)
    expect(document.activeElement.id).toBe("zoom")

    zoom.setSelectionRange(3, 3)
    press("ArrowRight")
    expect(document.activeElement.id).toBe("more")

    zoom.focus()
    zoom.setSelectionRange(0, 0)
    press("ArrowLeft")
    expect(document.activeElement.id).toBe("help")
  })

  it("keeps a disabled item in the focus order and blocks its click", async () => {
    await mount(toolbar("", { right: 'aria-disabled="true"' }))
    const right = document.getElementById("right")
    const clicked = vi.fn()
    right.addEventListener("click", clicked)

    expect(right.getAttribute("aria-disabled")).toBe("true")
    expect(right.hasAttribute("data-disabled")).toBe(true)
    expect(right.hasAttribute("data-focusable")).toBe(true)

    focus("left")
    press("ArrowRight")
    expect(document.activeElement.id).toBe("right")

    right.click()
    expect(clicked).not.toHaveBeenCalled()
  })

  it("skips an item with the native disabled attribute", async () => {
    await mount(toolbar("", { right: "disabled" }))
    const right = document.getElementById("right")

    expect(right.hasAttribute("data-disabled")).toBe(true)
    expect(right.hasAttribute("data-focusable")).toBe(false)
    expect(right.hasAttribute("aria-disabled")).toBe(false)

    focus("left")
    press("ArrowRight")
    expect(document.activeElement.id).toBe("help")
  })

  it("disables the items of a disabled group and of a disabled toolbar", async () => {
    await mount(toolbar("", { group: 'aria-disabled="true"' }))

    expect(document.getElementById("group").hasAttribute("data-disabled")).toBe(true)
    expect(document.getElementById("left").getAttribute("aria-disabled")).toBe("true")
    expect(document.getElementById("help").hasAttribute("aria-disabled")).toBe(false)

    // A disabled input takes no text.
    const root = document.getElementById("toolbar")
    root.setAttribute("data-phem-toolbar-disabled-value", "true")
    await Promise.resolve()

    const zoom = document.getElementById("zoom")
    const input = new InputEvent("beforeinput", { bubbles: true, cancelable: true, data: "1" })
    zoom.dispatchEvent(input)
    expect(input.defaultPrevented).toBe(true)
    expect(root.getAttribute("aria-disabled")).toBe("true")
    expect(root.hasAttribute("data-disabled")).toBe(true)
    expect(zoom.hasAttribute("data-focusable")).toBe(true)

    root.setAttribute("data-phem-toolbar-disabled-value", "false")
    await Promise.resolve()
    expect(zoom.hasAttribute("aria-disabled")).toBe(false)
    expect(document.getElementById("left").getAttribute("aria-disabled")).toBe("true")
  })

  it("enables the items of a toolbar that was disabled on load", async () => {
    await mount(toolbar('data-phem-toolbar-disabled-value="true"'))
    expect(document.getElementById("more").getAttribute("aria-disabled")).toBe("true")

    document.getElementById("toolbar").setAttribute("data-phem-toolbar-disabled-value", "false")
    await Promise.resolve()
    expect(document.getElementById("more").hasAttribute("aria-disabled")).toBe(false)
    expect(document.getElementById("more").hasAttribute("data-disabled")).toBe(false)
  })

  it("keeps the tab stop and the attributes after the morph of an event", async () => {
    await mount(toolbar())
    focus("left")
    press("End")
    expect(tabStops()).toEqual(["more"])

    respond(toolbar("", { right: 'aria-disabled="true"' }))
    const done = nextDone()
    click("#save")
    await done
    await Promise.resolve()

    expect(tabStops()).toEqual(["more"])
    expect(document.getElementById("toolbar").getAttribute("role")).toBe("toolbar")
    expect(document.getElementById("right").getAttribute("aria-disabled")).toBe("true")
    expect(document.getElementById("right").hasAttribute("data-focusable")).toBe(true)

    // The server render enables the item again.
    respond(toolbar())
    const again = nextDone()
    click("#save")
    await again
    await Promise.resolve()

    expect(document.getElementById("right").hasAttribute("aria-disabled")).toBe(false)
    expect(document.getElementById("right").hasAttribute("data-disabled")).toBe(false)
  })
})
