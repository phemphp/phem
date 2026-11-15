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
      expect($("trigger").ariaControlsElements).toEqual([popup()])
      expect($("group").ariaLabelledByElements).toEqual([label()])

      const done = nextDone()
      click("#trigger")
      click("#rename")
      await done

      expect(popup().id).toBe("")
      expect($("trigger").ariaControlsElements).toEqual([popup()])
      expect($("group").ariaLabelledByElements).toEqual([label()])
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
