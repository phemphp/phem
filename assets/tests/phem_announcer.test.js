import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

vi.mock("@hotwired/turbo", async (original) => ({ ...(await original()), visit: vi.fn() }))

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

const BUTTON = `<p>Results</p><button data-phem-on-click="c1.search">Search</button>`

// An event whose answer has these `announce` browser events (see Phem\Announcements).
async function announce(...details) {
  respond(BUTTON, {}, { events: details.map((detail) => ({ name: "announce", detail })) })
  const done = nextDone()
  click("button")
  await done
}

const regions = () => document.querySelectorAll("[data-phem-announcer]")
const messages = () => [...regions()[0].querySelectorAll("p")].map((p) => p.textContent)

// A Turbo visit: a new body, then `turbo:load`.
async function visit() {
  await mount(BUTTON)
  document.dispatchEvent(new Event("turbo:load"))
}

describe("announce", () => {
  it("has a hidden live region out of the root before the first message", async () => {
    await visit()

    expect(regions()).toHaveLength(1)
    const [region] = regions()
    expect(region.parentElement).toBe(document.body)
    expect(region.getAttribute("role")).toBe("status")
    expect(region.getAttribute("aria-live")).toBe("polite")
    expect(region.style.position).toBe("absolute")
    expect(region.style.width).toBe("1px")
    expect(region.textContent).toBe("")
  })

  it("puts the message in the region", async () => {
    await visit()
    const [region] = regions()

    await announce({ message: "3 results" })

    await vi.waitFor(() => expect(messages()).toEqual(["3 results"]))
    expect(regions()[0]).toBe(region)
  })

  it("shows the messages of one answer together, and a string detail is the message", async () => {
    await visit()

    await announce({ message: "3 results" }, "Saved", { message: " " }, { message: ["a"] })

    await vi.waitFor(() => expect(messages()).toEqual(["3 results", "Saved"]))
  })

  it("empties the region before a new message, also the same message again", async () => {
    await visit()
    await announce({ message: "3 results" })
    await vi.waitFor(() => expect(messages()).toEqual(["3 results"]))

    await announce({ message: "3 results" })

    // The screen reader sees a new text.
    expect(messages()).toEqual([])
    await vi.waitFor(() => expect(messages()).toEqual(["3 results"]))
    expect(regions()).toHaveLength(1)
  })

  it("puts the message in the region of the new page when a visit comes in the delay", async () => {
    await visit()
    await announce({ message: "Saved" })

    await visit()

    await vi.waitFor(() => expect(messages()).toEqual(["Saved"]))
    expect(regions()).toHaveLength(1)
  })

  it("keeps no old message in the snapshot of Turbo, and uses the region of a page from the cache", async () => {
    await visit()
    await announce({ message: "Saved" })
    await vi.waitFor(() => expect(messages()).toEqual(["Saved"]))

    document.dispatchEvent(new Event("turbo:before-cache"))
    expect(messages()).toEqual([])

    // The Back button: the body of the snapshot, with its region.
    const cached = regions()[0].cloneNode(true)
    await mount(BUTTON)
    document.body.append(cached)
    document.dispatchEvent(new Event("turbo:load"))
    await announce({ message: "Again" })

    await vi.waitFor(() => expect(messages()).toEqual(["Again"]))
    expect(regions()).toHaveLength(1)
  })
})
