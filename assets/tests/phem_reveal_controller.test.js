import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemRevealController from "../dist/phem_reveal_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-reveal", PhemRevealController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A password field with a trigger, and a button that sends an event.
function password(attributes = "") {
  return `<div id="root" data-controller="phem-reveal" ${attributes}>
      <input type="password" id="password" data-phem-reveal-target="field">
      <button type="button" id="show" data-phem-reveal-target="trigger">Show</button>
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function field() {
  return document.getElementById("password")
}

describe("phem-reveal", () => {
  it("shows and hides the password with a click on the trigger", async () => {
    await mount(password())

    const trigger = document.getElementById("show")
    expect(trigger.getAttribute("aria-pressed")).toBe("false")
    expect(trigger.getAttribute("aria-controls")).toBe("password")

    click("#show")
    expect(field().type).toBe("text")
    expect(field().hasAttribute("data-revealed")).toBe(true)
    expect(trigger.getAttribute("aria-pressed")).toBe("true")
    expect(document.getElementById("root").hasAttribute("data-revealed")).toBe(true)

    click("#show")
    expect(field().type).toBe("password")
    expect(trigger.getAttribute("aria-pressed")).toBe("false")
  })

  it("keeps the selection of a focused field", async () => {
    await mount(password())
    field().value = "secret123"
    field().focus()
    field().setSelectionRange(2, 4)

    application.getControllerForElementAndIdentifier(document.getElementById("root"), "phem-reveal").show()

    expect(field().type).toBe("text")
    expect([field().selectionStart, field().selectionEnd]).toEqual([2, 4])
  })

  it("keeps the shown password after the morph of an event", async () => {
    await mount(password())
    click("#show")

    respond(password())
    const done = nextDone()
    click("#save")
    await done
    await settle()

    expect(field().type).toBe("text")
    expect(document.getElementById("show").getAttribute("aria-pressed")).toBe("true")
  })

  it("takes a new revealed value of the server render", async () => {
    await mount(password())
    click("#show")

    respond(password('data-phem-reveal-revealed-value="false"'))
    const done = nextDone()
    click("#save")
    await done
    await settle()

    expect(field().type).toBe("password")
  })
})
