import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemComboboxController from "../dist/phem_combobox_controller.js"
import { mount, nextDone, respond } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-combobox", PhemComboboxController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A city field: the server gives the `cities` that match. Each city sends a "choose" event.
function field(cities = [], attributes = "") {
  const item = (c) => `<li id="city-${c}" data-phem-combobox-target="item" data-phem-on-click="c1.choose">${c}</li>`

  return `<div id="combobox" data-controller="phem-combobox" ${attributes}>
      <input id="input" data-phem-combobox-target="input" data-phem-on-input="c1.search" aria-label="City" />
      <button id="clear" data-phem-combobox-target="clear">Clear</button>
      <div id="popup" data-phem-combobox-target="popup" hidden>
        <ul id="list" data-phem-combobox-target="list">${cities.map(item).join("")}</ul>
        <p id="empty" data-phem-combobox-target="empty">No city.</p>
      </div>
      <button id="refresh" data-phem-on-click="c1.refresh">Refresh</button>
    </div>
    <button id="outside">Outside</button>`
}

const $ = (id) => document.getElementById(id)

function press(key) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
  $("input").dispatchEvent(event)

  return event
}

// The user types, and the server answers with the cities and the text (as `phem:bind` does).
async function type(text, cities, attributes = "") {
  respond(field(cities, attributes).replace('id="input"', `id="input" value="${text}"`))
  const done = nextDone()
  $("input").focus()
  $("input").value = text
  $("input").dispatchEvent(new Event("input", { bubbles: true }))
  await done
}

// The root of the page also sends the input events.
async function mountField(markup) {
  await mount(markup)
  const root = document.getElementById("phem-root")
  root.setAttribute("data-action", `${root.getAttribute("data-action")} input->phem#dispatch`)
  await new Promise((resolve) => setTimeout(resolve, 0))
}

describe("phem-combobox", () => {
  it("sets the roles and the ARIA attributes", async () => {
    await mountField(field(["Paris"]))

    expect($("input").getAttribute("role")).toBe("combobox")
    expect($("input").getAttribute("aria-autocomplete")).toBe("list")
    expect($("input").getAttribute("aria-controls")).toBe("list")
    expect($("input").getAttribute("aria-expanded")).toBe("false")
    expect($("input").getAttribute("autocomplete")).toBe("off")
    expect($("list").getAttribute("role")).toBe("listbox")
    expect($("city-Paris").getAttribute("role")).toBe("option")
    expect($("city-Paris").getAttribute("aria-selected")).toBe("false")
  })

  it("opens with the results of the server when the user types", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])

    expect($("popup").hidden).toBe(false)
    expect($("input").getAttribute("aria-expanded")).toBe("true")
    expect($("popup").hasAttribute("data-empty")).toBe(false)
    expect($("empty").hidden).toBe(true)
    expect($("popup").getAttribute("data-side")).toBe("bottom")
    expect($("input").value).toBe("pa")
  })

  it("shows the empty target when the server gives no result", async () => {
    await mountField(field())
    await type("zz", [])

    expect($("popup").hidden).toBe(false)
    expect($("popup").hasAttribute("data-empty")).toBe(true)
    expect($("list").hasAttribute("data-empty")).toBe(true)
    expect($("empty").hidden).toBe(false)
  })

  it("moves the highlight with the arrow keys, and keeps the focus in the input", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])

    press("ArrowDown")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Paris")
    expect($("city-Paris").getAttribute("aria-selected")).toBe("true")
    expect($("city-Paris").hasAttribute("data-highlighted")).toBe(true)
    press("ArrowDown")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
    press("ArrowDown")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Paris")
    press("ArrowUp")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
    expect(document.activeElement.id).toBe("input")
  })

  it("keeps the highlight on the same item through the morph of another event", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])
    press("ArrowDown")
    press("ArrowDown")
    respond(field(["Lyon", "Parma"]).replace('id="input"', 'id="input" value="pa"'))
    const done = nextDone()
    $("refresh").click()
    await done

    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
    expect($("city-Parma").hasAttribute("data-highlighted")).toBe(true)
    expect($("city-Lyon").getAttribute("aria-selected")).toBe("false")
  })

  it("resets the highlight when the user types", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])
    press("ArrowDown")
    await type("par", ["Paris", "Parma"])

    expect($("input").getAttribute("aria-activedescendant")).toBe(null)
  })

  it("highlights the first item with autoHighlight", async () => {
    const auto = 'data-phem-combobox-auto-highlight-value="true"'
    await mountField(field([], auto))
    await type("pa", ["Paris", "Parma"], auto)

    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Paris")
  })

  it("chooses the highlighted item with Enter: its handler runs, and the popup closes", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])
    const selected = vi.fn()
    $("combobox").addEventListener("phem-combobox:select", selected)
    press("ArrowDown")
    respond(field(["Paris", "Parma"]))
    const done = nextDone()

    expect(press("Enter").defaultPrevented).toBe(true)
    expect($("popup").hidden).toBe(true)
    expect(selected.mock.calls[0][0].detail.item.id).toBe("city-Paris")
    await done

    const body = JSON.parse(fetch.mock.calls.at(-1)[1].body)
    expect(JSON.stringify(body)).toContain("c1.choose")
    expect($("popup").hidden).toBe(true)
  })

  it("lets Enter submit the form when no item is highlighted", async () => {
    await mountField(field())
    await type("pa", ["Paris"])

    expect(press("Enter").defaultPrevented).toBe(false)
  })

  it("closes on Escape, and clears the input on a second Escape", async () => {
    await mountField(field())
    await type("pa", ["Paris"])
    const input = vi.fn()
    $("input").addEventListener("input", input)

    press("Escape")
    expect($("popup").hidden).toBe(true)
    expect($("input").value).toBe("pa")
    press("Escape")
    expect($("input").value).toBe("")
    expect(input).toHaveBeenCalledTimes(1)
  })

  it("clears the input with the clear target", async () => {
    await mountField(field())
    $("input").value = "pa"
    $("clear").click()

    expect($("input").value).toBe("")
    expect(document.activeElement.id).toBe("input")
  })

  it("closes on a click outside and when the focus goes out", async () => {
    await mountField(field())
    await type("pa", ["Paris"])
    $("outside").click()
    expect($("popup").hidden).toBe(true)

    await type("pa", ["Paris"])
    $("outside").focus()
    expect($("popup").hidden).toBe(true)
  })

  it("keeps the focus in the input on a press on an item, and highlights an item under the pointer", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])
    const down = new PointerEvent("pointerdown", { bubbles: true, cancelable: true })
    $("city-Parma").dispatchEvent(down)
    $("city-Parma").dispatchEvent(new PointerEvent("pointerover", { bubbles: true }))

    expect(down.defaultPrevented).toBe(true)
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
  })
})
