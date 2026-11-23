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

function pointer(type, el, x, y, pointerType = "mouse") {
  el.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, isPrimary: true, button: 0, pointerId: 1, clientX: x, clientY: y, pointerType }))
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
    expect(order("todo")).toBe("b,a,c")
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

  it("keeps the item at its place during the drag, and marks the drop place", () => {
    const root = document.documentElement.style
    drag($("a"), [50, 20], [50, 70], { drop: false })

    // The gap between "b" (40 to 80) and "c" (80 to 120).
    expect(order("todo")).toBe("a,b,c")
    expect($("todo").hasAttribute("data-drop")).toBe(true)
    expect(root.getPropertyValue("--sortable-drop-x")).toBe("50px")
    expect(root.getPropertyValue("--sortable-drop-y")).toBe("80px")
    expect(root.getPropertyValue("--sortable-drop-width")).toBe("100px")
    expect(root.getPropertyValue("--sortable-drop-height")).toBe("0px")

    // After the last item of the other list.
    pointer("pointermove", document, 250, 30)
    expect($("todo").hasAttribute("data-drop")).toBe(false)
    expect($("done").hasAttribute("data-drop")).toBe(true)
    expect(root.getPropertyValue("--sortable-drop-x")).toBe("250px")
    expect(root.getPropertyValue("--sortable-drop-y")).toBe("40px")

    pointer("pointerup", document, 250, 30)
    expect(order("done")).toBe("d,a")
    expect($("done").hasAttribute("data-drop")).toBe(false)
    expect(root.getPropertyValue("--sortable-drop-y")).toBe("")
  })

  it("marks the start of an empty list", () => {
    $("d").remove()
    drag($("a"), [50, 20], [250, 30], { drop: false })

    expect($("done").hasAttribute("data-drop")).toBe(true)
    expect(document.documentElement.style.getPropertyValue("--sortable-drop-y")).toBe("0px")

    pointer("pointerup", document, 250, 30)
    expect(order("done")).toBe("a")
  })

  it("has no marker at the place of the item", () => {
    drag($("b"), [50, 60], [50, 50], { drop: false })

    expect($("todo").hasAttribute("data-drop")).toBe(false)
    expect(document.documentElement.style.getPropertyValue("--sortable-drop-y")).toBe("")
    pointer("pointerup", document, 50, 50)
  })

  it("moves the item with moveBefore() when the browser has it", () => {
    // A move that keeps the focus, as the browser does.
    const moveBefore = vi.fn(function (node, child) {
      const focused = document.activeElement
      this.insertBefore(node, child)
      focused.focus()
    })
    Element.prototype.moveBefore = moveBefore

    try {
      drag($("a"), [50, 20], [50, 100], { drop: false })
      expect(moveBefore).not.toHaveBeenCalled()

      pointer("pointerup", document, 50, 100)
      expect(moveBefore).toHaveBeenCalledTimes(1)
      expect(order("todo")).toBe("b,c,a")

      $("a").focus()
      press(" ")
      press("ArrowUp")
      expect(moveBefore).toHaveBeenCalledTimes(2)
      expect(order("todo")).toBe("b,a,c")
      press("Escape")
    } finally {
      delete Element.prototype.moveBefore
    }
  })

  it("cancels the drag on Escape: the item stays, and the marker goes", () => {
    drag($("a"), [50, 20], [250, 5], { drop: false })
    expect($("done").hasAttribute("data-drop")).toBe(true)

    press("Escape", document)
    expect(order("todo")).toBe("a,b,c")
    expect(order("done")).toBe("d")
    expect($("done").hasAttribute("data-drop")).toBe(false)

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

  describe("a touch on an item with no handle", () => {
    beforeEach(() => vi.useFakeTimers())

    afterEach(() => vi.useRealTimers())

    it("starts a drag after a hold, and stops the scroll", () => {
      pointer("pointerdown", $("a"), 50, 20, "touch")
      expect($("a").hasAttribute("data-dragging")).toBe(false)

      vi.advanceTimersByTime(250)
      expect($("a").hasAttribute("data-dragging")).toBe(true)

      const scroll = new Event("touchmove", { bubbles: true, cancelable: true })
      document.dispatchEvent(scroll)
      expect(scroll.defaultPrevented).toBe(true)

      pointer("pointermove", document, 50, 100, "touch")
      pointer("pointerup", document, 50, 100, "touch")
      expect(order("todo")).toBe("b,c,a")
    })

    it("finds the drop place at the point of the press for a scroll before the first move", () => {
      pointer("pointerdown", $("a"), 50, 20, "touch")
      vi.advanceTimersByTime(250)
      const at = vi.spyOn(document, "elementFromPoint")

      document.dispatchEvent(new Event("scroll"))

      expect(at).toHaveBeenCalledWith(50, 20)
      pointer("pointerup", document, 50, 20, "touch")
    })

    it("scrolls the page on a move before the end of the hold", () => {
      pointer("pointerdown", $("a"), 50, 20, "touch")
      pointer("pointermove", document, 50, 60, "touch")
      vi.advanceTimersByTime(250)

      const scroll = new Event("touchmove", { bubbles: true, cancelable: true })
      document.dispatchEvent(scroll)
      expect(scroll.defaultPrevented).toBe(false)
      expect($("a").hasAttribute("data-dragging")).toBe(false)

      pointer("pointermove", document, 50, 100, "touch")
      pointer("pointerup", document, 50, 100, "touch")
      expect(order("todo")).toBe("a,b,c")
      expect(calls).toEqual([])
    })

    it("does not wait on a handle", () => {
      $("a").innerHTML = `<span id="grip" data-phem-sortable-target="handle">⠿</span> a`
      pointer("pointerdown", $("grip"), 50, 20, "touch")
      pointer("pointermove", document, 50, 30, "touch")

      expect($("a").hasAttribute("data-dragging")).toBe(true)
      pointer("pointerup", document, 50, 30, "touch")
    })
  })

  it("presses the handle during a drag with the keyboard", () => {
    $("a").innerHTML = `<button id="grip" data-phem-sortable-target="handle" aria-label="Move a">⠿</button> a`
    $("grip").focus()
    press(" ")
    expect($("grip").getAttribute("aria-pressed")).toBe("true")
    expect($("a").hasAttribute("aria-pressed")).toBe(false)

    press("ArrowDown")
    press(" ")
    expect($("grip").hasAttribute("aria-pressed")).toBe(false)
    expect(order("todo")).toBe("b,a,c")
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

    // A step, and the delay of its live text.
    const step = (key) => {
      press(key)
      vi.advanceTimersByTime(500)
    }

    beforeEach(() => {
      said = []
      window.addEventListener("phem:announce", listen)
      vi.useFakeTimers()
    })

    afterEach(() => {
      vi.runOnlyPendingTimers()
      vi.useRealTimers()
      window.removeEventListener("phem:announce", listen)
    })

    it("tells each step of a move", () => {
      $("a").focus()
      step(" ")
      step("ArrowDown")
      step("ArrowRight")
      step("Enter")

      expect(said).toEqual([
        "Picked up a. Position 1 of 3 in todo.",
        "a: position 2 of 3 in todo.",
        "a: position 2 of 2 in done.",
        "Dropped a at position 2 of 2 in done.",
      ])
    })

    it("tells a cancel, and no move at the end of the list", () => {
      $("c").focus()
      step("Enter")
      step("ArrowDown")
      step("Escape")

      expect(said).toEqual(["Picked up c. Position 3 of 3 in todo.", "Cancelled. c is back at position 3 of 3 in todo."])
    })

    it("uses the messages of the list, and the aria-label of the item and of the list", () => {
      $("todo").setAttribute("aria-label", "To do")
      $("todo").setAttribute("data-phem-sortable-picked-up-message-value", "{item} ({position}/{count}, {list})")
      $("b").setAttribute("aria-label", "Card B")
      $("b").focus()
      step(" ")

      expect(said).toEqual(["Card B (2/3, To do)"])
      press("Escape")
    })

    it("has no list in the text of a list with no name", () => {
      $("todo").removeAttribute("data-phem-sortable-list-value")
      $("a").focus()
      step(" ")

      expect(said).toEqual(["Picked up a. Position 1 of 3."])
      press("Escape")
    })

    it("waits before it tells a step, and tells only the last step of the delay", () => {
      $("a").focus()
      press(" ")
      press("ArrowDown")
      expect(said).toEqual([])

      vi.advanceTimersByTime(500)
      expect(said).toEqual(["a: position 2 of 3 in todo."])
      step("Escape")
    })

    it("tells the drop and the cancel of a drag with the pointer, not a drop at the same place", () => {
      drag($("a"), [50, 20], [50, 10])
      vi.advanceTimersByTime(500)
      expect(said).toEqual([])

      drag($("a"), [50, 20], [50, 100])
      vi.advanceTimersByTime(500)
      expect(said).toEqual(["Dropped a at position 3 of 3 in todo."])

      drag($("b"), [50, 20], [250, 5], { drop: false })
      press("Escape", document)
      vi.advanceTimersByTime(500)
      expect(said.at(-1)).toBe("Cancelled. b is back at position 1 of 3 in todo.")
    })
  })
})
