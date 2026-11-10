import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemToastController from "../dist/phem_toast_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-toast", PhemToastController)
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

// The toast element, a "Save" button that sends an event, and the `attributes` of the controller
// element. The server render has no toasts.
function page(attributes = "") {
  return `<div id="toasts" data-controller="phem-toast" ${attributes}></div>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function toasts() {
  return [...document.querySelectorAll("#toasts > [data-phem-toast]")]
}

function messages() {
  return toasts().map((el) => el.querySelector("[data-phem-toast-description]").textContent)
}

function toast(detail) {
  window.dispatchEvent(new CustomEvent("phem:toast", { detail }))
}

// Sends the "Save" event, and waits for its end.
async function save() {
  const done = nextDone()
  click("#save")
  await done
}

describe("phem-toast", () => {
  it("shows the phem:toast browser event of a handler", async () => {
    await mount(page())
    respond(page(), {}, { events: [{ name: "toast", detail: { message: "Saved", type: "success" } }] })
    await save()

    expect(messages()).toEqual(["Saved"])
    expect(toasts()[0].getAttribute("data-type")).toBe("success")
  })

  it("keeps the toasts and the live region after the morph of an event", async () => {
    await mount(page())
    toast("First")
    respond(page(), {}, { events: [{ name: "toast", detail: "Second" }] })
    await save()

    expect(messages()).toEqual(["First", "Second"])
    expect(document.getElementById("toasts").getAttribute("aria-live")).toBe("polite")
  })

  it("keeps the aria-live attribute of the server render", async () => {
    await mount(page(`aria-live="assertive"`))
    respond(page(`aria-live="off"`))
    await save()

    expect(document.getElementById("toasts").getAttribute("aria-live")).toBe("off")
  })

  it("shows a string detail as the message, and the info type by default", async () => {
    await mount(page())
    toast("Hello")

    expect(messages()).toEqual(["Hello"])
    expect(toasts()[0].getAttribute("data-type")).toBe("info")
  })

  it("shows the message as text, not HTML", async () => {
    await mount(page())
    toast("<img src=x onerror=alert(1)>")

    expect(toasts()[0].querySelector("img")).toBeNull()
    expect(messages()).toEqual(["<img src=x onerror=alert(1)>"])
  })

  it("shows nothing for a detail with no message", async () => {
    await mount(page())
    toast(null)
    toast({ type: "success" })
    toast("")

    expect(toasts()).toEqual([])
  })

  it("shows the error message in place of the default message of a failed event", async () => {
    await mount(page(`data-phem-toast-error-message-value="Not saved."`))
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    await save()

    expect(messages()).toEqual(["Not saved."])
    expect(toasts()[0].getAttribute("data-type")).toBe("error")
    expect(document.querySelector(".phem-error")).toBeNull()
  })

  it("shows nothing for a failed event when a listener before it shows its own message", async () => {
    await mount(page())
    document.getElementById("phem-root").addEventListener("phem:error", (event) => event.preventDefault())
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    await save()

    expect(toasts()).toEqual([])
    expect(document.querySelector(".phem-error")).toBeNull()
  })

  it("shows the rate limited message", async () => {
    await mount(page(`data-phem-toast-rate-limited-message-value="Slow down."`))
    vi.spyOn(console, "warn").mockImplementation(() => {})
    fetch.mockResolvedValueOnce(new Response("", { status: 429, headers: { "Retry-After": "10" } }))
    await save()

    expect(messages()).toEqual(["Slow down."])
    expect(toasts()[0].getAttribute("data-type")).toBe("warning")
  })

  it("shows the detail of another event with the show action", async () => {
    await mount(`<div id="toasts" data-controller="phem-toast" data-action="cart:added@window->phem-toast#show"></div>`)
    window.dispatchEvent(new CustomEvent("cart:added", { detail: { message: "Added" } }))

    expect(messages()).toEqual(["Added"])
  })

  it("removes a toast after the duration, or with its close button", async () => {
    await mount(page(`data-phem-toast-duration-value="1000"`))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    toast("First")
    toast({ message: "Second", duration: 3000 })
    toast({ message: "Third", duration: 0 })

    vi.advanceTimersByTime(1000)
    expect(messages()).toEqual(["Second", "Third"])

    vi.advanceTimersByTime(2000)
    expect(messages()).toEqual(["Third"])

    vi.advanceTimersByTime(60000)
    expect(messages()).toEqual(["Third"])

    const close = toasts()[0].querySelector("[data-phem-toast-close]")
    expect(close.getAttribute("aria-label")).toBe("Close")
    close.click()
    expect(toasts()).toEqual([])
  })

  it("stops the time while the pointer or the focus is on the toast", async () => {
    await mount(page(`data-phem-toast-duration-value="1000"`))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    toast("Hello")
    const el = toasts()[0]
    const close = el.querySelector("[data-phem-toast-close]")

    el.dispatchEvent(new MouseEvent("mouseover", { bubbles: true }))
    vi.advanceTimersByTime(5000)
    expect(toasts()).toHaveLength(1)

    // The pointer moves to the close button, in the toast: the time stays stopped.
    el.dispatchEvent(new MouseEvent("mouseout", { bubbles: true, relatedTarget: close }))
    vi.advanceTimersByTime(5000)
    expect(toasts()).toHaveLength(1)

    close.focus()
    close.dispatchEvent(new MouseEvent("mouseout", { bubbles: true, relatedTarget: document.body }))
    vi.advanceTimersByTime(5000)
    expect(toasts()).toHaveLength(1)

    close.blur()
    vi.advanceTimersByTime(999)
    expect(toasts()).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(toasts()).toEqual([])
  })

  it("keeps the toasts and the live region after a move of the element", async () => {
    await mount(page())
    toast("Hello")
    const el = document.getElementById("toasts")
    el.parentElement.append(el)
    await new Promise((resolve) => setTimeout(resolve, 0))
    respond(page())
    await save()

    expect(messages()).toEqual(["Hello"])
    expect(el.getAttribute("aria-live")).toBe("polite")
  })

  it("gives the time and the close button to a toast of a cloned page", async () => {
    await mount(page(`data-phem-toast-duration-value="1000"`))
    toast({ message: "First", duration: 0 })
    toast("Second")
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

    // A page from the Turbo cache: a copy of the elements, with no listeners.
    document.body.innerHTML = document.body.innerHTML
    await vi.advanceTimersByTimeAsync(0)
    expect(messages()).toEqual(["First", "Second"])

    await vi.advanceTimersByTimeAsync(1000)
    expect(messages()).toEqual(["First"])

    toasts()[0].querySelector("[data-phem-toast-close]").click()
    expect(toasts()).toEqual([])
  })

  it("shows an event in one element only", async () => {
    await mount(`<div id="toasts" data-controller="phem-toast" data-action="phem:toast@window->phem-toast#show"></div>
      <div id="other" data-controller="phem-toast"></div>`)
    toast("Hello")

    expect(document.querySelectorAll("[data-phem-toast]")).toHaveLength(1)
  })

  it("shows a toast again only when the same toast is not on the screen", async () => {
    await mount(page(`data-phem-toast-duration-value="1000"`))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    toast("Saved")
    vi.advanceTimersByTime(800)
    toast("Saved")
    toast({ message: "Saved", type: "success" })

    expect(messages()).toEqual(["Saved", "Saved"])

    // The second "Saved" started the time of the first toast again.
    vi.advanceTimersByTime(800)
    expect(messages()).toEqual(["Saved", "Saved"])
  })

  it("uses the info type and the default duration for a detail that is not valid", async () => {
    await mount(page(`data-phem-toast-duration-value="1000"`))
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    toast({ message: "Hello", type: "info phem-error", duration: "long" })

    expect(toasts()[0].getAttribute("data-type")).toBe("info")
    vi.advanceTimersByTime(999)
    expect(toasts()).toHaveLength(1)
    vi.advanceTimersByTime(1)
    expect(toasts()).toEqual([])
  })

  it("adds a new element of the server render to the element with the toasts", async () => {
    await mount(page())
    toast("Hello")
    respond(`<div id="toasts" data-controller="phem-toast"><div id="title">Title</div></div>
      <button id="save" data-phem-on-click="c1.save">Save</button>`)
    await save()

    expect(document.getElementById("title")).not.toBeNull()
    expect(messages()).toEqual(["Hello"])
  })

  it("makes the toasts from the template of the app", async () => {
    await mount(`<div id="toasts" data-controller="phem-toast">
        <template data-phem-toast-target="template">
          <div class="card"><strong>Note</strong><p data-phem-toast-description></p><button data-phem-toast-close>Close</button></div>
        </template>
      </div>`)
    toast({ message: "Saved", type: "success" })

    const el = toasts()[0]
    expect(el.className).toBe("card")
    expect(el.getAttribute("data-type")).toBe("success")
    expect(el.querySelector("strong").textContent).toBe("Note")
    expect(messages()).toEqual(["Saved"])
    el.querySelector("[data-phem-toast-close]").click()
    expect(toasts()).toEqual([])
  })

  it("gives the position in the stack, and marks the toasts over the limit", async () => {
    await mount(page(`data-phem-toast-limit-value="2"`))
    toast("First")
    toast("Second")
    toast("Third")

    const [first, second, third] = toasts()
    expect(third.style.getPropertyValue("--toast-index")).toBe("0")
    expect(second.style.getPropertyValue("--toast-index")).toBe("1")
    expect(first.style.getPropertyValue("--toast-index")).toBe("2")
    expect(first.hasAttribute("data-limited")).toBe(true)
    expect(second.hasAttribute("data-limited")).toBe(false)

    third.querySelector("[data-phem-toast-close]").click()
    expect(first.hasAttribute("data-limited")).toBe(false)
    expect(first.style.getPropertyValue("--toast-index")).toBe("1")
  })

  it("shows a new toast for the same message while the old one goes away", async () => {
    await mount(page())
    toast("Saved")
    const old = toasts()[0]
    old.getAnimations = () => [{ finished: new Promise(() => {}) }]
    old.querySelector("[data-phem-toast-close]").click()
    toast("Saved")

    expect(toasts()).toHaveLength(2)
    expect(toasts()[1]).not.toBe(old)
    expect(toasts()[1].hasAttribute("data-ending-style")).toBe(false)
  })

  it("removes a toast after the animation of data-ending-style", async () => {
    await mount(page())
    toast("Hello")
    const el = toasts()[0]
    let finish
    el.getAnimations = () => [{ finished: new Promise((resolve) => (finish = resolve)) }]
    el.querySelector("[data-phem-toast-close]").click()

    expect(el.isConnected).toBe(true)
    expect(el.hasAttribute("data-ending-style")).toBe(true)
    finish()
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(el.isConnected).toBe(false)
  })

  it("shows the error message of the root element when the element has no error message", async () => {
    await mount(page(), `data-phem-error-message-value="Root text."`)
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    await save()

    expect(messages()).toEqual(["Root text."])
  })
})
