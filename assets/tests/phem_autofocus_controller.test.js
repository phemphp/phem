import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import PhemAutofocusController from "../dist/phem_autofocus_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-autofocus", PhemAutofocusController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A search field (or a field with the `attributes`), an "Edit" button that sends an event, and the `content` of
// the server render.
function page(content = "", attributes = "") {
  return `<input id="search" ${attributes || 'type="search"'} />
    <button id="edit" data-phem-on-click="c1.edit">Edit</button>
    ${content}`
}

const title = `<input id="title" data-controller="phem-autofocus" />`

// Sends an event from the "Edit" button, with the focus on `focused`.
async function morphOnEdit(focused = "#edit") {
  document.querySelector(focused).focus()
  const done = nextDone()
  click("#edit")
  await done
  // Stimulus connects the controller in a mutation observer callback.
  await new Promise((resolve) => setTimeout(resolve, 0))
}

// Waits for the render of the streams and for Stimulus.
function settle() {
  return new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)))
}

// Rows with an input each, keyed by `data-phem-key`.
function rows(keys) {
  const row = (k) => `<li data-phem-key="${k}"><input id="row-${k}" data-controller="phem-autofocus" /></li>`

  return page(`<ul>${keys.map(row).join("")}</ul>`)
}

describe("phem-autofocus", () => {
  it("gives the focus to its element when it connects", async () => {
    await mount(page(title))

    expect(document.activeElement.id).toBe("title")
  })

  it("gives the focus to its first focus target", async () => {
    await mount(page(`<div data-controller="phem-autofocus">
        <label>Title</label>
        <input id="first" data-phem-autofocus-target="focus" />
        <input id="second" data-phem-autofocus-target="focus" />
      </div>`))

    expect(document.activeElement.id).toBe("first")
  })

  it("gives the focus to an element that the morph of an event adds", async () => {
    await mount(page())
    respond(page(title))
    await morphOnEdit()

    expect(document.activeElement.id).toBe("title")
  })

  it("gives the focus to an element that the morph of an event replaces", async () => {
    await mount(page(`<input class="title" data-controller="phem-autofocus" />`))
    respond(page(`<textarea class="title" data-controller="phem-autofocus"></textarea>`))
    await morphOnEdit()

    expect(document.activeElement.tagName).toBe("TEXTAREA")
  })

  it("gives the focus to a focus target that the morph adds", async () => {
    const form = (input) => page(`<div id="form" data-controller="phem-autofocus">${input}</div>`)
    await mount(form(""))
    respond(form(`<input id="title" data-phem-autofocus-target="focus" />`))
    await morphOnEdit()

    expect(document.activeElement.id).toBe("title")
  })

  it("does not give the focus again to an element that the morph keeps", async () => {
    await mount(page(title))
    respond(page(title))
    await morphOnEdit()

    expect(document.activeElement.id).toBe("edit")
  })

  it("does not take the focus from a field that the user types in", async () => {
    await mount(page())
    respond(page(title))
    await morphOnEdit("#search")

    expect(document.activeElement.id).toBe("search")
  })

  it("takes the focus from a read-only field", async () => {
    await mount(page("", "readonly"))
    respond(page(title, "readonly"))
    await morphOnEdit("#search")

    expect(document.activeElement.id).toBe("title")
  })

  it("does not give the focus again to an element that the morph moves", async () => {
    await mount(rows([1, 2]))
    respond(rows([2, 1]))
    // Stimulus connects the moved row again.
    const connect = vi.spyOn(PhemAutofocusController.prototype, "connect")
    await morphOnEdit()

    expect(connect).toHaveBeenCalled()
    expect(document.activeElement.id).toBe("edit")
  })

  it("gives the focus to the first element when more elements connect", async () => {
    await mount(page())
    respond(page(`<button id="first" data-controller="phem-autofocus">First</button><button id="second" data-controller="phem-autofocus">Second</button>`))
    await morphOnEdit()

    expect(document.activeElement.id).toBe("first")
  })

  it("does not take the focus in a push of the server", async () => {
    await mount(page())
    document.getElementById("edit").focus()
    Turbo.renderStreamMessage(`<turbo-stream action="update" method="morph" target="c1"><template>${page(title)}</template></turbo-stream>`)
    await settle()

    expect(document.getElementById("title")).not.toBeNull()
    expect(document.activeElement.id).toBe("edit")
  })

  it("takes the focus from a field of the form that the user submitted", async () => {
    const form = (content) => `<form id="form" data-phem-on-submit="c1.save"><input id="name" name="name" /></form>${content}`
    await mount(form(""))
    respond(form(title))
    document.getElementById("name").focus()
    const done = nextDone()
    document.getElementById("form").requestSubmit()
    await done
    await settle()

    expect(document.activeElement.id).toBe("title")
  })

  it("does not take the focus from a range field", async () => {
    await mount(page("", 'type="range"'))
    respond(page(title, 'type="range"'))
    await morphOnEdit("#search")

    expect(document.activeElement.id).toBe("search")
  })
})
