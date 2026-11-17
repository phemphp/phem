import { afterEach, describe, expect, it } from "vitest"
import { swipe } from "../dist/phem_swipe.js"

afterEach(() => {
  document.body.innerHTML = ""
})

function pointer(type, x, y, target = document) {
  const event = new PointerEvent(type, { bubbles: true, cancelable: true, isPrimary: true, button: 0, pointerId: 1, clientX: x, clientY: y })
  target.dispatchEvent(event)

  return event
}

describe("swipe", () => {
  it("moves on both axes without lockAxis", () => {
    const moves = []
    const stop = swipe(pointer("pointerdown", 100, 100), ["down", "right"], { move: (movement) => moves.push(movement), release: () => {} })

    pointer("pointermove", 120, 130)
    stop()

    expect(moves).toEqual([{ x: 20, y: 30 }])
  })

  it("keeps the axis of the first move with lockAxis, and gives the locked direction", () => {
    const moves = []
    const stop = swipe(pointer("pointerdown", 100, 100), ["down", "right"], { lockAxis: true, move: (movement, direction) => moves.push([movement, direction]), release: () => {} })

    pointer("pointermove", 120, 108)
    pointer("pointermove", 125, 140)
    stop()

    expect(moves).toEqual([
      [{ x: 20, y: 0 }, "right"],
      [{ x: 25, y: 0 }, "right"],
    ])
  })

  it("locks the vertical axis when the first move is mostly vertical", () => {
    const moves = []
    let ended = null
    swipe(pointer("pointerdown", 100, 100), ["down", "right"], { lockAxis: true, move: (movement, direction) => moves.push([movement, direction]), release: (end) => (ended = end) })

    pointer("pointermove", 103, 120)
    pointer("pointermove", 160, 125)
    pointer("pointerup", 160, 125)

    expect(moves.at(-1)).toEqual([{ x: 0, y: 25 }, "down"])
    expect(ended.direction).toBe("down")
    expect(ended.movement).toEqual({ x: 0, y: 25 })
  })

  it("does not lock an axis when the list has directions on one axis only", () => {
    const moves = []
    const stop = swipe(pointer("pointerdown", 100, 100), ["right"], { lockAxis: true, move: (movement) => moves.push(movement), release: () => {} })

    pointer("pointermove", 103, 130)
    pointer("pointermove", 150, 130)
    stop()

    expect(moves.at(-1).x).toBe(50)
  })

  it("gives no direction when a locked move comes back to its start", () => {
    const moves = []
    const stop = swipe(pointer("pointerdown", 100, 100), ["down", "right"], { lockAxis: true, move: (movement, direction) => moves.push([movement, direction]), release: () => {} })

    pointer("pointermove", 120, 100)
    pointer("pointermove", 100, 110)
    stop()

    expect(moves.at(-1)).toEqual([{ x: 0, y: 0 }, null])
  })
})

