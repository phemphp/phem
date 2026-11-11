import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

// A reload is a Turbo visit: jsdom cannot load a page.
vi.mock("@hotwired/turbo", async (original) => ({ ...(await original()), visit: vi.fn() }))

// jsdom has no IndexedDB: the records of the offline queue stay in memory.
const db = vi.hoisted(() => ({ records: [], saved: null, saves: 0 }))
vi.mock("../dist/phem_offline_queue.js", () => ({
  OfflineQueue: class {
    static supported() {
      return true
    }

    save(record) {
      db.saved = structuredClone(record)
      db.saves++
    }

    remove() {
      db.saved = null
    }

    async take(url) {
      const records = db.records.filter((r) => r.url === url)
      db.records = db.records.filter((r) => r.url !== url)

      return records
    }

    close() {}
  },
}))

let application
let online = true

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  vi.spyOn(navigator, "onLine", "get").mockImplementation(() => online)
  Turbo.visit.mockClear()
  online = true
  db.records = []
  db.saved = null
  db.saves = 0
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

const ROOT = `data-phem-offline-queue-value="true" data-phem-retry-delay-value="0"`
const PAGE = `<input id="title" data-phem-bind="c1.title" value=""><button id="add" data-phem-on-click="c1.add">Add</button>`

// The JSON of the nth request.
const body = (n) => JSON.parse(fetch.mock.calls[n][1].body)

const goOnline = () => {
  online = true
  window.dispatchEvent(new Event("online"))
}

describe("the offline queue", () => {
  it("keeps the events that wait for the connection, each with its own bound values", async () => {
    await mount(PAGE, ROOT)
    online = false
    fetch.mockRejectedValue(new TypeError("Failed to fetch"))

    document.getElementById("title").value = "first"
    click("#add")
    document.getElementById("title").value = "second"
    click("#add")
    await vi.waitFor(() => expect(db.saved?.events).toHaveLength(2))

    expect(db.saved.url).toBe(window.location.href)
    expect(db.saved.state).toBe("{}")
    expect(db.saved.signature).toBe("s1")
    expect(db.saved.events.map((e) => [e.handler, e.event.type, e.bound])).toEqual([
      ["c1.add", "click", { "c1.title": "first" }],
      ["c1.add", "click", { "c1.title": "second" }],
    ])

    // Back online: the events go in their order, each with its own values, and the record leaves.
    fetch.mockReset()
    respond(`${PAGE}`, {}, { signature: "s2" })
    respond(`${PAGE}`, {}, { signature: "s3" })
    const first = nextDone()
    goOnline()
    await first
    await vi.waitFor(() => expect(db.saved).toBeNull())

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(body(0).bound).toEqual({ "c1.title": "first" })
    expect(body(1).bound).toEqual({ "c1.title": "second" })
    expect(body(1).signature).toBe("s2")
  })

  it("keeps an event that started online and waits for the connection", async () => {
    await mount(PAGE, ROOT)
    fetch.mockImplementation(() => {
      online = false
      return Promise.reject(new TypeError("Failed to fetch"))
    })

    document.getElementById("title").value = "draft"
    click("#add")
    await vi.waitFor(() => expect(db.saved?.events).toHaveLength(1))

    expect(db.saved.events[0].bound).toEqual({ "c1.title": "draft" })

    fetch.mockReset()
    respond(PAGE)
    const done = nextDone()
    goOnline()
    await done
  })

  it("keeps no record while the browser is online", async () => {
    await mount(PAGE, ROOT)
    respond(PAGE)
    const done = nextDone()
    click("#add")
    await done

    expect(db.saves).toBe(0)
  })

  it("keeps nothing with no data-phem-offline-queue-value", async () => {
    await mount(PAGE, `data-phem-retry-delay-value="0"`)
    online = false
    fetch.mockRejectedValue(new TypeError("Failed to fetch"))
    document.getElementById("title").value = "first"
    click("#add")
    document.getElementById("title").value = "second"
    click("#add")
    await new Promise((resolve) => setTimeout(resolve, 10))
    document.getElementById("title").value = "third"

    expect(db.saves).toBe(0)

    // Each event keeps the values of its time, also the event that waited in the queue.
    fetch.mockReset()
    respond(PAGE)
    respond(PAGE)
    goOnline()
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))

    expect(body(0).bound).toEqual({ "c1.title": "first" })
    expect(body(1).bound).toEqual({ "c1.title": "second" })
  })

  it("sends the events of an earlier load of the page with their signed state", async () => {
    db.records = [{
      url: window.location.href,
      state: '{"c1":{"count":1}}',
      signature: "old",
      events: [
        { handler: "c1.add", event: { type: "click", value: null, checked: null, key: null, form: null }, bound: { "c1.title": "first" } },
        { handler: "c1.add", event: { type: "click", value: null, checked: null, key: null, form: null }, bound: { "c1.title": "second" } },
      ],
    }]
    const replay = vi.fn()
    window.addEventListener("phem:replay", replay, { once: true })
    respond(`<p id="count">2</p>`, {}, { signature: "s2" })
    respond(`<p id="count">3</p>`, {}, { signature: "s3" })

    await mount(`<p id="count">0</p>`, ROOT)
    await vi.waitFor(() => expect(document.getElementById("count").textContent).toBe("3"))

    expect(replay.mock.calls[0][0].detail).toEqual({ events: 2 })
    expect(body(0)).toMatchObject({ signature: "old", handler: "c1.add", bound: { "c1.title": "first" }, stale: ["c1"] })
    expect(body(0).state).toBe('{"c1":{"count":1}}')
    expect(body(1)).toMatchObject({ signature: "s2", bound: { "c1.title": "second" } })
    expect(body(1).stale).toBeUndefined()
    await vi.waitFor(() => expect(db.saved).toBeNull())
  })

  it("drops the events when the server does not take the state, with no reload", async () => {
    db.records = [{
      url: window.location.href,
      state: "{}",
      signature: "expired",
      events: [
        { handler: "c1.add", event: { type: "click", value: null, checked: null, key: null, form: null }, bound: {} },
        { handler: "c1.add", event: { type: "click", value: null, checked: null, key: null, form: null }, bound: {} },
      ],
    }]
    const failed = new Promise((resolve) => window.addEventListener("phem:replay-failed", resolve, { once: true }))
    fetch.mockResolvedValueOnce(new Response("", { status: 409 }))

    await mount(PAGE, ROOT)
    const event = await failed

    expect(event.detail).toEqual({ events: 2, status: 409 })
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(Turbo.visit).not.toHaveBeenCalled()

    // The page has its own state again: the next event sends it, and renders nothing more.
    respond(PAGE)
    const done = nextDone()
    click("#add")
    await done

    expect(body(1)).toMatchObject({ signature: "s1" })
    expect(body(1).stale).toBeUndefined()
  })

  it("keeps the values of the time the browser went offline for an event that waited in the queue", async () => {
    await mount(PAGE, ROOT)
    // The first event hangs until the end of the test.
    let answerFirst
    fetch.mockImplementationOnce(() => new Promise((resolve) => (answerFirst = resolve)))

    document.getElementById("title").value = "a"
    click("#add")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))
    document.getElementById("title").value = "b"
    click("#add")
    online = false
    window.dispatchEvent(new Event("offline"))
    document.getElementById("title").value = "c"

    expect(db.saved.events.map((e) => e.bound)).toEqual([{ "c1.title": "a" }, { "c1.title": "b" }])

    respond(PAGE)
    online = true
    answerFirst(new Response(JSON.stringify({ changes: {}, signature: "s2" }), { status: 200 }))
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))

    expect(body(1).bound).toEqual({ "c1.title": "b" })
  })

  it("does not count the wait for the connection as a try", async () => {
    await mount(PAGE, `${ROOT} data-phem-retries-value="0"`)
    online = false
    click("#add")
    await vi.waitFor(() => expect(db.saved?.events).toHaveLength(1))

    expect(fetch).not.toHaveBeenCalled()

    respond(PAGE)
    const done = nextDone()
    goOnline()

    expect((await done).detail.ok).toBe(true)
  })

  it("leaves the events to the next load when the page leaves, with no error", async () => {
    await mount(PAGE, ROOT)
    online = false
    click("#add")
    await vi.waitFor(() => expect(db.saved?.events).toHaveLength(1))
    const errors = vi.fn()
    window.addEventListener("phem:error", errors)

    document.getElementById("phem-root").remove()
    await new Promise((resolve) => setTimeout(resolve, 0))
    goOnline()
    await new Promise((resolve) => setTimeout(resolve, 10))
    window.removeEventListener("phem:error", errors)

    expect(fetch).not.toHaveBeenCalled()
    expect(errors).not.toHaveBeenCalled()
    expect(document.querySelector(".phem-error")).toBeNull()
    expect(db.saved?.events).toHaveLength(1)
  })

  it("keeps no event with a password", async () => {
    await mount(`<form><input type="password" name="password" value=""><button id="login" type="button" data-phem-on-click="c1.login">Log in</button></form>`, ROOT)
    online = false
    document.querySelector("[name=password]").value = "secret"
    click("#login")
    await new Promise((resolve) => setTimeout(resolve, 10))

    expect(db.saved).toBeNull()

    respond(PAGE)
    const done = nextDone()
    goOnline()
    await done
  })

  it("starts each segment of a record from its own state", async () => {
    const click = { type: "click", value: null, checked: null, key: null, form: null }
    db.records = [{
      url: window.location.href,
      state: "{}",
      signature: "first",
      events: [
        { handler: "c1.add", event: click, bound: {} },
        { handler: "c1.add", event: click, bound: {}, state: '{"c1":{"n":5}}', signature: "second" },
      ],
    }]
    respond(PAGE, {}, { signature: "s2" })
    respond(PAGE, {}, { signature: "s3" })

    await mount(PAGE, ROOT)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))

    expect(body(0)).toMatchObject({ signature: "first", stale: ["c1"] })
    expect(body(1)).toMatchObject({ signature: "second", state: '{"c1":{"n":5}}', stale: ["c1"] })
  })

  it("counts the events that did not go when the server does not take a later event", async () => {
    const click = { type: "click", value: null, checked: null, key: null, form: null }
    db.records = [{ url: window.location.href, state: "{}", signature: "s0", events: [0, 1, 2].map(() => ({ handler: "c1.add", event: click, bound: {} })) }]
    const failed = new Promise((resolve) => window.addEventListener("phem:replay-failed", resolve, { once: true }))
    fetch.mockResolvedValueOnce(new Response("", { status: 500 }))
    fetch.mockResolvedValueOnce(new Response("", { status: 409 }))

    await mount(PAGE, ROOT)

    expect((await failed).detail).toEqual({ events: 2, status: 409 })
    expect(fetch).toHaveBeenCalledTimes(2)
  })
})
