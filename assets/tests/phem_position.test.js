import { afterEach, beforeEach, describe, expect, it } from "vitest"
import { place, position, unposition } from "../dist/phem_position.js"

// jsdom has no layout: the boxes come from the tests. The viewport is 1000 x 800.
function box(el, { left, top, width, height }) {
  el.getBoundingClientRect = () => ({ left, top, width, height, right: left + width, bottom: top + height, x: left, y: top })
  Object.defineProperty(el, "offsetWidth", { value: width, configurable: true })
  Object.defineProperty(el, "offsetHeight", { value: height, configurable: true })
}

let anchor
let popup
let arrow

beforeEach(() => {
  Object.defineProperty(document.documentElement, "clientWidth", { value: 1000, configurable: true })
  Object.defineProperty(document.documentElement, "clientHeight", { value: 800, configurable: true })
  document.body.innerHTML = `<button id="anchor">Open</button><div id="popup"><div id="arrow"></div></div>`
  anchor = document.getElementById("anchor")
  popup = document.getElementById("popup")
  arrow = document.getElementById("arrow")
  box(anchor, { left: 400, top: 300, width: 100, height: 40 })
  box(popup, { left: 0, top: 0, width: 200, height: 100 })
  box(arrow, { left: 0, top: 0, width: 10, height: 10 })
})

afterEach(() => {
  document.body.innerHTML = ""
  document.documentElement.removeAttribute("dir")
})

function at() {
  return { left: popup.style.left, top: popup.style.top }
}

describe("place()", () => {
  it("places the popup below the anchor, at the center", () => {
    place(anchor, popup, { side: "bottom", align: "center" })

    expect(at()).toEqual({ left: "350px", top: "340px" })
    expect(popup.style.position).toBe("fixed")
    expect(popup.getAttribute("data-side")).toBe("bottom")
    expect(popup.getAttribute("data-align")).toBe("center")
  })

  it("aligns on the start and on the end of the anchor, with the offsets", () => {
    place(anchor, popup, { side: "bottom", align: "start", sideOffset: 8, alignOffset: 4 })
    expect(at()).toEqual({ left: "404px", top: "348px" })

    place(anchor, popup, { side: "bottom", align: "end", alignOffset: 4 })
    expect(at()).toEqual({ left: "296px", top: "340px" })
  })

  it("places the popup on each side", () => {
    place(anchor, popup, { side: "top" })
    expect(at()).toEqual({ left: "350px", top: "200px" })

    place(anchor, popup, { side: "left" })
    expect(at()).toEqual({ left: "200px", top: "270px" })

    place(anchor, popup, { side: "right" })
    expect(at()).toEqual({ left: "500px", top: "270px" })
  })

  it("uses the direction of the page for the logical sides and the align", () => {
    place(anchor, popup, { side: "inline-end" })
    expect(at().left).toBe("500px")
    expect(popup.getAttribute("data-side")).toBe("inline-end")

    document.documentElement.setAttribute("dir", "rtl")
    place(anchor, popup, { side: "inline-end" })
    expect(at().left).toBe("200px")

    place(anchor, popup, { side: "bottom", align: "start" })
    expect(at().left).toBe("300px")
  })

  it("flips to the other side when there is no space", () => {
    box(anchor, { left: 400, top: 720, width: 100, height: 40 })
    place(anchor, popup, { side: "bottom" })

    expect(at().top).toBe("620px")
    expect(popup.getAttribute("data-side")).toBe("top")
  })

  it("stays on its side when the other side has less space", () => {
    box(anchor, { left: 400, top: 20, width: 100, height: 720 })
    place(anchor, popup, { side: "bottom" })

    expect(popup.getAttribute("data-side")).toBe("bottom")
  })

  it("shifts the popup into the viewport, with the collision padding", () => {
    box(anchor, { left: 950, top: 300, width: 40, height: 40 })
    place(anchor, popup, { side: "bottom", collisionPadding: 10 })

    expect(at().left).toBe("790px")
  })

  it("sets the CSS variables", () => {
    place(anchor, popup, { side: "bottom", collisionPadding: 5 })

    expect(popup.style.getPropertyValue("--anchor-width")).toBe("100px")
    expect(popup.style.getPropertyValue("--anchor-height")).toBe("40px")
    expect(popup.style.getPropertyValue("--available-height")).toBe("455px")
    expect(popup.style.getPropertyValue("--available-width")).toBe("990px")
    expect(popup.style.getPropertyValue("--transform-origin")).toBe("100px 0px")
  })

  it("points the arrow at the center of the anchor", () => {
    place(anchor, popup, { side: "bottom", align: "start", arrow })

    expect(arrow.style.left).toBe("45px")
    expect(arrow.getAttribute("data-side")).toBe("bottom")
    expect(arrow.hasAttribute("data-uncentered")).toBe(false)
  })

  it("marks an arrow that cannot point at the center", () => {
    box(anchor, { left: 0, top: 300, width: 4, height: 40 })
    place(anchor, popup, { side: "bottom", align: "start", collisionPadding: 20, arrow })

    expect(arrow.style.left).toBe("5px")
    expect(arrow.hasAttribute("data-uncentered")).toBe(true)
  })

  it("marks an anchor out of the viewport", () => {
    box(anchor, { left: 400, top: -100, width: 100, height: 40 })
    place(anchor, popup, { side: "bottom" })

    expect(popup.hasAttribute("data-anchor-hidden")).toBe(true)
  })
})

describe("position() and unposition()", () => {
  it("places the popup again on a resize, until the signal aborts", async () => {
    const placing = new AbortController()
    position(anchor, popup, () => ({ side: "bottom" }), placing.signal)
    expect(at().top).toBe("340px")

    box(anchor, { left: 400, top: 100, width: 100, height: 40 })
    window.dispatchEvent(new Event("resize"))
    await new Promise((resolve) => requestAnimationFrame(resolve))
    expect(at().top).toBe("140px")

    placing.abort()
    box(anchor, { left: 400, top: 200, width: 100, height: 40 })
    window.dispatchEvent(new Event("resize"))
    await new Promise((resolve) => requestAnimationFrame(resolve))
    expect(at().top).toBe("140px")
  })

  it("removes the styles and the attributes of the position", () => {
    place(anchor, popup, { side: "bottom", arrow })
    unposition(popup, arrow)

    expect(popup.hasAttribute("style")).toBe(false)
    expect(popup.hasAttribute("data-side")).toBe(false)
    expect(arrow.hasAttribute("style")).toBe(false)
    expect(arrow.hasAttribute("data-side")).toBe(false)
  })
})
