import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemButtonController from "../dist/phem_button_controller.js"
import { mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-button", PhemButtonController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

function page(attributes = "", tag = "div") {
  return `<${tag} id="save" data-controller="phem-button" data-phem-on-click="c1.save" ${attributes}>Save <input id="name" /></${tag}>`
}

const button = () => document.getElementById("save")

function key(type, name, target = button()) {
  const event = new KeyboardEvent(type, { key: name, bubbles: true, cancelable: true })
  target.dispatchEvent(event)

  return event
}

describe("phem-button", () => {
  it("gives the element the role and the Tab stop of a button", async () => {
    await mount(page())

    expect(button().getAttribute("role")).toBe("button")
    expect(button().getAttribute("tabindex")).toBe("0")
  })

  it("keeps the role and the tabindex that it added after the morph of an event", async () => {
    await mount(page())
    respond(page())
    const done = nextDone()
    button().click()
    await done

    expect(button().getAttribute("role")).toBe("button")
    expect(button().getAttribute("tabindex")).toBe("0")
  })

  it("keeps the role and the tabindex of the server render", async () => {
    await mount(page('role="menuitem" tabindex="-1"'))

    expect(button().getAttribute("role")).toBe("menuitem")
    expect(button().getAttribute("tabindex")).toBe("-1")
  })

  it("clicks the element when Enter goes down", async () => {
    await mount(page())
    respond(page())
    const done = nextDone()

    expect(key("keydown", "Enter").defaultPrevented).toBe(true)
    await done
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("clicks the element when Space goes up", async () => {
    await mount(page())
    respond(page())

    // Space does not scroll the page, and does not click before the key goes up.
    expect(key("keydown", " ").defaultPrevented).toBe(true)
    expect(fetch).not.toHaveBeenCalled()

    const done = nextDone()
    key("keyup", " ")
    await done
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("does not click for a key on a field in the element, or for another key", async () => {
    await mount(page())

    key("keydown", "Enter", document.getElementById("name"))
    key("keyup", " ", document.getElementById("name"))
    key("keydown", "a")
    expect(fetch).not.toHaveBeenCalled()
  })

  it("ignores the keys and the click of a button with aria-disabled", async () => {
    await mount(page('aria-disabled="true"'))
    const clicks = vi.fn()
    button().addEventListener("click", clicks)

    key("keydown", "Enter")
    key("keydown", " ")
    key("keyup", " ")
    button().click()

    expect(clicks).not.toHaveBeenCalled()
    expect(fetch).not.toHaveBeenCalled()
  })

  it("leaves the keys of a native button to the browser, and stops its click when it has aria-disabled", async () => {
    await mount(page('aria-disabled="true"', "button"))
    const clicks = vi.fn()
    button().addEventListener("click", clicks)

    expect(button().hasAttribute("role")).toBe(false)
    expect(button().hasAttribute("tabindex")).toBe(false)
    expect(key("keydown", "Enter").defaultPrevented).toBe(true)

    button().click()
    expect(clicks).not.toHaveBeenCalled()
    expect(fetch).not.toHaveBeenCalled()

    // An enabled native button: the browser clicks it on Enter and on Space.
    button().removeAttribute("aria-disabled")
    expect(key("keydown", "Enter").defaultPrevented).toBe(false)
    expect(key("keydown", " ").defaultPrevented).toBe(false)
    expect(key("keyup", " ").defaultPrevented).toBe(false)
    expect(clicks).not.toHaveBeenCalled()
  })
})
