import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemComboboxController from "../dist/phem_combobox_controller.js"
import { click, hover, mount, nextDone, respond, stubElementReflection } from "./support.js"

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
    // After the last item, the highlight goes back to the input, then to the first item.
    press("ArrowDown")
    expect($("input").hasAttribute("aria-activedescendant")).toBe(false)
    press("ArrowDown")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Paris")
    press("ArrowUp")
    expect($("input").hasAttribute("aria-activedescendant")).toBe(false)
    press("ArrowUp")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
    expect(document.activeElement.id).toBe("input")
  })

  it("stays on the last item with loopFocus false", async () => {
    await mountField(field(["Paris", "Parma"], `data-phem-combobox-loop-focus-value="false"`))
    press("ArrowDown")
    press("ArrowDown")
    press("ArrowDown")

    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
  })

  it("shows the clear target only when the input has a text", async () => {
    await mountField(field())
    expect($("clear").hidden).toBe(true)

    await type("pa", ["Paris", "Parma"])
    expect($("clear").hidden).toBe(false)
    expect($("clear").hasAttribute("data-visible")).toBe(true)
  })

  it("opens on a click on the input with the openOnInputClick value", async () => {
    await mountField(field(["Paris"], `data-phem-combobox-open-on-input-click-value="true"`))
    $("input").click()

    expect($("popup").hidden).toBe(false)
  })

  it("does not open on a click on the input by default", async () => {
    await mountField(field(["Paris"]))
    $("input").click()

    expect($("popup").hidden).toBe(true)
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

  it("gives no id to a list and items with no id, and links them with element references, also after the morph", async () => {
    const restore = stubElementReflection()
    try {
      const plain = (cities) => field(cities).replace('id="list" ', "").replaceAll(/id="city-\w+" /g, "")
      const item = (name) => [...document.querySelectorAll("[data-phem-combobox-target=item]")].find((el) => el.textContent === name)
      await mountField(plain([]))
      respond(plain(["Paris", "Parma"]).replace('id="input"', 'id="input" value="pa"'))
      let done = nextDone()
      $("input").focus()
      $("input").value = "pa"
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      await done

      const list = document.querySelector("[data-phem-combobox-target=list]")
      expect(list.id).toBe("")
      expect($("input").ariaControlsElements).toEqual([list])

      press("ArrowDown")
      press("ArrowDown")
      expect(item("Parma").id).toBe("")
      expect($("input").ariaActiveDescendantElement).toBe(item("Parma"))

      respond(plain(["Paris", "Parma"]).replace('id="input"', 'id="input" value="pa"'))
      done = nextDone()
      $("refresh").click()
      await done

      expect(item("Parma").hasAttribute("data-highlighted")).toBe(true)
      expect($("input").ariaActiveDescendantElement).toBe(item("Parma"))
      expect(document.querySelectorAll("[data-phem-combobox-target=item][id]").length).toBe(0)

      press("Escape")
      expect($("input").ariaActiveDescendantElement).toBe(null)
    } finally {
      restore()
    }
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

  it("goes from the first item to the last one with autoHighlight", async () => {
    const auto = 'data-phem-combobox-auto-highlight-value="true"'
    await mountField(field([], auto))
    await type("pa", ["Paris", "Parma"], auto)
    press("ArrowUp")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")

    press("ArrowDown")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Paris")
  })

  it("places the popup against the inputGroup target", async () => {
    await mountField(field(["Paris"]).replace(`<input id="input"`, `<div id="group" data-phem-combobox-target="inputGroup"><input id="input"`).replace(`<button id="clear"`, `</div><button id="clear"`))
    $("group").getBoundingClientRect = () => ({ left: 10, top: 20, right: 310, bottom: 60, width: 300, height: 40, x: 10, y: 20 })
    press("ArrowDown")

    expect($("popup").style.getPropertyValue("--anchor-width")).toBe("300px")
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
    hover($("city-Parma"))

    expect(down.defaultPrevented).toBe(true)
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
  })

  it("does not scroll the list for an item that the pointer highlights", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])
    const scroll = vi.fn()
    Element.prototype.scrollIntoView = scroll
    try {
      hover($("city-Parma"))

      expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")
      expect(scroll).not.toHaveBeenCalled()
    } finally {
      delete Element.prototype.scrollIntoView
    }
  })

  it("does not highlight an item that comes under a pointer that does not move", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])
    $("city-Parma").dispatchEvent(new PointerEvent("pointerover", { bubbles: true, pointerType: "mouse" }))

    expect($("input").getAttribute("aria-activedescendant")).not.toBe("city-Parma")
  })

  describe("a choice of more than one value", () => {
    // A tag field: "a" and "b" are chosen, and the list has "a", "b" and "c". The remove button of
    // a chip and a click on an item log their tag.
    function tags(chosen = ["a", "b"]) {
      const chip = (t) => `<span id="chip-${t}" data-phem-combobox-target="chip">${t}<button id="remove-${t}" data-phem-combobox-target="chipRemove" aria-label="Remove ${t}">×</button></span>`
      const item = (t) => `<li id="tag-${t}" data-phem-combobox-target="item" aria-selected="${chosen.includes(t)}">${t}</li>`

      return `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-multiple-value="true">
          ${chosen.map(chip).join("")}
          <input id="input" data-phem-combobox-target="input" aria-label="Tags" />
          <div id="popup" data-phem-combobox-target="popup" hidden>
            <ul id="list" data-phem-combobox-target="list">${["a", "b", "c"].map(item).join("")}</ul>
          </div>
        </div>`
    }

    function key(el, key) {
      const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true })
      el.dispatchEvent(event)

      return event
    }

    function log() {
      const removed = []
      for (const el of document.querySelectorAll("[data-phem-combobox-target=chipRemove]")) el.addEventListener("click", () => removed.push(el.id))

      return removed
    }

    it("keeps the choice of the server in aria-selected, and the highlight in data-highlighted", async () => {
      await mount(tags())
      $("input").focus()
      press("ArrowDown")

      expect($("list").getAttribute("aria-multiselectable")).toBe("true")
      expect($("tag-a").getAttribute("aria-selected")).toBe("true")
      expect($("tag-a").hasAttribute("data-selected")).toBe(true)
      expect($("tag-a").hasAttribute("data-highlighted")).toBe(true)
      expect($("tag-c").getAttribute("aria-selected")).toBe("false")
      expect($("tag-c").hasAttribute("data-selected")).toBe(false)

      press("ArrowDown")
      press("ArrowDown")
      expect($("tag-a").getAttribute("aria-selected")).toBe("true")
      expect($("tag-c").hasAttribute("data-highlighted")).toBe(true)
      expect($("input").getAttribute("aria-activedescendant")).toBe("tag-c")
    })

    it("keeps the popup open and the highlight after a choice", async () => {
      await mount(tags())
      const selected = vi.fn()
      $("combobox").addEventListener("phem-combobox:select", selected)
      $("input").focus()
      press("ArrowDown")
      press("ArrowDown")
      press("ArrowDown")
      press("Enter")

      expect(selected).toHaveBeenCalledTimes(1)
      expect($("popup").hidden).toBe(false)
      expect($("tag-c").hasAttribute("data-highlighted")).toBe(true)
      expect(document.activeElement.id).toBe("input")
    })

    it("moves between the chips and the input with the arrow keys", async () => {
      await mount(tags())
      $("input").focus()

      expect($("chip-a").tabIndex).toBe(-1)
      expect($("remove-a").tabIndex).toBe(-1)

      key($("input"), "ArrowLeft")
      expect(document.activeElement.id).toBe("chip-b")
      key($("chip-b"), "ArrowLeft")
      expect(document.activeElement.id).toBe("chip-a")
      key($("chip-a"), "ArrowLeft")
      expect(document.activeElement.id).toBe("chip-a")
      key($("chip-a"), "ArrowRight")
      key($("chip-b"), "ArrowRight")
      expect(document.activeElement.id).toBe("input")
    })

    it("goes to the chips only from the start of the input", async () => {
      await mount(tags())
      $("input").focus()
      $("input").value = "ab"
      $("input").setSelectionRange(2, 2)

      const event = key($("input"), "ArrowLeft")
      expect(event.defaultPrevented).toBe(false)
      expect(document.activeElement.id).toBe("input")
    })

    it("removes a chip with Backspace and Delete", async () => {
      await mount(tags(["a", "b", "c"]))
      const removed = log()

      $("chip-b").focus()
      key($("chip-b"), "Backspace")
      expect(removed).toEqual(["remove-b"])
      expect(document.activeElement.id).toBe("chip-a")

      key($("chip-a"), "Delete")
      expect(removed).toEqual(["remove-b", "remove-a"])
      expect(document.activeElement.id).toBe("chip-b")
    })

    it("removes the last chip with Backspace in an empty input", async () => {
      await mount(tags())
      const removed = log()
      $("input").focus()
      key($("input"), "Backspace")
      expect(removed).toEqual(["remove-b"])

      $("input").value = "x"
      const event = key($("input"), "Backspace")
      expect(event.defaultPrevented).toBe(false)
      expect(removed).toEqual(["remove-b"])
    })

    it("keeps the focus in the input on a press on the remove button of a chip", async () => {
      await mount(tags())
      const event = new PointerEvent("pointerdown", { bubbles: true, cancelable: true })
      $("remove-a").dispatchEvent(event)

      expect(event.defaultPrevented).toBe(true)
    })
  })

  describe("with a client filter", () => {
    // A list that the server renders one time: the browser filters it.
    function fruits(attributes = 'data-phem-combobox-filter-value="contains"') {
      const item = (f) => `<li id="fruit-${f.toLowerCase()}" data-phem-combobox-target="item">${f}</li>`

      return `<div id="combobox" data-controller="phem-combobox" ${attributes}>
          <input id="input" data-phem-combobox-target="input" aria-label="Fruit" />
          <div id="popup" data-phem-combobox-target="popup" hidden>
            <ul id="list" data-phem-combobox-target="list">
              <li id="red" data-phem-combobox-target="group"><ul>${["Apple", "Cherry"].map(item).join("")}</ul></li>
              <li id="yellow" data-phem-combobox-target="group"><ul>${["Banana", "Lemon", "Éclair"].map(item).join("")}</ul></li>
            </ul>
            <p id="empty" data-phem-combobox-target="empty">No fruit.</p>
          </div>
          <button id="refresh" data-phem-on-click="c1.refresh">Refresh</button>
        </div>`
    }

    function typeText(text) {
      $("input").focus()
      $("input").value = text
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
    }

    const visible = () => [...document.querySelectorAll("[data-phem-combobox-target=item]")].filter((el) => !el.hidden).map((el) => el.id)

    it("hides the items that do not contain the text, with no case and no accents", async () => {
      await mount(fruits())
      typeText("E")

      expect(visible()).toEqual(["fruit-apple", "fruit-cherry", "fruit-lemon", "fruit-éclair"])
      expect($("popup").hidden).toBe(false)

      typeText("ecl")
      expect(visible()).toEqual(["fruit-éclair"])
      expect($("red").hidden).toBe(true)
      expect($("yellow").hidden).toBe(false)

      typeText("zz")
      expect(visible()).toEqual([])
      expect($("popup").hasAttribute("data-empty")).toBe(true)
      expect($("empty").hidden).toBe(false)

      typeText("")
      expect(visible()).toHaveLength(5)
      expect($("red").hidden).toBe(false)
    })

    it("matches the start of the label with starts-with, and shows the first matches with limit", async () => {
      await mount(fruits('data-phem-combobox-filter-value="starts-with" data-phem-combobox-limit-value="1"'))
      typeText("e")

      expect(visible()).toEqual(["fruit-éclair"])

      typeText("")
      expect(visible()).toEqual(["fruit-apple"])
    })

    it("moves the highlight over the visible items only", async () => {
      await mount(fruits())
      typeText("an")
      press("ArrowDown")

      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-banana")

      // One visible item: the next key goes back to the input, then to the item again.
      press("ArrowDown")
      expect($("input").hasAttribute("aria-activedescendant")).toBe(false)
      press("ArrowDown")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-banana")
    })

    describe("with the single selection mode", () => {
      const single = 'data-phem-combobox-filter-value="contains" data-phem-combobox-selection-mode-value="single"'
      const page = () => fruits(single).replace(`<input id="input"`, `<input type="hidden" id="value" name="fruit" data-phem-combobox-target="value" /><button id="clear" data-phem-combobox-target="clear">Clear</button><input id="input"`).replaceAll(`data-phem-combobox-target="item">`, `data-phem-combobox-target="item" data-value="x">`)

      it("selects the item of a click, and gives its value to the value target", async () => {
        await mount(page())
        const changes = []
        $("combobox").addEventListener("phem-combobox:change", (event) => changes.push(event.detail.item?.id ?? null))
        expect($("clear").hidden).toBe(true)

        typeText("che")
        $("fruit-cherry").click()

        expect($("input").value).toBe("Cherry")
        expect($("fruit-cherry").hasAttribute("data-selected")).toBe(true)
        expect($("fruit-cherry").getAttribute("aria-selected")).toBe("true")
        expect($("fruit-apple").getAttribute("aria-selected")).toBe("false")
        expect($("value").value).toBe("x")
        expect($("clear").hidden).toBe(false)
        expect(changes).toEqual(["fruit-cherry"])
      })

      it("shows all the items when the popup opens with the label of the selection", async () => {
        await mount(page())
        typeText("che")
        $("fruit-cherry").click()
        press("ArrowDown")

        expect(visible()).toHaveLength(5)

        // A new text filters again.
        typeText("an")
        expect(visible()).toEqual(["fruit-banana"])
      })

      it("gives the input the label of the selection again when the popup closes", async () => {
        await mount(page())
        typeText("che")
        $("fruit-cherry").click()
        typeText("zzz")
        $("outside")?.focus()
        $("input").dispatchEvent(new FocusEvent("focusout", { bubbles: true, relatedTarget: document.body }))

        expect($("input").value).toBe("Cherry")
      })

      it("removes the text with no selection when the popup closes", async () => {
        await mount(page())
        typeText("zzz")
        $("input").dispatchEvent(new FocusEvent("focusout", { bubbles: true, relatedTarget: document.body }))

        expect($("input").value).toBe("")
      })

      it("removes the selection with the clear target", async () => {
        await mount(page())
        typeText("che")
        $("fruit-cherry").click()
        $("clear").click()

        expect($("input").value).toBe("")
        expect($("fruit-cherry").hasAttribute("data-selected")).toBe(false)
        expect($("value").value).toBe("")
        expect($("clear").hidden).toBe(true)
      })

      it("takes a new selection of the server render, and no selection after a reset", async () => {
        await mount(page())
        typeText("che")
        $("fruit-cherry").click()

        respond(page().replace(`id="fruit-lemon"`, `id="fruit-lemon" data-selected`))
        let done = nextDone()
        click("#refresh")
        await done
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect($("fruit-lemon").hasAttribute("data-selected")).toBe(true)
        expect($("fruit-cherry").hasAttribute("data-selected")).toBe(false)
        expect($("input").value).toBe("Lemon")
        expect($("value").value).toBe("x")

        // The server render has no selected item now: a reset.
        respond(page())
        done = nextDone()
        click("#refresh")
        await done
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect($("fruit-lemon").hasAttribute("data-selected")).toBe(false)
        expect($("input").value).toBe("")
        expect($("value").value).toBe("")
        expect($("clear").hidden).toBe(true)
      })

      it("has no selection when the selected item leaves the page", async () => {
        await mount(page())
        typeText("che")
        $("fruit-cherry").click()
        $("fruit-cherry").remove()
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect($("value").value).toBe("")
        expect($("clear").hidden).toBe(true)
      })

      it("takes the first selection from the server render, and keeps the selection after a morph", async () => {
        const selected = page().replace(`id="fruit-lemon"`, `id="fruit-lemon" data-selected`)
        await mount(selected)
        expect($("fruit-lemon").getAttribute("aria-selected")).toBe("true")

        typeText("che")
        $("fruit-cherry").click()
        respond(selected)
        const done = nextDone()
        click("#refresh")
        await done

        expect($("fruit-cherry").hasAttribute("data-selected")).toBe(true)
        expect($("fruit-lemon").hasAttribute("data-selected")).toBe(false)
      })
    })

    it("writes the label of the chosen item in the input, and sends its events", async () => {
      await mount(fruits())
      const events = []
      $("input").addEventListener("input", () => events.push("input"))
      $("input").addEventListener("change", () => events.push("change"))
      typeText("che")
      events.length = 0
      press("ArrowDown")
      press("Enter")

      expect($("input").value).toBe("Cherry")
      expect(events).toEqual(["input", "change"])
      expect($("popup").hidden).toBe(true)
      expect($("input").getAttribute("aria-expanded")).toBe("false")
    })

    it("keeps the filter through the morph of another event", async () => {
      await mount(fruits())
      typeText("lem")
      respond(fruits())
      const done = nextDone()
      click("#refresh")
      await done
      await Promise.resolve()

      expect(visible()).toEqual(["fruit-lemon"])
      expect($("yellow").hidden).toBe(false)
      expect($("red").hidden).toBe(true)
    })

    it("writes the label of the highlighted item in the input with the both mode, and gives back the text on Escape", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-mode-value="both"'))
      typeText("a")

      expect($("input").getAttribute("aria-autocomplete")).toBe("both")

      press("ArrowDown")
      expect($("input").value).toBe("Apple")
      expect(visible()).toEqual(["fruit-apple", "fruit-banana", "fruit-éclair"])

      press("ArrowDown")
      expect($("input").value).toBe("Banana")

      press("Escape")
      expect($("input").value).toBe("a")
      expect($("popup").hidden).toBe(true)
    })

    it("sends the text of the user, not the inline completion, for a bound input", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-mode-value="both"').replace('<input id="input"', '<input id="input" data-phem-bind="c1.query" value=""'))
      typeText("a")
      press("ArrowDown")
      expect($("input").value).toBe("Apple")

      respond(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-mode-value="both"').replace('<input id="input"', '<input id="input" data-phem-bind="c1.query" value="a"'))
      const done = nextDone()
      click("#refresh")
      await done

      expect(JSON.parse(fetch.mock.calls[0][1].body).bound).toEqual({ "c1.query": "a" })
    })

    it("does not filter with the inline and none modes", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-mode-value="inline"'))
      typeText("zz")

      expect(visible()).toHaveLength(5)

      press("ArrowDown")
      expect($("input").value).toBe("Apple")
    })

    it("does not change the input on a highlight with the none mode, and writes the label on a choice", async () => {
      await mount(fruits('data-phem-combobox-mode-value="none"'))
      typeText("x")
      press("ArrowDown")

      expect($("input").getAttribute("aria-autocomplete")).toBe("none")
      expect($("input").value).toBe("x")

      $("fruit-lemon").click()
      expect($("input").value).toBe("Lemon")
    })
  })

  it("gives aria-busy to the popup while the input is busy", async () => {
    await mountField(field(["Paris"]))
    $("input").setAttribute("aria-busy", "true")
    await Promise.resolve()

    expect($("popup").getAttribute("aria-busy")).toBe("true")

    $("input").removeAttribute("aria-busy")
    await Promise.resolve()
    expect($("popup").hasAttribute("aria-busy")).toBe(false)
  })
})
