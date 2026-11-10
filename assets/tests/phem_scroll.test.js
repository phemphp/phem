import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

vi.mock("@hotwired/turbo", async (original) => ({ ...(await original()), visit: vi.fn() }))

// jsdom has no layout. The fake layout: a `.list` container is 100px high, and its top is 80px
// above the view (the page scrolled). Each element with no child element is a row of 50px, one
// under the other, and an element with children spans its rows. An element with `hidden` has no
// box.
const TOP = -80
const HEIGHT = 100
const ROW = 50

const scrollTops = new WeakMap()
let writes = 0

const isList = (el) => el.classList?.contains("list")
const rows = (container) => [...container.querySelectorAll("*")].filter((el) => !el.children.length && !el.closest("[hidden]"))

function rect(el) {
  const container = el.closest(".list")
  if (!container) return { top: 0, bottom: 0 }
  if (el === container) return { top: TOP, bottom: TOP + HEIGHT }
  if (el.closest("[hidden]")) return { top: 0, bottom: 0 }

  const all = rows(container)
  const mine = el.children.length ? all.filter((row) => el.contains(row)) : [el]
  if (!mine.length) return { top: 0, bottom: 0 }

  const top = TOP + all.indexOf(mine[0]) * ROW - container.scrollTop

  return { top, bottom: top + mine.length * ROW }
}

// The first row in view of the container, and its distance from the top of the container.
function firstInView(container = document.querySelector(".list")) {
  const row = rows(container).find((el) => rect(el).bottom > TOP)

  return [row.textContent, rect(row).top - TOP]
}

const keyed = (keys, attributes = {}) => keys.map((k) => `<li data-phem-key="${k}" ${attributes[k] ?? ""}>Item ${k}</li>`).join("")

const list = (keys, attributes = {}, mode = "") =>
  `<div class="list" data-phem-keep-scroll${mode ? `="${mode}"` : ""}><ul>${keyed(keys, attributes)}</ul></div><button data-phem-on-click="c1.add">Add</button>`

const range = (from, to) => Array.from({ length: to - from + 1 }, (_, i) => String(from + i))

async function event(markup) {
  respond(markup)
  const done = nextDone()
  click("button")
  await done
}

// Renders a push (a Turbo Stream with no event), and resolves after the render.
async function push(streams) {
  Turbo.renderStreamMessage(streams)
  await new Promise((resolve) => requestAnimationFrame(() => setTimeout(resolve, 0)))
}

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  writes = 0
  vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function () {
    return rect(this)
  })
  vi.spyOn(Element.prototype, "scrollTop", "get").mockImplementation(function () {
    return scrollTops.get(this) ?? 0
  })
  // As a browser, from 0 to the end.
  vi.spyOn(Element.prototype, "scrollTop", "set").mockImplementation(function (value) {
    if (!isList(this)) return

    writes++
    scrollTops.set(this, Math.max(0, Math.min(value, this.scrollHeight - this.clientHeight)))
  })
  vi.spyOn(Element.prototype, "clientHeight", "get").mockImplementation(function () {
    return isList(this) ? HEIGHT : 0
  })
  vi.spyOn(Element.prototype, "scrollHeight", "get").mockImplementation(function () {
    return isList(this) ? Math.max(HEIGHT, rows(this).length * ROW) : 0
  })
  Element.prototype.scrollIntoView = vi.fn()
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  delete Element.prototype.scrollIntoView
  document.body.innerHTML = ""
})

function scrollTo(top, container = document.querySelector(".list")) {
  container.scrollTop = top
  writes = 0
}

describe("phem:keep-scroll", () => {
  it("keeps the first item in view at its place when items come before it", async () => {
    await mount(list(range(1, 10)))
    scrollTo(170)
    expect(firstInView()).toEqual(["Item 4", -20])

    await event(list(["a", "b", ...range(1, 10)]))

    expect(firstInView()).toEqual(["Item 4", -20])
  })

  it("keeps the first item in view at its place when an item before it goes away", async () => {
    await mount(list(range(1, 10)))
    scrollTo(150)

    await event(list(range(2, 10)))

    expect(firstInView()).toEqual(["Item 4", 0])
  })

  it("keeps the item of a push in a list with an id, and items with an id and no key", async () => {
    // As a feed: the list has an id, and each item has its own id.
    const item = (k) => `<li id="m-${k}">Message ${k}</li>`
    await mount(`<div class="list" data-phem-keep-scroll><ul id="messages">${range(1, 10).map(item).join("")}</ul></div>`)
    scrollTo(170)

    await push(`<turbo-stream action="prepend" target="messages"><template>${item("a")}${item("b")}</template></turbo-stream>`)

    expect(firstInView()).toEqual(["Message 4", -20])
  })

  it("keeps the element of an item with no key when a push adds items before it", async () => {
    await mount(`<div class="list" data-phem-keep-scroll><ul id="messages">${range(1, 10).map((k) => `<li>Message ${k}</li>`).join("")}</ul></div>`)
    scrollTo(170)

    await push(`<turbo-stream action="prepend" target="messages"><template><li>Message a</li></template></turbo-stream>`)

    expect(firstInView()).toEqual(["Message 4", -20])
  })

  it("finds an item by its key in its own list, also when a push gives new elements", async () => {
    // The replies of each comment have the keys 1 and 2.
    const thread = (comments) =>
      `<div id="thread">${comments.map((c) => `<section data-phem-key="${c}"><p data-phem-key="1">${c}1</p><p data-phem-key="2">${c}2</p></section>`).join("")}</div>`
    await mount(`<div class="list" data-phem-keep-scroll>${thread(["a", "b", "c", "d"])}</div>`)
    scrollTo(210)
    expect(firstInView()).toEqual(["c1", -10])

    await push(`<turbo-stream action="replace" target="thread"><template>${thread(["z", "a", "b", "c", "d"])}</template></turbo-stream>`)

    expect(firstInView()).toEqual(["c1", -10])
  })

  it("does not take an element with no box as the item in view", async () => {
    await mount(`<div class="list" data-phem-keep-scroll><ul id="items"><li id="menu" hidden><span>Menu</span></li>${keyed(range(1, 10))}</ul></div>`)
    scrollTo(170)

    await push(`<turbo-stream action="after" target="menu"><template><li data-phem-key="a">Item a</li></template></turbo-stream>`)

    expect(firstInView()).toEqual(["Item 4", -20])
  })

  it("keeps the position when the render replaces the container", async () => {
    const container = (keys) => `<div id="box" class="list" data-phem-keep-scroll><ul>${keyed(keys)}</ul></div>`
    await mount(container(range(1, 10)))
    scrollTo(170)

    await push(`<turbo-stream action="replace" target="box"><template>${container(["a", ...range(1, 10)])}</template></turbo-stream>`)

    expect(document.querySelector(".list").scrollTop).toBe(220)
    expect(firstInView()).toEqual(["Item 4", -20])
  })

  it("does not move a container when the item in view stays at its place", async () => {
    await mount(list(range(1, 10)))
    scrollTo(170)

    await event(list([...range(1, 12)]))

    expect(writes).toBe(0)
    expect(firstInView()).toEqual(["Item 4", -20])
  })

  it("does not measure a container that the stream does not change", async () => {
    await mount(`${list(range(1, 10))}<ul id="other"></ul>`)
    scrollTo(170)
    const position = vi.mocked(Element.prototype.getBoundingClientRect).mock.calls.length

    await push(`<turbo-stream action="append" target="other"><template><li>Other</li></template></turbo-stream>`)

    expect(vi.mocked(Element.prototype.getBoundingClientRect).mock.calls.length).toBe(position)
  })

  it("keeps the item in view of a container that does not overflow yet", async () => {
    await mount(list(["1"]))

    await event(list(["a", "b", "c", ...range(1, 4)]))

    // A list with the newest item first does not jump to its end.
    expect(firstInView()).toEqual(["Item 1", 0])
  })

  it("stays at the end with end, also from a container that does not overflow yet", async () => {
    await mount(list(["1"], {}, "end"))
    const container = document.querySelector(".list")

    await event(list(range(1, 6), {}, "end"))
    expect(container.scrollTop).toBe(200)

    await event(list(range(1, 8), {}, "end"))
    expect(container.scrollTop).toBe(300)
  })

  it("keeps the item in view with end when the container is not at its end", async () => {
    await mount(list(range(1, 10), {}, "end"))
    scrollTo(170)

    await event(list(["a", ...range(1, 12)], {}, "end"))

    expect(firstInView()).toEqual(["Item 4", -20])
  })

  it("does not change a container with no attribute", async () => {
    await mount(list(range(1, 10)).replace(" data-phem-keep-scroll", ""))
    scrollTo(150)

    await event(list(["a", "b", ...range(1, 10)]).replace(" data-phem-keep-scroll", ""))

    expect(writes).toBe(0)
  })
})

describe("phem:scroll-into-view", () => {
  const scroll = (keys, marked) => list(keys, { [marked]: "data-phem-scroll-into-view" })
  const scrolled = () => Element.prototype.scrollIntoView.mock.contexts

  it("scrolls to a new element after the morph of an event", async () => {
    await mount(list(range(1, 3)))

    await event(scroll(range(1, 4), "4"))

    expect(scrolled()).toEqual([document.querySelector('[data-phem-key="4"]')])
    expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({ block: "nearest" })
  })

  it("scrolls to an element that gets the attribute, once", async () => {
    await mount(list(range(1, 3)))

    await event(scroll(range(1, 3), "2"))
    await event(scroll(range(1, 3), "2"))

    expect(scrolled()).toEqual([document.querySelector('[data-phem-key="2"]')])
  })

  it("scrolls to the first new element in the page", async () => {
    await mount(list(range(1, 3)))

    await event(list(range(1, 5), { 5: "data-phem-scroll-into-view", 4: "data-phem-scroll-into-view" }))

    expect(scrolled()).toEqual([document.querySelector('[data-phem-key="4"]')])
  })

  it("does not scroll on page load or in a push", async () => {
    await mount(scroll(range(1, 3), "3"))
    document.querySelector("ul").id = "items"

    await push(`<turbo-stream action="replace" target="items"><template><ul id="items">${keyed(["9"], { 9: "data-phem-scroll-into-view" })}</ul></template></turbo-stream>`)

    expect(document.querySelector('[data-phem-key="9"]')).not.toBeNull()
    expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled()
  })

  it("does not scroll for an event of the browser: a poll", async () => {
    await mount(`<p data-phem-on-poll="c1.tick" data-phem-poll="20">Polls</p>`)
    respond(`<p>Polls</p><p data-phem-scroll-into-view>New</p>`)

    await nextDone()

    expect(document.querySelector("[data-phem-scroll-into-view]")).not.toBeNull()
    expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled()
  })
})
