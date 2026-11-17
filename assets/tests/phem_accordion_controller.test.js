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

  it("does not move the focus with the arrow keys by default", async () => {
    await mount(accordion())

    document.getElementById("shipping-trigger").focus()
    key("#shipping-trigger", "ArrowDown")
    expect(document.activeElement.id).toBe("shipping-trigger")
  })

  it("gives the header of an item its open state", async () => {
    await mount(accordion().replace(`<button id="shipping-trigger"`, `<h3 id="shipping-header" data-phem-accordion-target="header"></h3><button id="shipping-trigger"`))
    expect(document.getElementById("shipping-header").hasAttribute("data-open")).toBe(false)

    document.getElementById("shipping-trigger").click()
    expect(document.getElementById("shipping-header").hasAttribute("data-open")).toBe(true)
    expect(document.getElementById("shipping-header").getAttribute("data-index")).toBe("0")
  })

  it("gives each part the index of its item", async () => {
    await mount(accordion())

    expect(document.getElementById("shipping").getAttribute("data-index")).toBe("0")
    expect(document.getElementById("returns-trigger").getAttribute("data-index")).toBe("1")
    expect(document.getElementById("returns-panel").getAttribute("data-index")).toBe("1")
  })

  it("moves the focus between the triggers with the arrow keys with the rovingFocus value", async () => {
    await mount(accordion(`data-phem-accordion-roving-focus-value="true"`))

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

describe("phem-accordion: the attributes of Base UI", () => {
  const $ = (id) => document.getElementById(id)

  it("gives the orientation to the element and to the panels", async () => {
    await mount(accordion())

    expect($("accordion").getAttribute("data-orientation")).toBe("vertical")
    expect($("shipping-panel").getAttribute("data-orientation")).toBe("vertical")

    $("accordion").setAttribute("data-phem-accordion-orientation-value", "horizontal")
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("accordion").getAttribute("data-orientation")).toBe("horizontal")
    expect($("returns-panel").getAttribute("data-orientation")).toBe("horizontal")
  })

  it("gives data-disabled to the parts of an item with a disabled trigger, also after the morph", async () => {
    const markup = accordion().replace('data-phem-accordion-target="trigger" disabled>gifts', 'data-phem-accordion-target="trigger" disabled>gifts').replaceAll("<button id=\"gifts-trigger\"", '<h3 id="gifts-header" data-phem-accordion-target="header"></h3><button id="gifts-trigger"')
    await mount(markup)

    for (const id of ["gifts", "gifts-header", "gifts-trigger", "gifts-panel"]) expect($(id).hasAttribute("data-disabled")).toBe(true)
    for (const id of ["shipping", "shipping-trigger", "shipping-panel"]) expect($(id).hasAttribute("data-disabled")).toBe(false)

    // The server render enables the trigger.
    respond(markup.replace('data-phem-accordion-target="trigger" disabled>gifts', 'data-phem-accordion-target="trigger">gifts'))
    const done = nextDone()
    click("#star")
    await done

    for (const id of ["gifts", "gifts-header", "gifts-trigger", "gifts-panel"]) expect($(id).hasAttribute("data-disabled")).toBe(false)
  })
})

describe("phem-accordion: the state of the header", () => {
  const $ = (id) => document.getElementById(id)
  const withHeader = () => accordion().replace(`<button id="shipping-trigger"`, `<h3 id="shipping-header" data-phem-accordion-target="header"></h3><button id="shipping-trigger"`)

  it("gives the header of a closed item data-closed", async () => {
    await mount(withHeader())

    expect($("shipping-header").hasAttribute("data-closed")).toBe(true)
    expect($("shipping-header").hasAttribute("data-open")).toBe(false)

    click("#shipping-trigger")
    expect($("shipping-header").hasAttribute("data-closed")).toBe(false)
    expect($("shipping-header").hasAttribute("data-open")).toBe(true)

    click("#shipping-trigger")
    expect($("shipping-header").hasAttribute("data-closed")).toBe(true)
  })

  it("keeps data-closed on the header after the morph of an event", async () => {
    await mount(withHeader())

    respond(withHeader())
    const done = nextDone()
    click("#star")
    await done

    expect($("shipping-header").hasAttribute("data-closed")).toBe(true)
  })
})

describe("phem-accordion: the size of an open panel", () => {
  const panel = () => document.getElementById("shipping-panel")
  const size = () => [panel().style.getPropertyValue("--accordion-panel-height"), panel().style.getPropertyValue("--accordion-panel-width")]
  const frame = () => new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)))

  it("gives the panel the auto size at the end of the open, so content that grows is not cut", async () => {
    await mount(accordion())
    Object.defineProperty(panel(), "scrollHeight", { value: 120 })
    Object.defineProperty(panel(), "scrollWidth", { value: 300 })
    click("#shipping-trigger")

    // The open animation goes from 0 to the size of the content.
    expect(size()).toEqual(["120px", "300px"])

    await frame()
    expect(size()).toEqual(["auto", "auto"])
  })

  it("gives the auto size at the end of the animations of the open", async () => {
    await mount(accordion())
    Object.defineProperty(panel(), "scrollHeight", { value: 120 })
    let finish
    const finished = new Promise((resolve) => (finish = resolve))
    panel().getAnimations = () => [{ finished }]
    click("#shipping-trigger")

    await frame()
    expect(size()[0]).toBe("120px")

    finish()
    await frame()
    expect(size()[0]).toBe("auto")
  })

  it("gives an open panel of the server render the auto size at once", async () => {
    await mount(accordion(`data-phem-accordion-open-value='["shipping"]'`))

    expect(size()).toEqual(["auto", "auto"])
  })

  it("starts the close from the size of the content in px", async () => {
    await mount(accordion(`data-phem-accordion-open-value='["shipping"]'`))
    expect(size()[0]).toBe("auto")
    Object.defineProperty(panel(), "scrollHeight", { value: 120 })
    panel().getAnimations = () => [{ finished: new Promise(() => {}) }]
    click("#shipping-trigger")

    expect(panel().hasAttribute("data-ending-style")).toBe(true)
    expect(size()[0]).toBe("120px")
  })

  it("starts the close of the other item from its size in px when an item opens", async () => {
    await mount(accordion(`data-phem-accordion-open-value='["shipping"]'`))
    expect(size()[0]).toBe("auto")
    Object.defineProperty(panel(), "scrollHeight", { value: 120 })
    panel().getAnimations = () => [{ finished: new Promise(() => {}) }]

    click("#returns-trigger")

    expect(panel().hasAttribute("data-ending-style")).toBe(true)
    expect(size()[0]).toBe("120px")
  })

  it("does not give the auto size to a panel that closes before the end of the open", async () => {
    await mount(accordion())
    Object.defineProperty(panel(), "scrollHeight", { value: 120 })
    let finish
    const finished = new Promise((resolve) => (finish = resolve))
    panel().getAnimations = () => [{ finished }]
    click("#shipping-trigger")
    click("#shipping-trigger")

    finish()
    await frame()

    expect(panel().hasAttribute("data-open")).toBe(false)
    expect(size()[0]).toBe("120px")
  })

  it("keeps the auto size after the morph of an event", async () => {
    await mount(accordion())
    click("#shipping-trigger")
    await frame()
    expect(size()[0]).toBe("auto")

    // The server render has no style attribute.
    respond(accordion())
    const done = nextDone()
    click("#star")
    await done
    await settle()

    expect(size()).toEqual(["auto", "auto"])
  })

  it("keeps the size in px after a morph during the open animation", async () => {
    await mount(accordion())
    Object.defineProperty(panel(), "scrollHeight", { value: 120 })
    panel().getAnimations = () => [{ finished: new Promise(() => {}) }]
    click("#shipping-trigger")
    await frame()

    respond(accordion())
    const done = nextDone()
    click("#star")
    await done
    await settle()

    expect(size()[0]).toBe("120px")
  })
})
