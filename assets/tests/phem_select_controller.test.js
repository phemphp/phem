import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemSelectController from "../dist/phem_select_controller.js"
import { click, hover, mount, nextDone, respond } from "./support.js"

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

  it("names the trigger with the label target, and focuses the trigger on a click on the label", async () => {
    await mount(select().replace(`<button id="trigger"`, `<div id="name" data-phem-select-target="label">Apple</div><button id="trigger"`))

    expect(document.getElementById("trigger").getAttribute("aria-labelledby")).toBe("name")

    document.getElementById("name").click()
    expect(document.activeElement.id).toBe("trigger")
    expect(isOpen()).toBe(false)
  })

  it("does not open on a click on a native label of the trigger, and keeps the aria-labelledby of the markup", async () => {
    await mount(select().replace(`<button id="trigger"`, `<label id="name" for="trigger" data-phem-select-target="label">Apple</label><button id="trigger" aria-labelledby="name value"`))
    document.getElementById("name").click()

    expect(isOpen()).toBe(false)
    expect(document.getElementById("trigger").getAttribute("aria-labelledby")).toBe("name value")
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

  it("highlights an item on a move of the pointer, not when it comes under a pointer that does not move", async () => {
    await mount(select())
    el("trigger").focus()
    press("ArrowDown")
    expect(document.activeElement).toBe(el("item-gala"))

    // The popup opens under the pointer: the browser sends only `pointerover`.
    el("item-granny").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
    expect(document.activeElement).toBe(el("item-gala"))

    hover(el("item-granny"))
    expect(document.activeElement).toBe(el("item-granny"))
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

  it("highlights the first item on a mouse open with no value", async () => {
    await mount(select())
    pointer("pointerdown", el("trigger"), { clientX: 10, clientY: 10 })

    expect(isOpen()).toBe(true)
    expect(document.activeElement).toBe(el("item-gala"))
    expect(el("item-gala").hasAttribute("data-highlighted")).toBe(true)
  })

  it("removes the highlight when the pointer leaves the items, and focuses the popup", async () => {
    await mount(select())
    el("trigger").focus()
    press("ArrowDown")
    hover(el("item-granny"))
    expect(document.activeElement).toBe(el("item-granny"))

    el("item-granny").dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: el("separator") }))

    expect(document.activeElement).toBe(el("popup"))
    expect(el("list").querySelectorAll("[data-highlighted]")).toHaveLength(0)
  })

  it("keeps the typed text for 750 ms", async () => {
    await mount(select())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

    try {
      el("trigger").focus()
      press("Enter")
      press("g")
      expect(document.activeElement).toBe(el("item-gala"))

      vi.advanceTimersByTime(600)
      press("r")

      expect(document.activeElement).toBe(el("item-granny"))
    } finally {
      vi.useRealTimers()
    }
  })

  it("starts a new typed text from the selected item", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))
    click("#trigger")
    expect(document.activeElement).toBe(el("item-fuji"))

    press("ArrowDown")
    expect(document.activeElement).toBe(el("item-granny"))

    // The next item with "g" after Fuji, the selected item.
    press("g")

    expect(document.activeElement).toBe(el("item-granny"))
  })

  it("closes at a press outside, and focuses the trigger", async () => {
    await mount(select())
    click("#trigger")
    expect(isOpen()).toBe(true)

    pointer("pointerdown", document.body)

    expect(isOpen()).toBe(false)
    expect(document.activeElement).toBe(el("trigger"))
  })

  it("gives tabindex 0 to the highlighted item only, and keeps it after the morph", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))
    click("#trigger")

    expect(el("item-fuji").getAttribute("tabindex")).toBe("0")
    expect(el("item-gala").getAttribute("tabindex")).toBe("-1")

    press("ArrowDown")

    expect(el("item-granny").getAttribute("tabindex")).toBe("0")
    expect(el("item-fuji").getAttribute("tabindex")).toBe("-1")

    await morphOnSave(select('data-phem-select-value-value="fuji"'))

    expect(el("item-granny").getAttribute("tabindex")).toBe("0")
    expect(el("item-fuji").getAttribute("tabindex")).toBe("-1")
  })

  it("scrolls one item each 40 ms on a scroll arrow", async () => {
    await mount(select())
    const list = el("list")
    let scrollTop = 0
    Object.defineProperty(list, "scrollTop", { configurable: true, get: () => scrollTop, set: (value) => (scrollTop = value) })
    Object.defineProperty(list, "scrollHeight", { configurable: true, get: () => 80 })
    Object.defineProperty(list, "clientHeight", { configurable: true, get: () => 40 })
    Object.defineProperty(el("down"), "offsetHeight", { configurable: true, get: () => 10 })

    // The items are 20 px high, from the top of the list.
    const items = ["gala", "fuji", "honeycrisp", "granny"].map((value) => el(`item-${value}`))
    const rect = (top, height) => ({ top, bottom: top + height, left: 0, right: 100, width: 100, height, x: 0, y: top })
    vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function () {
      const index = items.indexOf(this)

      return index === -1 ? rect(0, this === list ? 40 : 0) : rect(index * 20 - scrollTop, 20)
    })

    click("#trigger")
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

    try {
      pointer("pointerover", el("down"))
      vi.advanceTimersByTime(39)
      expect(scrollTop).toBe(0)

      // Each step shows the next item over the arrow.
      vi.advanceTimersByTime(1)
      expect(scrollTop).toBe(10)
      vi.advanceTimersByTime(40)
      expect(scrollTop).toBe(30)
      vi.advanceTimersByTime(40)
      expect(scrollTop).toBe(40)
      expect(el("down").hasAttribute("data-visible")).toBe(false)
      vi.advanceTimersByTime(80)
      expect(scrollTop).toBe(40)
    } finally {
      vi.useRealTimers()
    }
  })

  it("gives data-popup-side bottom to the trigger of a popup over the trigger", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))
    const root = document.documentElement
    Object.defineProperty(root, "clientHeight", { configurable: true, get: () => 800 })
    Object.defineProperty(root, "clientWidth", { configurable: true, get: () => 800 })

    const rect = (top, height, left = 0, width = 100) => ({ top, bottom: top + height, left, right: left + width, width, height, x: left, y: top })
    const text = el("item-fuji").querySelector("[data-phem-select-target=itemText]")
    vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function () {
      if (this === el("trigger") || this === el("value")) return rect(300, 20, 50)
      if (this === el("popup")) return rect(0, 80)
      if (this === text) return rect(30, 20, 10, 80)

      return rect(0, 0)
    })

    try {
      click("#trigger")

      expect(el("popup").getAttribute("data-side")).toBe("none")
      expect(el("trigger").getAttribute("data-popup-side")).toBe("bottom")
      expect(el("up").getAttribute("data-side")).toBe("none")
    } finally {
      delete root.clientHeight
      delete root.clientWidth
    }
  })

  it("keeps the highlight of a key move when an item goes out from under a pointer that does not move", async () => {
    await mount(select())
    el("trigger").focus()
    press("ArrowDown")
    pointer("pointermove", el("item-gala"))
    expect(document.activeElement).toBe(el("item-gala"))

    press("ArrowDown")
    expect(document.activeElement).toBe(el("item-fuji"))

    // The list scrolls: the separator comes under the pointer.
    el("item-gala").dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: el("separator") }))

    expect(document.activeElement).toBe(el("item-fuji"))
    expect(el("item-fuji").hasAttribute("data-highlighted")).toBe(true)
  })

  it("keeps the highlight when the pointer goes from one item to the next", async () => {
    await mount(select())
    click("#trigger")
    pointer("pointermove", el("item-gala"))

    el("item-gala").dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: el("item-fuji") }))

    expect(document.activeElement).toBe(el("item-gala"))
  })

  it("does not highlight with the pointer with highlightItemOnHover false", async () => {
    await mount(select('data-phem-select-highlight-item-on-hover-value="false"'))
    pointer("pointerdown", el("trigger"), { clientX: 10, clientY: 10 })

    expect(isOpen()).toBe(true)
    expect(document.activeElement).toBe(el("popup"))

    press("ArrowDown")
    expect(document.activeElement).toBe(el("item-gala"))

    el("item-gala").dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: el("separator") }))

    expect(document.activeElement).toBe(el("item-gala"))
  })

  it("stops the text selection only during a press on the trigger", async () => {
    await mount(select())
    const selection = () => {
      const event = new Event("selectstart", { bubbles: true, cancelable: true })
      document.body.dispatchEvent(event)

      return event.defaultPrevented
    }

    pointer("pointerdown", el("trigger"))
    expect(selection()).toBe(true)

    pointer("pointerup", document.body)
    expect(selection()).toBe(false)

    // A cancel of the browser (a native drag) gives no pointerup.
    pointer("pointerdown", el("trigger"))
    expect(selection()).toBe(true)

    pointer("pointercancel", document.body)
    expect(selection()).toBe(false)

    pointer("pointerdown", el("trigger"))
    el("select").remove()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(selection()).toBe(false)
  })

  it("gives tabindex -1 to all the items after the close", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))
    click("#trigger")
    press("ArrowDown")
    expect(el("item-granny").getAttribute("tabindex")).toBe("0")

    press("Escape")

    expect(isOpen()).toBe(false)
    for (const item of el("list").querySelectorAll("[data-phem-select-target=item]")) expect(item.getAttribute("tabindex")).toBe("-1")
  })

  it("keeps the focus of a press outside on an element that takes the focus", async () => {
    await mount(select())
    document.body.insertAdjacentHTML("beforeend", '<input id="other" />')
    click("#trigger")

    pointer("pointerdown", el("other"))
    el("other").focus()
    await new Promise((resolve) => requestAnimationFrame(resolve))
    await new Promise((resolve) => requestAnimationFrame(resolve))

    expect(isOpen()).toBe(false)
    expect(document.activeElement).toBe(el("other"))
  })

  it("starts a new typed text after 750 ms", async () => {
    await mount(select())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

    try {
      el("trigger").focus()
      press("Enter")
      press("g")
      expect(document.activeElement).toBe(el("item-gala"))

      // "r" alone matches no item: the focus stays. With the old text, "gr" would go to Granny Smith.
      vi.advanceTimersByTime(800)
      press("r")

      expect(document.activeElement).toBe(el("item-gala"))
    } finally {
      vi.useRealTimers()
    }
  })

  it("keeps the item with the focus out of the scroll-padding of the list", async () => {
    await mount(select())
    const list = el("list")
    let scrollTop = 0
    Object.defineProperty(list, "scrollTop", { configurable: true, get: () => scrollTop, set: (value) => (scrollTop = value) })
    Object.defineProperty(list, "scrollHeight", { configurable: true, get: () => 80 })
    Object.defineProperty(list, "clientHeight", { configurable: true, get: () => 40 })
    list.style.scrollPaddingBottom = "15px"

    // The items are 20 px high, from the top of the list.
    const items = ["gala", "fuji", "honeycrisp", "granny"].map((value) => el(`item-${value}`))
    const rect = (top, height) => ({ top, bottom: top + height, left: 0, right: 100, width: 100, height, x: 0, y: top })
    vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function () {
      const index = items.indexOf(this)

      return index === -1 ? rect(0, this === list ? 40 : 0) : rect(index * 20 - scrollTop, 20)
    })

    el("trigger").focus()
    press("ArrowUp")

    // Granny Smith ends at 80 px: its bottom goes 15 px over the bottom of the list.
    expect(document.activeElement).toBe(el("item-granny"))
    expect(scrollTop).toBe(55)
  })

  it("scrolls up one item each 40 ms, and again after a move on the arrow at the edge", async () => {
    await mount(select())
    const list = el("list")
    let scrollTop = 0
    Object.defineProperty(list, "scrollTop", { configurable: true, get: () => scrollTop, set: (value) => (scrollTop = value) })
    Object.defineProperty(list, "scrollHeight", { configurable: true, get: () => 80 })
    Object.defineProperty(list, "clientHeight", { configurable: true, get: () => 40 })
    Object.defineProperty(el("up"), "offsetHeight", { configurable: true, get: () => 10 })

    const items = ["gala", "fuji", "honeycrisp", "granny"].map((value) => el(`item-${value}`))
    const rect = (top, height) => ({ top, bottom: top + height, left: 0, right: 100, width: 100, height, x: 0, y: top })
    vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function () {
      const index = items.indexOf(this)

      return index === -1 ? rect(0, this === list ? 40 : 0) : rect(index * 20 - scrollTop, 20)
    })

    click("#trigger")
    scrollTop = 40
    list.dispatchEvent(new Event("scroll"))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

    try {
      pointer("pointerover", el("up"))

      // Each step shows the item before the first visible item, under the arrow.
      vi.advanceTimersByTime(40)
      expect(scrollTop).toBe(30)
      vi.advanceTimersByTime(40)
      expect(scrollTop).toBe(10)
      vi.advanceTimersByTime(40)
      expect(scrollTop).toBe(0)
      vi.advanceTimersByTime(80)
      expect(scrollTop).toBe(0)

      // A scroll of the wheel, then a move on the arrow: the scroll starts again.
      scrollTop = 40
      pointer("pointermove", el("up"))
      vi.advanceTimersByTime(40)
      expect(scrollTop).toBe(30)
    } finally {
      vi.useRealTimers()
    }
  })

  it("takes a new value of a server render", async () => {
    await mount(select('data-phem-select-value-value="fuji"'))

    await morphOnSave(select('data-phem-select-value-value="gala"'))

    expect(el("value").textContent).toBe("Gala")
    expect(el("input").value).toBe("gala")
    expect(el("item-fuji").hasAttribute("data-selected")).toBe(false)
  })
})
