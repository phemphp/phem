import { afterEach, beforeAll, beforeEach, describe, expect, it, onTestFinished, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemScrollAreaController from "../dist/phem_scroll_area_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-scroll-area", PhemScrollAreaController)
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

// A scroll area with a vertical and a horizontal scrollbar, a corner and a "Save" button that
// sends an event.
function area(attributes = "") {
  return `<div id="area" data-controller="phem-scroll-area" ${attributes}>
      <div id="viewport" data-phem-scroll-area-target="viewport">
        <div id="content" data-phem-scroll-area-target="content">Text</div>
      </div>
      <div id="vbar" data-phem-scroll-area-target="scrollbar" data-orientation="vertical">
        <div id="vthumb" data-phem-scroll-area-target="thumb"></div>
      </div>
      <div id="hbar" data-phem-scroll-area-target="scrollbar" data-orientation="horizontal">
        <div id="hthumb" data-phem-scroll-area-target="thumb"></div>
      </div>
      <div id="corner" data-phem-scroll-area-target="corner"></div>
      <button id="save" data-phem-on-click="c1.save">Save</button>
    </div>`
}

// jsdom has no layout: the viewport gets its sizes, and the scrollbars a track of 100px.
function layout({ width = 100, height = 100, scrollWidth = 100, scrollHeight = 400 } = {}) {
  const viewport = document.getElementById("viewport")
  const sizes = { clientWidth: width, clientHeight: height, scrollWidth, scrollHeight }
  for (const [name, value] of Object.entries(sizes)) Object.defineProperty(viewport, name, { configurable: true, get: () => value })

  for (const id of ["vbar", "hbar"]) {
    const bar = document.getElementById(id)
    Object.defineProperty(bar, "clientHeight", { configurable: true, get: () => 100 })
    Object.defineProperty(bar, "clientWidth", { configurable: true, get: () => 100 })
    bar.getBoundingClientRect = () => ({ top: 0, left: 0, right: 100, bottom: 100, width: 100, height: 100 })
  }
}

function controller() {
  return application.getControllerForElementAndIdentifier(document.getElementById("area"), "phem-scroll-area")
}

async function mountArea(attributes = "", sizes = {}) {
  await mount(area(attributes))
  layout(sizes)
  controller().update()
}

// The render of a scroll waits for the next frame.
const frame = () => new Promise((resolve) => requestAnimationFrame(resolve))

async function scrollTo(top, left = 0) {
  const viewport = document.getElementById("viewport")
  viewport.scrollTop = top
  viewport.scrollLeft = left
  viewport.dispatchEvent(new Event("scroll"))
  await frame()
}

// A browser with no `scrollend` event: jsdom has one.
function withoutScrollEnd() {
  let owner = window
  while (owner && !Object.hasOwn(owner, "onscrollend")) owner = Object.getPrototypeOf(owner)
  const descriptor = Object.getOwnPropertyDescriptor(owner, "onscrollend")
  delete owner.onscrollend
  onTestFinished(() => Object.defineProperty(owner, "onscrollend", descriptor))
}

// The fake timers keep the frames of jsdom.
const fakeTimers = () => vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })

function pointer(type, el, init = {}) {
  el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, ...init }))
}

const el = (id) => document.getElementById(id)

describe("phem-scroll-area", () => {
  it("shows the overflow of the content", async () => {
    await mountArea()

    for (const id of ["area", "viewport", "content", "vbar"]) {
      expect(el(id).hasAttribute("data-has-overflow-y")).toBe(true)
      expect(el(id).hasAttribute("data-overflow-y-end")).toBe(true)
      expect(el(id).hasAttribute("data-overflow-y-start")).toBe(false)
      expect(el(id).hasAttribute("data-has-overflow-x")).toBe(false)
    }

    expect(el("viewport").style.getPropertyValue("--scroll-area-overflow-y-end")).toBe("300px")
    expect(el("viewport").style.getPropertyValue("--scroll-area-overflow-y-start")).toBe("0px")
    expect(el("viewport").style.overflow).toBe("scroll")
    expect(el("area").style.position).toBe("relative")
  })

  it("hides a scrollbar and the corner with no overflow on their axis", async () => {
    await mountArea()

    expect(el("vbar").hidden).toBe(false)
    expect(el("hbar").hidden).toBe(true)
    expect(el("corner").hidden).toBe(true)
    expect(el("area").style.getPropertyValue("--scroll-area-corner-width")).toBe("0px")
  })

  it("keeps a scrollbar with data-keep-mounted", async () => {
    await mount(area())
    el("hbar").setAttribute("data-keep-mounted", "")
    layout()
    controller().update()

    expect(el("hbar").hidden).toBe(false)
    expect(el("hbar").hasAttribute("data-has-overflow-x")).toBe(false)
  })

  it("shows the corner when the two axes overflow", async () => {
    await mountArea("", { scrollWidth: 300 })

    expect(el("hbar").hidden).toBe(false)
    expect(el("corner").hidden).toBe(false)
    expect(el("area").hasAttribute("data-has-overflow-x")).toBe(true)
    expect(el("area").hasAttribute("data-overflow-x-end")).toBe(true)
  })

  it("sizes and moves the thumb", async () => {
    await mountArea()

    expect(el("vbar").style.getPropertyValue("--scroll-area-thumb-height")).toBe("25px")
    expect(el("vthumb").style.height).toBe("var(--scroll-area-thumb-height)")
    expect(el("vthumb").getAttribute("data-orientation")).toBe("vertical")
    expect(el("vthumb").style.transform).toBe("translate3d(0px, 0px, 0px)")

    await scrollTo(300)

    expect(el("vthumb").style.transform).toBe("translate3d(0px, 75px, 0px)")
  })

  it("marks the scroll, and removes the mark 500 ms after the scroll with no scrollend event", async () => {
    withoutScrollEnd()
    await mountArea()
    fakeTimers()
    await scrollTo(100)

    expect(el("area").hasAttribute("data-scrolling")).toBe(true)
    expect(el("vbar").hasAttribute("data-scrolling")).toBe(true)
    expect(el("vthumb").hasAttribute("data-scrolling")).toBe(true)
    expect(el("hbar").hasAttribute("data-scrolling")).toBe(false)
    expect(el("area").hasAttribute("data-overflow-y-start")).toBe(true)
    expect(el("viewport").style.getPropertyValue("--scroll-area-overflow-y-start")).toBe("100px")

    vi.advanceTimersByTime(500)
    await frame()

    expect(el("area").hasAttribute("data-scrolling")).toBe(false)
    expect(el("vthumb").hasAttribute("data-scrolling")).toBe(false)
  })

  it("renders all the scroll events of a frame once, in the frame", async () => {
    await mountArea()
    const update = vi.spyOn(controller(), "update")
    const viewport = el("viewport")
    for (const top of [10, 20, 30]) {
      viewport.scrollTop = top
      viewport.dispatchEvent(new Event("scroll"))
    }

    expect(update).not.toHaveBeenCalled()
    expect(el("area").hasAttribute("data-scrolling")).toBe(false)

    await frame()

    expect(update).toHaveBeenCalledTimes(1)
    expect(el("area").hasAttribute("data-scrolling")).toBe(true)
    expect(el("viewport").style.getPropertyValue("--scroll-area-overflow-y-start")).toBe("30px")
  })

  it("applies the overflowEdgeThreshold value", async () => {
    await mountArea('data-phem-scroll-area-overflow-edge-threshold-value="20"')
    await scrollTo(10)

    expect(el("area").hasAttribute("data-overflow-y-start")).toBe(false)

    await scrollTo(30)

    expect(el("area").hasAttribute("data-overflow-y-start")).toBe(true)
  })

  it("reads the thresholds of the edges from a JSON object", async () => {
    await mountArea(`data-phem-scroll-area-overflow-edge-threshold-value='{"yEnd": 50}'`)
    await scrollTo(260)

    expect(el("area").hasAttribute("data-overflow-y-start")).toBe(true)
    expect(el("area").hasAttribute("data-overflow-y-end")).toBe(false)
  })

  it("marks the hover on the scrollbars", async () => {
    await mountArea()
    el("area").dispatchEvent(new MouseEvent("pointerenter"))

    expect(el("vbar").hasAttribute("data-hovering")).toBe(true)

    el("area").dispatchEvent(new MouseEvent("pointerleave"))

    expect(el("vbar").hasAttribute("data-hovering")).toBe(false)
  })

  it("scrolls the viewport on a drag of the thumb", async () => {
    await mountArea()
    pointer("pointerdown", el("vthumb"), { clientY: 10 })
    pointer("pointermove", el("vbar"), { clientY: 35 })

    // The track has 75px for the thumb, for 300px of scroll.
    expect(el("viewport").scrollTop).toBe(100)

    pointer("pointerup", el("vbar"), { clientY: 35 })
    pointer("pointermove", el("vbar"), { clientY: 60 })

    expect(el("viewport").scrollTop).toBe(100)
  })

  it("scrolls to the point of a press on the track", async () => {
    await mountArea()
    pointer("pointerdown", el("vbar"), { clientY: 50 })

    // The center of the thumb (25px) goes to the pointer: the thumb starts at 37.5px of 75px.
    expect(el("viewport").scrollTop).toBe(150)
  })

  it("keeps the state and the scroll position after the morph", async () => {
    await mountArea()
    await scrollTo(100)

    respond(area())
    const done = nextDone()
    click("#save")
    await done
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(el("viewport").scrollTop).toBe(100)
    expect(el("area").hasAttribute("data-overflow-y-start")).toBe(true)
    expect(el("vthumb").style.transform).toBe("translate3d(0px, 25px, 0px)")
    expect(el("hbar").hidden).toBe(true)
    expect(el("viewport").style.overflow).toBe("scroll")
  })
})

describe("phem-scroll-area: the scrollend event", () => {
  it("removes the scroll mark at the scrollend event, with no timer", async () => {
    await mountArea()
    fakeTimers()
    await scrollTo(100)

    expect(el("area").hasAttribute("data-scrolling")).toBe(true)
    expect(vi.getTimerCount()).toBe(0)

    el("viewport").dispatchEvent(new Event("scrollend"))
    await frame()

    expect(el("area").hasAttribute("data-scrolling")).toBe(false)
    expect(el("vthumb").hasAttribute("data-scrolling")).toBe(false)
  })

  it("keeps the scroll mark at the scrollend event of a drag of the thumb", async () => {
    await mountArea()
    pointer("pointerdown", el("vthumb"), { clientY: 10 })
    pointer("pointermove", el("vbar"), { clientY: 35 })
    el("viewport").dispatchEvent(new Event("scroll"))
    el("viewport").dispatchEvent(new Event("scrollend"))
    await frame()

    expect(el("area").hasAttribute("data-scrolling")).toBe(true)

    pointer("pointerup", el("vbar"), { clientY: 35 })

    expect(el("area").hasAttribute("data-scrolling")).toBe(false)
  })
})

describe("phem-scroll-area: the Tab order of the viewport", () => {
  it("puts a viewport that scrolls in the Tab order, and takes it out with no overflow", async () => {
    await mountArea()
    expect(el("viewport").getAttribute("tabindex")).toBe("0")

    layout({ scrollHeight: 100 })
    controller().update()
    expect(el("viewport").getAttribute("tabindex")).toBe("-1")
  })
})

describe("phem-scroll-area: the end of a drag", () => {
  it("removes the scroll mark at once when the drag of the thumb ends", async () => {
    await mountArea()
    fakeTimers()
    pointer("pointerdown", el("vthumb"), { clientY: 10 })
    pointer("pointermove", el("vbar"), { clientY: 35 })
    el("viewport").dispatchEvent(new Event("scroll"))
    await frame()

    expect(el("area").hasAttribute("data-scrolling")).toBe(true)
    expect(el("vthumb").hasAttribute("data-scrolling")).toBe(true)

    pointer("pointerup", el("vbar"), { clientY: 35 })

    expect(el("area").hasAttribute("data-scrolling")).toBe(false)
    expect(el("vbar").hasAttribute("data-scrolling")).toBe(false)
    expect(el("vthumb").hasAttribute("data-scrolling")).toBe(false)
    expect(vi.getTimerCount()).toBe(0)
  })

  it("removes the scroll mark of the horizontal axis when the pointer capture goes", async () => {
    await mountArea("", { scrollWidth: 400 })
    fakeTimers()
    pointer("pointerdown", el("hthumb"), { clientX: 10 })
    pointer("pointermove", el("hbar"), { clientX: 35 })
    el("viewport").dispatchEvent(new Event("scroll"))
    await frame()

    expect(el("hbar").hasAttribute("data-scrolling")).toBe(true)
    expect(el("hthumb").hasAttribute("data-scrolling")).toBe(true)

    el("hbar").dispatchEvent(new Event("lostpointercapture", { bubbles: true }))

    expect(el("area").hasAttribute("data-scrolling")).toBe(false)
    expect(el("hbar").hasAttribute("data-scrolling")).toBe(false)
    expect(el("hthumb").hasAttribute("data-scrolling")).toBe(false)
    expect(vi.getTimerCount()).toBe(0)
  })
})

describe("phem-scroll-area: the fractions of a pixel", () => {
  it("keeps the fractions in the size and the offset of the thumb", async () => {
    await mountArea("", { scrollHeight: 300 })
    await scrollTo(100)

    // The thumb is 100 / 3 px, and it moves half of the 200 / 3 px that it has.
    expect(parseFloat(el("vbar").style.getPropertyValue("--scroll-area-thumb-height"))).toBeCloseTo(100 / 3, 5)
    const offset = el("vthumb").style.transform.match(/translate3d\(0px, ([\d.]+)px, 0px\)/)
    expect(parseFloat(offset?.[1])).toBeCloseTo(100 / 3, 5)
  })
})
