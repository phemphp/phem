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
      <button id="open" data-action="phem-dialog#open">Edit</button>
      <dialog id="dialog" data-phem-dialog-target="dialog">
        <p id="body">Body</p>
        <button id="save" data-phem-on-click="c1.save">Save</button>
        <button id="close" data-action="phem-dialog#close">Close</button>
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
})
