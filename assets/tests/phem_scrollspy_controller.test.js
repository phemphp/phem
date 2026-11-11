import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemScrollspyController from "../dist/phem_scrollspy_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-scrollspy", PhemScrollspyController)
})

// jsdom has no layout: each section has a top in the page, and the scroll moves all of them.
let tops
let scrolled = 0
const rect = Element.prototype.getBoundingClientRect

function scrollTo(y) {
  scrolled = y
  document.dispatchEvent(new Event("scroll"))

  return frame()
}

function frame() {
  return new Promise((resolve) => requestAnimationFrame(() => resolve()))
}

// The page scrolls, and its end is at `end`.
function pageEnd(end) {
  const root = document.documentElement
  Object.defineProperty(root, "scrollHeight", { configurable: true, value: end + 800 })
  Object.defineProperty(root, "clientHeight", { configurable: true, value: 800 })
  Object.defineProperty(root, "scrollTop", { configurable: true, get: () => scrolled })
}

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  scrolled = 0
  tops = { install: 0, configure: 500, deploy: 1000, "50%-off": 1500 }
  Element.prototype.getBoundingClientRect = function () {
    const top = this.id in tops ? tops[this.id] - scrolled : 0

    return { top, left: 0, bottom: top + 10, right: 100, width: 100, height: 10 }
  }
})

afterEach(() => {
  Element.prototype.getBoundingClientRect = rect
  for (const name of ["scrollHeight", "clientHeight", "scrollTop"]) delete document.documentElement[name]
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A table of contents, its sections with a sticky header of 80px, and a button that sends an
// event.
function page(links = "", sections = "") {
  return `<nav id="toc" data-turbo="false" data-controller="phem-scrollspy">
      <a href="#install" id="to-install" data-phem-scrollspy-target="link">Install</a>
      <a href="#configure" id="to-configure" data-phem-scrollspy-target="link">Configure</a>
      <a href="#deploy" id="to-deploy" data-phem-scrollspy-target="link">Deploy</a>
      <a href="#missing" id="to-missing" data-phem-scrollspy-target="link">Missing</a>
      ${links}
    </nav>
    <h2 id="install" style="scroll-margin-top: 80px">Install</h2>
    <h2 id="configure" style="scroll-margin-top: 80px">Configure</h2>
    <h2 id="deploy" style="scroll-margin-top: 80px">Deploy</h2>
    ${sections}
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

function current() {
  return [...document.querySelectorAll("[aria-current]")].map((el) => el.id)
}

describe("phem-scrollspy", () => {
  it("marks no link above the first section", async () => {
    tops.install = 200
    await mount(page())

    expect(current()).toEqual([])
    expect(document.querySelector("[data-active]")).toBeNull()
  })

  it("marks the last section at its scroll margin or above it", async () => {
    await mount(page())
    expect(current()).toEqual(["to-install"])

    // A click on "Configure" scrolls its top to 80px.
    await scrollTo(420)
    expect(current()).toEqual(["to-configure"])
    expect(document.getElementById("to-configure").getAttribute("aria-current")).toBe("true")
    expect(document.getElementById("to-configure").hasAttribute("data-active")).toBe(true)
    expect(document.getElementById("to-install").hasAttribute("data-active")).toBe(false)

    await scrollTo(410)
    expect(current()).toEqual(["to-install"])
  })

  it("marks the last section at the end of the scroll", async () => {
    await mount(page())
    pageEnd(600)

    await scrollTo(600)
    expect(current()).toEqual(["to-deploy"])
  })

  it("keeps the clicked section in view at the end of the scroll, until the user scrolls", async () => {
    await mount(page())
    pageEnd(450)

    await scrollTo(450)
    expect(current()).toEqual(["to-deploy"])

    document.getElementById("to-configure").dispatchEvent(new MouseEvent("click", { bubbles: true }))
    await scrollTo(450)
    expect(current()).toEqual(["to-configure"])

    window.dispatchEvent(new Event("wheel"))
    await scrollTo(450)
    expect(current()).toEqual(["to-deploy"])
  })

  it("marks all the links of the current section", async () => {
    await mount(page(`<a href="#configure" id="also-configure" data-phem-scrollspy-target="link">Configure</a>`))

    await scrollTo(420)
    expect(current()).toEqual(["to-configure", "also-configure"])
  })

  it("takes a hash with a percent sign that is not an escape", async () => {
    await mount(page(`<a href="#50%-off" id="to-offer" data-phem-scrollspy-target="link">Offer</a>`, `<h2 id="50%-off">Offer</h2>`))

    await scrollTo(1500)
    expect(current()).toEqual(["to-offer"])
  })

  it("ignores the scroll of an element around no section", async () => {
    await mount(page())
    scrolled = 420
    const list = document.createElement("ul")
    document.body.append(list)
    list.dispatchEvent(new Event("scroll"))
    await frame()

    expect(current()).toEqual(["to-install"])
  })

  it("dispatches a change event when the current section changes", async () => {
    await mount(page())
    const changes = []
    document.getElementById("toc").addEventListener("phem-scrollspy:change", (event) => changes.push(event.detail.section?.id ?? null))

    await scrollTo(430)
    await scrollTo(440)
    await scrollTo(920)

    expect(changes).toEqual(["configure", "deploy"])
  })

  it("keeps the current link after the morph of an event", async () => {
    await mount(page())
    await scrollTo(420)

    respond(page())
    const done = nextDone()
    click("#save")
    await done
    await settle()

    expect(current()).toEqual(["to-configure"])
  })

  it("stops after the disconnect", async () => {
    await mount(page())
    const toc = document.getElementById("toc")
    toc.removeAttribute("data-controller")
    await settle()

    await scrollTo(420)
    expect(document.getElementById("to-configure").hasAttribute("data-active")).toBe(false)
  })
})
