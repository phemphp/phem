import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemOptimisticController from "../dist/phem_optimistic_controller.js"
import { click, mount, nextDone, respond } from "./support.js"

let application
// The listeners of a test on the document end with the test.
let listeners

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-optimistic", PhemOptimisticController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  listeners = new AbortController()
})

afterEach(() => {
  listeners.abort()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

function listen(type, listener) {
  document.addEventListener(type, listener, { signal: listeners.signal })
}

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

  it("does not make the changes again at the morph of the event", async () => {
    await mount(like())
    respond(like("liked"))
    const done = nextDone()
    let morphed = null
    listen("turbo:morph-element", (e) => {
      if (e.target.id === "like") morphed = e.target.classList.contains("liked")
    })
    click("#like")
    await done

    expect(morphed).toBe(true)
    expect(document.getElementById("like").classList.contains("liked")).toBe(true)
  })

  it("changes the targets of the target param", async () => {
    await mount(`<div id="card" data-controller="phem-optimistic">
      <p id="body" data-phem-optimistic-target="body">Text</p>
      <button id="go" data-phem-on-click="c1.go" data-action="phem-optimistic#hide" data-phem-optimistic-target-param="body">Go</button>
    </div>`)
    fail()
    const done = nextDone()
    click("#go")

    expect(document.getElementById("body").hidden).toBe(true)
    expect(document.getElementById("card").hidden).toBe(false)
    await done
    expect(document.getElementById("body").hidden).toBe(false)
  })

  it("takes the param of the action before the param of all the actions", async () => {
    const markup = (count) => `<button id="like" data-controller="phem-optimistic" data-phem-on-click="c1.like"
      data-action="phem-optimistic#toggleClass phem-optimistic#increment" data-phem-optimistic-class-param="liked"
      data-phem-optimistic-increment-target-param="count">♥ <span id="count" data-phem-optimistic-target="count">12 likes</span></button>`
    await mount(markup("12 likes"))
    fail()
    const done = nextDone()
    click("#like")

    expect(document.getElementById("like").classList.contains("liked")).toBe(true)
    expect(document.getElementById("count").classList.contains("liked")).toBe(false)
    expect(document.getElementById("count").textContent).toBe("13 likes")
    await done
    expect(document.getElementById("like").classList.contains("liked")).toBe(false)
    expect(document.getElementById("count").textContent).toBe("12 likes")
  })

  it("throws for a target that is not on the page", async () => {
    await mount(`<div data-controller="phem-optimistic">
      <button id="go" data-action="phem-optimistic#hide" data-phem-optimistic-target-param="nothing">Go</button>
    </div>`)
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    click("#go")

    expect(error.mock.calls.flat().map(String).join(" ")).toContain('no "nothing" target')
  })

  it("sets an attribute, and removes it again when the event fails", async () => {
    await mount(`<button id="pin" data-controller="phem-optimistic" data-phem-on-click="c1.pin" data-action="phem-optimistic#setAttribute"
      data-phem-optimistic-attribute-param="aria-pressed" data-phem-optimistic-value-param="true">Pin</button>`)
    fail()
    const done = nextDone()
    click("#pin")

    expect(document.getElementById("pin").getAttribute("aria-pressed")).toBe("true")
    await done
    expect(document.getElementById("pin").hasAttribute("aria-pressed")).toBe(false)
  })

  it("toggles and removes attributes, each with the param of its action", async () => {
    await mount(`<div id="box" title="Box" data-controller="phem-optimistic">
      <button id="go" data-phem-on-click="c1.go" data-action="phem-optimistic#toggleAttribute phem-optimistic#removeAttribute"
        data-phem-optimistic-toggle-attribute-attribute-param="data-open" data-phem-optimistic-remove-attribute-attribute-param="title">Go</button>
    </div>`)
    fail()
    const done = nextDone()
    click("#go")
    const box = document.getElementById("box")

    expect(box.hasAttribute("data-open")).toBe(true)
    expect(box.hasAttribute("title")).toBe(false)
    await done
    expect(box.hasAttribute("data-open")).toBe(false)
    expect(box.getAttribute("title")).toBe("Box")
  })

  it("sets the text as it is, and gives the markup back at the end", async () => {
    await mount(`<div data-controller="phem-optimistic"><p id="price" data-phem-optimistic-target="price"><b>1.00</b> EUR</p>
      <button id="go" data-phem-on-click="c1.go" data-action="phem-optimistic#setText" data-phem-optimistic-target-param="price"
        data-phem-optimistic-text-param="1.50"></button></div>`)
    fail()
    const done = nextDone()
    click("#go")

    expect(document.getElementById("price").textContent).toBe("1.50")
    await done
    expect(document.getElementById("price").querySelector("b").textContent).toBe("1.00")
  })

  it("adds to the server number at the morph of another event", async () => {
    const counter = (n) => `<p id="other" data-phem-on-click="c1.other">Other</p>
      <div data-controller="phem-optimistic"><span id="count" data-phem-optimistic-target="count">${n} likes</span>
      <button id="down" data-phem-on-click="c1.down" data-action="phem-optimistic#decrement" data-phem-optimistic-target-param="count"
        data-phem-optimistic-by-param="2"></button></div>`
    await mount(counter(12))
    respond(counter(20))
    respond(counter(18))
    const first = nextDone()
    click("#other")
    click("#down")
    const count = document.getElementById("count")

    expect(count.textContent.trim()).toBe("10 likes")
    await first
    expect(count.textContent.trim()).toBe("18 likes")
    await nextDone()
    expect(count.textContent.trim()).toBe("18 likes")
  })

  it("keeps the change of a second click while the event of the first click runs", async () => {
    await mount(like())
    respond(like("liked"))
    respond(like())
    const first = nextDone()
    let morphed = []
    listen("turbo:morph-element", (e) => {
      if (e.target.id === "like") morphed.push(e.target.classList.contains("liked"))
    })
    click("#like")
    click("#like")
    const button = document.getElementById("like")

    expect(button.classList.contains("liked")).toBe(false)
    await first
    expect(button.classList.contains("liked")).toBe(false)
    await nextDone()
    expect(button.classList.contains("liked")).toBe(false)
    expect(morphed).toEqual([false, false])
  })

  it("reverts only the change of the event that failed", async () => {
    const counter = (n) => `<div data-controller="phem-optimistic"><span id="count" data-phem-optimistic-target="count">${n} likes</span>
      <button id="up" data-phem-on-click="c1.up" data-action="phem-optimistic#increment" data-phem-optimistic-target-param="count"></button></div>`
    await mount(counter(12))
    fail()
    respond(counter(13))
    const first = nextDone()
    click("#up")
    click("#up")
    const count = document.getElementById("count")

    expect(count.textContent).toBe("14 likes")
    expect((await first).detail.ok).toBe(false)
    expect(count.textContent).toBe("13 likes")
    await nextDone()
    expect(count.textContent).toBe("13 likes")
  })

  it("keeps the change of a debounced action until its own event ends", async () => {
    const markup = (classes = "") => `<p id="other" data-phem-on-click="c1.other">Other</p>
      <button id="like" class="${classes}" data-controller="phem-optimistic" data-phem-optimistic-class-value="liked"
        data-action="phem-optimistic#addClass" data-phem-on-click="c1.like" data-phem-debounce="30">Like</button>`
    await mount(markup())
    respond(markup())
    respond(markup("liked"))
    const first = nextDone()
    click("#other")
    click("#like")
    const button = document.getElementById("like")
    await first

    expect(button.classList.contains("liked")).toBe(true)
    await nextDone()
    expect(button.classList.contains("liked")).toBe(true)
  })

  it("ends the changes of all the DOM events that one debounced event carries", async () => {
    const counter = (n) => `<div data-controller="phem-optimistic"><span id="count" data-phem-optimistic-target="count">${n} likes</span>
      <button id="up" data-phem-on-click="c1.up" data-phem-debounce="20" data-action="phem-optimistic#increment"
        data-phem-optimistic-target-param="count"></button></div>`
    await mount(counter(12))
    respond(counter(14))
    const done = nextDone()
    let morphed = null
    listen("turbo:morph-element", (e) => {
      if (e.target.id === "count") morphed = e.target.textContent
    })
    click("#up")
    click("#up")

    expect(document.getElementById("count").textContent).toBe("14 likes")
    await done
    expect(morphed).toBe("14 likes")
    expect(document.getElementById("count").textContent).toBe("14 likes")
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("does not write an element that the morph of the event wrote when the event ends", async () => {
    const counter = (n) => `<div data-controller="phem-optimistic"><span id="count" data-phem-optimistic-target="count">${n} likes</span>
      <button id="up" data-phem-on-click="c1.up" data-action="phem-optimistic#increment" data-phem-optimistic-target-param="count"></button></div>`
    await mount(counter(12))
    respond(counter(13))
    respond(counter(14))
    const first = nextDone()
    let node = null
    listen("turbo:morph-element", (e) => {
      if (e.target.id === "count") node = e.target.firstChild
    })
    click("#up")
    click("#up")
    await first

    expect(document.getElementById("count").textContent).toBe("14 likes")
    expect(document.getElementById("count").firstChild).toBe(node)
    await nextDone()
  })

  it("makes no batch for an insert that has no <template>", async () => {
    await mount(`<div id="box" data-controller="phem-optimistic"><p data-phem-optimistic-target="list"></p>
      <button id="go" data-action="phem-optimistic#insert" data-phem-optimistic-target-param="list">Go</button></div>`)
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    click("#go")
    const controller = application.getControllerForElementAndIdentifier(document.getElementById("box"), "phem-optimistic")

    expect(error.mock.calls.flat().map(String).join(" ")).toContain("needs a <template>")
    expect(controller.batches).toEqual([])
  })

  describe("insert", () => {
    const chat = (items) => `<div id="chat" data-controller="phem-optimistic">
        <ul id="list">${items.map((m, i) => `<li key="${i}">${m}</li>`).join("")}<template data-phem-optimistic-target="message"><li class="pending"><span data-phem-optimistic-field="body"></span></li></template></ul>
        <form id="form" data-phem-on-submit="c1.send" data-action="phem-optimistic#insert" data-phem-optimistic-target-param="message">
          <input name="body" value="Hi"><button id="send">Send</button>
        </form>
        <p id="other" data-phem-on-click="c1.other">Other</p>
      </div>`
    const items = () => [...document.querySelectorAll("#list li")].map((li) => `${li.className}:${li.textContent}`)

    it("adds the item at once, and the morph of the event shows the server item only", async () => {
      await mount(chat(["One"]))
      respond(chat(["One", "Hi"]))
      const done = nextDone()
      let morphed = null
      listen("turbo:morph-element", (e) => {
        if (e.target.id === "list") morphed = items()
      })
      document.getElementById("send").click()

      expect(items()).toEqual([":One", "pending:Hi"])
      await done
      expect(morphed).toEqual([":One", ":Hi"])
      expect(items()).toEqual([":One", ":Hi"])
    })

    it("keeps the item after the morph of another event, and removes it when the event fails", async () => {
      await mount(chat(["One"]))
      respond(chat(["Zero", "One"]))
      const first = nextDone()
      click("#other")
      document.getElementById("send").click()
      vi.spyOn(console, "error").mockImplementation(() => {})
      window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
      fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))

      expect(items()).toEqual([":One", "pending:Hi"])
      await first
      expect(items()).toEqual([":Zero", ":One", "pending:Hi"])
      await nextDone()
      expect(items()).toEqual([":Zero", ":One"])
    })

    it("removes the item before a stream with a `targets` selector", async () => {
      await mount(chat(["One"]))
      respond("", {}, { streams: `<turbo-stream action="update" method="morph" targets="#c1"><template>${chat(["One", "Hi"])}</template></turbo-stream>` })
      const done = nextDone()
      let morphed = null
      listen("turbo:morph-element", (e) => {
        if (e.target.id === "list") morphed = items()
      })
      document.getElementById("send").click()
      await done

      expect(morphed).toEqual([":One", ":Hi"])
      expect(items()).toEqual([":One", ":Hi"])
    })

    it("adds the item again before the new template of a stream that replaced the list", async () => {
      await mount(chat(["One"]))
      const list = (items) => `${items.map((m, i) => `<li key="${i}">${m}</li>`).join("")}<template data-phem-optimistic-target="message"><li class="pending"><span data-phem-optimistic-field="body"></span></li></template>`
      respond("", {}, { streams: `<turbo-stream action="update" target="list"><template>${list(["Zero", "One"])}</template></turbo-stream>` })
      respond(chat(["Zero", "One", "Hi"]))
      const first = nextDone()
      click("#other")
      document.getElementById("send").click()
      await first

      expect(items()).toEqual([":Zero", ":One", "pending:Hi"])
      await nextDone()
      expect(items()).toEqual([":Zero", ":One", ":Hi"])
    })

    it("joins the values of a field with more than one value", async () => {
      await mount(`<div data-controller="phem-optimistic"><ul id="list"><template data-phem-optimistic-target="tag"><li data-phem-optimistic-field="tags"></li></template></ul>
        <form id="form" data-phem-on-submit="c1.tag" data-action="phem-optimistic#insert" data-phem-optimistic-target-param="tag">
          <input type="checkbox" name="tags" value="a" checked><input type="checkbox" name="tags" value="b" checked><button id="send">Send</button>
        </form></div>`)
      fail()
      const done = nextDone()
      document.getElementById("send").click()

      expect(document.querySelector("#list li").textContent).toBe("a, b")
      await done
    })

    it("removes the item of a submit that the locked form did not send when the event ends", async () => {
      await mount(chat(["One"]))
      respond(chat(["One", "Hi"]))
      const done = nextDone()
      document.getElementById("send").click()
      document.getElementById("form").requestSubmit()

      expect(items()).toEqual([":One", "pending:Hi", "pending:Hi"])
      await done
      expect(items()).toEqual([":One", ":Hi"])
    })
  })
})
