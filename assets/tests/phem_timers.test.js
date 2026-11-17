import { afterEach, describe, expect, it, vi } from "vitest"
import { PausableTimers } from "../dist/phem_timers.js"

afterEach(() => {
  vi.useRealTimers()
})

describe("PausableTimers", () => {
  it("runs only after resume, and keeps the time that remains after a pause", () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] })
    const ended = []
    const timers = new PausableTimers((key) => ended.push(key))

    timers.set("a", 1000)
    vi.advanceTimersByTime(5000)
    expect(ended).toEqual([])

    timers.resume("a")
    vi.advanceTimersByTime(600)
    timers.pause("a")
    timers.pause("a")
    vi.advanceTimersByTime(5000)
    expect(ended).toEqual([])

    timers.resume("a")
    timers.resume("a")
    vi.advanceTimersByTime(399)
    expect(ended).toEqual([])

    vi.advanceTimersByTime(1)
    expect(ended).toEqual(["a"])
    expect(timers.has("a")).toBe(false)
  })

  it("gives no timer for a duration of 0, and starts the full time again on set", () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] })
    const ended = []
    const timers = new PausableTimers((key) => ended.push(key))

    timers.set("a", 0)
    expect(timers.has("a")).toBe(false)

    timers.set("b", 1000)
    timers.resume("b")
    vi.advanceTimersByTime(600)
    timers.set("b", 1000)
    timers.resume("b")
    vi.advanceTimersByTime(999)
    expect(ended).toEqual([])

    vi.advanceTimersByTime(1)
    expect(ended).toEqual(["b"])
  })

  it("stops all the timers on clear", () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] })
    const ended = []
    const timers = new PausableTimers((key) => ended.push(key))

    timers.set("a", 1000)
    timers.resume("a")
    timers.clear()
    vi.advanceTimersByTime(5000)

    expect(ended).toEqual([])
    expect(timers.has("a")).toBe(false)
  })
})
