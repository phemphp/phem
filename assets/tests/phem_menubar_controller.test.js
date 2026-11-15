import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemMenuController from "../dist/phem_menu_controller.js"
import PhemMenubarController from "../dist/phem_menubar_controller.js"
import { mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-menu", PhemMenuController)
  application.register("phem-menubar", PhemMenubarController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(async () => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
  await new Promise((resolve) => setTimeout(resolve, 0))
})

// A menubar: "File" (with the "Export" submenu), "Edit" (with "Undo" that sends an event), a
// disabled "View", and "Help".
function bar(attributes = "", log = "") {
  return `<div id="bar" data-controller="phem-menubar" ${attributes}>
      <div id="file" data-controller="phem-menu">
        <button id="file-trigger" data-phem-menu-target="trigger">File</button>
        <div id="file-popup" data-phem-menu-target="popup" hidden>
          <button id="new" data-phem-menu-target="item">New</button>
          <div id="export" data-controller="phem-menu">
            <button id="export-trigger" data-phem-menu-target="trigger">Export</button>
            <div id="export-popup" data-phem-menu-target="popup" hidden>
              <button id="pdf" data-phem-menu-target="item">PDF</button>
            </div>
          </div>
        </div>
      </div>
      <div id="edit" data-controller="phem-menu">
        <button id="edit-trigger" data-phem-menu-target="trigger">Edit</button>
        <div id="edit-popup" data-phem-menu-target="popup" hidden>
          <button id="undo" data-phem-menu-target="item" data-phem-menu-close-on-click="false" data-phem-on-click="c1.undo">Undo</button>
          <button id="redo" data-phem-menu-target="item">Redo</button>
        </div>
      </div>
      <div id="view" data-controller="phem-menu">
        <button id="view-trigger" data-phem-menu-target="trigger" disabled>View</button>
        <div id="view-popup" data-phem-menu-target="popup" hidden></div>
      </div>
      <div id="help" data-controller="phem-menu">
        <button id="help-trigger" data-phem-menu-target="trigger">Help</button>
        <div id="help-popup" data-phem-menu-target="popup" hidden>
          <button id="about" data-phem-menu-target="item">About</button>
        </div>
      </div>
    </div>
    <output id="log">${log}</output>
    <button id="outside">Outside</button>`
}

const $ = (id) => document.getElementById(id)

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)

  return event
}

function hover(el) {
  el.dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
}

const tabStops = () => ["file-trigger", "edit-trigger", "view-trigger", "help-trigger"].filter((id) => $(id).tabIndex === 0)

describe("phem-menubar", () => {
  it("sets the roles, the orientation and one tab stop", async () => {
    await mount(bar())

    expect($("bar").getAttribute("role")).toBe("menubar")
    expect($("bar").getAttribute("aria-orientation")).toBe("horizontal")
    expect($("bar").getAttribute("data-orientation")).toBe("horizontal")
    expect($("bar").hasAttribute("data-modal")).toBe(true)
    expect($("file-trigger").getAttribute("role")).toBe("menuitem")
    expect($("file-trigger").getAttribute("aria-haspopup")).toBe("menu")
    expect($("view-trigger").hasAttribute("data-disabled")).toBe(true)
    expect(tabStops()).toEqual(["file-trigger"])
    // The trigger of a submenu is an item of its menu, not of the menubar.
    expect($("export-trigger").tabIndex).toBe(-1)
  })

  it("moves the focus between the triggers with the arrow keys, Home and End", async () => {
    await mount(bar())

    $("file-trigger").focus()
    press("ArrowRight")
    expect(document.activeElement).toBe($("edit-trigger"))
    expect(tabStops()).toEqual(["edit-trigger"])

    // The disabled trigger gets no focus.
    press("ArrowRight")
    expect(document.activeElement).toBe($("help-trigger"))

    // From the last trigger to the first one.
    press("ArrowRight")
    expect(document.activeElement).toBe($("file-trigger"))
    press("ArrowLeft")
    expect(document.activeElement).toBe($("help-trigger"))

    press("Home")
    expect(document.activeElement).toBe($("file-trigger"))
    press("End")
    expect(document.activeElement).toBe($("help-trigger"))
  })

  it("does not go from the last trigger to the first one with loopFocus false", async () => {
    await mount(bar('data-phem-menubar-loop-focus-value="false"'))

    $("help-trigger").focus()
    press("ArrowRight")
    expect(document.activeElement).toBe($("help-trigger"))
  })

  it("opens a menu with ArrowDown, and moves to the next open menu with ArrowRight", async () => {
    await mount(bar())

    $("file-trigger").focus()
    press("ArrowDown")
    expect($("file-popup").hidden).toBe(false)
    expect(document.activeElement).toBe($("new"))
    expect($("bar").hasAttribute("data-has-submenu-open")).toBe(true)
    expect($("file-trigger").hasAttribute("data-pressed")).toBe(true)

    press("ArrowRight")
    expect($("file-popup").hidden).toBe(true)
    expect($("edit-popup").hidden).toBe(false)
    expect(document.activeElement).toBe($("undo"))
    expect(tabStops()).toEqual(["edit-trigger"])

    press("ArrowLeft")
    expect($("edit-popup").hidden).toBe(true)
    expect($("file-popup").hidden).toBe(false)
    expect(document.activeElement).toBe($("new"))

    press("Escape")
    expect($("file-popup").hidden).toBe(true)
    expect(document.activeElement).toBe($("file-trigger"))
    expect($("bar").hasAttribute("data-has-submenu-open")).toBe(false)
  })

  it("opens a submenu with ArrowRight on its trigger, and closes it with ArrowLeft", async () => {
    await mount(bar())

    $("file-trigger").focus()
    press("ArrowDown")
    press("ArrowDown")
    expect(document.activeElement).toBe($("export-trigger"))

    press("ArrowRight")
    expect($("export-popup").hidden).toBe(false)
    expect(document.activeElement).toBe($("pdf"))
    expect($("file-popup").hidden).toBe(false)

    press("ArrowLeft")
    expect($("export-popup").hidden).toBe(true)
    expect(document.activeElement).toBe($("export-trigger"))

    // ArrowRight on an item of a submenu goes to the next menu of the bar.
    press("ArrowRight")
    press("ArrowRight")
    expect($("file-popup").hidden).toBe(true)
    expect($("edit-popup").hidden).toBe(false)
  })

  it("opens another menu at once on hover while a menu is open, and keeps it open on its click", async () => {
    await mount(bar())

    hover($("edit-trigger"))
    expect($("edit-popup").hidden).toBe(true)

    $("file-trigger").click()
    expect($("file-popup").hidden).toBe(false)

    hover($("edit-trigger"))
    expect($("file-popup").hidden).toBe(true)
    expect($("edit-popup").hidden).toBe(false)

    $("edit-trigger").click()
    expect($("edit-popup").hidden).toBe(false)

    $("edit-trigger").click()
    expect($("edit-popup").hidden).toBe(true)
  })

  it("keeps one menu open: a click on another trigger closes the first menu", async () => {
    await mount(bar())

    $("file-trigger").click()
    $("help").querySelector("button").click()
    expect($("file-popup").hidden).toBe(true)
    expect($("help-popup").hidden).toBe(false)
  })

  it("locks the page while a menu is open, and keeps the pointer events of the menubar", async () => {
    await mount(bar())

    $("file-trigger").click()
    expect(document.body.style.pointerEvents).toBe("none")
    expect($("bar").style.pointerEvents).toBe("auto")

    $("outside").click()
    expect($("file-popup").hidden).toBe(true)
    expect(document.body.style.pointerEvents).toBe("")
    expect($("bar").hasAttribute("style")).toBe(false)
  })

  it("does not lock the page with modal false", async () => {
    await mount(bar('data-phem-menubar-modal-value="false"'))

    $("file-trigger").click()
    expect(document.body.style.pointerEvents).toBe("")
    expect($("bar").hasAttribute("data-modal")).toBe(false)
  })

  it("opens no menu when it is disabled", async () => {
    await mount(bar('data-phem-menubar-disabled-value="true"'))

    expect($("bar").getAttribute("aria-disabled")).toBe("true")
    expect($("bar").hasAttribute("data-disabled")).toBe(true)
    expect($("file-trigger").hasAttribute("data-disabled")).toBe(true)

    $("file-trigger").click()
    $("file-trigger").focus()
    press("ArrowDown")
    press("Enter")
    expect($("file-popup").hidden).toBe(true)
  })

  it("moves with ArrowDown and opens with ArrowRight in a vertical menubar", async () => {
    await mount(bar('data-phem-menubar-orientation-value="vertical"'))

    expect($("bar").getAttribute("aria-orientation")).toBe("vertical")

    $("file-trigger").focus()
    press("ArrowDown")
    expect(document.activeElement).toBe($("edit-trigger"))
    expect($("file-popup").hidden).toBe(true)

    press("ArrowRight")
    expect($("edit-popup").hidden).toBe(false)
    expect(document.activeElement).toBe($("undo"))
    expect($("edit-popup").getAttribute("data-side")).toBe("inline-end")

    press("ArrowLeft")
    expect($("edit-popup").hidden).toBe(true)
    expect(document.activeElement).toBe($("edit-trigger"))
  })

  it("keeps the open menu and the tab stop after the morph of an event", async () => {
    await mount(bar())

    $("edit-trigger").click()
    expect($("edit-popup").hidden).toBe(false)

    respond(bar("", "undo"))
    const done = nextDone()
    $("undo").click()
    await done
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect($("log").textContent).toBe("undo")
    expect($("edit-popup").hidden).toBe(false)
    expect($("bar").hasAttribute("data-has-submenu-open")).toBe(true)
    expect($("bar").getAttribute("role")).toBe("menubar")
    expect(tabStops()).toEqual(["edit-trigger"])
    expect($("bar").style.pointerEvents).toBe("auto")
  })
})
