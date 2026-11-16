import { beforeEach, describe, expect, it, vi } from "vitest"
import { hide, settle, show } from "../dist/phem_transition.js"

let part

// jsdom has no animations: the part gets a fake animation that the test ends.
function animate(el) {
  let finish
  const finished = new Promise((resolve) => (finish = resolve))
  el.getAnimations = () => [{ finished }]

  return () => finish()
}

function noAnimations(el) {
  el.getAnimations = () => []
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
  document.body.innerHTML = `<div id="part" hidden></div>`
  part = document.getElementById("part")
})

describe("show()", () => {
  it("keeps the animation with a string instant, and gives the cause in data-instant", async () => {
    const shown = show(part, { instant: "click" })

    expect(part.getAttribute("data-instant")).toBe("click")
    expect(part.hasAttribute("data-open")).toBe(true)
    expect(part.hasAttribute("data-starting-style")).toBe(true)
    expect(part.hidden).toBe(false)

    await shown

    expect(part.hasAttribute("data-starting-style")).toBe(false)
  })

  it("changes at once with instant: true", async () => {
    const shown = show(part, { instant: true })

    expect(part.getAttribute("data-instant")).toBe("")
    expect(part.hasAttribute("data-starting-style")).toBe(false)
    await expect(shown).resolves.toBeUndefined()
  })

  it("removes data-instant for a change with the pointer", async () => {
    await show(part, { instant: "click" })
    await hide(part, { instant: true })
    await show(part, { instant: false })

    expect(part.hasAttribute("data-instant")).toBe(false)
  })
})

describe("hide()", () => {
  beforeEach(async () => {
    await show(part)
  })

  it("waits for the animations with a string instant", async () => {
    const finish = animate(part)
    const onEnd = vi.fn()

    const hidden = hide(part, { instant: "dismiss", onEnd })

    expect(part.getAttribute("data-instant")).toBe("dismiss")
    expect(part.hasAttribute("data-ending-style")).toBe(true)
    expect(part.hidden).toBe(false)
    expect(onEnd).not.toHaveBeenCalled()

    finish()
    await hidden

    expect(part.hidden).toBe(true)
    expect(part.hasAttribute("data-ending-style")).toBe(false)
    expect(onEnd).toHaveBeenCalledOnce()
  })

  it("closes at once with a string instant and no animation", () => {
    noAnimations(part)
    const onEnd = vi.fn()

    hide(part, { instant: "dismiss", onEnd })

    expect(part.hidden).toBe(true)
    expect(onEnd).toHaveBeenCalledOnce()
  })

  it("closes at once with instant: true, with no data-ending-style", () => {
    animate(part)
    const setAttribute = vi.spyOn(part, "setAttribute")

    hide(part, { instant: true })

    expect(part.hidden).toBe(true)
    expect(setAttribute).not.toHaveBeenCalledWith("data-ending-style", "")
  })
})

describe("settle()", () => {
  it("gives the size variables auto after the animations of an open panel", async () => {
    const finish = animate(part)
    show(part)

    const settled = settle(part, "collapsible")
    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("")

    finish()
    await settled

    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("auto")
    expect(part.style.getPropertyValue("--collapsible-panel-width")).toBe("auto")
  })

  it("does not change a panel that is closed at the end", async () => {
    const finish = animate(part)
    await show(part)
    part.removeAttribute("data-open")

    const settled = settle(part, "collapsible")
    finish()
    await settled

    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("")
  })

  it("stops when a new hide() starts before the end", async () => {
    const finish = animate(part)
    show(part)

    const settled = settle(part, "collapsible")
    hide(part)
    // The panel opens again with no new show(): only the stop of settle() keeps the variables.
    part.setAttribute("data-open", "")
    finish()
    await settled
    await flush()

    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("")
  })

  it("gives the size variables auto at the next frame for a panel with no animation", async () => {
    noAnimations(part)
    await show(part)

    await settle(part, "collapsible")

    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("auto")
  })

  it("works for a panel that opened with no show()", async () => {
    noAnimations(part)
    part.setAttribute("data-open", "")

    await settle(part, "collapsible")

    expect(part.style.getPropertyValue("--collapsible-panel-width")).toBe("auto")
  })

  it("reads the animations after the first frame of show()", async () => {
    let finish
    const finished = new Promise((resolve) => (finish = resolve))
    // The transition starts when the first frame removes data-starting-style.
    part.getAnimations = () => (part.hasAttribute("data-starting-style") ? [] : [{ finished }])
    show(part)

    const settled = settle(part, "collapsible")
    await flush()
    await new Promise((resolve) => requestAnimationFrame(resolve))
    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("")

    finish()
    await settled
    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("auto")
  })

  it("does not wait for an infinite animation", async () => {
    part.getAnimations = () => [{ finished: new Promise(() => {}), effect: { getTiming: () => ({ iterations: Infinity }) } }]
    await show(part)

    await settle(part, "collapsible")

    expect(part.style.getPropertyValue("--collapsible-panel-height")).toBe("auto")
  })
})
