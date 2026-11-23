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
  it("adds no ARIA to a tooltip with the accessible name of its trigger", async () => {
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

  it("describes a trigger with a tooltip that is not its accessible name", async () => {
    await mount(`<button id="archive" data-controller="phem-tooltip" aria-label="Archive">
        A<span id="tip" data-phem-tooltip-target="popup" hidden>Moves the message out of the inbox</span>
      </button>
      <button id="save" data-controller="phem-tooltip"><img alt="Save"> <span id="save-tip" data-phem-tooltip-target="popup" hidden>save</span></button>
      <button id="named" data-controller="phem-tooltip" aria-describedby="help"><span id="named-tip" data-phem-tooltip-target="popup" role="note" hidden>More</span>Named</button>`)

    expect($("tip").getAttribute("role")).toBe("tooltip")
    expect($("archive").getAttribute("aria-describedby")).toBe("tip")
    // The text of the content (with the alt of an image) is the name: no description.
    expect($("save-tip").hasAttribute("role")).toBe(false)
    expect($("save").hasAttribute("aria-describedby")).toBe(false)
    // The ARIA of the server render stays.
    expect($("named-tip").getAttribute("role")).toBe("note")
    expect($("named").getAttribute("aria-describedby")).toBe("help")
  })

  it("keeps the description of a trigger after the morph", async () => {
    const markup = `<button id="archive" data-controller="phem-tooltip" aria-label="Archive" data-phem-on-click="c1.archive">
        A<span id="tip" data-phem-tooltip-target="popup" hidden>Moves the message out of the inbox</span>
      </button>`
    await mount(markup)
    respond(markup)
    const done = nextDone()
    $("archive").click()
    await done

    expect($("tip").getAttribute("role")).toBe("tooltip")
    expect($("archive").getAttribute("aria-describedby")).toBe("tip")
  })

  it("lets the pointer cross the gap between the trigger and the popup", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))
    vi.advanceTimersByTime(0)

    // The popup is 10 px above the trigger.
    const box = (left, top, right, bottom) => () => ({ left, top, right, bottom, width: right - left, height: bottom - top, x: left, y: top })
    $("archive").getBoundingClientRect = box(0, 100, 100, 130)
    $("tip").getBoundingClientRect = box(0, 50, 100, 90)
    const pointer = (type, el, x, y, init = {}) => el.dispatchEvent(new PointerEvent(type, { bubbles: true, pointerType: "mouse", clientX: x, clientY: y, ...init }))

    // The pointer leaves the top of the trigger, and moves in the gap: the popup stays.
    pointer("pointerout", $("archive"), 50, 100, { relatedTarget: $("outside") })
    pointer("pointermove", $("outside"), 50, 95)
    vi.advanceTimersByTime(10)
    expect($("tip").hidden).toBe(false)

    // The pointer on the popup.
    pointer("pointerover", $("tip"), 50, 89)
    pointer("pointermove", $("tip"), 50, 80)
    expect($("tip").hidden).toBe(false)

    // The pointer goes out of the top of the popup: away from the trigger.
    pointer("pointerout", $("tip"), 50, 49, { relatedTarget: $("outside") })
    expect($("tip").hidden).toBe(true)
  })

  it("closes the popup when the pointer leaves the gap", async () => {
    await mount(toolbar('data-phem-tooltip-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("archive"))
    vi.advanceTimersByTime(0)

    const box = (left, top, right, bottom) => () => ({ left, top, right, bottom, width: right - left, height: bottom - top, x: left, y: top })
    $("archive").getBoundingClientRect = box(0, 100, 100, 130)
    $("tip").getBoundingClientRect = box(0, 50, 100, 90)
    const pointer = (type, el, x, y, init = {}) => el.dispatchEvent(new PointerEvent(type, { bubbles: true, pointerType: "mouse", clientX: x, clientY: y, ...init }))

    pointer("pointerout", $("archive"), 50, 100, { relatedTarget: $("outside") })
    pointer("pointermove", $("outside"), 200, 95)
    vi.advanceTimersByTime(0)

    expect($("tip").hidden).toBe(true)
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

  it("shows one tooltip at a time when the focus of the keyboard moves to the next trigger", async () => {
    await mount(toolbar())
    $("tip").getAnimations = () => ($("tip").hasAttribute("data-instant") ? [] : [{ finished: new Promise(() => {}) }])
    tabTo($("archive"))
    expect($("tip").hidden).toBe(false)

    // Tab: the blur closes the first tooltip, and the focus opens the next one.
    tabTo($("delete"))

    expect($("tip-2").hidden).toBe(false)
    expect($("tip-2").getAttribute("data-instant")).toBe("focus")
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

// Three detached triggers of one tooltip, with a payload each.
function detached(attributes = "") {
  return `<div id="bar">
      <button id="t1" data-phem-tooltip-for="shared" data-phem-tooltip-payload="Listen" aria-label="Listen">1</button>
      <button id="t2" data-phem-tooltip-for="shared" data-phem-tooltip-payload="Set a timer" aria-label="Set a timer">2</button>
      <button id="t3" data-phem-tooltip-for="other" aria-label="Other">3</button>
    </div>
    <span id="shared" data-controller="phem-tooltip" data-phem-tooltip-delay-value="0" ${attributes}>
      <span id="shared-tip" data-phem-tooltip-target="popup" hidden><span id="vp" data-phem-tooltip-target="viewport">Server text</span></span>
    </span>
    <p id="outside">Outside</p>`
}

describe("phem-tooltip with several triggers", () => {
  it("describes only the active trigger of an open popup with a viewport", async () => {
    await mount(detached().replace('aria-label="Set a timer"', 'aria-label="Timer"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

    expect($("t2").hasAttribute("aria-describedby")).toBe(false)

    over($("t2"))
    vi.advanceTimersByTime(0)
    expect($("t2").getAttribute("aria-describedby")).toBe("shared-tip")
    expect($("shared-tip").getAttribute("role")).toBe("tooltip")
    expect($("t1").hasAttribute("aria-describedby")).toBe(false)

    out($("t2"), $("outside"))
    expect($("t2").hasAttribute("aria-describedby")).toBe(false)
    expect($("shared-tip").hasAttribute("role")).toBe(false)
  })

  it("opens next to a detached trigger, with its payload in the viewport", async () => {
    await mount(detached())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("t2"))
    vi.advanceTimersByTime(0)

    expect($("shared-tip").hidden).toBe(false)
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)
    expect($("t1").hasAttribute("data-popup-open")).toBe(false)
    expect($("vp").innerHTML).toBe('<span data-current="">Set a timer</span>')
    expect($("shared").getAttribute("data-phem-tooltip-open-value")).toBe("true")
    expect($("shared").getAttribute("data-phem-tooltip-trigger-id-value")).toBe("t2")

    out($("t2"), $("outside"))
    expect($("shared-tip").hidden).toBe(true)
    expect($("t2").hasAttribute("data-popup-open")).toBe(false)
    expect($("shared").getAttribute("data-phem-tooltip-open-value")).toBe("false")
  })

  it("ignores a trigger of another tooltip", async () => {
    await mount(detached())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    over($("t3"))
    vi.advanceTimersByTime(0)

    expect($("shared-tip").hidden).toBe(true)
  })

  it("moves to the next trigger at once, and animates the change of the content", async () => {
    await mount(detached())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    $("t1").getBoundingClientRect = () => new DOMRect(0, 0, 32, 32)
    $("t2").getBoundingClientRect = () => new DOMRect(32, 0, 32, 32)
    over($("t1"))
    vi.advanceTimersByTime(0)
    out($("t1"), $("t2"))
    over($("t2"))

    expect($("shared-tip").hidden).toBe(false)
    expect($("t1").hasAttribute("data-popup-open")).toBe(false)
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)
    expect($("vp").getAttribute("data-activation-direction")).toBe("right")
    expect($("vp").querySelector("[data-current]").textContent).toBe("Set a timer")
    expect($("shared").getAttribute("data-phem-tooltip-trigger-id-value")).toBe("t2")
  })

  it("moves with the focus of the keyboard from one trigger to the next", async () => {
    await mount(detached())
    tabTo($("t1"))
    expect($("vp").textContent).toBe("Listen")

    tabTo($("t2"))
    expect($("shared-tip").hidden).toBe(false)
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)
    expect($("vp").querySelector("[data-current]").textContent).toBe("Set a timer")

    $("outside").setAttribute("tabindex", "-1")
    $("outside").focus()
    expect($("shared-tip").hidden).toBe(true)
  })

  it("opens and moves with the open and triggerId values", async () => {
    await mount(detached())
    $("shared").setAttribute("data-phem-tooltip-trigger-id-value", "t2")
    $("shared").setAttribute("data-phem-tooltip-open-value", "true")
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect($("shared-tip").hidden).toBe(false)
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)

    $("shared").setAttribute("data-phem-tooltip-trigger-id-value", "t1")
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("t1").hasAttribute("data-popup-open")).toBe(true)
    expect($("vp").querySelector("[data-current]").textContent).toBe("Listen")

    $("shared").setAttribute("data-phem-tooltip-open-value", "false")
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("shared-tip").hidden).toBe(true)
  })

  it("opens on load with the open value, next to the trigger of triggerId", async () => {
    await mount(detached('data-phem-tooltip-open-value="true" data-phem-tooltip-trigger-id-value="t2"'))

    expect($("shared-tip").hidden).toBe(false)
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)
  })

  it("opens next to the trigger of the triggerId param of the show action", async () => {
    await mount(`<span id="shared" data-controller="phem-tooltip">
        <button id="a" data-phem-tooltip-target="trigger">A</button>
        <button id="b" data-phem-tooltip-target="trigger">B</button>
        <button id="open" data-action="phem-tooltip#show" data-phem-tooltip-trigger-id-param="b">Open</button>
        <span id="shared-tip" data-phem-tooltip-target="popup" hidden>Tip</span>
      </span>`)
    $("open").click()

    expect($("shared-tip").hidden).toBe(false)
    expect($("b").hasAttribute("data-popup-open")).toBe(true)
    expect($("a").hasAttribute("data-popup-open")).toBe(false)

    // The pointer out of the button that is not a trigger keeps the tooltip open.
    out($("open"), $("a"))
    expect($("shared-tip").hidden).toBe(false)
  })

  it("keeps the payload of the active trigger through the morph of an event", async () => {
    await mount(`<button id="send" data-phem-on-click="c1.send">Send</button>${detached()}`)
    respond(`<button id="send" data-phem-on-click="c1.send">Send</button>${detached()}`)
    tabTo($("t2"))
    const done = nextDone()
    $("send").click()
    await done
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect($("vp").querySelector("[data-current]")?.textContent).toBe("Set a timer")
  })

  it("keeps the trigger id and the state of the detached trigger through the morph of an event", async () => {
    await mount(`<button id="send" data-phem-on-click="c1.send">Send</button>${detached()}`)
    respond(`<button id="send" data-phem-on-click="c1.send">Send</button>${detached()}`)
    tabTo($("t2"))
    const done = nextDone()
    $("send").click()
    await done
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect($("shared").getAttribute("data-phem-tooltip-open-value")).toBe("true")
    expect($("shared").getAttribute("data-phem-tooltip-trigger-id-value")).toBe("t2")
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)
  })
})
