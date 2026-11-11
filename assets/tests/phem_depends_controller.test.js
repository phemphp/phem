import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemDependsController from "../dist/phem_depends_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-depends", PhemDependsController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A reason with an "Other" text field, and a button that sends an event.
function reason(selected = "price") {
  const option = (value) => `<option value="${value}"${value === selected ? " selected" : ""}>${value}</option>`

  return `<div id="root" data-controller="phem-depends">
      <select id="reason" data-phem-depends-target="field">${["price", "quality", "other"].map(option).join("")}</select>
      <div id="other" data-phem-depends-target="panel" data-phem-depends-match="other" hidden>
        <input id="text" name="text">
        <input id="fixed" name="fixed" disabled>
      </div>
      <p id="sorry" data-phem-depends-target="panel" data-phem-depends-match="price quality" hidden>Sorry</p>
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function select(selector, value) {
  const field = document.querySelector(selector)
  field.value = value
  field.dispatchEvent(new Event("change", { bubbles: true }))
}

function shown(id) {
  return !document.getElementById(id).hidden
}

describe("phem-depends", () => {
  it("shows the panels that match the value of a select", async () => {
    await mount(reason())

    expect(shown("other")).toBe(false)
    expect(shown("sorry")).toBe(true)
    expect(document.getElementById("root").getAttribute("data-field-value")).toBe("price")

    select("#reason", "other")
    expect(shown("other")).toBe(true)
    expect(shown("sorry")).toBe(false)
    expect(document.getElementById("other").hasAttribute("data-open")).toBe(true)
  })

  it("disables the controls of a hidden panel", async () => {
    await mount(reason())

    expect(document.getElementById("text").disabled).toBe(true)
    select("#reason", "other")
    expect(document.getElementById("text").disabled).toBe(false)
    // The server render disables it.
    expect(document.getElementById("fixed").disabled).toBe(true)
  })

  it("enables the controls again after the controller connects again", async () => {
    await mount(reason())
    expect(document.getElementById("text").hasAttribute("data-phem-depends-disabled")).toBe(true)

    // A move of the element: Stimulus disconnects and connects the controller.
    const root = document.getElementById("root")
    root.parentElement.append(root)
    await settle()

    select("#reason", "other")
    expect(document.getElementById("text").disabled).toBe(false)
    expect(document.getElementById("text").hasAttribute("data-phem-depends-disabled")).toBe(false)
    expect(document.getElementById("fixed").disabled).toBe(true)
  })

  it("reads a bound list with one checkbox as a group", async () => {
    await mount(`<div data-controller="phem-depends">
        <input type="checkbox" id="urgent" value="urgent" data-phem-bind="c1.tags" data-phem-bind-list data-phem-depends-target="field">
        <div id="notes" data-phem-depends-target="panel" data-phem-depends-match="urgent" hidden></div>
      </div>`)

    expect(shown("notes")).toBe(false)
    click("#urgent")
    expect(shown("notes")).toBe(true)
  })

  it("shows a panel from a checkbox", async () => {
    await mount(`<div data-controller="phem-depends">
        <input type="checkbox" id="ship" data-phem-depends-target="field">
        <div id="address" data-phem-depends-target="panel" data-phem-depends-match="checked" hidden></div>
        <div id="same" data-phem-depends-target="panel" data-phem-depends-match="unchecked" hidden></div>
      </div>`)

    expect([shown("address"), shown("same")]).toEqual([false, true])
    click("#ship")
    expect([shown("address"), shown("same")]).toEqual([true, false])
  })

  it("shows a panel from a group of radio buttons", async () => {
    await mount(`<div data-controller="phem-depends">
        <input type="radio" name="kind" id="person" value="person" data-phem-depends-target="field" checked>
        <input type="radio" name="kind" id="company" value="company" data-phem-depends-target="field">
        <div id="vat" data-phem-depends-target="panel" data-phem-depends-match="company" hidden></div>
      </div>`)

    expect(shown("vat")).toBe(false)
    click("#company")
    expect(shown("vat")).toBe(true)
  })

  it("shows a panel with no match when the field has a value", async () => {
    await mount(`<div data-controller="phem-depends">
        <input id="coupon" data-phem-depends-target="field">
        <button id="apply" data-phem-depends-target="panel" data-phem-depends-match hidden>Apply</button>
      </div>`)

    expect(shown("apply")).toBe(false)
    const field = document.getElementById("coupon")
    field.value = "SALE"
    field.dispatchEvent(new Event("input", { bubbles: true }))
    expect(shown("apply")).toBe(true)
  })

  it("keeps the panels of the field after the morph of an event", async () => {
    await mount(reason())
    select("#reason", "other")

    // The server render takes the new value, and hides all the panels.
    respond(reason("other"))
    const done = nextDone()
    click("#save")
    await done
    await settle()

    expect(shown("other")).toBe(true)
    expect(shown("sorry")).toBe(false)
    expect(document.getElementById("text").disabled).toBe(false)
  })
})
