import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemNavigationMenuController from "../dist/phem_navigation_menu_controller.js"
import { mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-navigation-menu", PhemNavigationMenuController)
})

// A click on a link of the test does not start a Turbo visit. The listener runs after the one of
// the controller.
const stay = (event) => event.target.closest?.("a[href]") && event.preventDefault()

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  window.addEventListener("click", stay)
})

afterEach(async () => {
  window.removeEventListener("click", stay)
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
  await new Promise((resolve) => setTimeout(resolve, 0))
})

// A menu: "Overview" and "Guides" open a content, "Blog" is a link of the list (the current page),
// and "Archive" is disabled. With `viewport`, the popup has a viewport.
function nav({ attributes = "", viewport = true, log = "" } = {}) {
  const popup = viewport
    ? `<div id="positioner" data-phem-navigation-menu-target="positioner" hidden>
        <div id="popup" data-phem-navigation-menu-target="popup">
          <div id="arrow" data-phem-navigation-menu-target="arrow"></div>
          <div id="viewport" data-phem-navigation-menu-target="viewport"></div>
        </div>
      </div>`
    : ""

  return `<nav id="nav" data-controller="phem-navigation-menu" ${attributes}>
      <ul data-phem-navigation-menu-target="list">
        <li id="overview" data-phem-navigation-menu-target="item" data-value="overview">
          <button id="overview-trigger" data-phem-navigation-menu-target="trigger">Overview <span id="icon" data-phem-navigation-menu-target="icon"></span></button>
          <div id="overview-content" data-phem-navigation-menu-target="content" hidden>
            <a id="start" data-phem-navigation-menu-target="link" href="#start">Quick start</a>
            <a id="install" data-phem-navigation-menu-target="link" href="#install">Install</a>
            <button id="save" data-phem-on-click="c1.save">Save</button>
          </div>
        </li>
        <li id="guides" data-phem-navigation-menu-target="item" data-value="guides">
          <button id="guides-trigger" data-phem-navigation-menu-target="trigger">Guides</button>
          <div data-phem-navigation-menu-target="content" hidden>
            <a id="styling" data-phem-navigation-menu-target="link" href="#styling" data-phem-navigation-menu-close-on-click="true">Styling</a>
          </div>
        </li>
        <li data-phem-navigation-menu-target="item">
          <a id="blog" data-phem-navigation-menu-target="link" href="#blog" aria-current="page">Blog</a>
        </li>
        <li data-phem-navigation-menu-target="item" data-value="archive">
          <button id="archive-trigger" data-phem-navigation-menu-target="trigger" disabled>Archive</button>
          <div data-phem-navigation-menu-target="content" hidden><a href="#old">Old</a></div>
        </li>
      </ul>
      ${popup}
    </nav>
    <output id="log">${log}</output>
    <button id="outside">Outside</button>`
}

const $ = (id) => document.getElementById(id)
const content = (id) => $(id).querySelector('[data-phem-navigation-menu-target="content"]') ?? $("viewport").querySelector(`[data-phem-navigation-menu-target="content"]`)

function press(key, init = {}) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  document.activeElement.dispatchEvent(event)

  return event
}

function pointer(type, el, relatedTarget = null) {
  el.dispatchEvent(new PointerEvent(type, { bubbles: true, pointerType: "mouse", relatedTarget }))
}

// A keyboard click (`detail` 0) or a pointer click (`detail` 1).
function clickOn(el, detail = 1) {
  el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail }))
}

describe("phem-navigation-menu", () => {
  it("sets the ARIA attributes and the active link", async () => {
    await mount(nav())

    expect($("overview-trigger").getAttribute("aria-expanded")).toBe("false")
    expect($("overview-trigger").getAttribute("aria-controls")).toBe("overview-content")
    expect($("archive-trigger").hasAttribute("data-disabled")).toBe(true)
    expect($("blog").hasAttribute("data-active")).toBe(true)
    expect($("positioner").hidden).toBe(true)
    expect($("overview-content").hasAttribute("data-closed")).toBe(true)
  })

  it("opens the content in the viewport with a click, and closes it with a second click", async () => {
    await mount(nav())
    const changes = []
    $("nav").addEventListener("phem-navigation-menu:change", (event) => changes.push(event.detail.value))

    clickOn($("overview-trigger"))
    expect($("nav").getAttribute("data-phem-navigation-menu-value-value")).toBe("overview")
    expect($("positioner").hidden).toBe(false)
    expect($("positioner").hasAttribute("data-open")).toBe(true)
    expect($("popup").hasAttribute("data-open")).toBe(true)
    expect($("viewport").contains($("overview-content"))).toBe(true)
    expect($("overview-content").hidden).toBe(false)
    expect($("overview-trigger").getAttribute("aria-expanded")).toBe("true")
    expect($("overview-trigger").hasAttribute("data-popup-open")).toBe(true)
    expect($("overview-trigger").hasAttribute("data-pressed")).toBe(true)
    expect($("icon").hasAttribute("data-popup-open")).toBe(true)
    expect($("popup").style.getPropertyValue("--popup-width")).toBe("0px")
    expect($("positioner").style.getPropertyValue("--positioner-height")).toBe("0px")

    clickOn($("overview-trigger"))
    expect($("positioner").hidden).toBe(true)
    expect($("overview").contains($("overview-content"))).toBe(true)
    expect($("overview-content").hidden).toBe(true)
    expect($("nav").getAttribute("data-phem-navigation-menu-value-value")).toBe("")
    expect(changes).toEqual(["overview", ""])
  })

  it("puts the content back in its place in the item", async () => {
    await mount(nav())
    const before = [...$("overview").childNodes]

    clickOn($("overview-trigger"))
    clickOn($("guides-trigger"))
    clickOn($("guides-trigger"))

    expect([...$("overview").childNodes]).toEqual(before)
    expect($("viewport").children.length).toBe(0)
  })

  it("places the positioner at once on the first open, and animates the move to another item", async () => {
    await mount(nav())

    clickOn($("overview-trigger"))
    expect($("positioner").hasAttribute("data-instant")).toBe(true)

    clickOn($("guides-trigger"))
    expect($("positioner").hasAttribute("data-instant")).toBe(false)

    // A close, then a new first open.
    clickOn($("guides-trigger"))
    clickOn($("overview-trigger"))
    expect($("positioner").hasAttribute("data-instant")).toBe(true)
  })

  it("changes the open item with the activation direction", async () => {
    await mount(nav())

    clickOn($("overview-trigger"))
    expect($("overview-content").hasAttribute("data-activation-direction")).toBe(false)

    clickOn($("guides-trigger"))
    const guides = content("guides")
    expect(guides.getAttribute("data-activation-direction")).toBe("right")
    expect(guides.hidden).toBe(false)
    expect($("overview-content").hidden).toBe(true)
    expect($("overview-content").getAttribute("data-activation-direction")).toBe("right")
    expect($("overview-trigger").getAttribute("aria-expanded")).toBe("false")
    expect($("guides-trigger").getAttribute("aria-expanded")).toBe("true")
    expect($("positioner").hidden).toBe(false)

    clickOn($("overview-trigger"))
    expect($("overview-content").getAttribute("data-activation-direction")).toBe("left")
  })

  it("opens on hover after the delay, and closes after the close delay", async () => {
    await mount(nav())
    vi.useFakeTimers()

    pointer("pointerover", $("overview-trigger"))
    expect($("positioner").hidden).toBe(true)
    vi.advanceTimersByTime(50)
    expect($("overview-content").hidden).toBe(false)

    // A click on a trigger that the pointer opened keeps it open.
    clickOn($("overview-trigger"))
    expect($("overview-content").hidden).toBe(false)
    clickOn($("overview-trigger"))
    expect($("overview-content").hidden).toBe(true)

    pointer("pointerover", $("overview-trigger"))
    vi.advanceTimersByTime(50)
    pointer("pointerover", $("guides-trigger"))
    vi.advanceTimersByTime(50)
    expect(content("guides").hidden).toBe(false)

    // From the trigger to the popup: the menu stays open.
    pointer("pointerout", $("guides-trigger"), $("popup"))
    vi.advanceTimersByTime(100)
    expect(content("guides").hidden).toBe(false)

    pointer("pointerout", $("popup"), $("outside"))
    vi.advanceTimersByTime(49)
    expect(content("guides").hidden).toBe(false)
    vi.advanceTimersByTime(1)
    expect($("positioner").hidden).toBe(true)
  })

  it("does not close on the pointer out after a click opened it", async () => {
    await mount(nav())
    vi.useFakeTimers()

    clickOn($("overview-trigger"))
    pointer("pointerout", $("overview-trigger"), $("outside"))
    vi.advanceTimersByTime(200)
    expect($("overview-content").hidden).toBe(false)
  })

  it("moves the focus in the list with the arrow keys, and skips a disabled trigger", async () => {
    await mount(nav())

    $("overview-trigger").focus()
    press("ArrowRight")
    expect(document.activeElement).toBe($("guides-trigger"))
    press("ArrowRight")
    expect(document.activeElement).toBe($("blog"))
    // The focus stops at the last one, and Home and End do not move it, as in Base UI.
    press("ArrowRight")
    expect(document.activeElement).toBe($("blog"))
    press("Home")
    expect(document.activeElement).toBe($("blog"))
    press("ArrowLeft")
    press("ArrowLeft")
    expect(document.activeElement).toBe($("overview-trigger"))
    press("End")
    expect(document.activeElement).toBe($("overview-trigger"))
  })

  it("goes from the last one to the first one with loopFocus", async () => {
    await mount(nav({ attributes: 'data-phem-navigation-menu-loop-focus-value="true"' }))

    $("blog").focus()
    press("ArrowRight")
    expect(document.activeElement).toBe($("overview-trigger"))
  })

  it("opens with ArrowDown and Enter with the focus on the trigger, and closes with Escape", async () => {
    await mount(nav())

    $("overview-trigger").focus()
    press("ArrowDown")
    expect($("overview-content").hidden).toBe(false)
    expect(document.activeElement).toBe($("overview-trigger"))

    press("Escape")
    expect($("overview-content").hidden).toBe(true)
    expect(document.activeElement).toBe($("overview-trigger"))

    $("guides-trigger").focus()
    clickOn($("guides-trigger"), 0)
    expect($("guides-trigger").getAttribute("aria-expanded")).toBe("true")
    expect(document.activeElement).toBe($("guides-trigger"))
  })

  it("leaves the arrow keys to a field in a content", async () => {
    await mount(nav().replace('<a id="start"', '<input id="search" aria-label="Search" /><a id="start"'))

    clickOn($("overview-trigger"))
    $("search").focus()

    expect(press("ArrowLeft").defaultPrevented).toBe(false)
    expect(press("ArrowDown").defaultPrevented).toBe(false)
    expect(document.activeElement).toBe($("search"))
  })

  it("moves the focus between the links of a content with the four arrow keys", async () => {
    await mount(nav())

    clickOn($("overview-trigger"))
    $("start").focus()
    const links = [...$("overview-content").querySelectorAll("[data-phem-navigation-menu-target~='link']")]
    expect(links.length).toBeGreaterThan(1)

    expect(press("ArrowDown").defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(links[1])
    press("ArrowLeft")
    expect(document.activeElement).toBe(links[0])
    // From the first link to the last one, and back.
    press("ArrowUp")
    expect(document.activeElement).toBe(links.at(-1))
    press("ArrowRight")
    expect(document.activeElement).toBe(links[0])
  })

  it("moves Tab into the content in the viewport, and out of it to the next trigger with the menu open", async () => {
    await mount(nav())

    clickOn($("overview-trigger"))
    $("overview-trigger").focus()
    expect(press("Tab").defaultPrevented).toBe(true)
    expect(document.activeElement).toBe($("start"))

    $("start").focus()
    expect(press("Tab", { shiftKey: true }).defaultPrevented).toBe(true)
    expect(document.activeElement).toBe($("overview-trigger"))

    $("save").focus()
    expect(press("Tab").defaultPrevented).toBe(true)
    expect(document.activeElement).toBe($("guides-trigger"))
    // The menu stays open until the focus goes out of it.
    expect($("positioner").hidden).toBe(false)
  })

  it("closes on a click outside, and on a link with close-on-click", async () => {
    await mount(nav())

    clickOn($("overview-trigger"))
    $("outside").click()
    expect($("positioner").hidden).toBe(true)

    clickOn($("guides-trigger"))
    $("styling").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }))
    expect($("positioner").hidden).toBe(true)
  })

  it("does not open a disabled trigger", async () => {
    await mount(nav())

    clickOn($("archive-trigger"))
    expect($("nav").getAttribute("data-phem-navigation-menu-value-value")).toBe(null)
  })

  it("opens from the value of the server", async () => {
    await mount(nav({ attributes: 'data-phem-navigation-menu-value-value="guides"' }))

    expect(content("guides").hidden).toBe(false)
    expect($("viewport").contains(content("guides"))).toBe(true)
    expect($("positioner").hidden).toBe(false)
  })

  it("shows the content in its item with no viewport", async () => {
    await mount(nav({ viewport: false }))

    clickOn($("overview-trigger"))
    expect($("overview-content").hidden).toBe(false)
    expect($("overview").contains($("overview-content"))).toBe(true)

    press("Escape")
    expect($("overview-content").hidden).toBe(true)
  })

  it("keeps the open item in the viewport after the morph of an event", async () => {
    await mount(nav())

    clickOn($("overview-trigger"))
    respond(nav({ log: "saved" }))
    const done = nextDone()
    $("save").click()
    await done
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect($("log").textContent).toBe("saved")
    expect($("viewport").contains($("overview-content"))).toBe(true)
    expect($("viewport").children.length).toBe(1)
    expect($("overview-content").hidden).toBe(false)
    expect($("overview").querySelectorAll('[data-phem-navigation-menu-target="content"]').length).toBe(0)
    expect($("overview-trigger").getAttribute("aria-expanded")).toBe("true")
    expect($("positioner").hidden).toBe(false)

    clickOn($("overview-trigger"))
    expect($("overview").contains($("overview-content"))).toBe(true)
  })
})
