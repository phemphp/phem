import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
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

describe("phem:announce", () => {
  const grid = (text, attribute = "data-phem-announce") => `<p ${attribute}>${text}</p><button data-phem-on-click="c1.search">Search</button>`

  // An event whose answer renders this markup.
  async function search(markup) {
    respond(markup)
    const done = nextDone()
    click("button")
    await done
  }

  // The `phem:announce` events of the page, as the live region gets them.
  let heard
  const listen = (event) => heard.push(event.detail)

  beforeEach(() => {
    heard = []
    window.addEventListener("phem:announce", listen)
  })

  afterEach(() => window.removeEventListener("phem:announce", listen))

  it("announces the new text of the element after the morph of an event", async () => {
    await mount(grid("3 results"))

    await search(grid("12   results\n"))

    expect(heard).toEqual(["12 results"])
    await vi.waitFor(() => expect(messages()).toEqual(["12 results"]))
  })

  it("announces nothing when the text stays the same, also in a new element", async () => {
    await mount(grid("3 results"))

    await search(`<div>${grid("3 results")}</div>`)

    expect(heard).toEqual([])
  })

  it("announces a new element, but not an empty one", async () => {
    await mount(grid("3 results", ""))

    await search(`<p data-phem-announce>Saved</p><p data-phem-announce> </p><p data-phem-announce>3 results</p><button data-phem-on-click="c1.search">Search</button>`)

    expect(heard).toEqual(["Saved", "3 results"])
    await vi.waitFor(() => expect(messages()).toEqual(["Saved", "3 results"]))
  })

  it("announces with the messages of $e->announce() of the same answer", async () => {
    await mount(grid("3 results"))

    respond(grid("12 results"), {}, { events: [{ name: "announce", detail: { message: "Filter added" } }] })
    const done = nextDone()
    click("button")
    await done

    await vi.waitFor(() => expect(messages()).toEqual(["12 results", "Filter added"]))
  })

  it("announces the outer element only, with the text of the element in it", async () => {
    const cart = (n) => `<div data-phem-announce>Cart: <span data-phem-announce>${n} items</span></div><button data-phem-on-click="c1.search">Search</button>`
    await mount(cart(3))

    await search(cart(4))

    expect(heard).toEqual(["Cart: 4 items"])
    await vi.waitFor(() => expect(messages()).toEqual(["Cart: 4 items"]))
  })

  it("reads the text as a screen reader: with no hidden content", async () => {
    await mount(grid("3 results"))

    await search(`<p data-phem-announce>12 results <span hidden>(filtered)</span><span aria-hidden="true">★</span><template>x</template></p><div aria-hidden="true"><p data-phem-announce>Hidden</p></div><p data-phem-announce hidden>Gone</p><button data-phem-on-click="c1.search">Search</button>`)

    expect(heard).toEqual(["12 results"])
    await vi.waitFor(() => expect(messages()).toEqual(["12 results"]))
  })

  it("does not announce a push that renders while the answer renders", async () => {
    await mount(grid("3 results"))
    document.body.insertAdjacentHTML("beforeend", `<div id="online"><p data-phem-announce>5 online</p></div>`)
    // The push comes before the render of the answer, and Turbo renders both after the repaint.
    window.addEventListener("phem:receive", () => Turbo.renderStreamMessage(`<turbo-stream action="replace" target="online"><template><div id="online"><p data-phem-announce>6 online</p></div></template></turbo-stream>`), { once: true })

    await search(grid("12 results"))

    expect(document.querySelector("#online").textContent).toBe("6 online")
    expect(heard).toEqual(["12 results"])
    await vi.waitFor(() => expect(messages()).toEqual(["12 results"]))
  })

  it("does not announce for an event of the browser: a poll", async () => {
    await mount(`<p data-phem-on-poll="c1.tick" data-phem-poll="20" data-phem-announce>3 results</p>`)
    respond(`<p data-phem-announce>12 results</p>`)

    await nextDone()

    expect(document.querySelector("[data-phem-announce]").textContent).toBe("12 results")
    expect(heard).toEqual([])
  })
})
