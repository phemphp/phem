import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import { resetTabSync } from "../dist/phem_tab_sync.js"
import { click, mount, nextDone, respond } from "./support.js"

// A redirect is a Turbo visit: jsdom cannot load a page.
vi.mock("@hotwired/turbo", async (original) => ({ ...(await original()), visit: vi.fn() }))

// The channels of all the tabs, in this one jsdom window: a message goes to the other channels
// of the same name, in a later task, as in a browser.
class FakeChannel extends EventTarget {
  static open = new Set()

  constructor(name) {
    super()
    this.name = name
    FakeChannel.open.add(this)
  }

  postMessage(data) {
    for (const channel of FakeChannel.open) {
      if (channel === this || channel.name !== this.name) continue

      setTimeout(() => channel.dispatchEvent(new MessageEvent("message", { data: structuredClone(data) })), 0)
    }
  }

  close() {
    FakeChannel.open.delete(this)
  }
}

let application
let visibility = "visible"

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  vi.stubGlobal("BroadcastChannel", FakeChannel)
  vi.spyOn(document, "visibilityState", "get").mockImplementation(() => visibility)
  visibility = "visible"
  Turbo.visit.mockClear()
  // Each element is in view: an `onVisible` element sends its event at once.
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      constructor(callback) {
        this.callback = callback
      }
      observe(el) {
        queueMicrotask(() => this.callback([{ isIntersecting: true, target: el }]))
      }
      unobserve() {}
      disconnect() {}
    },
  )
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
  resetTabSync()
  FakeChannel.open.clear()
})

const ROOT = `data-phem-sync-tabs-value="true"`
const PAGE = `<p id="count">1</p><button id="add" data-phem-on-click="c1.add">Add</button>`

// The channel of another tab of this browser: it gets the messages of the page, and sends its own.
function otherTab() {
  const tab = new FakeChannel("phem-sync-tabs")
  tab.messages = []
  tab.addEventListener("message", (event) => tab.messages.push(event.data))

  return tab
}

const wait = (ms = 100) => new Promise((resolve) => setTimeout(resolve, ms))
const body = (n) => JSON.parse(fetch.mock.calls[n][1].body)
const json = (data) => fetch.mockResolvedValueOnce(new Response(JSON.stringify(data), { status: 200, headers: { "Content-Type": "application/json" } }))

// The page leaves (a Turbo visit to another page), and comes back.
const leave = async () => {
  document.body.innerHTML = ""
  await wait(0)
}

describe("the sync between tabs", () => {
  it("tells the other tabs of the page after an event of the user", async () => {
    await mount(PAGE, ROOT)
    const tab = otherTab()
    respond('<p id="count">2</p>')
    click("#add")
    await nextDone()

    await vi.waitFor(() => expect(tab.messages).toEqual([{ path: window.location.pathname }]))
  })

  it("tells nothing after an event that failed", async () => {
    await mount(PAGE, ROOT)
    const tab = otherTab()
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    vi.spyOn(console, "error").mockImplementation(() => {})
    click("#add")
    await nextDone()
    await wait()

    expect(tab.messages).toEqual([])
  })

  it("refreshes the content of the page after an event of another tab", async () => {
    await mount(PAGE, ROOT)
    respond('<p id="count">2</p>')
    otherTab().postMessage({ path: window.location.pathname })

    await vi.waitFor(() => expect(document.getElementById("count").textContent).toBe("2"))
    expect(body(0).handler).toBeNull()
    expect(body(0).stale).toEqual(["c1"])
    expect(body(0).event).toMatchObject({ type: "refresh" })
  })

  it("does not tell the other tabs after its refresh: the tabs do not refresh each other again", async () => {
    await mount(PAGE, ROOT)
    const tab = otherTab()
    respond('<p id="count">2</p>')
    tab.postMessage({ path: window.location.pathname })
    await vi.waitFor(() => expect(document.getElementById("count").textContent).toBe("2"))
    await wait()

    expect(tab.messages).toEqual([])
  })

  it("does not refresh after an event of another page", async () => {
    await mount(PAGE, ROOT)
    otherTab().postMessage({ path: "/another-page" })
    await wait()

    expect(fetch).not.toHaveBeenCalled()
  })

  it("refreshes a hidden tab when it becomes visible, one time for all the events", async () => {
    await mount(PAGE, ROOT)
    visibility = "hidden"
    const tab = otherTab()
    tab.postMessage({ path: window.location.pathname })
    tab.postMessage({ path: window.location.pathname })
    await wait()

    expect(fetch).not.toHaveBeenCalled()

    respond('<p id="count">3</p>')
    visibility = "visible"
    document.dispatchEvent(new Event("visibilitychange"))

    await vi.waitFor(() => expect(document.getElementById("count").textContent).toBe("3"))
    await wait()
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("is off by default", async () => {
    await mount(PAGE)
    const tab = otherTab()
    tab.postMessage({ path: window.location.pathname })
    respond('<p id="count">2</p>')
    click("#add")
    await nextDone()
    await wait()

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(tab.messages).toEqual([])
  })

  it.each([
    ["a phem:live event", '<input id="on" type="checkbox" data-phem-bind="c1.on" data-phem-live>', () => click("#on")],
    ["a poll", '<span data-phem-on-poll="c1.check" data-phem-poll="20">Waiting</span>', () => {}],
    ["an onVisible event", '<p data-phem-on-visible="c1.more">Loading…</p>', () => {}],
    ["a Suspense load", '<phem-suspense data-phem-c="b1" data-phem-load="b1.load"><p>Loading…</p></phem-suspense>', () => {}],
  ])("tells nothing after %s", async (_, markup, trigger) => {
    // The answer has no trigger: one event only. A load and an onVisible event go when the page
    // connects: the other tab listens before.
    respond("<p>Done</p>")
    const tab = otherTab()
    await mount(markup, ROOT)
    trigger()
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    await wait()

    expect(tab.messages).toEqual([])
  })

  it("tells the other tabs before a redirect, and nothing after a redirect that it blocks", async () => {
    await mount(PAGE, ROOT)
    const tab = otherTab()
    vi.spyOn(console, "error").mockImplementation(() => {})
    json({ redirect: "javascript:alert(1)", signature: "s2" })
    click("#add")
    await nextDone()
    await wait()

    expect(tab.messages).toEqual([])

    json({ redirect: "/orders", signature: "s2" })
    click("#add")
    await nextDone()

    expect(Turbo.visit).toHaveBeenCalledWith(new URL("/orders", window.location.href).href)
    await vi.waitFor(() => expect(tab.messages).toEqual([{ path: window.location.pathname }]))
  })

  it("tells nothing after an event that pushed a refresh while the stream is open: the hub refreshes the other tabs", async () => {
    vi.stubGlobal(
      "EventSource",
      class {
        static OPEN = 1
        readyState = 1
        addEventListener() {}
        close() {}
      },
    )
    await mount(PAGE, `${ROOT} data-phem-stream-value="/.well-known/mercure?match=x"`)
    const tab = otherTab()
    respond(PAGE, {}, { sender: "t1", rendered: ["c1"] })
    click("#add")
    await nextDone()
    await wait()

    expect(tab.messages).toEqual([])

    // An event with no push: the hub tells nothing, so the channel tells the other tabs.
    respond(PAGE)
    click("#add")
    await nextDone()

    await vi.waitFor(() => expect(tab.messages).toEqual([{ path: window.location.pathname }]))
  })

  it("refreshes a page that Turbo shows again from its cache after a message of the time it was away", async () => {
    await mount(PAGE, ROOT)
    await leave()
    otherTab().postMessage({ path: window.location.pathname })
    await wait()

    respond('<p id="count">2</p>')
    await mount(PAGE, ROOT)

    await vi.waitFor(() => expect(document.getElementById("count").textContent).toBe("2"))
    expect(body(0).stale).toEqual(["c1"])
  })

  it("does not refresh a page that comes back with no message of the time it was away", async () => {
    await mount(PAGE, ROOT)
    await leave()
    await mount(PAGE, ROOT)
    await wait()

    expect(fetch).not.toHaveBeenCalled()
  })

  it("refreshes a page that the browser restores from its back/forward cache", async () => {
    await mount(PAGE, ROOT)
    respond('<p id="count">2</p>')
    window.dispatchEvent(new PageTransitionEvent("pageshow", { persisted: true }))

    await vi.waitFor(() => expect(document.getElementById("count").textContent).toBe("2"))

    // A normal load of the page is not a restore.
    window.dispatchEvent(new PageTransitionEvent("pageshow", { persisted: false }))
    await wait()
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("stops when the page leaves", async () => {
    await mount(PAGE, ROOT)
    await leave()
    otherTab().postMessage({ path: window.location.pathname })
    window.dispatchEvent(new PageTransitionEvent("pageshow", { persisted: true }))
    await wait()

    expect(fetch).not.toHaveBeenCalled()
  })
})
