import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemMenuController from "../dist/phem_menu_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-menu", PhemMenuController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

// The controllers disconnect, so a modal menu unlocks the page also after a failed test.
afterEach(async () => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
  await new Promise((resolve) => setTimeout(resolve, 0))
})

// A menu: "Rename" sends an event, "Copy" is disabled, "Archive" and "Pin" (a checkbox item that
// stays open) are in a group, and "Delete" closes the menu.
function actions(attributes = "", pinned = false) {
  return `<div id="menu" data-controller="phem-menu" ${attributes}>
      <button id="trigger" data-phem-menu-target="trigger">Actions</button>
      <div id="popup" data-phem-menu-target="popup" hidden>
        <button id="rename" data-phem-menu-target="item" data-phem-on-click="c1.rename">Rename</button>
        <button id="copy" data-phem-menu-target="item" disabled>Copy</button>
        <div id="separator" data-phem-menu-target="separator"></div>
        <div id="group" data-phem-menu-target="group">
          <span id="label" data-phem-menu-target="groupLabel">More</span>
          <div id="archive" data-phem-menu-target="item">Archive</div>
          <button id="pin" data-phem-menu-target="item" role="menuitemcheckbox" aria-checked="${pinned}" data-phem-menu-close-on-click="false">Pin</button>
        </div>
        <button id="delete" data-phem-menu-target="item">Delete</button>
      </div>
    </div>
    <button id="outside">Outside</button>`
}

const $ = (id) => document.getElementById(id)

function isOpen() {
  const open = !$("popup").hidden
  if ($("trigger").getAttribute("aria-expanded") !== String(open) || $("trigger").hasAttribute("data-popup-open") !== open) throw new Error("The trigger and the popup do not agree")

  return open
}

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)

  return event
}

describe("phem-menu", () => {
  it("sets the roles and the ARIA attributes", async () => {
    await mount(actions())

    expect($("trigger").getAttribute("aria-haspopup")).toBe("menu")
    expect($("trigger").getAttribute("aria-controls")).toBe("popup")
    expect($("popup").getAttribute("role")).toBe("menu")
    expect($("rename").getAttribute("role")).toBe("menuitem")
    expect($("rename").tabIndex).toBe(-1)
    expect($("copy").hasAttribute("data-disabled")).toBe(true)
    expect($("pin").getAttribute("role")).toBe("menuitemcheckbox")
    expect($("pin").hasAttribute("data-checked")).toBe(false)
    expect($("group").getAttribute("role")).toBe("group")
    expect($("group").getAttribute("aria-labelledby")).toBe("label")
    expect($("separator").getAttribute("role")).toBe("separator")
  })

  it("opens with a click, and gives the focus to the popup", async () => {
    await mount(actions())
    $("trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 1 }))

    expect(isOpen()).toBe(true)
    expect(document.activeElement.id).toBe("popup")
    expect($("popup").getAttribute("data-side")).toBe("bottom")
    expect($("popup").getAttribute("data-align")).toBe("start")
  })

  it("opens with a click of the keyboard on the first item, and with ArrowUp on the last one", async () => {
    await mount(actions())
    click("#trigger")
    expect(document.activeElement.id).toBe("rename")
    expect($("rename").hasAttribute("data-highlighted")).toBe(true)

    press("Escape")
    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("trigger")

    press("ArrowUp")
    expect(isOpen()).toBe(true)
    expect(document.activeElement.id).toBe("delete")
  })

  it("moves the focus with the arrow keys, Home, End and a letter, and skips a disabled item", async () => {
    await mount(actions())
    $("trigger").focus()
    press("ArrowDown")

    press("ArrowDown")
    expect(document.activeElement.id).toBe("archive")
    expect($("rename").hasAttribute("data-highlighted")).toBe(false)
    expect($("archive").hasAttribute("data-highlighted")).toBe(true)
    press("End")
    press("ArrowDown")
    expect(document.activeElement.id).toBe("rename")
    press("ArrowUp")
    expect(document.activeElement.id).toBe("delete")
    press("Home")
    press("p")
    expect(document.activeElement.id).toBe("pin")
  })

  it("does not go from the last item to the first one with loopFocus false", async () => {
    await mount(actions('data-phem-menu-loop-focus-value="false"'))
    $("trigger").focus()
    press("ArrowUp")
    press("ArrowDown")

    expect(document.activeElement.id).toBe("delete")
  })

  it("runs the handler of an item and closes the menu", async () => {
    await mount(actions())
    respond(actions())
    click("#trigger")
    const done = nextDone()
    click("#rename")
    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("trigger")
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("clicks an item that is not a button with Enter", async () => {
    await mount(actions())
    const clicked = vi.fn()
    $("archive").addEventListener("click", clicked)
    click("#trigger")
    $("archive").focus()
    press("Enter")

    expect(clicked).toHaveBeenCalledTimes(1)
    expect(isOpen()).toBe(false)
  })

  it("stays open after a click on an item with data-phem-menu-close-on-click false", async () => {
    await mount(actions())
    click("#trigger")
    click("#pin")

    expect(isOpen()).toBe(true)
  })

  it("closes on Tab, on a click outside and on the focus out of the menu", async () => {
    await mount(actions())
    click("#trigger")
    press("Tab")
    expect(isOpen()).toBe(false)

    click("#trigger")
    click("#outside")
    expect(isOpen()).toBe(false)

    click("#trigger")
    $("outside").focus()
    expect(isOpen()).toBe(false)
  })

  it("stays open with the focus on its item after the morph of an event", async () => {
    await mount(actions())
    respond(actions("", true))
    click("#trigger")
    $("pin").focus()
    const done = nextDone()
    $("rename").dispatchEvent(new Event("phem-test"))
    $("pin").setAttribute("data-phem-on-click", "c1.pin")
    click("#pin")
    await done

    expect(isOpen()).toBe(true)
    expect($("pin").hasAttribute("data-checked")).toBe(true)
    expect($("pin").hasAttribute("data-highlighted")).toBe(true)
    expect($("menu").getAttribute("data-phem-menu-open-value")).toBe("true")
  })

  it("highlights an item under the pointer", async () => {
    await mount(actions())
    click("#trigger")
    $("delete").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))

    expect(document.activeElement.id).toBe("delete")
    expect($("delete").hasAttribute("data-highlighted")).toBe(true)
  })

  describe("a submenu", () => {
    // A menu with a "Move to" submenu between "Rename" and "Delete".
    function nested(attributes = "") {
      return `<div id="menu" data-controller="phem-menu">
          <button id="trigger" data-phem-menu-target="trigger">Actions</button>
          <div id="popup" data-phem-menu-target="popup" hidden>
            <button id="rename" data-phem-menu-target="item">Rename</button>
            <div id="move" data-controller="phem-menu" ${attributes}>
              <button id="move-trigger" data-phem-menu-target="trigger">Move to</button>
              <div id="move-popup" data-phem-menu-target="popup" hidden>
                <button id="inbox" data-phem-menu-target="item">Inbox</button>
                <button id="archive" data-phem-menu-target="item">Archive</button>
              </div>
            </div>
            <button id="delete" data-phem-menu-target="item">Delete</button>
          </div>
        </div>
        <button id="outside">Outside</button>`
    }

    const subOpen = () => !$("move-popup").hidden

    it("makes its trigger an item of the menu around it", async () => {
      await mount(nested())

      expect($("move-trigger").getAttribute("role")).toBe("menuitem")
      expect($("move-trigger").getAttribute("aria-haspopup")).toBe("menu")
      expect($("move-trigger").getAttribute("aria-expanded")).toBe("false")
      expect($("move-trigger").tabIndex).toBe(-1)

      $("trigger").focus()
      press("ArrowDown")
      press("ArrowDown")
      expect(document.activeElement.id).toBe("move-trigger")
      expect($("move-trigger").hasAttribute("data-highlighted")).toBe(true)
      press("ArrowDown")
      expect(document.activeElement.id).toBe("delete")
      expect(subOpen()).toBe(false)
    })

    it("opens with ArrowRight on its first item, and closes with ArrowLeft", async () => {
      await mount(nested())
      $("trigger").focus()
      press("ArrowDown")
      press("ArrowDown")
      press("ArrowRight")

      expect(subOpen()).toBe(true)
      expect(document.activeElement.id).toBe("inbox")
      expect($("move-trigger").getAttribute("aria-expanded")).toBe("true")
      expect($("move-popup").getAttribute("data-side")).toBe("inline-end")

      press("ArrowDown")
      expect(document.activeElement.id).toBe("archive")

      press("ArrowLeft")
      expect(subOpen()).toBe(false)
      expect(isOpen()).toBe(true)
      expect(document.activeElement.id).toBe("move-trigger")
    })

    it("opens with Enter, and closes only itself on Escape", async () => {
      await mount(nested())
      click("#trigger")
      $("move-trigger").focus()
      press("Enter")
      expect(subOpen()).toBe(true)
      expect(document.activeElement.id).toBe("inbox")

      press("Escape")
      expect(subOpen()).toBe(false)
      expect(isOpen()).toBe(true)
      expect(document.activeElement.id).toBe("move-trigger")
    })

    it("opens on hover, and keeps the focus on its trigger", async () => {
      await mount(nested())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      $("move-trigger").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      expect(document.activeElement.id).toBe("move-trigger")
      expect(subOpen()).toBe(false)

      vi.advanceTimersByTime(100)
      expect(subOpen()).toBe(true)
      expect(document.activeElement.id).toBe("move-trigger")

      // The pointer on another item of the menu closes it after a grace time.
      $("delete").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      expect(subOpen()).toBe(true)
      vi.advanceTimersByTime(150)
      expect(document.activeElement.id).toBe("delete")
      expect(subOpen()).toBe(false)
    })

    it("stays open when the pointer crosses an item on its way to the submenu", async () => {
      await mount(nested())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      $("move-trigger").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      vi.advanceTimersByTime(100)

      $("delete").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      $("inbox").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      vi.advanceTimersByTime(1000)

      expect(subOpen()).toBe(true)
      expect(document.activeElement.id).toBe("inbox")
    })

    it("does not open with an aria-disabled trigger", async () => {
      await mount(nested().replace('id="move-trigger"', 'id="move-trigger" aria-disabled="true"'))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      $("move-trigger").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      vi.advanceTimersByTime(1000)
      $("move-trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 1 }))

      expect($("move-trigger").hasAttribute("data-disabled")).toBe(true)
      expect(subOpen()).toBe(false)
    })

    it("does not open on hover with open-on-hover false", async () => {
      await mount(nested('data-phem-menu-open-on-hover-value="false"'))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      $("move-trigger").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      vi.advanceTimersByTime(1000)

      expect(subOpen()).toBe(false)
    })

    it("stays open after a click on its trigger, and closes all the menus after a click on its item", async () => {
      await mount(nested())
      click("#trigger")
      $("move-trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 1 }))
      $("move-trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 1 }))
      expect(subOpen()).toBe(true)
      expect(isOpen()).toBe(true)

      $("inbox").focus()
      click("#archive")
      expect(subOpen()).toBe(false)
      expect(isOpen()).toBe(false)
      expect(document.activeElement.id).toBe("trigger")
    })

    it("closes with the menu around it", async () => {
      await mount(nested())
      click("#trigger")
      $("move-trigger").focus()
      press("Enter")
      click("#outside")

      expect(subOpen()).toBe(false)
      expect(isOpen()).toBe(false)
    })
  })

  describe("a modal menu", () => {
    it("locks the scroll and the pointer events of the page while it is open", async () => {
      await mount(actions('data-phem-menu-modal-value="true"'))
      click("#trigger")

      expect(document.documentElement.style.overflow).toBe("hidden")
      expect(document.body.style.pointerEvents).toBe("none")
      expect($("popup").style.pointerEvents).toBe("auto")

      press("Escape")
      expect(document.documentElement.hasAttribute("style")).toBe(false)
      expect(document.body.hasAttribute("style")).toBe(false)
      expect($("popup").style.pointerEvents).toBe("")
    })

    it("does not lock the page by default", async () => {
      await mount(actions())
      click("#trigger")

      expect(document.body.style.pointerEvents).toBe("")
    })

    it("unlocks the page when it disconnects", async () => {
      await mount(actions('data-phem-menu-modal-value="true"'))
      click("#trigger")
      $("menu").remove()
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect(document.body.hasAttribute("style")).toBe(false)
    })
  })
})
