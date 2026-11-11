import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemThemeController from "../dist/phem_theme_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-theme", PhemThemeController)
})

// jsdom has no `matchMedia`: the system theme is `dark` or `light`, and `system()` changes it.
let dark
let media

function system(theme) {
  dark = theme === "dark"
  media.dispatchEvent(new Event("change"))
}

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  dark = false
  media = new EventTarget()
  Object.defineProperty(media, "matches", { get: () => dark })
  vi.stubGlobal("matchMedia", vi.fn(() => media))
  window.matchMedia = globalThis.matchMedia
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  delete window.matchMedia
  localStorage.clear()
  document.body.innerHTML = ""
  delete document.documentElement.dataset.theme
  delete document.documentElement.dataset.themeDefault
  document.documentElement.style.colorScheme = ""
})

function buttons(attributes = "") {
  return `<div id="switch" data-controller="phem-theme" ${attributes}>
      <button id="light" data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="light">Light</button>
      <button id="dark" data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="dark">Dark</button>
      <button id="system" data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="system">System</button>
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function pressed() {
  return [...document.querySelectorAll('[aria-pressed="true"]')].map((el) => el.id)
}

const html = document.documentElement

describe("phem-theme", () => {
  it("follows the system with no choice", async () => {
    dark = true
    await mount(buttons())

    expect(html.dataset.theme).toBe("dark")
    expect(html.style.colorScheme).toBe("dark")
    expect(pressed()).toEqual(["system"])
    expect(document.getElementById("light").getAttribute("aria-pressed")).toBe("false")

    const sw = document.getElementById("switch")
    expect(sw.dataset.theme).toBe("dark")
    expect(sw.dataset.preference).toBe("system")

    system("light")
    expect(html.dataset.theme).toBe("light")
    expect(sw.dataset.theme).toBe("light")
  })

  it("stores the choice of the user", async () => {
    await mount(buttons())
    const changes = []
    document.addEventListener("phem-theme:change", (event) => changes.push(event.detail))

    click("#dark")

    expect(localStorage.getItem("phem-theme")).toBe("dark")
    expect(html.dataset.theme).toBe("dark")
    expect(pressed()).toEqual(["dark"])
    expect(changes).toEqual([{ theme: "dark", preference: "dark" }])

    // A chosen theme does not follow the system.
    system("light")
    expect(html.dataset.theme).toBe("dark")

    click("#system")
    expect(localStorage.getItem("phem-theme")).toBe("system")
    expect(html.dataset.theme).toBe("light")
    expect(changes.at(-1)).toEqual({ theme: "light", preference: "system" })
  })

  it("takes the stored choice on load", async () => {
    localStorage.setItem("phem-theme", "dark")
    await mount(buttons())

    expect(html.dataset.theme).toBe("dark")
    expect(pressed()).toEqual(["dark"])
  })

  it("takes the default of theme_script() with no choice", async () => {
    html.dataset.themeDefault = "dark"
    await mount(buttons())

    expect(html.dataset.theme).toBe("dark")
    expect(pressed()).toEqual(["dark"])
  })

  it("ignores a stored value that is not a theme", async () => {
    localStorage.setItem("phem-theme", "blue")
    await mount(buttons())

    expect(pressed()).toEqual(["system"])
  })

  it("dispatches one change event for all the switches of the page", async () => {
    await mount(`${buttons()}<button id="toggle" data-controller="phem-theme" data-action="phem-theme#toggle">Theme</button>`)
    const changes = []
    document.addEventListener("phem-theme:change", (event) => changes.push([event.target, event.detail]))

    click("#toggle")
    system("dark")

    expect(changes).toEqual([
      [html, { theme: "dark", preference: "dark" }],
    ])
  })

  it("follows the removal of the choice in another tab", async () => {
    await mount(buttons())
    click("#dark")

    localStorage.removeItem("phem-theme")
    window.dispatchEvent(new StorageEvent("storage", { key: "phem-theme", oldValue: "dark", newValue: null }))
    expect(html.dataset.theme).toBe("light")
    expect(pressed()).toEqual(["system"])

    click("#dark")
    localStorage.clear()
    window.dispatchEvent(new StorageEvent("storage", { key: null }))
    expect(pressed()).toEqual(["system"])
  })

  it("keeps a choice that the storage cannot keep until the reload", async () => {
    await mount(buttons())
    vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
      throw new DOMException("QuotaExceededError")
    })

    click("#dark")
    expect(html.dataset.theme).toBe("dark")
    expect(pressed()).toEqual(["dark"])
  })

  it("toggles the current theme", async () => {
    dark = true
    await mount(`<button id="toggle" data-controller="phem-theme" data-action="phem-theme#toggle">Theme</button>`)

    click("#toggle")
    expect(localStorage.getItem("phem-theme")).toBe("light")
    expect(html.dataset.theme).toBe("light")

    click("#toggle")
    expect(localStorage.getItem("phem-theme")).toBe("dark")
    expect(html.dataset.theme).toBe("dark")
  })

  it("takes the value of a select", async () => {
    await mount(`<select id="theme" data-controller="phem-theme" data-action="phem-theme#choose">
        <option data-phem-theme-target="option" value="system">System</option>
        <option data-phem-theme-target="option" value="light">Light</option>
        <option data-phem-theme-target="option" value="dark">Dark</option>
      </select>`)
    const select = document.getElementById("theme")

    select.value = "dark"
    select.dispatchEvent(new Event("change", { bubbles: true }))
    expect(localStorage.getItem("phem-theme")).toBe("dark")
    expect(html.dataset.theme).toBe("dark")

    localStorage.setItem("phem-theme", "light")
    window.dispatchEvent(new StorageEvent("storage", { key: "phem-theme", newValue: "light" }))
    expect(select.value).toBe("light")
  })

  it("checks the radio button of the choice", async () => {
    localStorage.setItem("phem-theme", "light")
    await mount(`<fieldset data-controller="phem-theme">
        <input type="radio" name="theme" id="r-light" value="light" data-phem-theme-target="option" data-action="phem-theme#choose">
        <input type="radio" name="theme" id="r-dark" value="dark" data-phem-theme-target="option" data-action="phem-theme#choose">
      </fieldset>`)

    expect(document.getElementById("r-light").checked).toBe(true)

    click("#r-dark")
    expect(localStorage.getItem("phem-theme")).toBe("dark")
    expect(html.dataset.theme).toBe("dark")
  })

  it("gets the choice of another switch and of another tab", async () => {
    await mount(`${buttons()}<button id="toggle" data-controller="phem-theme" data-action="phem-theme#toggle">Theme</button>`)

    click("#toggle")
    expect(pressed()).toEqual(["dark"])

    localStorage.setItem("phem-theme", "light")
    window.dispatchEvent(new StorageEvent("storage", { key: "phem-theme", newValue: "light" }))
    expect(html.dataset.theme).toBe("light")
    expect(pressed()).toEqual(["light"])
  })

  it("keeps the marks after the morph of an event", async () => {
    await mount(buttons())
    click("#dark")

    respond(buttons())
    const done = nextDone()
    click("#save")
    await done
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(pressed()).toEqual(["dark"])
    expect(document.getElementById("switch").dataset.preference).toBe("dark")
    expect(html.dataset.theme).toBe("dark")
  })
})
