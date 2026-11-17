import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemMenuController from "../dist/phem_menu_controller.js"
import { click, hover, mount, nextDone, respond, stubElementReflection } from "./support.js"

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
    expect($("trigger").hasAttribute("aria-controls")).toBe(false)
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

  it("gives no id to a popup and a group label with no id, and links them with element references, also after the morph", async () => {
    const restore = stubElementReflection()
    try {
      const markup = actions().replace('id="popup" ', "").replace('id="label" ', "")
      await mount(markup)
      respond(markup)
      const popup = () => document.querySelector("[data-phem-menu-target=popup]")
      const label = () => document.querySelector("[data-phem-menu-target=groupLabel]")

      expect(popup().id).toBe("")
      expect(label().id).toBe("")
      expect($("group").ariaLabelledByElements).toEqual([label()])

      // The trigger points to the popup while the menu is open.
      const done = nextDone()
      click("#trigger")
      expect($("trigger").ariaControlsElements).toEqual([popup()])
      click("#rename")
      await done

      expect(popup().id).toBe("")
      expect($("trigger").ariaControlsElements).toBe(null)
      expect($("group").ariaLabelledByElements).toEqual([label()])

      click("#trigger")
      expect($("trigger").ariaControlsElements).toEqual([popup()])
    } finally {
      restore()
    }
  })

  it("keeps the aria-labelledby of the server render on a group", async () => {
    await mount(actions().replace('data-phem-menu-target="group"', 'data-phem-menu-target="group" aria-labelledby="trigger"'))

    expect($("group").getAttribute("aria-labelledby")).toBe("trigger")
  })

  it("opens with a click, and gives the focus to the popup", async () => {
    await mount(actions())
    $("trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 1 }))

    expect(isOpen()).toBe(true)
    expect(document.activeElement.id).toBe("popup")
    expect($("popup").getAttribute("data-side")).toBe("bottom")
    expect($("popup").getAttribute("data-align")).toBe("start")
  })

  it("opens with a click of the keyboard on the first item", async () => {
    await mount(actions())
    click("#trigger")
    expect(document.activeElement.id).toBe("rename")
    expect($("rename").hasAttribute("data-highlighted")).toBe(true)

    press("Escape")
    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("trigger")
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
    press("ArrowDown")
    press("End")
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
    expect($("pin").tabIndex).toBe(0)
    expect($("rename").tabIndex).toBe(-1)
    expect($("menu").getAttribute("data-phem-menu-open-value")).toBe("true")
  })

  it("highlights an item under the pointer", async () => {
    await mount(actions())
    click("#trigger")
    hover($("delete"))

    expect(document.activeElement.id).toBe("delete")
    expect($("delete").hasAttribute("data-highlighted")).toBe(true)
  })

  it("does not highlight an item that comes under a pointer that does not move", async () => {
    await mount(actions())
    click("#trigger")
    const focused = document.activeElement
    $("delete").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))

    expect(document.activeElement).toBe(focused)
    expect($("delete").hasAttribute("data-highlighted")).toBe(false)
  })

  describe("a checkbox item and a radio item", () => {
    // A menu with a "Wrap" checkbox item, two groups of radio items, two radio items in no group,
    // a "Pin" checkbox item with a handler, and a "Save" item that sends an event. `wrap`, `size`
    // and `pinned` are the state of the server render. "Dark" closes the menu.
    function view({ wrap = false, size = "m", pinned = false } = {}) {
      return `<div id="menu" data-controller="phem-menu">
          <button id="trigger" data-phem-menu-target="trigger">View</button>
          <div id="popup" data-phem-menu-target="popup" hidden>
            <button id="wrap" data-phem-menu-target="item" role="menuitemcheckbox" aria-checked="${wrap}" data-phem-menu-close-on-click="false">Wrap</button>
            <div data-phem-menu-target="group">
              <button id="small" data-phem-menu-target="item" role="menuitemradio" data-value="s" aria-checked="${size === "s"}" data-phem-menu-close-on-click="false">Small</button>
              <button id="medium" data-phem-menu-target="item" role="menuitemradio" data-value="m" aria-checked="${size === "m"}" data-phem-menu-close-on-click="false">Medium</button>
              <button id="large" data-phem-menu-target="item" role="menuitemradio" data-value="l" aria-checked="${size === "l"}" data-phem-menu-close-on-click="false">Large</button>
            </div>
            <div data-phem-menu-target="group">
              <button id="light" data-phem-menu-target="item" role="menuitemradio" aria-checked="true" data-phem-menu-close-on-click="false">Light</button>
              <button id="dark" data-phem-menu-target="item" role="menuitemradio" aria-checked="false">Dark</button>
            </div>
            <button id="left" data-phem-menu-target="item" role="menuitemradio" aria-checked="true" data-phem-menu-close-on-click="false">Left</button>
            <button id="right" data-phem-menu-target="item" role="menuitemradio" aria-checked="false" data-phem-menu-close-on-click="false">Right</button>
            <button id="pin" data-phem-menu-target="item" role="menuitemcheckbox" aria-checked="${pinned}" data-phem-menu-close-on-click="false" data-phem-on-click="c1.pin">Pin</button>
            <button id="save" data-phem-menu-target="item" data-phem-menu-close-on-click="false" data-phem-on-click="c1.save">Save</button>
          </div>
        </div>`
    }

    const checked = (id) => {
      const state = $(id).getAttribute("aria-checked") === "true"
      if ($(id).hasAttribute("data-checked") !== state || $(id).hasAttribute("data-unchecked") === state) throw new Error(`The attributes of #${id} do not agree`)

      return state
    }

    it("checks and unchecks a checkbox item in the browser, and keeps the menu open", async () => {
      await mount(view())
      const changes = []
      $("menu").addEventListener("phem-menu:change", (event) => changes.push([event.target.id, event.detail]))
      expect(checked("wrap")).toBe(false)

      click("#trigger")
      click("#wrap")
      expect(checked("wrap")).toBe(true)
      expect(isOpen()).toBe(true)

      click("#wrap")
      expect(checked("wrap")).toBe(false)
      expect(changes).toEqual([["wrap", { checked: true, value: null }], ["wrap", { checked: false, value: null }]])
      expect(fetch).not.toHaveBeenCalled()
    })

    it("checks a radio item, and unchecks the other radio items of its group only", async () => {
      await mount(view())
      const changes = []
      $("menu").addEventListener("phem-menu:change", (event) => changes.push([event.target.id, event.detail]))
      click("#trigger")
      click("#small")

      expect(checked("small")).toBe(true)
      expect(checked("medium")).toBe(false)
      expect(checked("light")).toBe(true)
      expect(isOpen()).toBe(true)

      // A click on the checked radio item changes nothing.
      click("#small")
      expect(checked("small")).toBe(true)
      expect(changes).toEqual([["small", { checked: true, value: "s" }]])
    })

    it("closes the menu after a click on an item, unless it has data-phem-menu-close-on-click false", async () => {
      await mount(view())
      click("#trigger")
      click("#dark")

      expect(checked("dark")).toBe(true)
      expect(checked("light")).toBe(false)
      expect(isOpen()).toBe(false)
    })

    it("unchecks only the radio items in no group after a click on a radio item in no group", async () => {
      await mount(view())
      click("#trigger")
      click("#right")

      expect(checked("right")).toBe(true)
      expect(checked("left")).toBe(false)
      expect(checked("medium")).toBe(true)
      expect(checked("light")).toBe(true)
    })

    it("leaves the state of an item with a handler to the server", async () => {
      await mount(view())
      const changed = vi.fn()
      $("menu").addEventListener("phem-menu:change", changed)
      click("#trigger")

      // The handler refuses the change: the server render has the same state.
      respond(view())
      const refused = nextDone()
      click("#pin")
      expect(checked("pin")).toBe(false)
      await refused
      expect(checked("pin")).toBe(false)

      respond(view({ pinned: true }))
      const done = nextDone()
      click("#pin")
      await done

      expect(checked("pin")).toBe(true)
      expect(changed).not.toHaveBeenCalled()
    })

    it("unchecks the radio item of the browser when the server render checks another one", async () => {
      await mount(view({ size: "m" }))
      click("#trigger")
      click("#large")
      expect(checked("large")).toBe(true)

      // The server render checks "Small": a new state of "Small" and "Medium", not of "Large".
      respond(view({ size: "s" }))
      const done = nextDone()
      click("#save")
      await done
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect(checked("small")).toBe(true)
      expect(checked("medium")).toBe(false)
      expect(checked("large")).toBe(false)
    })

    it("gives the page of the Turbo cache the state of the server render", async () => {
      await mount(view())
      click("#trigger")
      click("#wrap")
      click("#small")
      document.dispatchEvent(new Event("turbo:before-cache"))

      expect(checked("wrap")).toBe(false)
      expect(checked("small")).toBe(false)
      expect(checked("medium")).toBe(true)
    })

    it("does not check a disabled item", async () => {
      await mount(view())
      $("wrap").setAttribute("aria-disabled", "true")
      click("#trigger")
      click("#wrap")

      expect(checked("wrap")).toBe(false)
    })

    it("keeps the state of the browser after the morph of an event", async () => {
      await mount(view())
      respond(view())
      click("#trigger")
      click("#wrap")
      click("#small")
      const done = nextDone()
      click("#save")
      await done

      expect(checked("wrap")).toBe(true)
      expect(checked("small")).toBe(true)
      expect(checked("medium")).toBe(false)
    })

    it("takes a new state of the server render", async () => {
      await mount(view())
      respond(view({ wrap: true, size: "s" }))
      click("#trigger")
      const done = nextDone()
      click("#save")
      await done

      expect(checked("wrap")).toBe(true)
      expect(checked("small")).toBe(true)
      expect(checked("medium")).toBe(false)

      // The server render does not change: the state of the browser stays.
      respond(view({ wrap: true, size: "s" }))
      click("#wrap")
      const again = nextDone()
      click("#save")
      await again

      expect(checked("wrap")).toBe(false)
    })
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
      hover($("move-trigger"))
      expect(document.activeElement.id).toBe("move-trigger")
      expect(subOpen()).toBe(false)

      vi.advanceTimersByTime(100)
      expect(subOpen()).toBe(true)
      expect(document.activeElement.id).toBe("move-trigger")

      // The pointer on another item of the menu closes it after a grace time.
      hover($("delete"))
      expect(subOpen()).toBe(true)
      vi.advanceTimersByTime(150)
      expect(document.activeElement.id).toBe("delete")
      expect(subOpen()).toBe(false)
    })

    it("stays open when the pointer crosses an item on its way to the submenu", async () => {
      await mount(nested())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      hover($("move-trigger"))
      vi.advanceTimersByTime(100)

      hover($("delete"))
      hover($("inbox"))
      vi.advanceTimersByTime(1000)

      expect(subOpen()).toBe(true)
      expect(document.activeElement.id).toBe("inbox")
    })

    it("keeps the focus on its trigger when the pointer crosses an item and comes back", async () => {
      await mount(nested())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      hover($("move-trigger"))
      vi.advanceTimersByTime(100)

      hover($("delete"))
      hover($("move-trigger"))
      vi.advanceTimersByTime(1000)

      expect(subOpen()).toBe(true)
      expect(document.activeElement.id).toBe("move-trigger")
    })

    it("does not open with an aria-disabled trigger", async () => {
      await mount(nested().replace('id="move-trigger"', 'id="move-trigger" aria-disabled="true"'))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      hover($("move-trigger"))
      vi.advanceTimersByTime(1000)
      $("move-trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, detail: 1 }))

      expect($("move-trigger").hasAttribute("data-disabled")).toBe(true)
      expect(subOpen()).toBe(false)
    })

    it("does not open on hover with open-on-hover false", async () => {
      await mount(nested('data-phem-menu-open-on-hover-value="false"'))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      click("#trigger")
      hover($("move-trigger"))
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

describe("phem-menu: the behaviors of Base UI", () => {
  const mouseClick = (el) => el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1 }))
  const out = (el, to) => el.dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: to }))

  it("does not lock the page when the pointer opens a modal menu", async () => {
    await mount(actions('data-phem-menu-modal-value="true" data-phem-menu-open-on-hover-value="true"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    hover($("trigger"))
    vi.advanceTimersByTime(100)

    expect(isOpen()).toBe(true)
    expect(document.body.style.pointerEvents).toBe("")
    expect(document.documentElement.style.overflow).toBe("")

    // A click opens it again: the page is locked.
    press("Escape")
    mouseClick($("trigger"))
    expect(isOpen()).toBe(true)
    expect(document.body.style.pointerEvents).toBe("none")
  })

  it("gives the focus to an item with aria-disabled, and does not run its handler", async () => {
    await mount(actions().replace("disabled>Copy", 'aria-disabled="true" data-phem-on-click="c1.copy">Copy'))
    click("#trigger")
    expect(document.activeElement.id).toBe("rename")

    press("ArrowDown")
    expect(document.activeElement.id).toBe("copy")
    expect($("copy").hasAttribute("data-highlighted")).toBe(true)
    expect($("copy").hasAttribute("data-disabled")).toBe(true)

    $("copy").click()
    expect(fetch).not.toHaveBeenCalled()
    expect(isOpen()).toBe(true)

    // The letter of the item finds it.
    $("rename").focus()
    press("c")
    expect(document.activeElement.id).toBe("copy")
  })

  it("skips an item with aria-disabled for the first focus of the menu", async () => {
    await mount(actions().replace('data-phem-on-click="c1.rename">Rename', 'aria-disabled="true">Rename'))
    click("#trigger")

    expect(document.activeElement.id).toBe("archive")
  })

  it("removes the highlight when the pointer goes out of an item, and not to another item", async () => {
    await mount(actions())
    mouseClick($("trigger"))
    hover($("rename"))
    expect(document.activeElement.id).toBe("rename")

    // To another item: the highlight stays until that item takes it.
    out($("rename"), $("delete"))
    expect(document.activeElement.id).toBe("rename")

    out($("rename"), $("separator"))
    expect(document.activeElement).toBe($("popup"))
    expect($("rename").hasAttribute("data-highlighted")).toBe(false)
    expect(isOpen()).toBe(true)
  })

  it("keeps the highlight of the keyboard when the pointer goes out", async () => {
    await mount(actions())
    click("#trigger")
    hover($("rename"))
    press("End")
    expect(document.activeElement.id).toBe("delete")

    out($("delete"), $("outside"))
    expect(document.activeElement.id).toBe("delete")
    expect($("delete").hasAttribute("data-highlighted")).toBe(true)
  })

  it("sets data-instant for a change with the keyboard, and removes it for the pointer", async () => {
    await mount(actions())

    // Enter or Space on the trigger is a click with no pointer.
    click("#trigger")
    expect($("popup").getAttribute("data-instant")).toBe("click")

    press("Escape")
    expect($("popup").getAttribute("data-instant")).toBe("dismiss")

    mouseClick($("trigger"))
    expect($("popup").hasAttribute("data-instant")).toBe(false)

    // Enter on an item closes the menu with `click`.
    $("delete").focus()
    $("delete").click()
    expect(isOpen()).toBe(false)
    expect($("popup").getAttribute("data-instant")).toBe("click")

    mouseClick($("trigger"))
    mouseClick($("delete"))
    expect($("popup").hasAttribute("data-instant")).toBe(false)
  })
})

describe("phem-menu: a submenu and the pointer", () => {
  const page = (attributes = "") => `<div id="menu" data-controller="phem-menu" ${attributes}>
      <button id="trigger" data-phem-menu-target="trigger">Actions</button>
      <div id="popup" data-phem-menu-target="popup" hidden>
        <button id="rename" data-phem-menu-target="item">Rename</button>
        <div id="move" data-controller="phem-menu">
          <button id="move-trigger" data-phem-menu-target="trigger">Move to</button>
          <div id="move-popup" data-phem-menu-target="popup" hidden>
            <button id="inbox" data-phem-menu-target="item">Inbox</button>
          </div>
        </div>
      </div>
    </div>`

  it("keeps a submenu open when the pointer goes out of its trigger to the popup of the menu", async () => {
    await mount(page())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    $("trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1 }))
    hover($("move-trigger"))
    vi.advanceTimersByTime(100)
    expect($("move-popup").hidden).toBe(false)

    // The pointer is on the padding of the popup, on its way to the submenu.
    $("move-trigger").dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: $("popup") }))
    expect(document.activeElement.id).toBe("move-trigger")
    expect($("move-popup").hidden).toBe(false)
  })

  it("opens a submenu with Enter with no animation", async () => {
    await mount(page())
    click("#trigger")
    $("move-trigger").focus()
    press("Enter")

    expect($("move-popup").hidden).toBe(false)
    expect($("move-popup").getAttribute("data-instant")).toBe("click")
  })

  it("locks the page for an open by the server after the pointer opened the menu", async () => {
    await mount(page('data-phem-menu-modal-value="true" data-phem-menu-open-on-hover-value="true"'))
    const nextTask = () => new Promise((resolve) => setTimeout(resolve, 0))
    hover($("trigger"))
    await new Promise((resolve) => setTimeout(resolve, 150))
    expect($("popup").hidden).toBe(false)
    expect(document.body.style.pointerEvents).toBe("")

    $("menu").setAttribute("data-phem-menu-open-value", "false")
    await nextTask()
    $("menu").setAttribute("data-phem-menu-open-value", "true")
    await nextTask()

    expect($("popup").hidden).toBe(false)
    expect(document.body.style.pointerEvents).toBe("none")
  })
})

describe("phem-menu: the behavior of Base UI", () => {
  const pointer = (type, el, init = {}) => {
    const event = new PointerEvent(type, { bubbles: true, cancelable: true, pointerType: "mouse", isPrimary: true, button: 0, ...init })
    el.dispatchEvent(event)

    return event
  }
  const mouseClick = (el) => el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1 }))
  const out = (el, to) => el.dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: to }))

  it("opens at a primary mouse pointerdown on the trigger, and the click after the press does not close it", async () => {
    await mount(actions())
    pointer("pointerdown", $("trigger"))
    expect(isOpen()).toBe(true)

    pointer("pointerup", $("trigger"))
    mouseClick($("trigger"))
    expect(isOpen()).toBe(true)
  })

  it("clicks an item at a pointerup 200 ms after the press on the trigger, and not before", async () => {
    await mount(actions())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

    pointer("pointerdown", $("trigger"))
    vi.advanceTimersByTime(100)
    pointer("pointerup", $("delete"))
    expect(isOpen()).toBe(true)

    press("Escape")
    pointer("pointerdown", $("trigger"))
    vi.advanceTimersByTime(250)
    pointer("pointerup", $("delete"))
    expect(isOpen()).toBe(false)
  })

  it("stays open after a drag from the trigger that ends outside the menu", async () => {
    await mount(actions())
    pointer("pointerdown", $("trigger"))
    expect(isOpen()).toBe(true)

    // The browser gives the click to the common ancestor of the press and the release.
    pointer("pointerup", document.body)
    mouseClick(document.body)
    expect(isOpen()).toBe(true)

    // The next press outside closes it.
    pointer("pointerdown", $("outside"))
    expect(isOpen()).toBe(false)
  })

  it("keeps the open at click for a touch", async () => {
    await mount(actions())
    pointer("pointerdown", $("trigger"), { pointerType: "touch" })
    expect(isOpen()).toBe(false)

    mouseClick($("trigger"))
    expect(isOpen()).toBe(true)
  })

  it("closes an open menu at a pointerdown on its trigger, and the click does not open it again", async () => {
    await mount(actions())
    pointer("pointerdown", $("trigger"))
    pointer("pointerup", $("trigger"))
    mouseClick($("trigger"))
    expect(isOpen()).toBe(true)

    pointer("pointerdown", $("trigger"))
    expect(isOpen()).toBe(false)

    pointer("pointerup", $("trigger"))
    mouseClick($("trigger"))
    expect(isOpen()).toBe(false)
  })

  it("keeps the focus on a field that a press outside focuses", async () => {
    await mount(`${actions()}<input id="field">`)
    const frame = () => new Promise((resolve) => requestAnimationFrame(resolve))

    // The mousedown on the field focuses it: the frame after the press does not take it back.
    click("#trigger")
    pointer("pointerdown", $("field"))
    $("field").focus()
    await frame()
    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("field")

    // The mousedown on an element that cannot get the focus moves it to the body: the frame gives
    // it back to the trigger.
    click("#trigger")
    pointer("pointerdown", document.body)
    document.activeElement.blur()
    await frame()
    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("trigger")
  })

  it("closes at a pointerdown outside, also with the right button, and gives the focus to the trigger", async () => {
    await mount(actions())
    click("#trigger")
    expect(document.activeElement.id).toBe("rename")

    pointer("pointerdown", $("outside"), { button: 2 })
    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("trigger")
  })

  it("does not open a closed root menu with ArrowUp", async () => {
    await mount(actions())
    $("trigger").focus()
    const event = press("ArrowUp")

    expect(isOpen()).toBe(false)
    expect(event.defaultPrevented).toBe(false)

    press("ArrowDown")
    expect(isOpen()).toBe(true)
    expect(document.activeElement.id).toBe("rename")
  })

  it("gives the focus to the trigger on Shift+Tab", async () => {
    await mount(actions())
    click("#trigger")
    const event = press("Tab", { shiftKey: true })

    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement.id).toBe("trigger")
  })

  it("gives tabindex 0 to the highlighted item, and -1 to the other items", async () => {
    await mount(actions())
    click("#trigger")
    expect($("rename").tabIndex).toBe(0)
    expect($("delete").tabIndex).toBe(-1)

    press("End")
    expect($("delete").tabIndex).toBe(0)
    expect($("rename").tabIndex).toBe(-1)
  })

  it("sets aria-controls on the trigger only while the menu is open", async () => {
    await mount(actions())
    expect($("trigger").hasAttribute("aria-controls")).toBe(false)

    click("#trigger")
    expect($("trigger").getAttribute("aria-controls")).toBe("popup")

    press("Escape")
    expect($("trigger").hasAttribute("aria-controls")).toBe(false)
  })

  it("removes the element reference of aria-controls on close", async () => {
    const restore = stubElementReflection()
    try {
      await mount(actions().replace('id="popup" ', ""))
      expect($("trigger").hasAttribute("aria-controls")).toBe(false)

      click("#trigger")
      expect($("trigger").ariaControlsElements).toEqual([document.querySelector("[data-phem-menu-target=popup]")])

      press("Escape")
      expect($("trigger").hasAttribute("aria-controls")).toBe(false)
      expect($("trigger").ariaControlsElements).toBe(null)
    } finally {
      restore()
    }
  })

  it("keeps the aria-controls of the server render on a closed trigger", async () => {
    await mount(actions().replace('id="trigger"', 'id="trigger" aria-controls="popup"'))
    click("#trigger")
    press("Escape")

    expect($("trigger").getAttribute("aria-controls")).toBe("popup")
  })

  describe("a submenu", () => {
    const nested = () => `<div id="menu" data-controller="phem-menu">
        <button id="trigger" data-phem-menu-target="trigger">Actions</button>
        <div id="popup" data-phem-menu-target="popup" hidden>
          <button id="rename" data-phem-menu-target="item">Rename</button>
          <div id="move" data-controller="phem-menu">
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

    const subOpen = () => !$("move-popup").hidden

    // The pointer opens the submenu, and goes into its popup.
    async function enter() {
      await mount(nested())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      mouseClick($("trigger"))
      hover($("move-trigger"))
      vi.advanceTimersByTime(100)
      expect(subOpen()).toBe(true)

      out($("move-trigger"), $("move-popup"))
      hover($("move-popup"))
      out($("move-popup"), $("inbox"))
      hover($("inbox"))
    }

    it("does not click a disabled item or a submenu trigger at a release after 200 ms", async () => {
      await mount(nested().replace('id="rename"', 'id="rename" aria-disabled="true"'))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

      pointer("pointerdown", $("trigger"))
      vi.advanceTimersByTime(250)
      pointer("pointerup", $("rename"))
      expect(isOpen()).toBe(true)

      // A new press closes the menu, and the press after it opens the menu again.
      pointer("pointerdown", $("trigger"))
      pointer("pointerdown", $("trigger"))
      vi.advanceTimersByTime(250)
      pointer("pointerup", $("move-trigger"))
      expect(isOpen()).toBe(true)
      expect(subOpen()).toBe(false)
    })

    it("keeps a submenu open with openOnHover when the pointer goes to empty space, but a root menu with openOnHover closes", async () => {
      await enter()
      out($("inbox"), document.body)
      vi.advanceTimersByTime(1000)
      expect(subOpen()).toBe(true)

      vi.useRealTimers()
      await mount(nested().replace('data-controller="phem-menu">', 'data-controller="phem-menu" data-phem-menu-open-on-hover-value="true">'))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      hover($("trigger"))
      vi.advanceTimersByTime(100)
      expect(isOpen()).toBe(true)

      out($("trigger"), $("popup"))
      hover($("popup"))
      out($("popup"), document.body)
      vi.advanceTimersByTime(10)
      expect(isOpen()).toBe(false)
    })

    it("Shift+Tab in a submenu closes the submenu and focuses its trigger", async () => {
      await mount(nested())
      click("#trigger")
      press("ArrowDown")
      press("Enter")
      expect(document.activeElement.id).toBe("inbox")

      const event = press("Tab", { shiftKey: true })
      expect(event.defaultPrevented).toBe(true)
      expect(subOpen()).toBe(false)
      expect(isOpen()).toBe(true)
      expect(document.activeElement.id).toBe("move-trigger")
    })

    it("gives data-nested to the popup of a submenu", async () => {
      await mount(nested())
      click("#trigger")
      $("move-trigger").focus()
      press("Enter")

      expect($("move-popup").hasAttribute("data-nested")).toBe(true)
      expect($("popup").hasAttribute("data-nested")).toBe(false)
    })

    it("gives tabindex 0 to a highlighted submenu trigger", async () => {
      await mount(nested())
      click("#trigger")
      press("ArrowDown")

      expect(document.activeElement.id).toBe("move-trigger")
      expect($("move-trigger").tabIndex).toBe(0)
      expect($("rename").tabIndex).toBe(-1)
    })

    it("removes the highlight of its trigger while the pointer is in the submenu", async () => {
      await mount(nested())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      mouseClick($("trigger"))
      hover($("move-trigger"))
      vi.advanceTimersByTime(100)
      expect($("move-trigger").hasAttribute("data-highlighted")).toBe(true)

      out($("move-trigger"), $("move-popup"))
      hover($("move-popup"))

      expect(subOpen()).toBe(true)
      expect($("move-trigger").hasAttribute("data-highlighted")).toBe(false)
    })

    it("highlights a sibling item at once when the submenu closes before the grace time", async () => {
      await mount(nested())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      mouseClick($("trigger"))
      hover($("move-trigger"))
      vi.advanceTimersByTime(100)
      expect(subOpen()).toBe(true)

      // The pointer goes from the trigger of the submenu to a sibling item: the item waits.
      out($("move-trigger"), $("delete"))
      hover($("delete"))
      expect(document.activeElement.id).toBe("move-trigger")

      // The submenu closes before the end of the grace time: the item gets the highlight then.
      application.getControllerForElementAndIdentifier($("move"), "phem-menu").close()

      expect(subOpen()).toBe(false)
      expect(document.activeElement.id).toBe("delete")
      expect($("move-trigger").hasAttribute("data-highlighted")).toBe(false)
    })

    it("stays open when the pointer goes from the submenu to empty space", async () => {
      await enter()

      out($("inbox"), document.body)
      document.body.dispatchEvent(new PointerEvent("pointermove", { bubbles: true, pointerType: "mouse", clientX: 900, clientY: 700 }))
      vi.advanceTimersByTime(1000)

      expect(subOpen()).toBe(true)
      expect(isOpen()).toBe(true)
    })

    // Base UI v1.9.0 in Chromium closes the submenu about 5 ms (one frame) after the pointer
    // reaches a sibling item, and about 55 ms after the pointer leaves the submenu (the path).
    it("closes when the pointer goes from the submenu to a sibling item", async () => {
      await enter()

      out($("inbox"), $("delete"))
      hover($("delete"))
      vi.advanceTimersByTime(20)

      expect(subOpen()).toBe(false)
      expect(isOpen()).toBe(true)
    })

    it("closes when the pointer goes from the submenu to a sibling item, with no highlight on hover", async () => {
      await mount(nested().replace('<div id="menu" data-controller="phem-menu">', '<div id="menu" data-controller="phem-menu" data-phem-menu-highlight-item-on-hover-value="false">'))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      mouseClick($("trigger"))
      $("move-trigger").focus()
      press("Enter")
      expect(subOpen()).toBe(true)

      hover($("move-popup"))
      out($("move-popup"), $("delete"))
      hover($("delete"))

      expect(subOpen()).toBe(false)
      expect(isOpen()).toBe(true)
    })

    it("waits for its grace time again after a close and an open with the keyboard", async () => {
      await enter()

      // ArrowLeft closes the submenu, and ArrowRight opens it again: the pointer did not enter it.
      press("ArrowLeft")
      expect(subOpen()).toBe(false)
      $("move-trigger").focus()
      press("ArrowRight")
      expect(subOpen()).toBe(true)

      hover($("delete"))
      expect(subOpen()).toBe(true)
    })
  })
})

describe("phem-menu: several triggers", () => {
  // Two detached triggers with a payload each, and a viewport with a template for each payload.
  const markup = `
    <button id="t1" data-phem-menu-for="menu" data-phem-menu-payload="file">File</button>
    <button id="t2" data-phem-menu-for="menu" data-phem-menu-payload="edit">Edit</button>
    <div id="menu" data-controller="phem-menu">
      <div id="popup" data-phem-menu-target="popup" hidden>
        <div id="viewport" data-phem-menu-target="viewport">
          <template data-payload="file"><button data-phem-menu-target="item">New</button></template>
          <template data-payload="edit"><button data-phem-menu-target="item">Undo</button><button data-phem-menu-target="item">Redo</button></template>
        </div>
      </div>
      <button id="t3" data-phem-menu-target="trigger">Open</button>
      <button id="open-edit" data-action="phem-menu#open" data-phem-menu-trigger-id-param="t2">Open edit</button>
    </div>`
  const items = () => [...document.querySelectorAll("#viewport [data-current] [role=menuitem]")].map((el) => el.textContent)

  it("opens next to the detached trigger with its payload, and moves to another trigger", async () => {
    await mount(markup)

    $("t1").click()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("popup").hidden).toBe(false)
    expect(items()).toEqual(["New"])
    expect($("t1").getAttribute("aria-expanded")).toBe("true")
    expect($("t2").getAttribute("aria-expanded")).toBe("false")
    expect($("t1").getAttribute("aria-haspopup")).toBe("menu")
    expect($("menu").getAttribute("data-phem-menu-trigger-id-value")).toBe("t1")

    $("t2").click()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("popup").hidden).toBe(false)
    expect(items()).toEqual(["Undo", "Redo"])
    expect($("t1").getAttribute("aria-expanded")).toBe("false")
    expect($("t2").getAttribute("aria-expanded")).toBe("true")
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)

    // Escape gives the focus back to the active trigger.
    $("popup").focus()
    press("Escape")
    expect($("popup").hidden).toBe(true)
    expect(document.activeElement).toBe($("t2"))
  })

  it("opens next to the trigger of the triggerId param of the open action", async () => {
    await mount(markup)

    $("open-edit").click()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("popup").hidden).toBe(false)
    expect($("t2").getAttribute("aria-expanded")).toBe("true")
    expect(items()).toEqual(["Undo", "Redo"])
  })

  it("keeps the menu open when the focus goes from the popup to another trigger", async () => {
    await mount(markup)

    $("t1").click()
    await new Promise((resolve) => setTimeout(resolve, 0))
    $("popup").focus()
    $("t2").focus()
    expect($("popup").hidden).toBe(false)
  })
})

describe("phem-menu: a filter", () => {
  const markup = (attributes = "") => `
    <div id="menu" data-controller="phem-menu" ${attributes}>
      <button id="trigger" data-phem-menu-target="trigger">Actions</button>
      <div id="popup" data-phem-menu-target="popup" hidden>
        <input id="input" data-phem-menu-target="input" aria-label="Filter actions">
        <button id="clear" data-phem-menu-target="clear">Clear</button>
        <div id="empty" data-phem-menu-target="empty" hidden>No actions found.</div>
        <div id="list" data-phem-menu-target="list">
          <div id="file" data-phem-menu-target="group">
            <button id="new" data-phem-menu-target="item">New file</button>
            <button id="save" data-phem-menu-target="item">Save</button>
            <button id="save-as" data-phem-menu-target="item">Save as…</button>
          </div>
          <div id="organize" data-phem-menu-target="group">
            <button id="move" data-phem-menu-target="item" data-label="Move to folder">Move</button>
            <button id="delete" data-phem-menu-target="item">Délete</button>
          </div>
        </div>
      </div>
    </div>
    <button id="outside">Outside</button>`
  const type = (text) => {
    $("input").value = text
    $("input").dispatchEvent(new Event("input", { bubbles: true }))
  }
  // A click of the mouse: a click of the keyboard also highlights the first item.
  const open = () => $("trigger").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1 }))
  const shown = () => [...document.querySelectorAll("#list [role=menuitem]")].filter((el) => !el.hidden).map((el) => el.id)

  it("is a dialog with a searchbox and a menu, and the input gets the focus", async () => {
    await mount(markup())

    expect($("popup").getAttribute("role")).toBe("dialog")
    expect($("list").getAttribute("role")).toBe("menu")
    expect($("input").getAttribute("role")).toBe("searchbox")
    expect($("input").getAttribute("aria-controls")).toBe("list")

    open()
    expect(document.activeElement).toBe($("input"))
    expect($("input").hasAttribute("data-highlighted")).toBe(true)
  })

  it("hides the items and the groups that do not match, with no case, accents and punctuation", async () => {
    await mount(markup())
    open()

    type("SAVE AS")
    expect(shown()).toEqual(["save-as"])
    expect($("organize").hidden).toBe(true)
    expect($("file").hidden).toBe(false)
    expect($("empty").hidden).toBe(true)

    type("delete")
    expect(shown()).toEqual(["delete"])

    // The label of an item replaces its text.
    type("folder")
    expect(shown()).toEqual(["move"])

    type("zzz")
    expect(shown()).toEqual([])
    expect($("empty").hidden).toBe(false)

    // The clear part empties the query.
    $("clear").click()
    expect($("input").value).toBe("")
    expect(shown()).toEqual(["new", "save", "save-as", "move", "delete"])
    expect(document.activeElement).toBe($("input"))
  })

  it("moves the highlight with the arrow keys while the input keeps the focus, and Enter clicks the item", async () => {
    await mount(markup())
    open()
    type("save")

    press("ArrowDown")
    expect(document.activeElement).toBe($("input"))
    expect($("save").hasAttribute("data-highlighted")).toBe(true)
    expect($("input").getAttribute("aria-activedescendant")).toBe("save")
    expect($("input").hasAttribute("data-highlighted")).toBe(false)

    press("ArrowDown")
    expect($("save-as").hasAttribute("data-highlighted")).toBe(true)

    const clicked = vi.fn()
    $("save-as").addEventListener("click", clicked)
    press("Enter")
    expect(clicked).toHaveBeenCalledOnce()
    expect($("popup").hidden).toBe(true)
  })

  it("highlights the first match with autoHighlight, and resets the query after the close", async () => {
    await mount(markup('data-phem-menu-auto-highlight-value="input"'))
    open()

    expect(document.querySelector("[role=menuitem][data-highlighted]")).toBeNull()
    type("as")
    expect($("save-as").hasAttribute("data-highlighted")).toBe(true)

    press("Escape")
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("input").value).toBe("")
    expect(shown()).toEqual(["new", "save", "save-as", "move", "delete"])
  })

  it("highlights the first item after an open with the keyboard", async () => {
    await mount(markup())

    $("trigger").click()
    expect(document.activeElement).toBe($("input"))
    expect($("new").hasAttribute("data-highlighted")).toBe(true)
  })

  it("hides nothing with the filter value false: the server renders the items of the query", async () => {
    await mount(markup('data-phem-menu-filter-value="false"'))
    open()

    type("zzz")
    expect(shown()).toEqual(["new", "save", "save-as", "move", "delete"])
    expect($("empty").hidden).toBe(true)
  })
})
