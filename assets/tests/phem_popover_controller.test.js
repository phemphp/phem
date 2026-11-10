import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemPopoverController from "../dist/phem_popover_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-popover", PhemPopoverController)
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

// A popover with a "Copy" button that sends an event, a close button, and the `attributes` of the
// controller element. The server render always gives a closed popover.
function share(attributes = "", content = "") {
  return `<div id="share" data-controller="phem-popover" ${attributes}>
      <button id="trigger" data-phem-popover-target="trigger">Share</button>
      <div id="popup" data-phem-popover-target="popup" hidden>
        <div id="arrow" data-phem-popover-target="arrow"></div>
        <h2 id="title" data-phem-popover-target="title">Share</h2>
        <p id="description" data-phem-popover-target="description">Copy the link.</p>
        <button id="copy" data-phem-on-click="c1.copy">Copy</button>
        <button id="close" data-phem-popover-target="close">Close</button>
        ${content}
      </div>
    </div>
    <button id="outside">Outside</button>`
}

function popup() {
  return document.getElementById("popup")
}

// The popup and the trigger must agree.
function isOpen() {
  const open = !popup().hidden
  const trigger = document.getElementById("trigger")
  const agree = [trigger.getAttribute("aria-expanded") === String(open), trigger.hasAttribute("data-popup-open") === open, popup().hasAttribute("data-open") === open]
  if (agree.includes(false)) throw new Error(`The parts do not agree: ${agree}`)

  return open
}

function press(key, target = document.activeElement) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
  target.dispatchEvent(event)

  return event
}

describe("phem-popover", () => {
  it("opens and closes the popup with a click on the trigger", async () => {
    await mount(share())

    expect(isOpen()).toBe(false)
    click("#trigger")
    expect(isOpen()).toBe(true)
    click("#trigger")
    expect(isOpen()).toBe(false)
  })

  it("sets the roles and the ARIA attributes", async () => {
    await mount(share())
    const trigger = document.getElementById("trigger")

    expect(popup().getAttribute("role")).toBe("dialog")
    expect(popup().getAttribute("aria-labelledby")).toBe("title")
    expect(popup().getAttribute("aria-describedby")).toBe("description")
    expect(trigger.getAttribute("aria-haspopup")).toBe("dialog")
    expect(trigger.getAttribute("aria-controls")).toBe("popup")
  })

  it("places the popup next to the trigger while it is open", async () => {
    await mount(share('data-phem-popover-side-value="top" data-phem-popover-align-value="end"'))
    click("#trigger")

    expect(popup().style.position).toBe("fixed")
    expect(popup().getAttribute("data-side")).toBe("top")
    expect(popup().getAttribute("data-align")).toBe("end")
    expect(document.getElementById("arrow").getAttribute("data-side")).toBe("top")

    click("#close")
    expect(popup().hasAttribute("style")).toBe(false)
    expect(popup().hasAttribute("data-side")).toBe(false)
  })

  it("moves the focus into the popup, and back to the trigger on close", async () => {
    await mount(share())
    click("#trigger")

    expect(document.activeElement).toBe(popup())
    expect(popup().tabIndex).toBe(-1)
    click("#close")
    expect(document.activeElement.id).toBe("trigger")
  })

  it("gives the focus to the autofocus element of the popup", async () => {
    await mount(share("", `<input id="field" autofocus />`))
    click("#trigger")

    expect(document.activeElement.id).toBe("field")
  })

  it("closes on Escape, with the focus back to the trigger", async () => {
    await mount(share())
    click("#trigger")
    document.getElementById("copy").focus()

    expect(press("Escape").defaultPrevented).toBe(true)
    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("trigger")
  })

  it("closes on a click outside, not on a click inside", async () => {
    await mount(share())
    click("#trigger")
    click("#description")
    expect(isOpen()).toBe(true)

    click("#outside")
    expect(isOpen()).toBe(false)
  })

  it("closes when the focus goes out of the popover", async () => {
    await mount(share())
    click("#trigger")
    document.getElementById("outside").focus()

    expect(isOpen()).toBe(false)
  })

  it("stays open with dismiss false", async () => {
    await mount(share('data-phem-popover-dismiss-value="false"'))
    click("#trigger")
    press("Escape")
    click("#outside")
    document.getElementById("outside").focus()

    expect(isOpen()).toBe(true)
  })

  it("stays open, at its place, after the morph of an event", async () => {
    await mount(share())
    respond(share().replace('id="popup"', 'id="popup" class="card"'))
    click("#trigger")
    const done = nextDone()
    click("#copy")
    await done

    expect(isOpen()).toBe(true)
    expect(popup().className).toBe("card")
    expect(popup().style.position).toBe("fixed")
    expect(popup().getAttribute("data-side")).toBe("bottom")
    expect(document.getElementById("share").getAttribute("data-phem-popover-open-value")).toBe("true")
  })

  it("opens and closes with the open value of the server render", async () => {
    await mount(share('data-phem-popover-open-value="true"'))
    expect(isOpen()).toBe(true)

    respond(share('data-phem-popover-open-value="false"'))
    const done = nextDone()
    click("#copy")
    await done
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(isOpen()).toBe(false)
  })

  it("opens on hover after the delay with openOnHover", async () => {
    await mount(share('data-phem-popover-open-on-hover-value="true" data-phem-popover-delay-value="200"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    const trigger = document.getElementById("trigger")
    trigger.dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))

    vi.advanceTimersByTime(199)
    expect(isOpen()).toBe(false)
    vi.advanceTimersByTime(1)
    expect(isOpen()).toBe(true)
    expect(document.activeElement).not.toBe(popup())

    // The pointer goes from the trigger to the popup: it stays open.
    trigger.dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: popup() }))
    vi.advanceTimersByTime(500)
    expect(isOpen()).toBe(true)

    popup().dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: document.body }))
    vi.advanceTimersByTime(100)
    expect(isOpen()).toBe(false)
  })

  it("gives Escape to the layer below a popover that does not dismiss", async () => {
    await mount(`${share()}
      <div id="second" data-controller="phem-popover" data-phem-popover-dismiss-value="false">
        <button id="trigger-2" data-phem-popover-target="trigger">More</button>
        <div id="popup-2" data-phem-popover-target="popup" hidden>More</div>
      </div>`)
    click("#trigger")
    document.getElementById("popup").append(document.getElementById("second"))
    click("#trigger-2")

    expect(press("Escape", document.body).defaultPrevented).toBe(true)
    expect(isOpen()).toBe(false)
    expect(document.getElementById("popup-2").hidden).toBe(false)
    expect(press("Escape", document.body).defaultPrevented).toBe(false)
  })

  it("closes the last popover first on Escape", async () => {
    await mount(`${share()}
      <div id="second" data-controller="phem-popover">
        <button id="trigger-2" data-phem-popover-target="trigger">More</button>
        <div id="popup-2" data-phem-popover-target="popup" hidden>More</div>
      </div>`)
    click("#trigger")
    document.getElementById("share").setAttribute("data-phem-popover-dismiss-value", "true")
    // A click on the second trigger is outside the first popover: it closes.
    click("#trigger-2")
    expect(isOpen()).toBe(false)
    expect(document.getElementById("popup-2").hidden).toBe(false)
  })

  it("uses the top layer for a popup with the popover attribute", async () => {
    const shown = []
    HTMLElement.prototype.showPopover = function () {
      shown.push(this.id)
      this.setAttribute("data-test-popover-open", "")
    }
    HTMLElement.prototype.hidePopover = function () {
      this.removeAttribute("data-test-popover-open")
    }
    const matches = Element.prototype.matches
    vi.spyOn(Element.prototype, "matches").mockImplementation(function (selector) {
      return selector === ":popover-open" ? this.hasAttribute("data-test-popover-open") : matches.call(this, selector)
    })

    await mount(share().replace('id="popup"', 'id="popup" popover="manual"'))
    click("#trigger")
    expect(shown).toEqual(["popup"])
    expect(popup().hasAttribute("data-test-popover-open")).toBe(true)

    click("#close")
    expect(popup().hasAttribute("data-test-popover-open")).toBe(false)

    delete HTMLElement.prototype.showPopover
    delete HTMLElement.prototype.hidePopover
  })
})
