import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemTooltipController from "../dist/phem_tooltip_controller.js"
import { mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-tooltip", PhemTooltipController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// Two buttons with a tooltip each. The first one sends an event.
function toolbar(attributes = "") {
  return `<button id="archive" data-controller="phem-tooltip" aria-label="Archive" data-phem-on-click="c1.archive" ${attributes}>
      A<span id="tip" data-phem-tooltip-target="popup" hidden>Archive</span>
    </button>
    <button id="delete" data-controller="phem-tooltip" aria-label="Delete">
      D<span id="tip-2" data-phem-tooltip-target="popup" hidden>Delete</span>
    </button>
    <p id="outside">Outside</p>`
}

function over(el, init = {}) {
  el.dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse", ...init }))
}

function out(el, to = document.body) {
  el.dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: to }))
}

const $ = (id) => document.getElementById(id)

// A focus of the keyboard (Tab): `:focus-visible` matches only after a key, not after a click.
function tabTo(el) {
  document.dispatchEvent(new KeyboardEvent("keydown", { key: "Tab", bubbles: true }))
  el.focus()
}

describe("phem-tooltip", () => {
  it("sets the role, the id and aria-describedby", async () => {
    await mount(toolbar())

    expect($("tip").getAttribute("role")).toBe("tooltip")
    expect($("archive").getAttribute("aria-describedby")).toBe("tip")
  })

  it("gives an id to a popup with no id", async () => {
    await mount(toolbar().replace('id="tip" ', ""))
    const popup = $("archive").querySelector("[data-phem-tooltip-target=popup]")

    expect(popup.id).toMatch(/^phem-tooltip-\d+$/)
    expect($("archive").getAttribute("aria-describedby")).toBe(popup.id)
  })

  it("opens on hover after the delay, and closes when the pointer goes out", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="300"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))

    vi.advanceTimersByTime(299)
    expect($("tip").hidden).toBe(true)
    vi.advanceTimersByTime(1)
    expect($("tip").hidden).toBe(false)
    expect($("tip").getAttribute("data-side")).toBe("top")
    expect($("archive").hasAttribute("data-popup-open")).toBe(true)

    out($("archive"))
    expect($("tip").hidden).toBe(true)
    expect($("archive").hasAttribute("data-popup-open")).toBe(false)
  })

  it("stays open while the pointer is on the popup", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))
    vi.advanceTimersByTime(0)
    out($("archive"), $("tip"))

    expect($("tip").hidden).toBe(false)
  })

  it("opens the next tooltip of a group at once, with data-instant", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))
    vi.advanceTimersByTime(0)
    out($("archive"), $("delete"))
    over($("delete"))

    expect($("tip-2").hidden).toBe(false)
    expect($("tip-2").getAttribute("data-instant")).toBe("delay")
  })

  it("shows no tooltip for a touch", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"), { pointerType: "touch" })
    vi.advanceTimersByTime(1000)

    expect($("tip").hidden).toBe(true)
  })

  it("opens on the focus of the keyboard, and closes on the blur and on Escape", async () => {
    await mount(toolbar())
    tabTo($("archive"))
    expect($("tip").hidden).toBe(false)
    expect($("tip").getAttribute("data-instant")).toBe("focus")

    $("archive").blur()
    expect($("tip").hidden).toBe(true)

    tabTo($("archive"))
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true }))
    expect($("tip").hidden).toBe(true)
  })

  it("closes on a click on the trigger, unless closeOnClick is false", async () => {
    await mount(toolbar())
    respond(toolbar())
    tabTo($("archive"))
    const done = nextDone()
    $("archive").click()
    expect($("tip").hidden).toBe(true)
    await done

    expect($("archive").getAttribute("aria-describedby")).toBe("tip")
    expect($("tip").getAttribute("role")).toBe("tooltip")
  })

  it("does not open on the focus of a click", async () => {
    await mount(toolbar())
    $("outside").click()
    $("archive").focus()

    expect($("tip").hidden).toBe(true)
  })

  it("keeps the open tooltip through the morph of an event", async () => {
    await mount(toolbar('data-phem-tooltip-close-on-click-value="false"'))
    respond(toolbar('data-phem-tooltip-close-on-click-value="false"'))
    tabTo($("archive"))
    const done = nextDone()
    $("archive").click()
    await done

    expect($("tip").hidden).toBe(false)
    expect($("tip").style.position).toBe("fixed")
  })
})
