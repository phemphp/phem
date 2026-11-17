import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemTabsController from "../dist/phem_tabs_controller.js"
import { answer, click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-tabs", PhemTabsController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// Three tabs (a, b, c) and their panels, a "Save" button that sends an event, and the `attributes`
// of the controller element and of the list. The server render has no ARIA attributes.
function tabs(attributes = "", listAttributes = "", names = ["a", "b", "c"]) {
  const tab = (n) => `<button id="tab-${n}" data-phem-tabs-target="tab">${n}</button>`
  const panel = (n) => `<div id="panel-${n}" data-phem-tabs-target="panel">Panel ${n}</div>`

  return `<div id="tabs" data-controller="phem-tabs" ${attributes}>
      <div id="list" data-phem-tabs-target="list" ${listAttributes}>${names.map(tab).join("")}</div>
      ${names.map(panel).join("")}
      <button id="save" data-phem-on-click="c1.save">Save</button>
    </div>`
}

// The name of the selected tab. The tabs and the panels must agree. With no focus in the tabs, the
// selected tab is the tab stop. With the focus in the tabs, the tab stop can be on another tab.
function selected() {
  const names = [...document.querySelectorAll("#tabs > [data-phem-tabs-target=panel]")].filter((el) => !el.hidden).map((el) => el.id.slice(6))
  const tabs = [...document.querySelectorAll("[role=tab][aria-selected=true]")].map((el) => el.id.slice(4))
  if (names.length !== 1 || tabs.join() !== names.join()) throw new Error(`The tabs (${tabs}) and the panels (${names}) do not agree`)

  const focused = document.activeElement?.closest("[role=tab]")
  if (!focused && document.getElementById(`tab-${names[0]}`).tabIndex !== 0) throw new Error(`The selected tab (${names[0]}) is not the tab stop`)

  return names[0]
}

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)

  return event
}

async function morphOnSave() {
  const done = nextDone()
  click("#save")
  await done
}

describe("phem-tabs", () => {
  it("sets the ARIA roles and shows the first panel", async () => {
    await mount(tabs())

    expect(selected()).toBe("a")
    expect(document.getElementById("list").getAttribute("role")).toBe("tablist")
    expect(document.getElementById("tab-b").getAttribute("aria-selected")).toBe("false")
    expect(document.getElementById("tab-b").tabIndex).toBe(-1)
    expect(document.getElementById("tab-a").getAttribute("aria-controls")).toBe("panel-a")
    expect(document.getElementById("panel-b").getAttribute("role")).toBe("tabpanel")
    expect(document.getElementById("panel-b").getAttribute("aria-labelledby")).toBe("tab-b")
    expect(document.getElementById("panel-b").tabIndex).toBe(0)
  })

  it("selects a tab on a click", async () => {
    await mount(tabs())
    click("#tab-b")

    expect(selected()).toBe("b")
    expect(document.getElementById("tabs").getAttribute("data-phem-tabs-active-value")).toBe("1")
  })

  it("sets the data attributes of the parts", async () => {
    await mount(tabs())

    const root = document.getElementById("tabs")
    expect(root.getAttribute("data-orientation")).toBe("horizontal")
    expect(root.getAttribute("data-activation-direction")).toBe("none")
    expect(document.getElementById("tab-a").hasAttribute("data-active")).toBe(true)
    expect(document.getElementById("tab-b").hasAttribute("data-active")).toBe(false)
    expect(document.getElementById("panel-b").getAttribute("data-index")).toBe("1")
    expect(document.getElementById("panel-b").hasAttribute("data-hidden")).toBe(true)
    expect(document.getElementById("panel-a").hasAttribute("data-hidden")).toBe(false)
    expect(document.getElementById("panel-a").hasAttribute("data-open")).toBe(true)
  })

  it("moves the focus with the arrow keys, and selects with Enter", async () => {
    await mount(tabs())
    document.getElementById("tab-a").focus()

    press("ArrowRight")
    expect(document.activeElement.id).toBe("tab-b")
    expect(selected()).toBe("a")
    document.activeElement.click()
    expect(selected()).toBe("b")
  })

  it("clicks a link tab on Enter and on Space, so it selects it and opens its link", async () => {
    await mount(tabs().replace('<button id="tab-b" data-phem-tabs-target="tab">b</button>', '<a id="tab-b" href="#b" data-phem-tabs-target="tab">b</a>'))
    const link = document.getElementById("tab-b")
    const clicks = vi.fn((event) => event.preventDefault())
    link.addEventListener("click", clicks)

    const enter = new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true })
    link.dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(false)

    const space = new KeyboardEvent("keydown", { key: " ", bubbles: true, cancelable: true })
    link.dispatchEvent(space)
    expect(space.defaultPrevented).toBe(true)
    expect(clicks).toHaveBeenCalledTimes(1)
    expect(document.getElementById("panel-b").hidden).toBe(false)
  })

  it("sets the direction of the change", async () => {
    await mount(tabs())
    const box = (left) => () => ({ left, top: 0, width: 10, height: 10, right: left + 10, bottom: 10 })
    document.getElementById("tab-a").getBoundingClientRect = box(0)
    document.getElementById("tab-b").getBoundingClientRect = box(20)
    click("#tab-b")

    expect(document.getElementById("tabs").getAttribute("data-activation-direction")).toBe("right")
    expect(document.getElementById("panel-b").getAttribute("data-activation-direction")).toBe("right")
    click("#tab-a")
    expect(document.getElementById("tab-a").getAttribute("data-activation-direction")).toBe("left")
  })

  it("gives the box of the selected tab to the indicator", async () => {
    await mount(tabs().replace("</div>\n      <div id=\"panel-a\"", '<span id="indicator" data-phem-tabs-target="indicator"></span></div>\n      <div id="panel-a"'))
    const indicator = document.getElementById("indicator")
    document.getElementById("list").getBoundingClientRect = () => ({ left: 100, top: 50, width: 300, height: 40 })
    document.getElementById("tab-b").getBoundingClientRect = () => ({ left: 160, top: 55, width: 80, height: 30 })
    click("#tab-b")

    expect(indicator.style.getPropertyValue("--active-tab-left")).toBe("60px")
    expect(indicator.style.getPropertyValue("--active-tab-top")).toBe("5px")
    expect(indicator.style.getPropertyValue("--active-tab-width")).toBe("80px")
    expect(indicator.style.getPropertyValue("--active-tab-height")).toBe("30px")
    expect(indicator.getAttribute("data-orientation")).toBe("horizontal")
  })

  it("selects a tab that is not a button with Enter and Space", async () => {
    await mount(tabs().replaceAll("<button id=\"tab-", "<div tabindex=\"0\" id=\"tab-").replaceAll("</button><", "</div><").replace(/<\/button><\/div>/, "</div></div>"))
    document.getElementById("tab-a").focus()
    press("ArrowRight")

    expect(press("Enter").defaultPrevented).toBe(true)
    expect(selected()).toBe("b")
    press("ArrowRight")
    press(" ")
    expect(selected()).toBe("c")
  })

  it("observes the size of the first list and tabs", async () => {
    const observed = []
    vi.stubGlobal("ResizeObserver", class {
      observe(el) {
        observed.push(el.id)
      }

      disconnect() {}
    })
    await mount(tabs())

    expect(observed).toEqual(expect.arrayContaining(["list", "tab-a", "tab-b", "tab-c"]))
  })

  it("does not go from the last tab to the first one with loopFocus false", async () => {
    await mount(tabs('data-phem-tabs-loop-focus-value="false"'))
    document.getElementById("tab-c").focus()
    press("ArrowRight")

    expect(document.activeElement.id).toBe("tab-c")
  })

  it("moves the selection and the focus with the arrow keys, Home and End, with activateOnFocus", async () => {
    await mount(tabs('data-phem-tabs-activate-on-focus-value="true"'))
    document.getElementById("tab-a").focus()

    press("ArrowRight")
    expect(selected()).toBe("b")
    expect(document.activeElement.id).toBe("tab-b")
    press("ArrowRight")
    press("ArrowRight")
    expect(selected()).toBe("a")
    press("ArrowLeft")
    expect(selected()).toBe("c")
    press("Home")
    expect(selected()).toBe("a")
    expect(press("End").defaultPrevented).toBe(true)
    expect(selected()).toBe("c")
    expect(document.activeElement.id).toBe("tab-c")
  })

  it("skips a disabled tab", async () => {
    await mount(tabs().replace('id="tab-b"', 'id="tab-b" disabled'))
    document.getElementById("tab-a").focus()
    press("ArrowRight")

    expect(document.activeElement.id).toBe("tab-c")
    expect(document.getElementById("tab-b").hasAttribute("data-disabled")).toBe(true)
  })

  it("uses the up and down arrow keys with the vertical orientation", async () => {
    await mount(tabs('data-phem-tabs-orientation-value="vertical" data-phem-tabs-activate-on-focus-value="true"'))
    document.getElementById("tab-a").focus()

    expect(document.getElementById("list").getAttribute("aria-orientation")).toBe("vertical")
    expect(document.getElementById("tab-a").getAttribute("data-orientation")).toBe("vertical")

    expect(press("ArrowRight").defaultPrevented).toBe(false)
    expect(selected()).toBe("a")
    press("ArrowDown")
    expect(selected()).toBe("b")
    press("ArrowUp")
    expect(selected()).toBe("a")
  })

  it("does not handle a key that another element handled, or a key with a modifier", async () => {
    await mount(tabs())
    document.getElementById("tab-a").focus()
    document.getElementById("tab-a").addEventListener("keydown", (e) => e.key === "End" && e.preventDefault())
    press("End")
    press("ArrowRight", { ctrlKey: true })

    expect(selected()).toBe("a")
  })

  it("keeps the selected tab and the ARIA attributes after the morph of an event", async () => {
    await mount(tabs())
    respond(tabs())
    click("#tab-c")
    await morphOnSave()

    expect(selected()).toBe("c")
    expect(document.getElementById("tabs").getAttribute("data-phem-tabs-active-value")).toBe("2")
    expect(document.getElementById("list").getAttribute("role")).toBe("tablist")
    expect(document.getElementById("tab-c").getAttribute("aria-controls")).toBe("panel-c")
    expect(document.getElementById("panel-c").getAttribute("aria-labelledby")).toBe("tab-c")
  })

  it("keeps the other attributes of the server render", async () => {
    await mount(tabs())
    respond(tabs().replace('id="panel-c"', 'id="panel-c" class="wide"'))
    click("#tab-c")
    await morphOnSave()

    expect(document.getElementById("panel-c").className).toBe("wide")
    expect(selected()).toBe("c")
  })

  it("keeps the ARIA attributes of the server render", async () => {
    const markup = tabs().replace('id="tab-a"', 'id="tab-a" aria-controls="other"').replace('id="panel-a"', 'id="panel-a" tabindex="-1"')
    await mount(markup)

    expect(document.getElementById("tab-a").getAttribute("aria-controls")).toBe("other")
    expect(document.getElementById("panel-a").tabIndex).toBe(-1)
  })

  it("selects the tab of the active value of the server render", async () => {
    await mount(tabs('data-phem-tabs-active-value="1"'))

    expect(selected()).toBe("b")
  })

  it("selects the tab of the active value of a new server render", async () => {
    await mount(tabs('data-phem-tabs-active-value="0"'))
    respond(tabs('data-phem-tabs-active-value="1"'))
    click("#tab-c")
    await morphOnSave()

    expect(selected()).toBe("b")
  })

  it("keeps a tab that the user selected after the request of the answer left", async () => {
    await mount(tabs())
    click("#tab-b")

    // The answer of the server comes after a second click.
    let resolve
    fetch.mockImplementationOnce(() => new Promise((r) => (resolve = r)))
    const done = nextDone()
    click("#save")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    click("#tab-c")
    resolve(new Response(JSON.stringify(answer(tabs('data-phem-tabs-active-value="1"'))), { status: 200, headers: { "Content-Type": "application/json" } }))
    await done

    expect(selected()).toBe("c")

    // A new value of the server still sets the state.
    respond(tabs('data-phem-tabs-active-value="0"'))
    await morphOnSave()
    expect(selected()).toBe("a")
  })

  it("takes the value of the answer to an event of the same click", async () => {
    // A tab with a handler: the server keeps the first tab.
    const markup = (attributes) => tabs(attributes).replace('<button id="tab-b" data-phem-tabs-target="tab">', '<button id="tab-b" data-phem-tabs-target="tab" data-phem-on-click="c1.pick">')
    await mount(markup(""))
    respond(markup('data-phem-tabs-active-value="0"'))
    const done = nextDone()
    click("#tab-b")
    await done

    expect(selected()).toBe("a")
  })

  it("selects the first tab when the server render has no tab with the active value", async () => {
    await mount(tabs())
    respond(tabs("", "", ["a", "b"]))
    click("#tab-c")
    await morphOnSave()

    expect(selected()).toBe("a")
  })

  it("selects the first tab that is not disabled with no active value", async () => {
    await mount(tabs().replace('id="tab-a"', 'id="tab-a" disabled'))

    expect(selected()).toBe("b")
  })

  describe("with values", () => {
    // The tabs and the panels have a value and no id. The panels are in another order.
    function valued(attributes = "") {
      return `<div id="tabs" data-controller="phem-tabs" ${attributes}>
          <div data-phem-tabs-target="list">
            <button class="tab" data-phem-tabs-target="tab" data-value="profile">Profile</button>
            <button class="tab" data-phem-tabs-target="tab" data-value="security">Security</button>
          </div>
          <div class="panel" data-phem-tabs-target="panel" data-value="security">Security panel</div>
          <div class="panel" data-phem-tabs-target="panel" data-value="profile">Profile panel</div>
          <button id="save" data-phem-on-click="c1.save">Save</button>
        </div>`
    }

    const tabEls = () => [...document.querySelectorAll(".tab")]
    const panelEls = () => [...document.querySelectorAll(".panel")]

    it("shows the panel with the value of the selected tab, and keeps the value in the active value", async () => {
      await mount(valued())

      expect(panelEls().map((el) => el.hidden)).toEqual([true, false])

      tabEls()[1].click()
      expect(document.getElementById("tabs").getAttribute("data-phem-tabs-active-value")).toBe("security")
      expect(panelEls().map((el) => el.hidden)).toEqual([false, true])
    })

    it("selects the tab of the active value, also after a new server render", async () => {
      await mount(valued('data-phem-tabs-active-value="security"'))

      expect(tabEls()[1].getAttribute("aria-selected")).toBe("true")

      respond(valued('data-phem-tabs-active-value="profile"'))
      await morphOnSave()
      expect(tabEls()[0].getAttribute("aria-selected")).toBe("true")
    })

    it("gives no id to a tab or a panel", async () => {
      await mount(valued())

      expect(tabEls().every((el) => !el.id)).toBe(true)
      expect(panelEls().every((el) => !el.id)).toBe(true)
    })

    it("links a tab and its panel with element references when they have no id", async () => {
      const proto = Element.prototype
      const set = (name) => Object.defineProperty(proto, name, { configurable: true, get() { return this[`_${name}`] ?? null }, set(v) { this[`_${name}`] = v } })
      set("ariaControlsElements")
      set("ariaLabelledByElements")
      try {
        await mount(valued())

        expect(tabEls()[0].ariaControlsElements).toEqual([panelEls()[1]])
        expect(panelEls()[0].ariaLabelledByElements).toEqual([tabEls()[1]])
      } finally {
        delete proto.ariaControlsElements
        delete proto.ariaLabelledByElements
      }
    })
  })

  it("gives a tab that the morph adds the state", async () => {
    await mount(tabs("", "", ["a", "b"]))
    respond(tabs())
    click("#tab-b")
    await morphOnSave()

    expect(selected()).toBe("b")
    expect(document.getElementById("tab-c").getAttribute("role")).toBe("tab")
    expect(document.getElementById("panel-c").hidden).toBe(true)
  })

  it("keeps the state of nested tabs apart", async () => {
    const nested = `<div id="tabs" data-controller="phem-tabs">
        <div data-phem-tabs-target="list">
          <button id="tab-a" data-phem-tabs-target="tab">a</button>
          <button id="tab-b" data-phem-tabs-target="tab">b</button>
        </div>
        <div id="panel-a" data-phem-tabs-target="panel">
          <div data-controller="phem-tabs">
            <div data-phem-tabs-target="list">
              <button id="inner-x" data-phem-tabs-target="tab">x</button>
              <button id="inner-y" data-phem-tabs-target="tab">y</button>
            </div>
            <div id="inner-panel-x" data-phem-tabs-target="panel">x</div>
            <div id="inner-panel-y" data-phem-tabs-target="panel">y</div>
          </div>
        </div>
        <div id="panel-b" data-phem-tabs-target="panel">b</div>
      </div>`
    await mount(nested)
    click("#inner-y")

    expect(document.getElementById("inner-panel-y").hidden).toBe(false)
    expect(document.getElementById("inner-panel-x").hidden).toBe(true)
    expect(document.getElementById("panel-a").hidden).toBe(false)
    document.getElementById("inner-y").focus()
    press("ArrowRight")
    document.activeElement.click()
    expect(document.getElementById("inner-panel-x").hidden).toBe(false)
    expect(document.getElementById("panel-a").hidden).toBe(false)
  })
})

describe("phem-tabs: the tab with the focus", () => {
  const tabIndexes = () => ["a", "b", "c"].map((n) => document.getElementById(`tab-${n}`).tabIndex)

  it("gives the Tab stop to the tab with the focus, not to the selected tab", async () => {
    await mount(tabs())
    document.getElementById("tab-a").focus()

    press("ArrowRight")

    expect(document.activeElement.id).toBe("tab-b")
    expect(document.getElementById("tab-a").getAttribute("aria-selected")).toBe("true")
    expect(tabIndexes()).toEqual([-1, 0, -1])
  })

  it("keeps the Tab stop on the tab with the focus after the morph of an event", async () => {
    await mount(tabs())
    respond(tabs())
    document.getElementById("tab-a").focus()
    press("ArrowRight")
    press("ArrowRight")
    await morphOnSave()

    expect(document.getElementById("tab-a").getAttribute("aria-selected")).toBe("true")
    expect(tabIndexes()).toEqual([-1, -1, 0])
  })

  it("moves the Tab stop to the new selected tab of a server render when no tab has the focus", async () => {
    await mount(tabs())
    respond(tabs('data-phem-tabs-active-value="2"'))
    document.getElementById("tab-a").focus()
    press("ArrowRight")
    document.activeElement.blur()
    await morphOnSave()

    expect(selected()).toBe("c")
    expect(tabIndexes()).toEqual([-1, -1, 0])
  })

  it("keeps the Tab stop on the tab with the focus when a server render selects another tab", async () => {
    await mount(tabs())
    respond(tabs('data-phem-tabs-active-value="2"'))
    document.getElementById("tab-a").focus()
    press("ArrowRight")
    await morphOnSave()

    expect(document.getElementById("tab-c").getAttribute("aria-selected")).toBe("true")
    expect(tabIndexes()).toEqual([-1, 0, -1])
  })

  it("gives the Tab stop to the selected tab when the morph removes the tab of the last focus", async () => {
    await mount(tabs())
    respond(tabs("", "", ["a", "b"]))
    document.getElementById("tab-a").focus()
    press("ArrowRight")
    press("ArrowRight")
    document.activeElement.blur()
    await morphOnSave()

    expect(document.getElementById("tab-c")).toBeNull()
    expect(document.getElementById("tab-a").tabIndex).toBe(0)
    expect(document.getElementById("tab-b").tabIndex).toBe(-1)
  })

  it("gives the Tab stop to the selected tab when the morph disables the tab of the last focus", async () => {
    await mount(tabs())
    respond(tabs().replace('id="tab-b"', 'id="tab-b" disabled'))
    document.getElementById("tab-a").focus()
    press("ArrowRight")
    document.activeElement.blur()
    await morphOnSave()

    expect(document.getElementById("tab-b").disabled).toBe(true)
    expect(tabIndexes()).toEqual([0, -1, -1])
  })

  it("does not move the focus on an arrow key with Shift", async () => {
    await mount(tabs())
    document.getElementById("tab-a").focus()

    const event = press("ArrowRight", { shiftKey: true })

    expect(document.activeElement.id).toBe("tab-a")
    expect(event.defaultPrevented).toBe(false)
  })
})

describe("phem-tabs: the box of the indicator", () => {
  it("keeps the fractions of a pixel and subtracts the border of the list", async () => {
    await mount(tabs().replace("</div>\n      <div id=\"panel-a\"", '<span id="indicator" data-phem-tabs-target="indicator"></span></div>\n      <div id="panel-a"'))
    const indicator = document.getElementById("indicator")
    const list = document.getElementById("list")
    Object.defineProperty(list, "clientLeft", { configurable: true, get: () => 2 })
    Object.defineProperty(list, "clientTop", { configurable: true, get: () => 1 })
    list.getBoundingClientRect = () => ({ left: 100, top: 50, width: 300, height: 40 })
    document.getElementById("tab-b").getBoundingClientRect = () => ({ left: 160.5, top: 55.25, width: 80.5, height: 30.25 })
    click("#tab-b")

    expect(indicator.style.getPropertyValue("--active-tab-left")).toBe("58.5px")
    expect(indicator.style.getPropertyValue("--active-tab-top")).toBe("4.25px")
    expect(indicator.style.getPropertyValue("--active-tab-width")).toBe("80.5px")
    expect(indicator.style.getPropertyValue("--active-tab-height")).toBe("30.25px")
  })
})
