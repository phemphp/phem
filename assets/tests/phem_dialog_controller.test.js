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
  proto.show = function () {
    this.modal = false
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

  it("gives the dialog data-starting-style before it opens", async () => {
    // The browser computes the styles of the dialog in showModal(): the dialog must have its
    // starting styles then, or the open animation of the popup and of the backdrop does not run.
    await mount(edit())
    const states = []
    const showModal = HTMLDialogElement.prototype.showModal
    vi.spyOn(HTMLDialogElement.prototype, "showModal").mockImplementation(function () {
      states.push([this.hasAttribute("data-open"), this.hasAttribute("data-starting-style")])
      showModal.call(this)
    })
    click("#open")

    expect(states).toEqual([[true, true]])
    expect(isModal()).toBe(true)

    await new Promise((resolve) => requestAnimationFrame(resolve))
    expect(dialog().hasAttribute("data-starting-style")).toBe(false)
  })

  it("sets the data attributes and the ARIA attributes of the parts", async () => {
    await mount(edit())
    const trigger = document.getElementById("open")

    expect(dialog().hasAttribute("data-closed")).toBe(true)
    expect(dialog().getAttribute("aria-labelledby")).toBe("title")
    expect(dialog().getAttribute("aria-describedby")).toBe("body")
    expect(trigger.getAttribute("aria-haspopup")).toBe("dialog")
    expect(trigger.getAttribute("aria-expanded")).toBe("false")
    expect(trigger.hasAttribute("aria-controls")).toBe(false)

    click("#open")
    expect(dialog().hasAttribute("data-open")).toBe(true)
    expect(dialog().hasAttribute("data-closed")).toBe(false)
    expect(trigger.hasAttribute("data-popup-open")).toBe(true)
    expect(trigger.getAttribute("aria-expanded")).toBe("true")
    expect(trigger.getAttribute("aria-controls")).toBe("dialog")

    click("#close")
    expect(trigger.hasAttribute("aria-controls")).toBe(false)
  })

  it("keeps an aria-controls of the server render on a closed dialog", async () => {
    for (const given of ["help", "dialog"]) {
      await mount(edit().replace('data-phem-dialog-target="trigger"', `data-phem-dialog-target="trigger" aria-controls="${given}"`))
      const trigger = document.getElementById("open")

      click("#open")
      click("#close")
      expect(trigger.getAttribute("aria-controls")).toBe(given)
      document.body.innerHTML = ""
    }
  })

  it("takes an aria-controls that the morph gives to the trigger of an open dialog", async () => {
    await mount(edit())
    click("#open")
    const trigger = document.getElementById("open")
    expect(trigger.getAttribute("aria-controls")).toBe("dialog")

    // The server render of the morph gives another value: it stays after the close.
    trigger.setAttribute("aria-controls", "help")
    click("#close")
    click("#open")
    click("#close")
    expect(trigger.getAttribute("aria-controls")).toBe("help")
  })

  it("locks the scroll of the page while a modal dialog is open", async () => {
    await mount(edit())
    click("#open")
    expect(document.documentElement.style.overflow).toBe("hidden")

    click("#close")
    expect(document.documentElement.style.overflow).toBe("")
  })

  it("keeps the scroll lock until the end of the close animation", async () => {
    await mount(edit())
    click("#open")
    dialog().getAnimations = () => [{ finished: new Promise(() => {}) }]
    click("#close")

    expect(document.documentElement.style.overflow).toBe("hidden")
  })

  it("does not lock the scroll for a dialog that is not a modal", async () => {
    await mount(edit(`data-phem-dialog-modal-value="false"`))
    click("#open")

    expect(document.documentElement.style.overflow).toBe("")
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

  it("keeps the focus on a press on the backdrop, and not on a press in the dialog", async () => {
    await mount(edit())
    click("#open")
    const mousedown = (el, x) => {
      const event = new MouseEvent("mousedown", { bubbles: true, cancelable: true, clientX: x, clientY: x })
      el.dispatchEvent(event)

      return event
    }

    expect(mousedown(dialog(), 500).defaultPrevented).toBe(true)
    expect(mousedown(dialog(), 0).defaultPrevented).toBe(false)
    expect(mousedown(document.getElementById("save"), 500).defaultPrevented).toBe(false)
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
      expect($("dialog").style.getPropertyValue("--drawer-swipe-movement-y")).toBe("100px")
      expect($("dialog").style.getPropertyValue("--drawer-swipe-progress")).toBe("0.25")

      pointer("pointermove", 100, 700)
      pointer("pointerup", 100, 700)
      expect($("dialog").hasAttribute("data-swiping")).toBe(false)
      expect($("edit").getAttribute("data-phem-dialog-open-value")).toBe("false")
      expect($("dialog").open).toBe(false)
      expect($("dialog").getAttribute("data-swipe-direction")).toBe("down")
      expect($("dialog").hasAttribute("data-swipe-dismiss")).toBe(false)
    })

    it("does not start a swipe from a mouse press in a content target, or on an element with data-phem-swipe-ignore", async () => {
      await drawer(undefined, `<div id="content" data-phem-dialog-target="content">Text</div><div id="ignore" data-phem-swipe-ignore>Map</div>`)
      const mouse = new PointerEvent("pointerdown", { bubbles: true, cancelable: true, isPrimary: true, button: 0, pointerId: 1, pointerType: "mouse", clientX: 100, clientY: 500 })
      $("content").dispatchEvent(mouse)
      pointer("pointermove", 100, 600)
      expect($("dialog").hasAttribute("data-swiping")).toBe(false)
      pointer("pointerup", 100, 600)

      pointer("pointerdown", 100, 500, $("ignore"))
      pointer("pointermove", 100, 600)
      expect($("dialog").hasAttribute("data-swiping")).toBe(false)
      pointer("pointerup", 100, 600)

      // A touch in the content swipes.
      pointer("pointerdown", 100, 500, $("content"))
      pointer("pointermove", 100, 600)
      expect($("dialog").hasAttribute("data-swiping")).toBe(true)
    })

    it("keeps its place and the direction of the swipe during its close animation", async () => {
      await drawer()
      let finish
      $("dialog").getAnimations = () => [{ finished: new Promise((resolve) => (finish = resolve)) }]
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 700)
      pointer("pointerup", 100, 700)

      expect($("dialog").hasAttribute("data-swipe-dismiss")).toBe(true)
      expect($("dialog").getAttribute("data-swipe-direction")).toBe("down")
      expect($("dialog").hasAttribute("data-ending-style")).toBe(true)
      expect($("dialog").style.getPropertyValue("--drawer-swipe-movement-y")).toBe("200px")

      finish()
      await new Promise((resolve) => setTimeout(resolve, 0))
      expect($("dialog").open).toBe(false)
      expect($("dialog").hasAttribute("data-swipe-dismiss")).toBe(false)
    })

    it("goes back to its place after a short and slow swipe", async () => {
      await drawer()
      pointer("pointerdown", 100, 500, $("title"))
      pointer("pointermove", 100, 600)
      pointer("pointerup", 100, 600)

      expect($("dialog").open).toBe(true)
      expect($("dialog").hasAttribute("data-swiping")).toBe(false)
      expect($("dialog").style.getPropertyValue("--drawer-swipe-movement-y")).toBe("")
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
      expect($("dialog").style.getPropertyValue("--drawer-swipe-movement-y")).toBe("-10px")
      expect($("dialog").style.getPropertyValue("--drawer-swipe-progress")).toBe("0")
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
      expect($("dialog").hasAttribute("data-swipe-dismiss")).toBe(false)
      expect($("dialog").style.getPropertyValue("--drawer-swipe-movement-y")).toBe("")
    })

    it("stops at the snap point nearest to the release, and closes below the last one", async () => {
      vi.stubGlobal("innerHeight", 800)
      await drawer('data-phem-dialog-swipe-direction-value="down" data-phem-dialog-snap-points-value=\'["100px", 1]\'')
      const snaps = []
      $("edit").addEventListener("phem-dialog:snap", (event) => snaps.push(event.detail.snapPoint))

      // The first snap point: 100 px of the 400 px of the dialog.
      expect($("dialog").style.getPropertyValue("--drawer-snap-point-offset")).toBe("300px")
      expect($("dialog").style.getPropertyValue("--drawer-height")).toBe("400px")
      expect($("dialog").hasAttribute("data-expanded")).toBe(false)

      // A slow swipe up of 250 px: the full height.
      pointer("pointerdown", 100, 750, $("title"))
      pointer("pointermove", 100, 600)
      expect($("dialog").style.getPropertyValue("--drawer-swipe-movement-y")).toBe("-150px")
      pointer("pointermove", 100, 500)
      pointer("pointerup", 100, 500)
      expect(snaps).toEqual(["1"])
      expect($("edit").getAttribute("data-phem-dialog-snap-point-value")).toBe("1")
      expect($("dialog").style.getPropertyValue("--drawer-snap-point-offset")).toBe("0px")
      expect($("dialog").hasAttribute("data-expanded")).toBe(true)
      expect($("dialog").style.getPropertyValue("--drawer-swipe-movement-y")).toBe("")

      // A slow swipe down of 360 px: past the first snap point, nearer to the close.
      pointer("pointerdown", 100, 450, $("title"))
      pointer("pointermove", 100, 600)
      pointer("pointermove", 100, 810)
      pointer("pointerup", 100, 810)
      expect($("dialog").open).toBe(false)
      expect(snaps).toEqual(["1"])
    })

    it("lets a touch scroll the content up at the full size, and opens a lower snap point", async () => {
      vi.stubGlobal("innerHeight", 800)
      const touchmove = (y) => {
        const event = new Event("touchmove", { bubbles: true, cancelable: true })
        Object.defineProperty(event, "touches", { value: [{ clientX: 100, clientY: y }] })
        document.dispatchEvent(event)

        return event
      }
      const touch = (y) => $("title").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, isPrimary: true, button: 0, pointerId: 1, pointerType: "touch", clientX: 100, clientY: y }))
      const lift = () => document.dispatchEvent(new PointerEvent("pointercancel", { bubbles: true, pointerId: 1 }))

      await drawer('data-phem-dialog-swipe-direction-value="down" data-phem-dialog-snap-points-value=\'["100px", 1]\' data-phem-dialog-snap-point-value="1"')
      touch(500)
      expect(touchmove(480).defaultPrevented).toBe(false)
      lift()

      document.body.innerHTML = ""
      await drawer('data-phem-dialog-swipe-direction-value="down" data-phem-dialog-snap-points-value=\'["100px", 1]\'')
      touch(750)
      expect(touchmove(730).defaultPrevented).toBe(true)
      lift()
    })

    it("takes the style of the server after the morph, and keeps the snap point", async () => {
      vi.stubGlobal("innerHeight", 800)
      const attributes = 'data-phem-dialog-swipe-direction-value="down" data-phem-dialog-snap-points-value=\'["100px", 1]\''
      await drawer(attributes)
      expect($("dialog").style.getPropertyValue("--drawer-snap-point-offset")).toBe("300px")

      respond(edit(attributes).replace('id="dialog"', 'id="dialog" style="max-height: 90vh"'))
      const done = nextDone()
      click("#save")
      await done
      await nextTask()

      expect($("dialog").style.getPropertyValue("max-height")).toBe("90vh")
      expect($("dialog").style.getPropertyValue("--drawer-snap-point-offset")).toBe("300px")
    })

    it("places the snap point again after a resize of the viewport", async () => {
      vi.stubGlobal("innerHeight", 800)
      await drawer('data-phem-dialog-swipe-direction-value="down" data-phem-dialog-snap-points-value=\'[0.25, 1]\'')
      expect($("dialog").style.getPropertyValue("--drawer-snap-point-offset")).toBe("200px")

      vi.stubGlobal("innerHeight", 400)
      window.dispatchEvent(new Event("resize"))
      expect($("dialog").style.getPropertyValue("--drawer-snap-point-offset")).toBe("300px")
    })

    it("goes to the next snap point after a fast swipe", async () => {
      vi.stubGlobal("innerHeight", 800)
      await drawer('data-phem-dialog-swipe-direction-value="down" data-phem-dialog-snap-points-value=\'["100px", 0.25, 1]\' data-phem-dialog-snap-point-value="1"')
      pointer("pointerdown", 100, 500, $("title"))
      const move = new PointerEvent("pointermove", { bubbles: true, isPrimary: true, pointerId: 1, clientX: 100, clientY: 540 })
      Object.defineProperty(move, "timeStamp", { value: time + 50 })
      document.dispatchEvent(move)
      const up = new PointerEvent("pointerup", { bubbles: true, isPrimary: true, pointerId: 1, clientX: 100, clientY: 540 })
      Object.defineProperty(up, "timeStamp", { value: time + 60 })
      document.dispatchEvent(up)

      // 0.25 of the viewport is 200 px: the next snap point after the full height.
      expect($("edit").getAttribute("data-phem-dialog-snap-point-value")).toBe("0.25")
      expect($("dialog").style.getPropertyValue("--drawer-snap-point-offset")).toBe("200px")
      expect($("dialog").style.getPropertyValue("--drawer-swipe-strength")).not.toBe("")
    })
  })

  it("closes on Escape and not on a tap on the backdrop with disablePointerDismissal", async () => {
    await mount(edit('data-phem-dialog-disable-pointer-dismissal-value="true"'))
    click("#open")
    clickAt(dialog(), [500, 500])
    expect(isModal()).toBe(true)

    escape()
    await nextTask()
    expect(dialog().open).toBe(false)
  })

  it("opens a dialog that is not a modal with modal false, and closes it on a press outside", async () => {
    await mount(edit('data-phem-dialog-modal-value="false"') + '<button id="outside">Outside</button>')
    click("#open")

    expect(dialog().open).toBe(true)
    expect(dialog().modal).toBe(false)

    document.getElementById("body").click()
    expect(dialog().open).toBe(true)

    document.getElementById("outside").click()
    await nextTask()
    expect(dialog().open).toBe(false)
    expect(document.getElementById("edit").getAttribute("data-phem-dialog-open-value")).toBe("false")
  })

  it("closes a dialog that is not a modal on Escape", async () => {
    await mount(edit('data-phem-dialog-modal-value="false"'))
    click("#open")
    const event = new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true })
    document.getElementById("save").dispatchEvent(event)
    await nextTask()

    expect(event.defaultPrevented).toBe(true)
    expect(dialog().open).toBe(false)
  })

  it("skips a hidden field at the end of the dialog with trap-focus", async () => {
    await mount(edit('data-phem-dialog-modal-value="trap-focus"', '<button id="hidden" hidden>Hidden</button>'))
    click("#open")
    document.getElementById("close").focus()
    const event = new KeyboardEvent("keydown", { key: "Tab", bubbles: true, cancelable: true })
    document.getElementById("close").dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement.id).toBe("save")
  })

  describe("Tab in a modal dialog", () => {
    const tab = (id, shiftKey = false) => {
      const target = document.getElementById(id)
      target.focus()
      const event = new KeyboardEvent("keydown", { key: "Tab", shiftKey, bubbles: true, cancelable: true })
      target.dispatchEvent(event)

      return event
    }

    it("goes from the last field to the first one, and back", async () => {
      await mount(edit())
      click("#open")

      expect(tab("close").defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("save")
      expect(tab("save", true).defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("close")
    })

    it("lets the browser move the focus between the fields", async () => {
      await mount(edit())
      click("#open")

      expect(tab("save").defaultPrevented).toBe(false)
      expect(tab("close", true).defaultPrevented).toBe(false)
    })

    it("lets Tab go from the popup to its first field, and Shift+Tab to its last field", async () => {
      await mount(edit())
      click("#open")
      dialog().tabIndex = -1

      expect(tab("dialog").defaultPrevented).toBe(false)
      expect(tab("dialog", true).defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("close")
    })

    it("goes to the first field from the checked radio of a last radio group", async () => {
      await mount(edit("", `<input type="radio" name="size" id="small" checked>
          <input type="radio" name="size" id="large">`))
      click("#open")

      expect(tab("close").defaultPrevented).toBe(false)
      expect(tab("small").defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("save")

      // Shift+Tab from the first field goes to the checked radio, not to the last radio.
      expect(tab("save", true).defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("small")
    })

    it("goes to the first field from an element with tabindex -1 after the last field", async () => {
      await mount(edit("", '<div id="item" role="menuitem" tabindex="-1">Item</div>'))
      click("#open")

      expect(tab("item").defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("save")
    })

    it("does nothing in a dialog with no field", async () => {
      await mount(`<div data-controller="phem-dialog">
          <button id="open" data-phem-dialog-target="trigger">Open</button>
          <dialog id="dialog" data-phem-dialog-target="popup" tabindex="-1"><p>Done</p></dialog>
        </div>`)
      click("#open")

      expect(tab("dialog").defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("dialog")
    })

    it("lets Tab go out of a dialog that is not a modal", async () => {
      await mount(edit('data-phem-dialog-modal-value="false"'))
      click("#open")

      expect(tab("close").defaultPrevented).toBe(false)
    })

    it("leaves Tab in a dialog in the dialog to the controller of that dialog", async () => {
      await mount(edit("", `<div data-controller="phem-dialog">
          <button id="inner-open" data-phem-dialog-target="trigger">More</button>
          <dialog id="inner" data-phem-dialog-target="popup">
            <button id="inner-first">First</button>
            <button id="inner-last">Last</button>
          </dialog>
        </div>`))
      click("#open")
      click("#inner-open")

      expect(tab("inner-first").defaultPrevented).toBe(false)
      expect(tab("inner-last").defaultPrevented).toBe(true)
      expect(document.activeElement.id).toBe("inner-first")
    })
  })

  it("keeps the focus in the dialog with trap-focus", async () => {
    await mount(edit('data-phem-dialog-modal-value="trap-focus"'))
    click("#open")
    document.getElementById("close").focus()
    const event = new KeyboardEvent("keydown", { key: "Tab", bubbles: true, cancelable: true })
    document.getElementById("close").dispatchEvent(event)

    expect(dialog().modal).toBe(false)
    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement.id).toBe("save")
  })

  describe("with a viewport and a backdrop", () => {
    const viewport = (attributes = "") => `<div id="edit" data-controller="phem-dialog" ${attributes}>
        <button id="open" data-phem-dialog-target="trigger">Edit</button>
        <div id="backdrop" data-phem-dialog-target="backdrop" hidden></div>
        <dialog id="dialog" data-phem-dialog-target="viewport">
          <div id="popup" data-phem-dialog-target="popup">
            <h2 data-phem-dialog-target="title">Edit</h2>
            <button id="save" data-phem-on-click="c1.save">Save</button>
          </div>
        </dialog>
      </div>`

    it("opens the viewport as a modal, and gives the open state to the popup and the backdrop", async () => {
      await mount(viewport())
      click("#open")

      expect(isModal()).toBe(true)
      for (const id of ["dialog", "popup", "backdrop"]) expect(document.getElementById(id).hasAttribute("data-open")).toBe(true)
      expect(document.getElementById("backdrop").hidden).toBe(false)
    })

    it("keeps the focus on a press on the viewport, and not on a press on a field in it", async () => {
      await mount(viewport().replace('<div id="popup"', '<input id="search"><div id="popup"'))
      click("#open")
      const mousedown = (el) => {
        const event = new MouseEvent("mousedown", { bubbles: true, cancelable: true })
        el.dispatchEvent(event)

        return event
      }

      expect(mousedown(dialog()).defaultPrevented).toBe(true)
      expect(mousedown(document.getElementById("search")).defaultPrevented).toBe(false)
      expect(mousedown(document.getElementById("save")).defaultPrevented).toBe(false)
    })

    it("closes on a press on the viewport outside the popup", async () => {
      await mount(viewport())
      click("#open")
      respond(viewport())
      clickAt(document.getElementById("save"), [10, 10])
      expect(dialog().open).toBe(true)

      clickAt(dialog(), [1, 1])
      await nextTask()
      expect(dialog().open).toBe(false)
      for (const id of ["popup", "backdrop"]) expect(document.getElementById(id).hasAttribute("data-closed")).toBe(true)
      expect(document.getElementById("backdrop").hidden).toBe(true)
    })

    it("keeps the state through the morph of an event", async () => {
      await mount(viewport())
      click("#open")
      respond(viewport())
      const done = nextDone()
      click("#save")
      await done
      await nextTask()

      expect(isModal()).toBe(true)
      expect(document.getElementById("popup").hasAttribute("data-open")).toBe(true)
      expect(document.getElementById("backdrop").hidden).toBe(false)
    })
  })
})

describe("phem-dialog: the focus of a touch open", () => {
  const press = (el, pointerType) => {
    el.dispatchEvent(new PointerEvent("pointerdown", { bubbles: true, pointerType }))
    el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, detail: 1 }))
  }

  it("gives the focus to the popup after an open with a touch", async () => {
    await mount(edit())
    press(document.getElementById("open"), "touch")

    expect(dialog().open).toBe(true)
    expect(dialog().getAttribute("tabindex")).toBe("-1")
    expect(document.activeElement).toBe(dialog())
  })

  it("does not change the focus of the browser after an open with the mouse or the keyboard", async () => {
    await mount(edit())
    press(document.getElementById("open"), "mouse")
    expect(dialog().hasAttribute("tabindex")).toBe(false)

    click("#close")
    await nextTask()
    press(document.getElementById("open"), "touch")
    click("#close")
    await nextTask()

    // A click of the keyboard after a touch.
    document.getElementById("open").focus()
    click("#open")
    expect(document.activeElement).not.toBe(dialog())
  })
})

describe("phem-dialog: the focus of a drawer", () => {
  const drawer = (content = "") => edit('data-phem-dialog-swipe-direction-value="down"', content)

  it("gives the focus to the popup", async () => {
    await mount(drawer())
    click("#open")

    expect(dialog().getAttribute("tabindex")).toBe("-1")
    expect(document.activeElement).toBe(dialog())
  })

  it("keeps the focus of an element with autofocus", async () => {
    await mount(drawer('<input id="name" autofocus>'))
    click("#open")

    expect(dialog().hasAttribute("tabindex")).toBe(false)
    expect(document.activeElement).not.toBe(dialog())
  })
})

describe("phem-dialog: a drawer in a drawer", () => {
  const $ = (id) => document.getElementById(id)
  let time = 0

  function pointer(type, x, y, target = document) {
    const event = new PointerEvent(type, { bubbles: true, cancelable: true, isPrimary: true, button: 0, pointerId: 1, clientX: x, clientY: y })
    Object.defineProperty(event, "timeStamp", { value: (time += 1000) })
    target.dispatchEvent(event)
  }

  async function drawers() {
    const down = 'data-controller="phem-dialog" data-phem-dialog-swipe-direction-value="down"'
    await mount(`<div ${down}>
        <button id="outer-open" data-phem-dialog-target="trigger">Open</button>
        <dialog id="outer" data-phem-dialog-target="popup">
          <h2 id="outer-title">Outer</h2>
          <div ${down}>
            <button id="inner-open" data-phem-dialog-target="trigger">More</button>
            <dialog id="inner" data-phem-dialog-target="popup">
              <h2 id="inner-title">Inner</h2>
              <button id="inner-close" data-phem-dialog-target="close">Close</button>
            </dialog>
          </div>
        </dialog>
      </div>`)
    Object.defineProperty($("outer"), "offsetHeight", { configurable: true, value: 400 })
    Object.defineProperty($("inner"), "offsetHeight", { configurable: true, value: 200 })
    $("outer").getBoundingClientRect = () => ({ left: 0, top: 400, right: 300, bottom: 800, width: 300, height: 400 })
    $("inner").getBoundingClientRect = () => ({ left: 0, top: 600, right: 300, bottom: 800, width: 300, height: 200 })
  }

  it("gives each drawer the height of the last open drawer in it", async () => {
    await drawers()
    click("#outer-open")
    expect($("outer").style.getPropertyValue("--drawer-frontmost-height")).toBe("400px")

    click("#inner-open")
    expect($("outer").style.getPropertyValue("--drawer-frontmost-height")).toBe("200px")
    expect($("inner").style.getPropertyValue("--drawer-frontmost-height")).toBe("200px")
    expect($("outer").hasAttribute("data-nested-drawer-open")).toBe(true)

    click("#inner-close")
    await nextTask()
    expect($("outer").style.getPropertyValue("--drawer-frontmost-height")).toBe("400px")
  })

  it("gives the drawer around a drawer its own height when the close of the drawer starts", async () => {
    await drawers()
    click("#outer-open")
    click("#inner-open")
    $("inner").getAnimations = () => [{ finished: new Promise(() => {}) }]
    click("#inner-close")

    // The drawer in it still closes: its animation is not at its end.
    expect($("inner").open).toBe(true)
    expect($("outer").style.getPropertyValue("--drawer-frontmost-height")).toBe("400px")
  })

  it("marks the drawer around a drawer during its swipe, and does not swipe it", async () => {
    await drawers()
    click("#outer-open")
    click("#inner-open")

    pointer("pointerdown", 100, 650, $("inner-title"))
    pointer("pointermove", 100, 680)
    expect($("inner").hasAttribute("data-swiping")).toBe(true)
    expect($("outer").hasAttribute("data-swiping")).toBe(false)
    expect($("outer").hasAttribute("data-nested-drawer-swiping")).toBe(true)

    pointer("pointerup", 100, 680)
    expect($("outer").hasAttribute("data-nested-drawer-swiping")).toBe(false)
    expect($("inner").open).toBe(true)
  })
})
