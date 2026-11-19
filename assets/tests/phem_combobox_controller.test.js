import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemComboboxController from "../dist/phem_combobox_controller.js"
import { answer as answerOf, click, hover, mount, nextDone, respond, stubElementReflection } from "./support.js"

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
    // The Empty part stays in the page as a live region, with no content.
    expect($("empty").hidden).toBe(false)
    expect($("empty").getAttribute("role")).toBe("status")
    expect($("empty").getAttribute("aria-live")).toBe("polite")
    expect($("empty").getAttribute("aria-atomic")).toBe("true")
    expect($("empty").childNodes.length).toBe(0)
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
    expect($("empty").textContent).toBe("No city.")
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
    expect($("popup").hidden).toBe(true)
    expect($("input").getAttribute("aria-expanded")).toBe("false")
  })

  it("does not open a closed popup on a clear, also after the answer of the server", async () => {
    await mountField(field())
    await type("pa", ["Paris"])
    press("Escape")
    expect($("popup").hidden).toBe(true)

    respond(field(["Paris", "Parma"]))
    const done = nextDone()
    $("clear").click()
    await done

    expect($("input").value).toBe("")
    expect($("popup").hidden).toBe(true)
    expect($("input").getAttribute("aria-expanded")).toBe("false")
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

  it("removes the highlight when the pointer leaves the items", async () => {
    await mountField(field())
    await type("pa", ["Paris", "Parma"])
    hover($("city-Parma"))
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Parma")

    $("city-Parma").dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: $("outside") }))

    expect($("input").hasAttribute("aria-activedescendant")).toBe(false)
    expect($("city-Parma").hasAttribute("data-highlighted")).toBe(false)
    expect($("popup").hidden).toBe(false)
  })

  it("opens the popup with no highlight on the first arrow key", async () => {
    await mountField(field(["Paris", "Parma"]))
    $("input").focus()
    press("ArrowDown")

    expect($("popup").hidden).toBe(false)
    expect($("input").getAttribute("aria-expanded")).toBe("true")
    expect($("input").hasAttribute("aria-activedescendant")).toBe(false)
    expect(document.querySelector("[data-highlighted]")).toBe(null)

    press("ArrowDown")
    expect($("input").getAttribute("aria-activedescendant")).toBe("city-Paris")
  })

  it("gives the popup state to the input, the trigger and the input group, also after the morph", async () => {
    const parts = (cities) =>
      field(cities)
        .replace(`<input id="input"`, `<div id="group" data-phem-combobox-target="inputGroup"><input id="input"`)
        .replace(`<button id="clear"`, `<button id="trigger" type="button" data-phem-combobox-target="trigger">Open</button></div><button id="clear"`)
    await mountField(parts(["Paris", "Parma"]))

    expect($("input").getAttribute("aria-haspopup")).toBe("listbox")
    expect($("input").hasAttribute("data-list-empty")).toBe(false)
    expect($("trigger").getAttribute("aria-expanded")).toBe("false")
    expect($("trigger").getAttribute("aria-haspopup")).toBe("listbox")
    expect($("trigger").hasAttribute("aria-controls")).toBe(false)
    expect($("trigger").hasAttribute("data-popup-open")).toBe(false)
    expect($("group").getAttribute("role")).toBe("group")
    expect($("group").hasAttribute("data-popup-open")).toBe(false)

    $("trigger").click()
    expect($("popup").hidden).toBe(false)
    expect($("trigger").getAttribute("aria-expanded")).toBe("true")
    expect($("trigger").getAttribute("aria-controls")).toBe("list")
    expect($("trigger").hasAttribute("data-popup-open")).toBe(true)
    expect($("group").hasAttribute("data-popup-open")).toBe(true)

    respond(parts(["Paris", "Parma"]))
    const done = nextDone()
    $("refresh").click()
    await done

    expect($("popup").hidden).toBe(false)
    expect($("input").getAttribute("aria-haspopup")).toBe("listbox")
    expect($("trigger").getAttribute("aria-expanded")).toBe("true")
    expect($("trigger").getAttribute("aria-haspopup")).toBe("listbox")
    expect($("trigger").getAttribute("aria-controls")).toBe("list")
    expect($("trigger").hasAttribute("data-popup-open")).toBe(true)
    expect($("group").getAttribute("role")).toBe("group")
    expect($("group").hasAttribute("data-popup-open")).toBe(true)

    $("trigger").click()
    expect($("trigger").getAttribute("aria-expanded")).toBe("false")
    expect($("trigger").hasAttribute("aria-controls")).toBe(false)
    expect($("trigger").hasAttribute("data-popup-open")).toBe(false)
    expect($("group").hasAttribute("data-popup-open")).toBe(false)
  })

  it("gives data-list-empty to the input when the list has no item", async () => {
    await mountField(field())

    expect($("input").hasAttribute("data-list-empty")).toBe(true)
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

    it("opens with the highlight on the first chosen item", async () => {
      await mount(tags(["b", "c"]))
      $("input").focus()
      press("ArrowDown")

      expect($("tag-b").hasAttribute("data-highlighted")).toBe(true)
      expect($("input").getAttribute("aria-activedescendant")).toBe("tag-b")

      // An open after the user typed has no highlight.
      press("Escape")
      $("input").value = "x"
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      expect($("popup").hidden).toBe(false)
      expect(document.querySelector("[data-highlighted]")).toBe(null)
    })

    it("closes the popup after a choice while the input has a text, and clears the text after the click", async () => {
      await mount(tags())
      $("input").focus()
      $("input").value = "c"
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      expect($("popup").hidden).toBe(false)

      const inputs = vi.fn()
      $("input").addEventListener("input", inputs)
      $("tag-c").click()

      // The click goes to the server first: its item is still in the list.
      expect($("popup").hidden).toBe(true)
      expect($("input").value).toBe("c")
      expect(inputs).not.toHaveBeenCalled()
      expect(document.activeElement.id).toBe("input")

      await new Promise((resolve) => setTimeout(resolve, 0))
      expect($("input").value).toBe("")
      expect(inputs).toHaveBeenCalledTimes(1)
      expect($("popup").hidden).toBe(true)
    })

    it("keeps the chosen items that a server render does not have while their chip shows", async () => {
      // The server gives the chips of the chosen tags, and the tags that match the text.
      function chosen(chips, results) {
        const chip = (t) => `<span id="chip-${t}" data-value="${t}" data-phem-combobox-target="chip">${t}</span>`
        const item = (t) => `<li id="tag-${t}" data-value="${t}" data-phem-combobox-target="item" aria-selected="${chips.includes(t)}">${t}</li>`

        return `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-multiple-value="true">
            ${chips.map(chip).join("")}
            <input id="input" data-phem-combobox-target="input" data-phem-on-input="c1.search" aria-label="Tags" />
            <div id="popup" data-phem-combobox-target="popup" hidden>
              <ul id="list" data-phem-combobox-target="list">${results.map(item).join("")}</ul>
            </div>
          </div>`
      }

      const ids = () => [...$("list").children].map((el) => el.id)
      async function search(text, markup) {
        respond(markup)
        const done = nextDone()
        $("input").focus()
        $("input").value = text
        $("input").dispatchEvent(new Event("input", { bubbles: true }))
        await done
        await new Promise((resolve) => setTimeout(resolve, 0))
      }

      await mountField(chosen(["a", "b"], ["a", "b"]))
      await search("c", chosen(["a", "b"], ["c"]))
      expect(ids()).toEqual(["tag-c", "tag-a", "tag-b"])
      expect($("tag-a").getAttribute("aria-selected")).toBe("true")
      expect($("tag-c").getAttribute("aria-selected")).toBe("false")

      // The chip of "b" goes: its item leaves the list.
      await search("cc", chosen(["a"], ["c"]))
      expect(ids()).toEqual(["tag-c", "tag-a"])

      // The user deletes all the text: the popup stays open with the chosen item, and it gets the
      // highlight, as at the open.
      await search("", chosen(["a"], []))
      expect(ids()).toEqual(["tag-a"])
      expect($("popup").hidden).toBe(false)
      expect($("tag-a").hasAttribute("data-highlighted")).toBe(true)
      expect($("input").getAttribute("aria-activedescendant")).toBe("tag-a")
    })

    it("clears the text when the popup closes, with its input event", async () => {
      await mount(tags())
      $("input").focus()
      $("input").value = "zz"
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      expect($("popup").hidden).toBe(false)

      const inputs = vi.fn()
      $("input").addEventListener("input", inputs)
      press("Escape")

      expect($("popup").hidden).toBe(true)
      expect($("input").value).toBe("")
      expect(inputs).toHaveBeenCalledTimes(1)
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

    it("removes all the chips with Escape on a closed popup, as in Base UI", async () => {
      await mount(tags())
      const removed = log()
      $("input").focus()

      const event = press("Escape")
      expect(event.defaultPrevented).toBe(true)
      expect(removed).toEqual(["remove-b", "remove-a"])
      expect($("popup").hidden).toBe(true)
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

    it("keeps the focus in the input on a press in the popup, out of the items", async () => {
      await mount(tags())
      const popup = document.querySelector('[data-phem-combobox-target="popup"]')
      const event = new PointerEvent("pointerdown", { bubbles: true, cancelable: true })
      popup.dispatchEvent(event)

      expect(event.defaultPrevented).toBe(true)
    })

    it("gives the focus to a field of the application in the popup", async () => {
      await mount(tags())
      const popup = document.querySelector('[data-phem-combobox-target="popup"]')
      popup.insertAdjacentHTML("beforeend", `<input id="other"><textarea id="note"></textarea>`)

      for (const id of ["other", "note"]) {
        const event = new PointerEvent("pointerdown", { bubbles: true, cancelable: true })
        $(id).dispatchEvent(event)

        expect(event.defaultPrevented).toBe(false)
      }
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

    describe("with the input in the popup", () => {
      const markup = `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-filter-value="contains" data-phem-combobox-selection-mode-value="single">
          <button id="trigger" type="button" data-phem-combobox-target="trigger"><span id="value" data-phem-combobox-target="value" placeholder="Select country"></span></button>
          <div id="popup" data-phem-combobox-target="popup" hidden>
            <input id="input" data-phem-combobox-target="input" aria-label="Country" />
            <ul id="list" data-phem-combobox-target="list">
              <li id="fr" data-value="fr" data-phem-combobox-target="item">France</li>
              <li id="de" data-value="de" data-phem-combobox-target="item">Germany</li>
            </ul>
          </div>
        </div>`

      it("makes the popup a dialog that the trigger opens", async () => {
        await mount(markup)
        expect($("popup").getAttribute("role")).toBe("dialog")
        expect($("list").getAttribute("role")).toBe("listbox")
        expect($("trigger").getAttribute("aria-haspopup")).toBe("dialog")
        expect($("trigger").hasAttribute("aria-controls")).toBe(false)
        expect($("input").getAttribute("aria-haspopup")).toBe("listbox")

        click("#trigger")
        expect($("trigger").getAttribute("aria-controls")).toBe("popup")
        expect($("input").getAttribute("aria-controls")).toBe("list")
      })

      it("shows the placeholder, and opens with the focus in the input", async () => {
        await mount(markup)
        expect($("value").textContent).toBe("Select country")
        expect($("value").hasAttribute("data-placeholder")).toBe(true)
        expect($("trigger").hasAttribute("data-placeholder")).toBe(true)

        click("#trigger")
        expect($("popup").hidden).toBe(false)
        expect(document.activeElement).toBe($("input"))
      })

      it("shows the label of the choice on the trigger, and gives the focus back to it", async () => {
        await mount(markup)
        click("#trigger")
        typeText("ger")
        click("#de")

        expect($("popup").hidden).toBe(true)
        expect($("value").textContent).toBe("Germany")
        expect($("value").hasAttribute("data-placeholder")).toBe(false)
        expect($("trigger").hasAttribute("data-placeholder")).toBe(false)
        expect(document.activeElement).toBe($("trigger"))

        // The input is a search: it has no text at the next open, and all the items show.
        click("#trigger")
        expect($("input").value).toBe("")
        expect($("fr").hidden).toBe(false)
      })

      it("gives the focus back to the trigger on Escape", async () => {
        await mount(markup)
        click("#trigger")
        $("input").dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true }))

        expect($("popup").hidden).toBe(true)
        expect(document.activeElement).toBe($("trigger"))
      })
    })

    it("names a group with its group label", async () => {
      await mount(fruits().replace('<li id="red" data-phem-combobox-target="group"><ul>', '<li id="red" data-phem-combobox-target="group"><div id="red-label" data-phem-combobox-target="groupLabel">Red</div><ul>'))

      expect($("red").getAttribute("role")).toBe("group")
      expect($("red").getAttribute("aria-labelledby")).toBe("red-label")
    })

    it("keeps the empty target in the page, with its content only when no item matches", async () => {
      await mount(fruits())
      expect($("empty").getAttribute("role")).toBe("status")
      expect($("empty").hidden).toBe(false)
      expect($("empty").childNodes.length).toBe(0)

      typeText("zz")
      expect($("empty").textContent).toBe("No fruit.")

      typeText("a")
      expect($("empty").childNodes.length).toBe(0)

      // The morph gets the content of the server render, and the controller takes it out again.
      respond(fruits().replace("No fruit.", "No fruit found."))
      const done = nextDone()
      $("refresh").click()
      await done
      expect($("empty").childNodes.length).toBe(0)

      typeText("zz")
      expect($("empty").textContent).toBe("No fruit found.")
    })

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

    it("does not open a closed popup on a click on the clear target", async () => {
      await mount(fruits().replace(`<input id="input"`, `<button id="clear" data-phem-combobox-target="clear">Clear</button><input id="input"`))
      typeText("an")
      press("Escape")
      expect($("popup").hidden).toBe(true)

      $("clear").click()

      expect($("input").value).toBe("")
      expect($("popup").hidden).toBe(true)
      expect($("input").getAttribute("aria-expanded")).toBe("false")
    })

    it("closes the popup on Enter with no highlighted item, and lets the form submit", async () => {
      await mount(fruits())
      typeText("an")
      expect($("popup").hidden).toBe(false)

      expect(press("Enter").defaultPrevented).toBe(false)
      expect($("popup").hidden).toBe(true)
      expect($("input").getAttribute("aria-expanded")).toBe("false")
    })

    it("opens the popup with no highlight on the first arrow key", async () => {
      await mount(fruits())
      $("input").focus()
      press("ArrowDown")

      expect($("popup").hidden).toBe(false)
      expect(document.querySelector("[data-highlighted]")).toBe(null)

      press("ArrowDown")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")
    })

    it("gives back the text of the user when the pointer leaves the items with an inline mode", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-mode-value="inline"'))
      typeText("a")
      press("ArrowDown")
      expect($("input").value).toBe("Apple")

      hover($("fruit-banana"))
      $("fruit-banana").dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: document.body }))

      expect(document.querySelector("[data-highlighted]")).toBe(null)
      expect($("input").value).toBe("a")
    })

    const out = (el) => el.dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: document.body }))

    it("gives back the text of the user when the pointer leaves the items with the both mode", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-mode-value="both"'))
      typeText("a")
      press("ArrowDown")
      expect($("input").value).toBe("Apple")

      hover($("fruit-banana"))
      out($("fruit-banana"))

      expect(document.querySelector("[data-highlighted]")).toBe(null)
      expect($("input").value).toBe("a")
    })

    it("keeps a highlight of the keyboard when the pointer leaves the items", async () => {
      await mount(fruits())
      typeText("a")
      hover($("fruit-apple"))
      press("ArrowDown")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-banana")

      out($("fruit-apple"))

      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-banana")
    })

    it("keeps a highlight of the keyboard on the item that the pointer highlighted before", async () => {
      await mount(fruits())
      typeText("a")
      hover($("fruit-apple"))
      press("ArrowDown")
      press("ArrowUp")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")

      // The keyboard gave the highlight to the item again: it is not a highlight of the pointer.
      out($("fruit-apple"))

      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")
    })

    it("keeps the highlight of autoHighlight when the pointer leaves the items after the user types", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-auto-highlight-value="true"'))
      typeText("a")
      hover($("fruit-banana"))
      typeText("an")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-banana")

      typeText("e")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")
      out($("fruit-banana"))

      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")
    })

    it("opens the popup with no highlight on ArrowUp, and the next ArrowUp goes to the last item", async () => {
      await mount(fruits())
      $("input").focus()
      press("ArrowUp")

      expect($("popup").hidden).toBe(false)
      expect(document.querySelector("[data-highlighted]")).toBe(null)

      press("ArrowUp")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-éclair")
    })

    it("highlights the first item on the arrow key that opens the popup with autoHighlight", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-auto-highlight-value="true"'))
      $("input").focus()
      press("ArrowDown")

      expect($("popup").hidden).toBe(false)
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")
    })

    it("does not keep an old highlight when an arrow key opens the popup again", async () => {
      await mount(fruits().replace(`<div id="popup"`, `<button id="trigger" type="button" data-phem-combobox-target="trigger">Open</button><div id="popup"`))
      typeText("a")
      press("ArrowDown")
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")

      $("trigger").click()
      expect($("popup").hidden).toBe(true)
      press("ArrowDown")

      expect($("popup").hidden).toBe(false)
      expect($("input").hasAttribute("aria-activedescendant")).toBe(false)
      expect(document.querySelector("[data-highlighted]")).toBe(null)
    })

    it("highlights an item on the arrow key that opens a multiple popup with no chip", async () => {
      await mount(fruits('data-phem-combobox-filter-value="contains" data-phem-combobox-multiple-value="true"'))
      $("input").focus()
      press("ArrowDown")

      expect($("popup").hidden).toBe(false)
      expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-apple")
    })

    it("keeps an open popup open on a click on the clear target", async () => {
      await mount(fruits().replace(`<input id="input"`, `<button id="clear" data-phem-combobox-target="clear">Clear</button><input id="input"`))
      typeText("an")
      expect($("popup").hidden).toBe(false)

      $("clear").click()

      expect($("input").value).toBe("")
      expect($("popup").hidden).toBe(false)
    })

    it("does not open a closed popup on the second Escape", async () => {
      await mount(fruits())
      typeText("an")
      press("Escape")
      expect($("popup").hidden).toBe(true)

      press("Escape")

      expect($("input").value).toBe("")
      expect($("popup").hidden).toBe(true)
    })

    it("gives data-list-empty to the input when the filter hides all the items, also after the morph", async () => {
      await mount(fruits())
      typeText("zz")
      expect($("input").hasAttribute("data-list-empty")).toBe(true)

      respond(fruits())
      const done = nextDone()
      $("refresh").click()
      await done

      expect($("input").hasAttribute("data-list-empty")).toBe(true)

      typeText("a")
      expect($("input").hasAttribute("data-list-empty")).toBe(false)
    })

    it("keeps the aria-haspopup and the role that the app gives", async () => {
      const markup = fruits()
        .replace(`<input id="input" data-phem-combobox-target="input"`, `<div id="group" role="toolbar" data-phem-combobox-target="inputGroup"><input id="input" data-phem-combobox-target="input" aria-haspopup="dialog"`)
        .replace(`<div id="popup"`, `<button id="trigger" type="button" aria-haspopup="dialog" data-phem-combobox-target="trigger">Open</button></div><div id="popup"`)
      await mount(markup)

      expect($("input").getAttribute("aria-haspopup")).toBe("dialog")
      expect($("trigger").getAttribute("aria-haspopup")).toBe("dialog")
      expect($("group").getAttribute("role")).toBe("toolbar")
    })

    it("gives the attributes to a trigger that comes after the connect", async () => {
      await mount(fruits())
      const trigger = document.createElement("button")
      trigger.id = "trigger"
      trigger.setAttribute("data-phem-combobox-target", "trigger")
      $("combobox").append(trigger)
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect(trigger.getAttribute("aria-haspopup")).toBe("listbox")
      expect(trigger.getAttribute("aria-expanded")).toBe("false")
    })

    describe("with the single selection mode", () => {
      it("does not open a closed popup on a click on the clear target", async () => {
        await mount(page().replace('id="fruit-cherry"', 'id="fruit-cherry" data-selected'))
        $("input").focus()
        expect($("popup").hidden).toBe(true)

        $("clear").click()

        expect($("input").value).toBe("")
        expect($("value").value).toBe("")
        expect($("popup").hidden).toBe(true)
        expect($("input").getAttribute("aria-expanded")).toBe("false")
      })

      it("closes the popup on Enter with no highlighted item, and shows the label of the selection again", async () => {
        await mount(page().replace('id="fruit-cherry"', 'id="fruit-cherry" data-selected'))
        typeText("an")
        expect($("popup").hidden).toBe(false)
        expect(document.querySelector("[data-highlighted]")).toBe(null)

        expect(press("Enter").defaultPrevented).toBe(false)
        expect($("popup").hidden).toBe(true)
        expect($("input").value).toBe("Cherry")
      })

      const single = 'data-phem-combobox-filter-value="contains" data-phem-combobox-selection-mode-value="single"'
      const page = () => fruits(single).replace(`<input id="input"`, `<input type="hidden" id="value" name="fruit" data-phem-combobox-target="value" /><button id="clear" data-phem-combobox-target="clear">Clear</button><input id="input"`).replaceAll(`data-phem-combobox-target="item">`, `data-phem-combobox-target="item" data-value="x">`)

      it("gives data-placeholder to the input group while no item is selected", async () => {
        await mount(page().replace(`<input id="input"`, `<div id="group" data-phem-combobox-target="inputGroup"><input id="input"`).replace(`aria-label="Fruit" />`, `aria-label="Fruit" /></div>`))
        expect($("group").hasAttribute("data-placeholder")).toBe(true)

        typeText("che")
        $("fruit-cherry").click()
        expect($("group").hasAttribute("data-placeholder")).toBe(false)
      })

      it("gives the input and the value target the selection of the server render", async () => {
        const events = []
        document.addEventListener("input", (event) => events.push(event.type), { once: true })
        await mount(page().replace('id="fruit-cherry"', 'id="fruit-cherry" data-selected'))

        expect($("input").value).toBe("Cherry")
        expect($("value").value).toBe("x")
        expect($("fruit-cherry").getAttribute("aria-selected")).toBe("true")
        expect($("clear").hidden).toBe(false)
        expect(events).toEqual([])
      })

      it("keeps a text and a value that the server render gives with the selection", async () => {
        await mount(page().replace('id="fruit-cherry"', 'id="fruit-cherry" data-selected').replace('<input id="input"', '<input id="input" value="Cerise"').replace('id="value"', 'id="value" value="c"'))

        expect($("input").value).toBe("Cerise")
        expect($("value").value).toBe("c")
      })

      it("opens with the highlight on the selected item, and scrolls to it", async () => {
        await mount(page().replace('id="fruit-cherry"', 'id="fruit-cherry" data-selected'))
        $("fruit-cherry").scrollIntoView = vi.fn()
        $("input").focus()
        press("ArrowDown")

        expect($("popup").hidden).toBe(false)
        expect($("fruit-cherry").hasAttribute("data-highlighted")).toBe(true)
        expect($("input").getAttribute("aria-activedescendant")).toBe("fruit-cherry")
        expect($("fruit-cherry").scrollIntoView).toHaveBeenCalled()

        // The next arrow key moves from the selected item.
        press("ArrowDown")
        expect($("fruit-banana").hasAttribute("data-highlighted")).toBe(true)

        // An open after the user typed has no highlight.
        press("Escape")
        typeText("an")
        expect(document.querySelector("[data-highlighted]")).toBe(null)
      })

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

      it("removes the selection when the user deletes all the text, as in Base UI", async () => {
        await mount(page())
        const changes = []
        $("combobox").addEventListener("phem-combobox:change", (event) => changes.push(event.detail.item?.id ?? null))
        typeText("che")
        $("fruit-cherry").click()

        typeText("")
        expect($("fruit-cherry").hasAttribute("data-selected")).toBe(false)
        expect($("value").value).toBe("")
        expect($("clear").hidden).toBe(true)
        expect(changes).toEqual(["fruit-cherry", null])

        // The input has no label to give back.
        press("Escape")
        expect($("input").value).toBe("")

        // A part of the text keeps the selection.
        typeText("che")
        $("fruit-cherry").click()
        typeText("Cher")
        press("Escape")
        expect($("input").value).toBe("Cherry")
        expect($("value").value).toBe("x")
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

      it("keeps the items and the highlight of the popup that closes, until the next open", async () => {
        await mount(page())
        typeText("e")
        press("ArrowDown")
        press("ArrowDown")
        press("Enter")

        // The input has the label of the choice, and the closing popup has the matches of "e".
        expect($("popup").hasAttribute("data-open")).toBe(false)
        expect($("input").value).toBe("Cherry")
        expect(visible()).toEqual(["fruit-apple", "fruit-cherry", "fruit-lemon", "fruit-éclair"])
        expect($("fruit-cherry").hasAttribute("data-highlighted")).toBe(true)
        expect($("fruit-banana").getAttribute("aria-selected")).toBe("false")

        // Escape after a text with no match: the closing popup has no item.
        typeText("zzz")
        press("Escape")
        expect($("popup").hasAttribute("data-open")).toBe(false)
        expect($("input").value).toBe("Cherry")
        expect(visible()).toEqual([])

        // The next open filters again.
        press("ArrowDown")
        expect(visible()).toHaveLength(5)
        expect($("fruit-cherry").hasAttribute("data-highlighted")).toBe(true)
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

    describe("with the multiple selection mode", () => {
      // The languages: the server renders a chip and an item for each one, one time.
      function languages({ chosen = [] } = {}) {
        const names = { js: "JavaScript", ts: "TypeScript", go: "Go" }
        const chip = (v) => `<span id="chip-${v}" data-phem-combobox-target="chip" data-value="${v}"${chosen.includes(v) ? "" : " hidden"}>${names[v]}<button id="remove-${v}" data-phem-combobox-target="chipRemove" aria-label="Remove ${names[v]}">×</button></span>`
        const item = (v) => `<li id="lang-${v}" data-phem-combobox-target="item" data-value="${v}"${chosen.includes(v) ? " data-selected" : ""}>${names[v]}</li>`

        return `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-filter-value="contains" data-phem-combobox-selection-mode-value="multiple" data-phem-combobox-open-on-input-click-value="true">
            <div id="group" data-phem-combobox-target="inputGroup">
              <div id="chips" data-phem-combobox-target="chips" aria-label="Selected languages">
                ${Object.keys(names).map(chip).join("")}
                <input id="input" data-phem-combobox-target="input" aria-label="Languages" placeholder="e.g. Go" data-phem-combobox-description="{count} selected" />
              </div>
            </div>
            <button id="clear" data-phem-combobox-target="clear">Clear</button>
            <div id="popup" data-phem-combobox-target="popup" hidden>
              <ul id="list" data-phem-combobox-target="list">${Object.keys(names).map(item).join("")}</ul>
            </div>
            <button id="refresh" data-phem-on-click="c1.refresh">Refresh</button>
          </div>`
      }

      const chips = () => [...$("chips").children].filter((el) => el.matches("[data-phem-combobox-target=chip]") && !el.hidden).map((el) => el.id)

      it("removes all the values with Escape on a closed popup, as in Base UI", async () => {
        await mount(languages({ chosen: ["js", "go"] }))
        $("input").focus()
        expect($("clear").hidden).toBe(false)

        press("Escape")
        expect(chips()).toEqual([])
        expect($("lang-js").hasAttribute("data-selected")).toBe(false)
        expect($("clear").hidden).toBe(true)
        expect($("popup").hidden).toBe(true)

        // Nothing to clear: Escape keeps its default action.
        expect(press("Escape").defaultPrevented).toBe(false)
      })

      it("shows the chips of the chosen items in the order of the choices, with no event of the server", async () => {
        await mount(languages())
        const changes = []
        $("combobox").addEventListener("phem-combobox:change", (event) => changes.push(event.detail.value))
        $("input").focus()
        press("ArrowDown")

        $("lang-ts").click()
        $("lang-js").click()

        expect(chips()).toEqual(["chip-ts", "chip-js"])
        expect($("lang-ts").getAttribute("aria-selected")).toBe("true")
        expect($("lang-ts").hasAttribute("data-selected")).toBe(true)
        expect($("lang-go").getAttribute("aria-selected")).toBe("false")
        expect($("list").getAttribute("aria-multiselectable")).toBe("true")
        expect(changes).toEqual([["ts"], ["ts", "js"]])
        expect(fetch).not.toHaveBeenCalled()

        // A second click removes the value.
        $("lang-ts").click()
        expect(chips()).toEqual(["chip-js"])
        expect($("lang-ts").getAttribute("aria-selected")).toBe("false")
      })

      it("keeps the popup open after a choice with an empty input, and closes it after a choice that clears a text", async () => {
        await mount(languages())
        $("input").focus()
        press("ArrowDown")
        $("lang-go").click()
        expect($("popup").hidden).toBe(false)

        typeText("ja")
        press("ArrowDown")
        press("Enter")

        expect(chips()).toEqual(["chip-go", "chip-js"])
        expect($("input").value).toBe("")
        expect($("popup").hidden).toBe(true)
        expect(document.activeElement.id).toBe("input")
      })

      it("removes a value with the remove button of its chip, Backspace and the clear target", async () => {
        await mount(languages({ chosen: ["js", "ts", "go"] }))
        expect(chips()).toEqual(["chip-js", "chip-ts", "chip-go"])

        $("remove-ts").click()
        expect(chips()).toEqual(["chip-js", "chip-go"])
        expect($("lang-ts").hasAttribute("data-selected")).toBe(false)

        $("input").focus()
        press("Backspace")
        expect(chips()).toEqual(["chip-js"])

        // The arrow keys skip the hidden chips.
        press("ArrowLeft")
        expect(document.activeElement.id).toBe("chip-js")

        expect($("clear").hidden).toBe(false)
        $("clear").click()
        expect(chips()).toEqual([])
        expect($("clear").hidden).toBe(true)
      })

      it("shows the placeholder and the label of the chips only with no value, and the number of the values", async () => {
        await mount(languages())
        expect($("input").getAttribute("placeholder")).toBe("e.g. Go")
        expect($("input").hasAttribute("aria-description")).toBe(false)
        expect($("chips").hasAttribute("aria-label")).toBe(false)
        expect($("chips").hasAttribute("role")).toBe(false)
        expect($("group").hasAttribute("data-placeholder")).toBe(true)

        $("lang-go").click()
        $("lang-js").click()
        expect($("input").hasAttribute("placeholder")).toBe(false)
        expect($("input").getAttribute("aria-description")).toBe("2 selected")
        expect($("chips").getAttribute("aria-label")).toBe("Selected languages")
        expect($("chips").getAttribute("role")).toBe("toolbar")
        expect($("group").hasAttribute("data-placeholder")).toBe(false)

        $("remove-go").click()
        $("remove-js").click()
        expect($("input").getAttribute("placeholder")).toBe("e.g. Go")
        expect($("input").hasAttribute("aria-description")).toBe(false)
        expect($("chips").hasAttribute("aria-label")).toBe(false)
        expect($("chips").hasAttribute("role")).toBe(false)
        expect($("group").hasAttribute("data-placeholder")).toBe(true)
      })

      it("gives the focus to the input and opens the popup on a press on the space of the input group", async () => {
        await mount(languages({ chosen: ["js"] }))
        const press = new PointerEvent("pointerdown", { bubbles: true, cancelable: true })
        $("chips").dispatchEvent(press)

        expect(press.defaultPrevented).toBe(true)
        expect(document.activeElement.id).toBe("input")

        $("chips").click()
        expect($("popup").hidden).toBe(false)

        // A press on a chip is not a press on the space.
        const chip = new PointerEvent("pointerdown", { bubbles: true, cancelable: true })
        $("chip-js").dispatchEvent(chip)
        expect(chip.defaultPrevented).toBe(false)
      })

      it("closes the popup when the focus goes to a chip", async () => {
        await mount(languages({ chosen: ["js", "go"] }))
        $("input").focus()
        press("ArrowDown")
        expect($("popup").hidden).toBe(false)

        press("ArrowLeft")

        expect(document.activeElement.id).toBe("chip-go")
        expect($("popup").hidden).toBe(true)
        expect($("input").getAttribute("aria-expanded")).toBe("false")
      })

      it("takes the first values and the changes of the server render, and keeps the values through a morph", async () => {
        await mount(languages({ chosen: ["go"] }))
        expect(chips()).toEqual(["chip-go"])

        $("lang-ts").click()
        respond(languages({ chosen: ["go"] }))
        let done = nextDone()
        click("#refresh")
        await done
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect(chips()).toEqual(["chip-go", "chip-ts"])
        expect($("lang-ts").hasAttribute("data-selected")).toBe(true)
        expect($("input").hasAttribute("placeholder")).toBe(false)

        // The server render chooses JavaScript and no more Go.
        respond(languages({ chosen: ["js"] }))
        done = nextDone()
        click("#refresh")
        await done
        await new Promise((resolve) => setTimeout(resolve, 0))

        expect(chips()).toEqual(["chip-ts", "chip-js"])
        expect($("lang-go").hasAttribute("data-selected")).toBe(false)
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

  describe("the single selection mode with a list of the server", () => {
    // The people that the server gives, the selected person and the text of the input.
    function people(results, { selected = null, text = "" } = {}) {
      const item = ([id, name]) => `<li id="person-${id}" data-value="${id}"${id === selected ? " data-selected" : ""} data-phem-combobox-target="item">${name}</li>`

      return `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-selection-mode-value="single">
          <input id="input" data-phem-combobox-target="input" data-phem-on-input="c1.search" aria-label="Reviewer" value="${text}" />
          <button id="clear" data-phem-combobox-target="clear">Clear</button>
          <input type="hidden" id="value" data-phem-combobox-target="value" value="${selected ?? ""}" />
          <div id="popup" data-phem-combobox-target="popup" hidden>
            <ul id="list" data-phem-combobox-target="list">${results.map(item).join("")}</ul>
          </div>
        </div>
        <button id="outside">Outside</button>`
    }

    const lindsay = ["lindsay", "Lindsay Walton"]
    const tom = ["tom", "Tom Cook"]

    // The user types, and the server answers with the results.
    async function search(text, results, selected = null) {
      respond(people(results, { selected, text }))
      const done = nextDone()
      $("input").focus()
      $("input").value = text
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      await done
    }

    it("selects the item of a click, writes its label in the input and sends the input event", async () => {
      await mountField(people([]))
      await search("o", [lindsay, tom])
      expect($("clear").hidden).toBe(true)

      respond(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      const done = nextDone()
      const calls = fetch.mock.calls.length
      $("person-lindsay").click()

      expect($("input").value).toBe("Lindsay Walton")
      expect($("value").value).toBe("lindsay")
      expect($("person-lindsay").hasAttribute("data-selected")).toBe(true)
      expect($("person-lindsay").getAttribute("aria-selected")).toBe("true")
      expect($("person-tom").getAttribute("aria-selected")).toBe("false")
      expect($("clear").hidden).toBe(false)
      expect($("popup").hidden).toBe(true)

      await done
      expect(fetch.mock.calls.length).toBe(calls + 1)
      // The label of the selection is not a search: the event sends no text.
      const { event } = JSON.parse(fetch.mock.calls.at(-1)[1].body)
      expect(event.type).toBe("input")
      expect(event.value).toBe("")
      expect($("person-lindsay").getAttribute("aria-selected")).toBe("true")
      expect($("input").value).toBe("Lindsay Walton")
    })

    it("keeps the label of the selection through a morph with no text in the input", async () => {
      await mountField(people([lindsay, tom]))
      respond(people([], { selected: "lindsay" }))
      const done = nextDone()
      $("person-lindsay").click()
      await done
      await new Promise((resolve) => setTimeout(resolve, 0))

      // The server render has no selected item and no text: the browser keeps both.
      expect($("input").value).toBe("Lindsay Walton")
      expect([...$("list").children].map((el) => el.id)).toEqual(["person-lindsay"])
      expect($("person-lindsay").getAttribute("aria-selected")).toBe("true")
      expect($("value").value).toBe("lindsay")
    })

    it("keeps a new selection through a morph that removes the item of the old selection", async () => {
      await mountField(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      // The user deletes the text until the server gives a second person. The server render
      // still has the old selection.
      await search("o", [lindsay, tom], "lindsay")
      expect($("popup").hidden).toBe(false)

      // Enter chooses Tom. The label of the selection sends no text: the server render has no
      // person, and its selection is Tom.
      press("ArrowDown")
      expect($("person-lindsay").hasAttribute("data-highlighted")).toBe(true)
      press("ArrowDown")
      expect($("person-tom").hasAttribute("data-highlighted")).toBe(true)
      respond(people([], { selected: "tom" }))
      const done = nextDone()
      press("Enter")

      expect($("input").value).toBe("Tom Cook")
      expect($("value").value).toBe("tom")
      await done
      await new Promise((resolve) => setTimeout(resolve, 0))

      // The browser keeps the item of the new selection, its label and its value.
      expect($("input").value).toBe("Tom Cook")
      expect($("value").value).toBe("tom")
      expect([...$("list").children].map((el) => el.id)).toEqual(["person-tom"])
      expect($("person-tom").getAttribute("aria-selected")).toBe("true")
      expect($("clear").hidden).toBe(false)
    })

    it("takes a new selection of the server that keeps the item of the old selection", async () => {
      await mountField(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      await search("o", [lindsay, tom], "lindsay")

      // The server selects Tom: the item of Lindsay stays, with no `data-selected`.
      await search("o", [lindsay, tom], "tom")
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect($("input").value).toBe("Tom Cook")
      expect($("value").value).toBe("tom")
      expect([...$("list").children].map((el) => el.id)).toEqual(["person-lindsay", "person-tom"])
      expect($("person-lindsay").getAttribute("aria-selected")).toBe("false")
      expect($("person-tom").getAttribute("aria-selected")).toBe("true")
    })

    it("keeps the text that the user types when the server render has the selection of the browser", async () => {
      await mountField(people([lindsay, tom]))
      respond(people([], { selected: "lindsay" }))
      let done = nextDone()
      $("person-lindsay").click()
      await done
      await new Promise((resolve) => setTimeout(resolve, 0))

      // The user deletes a letter. The server render marks Lindsay as selected (a bound value),
      // but the input has no `phem:bind`: the text belongs to the browser.
      respond(people([lindsay], { selected: "lindsay" }))
      done = nextDone()
      $("input").value = "Lindsay Walto"
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      await done
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect($("input").value).toBe("Lindsay Walto")
      expect($("value").value).toBe("lindsay")
      expect($("person-lindsay").getAttribute("aria-selected")).toBe("true")
      expect($("popup").hidden).toBe(false)
    })

    it("sends the text that the user types after a selection", async () => {
      await mountField(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      await search("Lind", [lindsay], "lindsay")

      expect(JSON.parse(fetch.mock.calls.at(-1)[1].body).event.value).toBe("Lind")
    })

    it("gives the input the label of the selection again when the popup closes", async () => {
      await mountField(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      await search("zzz", [lindsay], "lindsay")
      expect($("popup").hidden).toBe(false)

      respond(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      const done = nextDone()
      press("Escape")

      expect($("popup").hidden).toBe(true)
      expect($("input").value).toBe("Lindsay Walton")
      await done
      expect($("value").value).toBe("lindsay")
    })

    it("opens with the highlight on the selected item", async () => {
      await mountField(people([lindsay, tom], { selected: "tom", text: "Tom Cook" }))
      $("input").focus()
      press("ArrowDown")

      expect($("popup").hidden).toBe(false)
      expect($("person-tom").hasAttribute("data-highlighted")).toBe(true)
      expect($("input").getAttribute("aria-activedescendant")).toBe("person-tom")
    })

    it("shows the clear target only with a selected item, and a clear removes the text and the selection", async () => {
      await mountField(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      expect($("clear").hidden).toBe(false)

      respond(people([], { text: "" }))
      const done = nextDone()
      $("clear").click()

      expect($("input").value).toBe("")
      expect($("value").value).toBe("")
      expect($("person-lindsay").getAttribute("aria-selected")).toBe("false")
      expect($("clear").hidden).toBe(true)
      await done
      expect($("clear").hidden).toBe(true)
    })

    it("keeps the selected item at the end of a server render that does not have it", async () => {
      await mountField(people([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
      await search("tom", [tom])
      await new Promise((resolve) => setTimeout(resolve, 0))

      expect([...$("list").children].map((el) => el.id)).toEqual(["person-tom", "person-lindsay"])
      expect($("person-lindsay").getAttribute("aria-selected")).toBe("true")
      expect($("value").value).toBe("lindsay")
      expect($("clear").hidden).toBe(false)
    })

    describe("with the parts of a status", () => {
      // The people of the server, with a status that shows the state of the search.
      function searched(results, options = {}) {
        const status = `<div id="status" data-phem-combobox-target="status">
            <span id="pending" data-phem-combobox-status="pending" hidden>Searching…</span>
            <span id="error" data-phem-combobox-status="error" hidden>Failed to search.</span>
            <span id="idle" data-phem-combobox-status="idle" hidden>Start typing.</span>
            <span id="no-match" data-phem-combobox-status="empty" hidden>No matches.</span>
          </div>
          <p id="empty" data-phem-combobox-target="empty">Try another text.</p>`

        return people(results, options).replace('<ul id="list"', `${status}<ul id="list"`)
      }

      const shown = () => ["pending", "error", "idle", "no-match"].filter((id) => !$(id).hidden)

      async function typeText(text) {
        const done = nextDone()
        $("input").focus()
        $("input").value = text
        $("input").dispatchEvent(new Event("input", { bubbles: true }))
        await done
        await new Promise((resolve) => setTimeout(resolve, 0))
      }

      it("shows the idle part with no text and no item, and no empty content", async () => {
        await mountField(searched([]))

        expect(shown()).toEqual(["idle"])
        expect($("empty").textContent).toBe("")
      })

      it("removes the selection when the user deletes all the text, and shows the idle part", async () => {
        await mountField(searched([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
        respond(searched([]))
        await typeText("")

        expect($("value").value).toBe("")
        expect($("clear").hidden).toBe(true)
        // The server render has no person: the item that is not selected leaves the list.
        expect($("list").children).toHaveLength(0)
        expect(shown()).toEqual(["idle"])
        expect(JSON.parse(fetch.mock.calls.at(-1)[1].body).event.value).toBe("")

        press("Escape")
        expect($("input").value).toBe("")
      })

      it("shows the idle part at once after a clear on the open popup, with no kept item", async () => {
        await mountField(searched([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
        respond(searched([], { text: "zzz" }))
        await typeText("zzz")
        expect([...$("list").children].map((el) => el.id)).toEqual(["person-lindsay"])
        expect($("popup").hidden).toBe(false)

        // The server has not answered yet.
        fetch.mockReturnValueOnce(new Promise(() => {}))
        $("clear").click()

        expect($("list").children).toHaveLength(0)
        expect(shown()).toEqual(["idle"])
        expect($("popup").hidden).toBe(false)
        expect($("clear").hidden).toBe(true)
        expect(document.activeElement.id).toBe("input")
      })

      it("shows no part when the list has the selected item", async () => {
        await mountField(searched([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))

        expect(shown()).toEqual([])
      })

      it("shows the empty part and the empty content when no item matches the text", async () => {
        await mountField(searched([]))
        respond(searched([], { text: "zzz" }))
        await typeText("zzz")

        expect(shown()).toEqual(["no-match"])
        expect($("empty").textContent).toBe("Try another text.")
      })

      it("shows the empty part when nothing matches, also with the selected item that the browser keeps", async () => {
        await mountField(searched([lindsay], { selected: "lindsay", text: "Lindsay Walton" }))
        respond(searched([], { text: "zzz" }))
        await typeText("zzz")

        expect(shown()).toEqual(["no-match"])
        expect($("person-lindsay").hidden).toBe(false)
        // The list has an item: the empty target has no content.
        expect($("empty").textContent).toBe("")
      })

      it("shows the pending part while the event of the input runs", async () => {
        await mountField(searched([]))
        let answer
        fetch.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))
        $("input").focus()
        $("input").value = "lind"
        $("input").dispatchEvent(new Event("input", { bubbles: true }))
        // Before the loading state, the status keeps its state: no result of the old text.
        expect(shown()).toEqual(["idle"])

        await vi.waitFor(() => expect(shown()).toEqual(["pending"]))
        expect($("empty").textContent).toBe("")

        const done = nextDone()
        answer(new Response(JSON.stringify({ streams: "", changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
        await done
        await vi.waitFor(() => expect(shown()).toEqual(["no-match"]))
      })

      it("shows the error part and no item when the event of the input fails, with no message of phem", async () => {
        await mountField(searched([]))
        respond(searched([lindsay], { text: "lind" }))
        await typeText("lind")
        expect($("person-lindsay").hidden).toBe(false)

        vi.spyOn(console, "error").mockImplementation(() => {})
        fetch.mockResolvedValueOnce(new Response("Failed", { status: 500 }))
        await typeText("will_error")

        expect(shown()).toEqual(["error"])
        expect($("person-lindsay").hidden).toBe(true)
        expect($("empty").textContent).toBe("")
        expect(document.querySelector(".phem-error")).toBe(null)

        // The next search that the server takes hides the error.
        respond(searched([lindsay], { text: "lin" }))
        await typeText("lin")
        expect(shown()).toEqual([])
        expect($("person-lindsay").hidden).toBe(false)
      })
    })
  })

  describe("the multiple selection mode with a list of the server", () => {
    // The people that the server gives, with the template of a chip that sends the value in a
    // form, and the chips of the people that it renders as chosen.
    function people(results, { chosen = [], text = "" } = {}) {
      const names = { ada: "Ada Lovelace", bob: "Bob Kahn", tom: "Tom Cook" }
      const chip = (id) => `<span id="chip-${id}" data-phem-combobox-target="chip" data-value="${id}">${names[id]}<input type="hidden" name="reviewers" value="${id}" /><button type="button" id="remove-${id}" data-phem-combobox-target="chipRemove" aria-label="Remove ${names[id]}">×</button></span>`
      const item = (id) => `<li id="person-${id}" data-value="${id}" data-label="${names[id]}" data-phem-combobox-target="item">${names[id]}</li>`

      return `<form id="form" data-phem-on-submit="c1.save">
          <div id="combobox" data-controller="phem-combobox" data-phem-combobox-selection-mode-value="multiple" data-phem-combobox-multiple-value="true">
            <div id="chips" data-phem-combobox-target="chips">
              ${chosen.map(chip).join("")}
              <template id="chip-template" data-phem-combobox-target="chipTemplate"><span data-phem-combobox-target="chip" aria-label="{label}">{label}<input type="hidden" name="reviewers" value="{value}" /><button type="button" data-phem-combobox-target="chipRemove" aria-label="Remove {label}">×</button></span></template>
              <input id="input" data-phem-combobox-target="input" data-phem-on-input="c1.search" aria-label="Reviewers" value="${text}" />
            </div>
            <button type="button" id="clear" data-phem-combobox-target="clear">Clear</button>
            <div id="popup" data-phem-combobox-target="popup" hidden>
              <ul id="list" data-phem-combobox-target="list">${results.map(item).join("")}</ul>
            </div>
          </div>
        </form>`
    }

    const chips = () => [...$("chips").children].filter((el) => el.matches("[data-phem-combobox-target=chip]") && !el.hidden).map((el) => el.getAttribute("data-value"))
    const sent = () => new FormData($("form")).getAll("reviewers")

    // The user types, and the server answers with the results.
    async function search(text, results, chosen = []) {
      respond(people(results, { chosen, text }))
      const done = nextDone()
      $("input").focus()
      $("input").value = text
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      await done
    }

    it("adds the chip of a chosen item from the template, with no event of the server", async () => {
      await mountField(people([]))
      await search("a", ["ada", "bob"])
      const changes = []
      $("combobox").addEventListener("phem-combobox:change", (event) => changes.push(event.detail.value))
      const calls = fetch.mock.calls.length

      // The text goes after the click: its input event searches with no text.
      respond(people([]))
      const done = nextDone()
      $("person-bob").click()

      expect(chips()).toEqual(["bob"])
      const chip = $("chips").querySelector('[data-value="bob"]')
      expect(chip.textContent).toBe("Bob Kahn×")
      expect(chip.getAttribute("aria-label")).toBe("Bob Kahn")
      expect(chip.nextElementSibling.id).toBe("chip-template")
      expect($("person-bob").getAttribute("aria-selected")).toBe("true")
      expect($("person-bob").hasAttribute("data-selected")).toBe(true)
      expect($("person-bob").hasAttribute("data-pending")).toBe(false)
      expect(sent()).toEqual(["bob"])
      expect(changes).toEqual([["bob"]])
      expect(fetch.mock.calls.length).toBe(calls)

      // The new list of the server keeps the chip, and the chosen item.
      await done
      expect($("input").value).toBe("")
      expect(chips()).toEqual(["bob"])
      expect($("person-bob")).not.toBe(null)
      expect($("person-ada")).toBe(null)
      expect(sent()).toEqual(["bob"])
    })

    it("removes a value with a second click, the remove button of its chip and the clear target", async () => {
      await mountField(people([]))
      await search("o", ["ada", "bob", "tom"])
      respond(people(["ada", "bob", "tom"], { text: "" }))
      const done = nextDone()
      $("person-ada").click()
      $("person-bob").click()
      $("person-tom").click()
      await done
      expect(chips()).toEqual(["ada", "bob", "tom"])

      $("person-ada").click()
      expect(chips()).toEqual(["bob", "tom"])
      expect($("person-ada").getAttribute("aria-selected")).toBe("false")

      $("chips").querySelector('[data-value="bob"] button').click()
      expect(chips()).toEqual(["tom"])
      expect(sent()).toEqual(["tom"])

      respond(people([]))
      $("clear").click()
      expect(chips()).toEqual([])
      expect(sent()).toEqual([])
    })

    it("removes a kept item from the list when its value goes", async () => {
      await mountField(people([]))
      await search("bo", ["bob"])
      respond(people([]))
      const done = nextDone()
      $("person-bob").click()
      await done
      expect($("person-bob")).not.toBe(null)

      $("chips").querySelector('[data-value="bob"] button').click()
      expect(chips()).toEqual([])
      expect($("person-bob")).toBe(null)
    })

    it("takes the chips of the server render as the first values, and keeps a removed one out of the form", async () => {
      await mountField(people([], { chosen: ["ada", "tom"] }))
      expect(chips()).toEqual(["ada", "tom"])
      expect(sent()).toEqual(["ada", "tom"])

      $("remove-ada").click()
      expect(chips()).toEqual(["tom"])
      expect($("chip-ada").hidden).toBe(true)
      expect(sent()).toEqual(["tom"])

      // A server render of the same chips keeps the choice of the browser.
      await search("b", ["bob"], ["ada", "tom"])
      expect($("chip-ada").hidden).toBe(true)
      expect(chips()).toEqual(["tom"])
      expect(sent()).toEqual(["tom"])
    })

    it("removes the value of a chip that a server render removes", async () => {
      await mountField(people([], { chosen: ["ada", "tom"] }))
      await search("b", ["bob"], ["tom"])

      expect(chips()).toEqual(["tom"])
      expect(sent()).toEqual(["tom"])
      expect($("clear").hidden).toBe(false)
    })

    it("replaces the chip that the browser made with the chip of the server", async () => {
      await mountField(people([]))
      await search("a", ["ada", "bob"])
      respond(people(["ada", "bob"], { chosen: ["ada"], text: "a" }))
      $("person-ada").setAttribute("data-phem-on-click", "c1.assign")
      const done = nextDone()
      $("person-ada").click()
      expect(chips()).toEqual(["ada"])
      await done

      expect(chips()).toEqual(["ada"])
      expect($("chips").querySelectorAll('[data-value="ada"]').length).toBe(1)
      expect($("chip-ada")).not.toBe(null)
      expect(sent()).toEqual(["ada"])
    })

    it("lets the server change the disabled state of a button in a chip", async () => {
      await mountField(people([], { chosen: ["ada"] }))
      respond(people(["bob"], { chosen: ["ada"], text: "b" }).replace('id="remove-ada"', 'id="remove-ada" disabled'))
      const done = nextDone()
      $("input").focus()
      $("input").value = "b"
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      await done

      expect($("remove-ada").disabled).toBe(true)
    })

    it("sends the chosen values with the submit of the form", async () => {
      await mountField(people([]))
      await search("a", ["ada", "bob"])
      respond(people([]))
      const searched = nextDone()
      $("person-ada").click()
      await searched

      respond(people([]))
      const done = nextDone()
      $("form").requestSubmit()
      await done

      const { form } = JSON.parse(fetch.mock.calls.at(-1)[1].body).event
      expect(form).toEqual([["reviewers", "ada"]])
      expect(chips()).toEqual(["ada"])
    })
  })

  describe("an inline list", () => {
    // A command palette: the list is in the page, with no popup.
    function palette(attributes = "") {
      return `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-filter-value="contains" ${attributes}>
          <input id="input" data-phem-combobox-target="input" aria-label="Search commands" />
          <div id="list" data-phem-combobox-target="list">
            <div id="linear" data-phem-combobox-target="item">Linear</div>
            <div id="figma" data-phem-combobox-target="item">Figma</div>
            <div id="slack" data-phem-combobox-target="item">Slack</div>
          </div>
        </div>`
    }

    const out = (el) => el.dispatchEvent(new PointerEvent("pointerout", { bubbles: true, pointerType: "mouse", relatedTarget: document.body }))

    it("is always open", async () => {
      await mount(palette())

      expect($("input").getAttribute("aria-expanded")).toBe("true")
      expect(document.querySelector("[data-highlighted]")).toBe(null)

      press("ArrowDown")
      expect($("input").getAttribute("aria-activedescendant")).toBe("linear")
      press("ArrowDown")
      expect($("input").getAttribute("aria-activedescendant")).toBe("figma")
    })

    it("always has a highlight with autoHighlight always, and Enter clicks it", async () => {
      await mount(palette('data-phem-combobox-auto-highlight-value="always"'))
      expect($("linear").hasAttribute("data-highlighted")).toBe(true)

      $("input").focus()
      $("input").value = "sl"
      $("input").dispatchEvent(new Event("input", { bubbles: true }))
      expect($("input").getAttribute("aria-activedescendant")).toBe("slack")

      const clicked = vi.fn()
      $("slack").addEventListener("click", clicked)
      press("Enter")
      expect(clicked).toHaveBeenCalledOnce()
    })

    it("keeps the highlight when the pointer leaves the items with keepHighlight", async () => {
      await mount(palette('data-phem-combobox-auto-highlight-value="always" data-phem-combobox-keep-highlight-value="true"'))
      hover($("slack"))
      expect($("slack").hasAttribute("data-highlighted")).toBe(true)

      out($("slack"))
      expect($("slack").hasAttribute("data-highlighted")).toBe(true)
    })

    it("does not move the highlight on hover with highlightItemOnHover false", async () => {
      await mount(palette('data-phem-combobox-auto-highlight-value="always" data-phem-combobox-highlight-item-on-hover-value="false"'))
      hover($("slack"))

      expect($("linear").hasAttribute("data-highlighted")).toBe(true)
      expect($("slack").hasAttribute("data-highlighted")).toBe(false)
    })
  })

  describe("a grid", () => {
    // Two rows of three items, and a row of one item.
    const markup = `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-grid-value="true">
        <input id="input" data-phem-combobox-target="input" aria-label="Search emojis" />
        <div id="popup" data-phem-combobox-target="popup" hidden>
          <div id="list" data-phem-combobox-target="list">
            <div id="r1" data-phem-combobox-target="row"><span id="a" data-phem-combobox-target="item">a</span><span id="b" data-phem-combobox-target="item">b</span><span id="c" data-phem-combobox-target="item">c</span></div>
            <div id="r2" data-phem-combobox-target="row"><span id="d" data-phem-combobox-target="item">d</span><span id="e" data-phem-combobox-target="item">e</span><span id="f" data-phem-combobox-target="item">f</span></div>
            <div id="r3" data-phem-combobox-target="row"><span id="g" data-phem-combobox-target="item">g</span></div>
          </div>
        </div>
      </div>`

    const active = () => $("input").getAttribute("aria-activedescendant")

    it("sets the roles of a grid", async () => {
      await mount(markup)

      expect($("list").getAttribute("role")).toBe("grid")
      expect($("r1").getAttribute("role")).toBe("row")
      expect($("a").getAttribute("role")).toBe("gridcell")
      expect($("input").getAttribute("aria-haspopup")).toBe("grid")
    })

    it("moves the highlight in the rows and in the columns", async () => {
      await mount(markup)
      $("input").focus()
      press("ArrowDown")
      press("ArrowDown")
      expect(active()).toBe("a")

      press("ArrowRight")
      press("ArrowRight")
      expect(active()).toBe("c")
      press("ArrowDown")
      expect(active()).toBe("f")
      press("ArrowLeft")
      expect(active()).toBe("e")
      press("ArrowDown")
      expect(active()).toBe("g")
      press("ArrowUp")
      expect(active()).toBe("d")
      press("ArrowLeft")
      expect(active()).toBe("c")
    })

    it("keeps the default of ArrowLeft and ArrowRight with no highlight", async () => {
      await mount(markup)
      $("input").focus()

      expect(press("ArrowRight").defaultPrevented).toBe(false)
    })

    it("goes back to the input before the first row", async () => {
      await mount(markup)
      $("input").focus()
      press("ArrowDown")
      press("ArrowDown")
      press("ArrowUp")

      expect($("input").hasAttribute("aria-activedescendant")).toBe(false)
    })
  })

  describe("a choice that shows before the answer", () => {
    // Resolves with the `phem:done` of the next event of the handler.
    function doneOf(handler) {
      return new Promise((resolve) => {
        const listener = (event) => {
          if (event.detail.handler !== handler) return

          window.removeEventListener("phem:done", listener)
          resolve(event)
        }
        window.addEventListener("phem:done", listener)
      })
    }

    const failed = () => fetch.mockResolvedValueOnce(new Response("Failed", { status: 500 }))
    const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

    describe("with multiple", () => {
      // The server gives the chips of the chosen people, a template of a chip, and the results.
      function reviewers(chosen, results = ["ada", "bob"]) {
        const chip = (p) => `<span id="chip-${p}" data-value="${p}" data-phem-combobox-target="chip" aria-label="${p}">${p}<button data-phem-combobox-target="chipRemove" data-phem-on-click="c1.remove" aria-label="Remove ${p}">×</button></span>`
        const item = (p) => `<li id="person-${p}" data-value="${p}" data-label="${p.toUpperCase()}" data-phem-combobox-target="item" data-phem-on-click="c1.toggle" aria-selected="${chosen.includes(p)}">${p}</li>`

        return `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-multiple-value="true">
            <div id="chips" data-phem-combobox-target="chips">
              ${chosen.map(chip).join("")}
              <template id="chip-template" data-phem-combobox-target="chipTemplate"><span data-phem-combobox-target="chip" aria-label="{label}">{label}<button data-phem-combobox-target="chipRemove" aria-label="Remove {label}">×</button></span></template>
              <input id="input" data-phem-combobox-target="input" data-phem-on-input="c1.search" aria-label="Reviewers" />
            </div>
            <div id="popup" data-phem-combobox-target="popup" hidden>
              <ul id="list" data-phem-combobox-target="list">${results.map(item).join("")}</ul>
            </div>
          </div>`
      }

      const chipsOf = (value) => [...document.querySelectorAll(`#chips [data-value="${value}"]`)]

      it("adds the chip of the item at once, and the chip of the server replaces it", async () => {
        await mountField(reviewers([]))
        respond(reviewers(["ada"]))
        const done = doneOf("c1.toggle")
        $("person-ada").click()

        const [chip] = chipsOf("ada")
        expect(chip.hasAttribute("data-pending")).toBe(true)
        expect(chip.textContent).toBe("ADA×")
        expect(chip.getAttribute("aria-label")).toBe("ADA")
        expect(chip.querySelector("button").getAttribute("aria-label")).toBe("Remove ADA")
        expect(chip.nextElementSibling.id).toBe("chip-template")
        expect($("person-ada").getAttribute("aria-selected")).toBe("true")
        expect($("person-ada").hasAttribute("data-selected")).toBe(true)
        expect($("chips").getAttribute("role")).toBe("toolbar")

        await done
        expect(chipsOf("ada").map((el) => el.id)).toEqual(["chip-ada"])
        expect($("chip-ada").hasAttribute("data-pending")).toBe(false)
        expect($("person-ada").getAttribute("aria-selected")).toBe("true")
      })

      it("adds no chip for an item that is chosen, or with no template", async () => {
        await mountField(reviewers(["ada"]))
        respond(reviewers([]))
        const done = doneOf("c1.toggle")
        $("person-ada").click()

        expect(chipsOf("ada").map((el) => el.id)).toEqual(["chip-ada"])
        expect($("chip-ada").hasAttribute("data-pending")).toBe(false)
        await done

        $("chip-template").remove()
        respond(reviewers(["bob"]).replace(/<template[\s\S]*?<\/template>/, ""))
        const added = doneOf("c1.toggle")
        $("person-bob").click()
        expect(chipsOf("bob")).toEqual([])
        await added
      })

      it("shows the failure on the chip, then removes the chip and the choice after one second", async () => {
        await mountField(reviewers([]))
        vi.spyOn(console, "error").mockImplementation(() => {})
        const errors = []
        document.addEventListener("phem:error", (event) => errors.push(event.defaultPrevented), { once: true })
        failed()
        const done = doneOf("c1.toggle")
        $("person-ada").click()
        await done

        const [chip] = chipsOf("ada")
        expect(chip.hasAttribute("data-pending")).toBe(false)
        expect(chip.hasAttribute("data-failed")).toBe(true)
        expect($("person-ada").getAttribute("aria-selected")).toBe("true")
        // The chip shows the failure: phem shows no message.
        expect(errors).toEqual([true])
        expect(document.querySelector(".phem-error")).toBe(null)

        await wait(1050)
        expect(chipsOf("ada")).toEqual([])
        expect(chip.isConnected).toBe(false)
        expect($("person-ada").getAttribute("aria-selected")).toBe("false")
        expect($("person-ada").hasAttribute("data-selected")).toBe(false)
        expect($("chips").hasAttribute("role")).toBe(false)
      })
    })

    describe("with the single selection mode", () => {
      // The people that the server gives, and the selected person. A click on a person assigns it.
      function reviewer(results, { selected = null, text = "" } = {}) {
        const item = ([id, name]) => `<li id="person-${id}" data-value="${id}"${id === selected ? " data-selected" : ""} data-phem-combobox-target="item" data-phem-on-click="c1.assign">${name}</li>`

        return `<div id="combobox" data-controller="phem-combobox" data-phem-combobox-selection-mode-value="single">
            <input id="input" data-phem-combobox-target="input" data-phem-on-input="c1.search" aria-label="Reviewer" value="${text}" />
            <button id="clear" data-phem-combobox-target="clear">Clear</button>
            <input type="hidden" id="value" data-phem-combobox-target="value" value="${selected ?? ""}" />
            <div id="popup" data-phem-combobox-target="popup" hidden>
              <ul id="list" data-phem-combobox-target="list">${results.map(item).join("")}</ul>
            </div>
          </div>`
      }

      const people = [["ada", "Ada Lovelace"], ["bob", "Bob Kahn"]]

      it("gives data-pending to the clear part until the answer of the choice", async () => {
        await mountField(reviewer(people))
        // The event of the click, then the input event of the label: the server render of the
        // search has no item for the click.
        respond(reviewer([people[0]], { selected: "ada" }))
        respond(reviewer([], { selected: "ada" }))
        const done = doneOf("c1.assign")
        const searched = doneOf("c1.search")
        $("person-ada").click()

        expect($("input").value).toBe("Ada Lovelace")
        expect($("clear").hidden).toBe(false)
        expect($("clear").hasAttribute("data-pending")).toBe(true)

        await done
        await searched
        expect(fetch.mock.calls.map(([, { body }]) => JSON.parse(body).handler)).toEqual(["c1.assign", "c1.search"])
        expect($("clear").hasAttribute("data-pending")).toBe(false)
        expect($("clear").hasAttribute("data-failed")).toBe(false)
        expect($("value").value).toBe("ada")
        expect($("input").value).toBe("Ada Lovelace")
      })

      it("keeps a selection that the user changed before the answer of the choice", async () => {
        await mountField(reviewer(people))
        // The answer of the click waits.
        let answer
        fetch.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))
        const done = doneOf("c1.assign")
        $("person-ada").click()
        await new Promise((resolve) => setTimeout(resolve, 0))

        // The user deletes all the text: the selection goes.
        respond(reviewer([]))
        respond(reviewer([]))
        $("input").value = ""
        $("input").dispatchEvent(new Event("input", { bubbles: true }))
        expect($("value").value).toBe("")

        // The answer of the click marks Ada as selected: the server did not know the change.
        answer(new Response(JSON.stringify(answerOf(reviewer(people, { selected: "ada" }))), { status: 200, headers: { "Content-Type": "application/json" } }))
        await done
        await new Promise((resolve) => setTimeout(resolve, 0))
        expect($("value").value).toBe("")
        expect($("input").value).toBe("")
        expect($("clear").hidden).toBe(true)
        expect($("person-ada").getAttribute("aria-selected")).toBe("false")
      })

      it("shows the failure on the clear part, then clears the selection after one second", async () => {
        await mountField(reviewer(people))
        vi.spyOn(console, "error").mockImplementation(() => {})
        failed()
        respond(reviewer([]))
        const done = doneOf("c1.assign")
        $("person-ada").click()
        await done

        expect($("clear").hasAttribute("data-failed")).toBe(true)
        expect($("input").value).toBe("Ada Lovelace")
        expect(document.querySelector(".phem-error")).toBe(null)

        // The clear sends the input event of the empty input.
        respond(reviewer(people))
        await wait(1050)
        expect($("clear").hasAttribute("data-failed")).toBe(false)
        expect($("clear").hidden).toBe(true)
        expect($("input").value).toBe("")
        expect($("value").value).toBe("")
        // The browser kept the item for the selection: it leaves the list.
        expect($("person-ada")).toBe(null)
      })
    })
  })
})
