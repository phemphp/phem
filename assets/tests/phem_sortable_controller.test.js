import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemSortableController from "../dist/phem_sortable_controller.js"

let application
let calls

beforeAll(() => {
  application = Application.start()
  application.register("phem-sortable", PhemSortableController)
})

// Two columns of a board, and a list of another group. Each item is 40px high, from y = 0.
beforeEach(async () => {
  document.body.innerHTML = `
    <ul id="todo" data-controller="phem-sortable" data-phem-sortable-group-value="board" data-phem-sortable-list-value="todo">
      ${["a", "b", "c"].map(item).join("")}
    </ul>
    <ul id="done" data-controller="phem-sortable" data-phem-sortable-group-value="board" data-phem-sortable-list-value="done">
      ${["d"].map(item).join("")}
    </ul>
    <ul id="other" data-controller="phem-sortable" data-phem-sortable-group-value="other">
      ${["x"].map(item).join("")}
    </ul>`
  await new Promise((resolve) => setTimeout(resolve, 0))

  calls = []
  document.addEventListener("phem:call", record, true)
  layout()
})

afterEach(() => {
  document.removeEventListener("phem:call", record, true)
  document.body.innerHTML = ""
  delete document.elementFromPoint
})

function item(id) {
  return `<li id="${id}" data-phem-sortable-target="item" data-phem-sortable-id="${id}">${id}</li>`
}

function record(event) {
  calls.push({ list: event.target.id, detail: event.detail })
}

const $ = (id) => document.getElementById(id)

// The boxes of the items, from their place in the DOM: the "todo" column at x = 0, "done" at
// x = 200, "other" at x = 400.
function layout() {
  const columns = { todo: 0, done: 200, other: 400 }

  for (const [id, left] of Object.entries(columns)) {
    const list = $(id)
    list.getBoundingClientRect = () => ({ left, top: 0, width: 100, height: 400, right: left + 100, bottom: 400 })

    for (const li of list.querySelectorAll("li")) {
      li.getBoundingClientRect = () => {
        const i = [...li.parentElement.children].indexOf(li)

        return { left, top: i * 40, width: 100, height: 40, right: left + 100, bottom: i * 40 + 40 }
      }
    }
  }

  // The element under a point: an item, or the list.
  document.elementFromPoint = (x, y) => {
    const id = x < 200 ? "todo" : x < 400 ? "done" : "other"
    const list = $(id)
    const li = [...list.children].find((el) => {
      const box = el.getBoundingClientRect()

      return y >= box.top && y < box.bottom
    })

    return li ?? list
  }
}

function pointer(type, el, x, y) {
  el.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, isPrimary: true, button: 0, pointerId: 1, clientX: x, clientY: y }))
}

// A drag of `el` from (x1, y1) to (x2, y2).
function drag(el, [x1, y1], [x2, y2], { drop = true } = {}) {
  pointer("pointerdown", el, x1, y1)
  pointer("pointermove", document, x1 + 3, y1 + 3)
  pointer("pointermove", document, x2, y2)
  if (drop) pointer("pointerup", document, x2, y2)
}

function order(id) {
  return [...$(id).children].map((el) => el.id).join(",")
}

function press(key, el = document.activeElement) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
  el.dispatchEvent(event)

  return event
}

describe("phem-sortable", () => {
  it("makes the items focusable", () => {
    expect($("a").tabIndex).toBe(0)
  })

  it("moves an item in its list, and calls the handler with the detail", () => {
    drag($("a"), [50, 20], [50, 100])

    expect(order("todo")).toBe("b,c,a")
    expect(calls).toEqual([{ list: "todo", detail: { id: "a", index: 2, list: "todo" } }])
  })

  it("marks the item and the lists of the group during the drag", () => {
    drag($("a"), [50, 20], [50, 70], { drop: false })

    expect($("a").hasAttribute("data-dragging")).toBe(true)
    expect($("todo").hasAttribute("data-sorting")).toBe(true)
    expect($("done").hasAttribute("data-sorting")).toBe(true)
    expect($("other").hasAttribute("data-sorting")).toBe(false)
    expect(document.documentElement.hasAttribute("data-phem-sorting")).toBe(true)

    pointer("pointerup", document, 50, 70)
    expect($("a").hasAttribute("data-dragging")).toBe(false)
    expect($("todo").hasAttribute("data-sorting")).toBe(false)
    expect(document.documentElement.hasAttribute("data-phem-sorting")).toBe(false)
  })

  it("moves an item to another list of the group", () => {
    drag($("b"), [50, 60], [250, 5])

    expect(order("todo")).toBe("a,c")
    expect(order("done")).toBe("b,d")
    expect(calls).toEqual([{ list: "done", detail: { id: "b", index: 0, list: "done" } }])
  })

  it("does not move an item to a list of another group", () => {
    drag($("b"), [50, 60], [450, 5])

    expect(order("todo")).toBe("a,b,c")
    expect(order("other")).toBe("x")
    expect(calls).toEqual([])
  })

  it("does not drag on a move under the threshold, and does not call when the place is the same", () => {
    const clicked = vi.fn()
    $("a").addEventListener("click", clicked)
    pointer("pointerdown", $("a"), 50, 20)
    pointer("pointermove", document, 52, 22)
    pointer("pointerup", document, 52, 22)
    $("a").click()

    expect($("a").hasAttribute("data-dragging")).toBe(false)
    expect(clicked).toHaveBeenCalledTimes(1)

    drag($("a"), [50, 20], [50, 10])
    expect(calls).toEqual([])
  })

  it("stops the click after a drop", async () => {
    const clicked = vi.fn()
    $("a").addEventListener("click", clicked)
    drag($("a"), [50, 20], [50, 100])
    $("a").click()

    expect(clicked).not.toHaveBeenCalled()
    await new Promise((resolve) => setTimeout(resolve, 0))
    $("a").click()
    expect(clicked).toHaveBeenCalledTimes(1)
  })

  it("cancels the drag on Escape: the item goes back", () => {
    drag($("a"), [50, 20], [250, 5], { drop: false })
    expect(order("done")).toBe("a,d")

    press("Escape", document)
    expect(order("todo")).toBe("a,b,c")
    expect(order("done")).toBe("d")

    pointer("pointerup", document, 250, 5)
    expect(calls).toEqual([])
  })

  it("starts a drag only on a handle when the item has one", () => {
    $("a").innerHTML = `<span id="grip" data-phem-sortable-target="handle">⠿</span> a`
    drag($("a"), [50, 20], [50, 100])
    expect(order("todo")).toBe("a,b,c")

    drag($("grip"), [50, 20], [50, 100])
    expect(order("todo")).toBe("b,c,a")
  })

  it("moves an item with the keyboard, in its list and to the next list", () => {
    $("a").focus()
    press(" ")
    expect($("a").hasAttribute("data-dragging")).toBe(true)

    press("ArrowDown")
    expect(order("todo")).toBe("b,a,c")
    expect(document.activeElement.id).toBe("a")
    press("ArrowRight")
    expect(order("done")).toBe("d,a")
    press("ArrowUp")
    expect(order("done")).toBe("a,d")

    press("Enter")
    expect($("a").hasAttribute("data-dragging")).toBe(false)
    expect(calls).toEqual([{ list: "done", detail: { id: "a", index: 0, list: "done" } }])
  })

  it("cancels a drag of the keyboard on Escape", () => {
    $("b").focus()
    press("Enter")
    press("ArrowUp")
    expect(order("todo")).toBe("b,a,c")

    press("Escape")
    expect(order("todo")).toBe("a,b,c")
    expect(document.activeElement.id).toBe("b")
    expect(calls).toEqual([])
  })

  it("stops the native drag of an image during a press", () => {
    pointer("pointerdown", $("a"), 50, 20)
    const native = new Event("dragstart", { bubbles: true, cancelable: true })
    $("a").dispatchEvent(native)
    pointer("pointerup", document, 50, 20)

    expect(native.defaultPrevented).toBe(true)
    const later = new Event("dragstart", { bubbles: true, cancelable: true })
    $("a").dispatchEvent(later)
    expect(later.defaultPrevented).toBe(false)
  })

  it("keeps a tabindex that the markup gives", async () => {
    document.body.innerHTML = `<ul data-controller="phem-sortable"><li id="locked" data-phem-sortable-target="item" tabindex="-1">x</li></ul>`
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect($("locked").tabIndex).toBe(-1)
  })

  it("cancels the drag on the morph of a list", () => {
    drag($("a"), [50, 20], [50, 100], { drop: false })
    $("todo").dispatchEvent(new CustomEvent("turbo:before-morph-element", { bubbles: true, detail: { newElement: $("todo").cloneNode(true) } }))

    expect(order("todo")).toBe("a,b,c")
    expect($("a").hasAttribute("data-dragging")).toBe(false)
  })

  describe("the live text of a keyboard drag", () => {
    let said

    const listen = (event) => said.push(event.detail)

    beforeEach(() => {
      said = []
      window.addEventListener("phem:announce", listen)
    })

    afterEach(() => window.removeEventListener("phem:announce", listen))

    it("tells each step of a move", () => {
      $("a").focus()
      press(" ")
      press("ArrowDown")
      press("ArrowRight")
      press("Enter")

      expect(said).toEqual([
        "Picked up a. Position 1 of 3 in todo.",
        "a: position 2 of 3 in todo.",
        "a: position 2 of 2 in done.",
        "Dropped a at position 2 of 2 in done.",
      ])
    })

    it("tells a cancel, and no move at the end of the list", () => {
      $("c").focus()
      press("Enter")
      press("ArrowDown")
      press("Escape")

      expect(said).toEqual(["Picked up c. Position 3 of 3 in todo.", "Cancelled. c is back at position 3 of 3 in todo."])
    })

    it("uses the messages of the list, and the aria-label of the item and of the list", () => {
      $("todo").setAttribute("aria-label", "To do")
      $("todo").setAttribute("data-phem-sortable-picked-up-message-value", "{item} ({position}/{count}, {list})")
      $("b").setAttribute("aria-label", "Card B")
      $("b").focus()
      press(" ")

      expect(said).toEqual(["Card B (2/3, To do)"])
      press("Escape")
    })

    it("has no list in the text of a list with no name", () => {
      $("todo").removeAttribute("data-phem-sortable-list-value")
      $("a").focus()
      press(" ")

      expect(said).toEqual(["Picked up a. Position 1 of 3."])
      press("Escape")
    })

    it("tells nothing for a drag with the pointer", () => {
      drag($("a"), [50, 20], [50, 100])

      expect(said).toEqual([])
    })
  })
})
