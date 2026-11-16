import { afterEach, beforeEach, describe, expect, it, vi } from "vitest"
import { leaveList, nextItem, Typeahead } from "../dist/phem_roving.js"

let items

beforeEach(() => {
  document.body.innerHTML = `<button>Apple</button><button disabled>Banana</button><button>Cherry</button><button aria-disabled="true">Date</button><button>Avocado</button>`
  items = [...document.querySelectorAll("button")]
})

const [apple, , cherry, , avocado] = [0, 1, 2, 3, 4]

describe("nextItem()", () => {
  it("moves with the arrow keys of the orientation, and skips the disabled items", () => {
    expect(nextItem(items, items[apple], "ArrowDown")).toBe(items[cherry])
    expect(nextItem(items, items[cherry], "ArrowDown")).toBe(items[avocado])
    expect(nextItem(items, items[cherry], "ArrowUp")).toBe(items[apple])
    expect(nextItem(items, items[apple], "ArrowRight")).toBeUndefined()
    expect(nextItem(items, items[apple], "ArrowRight", { orientation: "horizontal" })).toBe(items[cherry])
    expect(nextItem(items, items[apple], "ArrowLeft", { orientation: "horizontal", rtl: true })).toBe(items[cherry])
  })

  it("goes from the last item to the first one, unless loop is false", () => {
    expect(nextItem(items, items[avocado], "ArrowDown")).toBe(items[apple])
    expect(nextItem(items, items[apple], "ArrowUp")).toBe(items[avocado])
    expect(nextItem(items, items[avocado], "ArrowDown", { loop: false })).toBeUndefined()
  })

  it("goes to the first and the last item with Home and End, or with no current item", () => {
    expect(nextItem(items, items[cherry], "Home")).toBe(items[apple])
    expect(nextItem(items, items[cherry], "End")).toBe(items[avocado])
    expect(nextItem(items, null, "ArrowDown")).toBe(items[apple])
    expect(nextItem(items, null, "ArrowUp")).toBe(items[avocado])
  })
})

describe("Typeahead", () => {
  const key = (k) => new KeyboardEvent("keydown", { key: k })

  it("finds the next item that starts with the letters, and the next one with the same letter", () => {
    const typeahead = new Typeahead()

    expect(typeahead.find(items, items[apple], key("c"))).toBe(items[cherry])
    typeahead.reset()
    expect(typeahead.find(items, items[apple], key("a"))).toBe(items[avocado])
    expect(typeahead.find(items, items[avocado], key("a"))).toBe(items[apple])
    typeahead.reset()
    expect(typeahead.find(items, null, key("a"))).toBe(items[apple])
    expect(typeahead.find(items, items[apple], key("v"))).toBe(items[avocado])
  })

  it("skips the disabled items and the keys that are not letters", () => {
    const typeahead = new Typeahead()

    expect(typeahead.find(items, items[apple], key("b"))).toBeUndefined()
    expect(typeahead.find(items, items[apple], key("ArrowDown"))).toBeUndefined()
    expect(typeahead.find(items, items[apple], new KeyboardEvent("keydown", { key: "c", ctrlKey: true }))).toBeUndefined()
  })

  describe("with fake timers", () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it("clears the text resetMs after the last letter", () => {
      const typeahead = new Typeahead({ resetMs: 750 })

      expect(typeahead.find(items, items[apple], key("c"))).toBe(items[cherry])
      vi.advanceTimersByTime(600)
      expect(typeahead.find(items, items[apple], key("h"))).toBe(items[cherry])
      vi.advanceTimersByTime(750)
      expect(typeahead.find(items, items[apple], key("a"))).toBe(items[avocado])
    })

    it("clears the text 500 ms after the last letter by default", () => {
      const typeahead = new Typeahead()

      expect(typeahead.find(items, items[apple], key("c"))).toBe(items[cherry])
      vi.advanceTimersByTime(499)
      expect(typeahead.find(items, items[apple], key("h"))).toBe(items[cherry])
      vi.advanceTimersByTime(500)
      expect(typeahead.find(items, items[apple], key("a"))).toBe(items[avocado])
    })
  })

  it("starts a new text after a letter that matches no item", () => {
    const typeahead = new Typeahead()

    expect(typeahead.find(items, items[apple], key("x"))).toBeUndefined()
    expect(typeahead.find(items, items[apple], key("c"))).toBe(items[cherry])
  })

  it("keeps the text after a Space that matches no item", () => {
    const typeahead = new Typeahead()

    expect(typeahead.find(items, items[apple], key("a"))).toBe(items[avocado])
    expect(typeahead.find(items, items[avocado], key(" "))).toBeUndefined()
    // The text is "a a": with a new text, "a" finds Apple.
    expect(typeahead.find(items, items[avocado], key("a"))).toBeUndefined()
  })

  it("starts a new text from the from item", () => {
    const label = (el) => el.textContent

    expect(new Typeahead().find(items, null, key("a"), label, undefined, items[cherry])).toBe(items[avocado])
    expect(new Typeahead().find(items, null, key("a"), label, undefined, items[apple])).toBe(items[avocado])
    expect(new Typeahead().find(items, null, key("a"), label, undefined, items[avocado])).toBe(items[apple])
  })

  it("continues a text from the current item, not from the from item", () => {
    const label = (el) => el.textContent
    const typeahead = new Typeahead()

    // The selected item (from) is Cherry: the first "a" finds Avocado after it. The second "a"
    // continues from the focus on Avocado, so it finds Apple, not Avocado again.
    expect(typeahead.find(items, items[apple], key("a"), label, undefined, items[cherry])).toBe(items[avocado])
    expect(typeahead.find(items, items[avocado], key("a"), label, undefined, items[cherry])).toBe(items[apple])
  })
})

describe("leaveList()", () => {
  let list, separator

  beforeEach(() => {
    document.body.innerHTML = `<div id="list"><div class="item"><span>One</span></div><hr><div class="item">Two</div></div><p id="out"></p>`
    list = [...document.querySelectorAll(".item")]
    separator = document.querySelector("hr")
  })

  const leave = (target, relatedTarget, pointerType = "mouse") => {
    const onLeave = vi.fn()
    leaveList({ target, relatedTarget, pointerType }, list, onLeave)

    return onLeave
  }

  it("runs onLeave when the pointer goes from an item to a part that is not an item", () => {
    expect(leave(list[0], separator)).toHaveBeenCalledOnce()
    expect(leave(list[1], document.getElementById("out"))).toHaveBeenCalledOnce()
    expect(leave(list[1], null)).toHaveBeenCalledOnce()
  })

  it("does not run onLeave from an item to an item, in the same item, from a separator, or for a touch", () => {
    expect(leave(list[0], list[1])).not.toHaveBeenCalled()
    expect(leave(list[0], list[0].querySelector("span"))).not.toHaveBeenCalled()
    expect(leave(separator, document.getElementById("out"))).not.toHaveBeenCalled()
    expect(leave(list[0], separator, "touch")).not.toHaveBeenCalled()
  })
})
