import { afterEach, describe, expect, it } from "vitest"
import { link } from "../dist/phem_aria.js"
import { stubElementReflection } from "./support.js"

afterEach(() => {
  document.body.innerHTML = ""
})

function parts(markup) {
  document.body.innerHTML = markup

  return [document.getElementById("from"), document.querySelector("[data-to]")]
}

describe("link()", () => {
  it("links with the id of the element", () => {
    const [from, to] = parts('<button id="from"></button><div id="to" data-to></div>')
    link(from, "aria-controls", "ariaControlsElements", to)

    expect(from.getAttribute("aria-controls")).toBe("to")
  })

  it("keeps a value that the server render gives", () => {
    const [from, to] = parts('<button id="from" aria-controls="other"></button><div id="to" data-to></div>')
    link(from, "aria-controls", "ariaControlsElements", to)

    expect(from.getAttribute("aria-controls")).toBe("other")
  })

  it("links with an element reference to an element with no id or a temporary id of the morph", () => {
    const restore = stubElementReflection()
    try {
      const [from, to] = parts('<button id="from" aria-controls="phem-key:old"></button><div id="phem-key:list/div:a" data-to></div>')
      link(from, "aria-controls", "ariaControlsElements", to)

      expect(from.getAttribute("aria-controls")).toBe("")
      expect(from.ariaControlsElements).toEqual([to])
    } finally {
      restore()
    }
  })

  it("does not set the same element reference again", () => {
    const restore = stubElementReflection()
    try {
      const [from, to] = parts('<button id="from"></button><div data-to></div>')
      link(from, "aria-controls", "ariaControlsElements", to)
      const set = []
      const descriptor = Object.getOwnPropertyDescriptor(Element.prototype, "ariaControlsElements")
      Object.defineProperty(from, "ariaControlsElements", { configurable: true, get: descriptor.get, set: (value) => set.push(value) })
      link(from, "aria-controls", "ariaControlsElements", to)

      expect(set).toEqual([])
    } finally {
      restore()
    }
  })

  it("removes a temporary id when the browser has no element references", () => {
    const [from, to] = parts('<button id="from" aria-controls="phem-key:old"></button><div data-to></div>')
    link(from, "aria-controls", "ariaControlsElements", to)

    expect(from.hasAttribute("aria-controls")).toBe(false)
  })

  it("reads the id attribute of a form with a field that has the name id", () => {
    const [from, to] = parts('<button id="from"></button><form id="filters" data-to><input name="id"></form>')
    link(from, "aria-controls", "ariaControlsElements", to)

    expect(from.getAttribute("aria-controls")).toBe("filters")
  })
})
