import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemCollapsibleController from "../dist/phem_collapsible_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-collapsible", PhemCollapsibleController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A section with a "Star" button that sends an event, a "Close" button, and the `attributes` of
// the controller element. The server render always gives a closed section.
function section(attributes = "", content = "") {
  return `<div id="section" data-controller="phem-collapsible" ${attributes}>
      <button id="open" data-phem-collapsible-target="trigger">Details</button>
      <div id="panel" data-phem-collapsible-target="panel" hidden>
        <button id="star" data-phem-on-click="c1.star">Star</button>
        <button id="close" data-action="phem-collapsible#close">Close</button>
        ${content}
      </div>
    </div>
    <p id="outside">Outside</p>`
}

// The panel, the trigger and the controller element must agree.
function isOpen() {
  const panel = document.getElementById("panel")
  const trigger = document.getElementById("open")
  const root = document.getElementById("section")
  const open = !panel.hidden
  const agree = [
    trigger.getAttribute("aria-expanded") === String(open),
    trigger.hasAttribute("data-panel-open") === open,
    panel.hasAttribute("data-open") === open,
    panel.hasAttribute("data-closed") === !open,
    root.hasAttribute("data-open") === open,
    root.hasAttribute("data-closed") === !open,
  ]
  if (agree.includes(false)) throw new Error(`The parts do not agree: ${agree}`)

  return open
}

// A list of rows with a section each, keyed by `data-phem-key`.
function rows(keys) {
  const row = (k) => `<li data-phem-key="${k}" data-controller="phem-collapsible">
      <button data-phem-collapsible-target="trigger">⋯</button>
      <span data-phem-collapsible-target="panel" hidden>Row ${k}</span>
    </li>`

  return `<ul>${keys.map(row).join("")}</ul><button id="change" data-phem-on-click="c1.change">Change</button>`
}

describe("phem-collapsible", () => {
  it("opens and closes the panel with a click on the trigger", async () => {
    await mount(section())

    expect(isOpen()).toBe(false)
    click("#open")
    expect(isOpen()).toBe(true)
    click("#open")
    expect(isOpen()).toBe(false)
  })

  it("links the trigger to the panel", async () => {
    await mount(section())

    const panel = document.getElementById("panel")
    expect(document.getElementById("open").getAttribute("aria-controls")).toBe("panel")
    expect(panel.getAttribute("data-closed")).toBe("")
  })

  it("gives an id to a panel with no id, the same after the morph", async () => {
    const markup = section().replace('id="panel" ', "")
    await mount(markup)
    respond(markup)

    const trigger = document.getElementById("open")
    const id = trigger.getAttribute("aria-controls")
    expect(id).toMatch(/^phem-collapsible-\d+-0$/)

    click("#open")
    const done = nextDone()
    click("#star")
    await done

    expect(trigger.getAttribute("aria-controls")).toBe(id)
    expect(document.getElementById(id)).not.toBeNull()
  })

  it("stays open after the morph of an event", async () => {
    await mount(section())
    respond(section("", `<span id="new">New</span>`))
    click("#open")
    const done = nextDone()
    click("#star")
    await done

    expect(isOpen()).toBe(true)
    expect(document.getElementById("section").getAttribute("data-phem-collapsible-open-value")).toBe("true")
    expect(document.getElementById("new")).not.toBeNull()
  })

  it("gives the state to a panel that the morph adds", async () => {
    await mount(section())
    click("#open")
    const extra = section().replace('</div>\n    </div>', '</div>\n      <div id="more" data-phem-collapsible-target="panel" hidden>More</div>\n    </div>')
    respond(extra)
    const done = nextDone()
    click("#star")
    await done

    expect(document.getElementById("more").hidden).toBe(false)
    expect(document.getElementById("more").hasAttribute("data-open")).toBe(true)
  })

  it("keeps the other attributes of the server render", async () => {
    await mount(section())
    respond(section().replace('id="panel"', 'id="panel" class="wide"'))
    click("#open")
    const done = nextDone()
    click("#star")
    await done

    expect(document.getElementById("panel").className).toBe("wide")
    expect(isOpen()).toBe(true)
  })

  it("opens with the open value of the server render", async () => {
    await mount(section('data-phem-collapsible-open-value="true"'))

    expect(isOpen()).toBe(true)
  })

  it("closes with the close action", async () => {
    await mount(section())
    click("#open")
    click("#close")

    expect(isOpen()).toBe(false)
  })

  it("stays open on a click outside and on Escape", async () => {
    await mount(section())
    click("#open")
    click("#outside")
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))

    expect(isOpen()).toBe(true)
  })

  it("opens and closes with the open value of a new server render", async () => {
    await mount(section())
    respond(section('data-phem-collapsible-open-value="false"'))
    click("#open")
    const done = nextDone()
    click("#star")
    await done

    expect(isOpen()).toBe(false)
  })

  it("does not toggle on a disabled trigger", async () => {
    await mount(section().replace('id="open"', 'id="open" disabled'))
    document.getElementById("open").dispatchEvent(new MouseEvent("click", { bubbles: true }))

    expect(isOpen()).toBe(false)
  })

  it("sets the size of the content in CSS variables", async () => {
    await mount(section())
    const panel = document.getElementById("panel")
    Object.defineProperty(panel, "scrollHeight", { value: 120 })
    Object.defineProperty(panel, "scrollWidth", { value: 300 })
    click("#open")

    expect(panel.style.getPropertyValue("--collapsible-panel-height")).toBe("120px")
    expect(panel.style.getPropertyValue("--collapsible-panel-width")).toBe("300px")
  })

  it("keeps data-ending-style until the end of the animation", async () => {
    await mount(section('data-phem-collapsible-open-value="true"'))
    const panel = document.getElementById("panel")
    let finish
    panel.getAnimations = () => [{ finished: new Promise((resolve) => (finish = resolve)) }]
    click("#open")

    expect(panel.hidden).toBe(false)
    expect(panel.hasAttribute("data-ending-style")).toBe(true)
    expect(panel.hasAttribute("data-closed")).toBe(true)

    finish()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(panel.hidden).toBe(true)
    expect(panel.hasAttribute("data-ending-style")).toBe(false)
  })

  it("gives data-starting-style for the first frame of the open", async () => {
    await mount(section())
    const panel = document.getElementById("panel")
    click("#open")

    expect(panel.hasAttribute("data-starting-style")).toBe(true)
    await new Promise((resolve) => requestAnimationFrame(resolve))
    expect(panel.hasAttribute("data-starting-style")).toBe(false)
  })

  it("uses hidden=until-found with the hiddenUntilFound value, and opens on beforematch", async () => {
    await mount(section('data-phem-collapsible-hidden-until-found-value="true"'))
    const panel = document.getElementById("panel")

    expect(panel.getAttribute("hidden")).toBe("until-found")
    panel.dispatchEvent(new Event("beforematch", { bubbles: true }))
    expect(panel.hidden).toBe(false)
    expect(isOpen()).toBe(true)
  })

  it("keeps the state of a nested collapsible apart", async () => {
    const nested = `<div id="outer" data-controller="phem-collapsible">
        <button id="outer-open" data-phem-collapsible-target="trigger">Outer</button>
        <div id="outer-panel" data-phem-collapsible-target="panel" hidden>
          <div data-controller="phem-collapsible">
            <button id="inner-open" data-phem-collapsible-target="trigger">Inner</button>
            <div id="inner-panel" data-phem-collapsible-target="panel" hidden>Inner</div>
          </div>
          <button id="save" data-phem-on-click="c1.save">Save</button>
        </div>
      </div>`
    await mount(nested)
    respond(nested)
    click("#outer-open")
    const done = nextDone()
    click("#save")
    await done

    expect(document.getElementById("outer-panel").hidden).toBe(false)
    expect(document.getElementById("inner-panel").hidden).toBe(true)
    click("#inner-open")
    expect(document.getElementById("inner-panel").hidden).toBe(false)
    expect(document.getElementById("outer-panel").hidden).toBe(false)
  })

  it("keeps the state on the keyed row when a row before it goes away", async () => {
    await mount(rows([1, 2]))
    respond(rows([2]))
    document.querySelector('[data-phem-key="2"] button').click()
    const done = nextDone()
    click("#change")
    await done

    expect(document.querySelector('[data-phem-key="2"] span').hidden).toBe(false)
  })
})
