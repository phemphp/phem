import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemDialogController from "../dist/phem_dialog_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

// jsdom has no showModal() and no top layer: `modal` marks a dialog in the top layer, and the
// `close` event comes in a later task, as in a browser.
beforeAll(() => {
  const proto = HTMLDialogElement.prototype
  const matches = Element.prototype.matches

  proto.showModal = function () {
    if (this.open) throw new DOMException("The dialog is open", "InvalidStateError")

    this.modal = true
    this.setAttribute("open", "")
  }
  proto.close = function () {
    if (!this.open) return

    this.modal = false
    this.removeAttribute("open")
    setTimeout(() => this.dispatchEvent(new Event("close")), 0)
  }
  proto.matches = function (selector) {
    return ":modal" === selector ? this.open && !!this.modal : matches.call(this, selector)
  }

  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-dialog", PhemDialogController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A dialog with a "Save" button that sends an event, a "Close" button, and the `attributes` of the
// controller element. The server render always gives a closed dialog.
function edit(attributes = "", content = "") {
  return `<div id="edit" data-controller="phem-dialog" ${attributes}>
      <button id="open" data-phem-dialog-target="trigger">Edit</button>
      <dialog id="dialog" data-phem-dialog-target="popup">
        <h2 id="title" data-phem-dialog-target="title">Edit</h2>
        <p id="body" data-phem-dialog-target="description">Body</p>
        <button id="save" data-phem-on-click="c1.save">Save</button>
        <button id="close" data-phem-dialog-target="close">Close</button>
        ${content}
      </dialog>
    </div>`
}

function dialog() {
  return document.getElementById("dialog")
}

function isModal() {
  return dialog().matches(":modal")
}

// The `close` event of the dialog.
function nextTask() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

// Escape: the browser sends `cancel`, and closes the dialog when no listener prevents it.
function escape() {
  const event = new Event("cancel", { cancelable: true })
  dialog().dispatchEvent(event)
  if (!event.defaultPrevented) dialog().close()
}

// A press on `from` and a click on `to`, at the points of the viewport. A browser gives the click
// to the common ancestor when the press and the release are on different elements.
function clickAt(from, [x1, y1], to = from, [x2, y2] = [x1, y1]) {
  from.dispatchEvent(new MouseEvent("pointerdown", { bubbles: true, cancelable: true, clientX: x1, clientY: y1 }))
  to.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, clientX: x2, clientY: y2 }))
}

describe("phem-dialog", () => {
  it("opens and closes the dialog as a modal", async () => {
    await mount(edit())

    expect(isModal()).toBe(false)
    click("#open")
    expect(isModal()).toBe(true)
    click("#close")
    expect(dialog().open).toBe(false)
  })

  it("sets the data attributes and the ARIA attributes of the parts", async () => {
    await mount(edit())
    const trigger = document.getElementById("open")

    expect(dialog().hasAttribute("data-closed")).toBe(true)
    expect(dialog().getAttribute("aria-labelledby")).toBe("title")
    expect(dialog().getAttribute("aria-describedby")).toBe("body")
    expect(trigger.getAttribute("aria-haspopup")).toBe("dialog")
    expect(trigger.getAttribute("aria-expanded")).toBe("false")

    click("#open")
    expect(dialog().hasAttribute("data-open")).toBe(true)
    expect(dialog().hasAttribute("data-closed")).toBe(false)
    expect(trigger.hasAttribute("data-popup-open")).toBe(true)
    expect(trigger.getAttribute("aria-expanded")).toBe("true")
  })

  it("opens and closes with the open and close actions", async () => {
    await mount(`<div data-controller="phem-dialog">
        <button id="open" data-action="phem-dialog#open">Edit</button>
        <dialog id="dialog" data-phem-dialog-target="popup"><button id="close" data-action="phem-dialog#close">Close</button></dialog>
      </div>`)
    click("#open")
    expect(isModal()).toBe(true)
    click("#close")
    expect(dialog().open).toBe(false)
  })

  it("closes after the animation of data-ending-style", async () => {
    await mount(edit())
    click("#open")
    let finish
    dialog().getAnimations = () => [{ finished: new Promise((resolve) => (finish = resolve)) }]
    click("#close")

    expect(dialog().open).toBe(true)
    expect(dialog().hasAttribute("data-ending-style")).toBe(true)
    finish()
    await nextTask()
    expect(dialog().open).toBe(false)
    expect(dialog().hasAttribute("data-ending-style")).toBe(false)
    expect(dialog().hidden).toBe(false)
  })

  it("marks a dialog in a dialog", async () => {
    await mount(`<div data-controller="phem-dialog">
        <dialog id="outer" data-phem-dialog-target="popup">
          <div data-controller="phem-dialog">
            <button id="inner-open" data-phem-dialog-target="trigger">More</button>
            <dialog id="inner" data-phem-dialog-target="popup"><button id="inner-close" data-phem-dialog-target="close">Close</button></dialog>
          </div>
        </dialog>
      </div>`)
    document.getElementById("outer").showModal()
    click("#inner-open")

    const outer = document.getElementById("outer")
    expect(document.getElementById("inner").hasAttribute("data-nested")).toBe(true)
    expect(outer.hasAttribute("data-nested-dialog-open")).toBe(true)
    expect(outer.style.getPropertyValue("--nested-dialogs")).toBe("1")

    click("#inner-close")
    await nextTask()
    expect(outer.hasAttribute("data-nested-dialog-open")).toBe(false)
    expect(outer.style.getPropertyValue("--nested-dialogs")).toBe("0")
  })

  it("stays open after the morph of an event", async () => {
    await mount(edit())
    respond(edit("", `<p id="new">New</p>`))
    click("#open")
    const done = nextDone()
    click("#save")
    await done
    await nextTask()

    expect(isModal()).toBe(true)
    expect(document.getElementById("new")).not.toBeNull()
    expect(document.getElementById("edit").getAttribute("data-phem-dialog-open-value")).toBe("true")
  })

  it("shows the dialog as a modal again when the morph took it out of the top layer", async () => {
    await mount(edit())
    respond(edit())
    click("#open")
    dialog().modal = false
    const done = nextDone()
    click("#save")
    await done
    await nextTask()

    expect(isModal()).toBe(true)
  })

  it("opens with the open value of the server render", async () => {
    await mount(edit('data-phem-dialog-open-value="true"'))

    expect(isModal()).toBe(true)
  })

  it("shows a dialog with the open attribute of the server render as a modal", async () => {
    await mount(edit('data-phem-dialog-open-value="true"').replace('id="dialog"', 'id="dialog" open'))

    expect(isModal()).toBe(true)
  })

  it("opens and closes with the open value of a new server render", async () => {
    await mount(edit())
    respond(edit('data-phem-dialog-open-value="false"'))
    click("#open")
    const done = nextDone()
    click("#save")
    await done
    await nextTask()

    expect(dialog().open).toBe(false)
  })

  it("closes on Escape", async () => {
    await mount(edit())
    click("#open")
    escape()
    await nextTask()

    expect(dialog().open).toBe(false)
    expect(document.getElementById("edit").getAttribute("data-phem-dialog-open-value")).toBe("false")
  })

  it("sets the state when the dialog closes in another way", async () => {
    await mount(edit())
    click("#open")
    dialog().close()
    await nextTask()
    click("#open")

    expect(isModal()).toBe(true)
  })

  it("closes on a tap on the backdrop, not on a click in the dialog", async () => {
    await mount(edit())
    click("#open")
    clickAt(document.getElementById("body"), [500, 500])
    clickAt(dialog(), [0, 0])

    expect(isModal()).toBe(true)
    clickAt(dialog(), [500, 500])
    expect(dialog().open).toBe(false)
  })

  it("stays open when a press in the dialog ends on the backdrop", async () => {
    await mount(edit())
    click("#open")
    vi.spyOn(dialog(), "getBoundingClientRect").mockReturnValue(new DOMRect(100, 100, 200, 200))

    // A text selection from the body to the backdrop: the click goes to the dialog.
    clickAt(document.getElementById("body"), [150, 150], dialog(), [150, 50])
    // A click with no press, from a script.
    dialog().dispatchEvent(new MouseEvent("click", { bubbles: true, clientX: 150, clientY: 50 }))

    expect(isModal()).toBe(true)
  })

  it("does not close on Escape or a click on the backdrop with dismiss false", async () => {
    await mount(edit('data-phem-dialog-dismiss-value="false"'))
    click("#open")
    escape()
    clickAt(dialog(), [500, 500])
    await nextTask()

    expect(isModal()).toBe(true)
  })

  it("keeps the state of a dialog that is open again before its close event", async () => {
    await mount(edit())
    click("#open")
    click("#close")
    click("#open")
    await nextTask()

    expect(isModal()).toBe(true)
  })

  describe("a drawer", () => {
    // The dialog is a box of 300 x 400 px at (0, 400).
    async function drawer(attributes = 'data-phem-dialog-swipe-direction-value="down"', content = "") {
      await mount(edit(attributes, content))
      $("dialog").getBoundingClientRect = () => ({ left: 0, top: 400, right: 300, bottom: 800, width: 300, height: 400 })
      click("#open")
    }

    let time = 0

    function pointer(type, x, y, target = document) {
      const event = new PointerEvent(type, { bubbles: true, cancelable: true, isPrimary: true, button: 0, pointerId: 1, clientX: x, clientY: y })
      Object.defineProperty(event, "timeStamp", { value: (time += 1000) })
      target.dispatchEvent(event)

      return event
    }

    const $ = (id) => document.getElementById(id)

    it("follows a swipe in its direction, and closes after half its size", async () => {
      await drawer()
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 600)

      expect($("dialog").hasAttribute("data-swiping")).toBe(true)
      expect($("dialog").style.getPropertyValue("--dialog-swipe-movement-y")).toBe("100px")
      expect($("dialog").style.getPropertyValue("--dialog-swipe-progress")).toBe("0.25")

      pointer("pointermove", 100, 700)
      pointer("pointerup", 100, 700)
      expect($("dialog").hasAttribute("data-swiping")).toBe(false)
      expect($("edit").getAttribute("data-phem-dialog-open-value")).toBe("false")
      expect($("dialog").open).toBe(false)
      expect($("dialog").hasAttribute("data-swipe-direction")).toBe(false)
    })

    it("keeps its place and the direction of the swipe during its close animation", async () => {
      await drawer()
      let finish
      $("dialog").getAnimations = () => [{ finished: new Promise((resolve) => (finish = resolve)) }]
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 700)
      pointer("pointerup", 100, 700)

      expect($("dialog").getAttribute("data-swipe-direction")).toBe("down")
      expect($("dialog").hasAttribute("data-ending-style")).toBe(true)
      expect($("dialog").style.getPropertyValue("--dialog-swipe-movement-y")).toBe("200px")

      finish()
      await new Promise((resolve) => setTimeout(resolve, 0))
      expect($("dialog").open).toBe(false)
      expect($("dialog").hasAttribute("data-swipe-direction")).toBe(false)
    })

    it("goes back to its place after a short and slow swipe", async () => {
      await drawer()
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 600)
      pointer("pointerup", 100, 600)

      expect($("dialog").open).toBe(true)
      expect($("dialog").hasAttribute("data-swiping")).toBe(false)
      expect($("dialog").style.getPropertyValue("--dialog-swipe-movement-y")).toBe("")
    })

    it("closes after a short and fast swipe", async () => {
      await drawer()
      pointer("pointerdown", 100, 500, $("title"))
      const move = new PointerEvent("pointermove", { bubbles: true, isPrimary: true, pointerId: 1, clientX: 100, clientY: 560 })
      Object.defineProperty(move, "timeStamp", { value: time + 50 })
      document.dispatchEvent(move)
      const up = new PointerEvent("pointerup", { bubbles: true, isPrimary: true, pointerId: 1, clientX: 100, clientY: 560 })
      Object.defineProperty(up, "timeStamp", { value: time + 60 })
      document.dispatchEvent(up)

      expect($("dialog").open).toBe(false)
    })

    it("resists a swipe in the other direction", async () => {
      await drawer()
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 400)
      expect($("dialog").hasAttribute("data-swiping")).toBe(true)
      expect($("dialog").style.getPropertyValue("--dialog-swipe-movement-y")).toBe("-10px")
      expect($("dialog").style.getPropertyValue("--dialog-swipe-progress")).toBe("0")
      pointer("pointerup", 100, 400)
      expect($("dialog").open).toBe(true)
    })

    it("does not swipe from a button, from the backdrop or with no swipe direction", async () => {
      await drawer()
      pointer("pointerdown", 100, 500, $("save"))
      pointer("pointermove", 100, 800)
      pointer("pointerup", 100, 800)
      expect($("dialog").open).toBe(true)

      pointer("pointerdown", 100, 100, $("dialog"))
      pointer("pointermove", 100, 800)
      expect($("dialog").hasAttribute("data-swiping")).toBe(false)
      pointer("pointerup", 100, 800)

      document.body.innerHTML = ""
      await drawer("")
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 800)
      pointer("pointerup", 100, 800)
      expect($("dialog").open).toBe(true)
    })

    it("does not swipe down from a list that is not at its top", async () => {
      await drawer('data-phem-dialog-swipe-direction-value="down"', `<ul id="list" style="overflow-y: auto"><li id="row">Row</li></ul>`)
      Object.defineProperty($("list"), "scrollTop", { value: 20, configurable: true })
      pointer("pointerdown", 100, 500, $("row"))
      pointer("pointermove", 100, 800)
      pointer("pointerup", 100, 800)
      expect($("dialog").open).toBe(true)

      // At its top, the list swipes.
      Object.defineProperty($("list"), "scrollTop", { value: 0, configurable: true })
      pointer("pointerdown", 100, 500, $("row"))
      pointer("pointermove", 100, 800)
      pointer("pointerup", 100, 800)
      expect($("dialog").open).toBe(false)
    })

    it("swipes up from content with a visible overflow", async () => {
      await drawer('data-phem-dialog-swipe-direction-value="up"', `<div id="header"><span id="icon">Icon</span></div>`)
      Object.defineProperty($("header"), "scrollHeight", { value: 30 })
      Object.defineProperty($("header"), "clientHeight", { value: 20 })
      pointer("pointerdown", 100, 700, $("icon"))
      pointer("pointermove", 100, 400)
      pointer("pointerup", 100, 400)

      expect($("dialog").open).toBe(false)
    })

    it("stops the scroll of a touch that swipes, and not of a touch that scrolls", async () => {
      await drawer()
      const touchmove = (y) => {
        const event = new Event("touchmove", { bubbles: true, cancelable: true })
        Object.defineProperty(event, "touches", { value: [{ clientX: 100, clientY: y }] })
        document.dispatchEvent(event)

        return event
      }
      const down = new PointerEvent("pointerdown", { bubbles: true, isPrimary: true, button: 0, pointerId: 1, pointerType: "touch", clientX: 100, clientY: 500 })
      $("title").dispatchEvent(down)

      expect(touchmove(480).defaultPrevented).toBe(false)
      expect(touchmove(520).defaultPrevented).toBe(true)
      document.dispatchEvent(new PointerEvent("pointercancel", { bubbles: true, pointerId: 1 }))
      expect(touchmove(560).defaultPrevented).toBe(false)
    })

    it("clears the swipe when it opens again", async () => {
      await drawer()
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 700)
      pointer("pointerup", 100, 700)
      await new Promise((resolve) => setTimeout(resolve, 0))
      click("#open")

      expect($("dialog").open).toBe(true)
      expect($("dialog").hasAttribute("data-swipe-direction")).toBe(false)
      expect($("dialog").style.getPropertyValue("--dialog-swipe-movement-y")).toBe("")
    })
  })
})
