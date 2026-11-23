import { afterEach, beforeAll, beforeEach, describe, expect, it } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemPaletteController from "../dist/phem_palette_controller.js"
import { click, mount } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem-palette", PhemPaletteController)
})

const html = document.documentElement

// What palette_script() gives `<html>`.
beforeEach(() => {
  html.dataset.palettes = "base shadcn"
  html.dataset.paletteDefault = "base"
})

afterEach(() => {
  localStorage.clear()
  document.body.innerHTML = ""
  delete html.dataset.palette
  delete html.dataset.palettes
  delete html.dataset.paletteDefault
})

function buttons() {
  return `<div id="picker" data-controller="phem-palette">
      <button id="base" data-phem-palette-target="option" data-action="phem-palette#choose" data-phem-palette-palette-param="base">Base</button>
      <button id="shadcn" data-phem-palette-target="option" data-action="phem-palette#choose" data-phem-palette-palette-param="shadcn">shadcn</button>
      <button id="other" data-phem-palette-target="option" data-action="phem-palette#choose" data-phem-palette-palette-param="other">Other</button>
    </div>`
}

function pressed() {
  return [...document.querySelectorAll('[aria-pressed="true"]')].map((el) => el.id)
}

describe("phem-palette", () => {
  it("takes the default of palette_script() with no choice", async () => {
    await mount(buttons())

    expect(html.dataset.palette).toBe("base")
    expect(document.getElementById("picker").dataset.palette).toBe("base")
    expect(pressed()).toEqual(["base"])
  })

  it("stores the choice of the user", async () => {
    await mount(buttons())
    const changes = []
    document.addEventListener("phem-palette:change", (event) => changes.push(event.detail))

    click("#shadcn")

    expect(localStorage.getItem("phem-palette")).toBe("shadcn")
    expect(html.dataset.palette).toBe("shadcn")
    expect(pressed()).toEqual(["shadcn"])
    expect(changes).toEqual([{ palette: "shadcn" }])
  })

  it("ignores a name that palette_script() does not have", async () => {
    await mount(buttons())

    click("#other")

    expect(localStorage.getItem("phem-palette")).toBeNull()
    expect(html.dataset.palette).toBe("base")
  })

  it("takes the stored choice on load", async () => {
    localStorage.setItem("phem-palette", "shadcn")
    await mount(buttons())

    expect(html.dataset.palette).toBe("shadcn")
    expect(pressed()).toEqual(["shadcn"])
  })

  it("selects the option of a select", async () => {
    await mount(`<select data-controller="phem-palette" data-action="phem-palette#choose">
        <option data-phem-palette-target="option" value="base">Base</option>
        <option id="option" data-phem-palette-target="option" value="shadcn">shadcn</option>
      </select>`)
    const select = document.querySelector("select")

    select.value = "shadcn"
    select.dispatchEvent(new Event("change", { bubbles: true }))

    expect(html.dataset.palette).toBe("shadcn")
    expect(document.getElementById("option").selected).toBe(true)
  })

  it("follows a choice in another tab", async () => {
    await mount(buttons())

    localStorage.setItem("phem-palette", "shadcn")
    window.dispatchEvent(new StorageEvent("storage", { key: "phem-palette", newValue: "shadcn" }))
    expect(html.dataset.palette).toBe("shadcn")

    // Another tab cleared the storage: the default comes back.
    localStorage.clear()
    window.dispatchEvent(new StorageEvent("storage", { key: null, newValue: null }))
    expect(html.dataset.palette).toBe("base")
  })

  it("has no palette with no names", async () => {
    delete html.dataset.palettes
    delete html.dataset.paletteDefault
    await mount(buttons())

    click("#shadcn")

    expect(html.dataset.palette).toBeUndefined()
    expect(pressed()).toEqual([])
  })
})
