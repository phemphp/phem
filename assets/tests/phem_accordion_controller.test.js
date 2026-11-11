import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemAccordionController from "../dist/phem_accordion_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-accordion", PhemAccordionController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// Three items: shipping, returns (with a button that sends an event) and a disabled one.
function accordion(attributes = "") {
  const item = (value, content = "", disabled = "") => `<div id="${value}" data-value="${value}" data-phem-accordion-target="item">
      <button id="${value}-trigger" data-phem-accordion-target="trigger"${disabled}>${value}</button>
      <div id="${value}-panel" data-phem-accordion-target="panel" hidden>${content}</div>
    </div>`

  return `<div id="accordion" data-controller="phem-accordion" ${attributes}>
      ${item("shipping")}
      ${item("returns", '<button id="star" data-phem-on-click="c1.star">Star</button>')}
      ${item("gifts", "", " disabled")}
    </div>`
}

// The open items. The panel, the trigger and the item must agree.
function open() {
  return ["shipping", "returns", "gifts"].filter((value) => {
    const item = document.getElementById(value)
    const trigger = document.getElementById(`${value}-trigger`)
    const panel = document.getElementById(`${value}-panel`)
    const isOpen = !panel.hidden
    const agree = [
      trigger.getAttribute("aria-expanded") === String(isOpen),
      trigger.hasAttribute("data-panel-open") === isOpen,
      panel.hasAttribute("data-open") === isOpen,
      item.hasAttribute("data-open") === isOpen,
      item.hasAttribute("data-closed") === !isOpen,
    ]
    if (agree.includes(false)) throw new Error(`The parts of ${value} do not agree: ${agree}`)

    return isOpen
  })
}

function key(selector, key) {
  document.querySelector(selector).dispatchEvent(new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true }))
}

describe("phem-accordion", () => {
  it("opens one item at a time", async () => {
    await mount(accordion())

    expect(open()).toEqual([])
    click("#shipping-trigger")
    expect(open()).toEqual(["shipping"])
    click("#returns-trigger")
    expect(open()).toEqual(["returns"])
    click("#returns-trigger")
    expect(open()).toEqual([])
  })

  it("opens more than one item with the multiple value", async () => {
    await mount(accordion('data-phem-accordion-multiple-value="true"'))

    click("#shipping-trigger")
    click("#returns-trigger")
    expect(open()).toEqual(["shipping", "returns"])
  })

  it("does not open a disabled item", async () => {
    await mount(accordion())

    document.getElementById("gifts-trigger").disabled = false
    document.getElementById("gifts-trigger").setAttribute("aria-disabled", "true")
    click("#gifts-trigger")
    expect(open()).toEqual([])
  })

  it("links each trigger to its panel", async () => {
    await mount(accordion())

    const trigger = document.getElementById("shipping-trigger")
    const panel = document.getElementById("shipping-panel")
    expect(trigger.getAttribute("aria-controls")).toBe("shipping-panel")
    expect(panel.getAttribute("aria-labelledby")).toBe("shipping-trigger")
    expect(panel.getAttribute("role")).toBe("region")
  })

  it("gives the parts no id, and keeps them in the morph of an event", async () => {
    const markup = `<div data-controller="phem-accordion">
        <div data-value="a" data-phem-accordion-target="item">
          <button class="trigger" data-phem-accordion-target="trigger">A</button>
          <div class="panel" data-phem-accordion-target="panel" hidden><button id="star" data-phem-on-click="c1.star">Star</button></div>
        </div>
      </div>`
    await mount(markup)
    click(".trigger")
    const trigger = document.querySelector(".trigger")
    const panel = document.querySelector(".panel")
    expect(trigger.hasAttribute("id")).toBe(false)
    expect(trigger.hasAttribute("aria-controls")).toBe(false)

    respond(markup)
    const done = nextDone()
    click("#star")
    await done
    await settle()

    expect(document.querySelector(".trigger")).toBe(trigger)
    expect(document.querySelector(".panel")).toBe(panel)
    expect(panel.hidden).toBe(false)
    expect(trigger.getAttribute("aria-expanded")).toBe("true")
  })

  it("opens the items of the open value on load", async () => {
    await mount(accordion(`data-phem-accordion-open-value='["returns"]'`))

    expect(open()).toEqual(["returns"])
  })

  it("moves the focus between the triggers with the arrow keys", async () => {
    await mount(accordion())

    document.getElementById("shipping-trigger").focus()
    key("#shipping-trigger", "ArrowDown")
    expect(document.activeElement.id).toBe("returns-trigger")

    // The disabled trigger does not get the focus: the focus goes to the first one.
    key("#returns-trigger", "ArrowDown")
    expect(document.activeElement.id).toBe("shipping-trigger")
    key("#shipping-trigger", "End")
    expect(document.activeElement.id).toBe("returns-trigger")
  })

  it("keeps the open item after the morph of an event", async () => {
    await mount(accordion())
    click("#returns-trigger")

    respond(accordion())
    const done = nextDone()
    click("#star")
    await done
    await settle()

    expect(open()).toEqual(["returns"])
  })

  it("takes a new open value of the server render", async () => {
    await mount(accordion())
    click("#returns-trigger")

    respond(accordion(`data-phem-accordion-open-value='["shipping"]'`))
    const done = nextDone()
    click("#star")
    await done
    await settle()

    expect(open()).toEqual(["shipping"])
  })
})
