import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemToggleController from "../dist/phem_toggle_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

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

const SAVE = `<button id="save" data-phem-on-click="c1.save">Save</button>`

// A switch in a label, with a checkbox input, and the `attributes` of the controller element.
function aSwitch(attributes = "") {
  return `<label id="label">
      <span id="switch" role="switch" data-controller="phem-toggle" ${attributes}>
        <span id="thumb" data-phem-toggle-target="indicator"></span>
        <input id="input" type="checkbox" name="notifications" hidden data-phem-toggle-target="input">
      </span>
      <span id="text">Notifications</span>
    </label>${SAVE}`
}

// A checkbox with an indicator, and the `attributes` of the controller element.
function aCheckbox(attributes = "") {
  return `<label>
      <span id="checkbox" role="checkbox" data-controller="phem-toggle" ${attributes}>
        <span id="indicator" data-phem-toggle-target="indicator">✓</span>
      </span>
      <span id="text">Accept</span>
    </label>${SAVE}`
}

function el(id) {
  return document.getElementById(id)
}

function press(key, target = document.activeElement) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
  target.dispatchEvent(event)

  return event
}

async function morphOnSave() {
  const done = nextDone()
  click("#save")
  await done
  await settle()
}

describe("phem-toggle", () => {
  it("presses a toggle button on a click", async () => {
    await mount(`<button id="bold" type="button" data-controller="phem-toggle">B</button>`)
    const changes = []
    el("bold").addEventListener("phem-toggle:change", (event) => changes.push(event.detail.checked))

    expect(el("bold").getAttribute("aria-pressed")).toBe("false")
    expect(el("bold").hasAttribute("data-pressed")).toBe(false)
    expect(el("bold").hasAttribute("role")).toBe(false)

    click("#bold")
    expect(el("bold").getAttribute("aria-pressed")).toBe("true")
    expect(el("bold").hasAttribute("data-pressed")).toBe(true)
    expect(el("bold").getAttribute("data-phem-toggle-checked-value")).toBe("true")
    expect(changes).toEqual([true])

    click("#bold")
    expect(el("bold").getAttribute("aria-pressed")).toBe("false")
    expect(changes).toEqual([true, false])
  })

  it("gives a role and a tabindex to an element that is not a button, and presses it with Enter and Space", async () => {
    await mount(`<span id="toggle" data-controller="phem-toggle">B</span>`)

    expect(el("toggle").getAttribute("role")).toBe("button")
    expect(el("toggle").tabIndex).toBe(0)

    el("toggle").focus()
    expect(press("Enter").defaultPrevented).toBe(true)
    expect(el("toggle").getAttribute("aria-pressed")).toBe("true")
    press(" ")
    expect(el("toggle").getAttribute("aria-pressed")).toBe("false")
  })

  it("checks a switch and its input with a click, a label and Space", async () => {
    await mount(aSwitch())
    const changes = []
    el("label").addEventListener("change", (event) => changes.push(event.target.id))

    expect(el("switch").getAttribute("aria-checked")).toBe("false")
    expect(el("switch").hasAttribute("data-unchecked")).toBe(true)
    expect(el("thumb").hasAttribute("data-unchecked")).toBe(true)
    expect(el("input").tabIndex).toBe(-1)
    expect(el("input").getAttribute("aria-hidden")).toBe("true")

    // A click on the switch in the label: one change.
    click("#switch")
    expect(el("switch").getAttribute("aria-checked")).toBe("true")
    expect(el("switch").hasAttribute("data-checked")).toBe(true)
    expect(el("thumb").hasAttribute("data-checked")).toBe(true)
    expect(el("thumb").hidden).toBe(false)
    expect(el("input").checked).toBe(true)
    expect(changes).toEqual(["input"])

    // A click on the text of the label: the browser clicks the input.
    click("#text")
    expect(el("switch").getAttribute("aria-checked")).toBe("false")
    expect(el("input").checked).toBe(false)
    expect(changes).toEqual(["input", "input"])

    el("switch").focus()
    press(" ")
    expect(el("input").checked).toBe(true)

    // Enter does not toggle a switch.
    press("Enter")
    expect(el("input").checked).toBe(true)
  })

  it("takes the first state from the checked input", async () => {
    await mount(aSwitch().replace("hidden data-phem", "hidden checked data-phem"))

    expect(el("switch").getAttribute("aria-checked")).toBe("true")
    expect(el("switch").getAttribute("data-phem-toggle-checked-value")).toBe("true")
  })

  it("sets the name, the value and the form of the input", async () => {
    await mount(aSwitch('data-phem-toggle-name-value="alerts" data-phem-toggle-value-value="yes" data-phem-toggle-form-value="settings" data-phem-toggle-required-value="true"'))

    expect(el("input").name).toBe("alerts")
    expect(el("input").value).toBe("yes")
    expect(el("input").getAttribute("form")).toBe("settings")
    expect(el("input").required).toBe(true)
    expect(el("switch").getAttribute("aria-required")).toBe("true")
    expect(el("thumb").hasAttribute("data-required")).toBe(true)
  })

  it("gives the value or the unchecked value to a hidden input", async () => {
    await mount(`<span id="switch" role="switch" data-controller="phem-toggle" data-phem-toggle-value-value="on" data-phem-toggle-unchecked-value-value="off">
        <input id="input" type="hidden" name="alerts" data-phem-toggle-target="input">
      </span>
      <label id="label" for="input">Alerts</label>`)

    expect(el("input").value).toBe("off")
    expect(el("input").disabled).toBe(false)

    // A label for a hidden input has no control: the controller toggles the switch.
    click("#label")
    expect(el("input").value).toBe("on")
    expect(document.activeElement).toBe(el("switch"))
  })

  it("disables a hidden input with no unchecked value", async () => {
    await mount(`<span id="switch" role="switch" data-controller="phem-toggle">
        <input id="input" type="hidden" name="alerts" data-phem-toggle-target="input">
      </span>`)

    expect(el("input").disabled).toBe(true)
    click("#switch")
    expect(el("input").disabled).toBe(false)
    expect(el("input").value).toBe("on")
  })

  it("shows the indicator of a checkbox when it is checked or indeterminate", async () => {
    await mount(aCheckbox('data-phem-toggle-indeterminate-value="true"'))

    expect(el("checkbox").getAttribute("aria-checked")).toBe("mixed")
    expect(el("checkbox").hasAttribute("data-indeterminate")).toBe(true)
    expect(el("checkbox").hasAttribute("data-unchecked")).toBe(false)
    expect(el("indicator").hasAttribute("data-indeterminate")).toBe(true)
    expect(el("indicator").hidden).toBe(false)

    // A click checks an indeterminate checkbox.
    click("#checkbox")
    expect(el("checkbox").getAttribute("aria-checked")).toBe("true")
    expect(el("checkbox").hasAttribute("data-indeterminate")).toBe(false)
    expect(el("indicator").hasAttribute("data-checked")).toBe(true)
    expect(el("indicator").hidden).toBe(false)

    click("#checkbox")
    expect(el("checkbox").getAttribute("aria-checked")).toBe("false")
    expect(el("indicator").hasAttribute("data-closed")).toBe(true)
    expect(el("indicator").hidden).toBe(true)

    // A click on the text of the label with no input.
    click("#text")
    expect(el("checkbox").getAttribute("aria-checked")).toBe("true")
    expect(el("indicator").hasAttribute("data-open")).toBe(true)
  })

  it("keeps the indicator in the page with keepMounted", async () => {
    await mount(aCheckbox('data-phem-toggle-keep-mounted-value="true"'))

    expect(el("indicator").hidden).toBe(false)
    expect(el("indicator").hasAttribute("data-unchecked")).toBe(true)
    expect(el("indicator").hasAttribute("data-closed")).toBe(true)
  })

  it("stops the changes of a disabled or read-only element", async () => {
    await mount(`${aCheckbox('data-phem-toggle-disabled-value="true"')}
      <button id="readonly" type="button" role="switch" data-controller="phem-toggle" data-phem-toggle-read-only-value="true">R</button>
      <button id="native" type="button" disabled data-controller="phem-toggle">N</button>`)

    click("#checkbox")
    click("#text")
    expect(el("checkbox").getAttribute("aria-checked")).toBe("false")
    expect(el("checkbox").getAttribute("aria-disabled")).toBe("true")
    expect(el("checkbox").hasAttribute("data-disabled")).toBe(true)
    expect(el("indicator").hasAttribute("data-disabled")).toBe(true)

    click("#readonly")
    expect(el("readonly").getAttribute("aria-checked")).toBe("false")
    expect(el("readonly").getAttribute("aria-readonly")).toBe("true")
    expect(el("readonly").hasAttribute("data-readonly")).toBe(true)

    expect(el("native").hasAttribute("data-disabled")).toBe(true)
    expect(el("native").hasAttribute("aria-disabled")).toBe(false)
  })

  it("keeps the state after the morph of an event", async () => {
    await mount(aSwitch())
    click("#switch")

    // The server render has no state.
    respond(aSwitch())
    await morphOnSave()

    expect(el("switch").getAttribute("aria-checked")).toBe("true")
    expect(el("switch").hasAttribute("data-checked")).toBe(true)
    expect(el("thumb").hasAttribute("data-checked")).toBe(true)
    expect(el("input").checked).toBe(true)
  })

  it("takes a new checked value from a server render", async () => {
    await mount(aSwitch('data-phem-toggle-checked-value="false"'))
    click("#switch")
    click("#switch")
    expect(el("switch").getAttribute("aria-checked")).toBe("false")

    respond(aSwitch('data-phem-toggle-checked-value="true" data-phem-toggle-disabled-value="true"'))
    await morphOnSave()

    expect(el("switch").getAttribute("aria-checked")).toBe("true")
    expect(el("input").checked).toBe(true)
    expect(el("switch").hasAttribute("data-disabled")).toBe(true)
  })

  it("takes the state of a bound input after the morph of an event", async () => {
    const bound = (checked) => aSwitch().replace("hidden data-phem", `hidden data-phem-bind="c1.alerts"${checked ? " checked" : ""} data-phem`)
    await mount(bound(false))
    click("#switch")
    expect(el("input").checked).toBe(true)

    // The server takes the value, then sets it to false.
    respond(bound(false))
    await morphOnSave()

    expect(el("input").checked).toBe(false)
    expect(el("switch").getAttribute("aria-checked")).toBe("false")
  })

  it("sends a bound hidden input, and takes its server value after the morph", async () => {
    const bound = (value) => `<span id="switch" role="switch" data-controller="phem-toggle" data-phem-toggle-value-value="yes" data-phem-toggle-unchecked-value-value="no">
        <input id="input" type="hidden" data-phem-bind="c1.alerts" value="${value}" data-phem-toggle-target="input">
      </span>${SAVE}`
    await mount(bound("no"))
    const events = []
    el("input").addEventListener("input", () => events.push("input"))
    el("input").addEventListener("change", () => events.push("change"))

    click("#switch")
    expect(el("input").value).toBe("yes")
    expect(events).toEqual(["input", "change"])

    // The server takes the value.
    respond(bound("yes"))
    await morphOnSave()
    expect(JSON.parse(fetch.mock.calls[0][1].body).bound).toEqual({ "c1.alerts": "yes" })
    expect(el("switch").getAttribute("aria-checked")).toBe("true")

    // The server sets it back.
    respond(bound("no"))
    await morphOnSave()
    expect(el("input").value).toBe("no")
    expect(el("switch").getAttribute("aria-checked")).toBe("false")
  })
})
