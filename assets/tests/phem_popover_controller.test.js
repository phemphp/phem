import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemPopoverController from "../dist/phem_popover_controller.js"
import { click, mount, nextDone, respond, stubElementReflection } from "./support.js"

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

  it("marks the trigger as pressed while the popup is open", async () => {
    await mount(share())
    click("#trigger")
    expect(document.getElementById("trigger").hasAttribute("data-pressed")).toBe(true)

    click("#trigger")
    expect(document.getElementById("trigger").hasAttribute("data-pressed")).toBe(false)
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

  it("links a popup, a title and a description with no id with element references, also after the morph", async () => {
    const restore = stubElementReflection()
    try {
      const markup = share().replace('id="popup" ', "").replace('id="title" ', "").replace('id="description" ', "")
      await mount(markup)
      respond(markup)
      const part = (name) => document.querySelector(`[data-phem-popover-target=${name}]`)
      const trigger = document.getElementById("trigger")

      expect(part("popup").ariaLabelledByElements).toEqual([part("title")])
      expect(part("popup").ariaDescribedByElements).toEqual([part("description")])
      expect(trigger.ariaControlsElements).toEqual([part("popup")])

      click("#trigger")
      const done = nextDone()
      click("#copy")
      await done

      expect(part("title").id).toBe("")
      expect(part("popup").ariaLabelledByElements).toEqual([part("title")])
      expect(part("popup").ariaDescribedByElements).toEqual([part("description")])
      expect(trigger.ariaControlsElements).toEqual([part("popup")])
    } finally {
      restore()
    }
  })

  it("keeps the aria-label of the popup that the markup gives", async () => {
    await mount(share().replace('id="popup"', 'id="popup" aria-label="Share options"'))

    expect(popup().getAttribute("aria-label")).toBe("Share options")
    expect(popup().hasAttribute("aria-labelledby")).toBe(false)
    expect(popup().getAttribute("aria-describedby")).toBe("description")
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

  it("moves the focus to the first element that Tab goes to, and back to the trigger on close", async () => {
    await mount(share())
    click("#trigger")

    expect(document.activeElement.id).toBe("copy")
    click("#close")
    expect(document.activeElement.id).toBe("trigger")
  })

  it("moves the focus to the popup when it has no element that Tab goes to", async () => {
    await mount(`<div data-controller="phem-popover">
        <button id="trigger" data-phem-popover-target="trigger">Info</button>
        <div id="popup" data-phem-popover-target="popup" hidden><p>Text</p><button disabled>Off</button></div>
      </div>`)
    click("#trigger")

    expect(document.activeElement).toBe(popup())
    expect(popup().tabIndex).toBe(-1)
  })

  it("moves the focus to the popup after an open with a touch", async () => {
    await mount(share())
    const trigger = document.getElementById("trigger")
    trigger.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, pointerType: "touch" }))
    trigger.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1 }))

    expect(document.activeElement).toBe(popup())

    // A press of the mouse: the first element gets the focus again.
    click("#close")
    trigger.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, pointerType: "mouse" }))
    trigger.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1 }))
    expect(document.activeElement.id).toBe("copy")
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

  it("gives the focus back to the trigger after a press outside that leaves the focus on the body", async () => {
    await mount(share() + `<p id="away">Away</p>`)
    click("#trigger")
    expect(document.activeElement.id).toBe("copy")

    // A press on an element that does not take the focus: the focus goes to the body before the click.
    document.activeElement.blur()
    expect(document.activeElement).toBe(document.body)
    click("#away")

    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("trigger")
  })

  it("keeps the focus on an element outside that took it at the press", async () => {
    await mount(share() + `<button id="away">Away</button>`)
    click("#trigger")

    // A press on a button: the button takes the focus before the click.
    document.getElementById("away").focus()
    click("#away")

    expect(isOpen()).toBe(false)
    expect(document.activeElement.id).toBe("away")
  })

  it("gives the focus back to the trigger with preventScroll", async () => {
    await mount(share() + `<p id="away">Away</p>`)
    click("#trigger")
    const focus = vi.spyOn(document.getElementById("trigger"), "focus")

    document.activeElement.blur()
    click("#away")

    expect(focus).toHaveBeenCalledWith({ preventScroll: true })
  })

  it("keeps the focus where it is after a press outside a popup that the hover opened", async () => {
    await mount(share('data-phem-popover-open-on-hover-value="true" data-phem-popover-delay-value="0"') + `<p id="away">Away</p>`)
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    document.getElementById("trigger").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
    vi.advanceTimersByTime(0)
    expect(isOpen()).toBe(true)
    expect(document.activeElement).toBe(document.body)

    click("#away")

    expect(isOpen()).toBe(false)
    expect(document.activeElement).toBe(document.body)
    vi.useRealTimers()
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

  describe("a preview card", () => {
    const card = (attributes = "") => share(`data-phem-popover-open-on-hover-value="true" data-phem-popover-delay-value="600" data-phem-popover-close-delay-value="300" ${attributes}`)
      .replace('<button id="trigger" data-phem-popover-target="trigger">Share</button>', '<a id="trigger" href="#profile" data-phem-popover-target="trigger">Ada</a>')
    const shown = () => popup().hasAttribute("data-open")

    it("keeps the link as the only part for the keyboard and a screen reader", async () => {
      await mount(card())
      const trigger = document.getElementById("trigger")

      expect(trigger.hasAttribute("aria-haspopup")).toBe(false)
      expect(trigger.hasAttribute("aria-expanded")).toBe(false)
      expect(popup().hasAttribute("role")).toBe(false)
      expect(document.getElementById("copy").tabIndex).toBe(-1)
    })

    it("keeps the popup in the accessibility tree: no aria-hidden, also after the morph", async () => {
      await mount(card())
      expect(popup().hasAttribute("aria-hidden")).toBe(false)
      expect(document.getElementById("copy").tabIndex).toBe(-1)

      respond(card())
      const done = nextDone()
      document.getElementById("copy").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }))
      await done

      expect(popup().hasAttribute("aria-hidden")).toBe(false)
      expect(document.getElementById("copy").tabIndex).toBe(-1)
    })

    it("keeps the aria-hidden of the server render, also after the morph", async () => {
      const markup = card().replace('<div id="popup" ', '<div id="popup" aria-hidden="true" ')
      await mount(markup)
      expect(popup().getAttribute("aria-hidden")).toBe("true")

      respond(markup)
      const done = nextDone()
      document.getElementById("copy").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }))
      await done

      expect(popup().getAttribute("aria-hidden")).toBe("true")
    })

    it("animates a popup that the keyboard focus opens: data-instant does not stop the animation", async () => {
      await mount(card())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      const trigger = document.getElementById("trigger")
      const matches = trigger.matches.bind(trigger)
      trigger.matches = (selector) => selector === ":focus-visible" || matches(selector)
      trigger.focus()
      vi.advanceTimersByTime(600)

      expect(shown()).toBe(true)
      expect(popup().getAttribute("data-instant")).toBe("focus")
      expect(popup().hasAttribute("data-starting-style")).toBe(true)
      vi.useRealTimers()
    })

    it("does not open on a click on the link", async () => {
      await mount(card())
      document.getElementById("trigger").addEventListener("click", (event) => event.preventDefault())
      click("#trigger")

      expect(shown()).toBe(false)
    })

    it("opens after the delay on the keyboard focus, and closes at once on the focus out, with data-instant", async () => {
      await mount(card())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      const trigger = document.getElementById("trigger")
      const matches = trigger.matches.bind(trigger)
      trigger.matches = (selector) => selector === ":focus-visible" || matches(selector)
      trigger.focus()

      vi.advanceTimersByTime(599)
      expect(shown()).toBe(false)
      vi.advanceTimersByTime(1)
      expect(shown()).toBe(true)
      expect(document.activeElement).toBe(trigger)
      expect(trigger.hasAttribute("data-popup-open")).toBe(true)
      expect(popup().getAttribute("data-instant")).toBe("focus")

      // The `closeDelay` value (300 ms) is for the pointer.
      document.getElementById("outside").focus()
      vi.advanceTimersByTime(0)
      expect(shown()).toBe(false)
      expect(popup().getAttribute("data-instant")).toBe("focus")
      vi.useRealTimers()
    })

    it("keeps the focus where it is on Escape after a hover open, and does not open again", async () => {
      await mount(card())
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      const outside = document.getElementById("outside")
      outside.focus()
      document.getElementById("trigger").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      vi.advanceTimersByTime(600)
      expect(shown()).toBe(true)

      press("Escape", outside)
      expect(shown()).toBe(false)
      expect(popup().getAttribute("data-instant")).toBe("dismiss")
      expect(document.activeElement).toBe(outside)

      vi.advanceTimersByTime(1000)
      expect(shown()).toBe(false)
      vi.useRealTimers()
    })

    it("gives a preview card no focus after a press outside", async () => {
      await mount(card() + `<p id="away">Away</p>`)
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      document.getElementById("trigger").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
      vi.advanceTimersByTime(600)
      expect(shown()).toBe(true)

      document.activeElement.blur()
      click("#away")

      expect(shown()).toBe(false)
      expect(document.activeElement).toBe(document.body)
      vi.useRealTimers()
    })
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

  it("closes at once with a closeDelay of 0, and stays open in the space between the trigger and the popup", async () => {
    await mount(share('data-phem-popover-open-on-hover-value="true" data-phem-popover-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    const trigger = document.getElementById("trigger")
    // The popup is 8 px below the trigger.
    trigger.getBoundingClientRect = () => ({ left: 0, top: 0, right: 100, bottom: 20, width: 100, height: 20 })
    popup().getBoundingClientRect = () => ({ left: 0, top: 28, right: 200, bottom: 128, width: 200, height: 100 })
    const move = (type, target, x, y, to = document.body) => target.dispatchEvent(new PointerEvent(type, { bubbles: true, pointerType: "mouse", clientX: x, clientY: y, relatedTarget: to }))

    move("pointerover", trigger, 50, 10)
    vi.advanceTimersByTime(0)
    expect(isOpen()).toBe(true)

    // The pointer is in the gap between the trigger and the popup.
    move("pointerout", trigger, 50, 24)
    vi.advanceTimersByTime(1000)
    expect(isOpen()).toBe(true)
    move("pointermove", document.body, 150, 26)
    vi.advanceTimersByTime(1000)
    expect(isOpen()).toBe(true)

    // It goes out of that space: the popup closes with no delay.
    move("pointermove", document.body, 300, 26)
    vi.advanceTimersByTime(0)
    expect(isOpen()).toBe(false)

    // A diagonal path from the side of the trigger to the popup is in the triangle from the point
    // where the pointer left the trigger.
    move("pointerover", trigger, 50, 10)
    vi.advanceTimersByTime(0)
    move("pointerout", trigger, 102, 15)
    move("pointermove", document.body, 130, 20)
    vi.advanceTimersByTime(1000)
    expect(isOpen()).toBe(true)

    // A path away from the popup is out of the triangle: the popup closes.
    move("pointermove", document.body, 160, 12)
    vi.advanceTimersByTime(0)
    expect(isOpen()).toBe(false)

    // Out of the trigger on its other side: the popup closes at once.
    move("pointerover", trigger, 50, 10)
    vi.advanceTimersByTime(0)
    move("pointerout", trigger, 50, -5)
    vi.advanceTimersByTime(0)
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

  it("gives the popup data-starting-style before it goes to the top layer", async () => {
    // The browser computes the styles of the popup in showPopover(): the popup must have its
    // starting styles then, or the open animation does not run.
    const states = []
    HTMLElement.prototype.showPopover = function () {
      states.push([this.hasAttribute("data-open"), this.hasAttribute("data-starting-style"), this.hasAttribute("data-closed")])
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
    expect(states).toEqual([[true, true, false]])

    await new Promise((resolve) => requestAnimationFrame(resolve))
    expect(popup().hasAttribute("data-starting-style")).toBe(false)

    delete HTMLElement.prototype.showPopover
    delete HTMLElement.prototype.hidePopover
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

// Three detached triggers of one popover: two with a template payload, one with a text payload.
function panels(attributes = "") {
  return `<div id="bar">
      <button id="t1" data-phem-popover-for="panels" data-phem-popover-payload="notifications">Notifications</button>
      <button id="t2" data-phem-popover-for="panels" data-phem-popover-payload="activity">Activity</button>
      <button id="t3" data-phem-popover-for="panels" data-phem-popover-payload="Plain text">Text</button>
    </div>
    <div id="panels" data-controller="phem-popover" ${attributes}>
      <div id="panels-popup" data-phem-popover-target="popup" hidden>
        <div id="vp" data-phem-popover-target="viewport">
          <template data-payload="notifications"><h2 data-phem-popover-target="title">Notifications</h2></template>
          <template data-payload="activity"><h2 data-phem-popover-target="title">Activity</h2><a id="link" href="#">Log</a></template>
        </div>
      </div>
    </div>
    <p id="outside">Outside</p>`
}

describe("phem-popover with several triggers", () => {
  const $ = (id) => document.getElementById(id)
  const current = () => $("vp").querySelector(":scope > [data-current]")

  it("opens next to a detached trigger, with a copy of the template of its payload", async () => {
    await mount(panels())
    $("t2").click()

    expect($("panels-popup").hidden).toBe(false)
    expect(current().querySelector("h2").textContent).toBe("Activity")
    expect($("t2").getAttribute("aria-expanded")).toBe("true")
    expect($("t2").hasAttribute("data-popup-open")).toBe(true)
    expect($("t1").getAttribute("aria-expanded")).toBe("false")
    expect($("t1").getAttribute("aria-haspopup")).toBe("dialog")
    expect($("panels").getAttribute("data-phem-popover-trigger-id-value")).toBe("t2")
    // The templates stay in the viewport for the next trigger.
    expect($("vp").querySelectorAll("template")).toHaveLength(2)
    // The focus goes to the first element of the content.
    expect(document.activeElement).toBe(current().querySelector("a"))
  })

  it("moves to another trigger at a click while it is open, and closes at a click on the same trigger", async () => {
    await mount(panels())
    $("t1").getBoundingClientRect = () => new DOMRect(0, 0, 80, 32)
    $("t3").getBoundingClientRect = () => new DOMRect(160, 0, 80, 32)
    $("t1").click()
    $("t3").click()

    expect($("panels-popup").hidden).toBe(false)
    expect($("vp").getAttribute("data-activation-direction")).toBe("right")
    expect(current().textContent).toBe("Plain text")
    expect($("t3").getAttribute("aria-expanded")).toBe("true")
    expect($("t1").getAttribute("aria-expanded")).toBe("false")

    $("t3").click()
    expect($("panels").getAttribute("data-phem-popover-open-value")).toBe("false")
    expect($("t3").getAttribute("aria-expanded")).toBe("false")
  })

  it("closes at a click outside, but not at a click on a detached trigger", async () => {
    await mount(panels())
    $("t1").click()
    click("#outside")

    expect($("panels").getAttribute("data-phem-popover-open-value")).toBe("false")
  })

  it("opens next to the trigger of the triggerId value", async () => {
    await mount(panels('data-phem-popover-open-value="true" data-phem-popover-trigger-id-value="t2"'))

    expect($("panels-popup").hidden).toBe(false)
    expect($("t2").getAttribute("aria-expanded")).toBe("true")

    $("panels").setAttribute("data-phem-popover-trigger-id-value", "t1")
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect($("t1").getAttribute("aria-expanded")).toBe("true")
    expect(current().querySelector("h2").textContent).toBe("Notifications")
  })

  it("moves on hover to another trigger with openOnHover", async () => {
    await mount(panels('data-phem-popover-open-on-hover-value="true" data-phem-popover-delay-value="0"'))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    const over = (el) => el.dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))
    over($("t1"))
    vi.advanceTimersByTime(0)
    expect($("t1").getAttribute("aria-expanded")).toBe("true")

    over($("t2"))
    expect($("t2").getAttribute("aria-expanded")).toBe("true")
    expect(current().querySelector("h2").textContent).toBe("Activity")
  })

  it("opens with the open action and a triggerId param", async () => {
    await mount(`<div data-controller="phem-popover">
        <button id="a" data-phem-popover-target="trigger">A</button>
        <button id="b" data-phem-popover-target="trigger">B</button>
        <button id="go" data-action="phem-popover#open" data-phem-popover-trigger-id-param="b">Open</button>
        <div id="p" data-phem-popover-target="popup" hidden>Popup</div>
      </div>`)
    $("go").click()

    expect($("p").hidden).toBe(false)
    expect($("b").getAttribute("aria-expanded")).toBe("true")
    expect($("a").getAttribute("aria-expanded")).toBe("false")
  })
})

describe("a preview card with several links", () => {
  const $ = (id) => document.getElementById(id)
  const card = () => `<p>
      <a id="l1" href="#a" data-phem-popover-for="cards" data-phem-popover-payload="a">A</a>
      <a id="l2" href="#b" data-phem-popover-for="cards" data-phem-popover-payload="b">B</a>
      <button id="open" type="button">Open</button>
    </p>
    <div id="cards" data-controller="phem-popover" data-phem-popover-open-on-hover-value="true" data-phem-popover-delay-value="600">
      <button id="go" type="button" data-action="phem-popover#open" data-phem-popover-trigger-id-param="l2">Open</button>
      <div id="card" data-phem-popover-target="popup" hidden>
        <div id="vp" data-phem-popover-target="viewport"><template data-payload="a">Card A</template><template data-payload="b">Card B</template></div>
      </div>
    </div>`
  const focusVisible = (el) => {
    const matches = el.matches.bind(el)
    el.matches = (selector) => selector === ":focus-visible" || matches(selector)
  }

  it("moves the open card at once when the keyboard focus goes to the next link", async () => {
    await mount(card())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    focusVisible($("l1"))
    focusVisible($("l2"))
    $("l1").focus()
    vi.advanceTimersByTime(600)
    expect($("vp").querySelector("[data-current]").textContent).toBe("Card A")

    $("l2").focus()
    vi.advanceTimersByTime(0)
    expect($("card").hasAttribute("data-open")).toBe(true)
    expect($("l2").hasAttribute("data-popup-open")).toBe(true)
    expect($("vp").querySelector("[data-current]").textContent).toBe("Card B")
    // The link stays the only part for the keyboard.
    expect($("l2").hasAttribute("aria-expanded")).toBe(false)
  })

  it("opens with the open action next to the link of the param, and keeps the focus", async () => {
    await mount(card())
    $("go").focus()
    $("go").click()

    expect($("card").hasAttribute("data-open")).toBe(true)
    expect($("l2").hasAttribute("data-popup-open")).toBe(true)
    expect(document.activeElement).toBe($("go"))
  })
})
