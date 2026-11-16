import { afterEach, describe, expect, it } from "vitest"
import { edgeTabbable, nextTabbable } from "../dist/phem_focus.js"

afterEach(() => {
  document.body.innerHTML = ""
})

const $ = (id) => document.getElementById(id)

describe("nextTabbable", () => {
  it("gives the next element that Tab goes to, or null at the end", () => {
    document.body.innerHTML = `<div id="root" tabindex="-1">
        <button id="a">A</button>
        <span id="text">Text</span>
        <button id="off" disabled>Off</button>
        <div id="item" tabindex="-1">Item</div>
        <button id="b">B</button>
      </div>`

    expect(nextTabbable($("root"), $("root"))).toBe($("a"))
    expect(nextTabbable($("root"), $("a"))).toBe($("b"))
    expect(nextTabbable($("root"), $("item"))).toBe($("b"))
    expect(nextTabbable($("root"), $("b"))).toBe(null)
    expect(nextTabbable($("root"), $("item"), { backward: true })).toBe($("a"))
    expect(nextTabbable($("root"), $("a"), { backward: true })).toBe(null)
    expect(nextTabbable($("root"), $("root"), { backward: true })).toBe(null)
  })

  it("skips the other radios of the group, and gives the checked radio of another group", () => {
    document.body.innerHTML = `<div id="root">
        <input type="radio" name="size" id="small" checked>
        <input type="radio" name="size" id="large">
        <input type="radio" name="color" id="red">
        <input type="radio" name="color" id="blue" checked>
      </div>`

    expect(nextTabbable($("root"), $("small"))).toBe($("blue"))
    expect(nextTabbable($("root"), $("blue"))).toBe(null)
    expect(nextTabbable($("root"), $("blue"), { backward: true })).toBe($("small"))
  })
})

describe("edgeTabbable", () => {
  it("gives the first and the last element, with the checked radio of a group", () => {
    document.body.innerHTML = `<div id="root">
        <button id="a">A</button>
        <input type="radio" name="size" id="small" checked>
        <input type="radio" name="size" id="large">
      </div>`

    expect(edgeTabbable($("root"))).toBe($("a"))
    expect(edgeTabbable($("root"), { backward: true })).toBe($("small"))
    expect(edgeTabbable(document.createElement("div"))).toBe(null)
  })
})
