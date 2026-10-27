import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemClipboardController from "../dist/phem_clipboard_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application
let writeText

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-clipboard", PhemClipboardController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  writeText = vi.fn(() => Promise.resolve())
  Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true })
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  delete navigator.clipboard
  document.body.innerHTML = ""
})

// A source with the text "s3cret", a "Copy" button, a feedback, a "Save" button that sends an
// event, and the `attributes` of the controller element. The server render has no feedback state.
function token(attributes = "", source = `<code id="source" data-phem-clipboard-target="source">s3cret</code>`) {
  return `<div id="clip" data-controller="phem-clipboard" data-phem-clipboard-copied-class="copied" ${attributes}>
      ${source}
      <button id="copy" data-action="phem-clipboard#copy">Copy</button>
      <span id="feedback" data-phem-clipboard-target="feedback"></span>
      <button id="save" data-phem-on-click="c1.save">Save</button>
    </div>`
}

function clip() {
  return document.getElementById("clip")
}

function feedback() {
  return document.getElementById("feedback")
}

function isCopied() {
  return feedback().textContent === "Copied" && clip().classList.contains("copied")
}

function isIdle() {
  return feedback().textContent === "" && !clip().classList.contains("copied")
}

// Resolves with the `phem-clipboard:copied` or `phem-clipboard:error` event of the next copy.
function nextCopy() {
  return new Promise((resolve) => {
    clip().addEventListener("phem-clipboard:copied", resolve, { once: true })
    clip().addEventListener("phem-clipboard:error", resolve, { once: true })
  })
}

async function copy() {
  const copied = nextCopy()
  click("#copy")

  return copied
}

async function morphOnSave() {
  const done = nextDone()
  click("#save")
  await done
}

// Stimulus connects and disconnects the controller in a mutation observer callback: a microtask.
async function mutations() {
  for (let i = 0; i < 5; i++) await Promise.resolve()
}

describe("phem-clipboard", () => {
  it("copies the text of the source target and shows the feedback", async () => {
    await mount(token())

    expect(isIdle()).toBe(true)
    expect(feedback().getAttribute("role")).toBe("status")
    const event = await copy()

    expect(writeText).toHaveBeenCalledWith("s3cret")
    expect(event.detail.text).toBe("s3cret")
    expect(isCopied()).toBe(true)
  })

  it("copies the text of the source target with no whitespace at the start and at the end", async () => {
    await mount(token("", `<code data-phem-clipboard-target="source">\n    s3cret\n  </code>`))
    await copy()

    expect(writeText).toHaveBeenCalledWith("s3cret")
  })

  it("copies the value of an input source", async () => {
    await mount(token("", `<input id="source" data-phem-clipboard-target="source" value=" typed " />`))
    await copy()

    expect(writeText).toHaveBeenCalledWith(" typed ")
  })

  it("copies the text value before the source target", async () => {
    await mount(token('data-phem-clipboard-text-value="from-value"'))
    await copy()

    expect(writeText).toHaveBeenCalledWith("from-value")
  })

  it("shows the label value", async () => {
    await mount(token('data-phem-clipboard-label-value="Done"'))
    await copy()

    expect(feedback().textContent).toBe("Done")
  })

  it("hides the feedback after the duration", async () => {
    await mount(token('data-phem-clipboard-duration-value="1000"'))
    vi.useFakeTimers()
    await copy()

    vi.advanceTimersByTime(999)
    expect(isCopied()).toBe(true)
    vi.advanceTimersByTime(1)
    expect(isIdle()).toBe(true)
  })

  it("starts the duration again on a second copy", async () => {
    await mount(token('data-phem-clipboard-duration-value="1000"'))
    vi.useFakeTimers()
    await copy()
    vi.advanceTimersByTime(600)
    await copy()
    vi.advanceTimersByTime(600)

    expect(isCopied()).toBe(true)
    vi.advanceTimersByTime(400)
    expect(isIdle()).toBe(true)
  })

  it("keeps the feedback after the morph of an event, and does not change the live region", async () => {
    await mount(token())
    respond(token().replace('id="feedback"', 'id="feedback" class="note"'))
    await copy()
    const label = feedback().firstChild
    await morphOnSave()

    expect(isCopied()).toBe(true)
    expect(feedback().firstChild).toBe(label)
    expect(feedback().className).toBe("note")
    expect(feedback().getAttribute("role")).toBe("status")
  })

  // The fake time also moves with the real time: the morph of the event needs its timers.
  it("hides the feedback after the morph when the duration ends", async () => {
    await mount(token('data-phem-clipboard-duration-value="60000"'))
    respond(token('data-phem-clipboard-duration-value="60000"'))
    vi.useFakeTimers({ shouldAdvanceTime: true })
    await copy()
    await morphOnSave()

    expect(isCopied()).toBe(true)
    vi.advanceTimersByTime(60000)
    expect(isIdle()).toBe(true)
  })

  it("gives a feedback target that the morph adds the state", async () => {
    const extra = `<span id="extra" data-phem-clipboard-target="feedback"></span>`
    await mount(token())
    respond(token().replace("</div>", `${extra}</div>`))
    await copy()
    await morphOnSave()

    expect(document.getElementById("extra").textContent).toBe("Copied")
    expect(document.getElementById("extra").getAttribute("role")).toBe("status")
  })

  it("keeps the role of the server render", async () => {
    await mount(token().replace('id="feedback"', 'id="feedback" role="alert"'))

    expect(feedback().getAttribute("role")).toBe("alert")
  })

  it("dispatches an error and shows no feedback when the browser does not copy", async () => {
    writeText.mockRejectedValueOnce(new DOMException("Denied", "NotAllowedError"))
    await mount(token())
    const event = await copy()

    expect(event.type).toBe("phem-clipboard:error")
    expect(event.detail.error.name).toBe("NotAllowedError")
    expect(isIdle()).toBe(true)
  })

  it("hides the feedback of the last copy when a new copy fails", async () => {
    await mount(token())
    await copy()
    writeText.mockRejectedValueOnce(new DOMException("Denied", "NotAllowedError"))
    await copy()

    expect(isIdle()).toBe(true)
  })

  it("hides the feedback on a disconnect", async () => {
    await mount(token())
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] })
    await copy()
    clip().removeAttribute("data-controller")
    await mutations()

    expect(isIdle()).toBe(true)
    expect(vi.getTimerCount()).toBe(0)
  })

  it("does not show the feedback of a copy that ends after a disconnect", async () => {
    let resolve
    writeText.mockReturnValueOnce(new Promise((r) => (resolve = r)))
    await mount(token())
    const copied = vi.fn()
    clip().addEventListener("phem-clipboard:copied", copied)
    click("#copy")
    clip().removeAttribute("data-controller")
    await mutations()
    clip().setAttribute("data-controller", "phem-clipboard")
    await mutations()
    resolve()
    await mutations()

    expect(copied).not.toHaveBeenCalled()
    expect(isIdle()).toBe(true)
  })
})
