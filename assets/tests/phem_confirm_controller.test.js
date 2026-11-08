import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import PhemConfirmController from "../dist/phem_confirm_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

// jsdom has no showModal(): it opens the dialog. close() sets the return value, and the `close`
// event comes in a later task, as in a browser.
beforeAll(() => {
  const proto = HTMLDialogElement.prototype
  proto.showModal = function () {
    if (this.open) throw new DOMException("The dialog is open", "InvalidStateError")

    this.setAttribute("open", "")
  }
  proto.close = function (returnValue) {
    if (!this.open) return

    if (undefined !== returnValue) this.returnValue = returnValue
    this.removeAttribute("open")
    setTimeout(() => this.dispatchEvent(new Event("close")), 0)
  }

  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-confirm", PhemConfirmController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A "Delete" button that sends an event, and the `attributes` of the button.
function button(attributes = "") {
  return `<button id="delete" data-phem-on-click="c1.delete" data-controller="phem-confirm" ${attributes}>Delete</button>`
}

// The user answers `answer` to the question.
function answer(answer) {
  const confirm = vi.fn(() => answer)
  vi.stubGlobal("confirm", confirm)

  return confirm
}

// A press on `from` and a click on `to`, at the points of the viewport. A browser gives the click
// to the common ancestor when the press and the release are on different elements.
function clickAt(from, [x1, y1], to = from, [x2, y2] = [x1, y1]) {
  from.dispatchEvent(new MouseEvent("pointerdown", { bubbles: true, cancelable: true, clientX: x1, clientY: y1 }))
  to.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, clientX: x2, clientY: y2 }))
}

// Lets the queue of the phem controller start a request.
function settle() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

describe("phem-confirm", () => {
  it("sends no event when the user cancels", async () => {
    await mount(button())
    answer(false)
    const queued = vi.fn()
    window.addEventListener("phem:queue", queued)

    click("#delete")
    await settle()
    window.removeEventListener("phem:queue", queued)

    expect(fetch).not.toHaveBeenCalled()
    expect(queued).not.toHaveBeenCalled()
  })

  it("sends the event when the user confirms", async () => {
    await mount(button())
    respond(button())
    const confirm = answer(true)

    const done = nextDone()
    click("#delete")
    await done

    expect(confirm).toHaveBeenCalledWith("Are you sure?")
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("asks the message value", async () => {
    await mount(button('data-phem-confirm-message-value="Delete this item?"'))
    const confirm = answer(false)
    click("#delete")

    expect(confirm).toHaveBeenCalledWith("Delete this item?")
  })

  it("does not stop the DOM event for the page", async () => {
    await mount(button())
    answer(false)
    const clicked = vi.fn()
    document.addEventListener("click", clicked)

    click("#delete")
    document.removeEventListener("click", clicked)

    expect(clicked).toHaveBeenCalledTimes(1)
  })

  it("does not submit a form when the user cancels", async () => {
    await mount(`<form id="form" data-phem-on-submit="c1.save" data-controller="phem-confirm">
        <button id="save">Save</button>
      </form>`)
    answer(false)

    let submit
    document.addEventListener("submit", (e) => (submit = e), { once: true })
    click("#save")
    await settle()

    expect(submit.defaultPrevented).toBe(true)
    expect(fetch).not.toHaveBeenCalled()
  })

  it("does not ask for a submit of a form that sends its event", async () => {
    const form = `<form id="form" data-phem-on-submit="c1.save" data-controller="phem-confirm">
        <button id="save">Save</button>
      </form>`
    await mount(form)
    respond(form)
    const confirm = answer(true)

    const done = nextDone()
    click("#save")
    click("#save")
    await done

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("sends no change of a checkbox and unchecks it when the user cancels", async () => {
    await mount(`<input id="archive" type="checkbox" data-phem-on-change="c1.archive" data-controller="phem-confirm">`)
    answer(false)
    click("#archive")
    await settle()

    expect(fetch).not.toHaveBeenCalled()
    expect(document.getElementById("archive").checked).toBe(false)
  })

  it("gives a select its value again when the user cancels", async () => {
    await mount(`<select id="role" data-phem-on-change="c1.role" data-controller="phem-confirm">
        <option value="user" selected>User</option>
        <option value="admin">Admin</option>
      </select>`)
    answer(false)

    const select = document.getElementById("role")
    select.value = "admin"
    select.dispatchEvent(new Event("change", { bubbles: true }))
    await settle()

    expect(fetch).not.toHaveBeenCalled()
    expect(select.value).toBe("user")
  })

  it("asks from an element around the handler, only for the event of a handler", async () => {
    await mount(`<div data-controller="phem-confirm">
        <button id="delete" data-phem-on-click="c1.delete">Delete</button>
        <button id="help" type="button">Help</button>
      </div>`)
    const confirm = answer(false)

    let help
    document.addEventListener("click", (e) => (help = e), { once: true })
    click("#help")

    expect(confirm).not.toHaveBeenCalled()
    expect(help.defaultPrevented).toBe(false)
    click("#delete")
    await settle()
    expect(confirm).toHaveBeenCalledTimes(1)
    expect(fetch).not.toHaveBeenCalled()
  })

  it("asks only once with a controller around another controller", async () => {
    await mount(`<div data-controller="phem-confirm">${button()}</div>`)
    const confirm = answer(true)
    respond(button())

    const done = nextDone()
    click("#delete")
    await done

    expect(confirm).toHaveBeenCalledTimes(1)
  })
})

describe("phem-confirm with a dialog", () => {
  // The `#confirm` dialog.
  const DIALOG = `<dialog id="confirm" aria-describedby="confirm-message">
      <form method="dialog">
        <p id="confirm-message" data-phem-confirm-message></p>
        <button value="cancel">Back</button>
        <button value="confirm" data-phem-confirm-accept>Yes</button>
      </form>
    </dialog>`

  // A "Delete" button that asks in the `#confirm` dialog, and the dialog.
  function page(attributes = "") {
    return `<button id="delete" data-phem-on-click="c1.delete" data-controller="phem-confirm"
        data-phem-confirm-message-value="Delete this item?" data-phem-confirm-dialog-value="#confirm" ${attributes}>Delete</button>
      <button id="other" data-phem-on-click="c1.other">Other</button>
      ${DIALOG}`
  }

  const dialog = () => document.getElementById("confirm")
  const accept = () => dialog().querySelector("[data-phem-confirm-accept]")

  it("opens the dialog with the message, and sends no event before the answer", async () => {
    await mount(page('data-phem-confirm-accept-value="Delete item"'))
    const confirm = answer(true)
    click("#delete")
    await settle()

    expect(confirm).not.toHaveBeenCalled()
    expect(dialog().open).toBe(true)
    expect(dialog().querySelector("[data-phem-confirm-message]").textContent).toBe("Delete this item?")
    expect(accept().textContent).toBe("Delete item")
    expect(fetch).not.toHaveBeenCalled()
  })

  it("keeps the label of the accept button with no accept value", async () => {
    await mount(page())
    click("#delete")

    expect(accept().textContent).toBe("Yes")
  })

  it("sends the event when the dialog closes with confirm", async () => {
    await mount(page())
    respond(page())
    click("#delete")

    const done = nextDone()
    dialog().close("confirm")
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ handler: "c1.delete", event: { type: "click" } })
  })

  it("sends the value of a field that the user confirms", async () => {
    const box = `<input id="archive" type="checkbox" data-phem-on-change="c1.archive" data-controller="phem-confirm" data-phem-confirm-dialog-value="#confirm">${DIALOG}`
    await mount(box)
    click("#archive")

    // The box has the value of the server render while the dialog asks.
    expect(document.getElementById("archive").checked).toBe(false)
    respond(box)
    const done = nextDone()
    dialog().close("confirm")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ handler: "c1.archive", event: { type: "change", checked: true } })
  })

  it("sends no event when the dialog closes in another way", async () => {
    await mount(page())
    click("#delete")
    dialog().close("cancel")
    await settle()

    // Escape: the return value of the last close does not stay.
    click("#delete")
    dialog().close()
    await settle()

    expect(fetch).not.toHaveBeenCalled()
  })

  it("asks again after a confirm", async () => {
    await mount(page())
    respond(page())
    click("#delete")
    const done = nextDone()
    dialog().close("confirm")
    await done

    click("#delete")
    dialog().close()
    await settle()

    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("gives the accept button its label again after a question", async () => {
    await mount(`${page('data-phem-confirm-accept-value="Delete item"')}
      <button id="archive" data-phem-on-click="c1.archive" data-controller="phem-confirm" data-phem-confirm-dialog-value="#confirm">Archive</button>`)
    click("#delete")
    dialog().close("cancel")
    await settle()
    expect(accept().textContent).toBe("Yes")

    click("#archive")

    expect(accept().textContent).toBe("Yes")
  })

  it("keeps the dialog as it is while a morph runs", async () => {
    // The dialog is in `c1`, and the button in `c2`: the morph of `c1` does not touch the button.
    document.body.innerHTML = `
      <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch"
           data-phem-state-value="{}" data-phem-signature-value="s1" data-phem-loading-delay-value="0">
        <phem-c data-phem-c="c1"><button id="other" data-phem-on-click="c1.other">Other</button>${DIALOG}</phem-c>
        <phem-c data-phem-c="c2"><button id="delete" data-phem-on-click="c2.delete" data-controller="phem-confirm"
            data-phem-confirm-message-value="Delete this item?" data-phem-confirm-dialog-value="#confirm">Delete</button></phem-c>
      </div>`
    await settle()
    click("#delete")

    respond(`<button id="other" data-phem-on-click="c1.other">Other</button>${DIALOG}`)
    let done = nextDone()
    click("#other")
    await done
    expect(dialog().open).toBe(true)
    expect(dialog().querySelector("[data-phem-confirm-message]").textContent).toBe("Delete this item?")

    respond(`<button id="other" data-phem-on-click="c1.other">Other</button>${DIALOG}`)
    done = nextDone()
    dialog().close("confirm")
    await done

    expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ handler: "c2.delete" })
  })

  it("closes the dialog when a morph drops the event, and a later confirm sends only its own event", async () => {
    await mount(page())
    click("#delete")

    // The morph of the button: its fiber id can belong to another item now.
    respond(page())
    let done = nextDone()
    click("#other")
    await done
    await settle()
    expect(dialog().open).toBe(false)

    respond(page())
    click("#delete")
    done = nextDone()
    dialog().close("confirm")
    await done
    await settle()

    expect(fetch.mock.calls.map((call) => JSON.parse(call[1].body).handler)).toEqual(["c1.other", "c1.delete"])
  })

  it("replaces the open question of the dialog with a new question", async () => {
    await mount(`${page()}
      <button id="archive" data-phem-on-click="c1.archive" data-controller="phem-confirm"
          data-phem-confirm-message-value="Archive this item?" data-phem-confirm-dialog-value="#confirm">Archive</button>`)
    click("#delete")
    click("#archive")
    expect(dialog().querySelector("[data-phem-confirm-message]").textContent).toBe("Archive this item?")

    respond(page())
    const done = nextDone()
    dialog().close("confirm")
    await done
    await settle()

    expect(fetch.mock.calls.map((call) => JSON.parse(call[1].body).handler)).toEqual(["c1.archive"])
  })

  it("closes the dialog on a tap on the backdrop, and sends no event", async () => {
    await mount(page('data-phem-confirm-accept-value="Delete item"'))
    click("#delete")
    vi.spyOn(dialog(), "getBoundingClientRect").mockReturnValue(new DOMRect(100, 100, 200, 200))

    // A click in the dialog: on its content, or on its padding (the dialog in its box).
    clickAt(dialog().querySelector("[data-phem-confirm-message]"), [500, 500])
    clickAt(dialog(), [150, 150])
    expect(dialog().open).toBe(true)

    clickAt(dialog(), [500, 500])
    await settle()

    expect(dialog().open).toBe(false)
    expect(accept().textContent).toBe("Yes")
    expect(fetch).not.toHaveBeenCalled()
  })

  it("keeps the dialog open when a press in the dialog ends on the backdrop", async () => {
    await mount(page())
    click("#delete")
    vi.spyOn(dialog(), "getBoundingClientRect").mockReturnValue(new DOMRect(100, 100, 200, 200))

    // A text selection from the message to the backdrop: the click goes to the dialog.
    clickAt(dialog().querySelector("[data-phem-confirm-message]"), [150, 150], dialog(), [150, 50])
    expect(dialog().open).toBe(true)

    // A press on the backdrop that ends in the dialog.
    clickAt(dialog(), [150, 50], dialog(), [150, 150])
    expect(dialog().open).toBe(true)
  })

  it("ends the question when Turbo caches the page", async () => {
    await mount(page('data-phem-confirm-accept-value="Delete item"'))
    click("#delete")

    // Turbo dispatches `turbo:before-cache`, and clones the page in a later task.
    const snapshot = await Turbo.session.view.cacheSnapshot()
    Turbo.session.clearCache()
    const cached = snapshot.element.querySelector("#confirm")

    expect(cached.hasAttribute("open")).toBe(false)
    expect(cached.querySelector("[data-phem-confirm-message]").textContent).toBe("")
    expect(cached.querySelector("[data-phem-confirm-accept]").textContent).toBe("Yes")
    expect(dialog().open).toBe(false)

    await settle()
    expect(fetch).not.toHaveBeenCalled()
  })

  it("stops the listeners of the dialog when the question ends", async () => {
    await mount(page())
    click("#delete")
    dialog().close("cancel")
    await settle()

    // The page opens the dialog for its own use.
    dialog().showModal()
    clickAt(dialog(), [500, 500])
    document.dispatchEvent(new Event("turbo:before-cache"))

    expect(dialog().open).toBe(true)
  })

  it("asks with window.confirm() when the selector finds no dialog", async () => {
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    await mount(button('data-phem-confirm-dialog-value="#missing"'))
    const confirm = answer(false)
    click("#delete")
    await settle()

    expect(confirm).toHaveBeenCalledWith("Are you sure?")
    expect(error).toHaveBeenCalledWith('[phem] phem-confirm: "#missing" is not a <dialog> of the page')
    expect(fetch).not.toHaveBeenCalled()
  })

  it("asks with window.confirm() when the selector is not valid", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {})
    await mount(button('data-phem-confirm-dialog-value="#42"'))
    const confirm = answer(false)
    click("#delete")
    await settle()

    expect(confirm).toHaveBeenCalledTimes(1)
    expect(fetch).not.toHaveBeenCalled()
  })

  it("asks with window.confirm() when the dialog is open with no question", async () => {
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    await mount(page().replace('<dialog id="confirm"', '<dialog id="confirm" open'))
    const confirm = answer(false)
    click("#delete")
    await settle()

    expect(confirm).toHaveBeenCalledWith("Delete this item?")
    expect(error).toHaveBeenCalledWith("[phem] phem-confirm: the dialog is open with no question")
    expect(fetch).not.toHaveBeenCalled()
  })
})
