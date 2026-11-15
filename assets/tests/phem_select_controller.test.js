import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemSelectController from "../dist/phem_select_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-select", PhemSelectController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A select of apples with a group, a disabled item, a hidden input and a "Save" button that
// sends an event. `attributes` go on the controller element.
function select(attributes = "", input = '<input id="input" data-phem-select-target="input" name="apple" type="hidden" />') {
  const item = (value, label, extra = "") => `<div id="item-${value}" data-phem-select-target="item" data-value="${value}" ${extra}>
      <span id="indicator-${value}" data-phem-select-target="itemIndicator">✓</span>
      <span data-phem-select-target="itemText">${label}</span>
    </div>`

  return `<div id="select" data-controller="phem-select" ${attributes}>
      <button id="trigger" data-phem-select-target="trigger">
        <span id="value" data-phem-select-target="value" placeholder="Select apple"></span>
        <span id="icon" data-phem-select-target="icon">▾</span>
      </button>
      ${input}
      <div id="popup" data-phem-select-target="popup" hidden>
        <div id="up" data-phem-select-target="scrollUpArrow">▴</div>
        <div id="list" data-phem-select-target="list">
          <div id="group" data-phem-select-target="group">
            <div id="label" data-phem-select-target="groupLabel">Red</div>
            ${item("gala", "Gala")}
            ${item("fuji", "Fuji")}
          </div>
          <div id="separator" data-phem-select-target="separator"></div>
          ${item("honeycrisp", "Honeycrisp", 'aria-disabled="true"')}
          ${item("granny", "Granny Smith")}
        </div>
        <div id="down" data-phem-select-target="scrollDownArrow">▾</div>
      </div>
      <button id="save" data-phem-on-click="c1.save">Save</button>
    </div>`
}

const el = (id) => document.getElementById(id)

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)

  return event
}

function pointer(type, target, init = {}) {
  const event = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init })
  Object.defineProperty(event, "pointerType", { value: init.pointerType ?? "mouse" })
  target.dispatchEvent(event)

  return event
}

function isOpen() {
  return !el("popup").hidden && el("trigger").getAttribute("aria-expanded") === "true"
}

async function morphOnSave(markup) {
  respond(markup)
  const done = nextDone()
  click("#save")
  await done
  await new Promise((resolve) => setTimeout(resolve, 0))
}

describe("phem-select", () => {
  it("sets the ARIA roles and shows the placeholder", async () => {
    await mount(select())

    const trigger = el("trigger")
    expect(trigger.getAttribute("role")).toBe("combobox")
    expect(trigger.getAttribute("aria-haspopup")).toBe("listbox")
    expect(trigger.getAttribute("aria-expanded")).toBe("false")
    expect(trigger.hasAttribute("data-placeholder")).toBe(true)
    expect(el("list").getAttribute("role")).toBe("listbox")
    expect(el("item-gala").getAttribute("role")).toBe("option")
    expect(el("item-gala").getAttribute("aria-selected")).toBe("false")
    expect(el("item-gala").tabIndex).toBe(-1)
    expect(el("item-honeycrisp").hasAttribute("data-disabled")).toBe(true)
    expect(el("group").getAttribute("role")).toBe("group")
    expect(el("group").getAttribute("aria-labelledby")).toBe("label")
    expect(el("separator").getAttribute("role")).toBe("separator")
    expect(el("value").textContent).toBe("Select apple")
    expect(el("value").hasAttribute("data-placeholder")).toBe(true)
    expect(el("indicator-gala").hidden).toBe(true)
    expect(el("up").hidden).toBe(true)
    expect(el("up").getAttribute("data-direction")).toBe("up")
  })

  it("shows the label of the value of the server render", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))

    expect(el("value").textContent).toBe("Fuji")
    expect(el("value").hasAttribute("data-placeholder")).toBe(false)
    expect(el("item-fuji").getAttribute("aria-selected")).toBe("true")
    expect(el("item-fuji").hasAttribute("data-selected")).toBe(true)
    expect(el("indicator-fuji").hidden).toBe(false)
    expect(el("input").value).toBe("fuji")
  })

  it("opens on a click and focuses the selected item", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))
    click("#trigger")

    expect(isOpen()).toBe(true)
    expect(el("trigger").hasAttribute("data-popup-open")).toBe(true)
    expect(el("trigger").hasAttribute("data-pressed")).toBe(true)
    expect(el("icon").hasAttribute("data-popup-open")).toBe(true)
    expect(document.activeElement).toBe(el("item-fuji"))
    expect(el("item-fuji").hasAttribute("data-highlighted")).toBe(true)
    expect(document.documentElement.style.overflow).toBe("hidden")
  })

  it("selects an item on a click, and closes", async () => {
    await mount(select())
    const events = []
    el("input").addEventListener("input", () => events.push("input"))
    el("input").addEventListener("change", () => events.push("change"))
    el("select").addEventListener("phem-select:change", (event) => events.push(event.detail.value))

    click("#trigger")
    click("#item-gala")

    expect(isOpen()).toBe(false)
    expect(el("select").getAttribute("data-phem-select-value-value")).toBe("gala")
    expect(el("value").textContent).toBe("Gala")
    expect(el("input").value).toBe("gala")
    expect(events).toEqual(["input", "change", "gala"])
    expect(document.activeElement).toBe(el("trigger"))
    expect(document.documentElement.style.overflow).toBe("")
  })

  it("moves the focus with the keyboard, and selects with Enter", async () => {
    await mount(select())
    el("trigger").focus()
    press("ArrowDown")

    expect(isOpen()).toBe(true)
    expect(document.activeElement).toBe(el("item-gala"))

    press("ArrowDown")
    press("ArrowDown")

    // The disabled item is skipped.
    expect(document.activeElement).toBe(el("item-granny"))

    press("ArrowDown")

    expect(document.activeElement).toBe(el("item-gala"))

    press("End")
    press("Enter")

    expect(isOpen()).toBe(false)
    expect(el("value").textContent).toBe("Granny Smith")
  })

  it("opens on ArrowUp on the last item", async () => {
    await mount(select())
    el("trigger").focus()
    press("ArrowUp")

    expect(document.activeElement).toBe(el("item-granny"))
  })

  it("goes to an item with the letters of its label", async () => {
    await mount(select())
    el("trigger").focus()
    press("Enter")
    press("f")

    expect(document.activeElement).toBe(el("item-fuji"))

    press(" ")

    expect(el("value").textContent).toBe("Fuji")
  })

  it("selects an item with a letter on the closed trigger", async () => {
    await mount(select())
    el("trigger").focus()
    press("g")

    expect(isOpen()).toBe(false)
    expect(el("value").textContent).toBe("Gala")
  })

  it("closes on Escape and on Tab, with the focus on the trigger", async () => {
    await mount(select())
    el("trigger").focus()
    press("ArrowDown")
    press("Escape")

    expect(isOpen()).toBe(false)
    expect(document.activeElement).toBe(el("trigger"))

    press("ArrowDown")
    press("Tab")

    expect(isOpen()).toBe(false)
    expect(document.activeElement).toBe(el("trigger"))
  })

  it("selects more than one item with the multiple value", async () => {
    await mount(select('data-phem-select-multiple-value="true"'))
    const values = []
    el("select").addEventListener("phem-select:change", (event) => values.push(event.detail.value))

    click("#trigger")
    click("#item-gala")
    click("#item-granny")

    expect(isOpen()).toBe(true)
    expect(el("list").getAttribute("aria-multiselectable")).toBe("true")
    expect(el("select").getAttribute("data-phem-select-value-value")).toBe('["gala","granny"]')
    expect(el("value").textContent).toBe("Gala, Granny Smith")
    expect(el("input").value).toBe("gala,granny")

    click("#item-gala")

    expect(el("value").textContent).toBe("Granny Smith")
    expect(values).toEqual([["gala"], ["gala", "granny"], ["granny"]])
  })

  it("selects the options of a select input", async () => {
    const input = `<select id="input" data-phem-select-target="input" name="apples[]" multiple hidden>
        <option value="gala">Gala</option><option value="fuji">Fuji</option><option value="granny">Granny Smith</option>
      </select>`
    await mount(select(`data-phem-select-multiple-value="true" data-phem-select-value-value='["fuji"]'`, input))

    expect([...el("input").selectedOptions].map((o) => o.value)).toEqual(["fuji"])

    click("#trigger")
    click("#item-granny")

    expect([...el("input").selectedOptions].map((o) => o.value)).toEqual(["fuji", "granny"])
  })

  it("does not open when it is disabled or read only", async () => {
    await mount(select('data-phem-select-disabled-value="true" data-phem-select-required-value="true"'))
    el("trigger").disabled = false
    click("#trigger")

    expect(isOpen()).toBe(false)
    expect(el("trigger").hasAttribute("data-disabled")).toBe(true)
    expect(el("input").disabled).toBe(true)
    expect(el("input").required).toBe(true)
    expect(el("trigger").hasAttribute("data-required")).toBe(true)

    await mount(select('data-phem-select-read-only-value="true"'))
    click("#trigger")

    expect(isOpen()).toBe(false)
    expect(el("trigger").hasAttribute("data-readonly")).toBe(true)
  })

  it("selects the item of a release after a drag from the trigger", async () => {
    await mount(select())
    pointer("pointerdown", el("trigger"), { clientX: 10, clientY: 10 })

    expect(isOpen()).toBe(true)

    pointer("pointerup", el("item-fuji"), { clientX: 10, clientY: 60 })
    click("#select")

    expect(isOpen()).toBe(false)
    expect(el("value").textContent).toBe("Fuji")
  })

  it("does not select on a release with no drag", async () => {
    await mount(select())
    pointer("pointerdown", el("trigger"), { clientX: 10, clientY: 10 })
    pointer("pointerup", el("trigger"), { clientX: 10, clientY: 10 })
    click("#trigger")

    expect(isOpen()).toBe(true)
    expect(el("value").textContent).toBe("Select apple")
  })

  it("shows a scroll arrow when the list can scroll", async () => {
    await mount(select())
    const list = el("list")
    Object.defineProperty(list, "scrollHeight", { configurable: true, get: () => 300 })
    Object.defineProperty(list, "clientHeight", { configurable: true, get: () => 100 })
    click("#trigger")

    expect(el("down").hidden).toBe(false)
    expect(el("down").hasAttribute("data-visible")).toBe(true)
    expect(el("up").hidden).toBe(true)

    list.scrollTop = 200
    list.dispatchEvent(new Event("scroll"))

    expect(el("up").hasAttribute("data-visible")).toBe(true)
    expect(el("down").hidden).toBe(true)
  })

  it("keeps the value and the open popup after the morph", async () => {
    await mount(select('data-phem-select-multiple-value="true"'))
    click("#trigger")
    click("#item-fuji")

    await morphOnSave(select('data-phem-select-multiple-value="true"'))

    expect(isOpen()).toBe(true)
    expect(el("select").getAttribute("data-phem-select-value-value")).toBe('["fuji"]')
    expect(el("value").textContent).toBe("Fuji")
    expect(el("item-fuji").getAttribute("aria-selected")).toBe("true")
    expect(el("indicator-fuji").hidden).toBe(false)
    expect(el("input").value).toBe("fuji")
    expect(el("trigger").hasAttribute("data-popup-open")).toBe(true)
  })

  it("takes a new value of a server render", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))

    await morphOnSave(select('data-phem-select-value-value="gala"'))

    expect(el("value").textContent).toBe("Gala")
    expect(el("input").value).toBe("gala")
    expect(el("item-fuji").hasAttribute("data-selected")).toBe(false)
  })
})
