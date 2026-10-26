import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

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

describe("data-phem-client", () => {
  it("keeps the attributes in the list, and morphs the others", async () => {
    await mount(`<p id="menu" data-phem-client="hidden aria-expanded" hidden aria-expanded="false" title="old">Menu</p><button data-phem-on-click="c1.star">Star</button>`)
    const menu = document.getElementById("menu")
    menu.hidden = false
    menu.setAttribute("aria-expanded", "true")
    respond(`<p id="menu" data-phem-client="hidden aria-expanded" hidden aria-expanded="false" title="new">Menu</p><button data-phem-on-click="c1.star">Unstar</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("menu")).toBe(menu)
    expect(menu.hidden).toBe(false)
    expect(menu.getAttribute("aria-expanded")).toBe("true")
    expect(menu.title).toBe("new")
    expect(document.querySelector("button").textContent).toBe("Unstar")
  })

  it("does not keep an attribute that is not in the list", async () => {
    await mount(`<p id="menu" data-phem-client="aria-expanded" hidden>Menu</p><button data-phem-on-click="c1.star">Star</button>`)
    document.getElementById("menu").hidden = false
    respond(`<p id="menu" data-phem-client="aria-expanded" hidden>Menu</p><button data-phem-on-click="c1.star">Star</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("menu").hidden).toBe(true)
  })
})

describe("the state", () => {
  // The same vector as in the tests of Phem\State\StateJson (tests/Runtime/StateJsonTest.php): the
  // server makes the same text from the same state and changes. A value has an escaped line break
  // after a comma and escaped quotes: they are not the end of an entry.
  const vector = JSON.parse(String.raw`{"before":"{\n\"_path\":{\"id\":\"3\"},\n\"_tree\":\"AAAAAAAA0\",\n\"phem0a\":{\"text\":\"a,\\nb \\\"c\\\" é/\"},\n\"phem0b\":{\"n\":1.0}\n}","changes":{"phem0a":null,"phem0b":"{\"n\":2.5}","phem0c":"[]","_tree":"\"BBBBBBBB0,CCCCCCCC1\""},"after":"{\n\"_path\":{\"id\":\"3\"},\n\"_tree\":\"BBBBBBBB0,CCCCCCCC1\",\n\"phem0b\":{\"n\":2.5},\n\"phem0c\":[]\n}"}`)

  const root = () => document.getElementById("phem-root")

  it("sets the changes of the response in the state, as the server does", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(root().getAttribute("data-phem-state-value")).toBe(vector.after)
    expect(root().getAttribute("data-phem-signature-value")).toBe("s2")
  })

  it("starts with no entries for a state with another form", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    root().setAttribute("data-phem-state-value", '{"phem0a":{"n":1}}')
    const all = { _path: '{"id":"3"}', _tree: '"BBBBBBBB0,CCCCCCCC1"', phem0b: '{"n":2.5}', phem0c: "[]" }
    respond(`<button data-phem-on-click="c1.save">Save</button>`, all)
    const done = nextDone()
    click("button")
    await done

    expect(root().getAttribute("data-phem-state-value")).toBe(vector.after)
  })

  it("sends the new state when the server does not have it", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`, `data-phem-state-inline-value="0"`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    let done = nextDone()
    click("button")
    await done

    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ state: "required" }), { status: 409 }))
    respond(`<button data-phem-on-click="c1.save">Save</button>`)
    done = nextDone()
    click("button")
    await done

    expect(fetch).toHaveBeenCalledTimes(3)
    expect(JSON.parse(fetch.mock.calls[1][1].body)).not.toHaveProperty("state")
    const body = JSON.parse(fetch.mock.calls[2][1].body)
    expect(body.state).toBe(vector.after)
    expect(body.signature).toBe("s2")
  })

  it("sends a state shorter than the inline size with the event", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`, `data-phem-state-inline-value="${vector.before.length + 1}"`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ state: vector.before, signature: "s1" })
  })

  it("sends only the signature of a state with the inline size or more", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`, `data-phem-state-inline-value="${vector.before.length}"`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body)).not.toHaveProperty("state")
  })

  it("always sends the state when the server has no cache", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body).state).toBe(vector.before)
  })
})

describe("data-phem-ignore", () => {
  it("keeps the element, its attributes and its content", async () => {
    await mount(`<div id="chart" data-phem-ignore>1</div><button data-phem-on-click="c1.add">Add</button>`)
    const chart = document.getElementById("chart")
    chart.innerHTML = "<canvas></canvas>"
    chart.className = "ready"
    respond(`<div id="chart" data-phem-ignore>2</div><button data-phem-on-click="c1.add">Add</button><p id="added">Added</p>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("chart")).toBe(chart)
    expect(chart.outerHTML).toBe('<div id="chart" data-phem-ignore="" class="ready"><canvas></canvas></div>')
    expect(document.getElementById("added")).not.toBeNull()
  })

  it("removes the element when the server render does not have it", async () => {
    await mount(`<div id="chart" data-phem-ignore>1</div><button data-phem-on-click="c1.remove">Remove</button>`)
    respond(`<button data-phem-on-click="c1.remove">Remove</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("chart")).toBeNull()
  })
})

describe("the morph of a list", () => {
  const rows = (keys) => `<ul>${keys.map((k) => `<li data-phem-key="${k}" data-phem-client="hidden">Row ${k}</li>`).join("")}</ul><button data-phem-on-click="c1.change">Change</button>`

  async function morph(from, to) {
    await mount(rows(from))
    const before = Object.fromEntries([...document.querySelectorAll("li")].map((li) => [li.dataset.phemKey, li]))
    before[from.at(-1)].hidden = true
    respond(rows(to))
    const done = nextDone()
    click("button")
    await done

    return before
  }

  it("keeps the element of an item when an item before it goes away", async () => {
    const before = await morph(["1", "2"], ["2"])
    const [row] = document.querySelectorAll("li")

    expect(row).toBe(before["2"])
    expect(row.hidden).toBe(true)
    expect(row.textContent).toBe("Row 2")
  })

  it("keeps the element of an item when an item comes before it", async () => {
    const before = await morph(["1"], ["0", "1"])
    const [added, row] = document.querySelectorAll("li")

    expect(row).toBe(before["1"])
    expect(row.hidden).toBe(true)
    expect(added.hidden).toBe(false)
    expect(added.textContent).toBe("Row 0")
  })

  it("removes the ids of the keys after the morph", async () => {
    await morph(["1", "2"], ["0", "2", "3"])

    expect(document.querySelectorAll("[id^='phem-key:']")).toHaveLength(0)
    expect([...document.querySelectorAll("li")].map((li) => li.hasAttribute("id"))).toEqual([false, false, false])
  })

  it("keeps an id of the server render", async () => {
    await mount(`<ul><li id="row-1" data-phem-key="1">Row 1</li></ul><button data-phem-on-click="c1.change">Change</button>`)
    respond(`<ul><li id="row-1" data-phem-key="1">Row 1</li><li id="row-2" data-phem-key="2">Row 2</li></ul><button data-phem-on-click="c1.change">Change</button>`)
    const done = nextDone()
    click("button")
    await done

    expect([...document.querySelectorAll("li")].map((li) => li.id)).toEqual(["row-1", "row-2"])
  })

  it("morphs two lists with the same keys by position", async () => {
    const lists = (a, b) => `<ul>${a.map((k) => `<li data-phem-key="${k}">A${k}</li>`).join("")}</ul><ul>${b.map((k) => `<li data-phem-key="${k}">B${k}</li>`).join("")}</ul><button data-phem-on-click="c1.change">Change</button>`
    await mount(lists(["1", "2"], ["1", "2"]))
    respond(lists(["2"], ["1", "2", "3"]))
    const done = nextDone()
    click("button")
    await done

    expect([...document.querySelectorAll("ul")].map((ul) => ul.textContent)).toEqual(["A2", "B1B2B3"])
  })
})

describe("phem:send and phem:done", () => {
  it("dispatches them on the element that sent the event, and phem:done after the morph", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    const events = []
    const button = document.getElementById("star")
    button.addEventListener("phem:send", (e) => events.push(["send", e.detail, fetch.mock.calls.length]))
    button.addEventListener("phem:done", (e) => events.push(["done", e.detail, e.target.textContent]))
    respond(`<button id="star" data-phem-on-click="c1.star">Unstar</button>`)
    const done = nextDone()
    click("#star")
    await done

    expect(events).toEqual([
      ["send", { handler: "c1.star" }, 0],
      ["done", { handler: "c1.star", ok: true, status: 200 }, "Unstar"],
    ])
  })

  it("dispatches phem:done on the root of the component when the morph removed the element", async () => {
    await mount(`<ul><li id="row-1"><button data-phem-on-click="c1.remove">Delete</button></li></ul>`)
    respond(`<ul></ul>`)
    const done = nextDone()
    click("#row-1 button")
    const event = await done

    expect(event.target.id).toBe("c1")
    expect(event.detail).toEqual({ handler: "c1.remove", ok: true, status: 200 })
  })

  it("lets a Stimulus action change the page before the event, and undo it on a failure", async () => {
    await mount(`<ul><li id="row-1" data-phem-client="hidden"><button data-phem-on-click="c1.remove">Delete</button></li></ul>`)
    const row = document.getElementById("row-1")
    const button = row.querySelector("button")
    // As `data-action="optimistic#hide phem:done->optimistic#show"`: the listener of the button
    // runs before the listener of the root element.
    button.addEventListener("click", () => (row.hidden = true))
    button.addEventListener("phem:done", () => (row.hidden = false))
    let hiddenOnSend = null
    button.addEventListener("phem:send", () => (hiddenOnSend = row.hidden))
    vi.spyOn(console, "error").mockImplementation(() => {})
    window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    const done = nextDone()
    button.click()
    const event = await done

    expect(hiddenOnSend).toBe(true)
    expect(event.detail).toEqual({ handler: "c1.remove", ok: false, status: 500 })
    expect(row.hidden).toBe(false)
  })

  it("dispatches phem:done with a 429 for a rate limit", async () => {
    await mount(`<button data-phem-on-click="c1.star">Star</button>`)
    vi.spyOn(console, "warn").mockImplementation(() => {})
    fetch.mockResolvedValueOnce(new Response("", { status: 429 }))
    const done = nextDone()
    click("button")

    expect((await done).detail).toEqual({ handler: "c1.star", ok: false, status: 429 })
  })

  it("dispatches phem:done with no status for a network error", async () => {
    await mount(`<button data-phem-on-click="c1.star">Star</button>`, 'data-phem-retries-value="0"')
    vi.spyOn(console, "error").mockImplementation(() => {})
    window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
    fetch.mockRejectedValueOnce(new TypeError("Failed to fetch"))
    const done = nextDone()
    click("button")

    expect((await done).detail).toEqual({ handler: "c1.star", ok: false, status: null })
  })
})

describe("<Suspense>", () => {
  // The JSON body of a call of fetch().
  const sent = (call) => JSON.parse(fetch.mock.calls[call][1].body)

  it("sends the load event when the page starts, and morphs the children in", async () => {
    respond(`<p id="report">Rows 3</p>`)
    const done = nextDone()
    await mount(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense>`)
    const event = await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent(0)).toMatchObject({ handler: "c1.load", event: { type: "load" } })
    expect(event.detail).toEqual({ handler: "c1.load", ok: true, status: 200 })
    expect(document.getElementById("report").textContent).toBe("Rows 3")
    expect(document.querySelector("phem-suspense")).toBeNull()
  })

  it("loads the boundaries of the page start in one event", async () => {
    respond(`<p id="sales">Sales</p><p id="orders">Orders</p><p id="users">Users</p>`)
    const done = nextDone()
    await mount(`
      <phem-suspense data-phem-load="c1.sales"><p>Loading…</p></phem-suspense>
      <phem-suspense data-phem-load="c1.orders"><p>Loading…</p></phem-suspense>
      <phem-suspense data-phem-load="c1.users"><p>Loading…</p></phem-suspense>`)
    const event = await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent(0)).toMatchObject({ handler: ["c1.sales", "c1.orders", "c1.users"], event: { type: "load" } })
    expect(sent(0).event).not.toHaveProperty("loads")
    expect(event.detail).toEqual({ handler: "c1.sales", ok: true, status: 200 })
    expect(document.querySelectorAll("phem-suspense")).toHaveLength(0)

    // The loading state ends after phem:done, for each boundary.
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(document.querySelectorAll("[aria-busy]")).toHaveLength(0)
  })

  it("loads each boundary alone after a failed load of all of them", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {})
    const quiet = (e) => e.preventDefault()
    window.addEventListener("phem:error", quiet)
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    respond(`<phem-suspense data-phem-load="c1.sales"><p>Loading…</p></phem-suspense><p id="orders">Orders</p>`)
    const loaded = new Promise((resolve) => window.addEventListener("phem:done", (e) => e.detail.handler === "c1.orders" && resolve(e)))
    await mount(`
      <phem-suspense data-phem-load="c1.sales"><p>Loading…</p></phem-suspense>
      <phem-suspense data-phem-load="c1.orders"><p>Loading…</p></phem-suspense>`)
    await loaded

    expect(fetch).toHaveBeenCalledTimes(3)
    expect(sent(0).handler).toEqual(["c1.sales", "c1.orders"])
    expect(sent(1).handler).toBe("c1.sales")
    expect(sent(2).handler).toBe("c1.orders")
    expect(document.getElementById("orders")).not.toBeNull()
    window.removeEventListener("phem:error", quiet)
  })

  it("loads a lazy boundary only when it comes into view", async () => {
    const observed = []
    let callback
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        constructor(cb) {
          callback = cb
        }
        observe(el) {
          observed.push(el)
        }
        unobserve() {}
        disconnect() {}
      },
    )
    await mount(`<phem-suspense data-phem-load="c1.load" data-phem-lazy><p>Loading…</p></phem-suspense>`)

    expect(fetch).not.toHaveBeenCalled()
    expect(observed).toEqual([document.querySelector("phem-suspense")])

    respond(`<p id="chart">Chart</p>`)
    const done = nextDone()
    callback([{ isIntersecting: true, target: observed[0] }])
    await done

    expect(sent(0).handler).toBe("c1.load")
    expect(document.getElementById("chart")).not.toBeNull()
  })

  it("does not load a boundary again after a failed load", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {})
    window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    const failed = nextDone()
    await mount(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense><button data-phem-on-click="c1.star">Star</button>`)
    expect((await failed).detail).toEqual({ handler: "c1.load", ok: false, status: 500 })

    respond(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense><button data-phem-on-click="c1.star">Unstar</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(sent(1).handler).toBe("c1.star")
    expect(document.querySelector("phem-suspense p").textContent).toBe("Loading…")
  })

  it("loads a boundary that a morph shows", async () => {
    await mount(`<button data-phem-on-click="c1.open">Open</button>`)
    respond(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense>`)
    respond(`<p id="report">Rows 3</p>`)
    const loaded = new Promise((resolve) => window.addEventListener("phem:done", (e) => e.detail.handler === "c1.load" && resolve(e)))
    click("button")
    await loaded

    expect(sent(1).handler).toBe("c1.load")
    expect(document.getElementById("report")).not.toBeNull()
  })
})

describe("pushes", () => {
  // The EventSource of the page: the test sends the messages of the hub.
  class FakeEventSource {
    static CLOSED = 2
    static last = null
    static count = 0
    constructor(url, options) {
      this.url = url
      this.options = options
      this.listeners = {}
      this.closed = false
      this.readyState = 0
      FakeEventSource.last = this
      FakeEventSource.count++
    }
    addEventListener(type, listener) {
      ;(this.listeners[type] ??= []).push(listener)
    }
    emit(type, event = {}) {
      for (const listener of this.listeners[type] ?? []) listener(event)
    }
    push(data) {
      this.emit("message", { data })
    }
    close() {
      this.closed = true
    }
  }

  const stream = (action, target, content = null) =>
    content === null ? `<turbo-stream action="${action}" target="${target}"></turbo-stream>` : `<turbo-stream action="${action}" target="${target}"><template>${content}</template></turbo-stream>`

  // The body of the next event that the controller posts.
  function nextBody() {
    return vi.waitFor(() => {
      expect(fetch).toHaveBeenCalled()
      return JSON.parse(fetch.mock.calls.at(-1)[1].body)
    })
  }

  const rendered = () => new Promise((resolve) => setTimeout(resolve, 30))

  beforeEach(() => {
    FakeEventSource.last = null
    FakeEventSource.count = 0
    vi.stubGlobal("EventSource", FakeEventSource)
  })

  async function subscribe(markup) {
    await mount(markup, 'data-phem-stream-value="/.well-known/mercure?match=phem%3AApp%5CGuestbook"')
    return FakeEventSource.last
  }

  it("opens one connection to the hub, and closes it when the page goes", async () => {
    const source = await subscribe("<ul id=\"entries\"></ul>")

    expect(source.url).toBe("/.well-known/mercure?match=phem%3AApp%5CGuestbook")
    expect(source.options).toEqual({ withCredentials: true })

    document.body.innerHTML = ""
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(source.closed).toBe(true)
  })

  it("opens no connection for a page with no subscription", async () => {
    await mount("<p>Hi</p>")

    expect(FakeEventSource.last).toBeNull()
  })

  it("renders a stream outside the root element as plain Turbo", async () => {
    const source = await subscribe("<button data-phem-on-click=\"c1.save\">Save</button>")
    document.body.insertAdjacentHTML("beforeend", '<p id="count">1</p>')
    source.push(stream("update", "count", "2"))
    await rendered()

    respond("<button data-phem-on-click=\"c1.save\">Save</button>")
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("count").textContent).toBe("2")
    expect(JSON.parse(fetch.mock.calls[0][1].body).stale).toBeUndefined()
  })

  it("marks the component of the target as stale, and the next event sends it", async () => {
    const source = await subscribe('<ul id="entries"></ul><button data-phem-on-click="c1.save">Save</button>')
    source.push(stream("append", "entries", '<li id="entry-1">Hi</li>'))
    await rendered()

    expect(document.getElementById("entry-1").textContent).toBe("Hi")

    respond('<ul id="entries"><li id="entry-1">Hi</li></ul><button data-phem-on-click="c1.save">Save</button>')
    let done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body).stale).toEqual(["c1"])

    // The server rendered it again: the next event does not send it.
    respond('<ul id="entries"><li id="entry-1">Hi</li></ul><button data-phem-on-click="c1.save">Save</button>')
    done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[1][1].body).stale).toBeUndefined()
  })

  it("removes an element with the same id in the target before an append", async () => {
    const source = await subscribe('<ul id="entries"><phem-c id="c2"><li id="entry-1">Old</li></phem-c></ul>')
    source.push(stream("prepend", "entries", '<li id="entry-1">New</li>'))
    await rendered()

    expect([...document.querySelectorAll("#entry-1")].map((li) => li.textContent)).toEqual(["New"])
  })

  it("keeps a bound field that the user changed in a pushed morph", async () => {
    const source = await subscribe('<div id="form"><input data-phem-bind="c1.name" value="Ada"><p>Old</p></div>')
    document.querySelector("input").value = "Grace"
    source.push(`<turbo-stream action="update" method="morph" target="form"><template><input data-phem-bind="c1.name" value="Ada"><p>New</p></template></turbo-stream>`)
    await rendered()

    expect(document.querySelector("p").textContent).toBe("New")
    expect(document.querySelector("input").value).toBe("Grace")
  })

  it("sends one refresh with no handler for the refreshes that come together", async () => {
    const source = await subscribe('<p id="stats">1 entry</p><p id="other">Other</p>')
    respond('<p id="stats">2 entries</p><p id="other">Other</p>')
    source.push(stream("phem-refresh", "stats") + stream("phem-refresh", "other"))
    source.push(stream("phem-refresh", "stats"))
    const body = await nextBody()

    expect(body.handler).toBeNull()
    expect(body.stale).toEqual(["c1"])
    await vi.waitFor(() => expect(document.getElementById("stats").textContent).toBe("2 entries"))
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("sends no refresh for a target that the page does not show", async () => {
    const source = await subscribe("<p>Hi</p>")
    source.push(stream("phem-refresh", "missing"))
    await new Promise((resolve) => setTimeout(resolve, 100))

    expect(fetch).not.toHaveBeenCalled()
  })

  it("refreshes the components of a targets selector", async () => {
    const source = await subscribe('<phem-c id="b1" data-phem-broadcast="all post1"><p>1</p></phem-c><phem-c id="b2" data-phem-broadcast="all post2"><p>2</p></phem-c><phem-c id="b3" data-phem-broadcast="all post1"><p>1</p></phem-c>')
    respond("<p>x</p>")
    source.push('<turbo-stream action="phem-refresh" targets="[data-phem-broadcast~=&quot;post1&quot;]"></turbo-stream>')
    const body = await nextBody()

    expect(body.stale).toEqual(["b1", "b3"])
  })

  it("skips the component of its own handler in a refresh after the handler", async () => {
    const markup = '<phem-c id="b1" data-phem-broadcast="all"><button data-phem-on-click="b1.like">Like</button></phem-c><phem-c id="b2" data-phem-broadcast="all"><p>2</p></phem-c>'
    const source = await subscribe(markup)
    respond(markup, {}, { sender: "t1" })
    const done = nextDone()
    click("button")
    await done

    respond(markup)
    source.push('<turbo-stream action="phem-refresh" targets="[data-phem-broadcast~=&quot;all&quot;]" data-phem-skip="t1 b1"></turbo-stream>')
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
    expect(JSON.parse(fetch.mock.calls[1][1].body).stale).toEqual(["b2"])

    // The token of an event of another page: no skip.
    respond(markup.replace("<p>2</p>", "<p>3</p>"))
    source.push('<turbo-stream action="phem-refresh" targets="[data-phem-broadcast~=&quot;all&quot;]" data-phem-skip="t2 b1"></turbo-stream>')
    // The morph of the last refresh, before the next test.
    await vi.waitFor(() => expect(document.getElementById("b2").textContent).toBe("3"))
    expect(JSON.parse(fetch.mock.calls[2][1].body).stale).toEqual(["b1", "b2"])
  })

  it("sets the keys of a component with #[Broadcast] after an event", async () => {
    await subscribe('<phem-c id="b1" data-phem-broadcast="all post1"><button data-phem-on-click="b1.next">Next</button></phem-c>')
    const streams = '<turbo-stream action="update" method="morph" target="b1"><template><button data-phem-on-click="b1.next">Next</button></template></turbo-stream>'
    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ streams, changes: {}, signature: "s2", keys: { b1: "all post2" } }), { status: 200, headers: { "Content-Type": "application/json" } }))
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("b1").getAttribute("data-phem-broadcast")).toBe("all post2")
  })

  it("refreshes the content of the root element for a refresh with no target", async () => {
    const source = await subscribe("<p>Hi</p>")
    respond("<p>Hi again</p>")
    source.push('<turbo-stream action="phem-refresh"></turbo-stream>')
    const body = await nextBody()

    expect(body.stale).toEqual(["c1"])
  })

  it("renders a push that comes while an event runs after the morph of the event", async () => {
    const source = await subscribe('<ul id="entries"><li id="entry-1">One</li></ul><button data-phem-on-click="c1.save">Save</button>')
    let answer
    fetch.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))
    const done = nextDone()
    click("button")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    // The event of this user added entry 2; the push of the same change comes first.
    source.push(stream("append", "entries", '<li id="entry-2">Two</li>'))
    await rendered()
    expect(document.getElementById("entry-2")).toBeNull()

    const markup = '<ul id="entries"><li id="entry-1">One</li><li id="entry-2">Two</li></ul><button data-phem-on-click="c1.save">Save</button>'
    answer(new Response(JSON.stringify({ streams: `<turbo-stream action="update" method="morph" target="c1"><template>${markup}</template></turbo-stream>`, changes: {}, signature: "s2" }), { status: 200 }))
    await done
    await rendered()

    expect([...document.querySelectorAll("li")].map((li) => li.textContent)).toEqual(["One", "Two"])
  })

  it("refreshes the content of the root element after a lost connection", async () => {
    const source = await subscribe("<p>Hi</p>")
    respond("<p>Hi again</p>")
    source.emit("open")
    source.emit("error")
    source.emit("open")
    const body = await nextBody()

    expect(body.stale).toEqual(["c1"])
    expect(body.handler).toBeNull()
  })

  it("sends the content of the root element when more than 100 components are stale", async () => {
    const rows = Array.from({ length: 101 }, (_, i) => `<phem-c id="row${i}"><p id="cell${i}">${i}</p></phem-c>`).join("")
    const source = await subscribe(`<p id="top">Rows</p>${rows}`)
    source.push(Array.from({ length: 101 }, (_, i) => stream("update", `cell${i}`, "x")).join(""))
    source.push(stream("phem-refresh", "top"))
    respond(rows)
    const body = await nextBody()

    expect(body.stale).toEqual(["c1"])
  })

  it("renews the token with a refresh and connects again after the hub refused the connection", async () => {
    const source = await subscribe("<p>Hi</p>")
    respond("<p>Hi</p>")
    vi.useFakeTimers({ toFake: ["setTimeout"] })
    try {
      source.readyState = FakeEventSource.CLOSED
      source.emit("error")
      vi.advanceTimersByTime(5000)
    } finally {
      vi.useRealTimers()
    }

    const body = await nextBody()

    expect(body.handler).toBeNull()
    expect(body.stale).toEqual(["c1"])
    await vi.waitFor(() => expect(FakeEventSource.count).toBe(2))
  })

  it("keeps a dirty bound field only in a pushed morph", async () => {
    await mount('<input data-phem-bind="c1.name" value="Ada">')
    const input = document.querySelector("input")
    input.value = "Grace"
    const event = new CustomEvent("turbo:before-morph-attribute", { cancelable: true, bubbles: true, detail: { attributeName: "value" } })
    input.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
  })
})

