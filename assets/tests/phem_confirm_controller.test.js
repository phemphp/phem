import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemConfirmController from "../dist/phem_confirm_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
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
