import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemTimeController from "../dist/phem_time_controller.js"
import { click, mount, nextDone, respond, settle } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-time", PhemTimeController)
})

// The time of the tests.
const NOW = new Date("2026-11-11T14:00:00Z")

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  vi.useFakeTimers({ toFake: ["Date", "setTimeout", "clearTimeout"] })
  vi.setSystemTime(NOW)
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A `<time>` with the UTC text of the server, and a button that sends an event.
function time(datetime, attributes = "") {
  return `<time id="time" lang="en" datetime="${datetime}" data-controller="phem-time" ${attributes}>${datetime} UTC</time>
    <button id="save" data-phem-on-click="c1.save">Save</button>`
}

async function connect(markup) {
  const ready = mount(markup)
  await vi.advanceTimersByTimeAsync(0)
  await ready
}

function text() {
  return document.getElementById("time").textContent
}

describe("phem-time", () => {
  it("shows a relative time, and updates it", async () => {
    await connect(time("2026-11-11T13:57:00Z"))

    expect(text()).toBe("3 minutes ago")
    expect(document.getElementById("time").hasAttribute("data-past")).toBe(true)

    await vi.advanceTimersByTimeAsync(60 * 1000)
    expect(text()).toBe("4 minutes ago")
  })

  it("sets the title of the full date and time", async () => {
    await connect(time("2026-11-11T13:57:00Z"))

    expect(document.getElementById("time").title).toMatch(/November 11, 2026/)
  })

  it("keeps the title of the server render", async () => {
    await connect(time("2026-11-11T13:57:00Z", 'title="Created"'))

    expect(document.getElementById("time").title).toBe("Created")
  })

  it("shows a date in the format of the language", async () => {
    await connect(time("2026-11-11T13:57:00Z", 'data-phem-time-format-value="date"'))

    expect(text()).toBe("Nov 11, 2026")
  })

  it("counts down to a future time, and dispatches reached", async () => {
    await connect(time("2026-11-11T14:00:30Z"))
    const reached = vi.fn()
    document.getElementById("time").addEventListener("phem-time:reached", reached)

    expect(text()).toBe("in 30 seconds")
    expect(document.getElementById("time").hasAttribute("data-future")).toBe(true)

    await vi.advanceTimersByTimeAsync(10 * 1000)
    expect(text()).toBe("in 20 seconds")
    expect(reached).not.toHaveBeenCalled()

    // The time comes 1 ms after the last second.
    await vi.advanceTimersByTimeAsync(20 * 1000 + 1)
    expect(text()).toBe("now")
    expect(document.getElementById("time").hasAttribute("data-past")).toBe(true)
    expect(reached).toHaveBeenCalledTimes(1)
  })

  it("counts the seconds of the last minute", async () => {
    await connect(time("2026-11-11T14:01:00.500Z"))
    expect(text()).toBe("in 1 minute")

    // The timer comes at 60 s exactly: the text changes 1 ms later.
    await vi.advanceTimersByTimeAsync(500 + 1)
    expect(text()).toBe("in 59 seconds")
  })

  it("takes a Symfony locale as a language tag", async () => {
    await connect(time("2026-11-11T13:57:00Z", 'data-phem-time-format-value="date"').replace('lang="en"', 'lang="fr_FR"'))

    expect(text()).toBe("11 nov. 2026")
  })

  it("gives the language of the browser for a language that Intl does not know", async () => {
    await connect(time("2026-11-11T13:57:00Z").replace('lang="en"', 'lang="not a tag"'))

    expect(text()).toMatch(/3/)
  })

  it("keeps the title that it set when it connects again", async () => {
    await connect(time("2026-11-11T13:57:00Z"))
    const element = document.getElementById("time")
    element.parentElement.append(element)
    await vi.advanceTimersByTimeAsync(0)

    element.setAttribute("datetime", "2026-11-11T12:00:00Z")
    application.getControllerForElementAndIdentifier(element, "phem-time").render()
    expect(element.title).toMatch(/12:00|1:00/)
  })

  it("shows the time again after the morph of an event", async () => {
    vi.useRealTimers()
    await mount(time(new Date(Date.now() - 2 * 3600 * 1000).toISOString()))
    expect(text()).toBe("2 hours ago")

    respond(time(new Date(Date.now() - 2 * 3600 * 1000).toISOString()))
    const done = nextDone()
    click("#save")
    await done
    await settle()

    expect(text()).toBe("2 hours ago")
  })
})
