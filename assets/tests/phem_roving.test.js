import { beforeEach, describe, expect, it } from "vitest"
import { nextItem, Typeahead } from "../dist/phem_roving.js"

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
})
