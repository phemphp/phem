import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import PhemAutofocusController from "../dist/phem_autofocus_controller.js"
import { release } from "../dist/phem_keyboard.js"
import { answer, click, mount, nextDone, respond } from "./support.js"

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
    Turbo.renderStreamMessage(`<turbo-stream action="update" method="morph" targets="[data-phem-c=c1]"><template>${page(title)}</template></turbo-stream>`)
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

  describe("with data-phem-autofocus-keyboard", () => {
    // The hidden field of phem_keyboard.js: a child of <html>.
    const standIn = () => document.querySelector("html > input")

    afterEach(() => {
      release()
      vi.useRealTimers()
    })

    const trigger = (markup) => markup.replace('id="edit"', 'id="edit" data-phem-autofocus-keyboard')
    const link = `<a id="link" href="/search" data-phem-autofocus-keyboard>Search</a>`

    // The element that has the focus in the `phem:queue` of the tap, and its parent.
    function focusInTap() {
      return new Promise((resolve) => window.addEventListener("phem:queue", () => resolve({ el: document.activeElement, parent: document.activeElement.parentElement }), { once: true }))
    }

    // The next visit of Turbo: jsdom cannot load a page, so the test stops it. With `start`, the
    // test dispatches its `turbo:visit`, as Turbo does when no listener cancels it.
    function nextVisit(start = true) {
      document.addEventListener(
        "turbo:before-visit",
        (event) => {
          event.preventDefault()
          if (start) document.dispatchEvent(new CustomEvent("turbo:visit"))
        },
        { once: true },
      )
    }

    // A tap on the link: Turbo dispatches `turbo:click`, then visits the page.
    function tapLink(start = true) {
      nextVisit(start)
      click("#link")
    }

    it("focuses a stand-in in the tap, then gives the focus to the field that the morph adds", async () => {
      await mount(trigger(page()))
      respond(page(title))
      const inTap = focusInTap()
      await morphOnEdit()

      expect((await inTap).parent).toBe(document.documentElement)
      expect(document.activeElement.id).toBe("title")
      expect(standIn()).toBeNull()
    })

    it("focuses a stand-in in the turbo:click of a link, and keeps it in the body swap", async () => {
      await mount(page(link))
      tapLink()

      expect(document.activeElement).toBe(standIn())

      document.body.replaceWith(Object.assign(document.createElement("body"), { innerHTML: `<input id="q" type="search" data-controller="phem-autofocus" />` }))
      await settle()

      expect(document.activeElement.id).toBe("q")
      expect(standIn()).toBeNull()
    })

    it("removes the stand-in when the visit shows no autofocus field", async () => {
      await mount(page(link))
      tapLink()
      await settle()

      expect(document.activeElement).toBe(standIn())

      document.dispatchEvent(new Event("turbo:load"))
      await settle()

      expect(standIn()).toBeNull()
      expect(document.activeElement).toBe(document.body)
    })

    it("removes the stand-in when a listener cancels the visit", async () => {
      await mount(page(link))
      tapLink(false)
      await settle()

      expect(standIn()).toBeNull()
    })

    it("removes the stand-in when the fetch of the visit fails", async () => {
      await mount(page(link))
      tapLink()
      document.dispatchEvent(new Event("turbo:fetch-request-error"))

      expect(standIn()).toBeNull()
    })

    it("removes the stand-in when the visit takes more than 30 seconds", async () => {
      await mount(page(link))
      vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
      tapLink()
      vi.advanceTimersByTime(29999)

      expect(document.activeElement).toBe(standIn())

      vi.advanceTimersByTime(1)

      expect(standIn()).toBeNull()
    })

    it("removes the stand-in when the event adds no autofocus field, and gives the focus back", async () => {
      await mount(trigger(page()))
      respond(page())
      const inTap = focusInTap()
      await morphOnEdit()
      await settle()

      expect((await inTap).parent).toBe(document.documentElement)
      expect(standIn()).toBeNull()
      expect(document.activeElement.id).toBe("edit")
    })

    it("waits for a phem-autofocus element that did not connect yet", async () => {
      await mount(trigger(page()))
      respond(page(`<input id="lazy" data-controller="phem-autofocus" />`))
      // Its lazy module did not load yet.
      application.unload("phem-autofocus")
      await morphOnEdit()
      await settle()

      expect(document.activeElement).toBe(standIn())

      await new Promise((resolve) => setTimeout(resolve, 1000))

      expect(standIn()).toBeNull()
      application.register("phem-autofocus", PhemAutofocusController)
    })

    it("keeps the stand-in at the end of another event", async () => {
      await mount(trigger(page()))
      let reply
      fetch.mockReturnValueOnce(new Promise((resolve) => (reply = resolve)))
      document.getElementById("edit").focus()
      click("#edit")
      window.dispatchEvent(new CustomEvent("phem:done", { detail: { handler: "c1.other", ids: [0] } }))
      await settle()

      expect(document.activeElement).toBe(standIn())

      const done = nextDone()
      reply(new Response(JSON.stringify(answer(page(title))), { status: 200, headers: { "Content-Type": "application/json" } }))
      await done
      await settle()

      expect(document.activeElement.id).toBe("title")
      expect(standIn()).toBeNull()
    })

    it("keeps the stand-in in the visit of a redirect", async () => {
      await mount(trigger(page()))
      fetch.mockResolvedValueOnce(new Response(JSON.stringify({ redirect: "/search" }), { status: 200 }))
      nextVisit()
      await morphOnEdit()
      await settle()

      expect(document.activeElement).toBe(standIn())

      document.dispatchEvent(new Event("turbo:load"))
      await settle()

      expect(standIn()).toBeNull()
    })

    it("focuses the stand-in in the open dialog of the tap, and the morph keeps it", async () => {
      const dialog = (content) => `<dialog id="dialog" open><button id="edit" data-phem-on-click="c1.edit" data-phem-autofocus-keyboard>Edit</button>${content}</dialog>`
      await mount(dialog(""))
      respond(dialog("<p>Saved</p>"))
      const inTap = focusInTap()
      const afterMorph = new Promise((resolve) => window.addEventListener("phem:done", () => resolve(document.activeElement), { once: true }))
      await morphOnEdit()
      const { el, parent } = await inTap

      expect(parent.id).toBe("dialog")
      expect(await afterMorph).toBe(el)
    })

    it("does not focus a stand-in when a field that the user edits has the focus", async () => {
      await mount(trigger(page()))
      respond(page(title))
      const inTap = focusInTap()
      await morphOnEdit("#search")

      expect((await inTap).el.id).toBe("search")
      expect(document.activeElement.id).toBe("search")
    })

    it("does not focus a stand-in with no data-phem-autofocus-keyboard", async () => {
      await mount(page())
      respond(page(title))
      const inTap = focusInTap()
      await morphOnEdit()

      expect((await inTap).el.id).toBe("edit")
    })
  })
})
