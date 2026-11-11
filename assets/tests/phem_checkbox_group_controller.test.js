import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemCheckboxGroupController from "../dist/phem_checkbox_group_controller.js"
import PhemCountController from "../dist/phem_count_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-checkbox-group", PhemCheckboxGroupController)
  application.register("phem-count", PhemCountController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

// The listeners of a test on window.
let listening

beforeEach(() => {
  listening = new AbortController()
})

afterEach(() => {
  listening.abort()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// Three rows with a parent box, the `checked` rows, and a button that sends an event.
function rows(checked = [], { disabled = [] } = {}) {
  const row = (id) => `<input type="checkbox" id="row-${id}" value="${id}" data-phem-checkbox-group-target="item"
      data-phem-count-target="field"${checked.includes(id) ? " checked" : ""}${disabled.includes(id) ? " disabled" : ""}>`

  return `<div data-controller="phem-checkbox-group phem-count">
      <input type="checkbox" id="all" data-phem-checkbox-group-target="parent">
      ${[1, 2, 3].map(row).join("")}
      <span id="count" data-phem-count-target="output" data-phem-count-template="{count} selected"></span>
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function parent() {
  return document.getElementById("all")
}

function checked() {
  return [1, 2, 3].filter((id) => document.getElementById(`row-${id}`).checked)
}

describe("phem-checkbox-group", () => {
  it("shows the state of the items on the parent", async () => {
    await mount(rows([2]))

    expect(parent().checked).toBe(false)
    expect(parent().indeterminate).toBe(true)
    expect(parent().hasAttribute("data-indeterminate")).toBe(true)
    expect(parent().getAttribute("aria-controls")).toBe("row-1 row-2 row-3")

    click("#row-1")
    click("#row-3")
    expect(parent().checked).toBe(true)
    expect(parent().indeterminate).toBe(false)
    expect(parent().hasAttribute("data-checked")).toBe(true)
  })

  it("checks all the items, then unchecks all of them", async () => {
    await mount(rows([2]))
    const changes = []
    document.querySelector("[data-controller]").addEventListener("change", (event) => changes.push(event.target.id))

    click("#all")
    expect(checked()).toEqual([1, 2, 3])
    // The changes of the items come in the change of the parent.
    expect(changes).toEqual(["row-1", "row-3", "all"])
    expect(document.getElementById("count").textContent).toBe("3 selected")

    click("#all")
    expect(checked()).toEqual([])
    expect(parent().hasAttribute("data-unchecked")).toBe(true)
    expect(document.getElementById("count").textContent).toBe("0 selected")
  })

  it("keeps the state of a disabled item", async () => {
    await mount(rows([], { disabled: [3] }))

    click("#all")
    expect(checked()).toEqual([1, 2])
    expect(parent().indeterminate).toBe(true)
  })

  it("sends one live event for all the items that the parent changes", async () => {
    const live = (markup) => markup.replaceAll('data-phem-checkbox-group-target="item"', 'data-phem-checkbox-group-target="item" data-phem-bind="c1.selected" data-phem-bind-list data-phem-live')
    await mount(live(rows()))
    const events = []
    window.addEventListener("phem:done", (event) => events.push(event.detail.ids), { signal: listening.signal })

    respond(live(rows([1, 2, 3])))
    click("#all")
    await vi.waitFor(() => expect(events.length).toBeGreaterThan(0))
    await settle()

    // The three changes go in one event, with the three ids.
    expect(events).toEqual([[1, 2, 3]])
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(JSON.parse(fetch.mock.calls[0][1].body).bound).toEqual({ "c1.selected": ["1", "2", "3"] })
  })

  it("shows the state of the items again after the morph of an event", async () => {
    await mount(rows([1, 2, 3]))
    expect(parent().checked).toBe(true)

    // The server render has no checked parent.
    respond(rows([1, 2, 3]))
    const done = nextDone()
    click("#save")
    await done
    await settle()

    expect(parent().checked).toBe(true)
    expect(parent().hasAttribute("data-checked")).toBe(true)
  })
})
