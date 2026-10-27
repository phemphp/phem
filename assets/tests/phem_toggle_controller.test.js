import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemToggleController from "../dist/phem_toggle_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-toggle", PhemToggleController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A menu with a "Star" item that sends an event, a "Close" item, and the `attributes` of the
// controller element. The server render always gives a closed menu.
function menu(attributes = "", items = "") {
  return `<span id="menu" data-controller="phem-toggle" ${attributes}>
      <button id="open" data-action="phem-toggle#toggle" data-phem-toggle-target="button" aria-expanded="false">⋯</button>
      <span id="content" data-phem-toggle-target="content" hidden>
        <button id="star" data-phem-on-click="c1.star">Star</button>
        <button id="close" data-action="phem-toggle#close">Close</button>
        ${items}
      </span>
    </span>
    <p id="outside">Outside</p>`
}

function isOpen() {
  const content = document.getElementById("content")
  const expanded = document.getElementById("open").getAttribute("aria-expanded")
  if (content.hidden === (expanded === "true")) throw new Error("The content and the button do not agree")

  return !content.hidden
}

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)
}

// A list of rows with a section each, keyed by `data-phem-key`. A click outside does not close it.
function rows(keys) {
  const row = (k) => `<li data-phem-key="${k}" data-controller="phem-toggle" data-phem-toggle-dismiss-value="false">
      <button data-action="phem-toggle#toggle" data-phem-toggle-target="button" aria-expanded="false">⋯</button>
      <span data-phem-toggle-target="content" hidden>Row ${k}</span>
    </li>`

  return `<ul>${keys.map(row).join("")}</ul><button id="change" data-phem-on-click="c1.change">Change</button>`
}

describe("phem-toggle", () => {
  it("opens and closes the content", async () => {
    await mount(menu())

    expect(isOpen()).toBe(false)
    click("#open")
    expect(isOpen()).toBe(true)
    click("#open")
    expect(isOpen()).toBe(false)
  })

  it("stays open after the morph of an event", async () => {
    await mount(menu())
    respond(menu("", `<span id="new" data-phem-toggle-target="content" hidden>New</span>`))
    click("#open")
    const done = nextDone()
    click("#star")
    await done

    expect(isOpen()).toBe(true)
    expect(document.getElementById("menu").getAttribute("data-phem-toggle-open-value")).toBe("true")
    // A target that the morph adds gets the state.
    expect(document.getElementById("new").hidden).toBe(false)
  })

  it("keeps the other attributes of the server render", async () => {
    await mount(menu())
    respond(menu().replace('id="content"', 'id="content" class="wide"'))
    click("#open")
    const done = nextDone()
    click("#star")
    await done

    expect(document.getElementById("content").className).toBe("wide")
    expect(isOpen()).toBe(true)
  })

  it("opens with the open value of the server render", async () => {
    await mount(menu('data-phem-toggle-open-value="true"'))

    expect(isOpen()).toBe(true)
  })

  it("closes with the close action", async () => {
    await mount(menu())
    click("#open")
    click("#close")

    expect(isOpen()).toBe(false)
  })

  it("closes on a click outside, not on a click inside", async () => {
    await mount(menu())
    click("#open")
    click("#content")

    expect(isOpen()).toBe(true)
    click("#outside")
    expect(isOpen()).toBe(false)
  })

  it("closes on Escape and gives the focus back to the button", async () => {
    await mount(menu())
    click("#open")
    document.getElementById("close").focus()
    press("Escape")

    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("open")
  })

  it("does not close on Escape or a click outside with dismiss false", async () => {
    await mount(menu('data-phem-toggle-dismiss-value="false"'))
    click("#open")
    press("Escape")
    click("#outside")

    expect(isOpen()).toBe(true)
  })

  it("opens and closes with the open value of a new server render", async () => {
    await mount(menu())
    respond(menu('data-phem-toggle-open-value="false"'))
    click("#open")
    const done = nextDone()
    click("#star")
    await done

    expect(isOpen()).toBe(false)
  })

  it("closes on a click outside when the server render opened it", async () => {
    await mount(menu('data-phem-toggle-open-value="true"'))
    click("#outside")

    expect(isOpen()).toBe(false)
  })

  it("does not close on an Escape that another element handled", async () => {
    await mount(menu())
    click("#open")
    document.getElementById("close").focus()
    document.getElementById("close").addEventListener("keydown", (e) => e.preventDefault())
    press("Escape")
    press("Escape", { isComposing: true })

    expect(isOpen()).toBe(true)
  })

  it("gives the focus to a button outside the content", async () => {
    await mount(menu().replace('id="close"', 'id="close" data-phem-toggle-target="button"'))
    click("#open")
    document.getElementById("close").focus()
    press("Escape")

    expect(document.activeElement.id).toBe("open")
  })

  it("keeps the state of a nested toggle apart", async () => {
    const nested = `<div id="outer" data-controller="phem-toggle">
        <button id="outer-open" data-action="phem-toggle#toggle" data-phem-toggle-target="button">Outer</button>
        <div id="outer-content" data-phem-toggle-target="content" hidden>
          <div data-controller="phem-toggle">
            <button id="inner-open" data-action="phem-toggle#toggle" data-phem-toggle-target="button">Inner</button>
            <div id="inner-content" data-phem-toggle-target="content" hidden>Inner</div>
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

    expect(document.getElementById("outer-content").hidden).toBe(false)
    expect(document.getElementById("inner-content").hidden).toBe(true)
    click("#inner-open")
    expect(document.getElementById("inner-content").hidden).toBe(false)
    expect(document.getElementById("outer-content").hidden).toBe(false)
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
