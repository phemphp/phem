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
  it("is only visual: no role on the popup and no aria-describedby on the trigger", async () => {
    await mount(toolbar())

    expect($("tip").hasAttribute("role")).toBe(false)
    expect($("archive").hasAttribute("aria-describedby")).toBe(false)
    expect($("archive").getAttribute("aria-label")).toBe("Archive")
  })

  it("keeps the role and the aria-describedby of the server render, also after the morph", async () => {
    const markup = toolbar().replace('id="tip" ', 'id="tip" role="tooltip" ').replace('aria-label="Archive"', 'aria-label="Archive" aria-describedby="tip"')
    await mount(markup)
    respond(markup)
    const done = nextDone()
    $("archive").click()
    await done

    expect($("tip").getAttribute("role")).toBe("tooltip")
    expect($("archive").getAttribute("aria-describedby")).toBe("tip")
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

  it("shows one tooltip at a time: the next tooltip of a group stops the close animation of the last one", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    // The CSS of the page stops the animation of a popup with `data-instant`.
    $("tip").getAnimations = () => ($("tip").hasAttribute("data-instant") ? [] : [{ finished: new Promise(() => {}) }])
    over($("archive"))
    vi.advanceTimersByTime(0)
    out($("archive"), $("delete"))
    expect($("tip").hasAttribute("data-ending-style")).toBe(true)

    over($("delete"))

    expect($("tip-2").hidden).toBe(false)
    expect($("tip").hidden).toBe(true)
    expect($("tip").getAttribute("data-instant")).toBe("delay")
  })

  it("keeps open a tooltip that opened again during its close animation, when the next tooltip of a group opens", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    $("tip").getAnimations = () => ($("tip").hasAttribute("data-instant") ? [] : [{ finished: new Promise(() => {}) }])
    over($("archive"))
    vi.advanceTimersByTime(0)
    out($("archive"), $("outside"))
    expect($("tip").hasAttribute("data-ending-style")).toBe(true)

    // The keyboard focus opens it again before the end of its close animation.
    tabTo($("archive"))
    expect($("tip").hidden).toBe(false)

    // The other tooltip of the page closes, and the pointer goes on the next one.
    $("tip-2").getAnimations = () => []
    over($("delete"))
    vi.advanceTimersByTime(0)
    out($("delete"), $("outside"))
    over($("delete"))

    expect($("tip").hidden).toBe(false)
    expect($("archive").hasAttribute("data-popup-open")).toBe(true)
  })

  it("does not touch a tooltip again when its close animation ended", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    let finish
    $("tip").getAnimations = () => [{ finished: new Promise((resolve) => (finish = resolve)) }]
    over($("archive"))
    vi.advanceTimersByTime(0)
    out($("archive"), $("delete"))
    finish()
    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()
    expect($("tip").hidden).toBe(true)

    over($("delete"))

    expect($("tip-2").hidden).toBe(false)
    expect($("tip").hasAttribute("data-instant")).toBe(false)
  })

  it("forgets a closing tooltip that the morph removes", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    const tip = $("tip")
    tip.getAnimations = () => [{ finished: new Promise(() => {}) }]
    over($("archive"))
    vi.advanceTimersByTime(0)
    out($("archive"), $("delete"))
    $("archive").remove()
    await Promise.resolve()

    over($("delete"))

    expect($("tip-2").hidden).toBe(false)
    expect(tip.hasAttribute("data-instant")).toBe(false)
  })

  it("keeps a hoverable tooltip open at a press on its popup", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))
    vi.advanceTimersByTime(0)

    $("tip").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, pointerType: "mouse" }))

    expect($("tip").hidden).toBe(false)
  })

  it("closes at the press of the pointer on the trigger, before the click", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))
    vi.advanceTimersByTime(0)
    expect($("tip").hidden).toBe(false)

    $("archive").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, pointerType: "mouse" }))

    expect($("tip").hidden).toBe(true)
    expect($("tip").getAttribute("data-instant")).toBe("dismiss")
  })

  it("keeps the close animation of a tooltip that closed before the group time", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    let now = 10000
    vi.spyOn(performance, "now").mockImplementation(() => now)
    $("tip").getAnimations = () => [{ finished: new Promise(() => {}) }]
    over($("archive"))
    vi.advanceTimersByTime(0)
    out($("archive"), $("delete"))
    expect($("tip").hasAttribute("data-ending-style")).toBe(true)

    now += 401
    over($("delete"))
    vi.advanceTimersByTime(600)

    expect($("tip-2").hidden).toBe(false)
    expect($("tip-2").hasAttribute("data-instant")).toBe(false)
    expect($("tip").hasAttribute("data-ending-style")).toBe(true)
    expect($("tip").hasAttribute("data-instant")).toBe(false)
  })

  it("closes at the click of Enter on the trigger", async () => {
    await mount(toolbar())
    tabTo($("delete"))
    expect($("tip-2").hidden).toBe(false)

    // Enter on a button gives a click and no pointerdown.
    $("delete").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }))

    expect($("tip-2").hidden).toBe(true)
    expect($("tip-2").getAttribute("data-instant")).toBe("dismiss")
  })

  it("keeps the tooltip open at a press with closeOnClick false", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0" data-phem-tooltip-close-on-click-value="false"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))
    vi.advanceTimersByTime(0)

    $("archive").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, pointerType: "mouse" }))

    expect($("tip").hidden).toBe(false)
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

    expect($("archive").hasAttribute("aria-describedby")).toBe(false)
    expect($("tip").hasAttribute("role")).toBe(false)
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
