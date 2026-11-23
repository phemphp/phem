import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemRadioGroupController from "../dist/phem_radio_group_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-radio-group", PhemRadioGroupController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// Three radios (fuji, gala, honeycrisp) in labels, a hidden input, and a "Save" button that sends
// an event. `radio` gives attributes to each radio by its value.
function group(attributes = "", radio = {}) {
  const item = (v) => `<label id="label-${v}">
        <span id="${v}" data-phem-radio-group-target="radio" data-value="${v}" ${radio[v] ?? ""}>
          <span id="indicator-${v}" data-phem-radio-group-target="indicator"></span>
        </span>
        ${v}
      </label>`

  return `<form id="form"><div id="group" data-controller="phem-radio-group" aria-label="Apple" ${attributes}>
      ${["fuji", "gala", "honeycrisp"].map(item).join("")}
      <input id="input" type="hidden" name="apple" data-phem-radio-group-target="input">
    </div></form>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)

  return event
}

// The id of the checked radio, or null. The radios, the indicators and the tab stop must agree.
function checked() {
  const radios = [...document.querySelectorAll('[role=radio][aria-checked=true][data-checked]')].map((el) => el.id)
  const indicators = [...document.querySelectorAll("[data-phem-radio-group-target=indicator]")].filter((el) => !el.hidden).map((el) => el.id.slice(10))
  if (radios.join() !== indicators.join() || radios.length > 1) throw new Error(`The radios (${radios}) and the indicators (${indicators}) do not agree`)

  return radios[0] ?? null
}

describe("phem-radio-group", () => {
  it("sets the roles with no checked radio", async () => {
    await mount(group())

    expect(document.getElementById("group").getAttribute("role")).toBe("radiogroup")
    expect(checked()).toBe(null)
    expect(document.getElementById("fuji").getAttribute("role")).toBe("radio")
    expect(document.getElementById("fuji").getAttribute("aria-checked")).toBe("false")
    expect(document.getElementById("fuji").hasAttribute("data-unchecked")).toBe(true)
    expect(document.getElementById("indicator-fuji").hasAttribute("data-unchecked")).toBe(true)
    expect(document.getElementById("fuji").tabIndex).toBe(0)
    expect(document.getElementById("gala").tabIndex).toBe(-1)
    expect(document.getElementById("input").value).toBe("")
  })

  it("checks the radio of the value", async () => {
    await mount(group('data-phem-radio-group-value-value="gala"'))

    expect(checked()).toBe("gala")
    expect(document.getElementById("gala").tabIndex).toBe(0)
    expect(document.getElementById("fuji").tabIndex).toBe(-1)
    expect(document.getElementById("input").value).toBe("gala")
  })

  it("checks a radio on a click and on a click on its label", async () => {
    await mount(group())
    const input = document.getElementById("input")
    const changes = []
    input.addEventListener("change", () => changes.push(input.value))
    const events = []
    document.getElementById("group").addEventListener("phem-radio-group:change", (e) => events.push(e.detail.value))

    click("#gala")
    expect(checked()).toBe("gala")
    expect(document.activeElement.id).toBe("gala")
    expect(document.getElementById("group").getAttribute("data-phem-radio-group-value-value")).toBe("gala")

    // The text of the label.
    document.getElementById("label-honeycrisp").click()
    expect(checked()).toBe("honeycrisp")
    expect(document.activeElement.id).toBe("honeycrisp")

    // The checked radio again sends no change.
    click("#honeycrisp")
    expect(changes).toEqual(["gala", "honeycrisp"])
    expect(events).toEqual(["gala", "honeycrisp"])
    expect(new FormData(document.getElementById("form")).get("apple")).toBe("honeycrisp")
  })

  it("moves the focus and checks with the arrow keys", async () => {
    await mount(group('data-phem-radio-group-value-value="fuji"'))
    document.getElementById("fuji").focus()

    press("ArrowDown")
    expect(document.activeElement.id).toBe("gala")
    expect(checked()).toBe("gala")

    press("ArrowRight")
    expect(checked()).toBe("honeycrisp")

    press("ArrowRight")
    expect(checked()).toBe("fuji")

    press("ArrowUp")
    expect(checked()).toBe("honeycrisp")

    press("ArrowLeft")
    expect(checked()).toBe("gala")
    expect(document.getElementById("gala").tabIndex).toBe(0)
  })

  it("checks the radio with the focus on Space", async () => {
    await mount(group())
    document.getElementById("fuji").focus()

    const event = press(" ")
    expect(event.defaultPrevented).toBe(true)
    expect(checked()).toBe("fuji")
  })

  it("skips a disabled radio", async () => {
    await mount(group('data-phem-radio-group-value-value="fuji"', { gala: 'aria-disabled="true"' }))
    document.getElementById("fuji").focus()

    expect(document.getElementById("gala").hasAttribute("data-disabled")).toBe(true)

    press("ArrowDown")
    expect(checked()).toBe("honeycrisp")

    click("#gala")
    expect(checked()).toBe("honeycrisp")
  })

  it("does nothing on a disabled group", async () => {
    await mount(group('data-phem-radio-group-disabled-value="true"'))

    const root = document.getElementById("group")
    expect(root.getAttribute("aria-disabled")).toBe("true")
    expect(root.hasAttribute("data-disabled")).toBe(true)
    expect(document.getElementById("fuji").getAttribute("aria-disabled")).toBe("true")
    expect(document.getElementById("fuji").tabIndex).toBe(-1)

    click("#fuji")
    expect(checked()).toBe(null)

    root.setAttribute("data-phem-radio-group-disabled-value", "false")
    await Promise.resolve()
    expect(document.getElementById("fuji").hasAttribute("aria-disabled")).toBe(false)
    expect(document.getElementById("fuji").tabIndex).toBe(0)
  })

  it("moves the focus but does not check when read-only", async () => {
    await mount(group('data-phem-radio-group-value-value="fuji" data-phem-radio-group-read-only-value="true" data-phem-radio-group-required-value="true"'))
    document.getElementById("fuji").focus()

    const root = document.getElementById("group")
    expect(root.getAttribute("aria-readonly")).toBe("true")
    expect(root.getAttribute("aria-required")).toBe("true")
    expect(document.getElementById("gala").hasAttribute("data-readonly")).toBe(true)
    expect(document.getElementById("gala").hasAttribute("data-required")).toBe(true)

    press("ArrowDown")
    expect(document.activeElement.id).toBe("gala")
    expect(checked()).toBe("fuji")

    press(" ")
    click("#gala")
    expect(checked()).toBe("fuji")
  })

  it("checks native radio inputs", async () => {
    const inputs = ["fuji", "gala"].map((v) => `<input id="native-${v}" type="radio" name="apple" value="${v}" data-phem-radio-group-target="input">`).join("")
    await mount(`<div id="group" data-controller="phem-radio-group">
        <span id="fuji" data-phem-radio-group-target="radio" data-value="fuji"></span>
        <span id="gala" data-phem-radio-group-target="radio" data-value="gala"></span>
        ${inputs}
      </div>`)
    const changed = []
    document.getElementById("group").addEventListener("change", (e) => changed.push(e.target.id))

    click("#gala")
    expect(document.getElementById("native-gala").checked).toBe(true)
    expect(document.getElementById("native-fuji").checked).toBe(false)
    expect(changed).toEqual(["native-gala"])
  })

  it("keeps the checked radio after the morph of an event", async () => {
    await mount(group())
    click("#gala")

    respond(group())
    const done = nextDone()
    click("#save")
    await done
    await Promise.resolve()

    expect(checked()).toBe("gala")
    expect(document.getElementById("input").value).toBe("gala")
    expect(document.getElementById("gala").tabIndex).toBe(0)
  })

  it("makes the input of a required group invalid with no checked radio, also after the morph", async () => {
    const markup = () => group('data-phem-radio-group-required-value="true"').replace('type="hidden" name="apple"', 'name="apple" hidden')
    await mount(markup())

    const input = () => document.getElementById("input")
    expect(input().required).toBe(true)
    expect(input().validity.valueMissing).toBe(true)

    respond(markup())
    const done = nextDone()
    click("#save")
    await done
    await Promise.resolve()

    expect(input().required).toBe(true)
    expect(input().checkValidity()).toBe(false)

    click("#gala")
    expect(input().value).toBe("gala")
    expect(input().checkValidity()).toBe(true)
  })

  it("checks the radio of a new value in the server render", async () => {
    await mount(group('data-phem-radio-group-value-value="fuji"'))
    click("#gala")

    respond(group('data-phem-radio-group-value-value="honeycrisp"'))
    const done = nextDone()
    click("#save")
    await done
    await Promise.resolve()

    expect(checked()).toBe("honeycrisp")
    expect(document.getElementById("input").value).toBe("honeycrisp")
  })
})
