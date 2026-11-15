import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemCheckboxGroupController from "../dist/phem_checkbox_group_controller.js"
import PhemCountController from "../dist/phem_count_controller.js"
import PhemToggleController from "../dist/phem_toggle_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-checkbox-group", PhemCheckboxGroupController)
  application.register("phem-count", PhemCountController)
  application.register("phem-toggle", PhemToggleController)
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

  it("gives back the mixed state on the third click of the parent", async () => {
    await mount(rows([2]))

    click("#all")
    expect(checked()).toEqual([1, 2, 3])
    click("#all")
    expect(checked()).toEqual([])
    click("#all")
    expect(checked()).toEqual([2])
    expect(parent().indeterminate).toBe(true)

    // A change of an item by the user is the new mixed state.
    click("#row-3")
    click("#all")
    click("#all")
    click("#all")
    expect(checked()).toEqual([2, 3])
  })

  it("writes the values of the checked items", async () => {
    await mount(rows([2]))
    const values = []
    const group = document.querySelector("[data-controller~=phem-checkbox-group]")
    group.addEventListener("phem-checkbox-group:change", (event) => values.push(event.detail.value))

    expect(group.getAttribute("data-phem-checkbox-group-value-value")).toBe('["2"]')

    click("#row-1")
    click("#all")
    expect(values).toEqual([["1", "2"], ["1", "2", "3"]])
    expect(group.getAttribute("data-phem-checkbox-group-value-value")).toBe('["1","2","3"]')
  })
})

// A group of `phem-toggle` checkboxes with a `phem-toggle` parent, and the `attributes` of the
// group. The `checked` items have a checked value.
function toggles(attributes = "", checked = []) {
  const box = (value) => `<label>
      <span id="box-${value}" role="checkbox" data-controller="phem-toggle" data-value="${value}" data-phem-checkbox-group-target="item"
            ${checked.includes(value) ? 'data-phem-toggle-checked-value="true"' : ""}>
        <input id="input-${value}" type="checkbox" name="fruits[]" value="${value}" hidden data-phem-toggle-target="input">
      </span>
      ${value}
    </label>`

  return `<div id="group" data-controller="phem-checkbox-group" ${attributes}>
      <span id="parent" role="checkbox" data-controller="phem-toggle" data-phem-checkbox-group-target="parent">
        <span id="parent-indicator" data-phem-toggle-target="indicator"></span>
      </span>
      ${["apple", "pear", "plum"].map(box).join("")}
    </div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function boxes() {
  return ["apple", "pear", "plum"].filter((value) => document.getElementById(`box-${value}`).getAttribute("aria-checked") === "true")
}

describe("phem-checkbox-group with phem-toggle checkboxes", () => {
  it("checks the items and shows the mixed state on the parent", async () => {
    await mount(toggles("", ["pear"]))
    const box = document.getElementById("parent")

    expect(box.getAttribute("aria-checked")).toBe("mixed")
    expect(box.hasAttribute("data-indeterminate")).toBe(true)
    expect(document.getElementById("parent-indicator").hidden).toBe(false)

    const changes = []
    document.getElementById("group").addEventListener("change", (event) => changes.push(event.target.id))

    click("#parent")
    expect(boxes()).toEqual(["apple", "pear", "plum"])
    expect(document.getElementById("input-plum").checked).toBe(true)
    expect(changes).toEqual(["input-apple", "input-plum"])
    expect(box.getAttribute("aria-checked")).toBe("true")
    expect(box.hasAttribute("data-checked")).toBe(true)

    click("#parent")
    expect(boxes()).toEqual([])
    expect(box.getAttribute("aria-checked")).toBe("false")

    click("#parent")
    expect(boxes()).toEqual(["pear"])
    expect(box.getAttribute("aria-checked")).toBe("mixed")

    click("#box-apple")
    expect(boxes()).toEqual(["apple", "pear"])
    expect(box.getAttribute("aria-checked")).toBe("mixed")
  })

  it("controls the items of the allValues value only", async () => {
    await mount(toggles(`data-phem-checkbox-group-all-values-value='["apple","pear"]'`, ["plum"]))
    const box = document.getElementById("parent")

    expect(box.getAttribute("aria-checked")).toBe("false")

    click("#parent")
    expect(boxes()).toEqual(["apple", "pear", "plum"])
    expect(box.getAttribute("aria-checked")).toBe("true")

    click("#parent")
    expect(boxes()).toEqual(["plum"])
  })

  it("keeps a disabled or read-only item", async () => {
    await mount(toggles().replace('data-value="pear"', 'data-value="pear" data-phem-toggle-read-only-value="true"'))

    click("#parent")
    expect(boxes()).toEqual(["apple", "plum"])
    expect(document.getElementById("parent").getAttribute("aria-checked")).toBe("mixed")
  })

  it("sets the items from the value of the server render", async () => {
    await mount(toggles(`data-phem-checkbox-group-value-value='["apple"]'`))

    expect(boxes()).toEqual(["apple"])
    expect(document.getElementById("input-apple").checked).toBe(true)

    // The state stays after the morph of an event.
    click("#box-plum")
    respond(toggles(`data-phem-checkbox-group-value-value='["apple"]'`))
    let done = nextDone()
    click("#save")
    await done
    await settle()
    expect(boxes()).toEqual(["apple", "plum"])
    expect(document.getElementById("parent").getAttribute("aria-checked")).toBe("mixed")

    // A new value in the server render sets the items.
    respond(toggles(`data-phem-checkbox-group-value-value='["pear"]'`))
    done = nextDone()
    click("#save")
    await done
    await settle()
    expect(boxes()).toEqual(["pear"])
    expect(document.getElementById("input-plum").checked).toBe(false)
  })
})

describe("phem-checkbox-group and phem-toggle items that connect in any order", () => {
  // The server render: Fuji is checked in the group value and in `aria-checked`, and its input is
  // not checked.
  const item = (v, checked) => `<span id="${v}" role="checkbox" data-controller="phem-toggle" data-phem-checkbox-group-target="item" data-value="${v}" data-phem-toggle-value-value="${v}" aria-checked="${checked}"><input type="checkbox" data-phem-toggle-target="input" hidden></span>`

  for (const order of [["group", "toggle"], ["toggle", "group"]]) {
    it(`gives the value of the group to the items when the ${order[0]} connects first`, async () => {
      // An application of its own registers the controllers in this order.
      application.stop()
      document.body.innerHTML = `<div id="apples" data-controller="phem-checkbox-group" data-phem-checkbox-group-value-value='["fuji"]'>${item("fuji", true)}${item("gala", false)}</div>`
      const app = Application.start()
      for (const name of order) {
        if (name === "group") app.register("phem-checkbox-group", PhemCheckboxGroupController)
        else app.register("phem-toggle", PhemToggleController)
      }
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect(document.getElementById("fuji").getAttribute("aria-checked")).toBe("true")
      expect(document.getElementById("fuji").getAttribute("data-phem-toggle-checked-value")).toBe("true")

      document.getElementById("gala").click()
      await new Promise((resolve) => setTimeout(resolve, 0))
      expect(JSON.parse(document.getElementById("apples").getAttribute("data-phem-checkbox-group-value-value"))).toEqual(["fuji", "gala"])
      app.stop()
      document.body.innerHTML = ""
      application.start()
    })
  }
})
