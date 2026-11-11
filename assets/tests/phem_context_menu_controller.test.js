import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemContextMenuController from "../dist/phem_context_menu_controller.js"
import PhemMenuController from "../dist/phem_menu_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-menu", PhemMenuController)
  application.register("phem-context-menu", PhemContextMenuController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  // jsdom has a viewport of 0 px: the popup would shift to the edge.
  vi.spyOn(document.documentElement, "clientWidth", "get").mockReturnValue(1000)
  vi.spyOn(document.documentElement, "clientHeight", "get").mockReturnValue(800)
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// An area with a context menu: "Copy" sends an event, and "Share" is a submenu.
function area() {
  return `<div id="menu" data-controller="phem-context-menu">
      <div id="area" data-phem-context-menu-target="trigger"><button id="inner">Inner</button></div>
      <div id="popup" data-phem-context-menu-target="popup" hidden>
        <button id="copy" data-phem-context-menu-target="item" data-phem-on-click="c1.copy">Copy</button>
        <div data-controller="phem-menu">
          <button id="share" data-phem-menu-target="trigger">Share</button>
          <div id="share-popup" data-phem-menu-target="popup" hidden>
            <button id="email" data-phem-menu-target="item">Email</button>
          </div>
        </div>
        <button id="delete" data-phem-context-menu-target="item">Delete</button>
      </div>
    </div>
    <button id="outside">Outside</button>`
}

const $ = (id) => document.getElementById(id)

const isOpen = () => !$("popup").hidden

function contextMenu(el, x = 120, y = 80) {
  const event = new MouseEvent("contextmenu", { bubbles: true, cancelable: true, clientX: x, clientY: y })
  el.dispatchEvent(event)

  return event
}

function press(key) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
  document.activeElement.dispatchEvent(event)

  return event
}

describe("phem-context-menu", () => {
  it("opens at the point of a right click, in place of the menu of the browser", async () => {
    await mount(area())
    const event = contextMenu($("area"))

    expect(event.defaultPrevented).toBe(true)
    expect(isOpen()).toBe(true)
    expect($("popup").getAttribute("role")).toBe("menu")
    expect($("popup").style.top).toBe("80px")
    expect($("popup").style.left).toBe("120px")
    expect($("popup").getAttribute("data-side")).toBe("bottom")
    expect($("area").hasAttribute("data-popup-open")).toBe(true)
    expect($("area").hasAttribute("aria-expanded")).toBe(false)
    expect(document.activeElement.id).toBe("popup")
  })

  it("moves the open menu to the point of a new right click", async () => {
    await mount(area())
    contextMenu($("area"))
    contextMenu($("inner"), 200, 150)

    expect(isOpen()).toBe(true)
    expect($("popup").style.top).toBe("150px")
    expect($("popup").style.left).toBe("200px")
  })

  it("does not open on a click on the trigger", async () => {
    await mount(area())
    click("#area")

    expect(isOpen()).toBe(false)
  })

  it("moves the focus with the keys of a menu, and runs the handler of an item", async () => {
    await mount(area())
    respond(area())
    contextMenu($("area"))
    press("ArrowDown")
    expect(document.activeElement.id).toBe("copy")
    press("ArrowDown")
    expect(document.activeElement.id).toBe("share")

    press("ArrowRight")
    expect($("share-popup").hidden).toBe(false)
    expect(document.activeElement.id).toBe("email")
    press("ArrowLeft")

    $("copy").focus()
    const done = nextDone()
    click("#copy")
    expect(isOpen()).toBe(false)
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("gives the focus back to the element that had it on Escape", async () => {
    await mount(area())
    $("inner").focus()
    contextMenu($("inner"))
    press("Escape")

    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("inner")
  })

  it("closes on a click outside", async () => {
    await mount(area())
    contextMenu($("area"))
    click("#outside")

    expect(isOpen()).toBe(false)
  })

  it("opens on a long press of a touch, and not on a touch that moves", async () => {
    await mount(area())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    const touch = (type, x, y) => new PointerEvent(type, { bubbles: true, isPrimary: true, pointerId: 1, pointerType: "touch", clientX: x, clientY: y })

    $("area").dispatchEvent(touch("pointerdown", 50, 60))
    document.dispatchEvent(touch("pointermove", 50, 90))
    vi.advanceTimersByTime(500)
    expect(isOpen()).toBe(false)

    $("area").dispatchEvent(touch("pointerdown", 50, 60))
    vi.advanceTimersByTime(499)
    expect(isOpen()).toBe(false)
    vi.advanceTimersByTime(1)
    expect(isOpen()).toBe(true)
    expect($("popup").style.top).toBe("60px")
  })

  it("opens below the trigger with the open action", async () => {
    await mount(area())
    $("area").getBoundingClientRect = () => ({ x: 40, y: 10, left: 40, top: 10, right: 240, bottom: 110, width: 200, height: 100 })
    application.getControllerForElementAndIdentifier($("menu"), "phem-context-menu").open()

    expect($("popup").style.top).toBe("110px")
    expect($("popup").style.left).toBe("40px")
  })

  it("follows the trigger that the morph gives for its direction", async () => {
    await mount(area())
    contextMenu($("area"))
    const controller = application.getControllerForElementAndIdentifier($("menu"), "phem-context-menu")
    const area2 = $("area").cloneNode(true)
    $("area").replaceWith(area2)
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(controller.anchor.contextElement).toBe(area2)
  })
})
