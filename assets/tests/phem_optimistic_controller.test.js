import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemOptimisticController from "../dist/phem_optimistic_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

beforeAll(() => {
  const application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-optimistic", PhemOptimisticController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A row with a "Delete" button that hides it at once, and a "Star" button with no action.
const ROW = `<li id="row-1" data-controller="phem-optimistic">
    <button id="delete" data-phem-on-click="c1.remove" data-action="phem-optimistic#hide">Delete</button>
    <button id="star" data-phem-on-click="c1.star">Star</button>
  </li>`

// A "Like" button that toggles the `liked` class at once, with the other `classes`.
function like(classes = "") {
  return `<button id="like" class="${classes}" data-controller="phem-optimistic" data-phem-optimistic-class-value="liked"
    data-action="phem-optimistic#toggleClass" data-phem-on-click="c1.like">Like</button>`
}

function fail() {
  vi.spyOn(console, "error").mockImplementation(() => {})
  window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
  fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
}

describe("phem-optimistic", () => {
  it("hides the element at once, and the morph removes it", async () => {
    await mount(`<ul>${ROW}</ul>`)
    respond(`<ul></ul>`)
    const done = nextDone()
    click("#delete")

    expect(document.getElementById("row-1").hidden).toBe(true)
    await done
    expect(document.getElementById("row-1")).toBeNull()
  })

  it("shows the element again when the server still renders it", async () => {
    await mount(`<ul>${ROW}</ul>`)
    respond(`<ul>${ROW}</ul>`)
    const done = nextDone()
    click("#delete")
    await done

    expect(document.getElementById("row-1").hidden).toBe(false)
  })

  it("reverts the change when the event fails", async () => {
    await mount(`<ul>${ROW}</ul>`)
    fail()
    const done = nextDone()
    click("#delete")
    const event = await done

    expect(event.detail.ok).toBe(false)
    expect(document.getElementById("row-1").hidden).toBe(false)
  })

  it("keeps the change after the morph of another event, with the other changes of that morph", async () => {
    await mount(`<p id="other" data-phem-on-click="c1.other">Other</p>${like()}`)
    respond(`<p id="other" data-phem-on-click="c1.other">Other</p>${like("big")}`)
    respond(`<p id="other" data-phem-on-click="c1.other">Other</p>${like("big liked")}`)
    const first = nextDone()
    // The event of "Like" waits in the queue for the event of "Other".
    click("#other")
    click("#like")
    const button = document.getElementById("like")

    expect(button.classList.contains("liked")).toBe(true)
    await first
    expect(button.classList.contains("big")).toBe(true)
    expect(button.classList.contains("liked")).toBe(true)

    await nextDone()
    expect(button.classList.contains("big")).toBe(true)
    expect(button.classList.contains("liked")).toBe(true)
  })

  it("shows the server render of the class at the end", async () => {
    await mount(like())
    respond(like())
    const done = nextDone()
    click("#like")

    expect(document.getElementById("like").classList.contains("liked")).toBe(true)
    await done
    expect(document.getElementById("like").classList.contains("liked")).toBe(false)
  })

  it("does not end the change on the event of another element", async () => {
    await mount(`<ul>${ROW}</ul>`)
    respond(`<ul>${ROW}</ul>`)
    respond(`<ul>${ROW}</ul>`)
    const first = nextDone()
    click("#star")
    click("#delete")
    const row = document.getElementById("row-1")
    await first

    expect(row.hidden).toBe(true)
    await nextDone()
    expect(row.hidden).toBe(false)
  })

  it("ends the change when the morph removed the element that sent the event", async () => {
    await mount(`<ul>${ROW}</ul>`)
    respond(`<ul><li id="row-1" data-controller="phem-optimistic"><button id="star" data-phem-on-click="c1.star">Star</button></li></ul>`)
    const done = nextDone()
    click("#delete")
    const event = await done

    expect(event.target.id).toBe("c1")
    expect(document.getElementById("row-1").hidden).toBe(false)
  })

  it("takes the class of the action param before the class value", async () => {
    await mount(`<span id="box" data-controller="phem-optimistic" data-phem-optimistic-class-value="value">
      <button data-phem-on-click="c1.go" data-action="phem-optimistic#addClass" data-phem-optimistic-class-param="param">Go</button>
    </span>`)
    respond(`<span id="box"></span>`)
    const done = nextDone()
    click("#box button")
    const box = document.getElementById("box")

    expect([...box.classList]).toEqual(["param"])
    await done
  })
})
