import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemAvatarController from "../dist/phem_avatar_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-avatar", PhemAvatarController)
})

// jsdom loads no image: an image is not complete until the test says so.
let images

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  images = new Map()
  vi.spyOn(HTMLImageElement.prototype, "complete", "get").mockImplementation(function () {
    return images.get(this.getAttribute("src"))?.complete ?? false
  })
  vi.spyOn(HTMLImageElement.prototype, "naturalWidth", "get").mockImplementation(function () {
    return images.get(this.getAttribute("src"))?.width ?? 0
  })
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  vi.useRealTimers()
  document.body.innerHTML = ""
})

function avatar(attributes = "", src = "/ada.png") {
  const image = src === null ? "" : `<img id="image" data-phem-avatar-target="image" src="${src}" alt="Ada">`

  return `<span id="avatar" data-controller="phem-avatar" ${attributes}>
      ${image}
      <span id="fallback" data-phem-avatar-target="fallback">AL</span>
    </span>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function image() {
  return document.getElementById("image")
}

function fallback() {
  return document.getElementById("fallback")
}

// The statuses of the `phem-avatar:status` events.
function statuses() {
  const list = []
  document.addEventListener("phem-avatar:status", (event) => list.push(event.detail.status))

  return list
}

describe("phem-avatar", () => {
  it("hides the image until it loads", async () => {
    const list = statuses()
    await mount(avatar())

    expect(image().hidden).toBe(true)
    expect(image().hasAttribute("data-loading")).toBe(true)
    expect(fallback().hidden).toBe(false)

    images.set("/ada.png", { complete: true, width: 40 })
    image().dispatchEvent(new Event("load"))

    expect(image().hidden).toBe(false)
    expect(image().hasAttribute("data-loading")).toBe(false)
    expect(image().hasAttribute("data-open")).toBe(true)
    expect(fallback().hidden).toBe(true)
    expect(list).toEqual(["loading", "loaded"])
  })

  it("shows the fallback when the image does not load", async () => {
    const list = statuses()
    await mount(avatar())

    image().dispatchEvent(new Event("error"))

    expect(image().hidden).toBe(true)
    expect(image().hasAttribute("data-error")).toBe(true)
    expect(image().hasAttribute("data-loading")).toBe(false)
    expect(fallback().hidden).toBe(false)
    expect(list).toEqual(["loading", "error"])
  })

  it("shows an image of the cache at once", async () => {
    images.set("/ada.png", { complete: true, width: 40 })
    const list = statuses()
    await mount(avatar())

    expect(image().hidden).toBe(false)
    expect(image().hasAttribute("data-starting-style")).toBe(false)
    expect(fallback().hidden).toBe(true)
    expect(list).toEqual(["loaded"])
  })

  it("gives the error status to an image that failed before the connection", async () => {
    images.set("/ada.png", { complete: true, width: 0 })
    await mount(avatar())

    expect(image().hasAttribute("data-error")).toBe(true)
    expect(fallback().hidden).toBe(false)
  })

  it("shows the fallback with no image", async () => {
    const list = statuses()
    await mount(avatar("", null))

    expect(fallback().hidden).toBe(false)
    expect(list).toEqual([])
  })

  it("shows the fallback after the delay", async () => {
    vi.useFakeTimers()
    document.body.innerHTML = avatar('data-phem-avatar-delay-value="600"')
    await vi.advanceTimersByTimeAsync(0)

    expect(fallback().hidden).toBe(true)

    await vi.advanceTimersByTimeAsync(599)
    expect(fallback().hidden).toBe(true)

    await vi.advanceTimersByTimeAsync(1)
    expect(fallback().hidden).toBe(false)
  })

  it("does not show the fallback when the image loads before the delay", async () => {
    vi.useFakeTimers()
    document.body.innerHTML = avatar('data-phem-avatar-delay-value="600"')
    await vi.advanceTimersByTimeAsync(0)

    images.set("/ada.png", { complete: true, width: 40 })
    image().dispatchEvent(new Event("load"))
    await vi.advanceTimersByTimeAsync(600)

    expect(image().hidden).toBe(false)
    expect(fallback().hidden).toBe(true)
  })

  it("loads a new src again", async () => {
    images.set("/ada.png", { complete: true, width: 40 })
    const list = statuses()
    await mount(avatar())

    image().setAttribute("src", "/grace.png")
    await Promise.resolve()

    expect(image().hidden).toBe(true)
    expect(image().hasAttribute("data-loading")).toBe(true)
    expect(fallback().hidden).toBe(false)
    expect(list).toEqual(["loaded", "loading"])
  })

  it("keeps the status after the morph of an event, and loads the src of the server render", async () => {
    await mount(avatar())
    images.set("/ada.png", { complete: true, width: 40 })
    image().dispatchEvent(new Event("load"))

    respond(avatar())
    const done = nextDone()
    click("#save")
    await done
    await Promise.resolve()

    expect(image().hidden).toBe(false)
    expect(fallback().hidden).toBe(true)

    respond(avatar("", "/grace.png"))
    const again = nextDone()
    click("#save")
    await again
    await Promise.resolve()

    expect(image().getAttribute("src")).toBe("/grace.png")
    expect(image().hidden).toBe(true)
    expect(image().hasAttribute("data-loading")).toBe(true)
    expect(fallback().hidden).toBe(false)
  })
})
