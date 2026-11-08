import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import { DIALOG, clickAt, mount, settle, stubConfirm, stubDialogs } from "./support.js"

// The visit after a confirm: jsdom cannot load a page.
vi.mock("@hotwired/turbo", async (original) => ({ ...(await original()), visit: vi.fn() }))

let application

beforeAll(() => {
  stubDialogs()
  application = Application.start()
  application.register("phem", PhemController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  Turbo.visit.mockClear()
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

const OTHER = "http://localhost:3000/other"

// A guarded form with a bound field, the `attributes` of the guard, a link, and the dialog.
function page(attributes = "") {
  return `<form data-phem-guard="Discard the draft?" ${attributes}><input name="title" data-phem-bind="c1.title" value=""></form>
    <a id="link" href="/other">Other</a>
    ${DIALOG}`
}

const dialog = () => document.getElementById("confirm")
const message = () => dialog().querySelector("[data-phem-confirm-message]")
const accept = () => dialog().querySelector("[data-phem-confirm-accept]")
const link = () => document.getElementById("link")
const controller = () => application.getControllerForElementAndIdentifier(document.getElementById("phem-root"), "phem")

// The user changes the bound field.
function edit() {
  const input = document.querySelector("input[name=title]")
  input.value = "Draft"
  input.dispatchEvent(new Event("input", { bubbles: true }))
}

// The user clicks the link: Turbo dispatches `turbo:click` on it. True when a listener cancels it,
// and the click too (the browser follows no link).
function follow(el = link()) {
  const click = new MouseEvent("click", { cancelable: true })
  const event = new CustomEvent("turbo:click", { bubbles: true, cancelable: true, detail: { url: el.href, originalEvent: click } })
  el.dispatchEvent(event)

  return event.defaultPrevented && click.defaultPrevented
}

// Turbo proposes a visit to `url`: true when a listener cancels it.
function visit(url = OTHER) {
  const event = new CustomEvent("turbo:before-visit", { cancelable: true, detail: { url } })
  document.dispatchEvent(event)

  return event.defaultPrevented
}

// Turbo starts the visit to `url`.
function start(url = OTHER) {
  document.dispatchEvent(new CustomEvent("turbo:visit", { detail: { url, action: "advance" } }))
}

// Whether the guard asks before the user leaves the page (`beforeunload`).
function asksBeforeUnload() {
  const event = new Event("beforeunload", { cancelable: true })
  window.dispatchEvent(event)

  return event.defaultPrevented
}

describe("data-phem-guard", () => {
  it("asks with window.confirm() with no dialog, and lets the visit go after a confirm", async () => {
    await mount(page())
    const confirm = stubConfirm(true)
    edit()

    expect(follow()).toBe(false)
    expect(visit()).toBe(false)
    expect(confirm).toHaveBeenCalledWith("Discard the draft?")
  })

  it("asks nothing when the guard has no dirty field", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))

    expect(follow()).toBe(false)
    expect(visit()).toBe(false)
    expect(dialog().open).toBe(false)
  })

  it("leaves the page with no question once Turbo starts the confirmed visit", async () => {
    await mount(page())
    stubConfirm(true)
    edit()
    visit()
    expect(asksBeforeUnload()).toBe(true)

    start()
    expect(asksBeforeUnload()).toBe(false)
  })

  it("asks again when another listener cancels the confirmed visit", async () => {
    await mount(page())
    const confirm = stubConfirm(true)
    edit()
    const cancel = (event) => event.preventDefault()
    document.addEventListener("turbo:before-visit", cancel)
    visit()
    document.removeEventListener("turbo:before-visit", cancel)

    expect(asksBeforeUnload()).toBe(true)
    visit()
    expect(confirm).toHaveBeenCalledTimes(2)
  })
})

describe("data-phem-guard with a dialog", () => {
  it("cancels the click of a link and asks in the dialog", async () => {
    await mount(page('data-phem-guard-dialog="#confirm" data-phem-guard-accept="Leave"'))
    const confirm = stubConfirm(true)
    edit()

    expect(follow()).toBe(true)
    expect(confirm).not.toHaveBeenCalled()
    expect(dialog().open).toBe(true)
    expect(message().textContent).toBe("Discard the draft?")
    expect(accept().textContent).toBe("Leave")
    expect(Turbo.visit).not.toHaveBeenCalled()
  })

  it("asks the message of the controller when the guard has no message", async () => {
    await mount(page().replace('data-phem-guard="Discard the draft?"', 'data-phem-guard data-phem-guard-dialog="#confirm"'), 'data-phem-guard-message-value="Leave the page?"')
    edit()
    follow()

    expect(message().textContent).toBe("Leave the page?")
    expect(accept().textContent).toBe("Yes")
  })

  it("visits the link after a confirm, with no second question", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    const confirm = stubConfirm(false)
    edit()
    follow()
    dialog().close("confirm")
    await settle()

    expect(Turbo.visit).toHaveBeenCalledExactlyOnceWith(OTHER, { action: "advance", acceptsStreamResponse: false })
    expect(visit()).toBe(false)
    expect(confirm).not.toHaveBeenCalled()
    expect(dialog().open).toBe(false)
    expect(message().textContent).toBe("")
    expect(controller().guardQuestion).toBeNull()

    // The visit starts: the page leaves.
    start()
    expect(asksBeforeUnload()).toBe(false)
  })

  it("asks again when another listener cancels the visit after a confirm", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    edit()
    follow()
    dialog().close("confirm")
    await settle()
    const cancel = (event) => event.preventDefault()
    document.addEventListener("turbo:before-visit", cancel)
    visit()
    document.removeEventListener("turbo:before-visit", cancel)

    expect(asksBeforeUnload()).toBe(true)
    expect(follow()).toBe(true)
    expect(dialog().open).toBe(true)
  })

  it("visits with the options of the link", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    link().setAttribute("data-turbo-action", "replace")
    link().setAttribute("data-turbo-stream", "")
    edit()
    follow()
    dialog().close("confirm")
    await settle()

    expect(Turbo.visit).toHaveBeenCalledExactlyOnceWith(OTHER, { action: "replace", acceptsStreamResponse: true })
  })

  it("asks with window.confirm() for a visit that is not a click on a link", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    const confirm = stubConfirm(false)
    edit()

    expect(visit()).toBe(true)
    expect(confirm).toHaveBeenCalledWith("Discard the draft?")
    expect(dialog().open).toBe(false)
  })

  it("stays on the page when the dialog closes in another way, and asks again", async () => {
    await mount(page('data-phem-guard-dialog="#confirm" data-phem-guard-accept="Leave"'))
    edit()
    follow()
    dialog().close("cancel")
    await settle()

    expect(Turbo.visit).not.toHaveBeenCalled()
    expect(accept().textContent).toBe("Yes")
    expect(controller().guardQuestion).toBeNull()

    // Escape closes the dialog with no return value.
    expect(follow()).toBe(true)
    dialog().close()
    await settle()

    expect(Turbo.visit).not.toHaveBeenCalled()
    expect(dialog().open).toBe(false)
  })

  it("closes the dialog on a tap on the backdrop, and stays on the page", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    edit()
    follow()
    vi.spyOn(dialog(), "getBoundingClientRect").mockReturnValue(new DOMRect(100, 100, 200, 200))

    clickAt(dialog(), [150, 150])
    expect(dialog().open).toBe(true)

    clickAt(dialog(), [500, 500])
    await settle()

    expect(dialog().open).toBe(false)
    expect(Turbo.visit).not.toHaveBeenCalled()
  })

  it("ends the question when Turbo caches the page", async () => {
    await mount(page('data-phem-guard-dialog="#confirm" data-phem-guard-accept="Leave"'))
    edit()
    follow()

    // A visit back with the button of the browser: Turbo asks no guard, and caches the page.
    const snapshot = await Turbo.session.view.cacheSnapshot()
    Turbo.session.clearCache()
    const cached = snapshot.element.querySelector("#confirm")

    expect(cached.hasAttribute("open")).toBe(false)
    expect(cached.querySelector("[data-phem-confirm-message]").textContent).toBe("")
    expect(cached.querySelector("[data-phem-confirm-accept]").textContent).toBe("Yes")

    await settle()
    expect(Turbo.visit).not.toHaveBeenCalled()
    expect(controller().guardQuestion).toBeNull()
  })

  it("replaces the open question with a new click", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    edit()
    follow()
    link().setAttribute("href", "/two")
    expect(follow()).toBe(true)
    dialog().close("confirm")
    await settle()

    expect(Turbo.visit).toHaveBeenCalledExactlyOnceWith("http://localhost:3000/two", { action: "advance", acceptsStreamResponse: false })
  })

  it("asks with window.confirm() when the selector finds no dialog", async () => {
    await mount(page('data-phem-guard-dialog="#nothing"'))
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    const confirm = stubConfirm(false)
    edit()

    expect(follow()).toBe(false)
    expect(visit()).toBe(true)
    expect(confirm).toHaveBeenCalledWith("Discard the draft?")
    expect(error).toHaveBeenCalledWith('[phem] data-phem-guard: "#nothing" is not a <dialog> of the page')
  })

  it("asks with window.confirm() when the dialog is open with no question", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    const confirm = stubConfirm(true)
    dialog().showModal()
    edit()

    expect(follow()).toBe(false)
    expect(visit()).toBe(false)
    expect(confirm).toHaveBeenCalledWith("Discard the draft?")
    expect(error).toHaveBeenCalledWith("[phem] data-phem-guard: the dialog is open with no question")
  })

  it("stops the question when the controller disconnects", async () => {
    await mount(page('data-phem-guard-dialog="#confirm"'))
    edit()
    follow()
    const kept = dialog()
    document.getElementById("phem-root").removeAttribute("data-controller")
    await settle()

    expect(kept.open).toBe(false)
    expect(Turbo.visit).not.toHaveBeenCalled()
  })
})
