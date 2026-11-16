import { afterEach, describe, expect, it, vi } from "vitest"
import { ReleaseClick } from "../dist/phem_release.js"

afterEach(() => {
  vi.useRealTimers()
  document.body.innerHTML = ""
})

const $ = (id) => document.getElementById(id)

function setup(open = () => true) {
  document.body.innerHTML = '<button id="item">Item</button><button id="other">Other</button>'
  const clicks = []
  $("item").addEventListener("click", (event) => clicks.push(event))
  const release = new ReleaseClick({ itemOf: (target) => (target === $("item") ? target : null), isOpen: open })

  return { release, clicks }
}

const up = (el, init = {}) => el.dispatchEvent(new PointerEvent("pointerup", { bubbles: true, button: 0, ...init }))

describe("ReleaseClick", () => {
  it("clicks the item at a release after the delay, with the keys and the point of the release", () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    const { release, clicks } = setup()
    release.arm({ delay: 200, accepts: (event) => event.button === 0 })
    vi.advanceTimersByTime(250)
    up($("item"), { metaKey: true, shiftKey: true, clientX: 30, clientY: 40 })

    expect(clicks).toHaveLength(1)
    expect([clicks[0].metaKey, clicks[0].shiftKey, clicks[0].clientX, clicks[0].clientY, clicks[0].detail]).toEqual([true, true, 30, 40, 1])
  })

  it("does not click before the delay, for a release that it does not accept, or on another element", () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    const { release, clicks } = setup()

    release.arm({ delay: 200, accepts: () => true })
    up($("item"))
    release.arm({ delay: 200, accepts: (event) => event.button === 2 })
    vi.advanceTimersByTime(250)
    up($("item"))
    release.arm({ delay: 200, accepts: () => true })
    vi.advanceTimersByTime(250)
    up($("other"))

    expect(clicks).toHaveLength(0)
  })

  it("counts only the next release, and nothing after disarm() or a close", () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    let open = true
    const { release, clicks } = setup(() => open)

    release.arm({ delay: 0, accepts: () => true })
    vi.advanceTimersByTime(1)
    up($("other"))
    up($("item"))
    expect(clicks).toHaveLength(0)

    release.arm({ delay: 0, accepts: () => true })
    vi.advanceTimersByTime(1)
    release.disarm()
    up($("item"))
    expect(clicks).toHaveLength(0)

    release.arm({ delay: 0, accepts: () => true })
    vi.advanceTimersByTime(1)
    open = false
    up($("item"))
    expect(clicks).toHaveLength(0)
  })
})
