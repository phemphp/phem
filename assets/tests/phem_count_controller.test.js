import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemCountController from "../dist/phem_count_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-count", PhemCountController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A bio with a maximum of 10 characters, and a button that sends an event.
function bio(attributes = "", output = "", value = "") {
  return `<div id="root" data-controller="phem-count" ${attributes}>
      <textarea id="bio" maxlength="10" data-phem-count-target="field">${value}</textarea>
      <span id="output" data-phem-count-target="output" ${output}>0/10</span>
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function type(field, value) {
  field.value = value
  field.dispatchEvent(new Event("input", { bubbles: true }))
}

describe("phem-count", () => {
  it("counts the characters of a text field while the user types", async () => {
    await mount(bio())

    const output = document.getElementById("output")
    expect(output.textContent).toBe("0/10")
    expect(output.getAttribute("data-max")).toBe("10")

    type(document.getElementById("bio"), "Hello")
    expect(output.textContent).toBe("5/10")
    expect(output.getAttribute("data-count")).toBe("5")
    expect(document.getElementById("root").getAttribute("data-count")).toBe("5")
  })

  it("marks a count above the max value", async () => {
    await mount(bio('data-phem-count-max-value="3"'))

    type(document.getElementById("bio"), "Hello")
    expect(document.getElementById("output").textContent).toBe("5/3")
    expect(document.getElementById("root").hasAttribute("data-over")).toBe(true)
    expect(document.getElementById("output").hasAttribute("data-over")).toBe(true)
  })

  it("shows the count with the template of the output", async () => {
    await mount(bio("", 'data-phem-count-template="{remaining} left"'))

    type(document.getElementById("bio"), "Hi")
    expect(document.getElementById("output").textContent).toBe("8 left")
  })

  it("counts the checked boxes", async () => {
    await mount(`<div data-controller="phem-count">
        <input type="checkbox" id="a" data-phem-count-target="field">
        <input type="checkbox" id="b" data-phem-count-target="field" checked>
        <span id="output" data-phem-count-target="output" data-phem-count-template="{count} selected"></span>
      </div>`)

    const output = document.getElementById("output")
    expect(output.textContent).toBe("1 selected")
    click("#a")
    expect(output.textContent).toBe("2 selected")
  })

  it("shows the count again when the server does not take the value", async () => {
    // A component (k1) with the field. The server does not render it again, so the morph keeps
    // it, and phem gives the field the server value after the morph.
    const field = `<div data-phem-c="k1" data-controller="phem-count">
        <input id="nick" data-phem-bind="k1.nick" maxlength="10" value="" data-phem-count-target="field">
        <span id="output" data-phem-count-target="output"></span>
      </div>`
    await mount(`${field}<button id="save" data-phem-on-click="c1.save">Save</button>`)
    type(document.getElementById("nick"), "Hello")
    expect(document.getElementById("output").textContent).toBe("5/10")

    respond('<template data-phem-keep="k1"></template><button id="save" data-phem-on-click="c1.save">Save</button>')
    const done = nextDone()
    click("#save")
    await done
    await settle()

    expect(document.getElementById("nick").value).toBe("")
    expect(document.getElementById("output").textContent).toBe("0/10")
  })

  it("shows the count again after the morph of an event", async () => {
    await mount(bio())
    type(document.getElementById("bio"), "Hello")

    // The server render has the value, and the count of the server: 0/10.
    respond(bio("", "", "Hello"))
    const done = nextDone()
    click("#save")
    await done
    await settle()

    const output = document.getElementById("output")
    expect(document.getElementById("bio").value).toBe("Hello")
    expect(output.textContent).toBe("5/10")
    expect(output.getAttribute("data-count")).toBe("5")
  })
})
