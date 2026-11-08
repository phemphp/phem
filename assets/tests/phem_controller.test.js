import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import * as Turbo from "@hotwired/turbo"
import PhemController from "../dist/phem_controller.js"
import { createRequire } from "node:module"
import { readFileSync } from "node:fs"
import { answer, click, mount, nextDone, respond } from "./support.js"

// A redirect and a reload are Turbo visits: jsdom cannot load a page.
vi.mock("@hotwired/turbo", async (original) => ({ ...(await original()), visit: vi.fn() }))

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  Turbo.visit.mockClear()
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

describe("data-phem-client", () => {
  it("keeps the attributes in the list, and morphs the others", async () => {
    await mount(`<p id="menu" data-phem-client="hidden aria-expanded" hidden aria-expanded="false" title="old">Menu</p><button data-phem-on-click="c1.star">Star</button>`)
    const menu = document.getElementById("menu")
    menu.hidden = false
    menu.setAttribute("aria-expanded", "true")
    respond(`<p id="menu" data-phem-client="hidden aria-expanded" hidden aria-expanded="false" title="new">Menu</p><button data-phem-on-click="c1.star">Unstar</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("menu")).toBe(menu)
    expect(menu.hidden).toBe(false)
    expect(menu.getAttribute("aria-expanded")).toBe("true")
    expect(menu.title).toBe("new")
    expect(document.querySelector("button").textContent).toBe("Unstar")
  })

  it("does not keep an attribute that is not in the list", async () => {
    await mount(`<p id="menu" data-phem-client="aria-expanded" hidden>Menu</p><button data-phem-on-click="c1.star">Star</button>`)
    document.getElementById("menu").hidden = false
    respond(`<p id="menu" data-phem-client="aria-expanded" hidden>Menu</p><button data-phem-on-click="c1.star">Star</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("menu").hidden).toBe(true)
  })
})

describe("the state", () => {
  // The same vector as in the tests of Phem\State\StateJson (tests/Runtime/StateJsonTest.php): the
  // server makes the same text from the same state and changes. A value has an escaped line break
  // after a comma and escaped quotes: they are not the end of an entry.
  const vector = JSON.parse(String.raw`{"before":"{\n\"_path\":{\"id\":\"3\"},\n\"_tree\":\"AAAAAAAA0\",\n\"phem0a\":{\"text\":\"a,\\nb \\\"c\\\" é/\"},\n\"phem0b\":{\"n\":1.0}\n}","changes":{"phem0a":null,"phem0b":"{\"n\":2.5}","phem0c":"[]","_tree":"\"BBBBBBBB0,CCCCCCCC1\""},"after":"{\n\"_path\":{\"id\":\"3\"},\n\"_tree\":\"BBBBBBBB0,CCCCCCCC1\",\n\"phem0b\":{\"n\":2.5},\n\"phem0c\":[]\n}"}`)

  // The same vector as in the tests of Phem\State\StateJson: a patch of the `_tree` entry (see
  // Phem\State\TextPatch), with a splice for each run of other parts.
  const patchVector = JSON.parse(String.raw`{"before":"{\n\"_tree\":\"AAAAAAAA0,BBBBBBBBbbbbbbbb1,CCCCCCCCcccccccc2,DDDDDDDDdddddddd1,EEEEEEEEeeeeeeee1\",\n\"phem0a\":{\"n\":1}\n}","changes":{"_tree":[[1,1,"BBBBBBBBxxxxxxxx1"],[4,1,"EEEEEEEEyyyyyyyy1\""]],"phem0a":"{\"n\":2}"},"after":"{\n\"_tree\":\"AAAAAAAA0,BBBBBBBBxxxxxxxx1,CCCCCCCCcccccccc2,DDDDDDDDdddddddd1,EEEEEEEEyyyyyyyy1\",\n\"phem0a\":{\"n\":2}\n}"}`)

  const root = () => document.getElementById("phem-root")

  it("applies the patch of an entry, as the server does", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    root().setAttribute("data-phem-state-value", patchVector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, patchVector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(root().getAttribute("data-phem-state-value")).toBe(patchVector.after)
  })

  it("applies a patch with more new parts than a call can have arguments", async () => {
    // A large #[State] list: one splice puts 300000 new parts in it. The event sends only the
    // signature of the state, as for a large state of a page.
    const count = 300000
    await mount(`<button data-phem-on-click="c1.save">Save</button>`, `data-phem-state-inline-value="4096"`)
    root().setAttribute("data-phem-state-value", `{\n"phem0a":[${Array(count + 2).fill("0").join(",")}]\n}`)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, { phem0a: [[1, count, ...Array(count).fill("1")]] })
    const done = nextDone()
    click("button")
    await done

    expect(root().getAttribute("data-phem-state-value")).toBe(`{\n"phem0a":[0,${Array(count).fill("1").join(",")},0]\n}`)
  })

  it("sets the changes of the response in the state, as the server does", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(root().getAttribute("data-phem-state-value")).toBe(vector.after)
    expect(root().getAttribute("data-phem-signature-value")).toBe("s2")
  })

  it("starts with no entries for a state with another form", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    root().setAttribute("data-phem-state-value", '{"phem0a":{"n":1}}')
    const all = { _path: '{"id":"3"}', _tree: '"BBBBBBBB0,CCCCCCCC1"', phem0b: '{"n":2.5}', phem0c: "[]" }
    respond(`<button data-phem-on-click="c1.save">Save</button>`, all)
    const done = nextDone()
    click("button")
    await done

    expect(root().getAttribute("data-phem-state-value")).toBe(vector.after)
  })

  it("sends the new state when the server does not have it", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`, `data-phem-state-inline-value="0"`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    let done = nextDone()
    click("button")
    await done

    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ state: "required" }), { status: 409 }))
    respond(`<button data-phem-on-click="c1.save">Save</button>`)
    done = nextDone()
    click("button")
    await done

    expect(fetch).toHaveBeenCalledTimes(3)
    expect(JSON.parse(fetch.mock.calls[1][1].body)).not.toHaveProperty("state")
    const body = JSON.parse(fetch.mock.calls[2][1].body)
    expect(body.state).toBe(vector.after)
    expect(body.signature).toBe("s2")
  })

  it("sends a state shorter than the inline size with the event", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`, `data-phem-state-inline-value="${vector.before.length + 1}"`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ state: vector.before, signature: "s1" })
  })

  it("sends only the signature of a state with the inline size or more", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`, `data-phem-state-inline-value="${vector.before.length}"`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body)).not.toHaveProperty("state")
  })

  it("always sends the state when the server has no cache", async () => {
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    root().setAttribute("data-phem-state-value", vector.before)
    respond(`<button data-phem-on-click="c1.save">Save</button>`, vector.changes)
    const done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body).state).toBe(vector.before)
  })
})

describe("data-phem-ignore", () => {
  it("keeps the element, its attributes and its content", async () => {
    await mount(`<div id="chart" data-phem-ignore>1</div><button data-phem-on-click="c1.add">Add</button>`)
    const chart = document.getElementById("chart")
    chart.innerHTML = "<canvas></canvas>"
    chart.className = "ready"
    respond(`<div id="chart" data-phem-ignore>2</div><button data-phem-on-click="c1.add">Add</button><p id="added">Added</p>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("chart")).toBe(chart)
    expect(chart.outerHTML).toBe('<div id="chart" data-phem-ignore="" class="ready"><canvas></canvas></div>')
    expect(document.getElementById("added")).not.toBeNull()
  })

  it("removes the element when the server render does not have it", async () => {
    await mount(`<div id="chart" data-phem-ignore>1</div><button data-phem-on-click="c1.remove">Remove</button>`)
    respond(`<button data-phem-on-click="c1.remove">Remove</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("chart")).toBeNull()
  })
})

describe("the components with the fiber id on their root element", () => {
  const row = (id, text, cls = "") => `<tr data-phem-c="${id}" class="${cls}"><td>${text}</td></tr>`
  const table = (rows) => `<table><tbody>${rows}</tbody></table><button data-phem-on-click="c1.change">Change</button>`

  async function event(streams) {
    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ streams, changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
    const done = nextDone()
    click("button")
    await done
  }

  it("keeps the element of a component that the server did not render again, also in a table", async () => {
    await mount(table(row("r1", "One") + row("r2", "Two")))
    const [one, two] = document.querySelectorAll("tr")
    one.hidden = true
    one.querySelector("td").textContent = "Changed by JS"

    // The page renders again, with a placeholder for "r1" and a new "r3" before "r2".
    respond(table('<template data-phem-keep="r1"></template>' + row("r3", "Three") + row("r2", "Two 2")))
    const done = nextDone()
    click("button")
    await done

    const rows = [...document.querySelectorAll("tbody > tr")]
    expect(rows.map((tr) => tr.getAttribute("data-phem-c"))).toEqual(["r1", "r3", "r2"])
    expect(rows[0]).toBe(one)
    expect(one.hidden).toBe(true)
    expect(one.textContent).toBe("Changed by JS")
    expect(rows[2]).toBe(two)
    expect(two.textContent).toBe("Two 2")
    expect(document.querySelector("template")).toBeNull()
    expect(document.querySelector('[id^="phem-key:"]')).toBeNull()
  })

  it("keeps the elements of a run of components from the shape of the state before the event", async () => {
    // The page `p` with the rows 1 to 4: the shape has their ids (phem00000000000N) in base64url.
    const id = (n) => `phem00000000000${n}`
    await mount(table([1, 2, 3, 4].map((n) => row(id(n), `Row ${n}`)).join("")))
    const tree = "AAAAAAAA0,AAAAAAAB1,AAAAAAAC1,AAAAAAAD1,AAAAAAAE2,AAAAAAAF1"
    document.getElementById("phem-root").setAttribute("data-phem-state-value", `{\n"_tree":"${tree}"\n}`)
    const before = [...document.querySelectorAll("tbody > tr")]
    before[1].hidden = true

    // Row 4 renders again, and the rows 1 to 3 are one run. The new state does not have them any
    // more: the run is in the state before the event.
    respond(table(`<template data-phem-keep="${id(1)}..${id(3)}"></template>` + row(id(4), "Row 4 changed")), { _tree: '"AAAAAAAA0"' })
    const done = nextDone()
    click("button")
    await done

    const rows = [...document.querySelectorAll("tbody > tr")]
    expect(rows.map((tr) => tr.getAttribute("data-phem-c"))).toEqual([id(1), id(2), id(3), id(4)])
    expect(rows.slice(0, 3)).toEqual(before.slice(0, 3))
    expect(rows[1].hidden).toBe(true)
    expect(rows[3].textContent).toBe("Row 4 changed")
    expect(document.querySelector("template")).toBeNull()
  })

  it("loads the page again when the state does not have a run of components", async () => {
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    const reload = vi.spyOn(PhemController.prototype, "reload").mockImplementation(() => {})
    await mount(table(row("phem000000000001", "Row 1") + row("phem000000000002", "Row 2")))
    respond(table('<template data-phem-keep="phem000000000001..phem000000000002"></template>'))
    const done = nextDone()
    click("button")
    await done

    expect(error).toHaveBeenCalledWith(expect.stringContaining("does not have a run of components"))
    expect(reload).toHaveBeenCalledOnce()
    expect(document.querySelectorAll("tbody > tr")).toHaveLength(2)
    reload.mockRestore()
  })

  it("morphs the attributes of the root element of a component", async () => {
    await mount(table(row("r1", "One", "off")))
    const tr = document.querySelector("tr")

    await event('<turbo-stream action="replace" method="morph" targets="[data-phem-c=r1]"><template><tr data-phem-c="r1" class="on"><td>One</td></tr></template></turbo-stream>')

    expect(document.querySelector("tr")).toBe(tr)
    expect(tr.className).toBe("on")
    expect(tr.hasAttribute("id")).toBe(false)
  })

  it("dispatches phem:done on the root element of the component when the morph removed the element", async () => {
    await mount(`<div data-phem-c="p1"><button data-phem-on-click="p1.go">Go</button></div>`)
    respond(`<div data-phem-c="p1"><p>Gone</p></div>`)
    const done = nextDone()
    click("button")
    const event = await done

    expect(event.target.localName).toBe("div")
    expect(event.target.getAttribute("data-phem-c")).toBe("p1")
  })
})

describe("the morph of a list", () => {
  const rows = (keys) => `<ul>${keys.map((k) => `<li data-phem-key="${k}" data-phem-client="hidden">Row ${k}</li>`).join("")}</ul><button data-phem-on-click="c1.change">Change</button>`

  async function morph(from, to) {
    await mount(rows(from))
    const before = Object.fromEntries([...document.querySelectorAll("li")].map((li) => [li.dataset.phemKey, li]))
    before[from.at(-1)].hidden = true
    respond(rows(to))
    const done = nextDone()
    click("button")
    await done

    return before
  }

  it("keeps the element of an item when an item before it goes away", async () => {
    const before = await morph(["1", "2"], ["2"])
    const [row] = document.querySelectorAll("li")

    expect(row).toBe(before["2"])
    expect(row.hidden).toBe(true)
    expect(row.textContent).toBe("Row 2")
  })

  it("keeps the element of an item when an item comes before it", async () => {
    const before = await morph(["1"], ["0", "1"])
    const [added, row] = document.querySelectorAll("li")

    expect(row).toBe(before["1"])
    expect(row.hidden).toBe(true)
    expect(added.hidden).toBe(false)
    expect(added.textContent).toBe("Row 0")
  })

  it("removes the ids of the keys after the morph", async () => {
    await morph(["1", "2"], ["0", "2", "3"])

    expect(document.querySelectorAll("[id^='phem-key:']")).toHaveLength(0)
    expect([...document.querySelectorAll("li")].map((li) => li.hasAttribute("id"))).toEqual([false, false, false])
  })

  it("keeps an id of the server render", async () => {
    await mount(`<ul><li id="row-1" data-phem-key="1">Row 1</li></ul><button data-phem-on-click="c1.change">Change</button>`)
    respond(`<ul><li id="row-1" data-phem-key="1">Row 1</li><li id="row-2" data-phem-key="2">Row 2</li></ul><button data-phem-on-click="c1.change">Change</button>`)
    const done = nextDone()
    click("button")
    await done

    expect([...document.querySelectorAll("li")].map((li) => li.id)).toEqual(["row-1", "row-2"])
  })

  it("morphs two lists with the same keys by position", async () => {
    const lists = (a, b) => `<ul>${a.map((k) => `<li data-phem-key="${k}">A${k}</li>`).join("")}</ul><ul>${b.map((k) => `<li data-phem-key="${k}">B${k}</li>`).join("")}</ul><button data-phem-on-click="c1.change">Change</button>`
    await mount(lists(["1", "2"], ["1", "2"]))
    respond(lists(["2"], ["1", "2", "3"]))
    const done = nextDone()
    click("button")
    await done

    expect([...document.querySelectorAll("ul")].map((ul) => ul.textContent)).toEqual(["A2", "B1B2B3"])
  })
})

describe("the morph of the bound fields", () => {
  // Two labels with a FieldError component each. The fiber id of a component comes from its index:
  // when the error of the name goes away, the error of the phone gets the fiber id of the error of
  // the name (phemE0).
  const form = (nameError, phoneError, phone = "") => `<form>
      <label><span>Name</span><input data-phem-bind="c1.name" value="Ada">${nameError ? '<p data-phem-c="phemE0">Name error</p>' : ""}</label>
      <label><span>Phone</span><span class="wrapper"><span>+53</span><input data-phem-bind="c1.phone" value="${phone}"></span>${phoneError ? `<p data-phem-c="${nameError ? "phemE1" : "phemE0"}">Phone error</p>` : "<span>Hint</span>"}</label>
    </form><button data-phem-on-click="c1.check">Check</button>`

  // The answer of the server comes when the test resolves it.
  function respondLater(markup) {
    let resolve
    fetch.mockImplementationOnce(() => new Promise((r) => (resolve = r)))
    return () => resolve(new Response(JSON.stringify({ streams: `<turbo-stream action="replace" method="morph" targets="[data-phem-c=c1]"><template><phem-c data-phem-c="c1">${markup}</phem-c></template></turbo-stream>`, changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
  }

  it("keeps the focused field and its value when a component before it goes away", async () => {
    await mount(form(true, true))
    const phone = document.querySelector('[data-phem-bind="c1.phone"]')
    const labels = [...document.querySelectorAll("label")]
    phone.focus()

    const answer = respondLater(form(false, true))
    const done = nextDone()
    click("button")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    // The user types while the request runs.
    phone.focus()
    phone.value = "555"
    answer()
    await done

    expect(document.querySelector('[data-phem-bind="c1.phone"]')).toBe(phone)
    expect([...document.querySelectorAll("label")]).toEqual(labels)
    expect(document.activeElement).toBe(phone)
    expect(phone.value).toBe("555")
    expect([...document.querySelectorAll("p")].map((p) => p.textContent)).toEqual(["Phone error"])
    expect(document.querySelector('[id^="phem-key:"]')).toBeNull()
  })

  it("keeps the focused field when the user submits the form while a live event runs", async () => {
    const live = (markup) => markup.replace("<form>", '<form data-phem-on-submit="c1.save">').replaceAll("<input ", '<input data-phem-live data-phem-debounce="blur" ')
    await mount(live(form(true, true)))
    const root = document.getElementById("phem-root")
    root.setAttribute("data-action", `${root.getAttribute("data-action")} input->phem#dispatch keydown->phem#dispatch`)
    await new Promise((resolve) => setTimeout(resolve, 0))
    const name = document.querySelector('[data-phem-bind="c1.name"]')
    const phone = document.querySelector('[data-phem-bind="c1.phone"]')

    // The blur of the name sends its live event. The user types a phone and submits the form
    // before its answer comes.
    const answer = respondLater(live(form(false, true)))
    name.focus()
    name.value = "Grace"
    name.dispatchEvent(new Event("input", { bubbles: true }))
    phone.focus()
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))
    phone.value = "555"
    respond(live(form(false, true, "555")))
    document.querySelector("form").requestSubmit()
    const done = nextDone()
    answer()
    await done
    await nextDone()

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(fetch.mock.calls[1][1].body).toContain("555")
    expect(document.querySelector('[data-phem-bind="c1.phone"]')).toBe(phone)
    expect(document.activeElement).toBe(phone)
    expect(phone.value).toBe("555")
  })

  it("matches each checkbox of a list by its value", async () => {
    const boxes = (values) => `<div>${values.map((v) => `<label>${v === "a" ? '<p data-phem-c="phemE0">!</p>' : ""}<input type="checkbox" data-phem-bind="c1.tags" data-phem-bind-list value="${v}"></label>`).join("")}</div><button data-phem-on-click="c1.check">Check</button>`
    await mount(boxes(["a", "b"]))
    const b = document.querySelector('[value="b"]')
    respond(boxes(["b"]).replace("<label>", '<label><p data-phem-c="phemE0">!</p>'))
    const done = nextDone()
    click("button")
    await done

    expect(document.querySelector('[value="b"]')).toBe(b)
  })
})

describe("the file inputs", () => {
  const form = (attributes = "") => `<form data-phem-on-submit="c1.save" ${attributes}>
      <input type="file" name="photo"><input name="title" value="Notes"><button type="submit">Save</button>
    </form>`

  // Selects files in a file input, as the user does: a new FileList each time, as Chrome and
  // Safari do, or the same FileList with other files (`sameList`), as Firefox does. jsdom has no
  // API for it, so the files go into the FileList with the jsdom internals.
  const { implForWrapper } = createRequire(import.meta.url)("jsdom/lib/generated/idl/utils.js")
  function pick(input, names, { sameList = false } = {}) {
    const files = sameList ? input.files : Object.assign(document.createElement("input"), { type: "file" }).files
    const list = implForWrapper(files)
    list.length = 0
    list.push(...names.map((name) => implForWrapper(new File([name], name))))
    input.files = files
    input.dispatchEvent(new Event("change", { bubbles: true }))
  }

  // An event with files goes with XHR (see post()): each request gets the next answer. Resolves a
  // request only when the test calls the function that it gives to `hold`. Returns the bodies of
  // the requests.
  function stubXhr(answers, hold = null) {
    const bodies = []
    vi.stubGlobal(
      "XMLHttpRequest",
      class extends EventTarget {
        upload = new EventTarget()
        open() {}
        setRequestHeader() {}
        getAllResponseHeaders() {
          return "content-type: application/json"
        }
        send(body) {
          bodies.push(body)
          Object.assign(this, { status: 200, statusText: "OK", response: JSON.stringify(answers.shift()) })
          const load = () => this.dispatchEvent(new Event("load"))
          if (hold) hold(load)
          else setTimeout(load, 0)
        }
      },
    )

    return bodies
  }

  // Whether the guard asks before the user leaves the page (`beforeunload`).
  function asksBeforeUnload() {
    const event = new Event("beforeunload", { cancelable: true })
    window.dispatchEvent(event)
    return event.defaultPrevented
  }

  it("empties the file inputs that sent their files when the handler takes them", async () => {
    await mount(form())
    const input = document.querySelector("input[type=file]")
    pick(input, ["a.txt"])
    stubXhr([answer(form(), {}, { clearFiles: true })])
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(document.querySelector("input[type=file]")).toBe(input)
    expect(input.files.length).toBe(0)
    expect(document.querySelector("form").classList.contains("phem-dirty")).toBe(false)
  })

  it("keeps the files when the handler does not take them", async () => {
    await mount(form())
    const input = document.querySelector("input[type=file]")
    pick(input, ["a.txt"])
    stubXhr([answer(form())])
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(input.files.length).toBe(1)
    expect(input.classList.contains("phem-dirty")).toBe(true)
    expect(document.querySelector("form").classList.contains("phem-dirty")).toBe(true)
  })

  it("keeps the files that the user selects while the request runs", async () => {
    await mount(form('data-phem-disable="false"'))
    const input = document.querySelector("input[type=file]")
    pick(input, ["a.txt"])
    let load
    stubXhr([answer(form('data-phem-disable="false"'), {}, { clearFiles: true })], (l) => (load = l))
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await vi.waitFor(() => expect(load).toBeDefined())
    pick(input, ["b.txt"])
    load()
    await done

    expect([...input.files].map((f) => f.name)).toEqual(["b.txt"])
  })

  it("keeps the files that the user selects while the request runs in the same FileList", async () => {
    await mount(form('data-phem-disable="false"'))
    const input = document.querySelector("input[type=file]")
    pick(input, ["a.txt"])
    let load
    stubXhr([answer(form('data-phem-disable="false"'), {}, { clearFiles: true })], (l) => (load = l))
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await vi.waitFor(() => expect(load).toBeDefined())
    pick(input, ["b.txt"], { sameList: true })
    load()
    await done

    expect([...input.files].map((f) => f.name)).toEqual(["b.txt"])
  })

  it("empties the file inputs when the event sends stale components", async () => {
    await mount(form())
    const input = document.querySelector("input[type=file]")
    application.getControllerForElementAndIdentifier(document.getElementById("phem-root"), "phem").stale.add("c9")
    pick(input, ["a.txt"])
    stubXhr([answer(form(), {}, { clearFiles: true })])
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(input.files.length).toBe(0)
  })

  it("does not make the page dirty for a file input that a submit does not send", async () => {
    await mount(`<form data-phem-guard><input type="file"></form><input type="file" name="avatar">`, "data-phem-guard")
    const [unnamed, outside] = document.querySelectorAll("input[type=file]")
    pick(unnamed, ["a.txt"])
    pick(outside, ["b.txt"])

    expect(document.querySelector(".phem-dirty")).toBeNull()
    expect(document.getElementById("phem-root").classList.contains("phem-dirty")).toBe(false)
  })

  it("makes its form dirty while it has files, and the guard asks before the user leaves", async () => {
    await mount(form("data-phem-guard"))
    const input = document.querySelector("input[type=file]")
    expect(asksBeforeUnload()).toBe(false)

    pick(input, ["a.txt"])
    expect(input.classList.contains("phem-dirty")).toBe(true)
    expect(document.querySelector("form").classList.contains("phem-dirty")).toBe(true)
    expect(asksBeforeUnload()).toBe(true)

    input.value = ""
    input.dispatchEvent(new Event("change", { bubbles: true }))
    expect(document.querySelector("form").classList.contains("phem-dirty")).toBe(false)
    expect(asksBeforeUnload()).toBe(false)
  })

  it("sends the files as parts, and not the file inputs in the JSON", async () => {
    await mount(form())
    pick(document.querySelector("input[type=file]"), ["a.txt"])
    const bodies = stubXhr([answer(form())])
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(bodies[0].get("photo").name).toBe("a.txt")
    expect(JSON.parse(bodies[0].get("_phem")).event).not.toHaveProperty("fileInputs")
    expect(JSON.parse(bodies[0].get("_phem")).event).not.toHaveProperty("files")
  })

  it("keeps the form dirty while the submit locks it and its files upload", async () => {
    await mount(form("data-phem-guard"))
    const input = document.querySelector("input[type=file]")
    pick(input, ["a.txt"])
    let load
    stubXhr([answer(form("data-phem-guard"))], (l) => (load = l))
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await vi.waitFor(() => expect(load).toBeDefined())

    expect(input.disabled).toBe(true)
    expect(asksBeforeUnload()).toBe(true)

    load()
    await done
  })

  it("unlocks the form before phem:done, so a listener can submit it again with its files", async () => {
    await mount(form())
    const input = document.querySelector("input[type=file]")
    pick(input, ["a.txt"])
    const bodies = stubXhr([answer(form()), answer(form())])
    const locked = []
    const again = new Promise((resolve) => {
      window.addEventListener(
        "phem:done",
        () => {
          locked.push(input.disabled, document.querySelector("[name=title]").readOnly, document.querySelector("[type=submit]").disabled)
          pick(input, ["b.txt"])
          window.addEventListener("phem:done", resolve, { once: true })
          document.querySelector("form").requestSubmit()
        },
        { once: true },
      )
    })
    document.querySelector("form").requestSubmit()
    await again

    expect(locked).toEqual([false, false, false])
    expect(bodies.map((body) => body.get("photo").name)).toEqual(["a.txt", "b.txt"])
  })

  it("empties only the file inputs that sent their files", async () => {
    const markup = `<form data-phem-on-submit="c1.save">
        <input type="file" name="photo" id="sent"><input type="file" id="unnamed"><input type="file" name="off" id="off" disabled>
      </form><input type="file" name="avatar" id="outside">`
    await mount(markup)
    for (const id of ["sent", "unnamed", "off", "outside"]) pick(document.getElementById(id), [`${id}.txt`])
    stubXhr([answer(markup, {}, { clearFiles: true })])
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(document.getElementById("sent").files.length).toBe(0)
    for (const id of ["unnamed", "off", "outside"]) expect(document.getElementById(id).files.length, id).toBe(1)
  })

  it("asks before a Turbo visit while a file input has files", async () => {
    await mount(form('data-phem-guard="Drop the photos?"'))
    const confirm = vi.fn(() => false)
    vi.stubGlobal("confirm", confirm)
    const visit = () => {
      const event = new CustomEvent("turbo:before-visit", { cancelable: true, detail: { url: "/other" } })
      document.dispatchEvent(event)
      return event.defaultPrevented
    }

    expect(visit()).toBe(false)
    expect(confirm).not.toHaveBeenCalled()

    pick(document.querySelector("input[type=file]"), ["a.txt"])
    expect(visit()).toBe(true)
    expect(confirm).toHaveBeenCalledWith("Drop the photos?")
  })
})

describe("the fields of a form with no phem:bind", () => {
  // The markup of a Symfony form. A PasswordType renders no value. `touched` lists the fields
  // that the server marks.
  const form = ({ email = "", city = "", error = "", touched = [], extra = "" } = {}) => {
    const mark = (name) => (touched.includes(name) ? " data-phem-touched" : "")
    return `<form data-phem-on-change="c1.validate" data-phem-on-submit="c1.save">
      <input type="email" name="a[email]" value="${email}"${mark("email")}>${error ? `<p>${error}</p>` : ""}
      <input type="password" name="a[password]"${mark("password")}>
      <input type="text" name="a[city]" value="${city}"${mark("city")}>
      <input type="checkbox" name="a[tags][]" value="x"${mark("tags")}>
      <button type="button" data-phem-on-click="c1.addPhone">Add a phone</button>
      ${extra}
    </form>`
  }

  async function mountForm(markup) {
    await mount(markup, 'data-phem-debounce-value="0"')
    const root = document.getElementById("phem-root")
    root.setAttribute("data-action", `${root.getAttribute("data-action")} input->phem#dispatch keydown->phem#dispatch`)
    await new Promise((resolve) => setTimeout(resolve, 0))
  }

  function respondLater(markup) {
    let resolve
    fetch.mockImplementationOnce(() => new Promise((r) => (resolve = r)))
    return () => resolve(new Response(JSON.stringify({ streams: `<turbo-stream action="replace" method="morph" targets="[data-phem-c=c1]"><template><phem-c data-phem-c="c1">${markup}</phem-c></template></turbo-stream>`, changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
  }

  const field = (name) => document.querySelector(`[name="a[${name}]"]`)
  const body = (call = 0) => JSON.parse(fetch.mock.calls[call][1].body)
  const sent = (call = 0) => JSON.parse(fetch.mock.calls[call][1].body).event

  function type(input, value) {
    input.focus()
    input.value = value
    input.dispatchEvent(new Event("input", { bubbles: true }))
  }

  it("sends each key of a text field to the onChange of the form, with the fields and the touched fields", async () => {
    await mountForm(form({ touched: ["city"] }))
    respond(form({ email: "ada", error: "Not valid.", touched: ["email", "city"] }))
    const done = nextDone()
    type(field("email"), "ada")
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent()).toMatchObject({ type: "input", form: [["a[email]", "ada"], ["a[password]", ""], ["a[city]", ""]], touched: ["a[email]", "a[city]"] })
    expect(sent().files).toBeUndefined()
    // The change event of the text field when it loses the focus sends nothing.
    field("email").dispatchEvent(new Event("change", { bubbles: true }))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("sends the event of a form with phem:debounce blur when the user leaves a field", async () => {
    await mountForm(form().replace("<form ", '<form data-phem-debounce="blur" '))
    respond(form({ email: "ada", touched: ["email"] }))
    const done = nextDone()
    type(field("email"), "ad")
    type(field("email"), "ada")
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(fetch).not.toHaveBeenCalled()

    field("city").focus()
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent()).toMatchObject({ type: "input", touched: ["a[email]"] })
  })

  it("sends one event for a checkbox", async () => {
    await mountForm(form())
    respond(form({ touched: ["tags"] }))
    const done = nextDone()
    document.querySelector('[name="a[tags][]"]').click()
    await done
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent()).toMatchObject({ type: "change", touched: ["a[tags][]"] })
  })

  it("sends the fields of the form with the click of a button in it", async () => {
    await mountForm(form())
    field("email").value = "ada@example.com"
    respond(form({ email: "ada@example.com" }))
    const done = nextDone()
    document.querySelector("button").click()
    await done

    expect(sent()).toMatchObject({ type: "click", form: [["a[email]", "ada@example.com"], ["a[password]", ""], ["a[city]", ""]], touched: [] })
  })

  it("sends no touched fields with a submit", async () => {
    await mountForm(form({ touched: ["email"] }))
    respond(form())
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(sent().type).toBe("submit")
    expect(sent().touched).toBeUndefined()
  })

  it("keeps the values that the user changes while the request runs, and their marks", async () => {
    await mountForm(form())
    const answer = respondLater(form({ email: "ada", error: "Not valid.", touched: ["email"] }))
    const done = nextDone()
    type(field("email"), "ada")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    // The user goes on in the next field. Its event waits for the first one.
    field("city").value = "Paris"
    field("city").setAttribute("data-phem-touched", "")
    field("email").value = "ada@example.com"
    respond(form({ email: "ada@example.com", city: "Paris", touched: ["email", "city"] }))
    answer()
    await done

    expect(field("email").value).toBe("ada@example.com")
    expect(field("city").value).toBe("Paris")
    expect(field("city").hasAttribute("data-phem-touched")).toBe(true)
    expect(document.querySelector("p").textContent).toBe("Not valid.")
  })

  it("keeps the value of the focused text field that the server changes, but not after a submit", async () => {
    await mountForm(form())
    respond(form({ city: "New", touched: ["city"] }))
    let done = nextDone()
    type(field("city"), "New ")
    await done

    // The form trims the value: the user is still typing.
    expect(field("city").value).toBe("New ")

    respond(form())
    done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    // A new form.
    expect(field("city").value).toBe("")
  })

  it("sets the value that the server changes in a field that the user did not change", async () => {
    await mountForm(form())
    respond(form({ email: "ada", city: "Paris", touched: ["email"] }))
    const done = nextDone()
    type(field("email"), "ada")
    await done

    expect(field("city").value).toBe("Paris")
  })

  it("keeps the value of a touched password that the server renders with no value", async () => {
    await mountForm(form())
    respond(form({ city: "Paris", touched: ["city", "password"] }))
    let done = nextDone()
    field("password").value = "secret123"
    field("password").setAttribute("data-phem-touched", "")
    type(field("city"), "Paris")
    await done

    expect(field("password").value).toBe("secret123")

    // A new form has no mark: the password goes.
    respond(form())
    done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(field("password").value).toBe("")
  })

  it("sets the value of the server render in a focused controlled input", async () => {
    // A field with no mark of the server: not a field of a Symfony form with live validation.
    await mountForm(form())
    respond(form({ city: "NEW" }))
    const done = nextDone()
    type(field("city"), "new")
    await done

    expect(field("city").value).toBe("NEW")
  })

  it("sends the newest fields when the event leaves after a wait", async () => {
    await mountForm(form())
    const answer = respondLater(form({ email: "ada", touched: ["email"] }))
    let done = nextDone()
    type(field("email"), "ada")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    // The click waits for the first event, and the user types in the city.
    document.querySelector("button").click()
    field("city").value = "Paris"
    respond(form({ email: "ada", city: "Paris", touched: ["email"] }))
    answer()
    await done
    done = nextDone()
    await done

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(sent(1)).toMatchObject({ type: "click", form: [["a[email]", "ada"], ["a[password]", ""], ["a[city]", "Paris"]] })
    expect(field("city").value).toBe("Paris")
  })

  it("lets a phem:live field send its own events in a form with onChange", async () => {
    await mountForm(form({ extra: '<input name="a[nick]" data-phem-bind="c1.nick" data-phem-live value="">' }))
    respond(form())
    const done = nextDone()
    type(field("nick"), "ada")
    await done

    expect(body().handler).toBeNull()
    expect(body().bound).toEqual({ "c1.nick": "ada" })
  })

  it("does not touch a field when the change is cancelled", async () => {
    await mountForm(form())
    const box = document.querySelector('[name="a[tags][]"]')
    const cancel = (e) => e.preventDefault()
    window.addEventListener("phem:before-queue", cancel)
    box.click()
    window.removeEventListener("phem:before-queue", cancel)

    expect(box.checked).toBe(false)
    expect(box.hasAttribute("data-phem-touched")).toBe(false)
    expect(fetch).not.toHaveBeenCalled()
  })

  it("sends no fields with a key of a field in the form", async () => {
    await mountForm(form({ extra: '<input name="a[q]" data-phem-on-keydown="c1.press">' }))
    respond(form())
    const done = nextDone()
    field("q").dispatchEvent(new KeyboardEvent("keydown", { key: "a", bubbles: true }))
    await done

    expect(sent()).toMatchObject({ type: "keydown", form: null })
    expect(sent().touched).toBeUndefined()
  })
})

describe("phem:queue, phem:send, phem:receive and phem:done", () => {
  it("dispatches them on the element that sent the event, phem:receive before the morph and phem:done after it", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    const events = []
    const button = document.getElementById("star")
    button.addEventListener("phem:queue", (e) => events.push(["queue", e.detail, fetch.mock.calls.length]))
    button.addEventListener("phem:send", (e) => events.push(["send", e.detail, fetch.mock.calls.length]))
    button.addEventListener("phem:receive", (e) => events.push(["receive", e.detail, e.target.textContent]))
    button.addEventListener("phem:done", (e) => events.push(["done", e.detail, e.target.textContent]))
    respond(`<button id="star" data-phem-on-click="c1.star">Unstar</button>`)
    const done = nextDone()
    click("#star")
    await done

    expect(events).toEqual([
      ["queue", { handler: "c1.star", id: 1 }, 0],
      ["send", { handler: "c1.star", ids: [1] }, 0],
      ["receive", { handler: "c1.star", ids: [1] }, "Star"],
      ["done", { handler: "c1.star", ids: [1], ok: true, status: 200 }, "Unstar"],
    ])
  })

  it("dispatches phem:queue in the dispatch of the DOM event, also when the event waits for another event", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    const queued = []
    document.getElementById("star").addEventListener("phem:queue", () => queued.push(fetch.mock.calls.length))
    respond(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    respond(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    const first = nextDone()
    click("#star")
    click("#star")

    expect(queued).toEqual([0, 0])
    await first
    await nextDone()
  })

  it("gives the ids of the DOM events that a debounced event carries", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star" data-phem-debounce="20">Star</button>`)
    const queued = []
    document.getElementById("star").addEventListener("phem:queue", (e) => queued.push(e.detail.id))
    respond(`<button id="star" data-phem-on-click="c1.star" data-phem-debounce="20">Star</button>`)
    const done = nextDone()
    click("#star")
    click("#star")

    expect(queued).toEqual([1, 2])
    expect((await done).detail.ids).toEqual([1, 2])
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("dispatches phem:done with no status for a live field with nothing to send", async () => {
    await mount(`<input id="on" type="checkbox" data-phem-bind="c1.on" data-phem-live>`)
    const done = nextDone()
    document.getElementById("on").dispatchEvent(new Event("change", { bubbles: true }))

    expect((await done).detail).toEqual({ handler: "c1.on", ids: [1], ok: true, status: null })
    expect(fetch).not.toHaveBeenCalled()
  })

  it("dispatches phem:done with ok false when the render of the answer fails", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star">Star</button>`, "data-phem-transition")
    vi.stubGlobal("matchMedia", () => ({ matches: false }))
    document.startViewTransition = ({ update }) => ({ updateCallbackDone: Promise.resolve().then(update) })
    vi.spyOn(console, "error").mockImplementation(() => {})
    const fail = (e) => (e.detail.render = () => Promise.reject(new Error("render")))
    document.addEventListener("turbo:before-stream-render", fail, { once: true })
    respond(`<button id="star" data-phem-on-click="c1.star">Unstar</button>`)
    const done = nextDone()
    click("#star")

    try {
      expect((await done).detail).toEqual({ handler: "c1.star", ids: [1], ok: false, status: 200 })
    } finally {
      delete document.startViewTransition
    }
  })

  it("dispatches phem:done on the root of the component when the morph removed the element", async () => {
    await mount(`<ul><li id="row-1"><button data-phem-on-click="c1.remove">Delete</button></li></ul>`)
    respond(`<ul></ul>`)
    const done = nextDone()
    click("#row-1 button")
    const event = await done

    expect(event.target.getAttribute("data-phem-c")).toBe("c1")
    expect(event.detail).toEqual({ handler: "c1.remove", ids: [1], ok: true, status: 200 })
  })

  it("lets a Stimulus action change the page before the event, and undo it on a failure", async () => {
    await mount(`<ul><li id="row-1" data-phem-client="hidden"><button data-phem-on-click="c1.remove">Delete</button></li></ul>`)
    const row = document.getElementById("row-1")
    const button = row.querySelector("button")
    // As `data-action="optimistic#hide phem:done->optimistic#show"`: the listener of the button
    // runs before the listener of the root element.
    button.addEventListener("click", () => (row.hidden = true))
    button.addEventListener("phem:done", () => (row.hidden = false))
    let hiddenOnSend = null
    button.addEventListener("phem:send", () => (hiddenOnSend = row.hidden))
    let received = false
    button.addEventListener("phem:receive", () => (received = true))
    vi.spyOn(console, "error").mockImplementation(() => {})
    window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    const done = nextDone()
    button.click()
    const event = await done

    expect(hiddenOnSend).toBe(true)
    expect(received).toBe(false)
    expect(event.detail).toEqual({ handler: "c1.remove", ids: [1], ok: false, status: 500 })
    expect(row.hidden).toBe(false)
  })

  it("dispatches phem:done with a 429 for a rate limit", async () => {
    await mount(`<button data-phem-on-click="c1.star">Star</button>`)
    vi.spyOn(console, "warn").mockImplementation(() => {})
    fetch.mockResolvedValueOnce(new Response("", { status: 429 }))
    const done = nextDone()
    click("button")

    expect((await done).detail).toEqual({ handler: "c1.star", ids: [1], ok: false, status: 429 })
  })

  it("dispatches phem:done with no status for a network error", async () => {
    await mount(`<button data-phem-on-click="c1.star">Star</button>`, 'data-phem-retries-value="0"')
    vi.spyOn(console, "error").mockImplementation(() => {})
    window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
    fetch.mockRejectedValueOnce(new TypeError("Failed to fetch"))
    const done = nextDone()
    click("button")

    expect((await done).detail).toEqual({ handler: "c1.star", ids: [1], ok: false, status: null })
  })

  it("dispatches phem:done with the URL for a redirect, before the visit", async () => {
    await mount(`<button data-phem-on-click="c1.add">Add</button>`)
    const events = []
    for (const name of ["send", "receive", "done"]) window.addEventListener(`phem:${name}`, (e) => events.push([name, e.detail, Turbo.visit.mock.calls.length]))
    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ redirect: "/bag" }), { status: 200 }))
    const done = nextDone()
    click("button")
    await done

    expect(events).toEqual([
      ["send", { handler: "c1.add", ids: [1] }, 0],
      ["done", { handler: "c1.add", ids: [1], ok: true, status: 200, redirect: "http://localhost:3000/bag" }, 0],
    ])
    expect(Turbo.visit).toHaveBeenCalledExactlyOnceWith("http://localhost:3000/bag")
  })

  it("dispatches phem:done with ok false for a redirect that is not http(s)", async () => {
    await mount(`<button data-phem-on-click="c1.add">Add</button>`)
    vi.spyOn(console, "error").mockImplementation(() => {})
    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ redirect: "javascript:alert(1)" }), { status: 200 }))
    const done = nextDone()
    click("button")

    expect((await done).detail).toEqual({ handler: "c1.add", ids: [1], ok: false, status: 200 })
    expect(Turbo.visit).not.toHaveBeenCalled()
  })

  it("dispatches phem:done with a 409 before it loads the page again", async () => {
    await mount(`<button data-phem-on-click="c1.star">Star</button>`)
    fetch.mockResolvedValueOnce(new Response("", { status: 409 }))
    const done = nextDone()
    click("button")

    expect((await done).detail).toEqual({ handler: "c1.star", ids: [1], ok: false, status: 409 })
    expect(Turbo.visit).toHaveBeenCalledExactlyOnceWith(window.location.href, { action: "replace" })
  })
})

describe("phem:before-queue", () => {
  it("dispatches it on the element of the handler, and a cancel stops the event", async () => {
    await mount(`<input id="star" type="checkbox" data-phem-on-click="c1.star">`)
    const before = []
    const queued = vi.fn()
    const box = document.getElementById("star")
    box.addEventListener("phem:before-queue", (e) => {
      before.push(e.detail)
      e.preventDefault()
    })
    box.addEventListener("phem:queue", queued)

    click("#star")
    await new Promise((resolve) => setTimeout(resolve, 0))

    // The default action of the click does not run: the box stays unchecked.
    expect(before).toEqual([{ handler: "c1.star", type: "click", resume: expect.any(Function), signal: expect.any(AbortSignal) }])
    expect(box.checked).toBe(false)
    expect(queued).not.toHaveBeenCalled()
    expect(fetch).not.toHaveBeenCalled()
  })

  it("checks the radio button of the server render again on a cancel", async () => {
    await mount(`<form>
        <input id="small" type="radio" name="size" value="s" data-phem-bind="size" data-phem-on-change="c1.size" checked>
        <input id="large" type="radio" name="size" value="l" data-phem-bind="size" data-phem-on-change="c1.size">
      </form>`)
    document.getElementById("phem-root").addEventListener("phem:before-queue", (e) => e.preventDefault())
    click("#large")

    expect(document.getElementById("small").checked).toBe(true)
    expect(document.getElementById("large").checked).toBe(false)
    expect(document.getElementById("large").classList.contains("phem-dirty")).toBe(false)
    expect(fetch).not.toHaveBeenCalled()
  })

  it("sends the cancelled event later with resume(), with the values of the DOM event", async () => {
    await mount(`<input id="star" type="checkbox" data-phem-on-click="c1.star">`)
    let resume
    const before = vi.fn((e) => {
      e.preventDefault()
      resume = e.detail.resume
    })
    document.getElementById("star").addEventListener("phem:before-queue", before)
    click("#star")
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(fetch).not.toHaveBeenCalled()

    respond(`<input id="star" type="checkbox" data-phem-on-click="c1.star" checked>`)
    const done = nextDone()
    resume()
    resume()
    await done

    expect(before).toHaveBeenCalledTimes(1)
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ handler: "c1.star", event: { type: "click", checked: true } })
  })

  it("sends nothing with resume() when the event was not cancelled", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    let resume
    document.getElementById("star").addEventListener("phem:before-queue", (e) => (resume = e.detail.resume))
    respond(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    const done = nextDone()
    click("#star")
    await done

    resume()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("undoes the cancel with resume() in the dispatch", async () => {
    await mount(`<input id="star" type="checkbox" data-phem-on-click="c1.star">`)
    document.getElementById("star").addEventListener("phem:before-queue", (e) => {
      e.preventDefault()
      e.detail.resume()
    })
    respond(`<input id="star" type="checkbox" data-phem-on-click="c1.star" checked>`)
    const done = nextDone()
    click("#star")

    // The default action of the click runs: the box stays checked.
    expect(document.getElementById("star").checked).toBe(true)
    await done
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ event: { checked: true } })
  })

  it("sends the fields that a listener of phem:before-queue changed", async () => {
    const form = `<form data-phem-on-submit="c1.save"><input type="hidden" name="at" value=""><button id="save">Save</button></form>`
    await mount(form)
    document.getElementById("phem-root").addEventListener("phem:before-queue", () => (document.querySelector('[name="at"]').value = "now"))
    respond(form)
    const done = nextDone()
    click("#save")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body).event.form).toEqual([["at", "now"]])
  })

  it("drops a held event when a morph changes its element, and aborts its signal", async () => {
    const markup = `<button id="star" data-phem-on-click="c1.star">Star</button><button id="other" data-phem-on-click="c1.other">Other</button>`
    await mount(markup)
    let detail
    document.getElementById("star").addEventListener("phem:before-queue", (e) => {
      e.preventDefault()
      detail = e.detail
    })
    click("#star")
    expect(detail.signal.aborted).toBe(false)

    // Another event renders the component again: its fiber id can belong to another component now.
    respond(markup)
    const done = nextDone()
    click("#other")
    await done
    detail.resume()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(detail.signal.aborted).toBe(true)
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("drops a held event when a stream removes its element", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star">Star</button><button id="other" data-phem-on-click="c1.other">Other</button>`)
    let detail
    document.getElementById("star").addEventListener("phem:before-queue", (e) => {
      e.preventDefault()
      detail = e.detail
    })
    click("#star")

    respond("", {}, { streams: `<turbo-stream action="remove" target="star"></turbo-stream>` })
    const done = nextDone()
    click("#other")
    await done
    await new Promise((resolve) => requestAnimationFrame(resolve))

    expect(document.getElementById("star")).toBeNull()
    expect(detail.signal.aborted).toBe(true)
  })

  it("sends nothing with resume() when the element left the page", async () => {
    await mount(`<button id="star" data-phem-on-click="c1.star">Star</button>`)
    let resume
    document.getElementById("star").addEventListener("phem:before-queue", (e) => {
      e.preventDefault()
      resume = e.detail.resume
    })
    click("#star")
    document.getElementById("star").remove()

    resume()
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(fetch).not.toHaveBeenCalled()
  })
})

describe("<Suspense>", () => {
  // The JSON body of a call of fetch().
  const sent = (call) => JSON.parse(fetch.mock.calls[call][1].body)

  it("has no events when the stream of the page failed", async () => {
    const error = vi.spyOn(console, "error").mockImplementation(() => {})
    document.body.innerHTML = `
      <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch" data-phem-streaming data-phem-loading-delay-value="0">
        <phem-c data-phem-c="c1">
          <phem-suspense data-phem-c="b1" data-phem-load="b1.load"><p>Loading…</p></phem-suspense>
          <button data-phem-on-click="c1.star">Star</button>
        </phem-c>
      </div>
      <template data-phem-root-state data-phem-stream-error></template>`
    await new Promise((resolve) => setTimeout(resolve, 0))
    click("button")
    await new Promise((resolve) => setTimeout(resolve, 0))

    expect(fetch).not.toHaveBeenCalled()
    expect(error).toHaveBeenCalledWith("[phem] the stream of the page failed: the page has no events")
    expect(document.querySelector("template[data-phem-root-state]")).toBeNull()
    expect(document.getElementById("phem-root").hasAttribute("data-phem-streaming")).toBe(false)
    error.mockRestore()
  })

  it("runs the scripts of a boundary that the controller puts in the page", async () => {
    document.body.innerHTML = `
      <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch" data-phem-streaming data-phem-loading-delay-value="0">
        <phem-c data-phem-c="c1"><phem-suspense data-phem-c="b1" data-phem-load="b1.load"><p>Loading…</p></phem-suspense></phem-c>
      </div>
      <template data-phem-for="b1"><div data-phem-c="b1"><p id="chart">Chart</p><script>window.boundaryScript++</script></div></template>
      <template data-phem-root-state data-phem-state-value="{}" data-phem-signature-value="s9"></template>`
    // A script of a template does not run when it goes in the page: the page gets a copy, which runs.
    const parsed = document.querySelector("template[data-phem-for]").content.querySelector("script")
    await new Promise((resolve) => setTimeout(resolve, 0))

    const script = document.querySelector("#phem-root script")
    expect(document.getElementById("chart")).not.toBeNull()
    expect(script).not.toBe(parsed)
    expect(script.textContent).toBe("window.boundaryScript++")
    expect(fetch).not.toHaveBeenCalled()
  })

  it("finishes a streamed page: the chunks that no script swapped, and the state at its end", async () => {
    respond(`<p id="report" data-phem-c="b1">Rows 3</p><p id="chart">Chart</p>`)
    const done = nextDone()
    // innerHTML does not run scripts, as a CSP that blocks them.
    document.body.innerHTML = `
      <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch" data-phem-streaming data-phem-loading-delay-value="0">
        <phem-c data-phem-c="c1">
          <phem-suspense data-phem-c="b1" data-phem-load="b1.load"><p>Loading the report…</p></phem-suspense>
          <phem-suspense data-phem-c="b2" data-phem-load="b2.load"><p>Loading the chart…</p></phem-suspense>
        </phem-c>
      </div>
      <template data-phem-for="b1"><p id="report" data-phem-c="b1">Rows 3</p></template><script>swap()</script>
      <template data-phem-root-state data-phem-state-value='{"b1":{"status":"loaded"}}' data-phem-signature-value="s9"></template>`
    await new Promise((resolve) => setTimeout(resolve, 0))

    const root = document.getElementById("phem-root")
    expect(document.getElementById("report").textContent).toBe("Rows 3")
    expect(document.querySelector("[data-phem-load='b1.load']")).toBeNull()
    expect(document.querySelector("template[data-phem-for], template[data-phem-root-state], script")).toBeNull()
    expect(root.hasAttribute("data-phem-streaming")).toBe(false)

    // The boundary whose stream failed keeps its fallback: it loads with an event, with the state of the end of the page.
    await done
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent(0)).toMatchObject({ handler: "b2.load", signature: "s9", state: '{"b1":{"status":"loaded"}}' })
  })

  it("sends the load event when the page starts, and morphs the children in", async () => {
    respond(`<p id="report">Rows 3</p>`)
    const done = nextDone()
    await mount(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense>`)
    const event = await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent(0)).toMatchObject({ handler: "c1.load", event: { type: "load" } })
    expect(event.detail).toEqual({ handler: "c1.load", ids: [], ok: true, status: 200 })
    expect(document.getElementById("report").textContent).toBe("Rows 3")
    expect(document.querySelector("phem-suspense")).toBeNull()
  })

  it("loads the boundaries of the page start in one event", async () => {
    respond(`<p id="sales">Sales</p><p id="orders">Orders</p><p id="users">Users</p>`)
    const done = nextDone()
    await mount(`
      <phem-suspense data-phem-load="c1.sales"><p>Loading…</p></phem-suspense>
      <phem-suspense data-phem-load="c1.orders"><p>Loading…</p></phem-suspense>
      <phem-suspense data-phem-load="c1.users"><p>Loading…</p></phem-suspense>`)
    const event = await done

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(sent(0)).toMatchObject({ handler: ["c1.sales", "c1.orders", "c1.users"], event: { type: "load" } })
    expect(sent(0).event).not.toHaveProperty("loads")
    expect(event.detail).toEqual({ handler: "c1.sales", ids: [], ok: true, status: 200 })
    expect(document.querySelectorAll("phem-suspense")).toHaveLength(0)

    // The loading state ends after phem:done, for each boundary.
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(document.querySelectorAll("[aria-busy]")).toHaveLength(0)
  })

  it("loads each boundary alone after a failed load of all of them", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {})
    const quiet = (e) => e.preventDefault()
    window.addEventListener("phem:error", quiet)
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    respond(`<phem-suspense data-phem-load="c1.sales"><p>Loading…</p></phem-suspense><p id="orders">Orders</p>`)
    const loaded = new Promise((resolve) => window.addEventListener("phem:done", (e) => e.detail.handler === "c1.orders" && resolve(e)))
    await mount(`
      <phem-suspense data-phem-load="c1.sales"><p>Loading…</p></phem-suspense>
      <phem-suspense data-phem-load="c1.orders"><p>Loading…</p></phem-suspense>`)
    await loaded

    expect(fetch).toHaveBeenCalledTimes(3)
    expect(sent(0).handler).toEqual(["c1.sales", "c1.orders"])
    expect(sent(1).handler).toBe("c1.sales")
    expect(sent(2).handler).toBe("c1.orders")
    expect(document.getElementById("orders")).not.toBeNull()
    window.removeEventListener("phem:error", quiet)
  })

  it("loads a lazy boundary only when it comes into view", async () => {
    const observed = []
    let callback
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        constructor(cb) {
          callback = cb
        }
        observe(el) {
          observed.push(el)
        }
        unobserve() {}
        disconnect() {}
      },
    )
    await mount(`<phem-suspense data-phem-load="c1.load" data-phem-lazy><p>Loading…</p></phem-suspense>`)

    expect(fetch).not.toHaveBeenCalled()
    expect(observed).toEqual([document.querySelector("phem-suspense")])

    respond(`<p id="chart">Chart</p>`)
    const done = nextDone()
    callback([{ isIntersecting: true, target: observed[0] }])
    await done

    expect(sent(0).handler).toBe("c1.load")
    expect(document.getElementById("chart")).not.toBeNull()
  })

  it("does not load a boundary again after a failed load", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {})
    window.addEventListener("phem:error", (e) => e.preventDefault(), { once: true })
    fetch.mockResolvedValueOnce(new Response("Error", { status: 500 }))
    const failed = nextDone()
    await mount(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense><button data-phem-on-click="c1.star">Star</button>`)
    expect((await failed).detail).toEqual({ handler: "c1.load", ids: [], ok: false, status: 500 })

    respond(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense><button data-phem-on-click="c1.star">Unstar</button>`)
    const done = nextDone()
    click("button")
    await done

    expect(fetch).toHaveBeenCalledTimes(2)
    expect(sent(1).handler).toBe("c1.star")
    expect(document.querySelector("phem-suspense p").textContent).toBe("Loading…")
  })

  it("loads a boundary that a morph shows", async () => {
    await mount(`<button data-phem-on-click="c1.open">Open</button>`)
    respond(`<phem-suspense data-phem-load="c1.load"><p>Loading…</p></phem-suspense>`)
    respond(`<p id="report">Rows 3</p>`)
    const loaded = new Promise((resolve) => window.addEventListener("phem:done", (e) => e.detail.handler === "c1.load" && resolve(e)))
    click("button")
    await loaded

    expect(sent(1).handler).toBe("c1.load")
    expect(document.getElementById("report")).not.toBeNull()
  })
})

describe("pushes", () => {
  // The EventSource of the page: the test sends the messages of the hub.
  class FakeEventSource {
    static CLOSED = 2
    static last = null
    static count = 0
    constructor(url, options) {
      this.url = url
      this.options = options
      this.listeners = {}
      this.closed = false
      this.readyState = 0
      FakeEventSource.last = this
      FakeEventSource.count++
    }
    addEventListener(type, listener) {
      ;(this.listeners[type] ??= []).push(listener)
    }
    emit(type, event = {}) {
      for (const listener of this.listeners[type] ?? []) listener(event)
    }
    push(data) {
      this.emit("message", { data })
    }
    close() {
      this.closed = true
    }
  }

  const stream = (action, target, content = null) =>
    content === null ? `<turbo-stream action="${action}" target="${target}"></turbo-stream>` : `<turbo-stream action="${action}" target="${target}"><template>${content}</template></turbo-stream>`

  // The body of the next event that the controller posts.
  function nextBody() {
    return vi.waitFor(() => {
      expect(fetch).toHaveBeenCalled()
      return JSON.parse(fetch.mock.calls.at(-1)[1].body)
    })
  }

  const rendered = () => new Promise((resolve) => setTimeout(resolve, 30))

  beforeEach(() => {
    FakeEventSource.last = null
    FakeEventSource.count = 0
    vi.stubGlobal("EventSource", FakeEventSource)
  })

  async function subscribe(markup) {
    await mount(markup, 'data-phem-stream-value="/.well-known/mercure?match=phem%3AApp%5CGuestbook"')
    return FakeEventSource.last
  }

  it("opens one connection to the hub, and closes it when the page goes", async () => {
    const source = await subscribe("<ul id=\"entries\"></ul>")

    expect(source.url).toBe("/.well-known/mercure?match=phem%3AApp%5CGuestbook")
    expect(source.options).toEqual({ withCredentials: true })

    document.body.innerHTML = ""
    await new Promise((resolve) => setTimeout(resolve, 0))
    expect(source.closed).toBe(true)
  })

  it("opens no connection for a page with no subscription", async () => {
    await mount("<p>Hi</p>")

    expect(FakeEventSource.last).toBeNull()
  })

  it("renders a stream outside the root element as plain Turbo", async () => {
    const source = await subscribe("<button data-phem-on-click=\"c1.save\">Save</button>")
    document.body.insertAdjacentHTML("beforeend", '<p id="count">1</p>')
    source.push(stream("update", "count", "2"))
    await rendered()

    respond("<button data-phem-on-click=\"c1.save\">Save</button>")
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("count").textContent).toBe("2")
    expect(JSON.parse(fetch.mock.calls[0][1].body).stale).toBeUndefined()
  })

  it("marks the component of the target as stale, and the next event sends it", async () => {
    const source = await subscribe('<ul id="entries"></ul><button data-phem-on-click="c1.save">Save</button>')
    source.push(stream("append", "entries", '<li id="entry-1">Hi</li>'))
    await rendered()

    expect(document.getElementById("entry-1").textContent).toBe("Hi")

    respond('<ul id="entries"><li id="entry-1">Hi</li></ul><button data-phem-on-click="c1.save">Save</button>')
    let done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[0][1].body).stale).toEqual(["c1"])

    // The server rendered it again: the next event does not send it.
    respond('<ul id="entries"><li id="entry-1">Hi</li></ul><button data-phem-on-click="c1.save">Save</button>')
    done = nextDone()
    click("button")
    await done

    expect(JSON.parse(fetch.mock.calls[1][1].body).stale).toBeUndefined()
  })

  it("removes an element with the same id in the target before an append", async () => {
    const source = await subscribe('<ul id="entries"><li id="entry-1" data-phem-c="c2">Old</li></ul>')
    source.push(stream("prepend", "entries", '<li id="entry-1">New</li>'))
    await rendered()

    expect([...document.querySelectorAll("#entry-1")].map((li) => li.textContent)).toEqual(["New"])
  })

  it("keeps a bound field that the user changed in a pushed morph", async () => {
    const source = await subscribe('<div id="form"><input data-phem-bind="c1.name" value="Ada"><p>Old</p></div>')
    document.querySelector("input").value = "Grace"
    source.push(`<turbo-stream action="update" method="morph" target="form"><template><input data-phem-bind="c1.name" value="Ada"><p>New</p></template></turbo-stream>`)
    await rendered()

    expect(document.querySelector("p").textContent).toBe("New")
    expect(document.querySelector("input").value).toBe("Grace")
  })

  it("keeps a bound field that the user changed when a component before it goes away", async () => {
    const fields = (error) => `<label><input data-phem-bind="c1.name" value="Ada">${error ? '<p data-phem-c="phemE0">Name error</p>' : ""}</label><label><input data-phem-bind="c1.phone" value=""><p data-phem-c="${error ? "phemE1" : "phemE0"}">Phone error</p></label>`
    const source = await subscribe(`<div id="form">${fields(true)}</div>`)
    const phone = document.querySelector('[data-phem-bind="c1.phone"]')
    phone.value = "555"
    source.push(`<turbo-stream action="update" method="morph" target="form"><template>${fields(false)}</template></turbo-stream>`)
    await rendered()

    expect(document.querySelector('[data-phem-bind="c1.phone"]')).toBe(phone)
    expect(phone.value).toBe("555")
    expect([...document.querySelectorAll("p")].map((p) => p.textContent)).toEqual(["Phone error"])
  })

  it("sends one refresh with no handler for the refreshes that come together", async () => {
    const source = await subscribe('<p id="stats">1 entry</p><p id="other">Other</p>')
    respond('<p id="stats">2 entries</p><p id="other">Other</p>')
    source.push(stream("phem-refresh", "stats") + stream("phem-refresh", "other"))
    source.push(stream("phem-refresh", "stats"))
    const body = await nextBody()

    expect(body.handler).toBeNull()
    expect(body.stale).toEqual(["c1"])
    await vi.waitFor(() => expect(document.getElementById("stats").textContent).toBe("2 entries"))
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("sends no refresh for a target that the page does not show", async () => {
    const source = await subscribe("<p>Hi</p>")
    source.push(stream("phem-refresh", "missing"))
    await new Promise((resolve) => setTimeout(resolve, 100))

    expect(fetch).not.toHaveBeenCalled()
  })

  it("refreshes the components of a targets selector", async () => {
    const source = await subscribe('<p data-phem-c="b1" data-phem-broadcast="all post1">1</p><p data-phem-c="b2" data-phem-broadcast="all post2">2</p><phem-c data-phem-c="b3" data-phem-broadcast="all post1"><p>1</p></phem-c>')
    respond("<p>x</p>")
    source.push('<turbo-stream action="phem-refresh" targets="[data-phem-broadcast~=&quot;post1&quot;]"></turbo-stream>')
    const body = await nextBody()

    expect(body.stale).toEqual(["b1", "b3"])
    // The morph of the refresh, before the next test: else it can morph the markup of the next test.
    await vi.waitFor(() => expect(document.querySelector('[data-phem-c="c1"]').innerHTML).toBe("<p>x</p>"))
  })

  it("skips the components that its own event rendered in a refresh of the event", async () => {
    const markup = '<button data-phem-on-click="b1.like" data-phem-c="b1" data-phem-broadcast="all">Like</button><p id="b2" data-phem-c="b2" data-phem-broadcast="all">2</p>'
    const source = await subscribe(markup)
    respond(markup, {}, { sender: "t1", rendered: ["b1"] })
    const done = nextDone()
    click("button")
    await done

    respond(markup)
    source.push('<turbo-stream action="phem-refresh" targets="[data-phem-broadcast~=&quot;all&quot;]" data-phem-sender="t1"></turbo-stream>')
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
    expect(JSON.parse(fetch.mock.calls[1][1].body).stale).toEqual(["b2"])

    // The token of an event of another page: no skip.
    respond(markup.replace(">2</p>", ">3</p>"))
    source.push('<turbo-stream action="phem-refresh" targets="[data-phem-broadcast~=&quot;all&quot;]" data-phem-sender="t2"></turbo-stream>')
    // The morph of the last refresh, before the next test.
    await vi.waitFor(() => expect(document.getElementById("b2").textContent).toBe("3"))
    expect(JSON.parse(fetch.mock.calls[2][1].body).stale).toEqual(["b1", "b2"])
  })

  it("skips the target and the content that its own event rendered, with all the components in them", async () => {
    const markup = '<p id="stats" data-phem-c="s1">1 entry</p><div id="list" data-phem-c="l1"><span data-phem-c="i1">One</span></div><button data-phem-on-click="c1.post">Post</button>'
    const source = await subscribe(markup)
    const root = document.getElementById("phem-root")
    // The event rendered the content, the stats and the list, but not the item of the list (it kept it).
    respond(markup, {}, { sender: "t1", rendered: ["c1", "s1", "l1"] })
    const done = nextDone()
    click("button")
    await done

    // The stats: the event rendered them, so no refresh.
    source.push('<turbo-stream action="phem-refresh" target="stats" data-phem-sender="t1"></turbo-stream>')
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
    await rendered()
    expect(fetch).toHaveBeenCalledTimes(1)

    // The list: the event did not render its item, so it renders again.
    respond(markup)
    source.push('<turbo-stream action="phem-refresh" target="list" data-phem-sender="t1"></turbo-stream>')
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
    expect(JSON.parse(fetch.mock.calls[1][1].body).stale).toEqual(["l1"])
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))

    // The content: the item in it did not render either.
    respond(markup)
    source.push('<turbo-stream action="phem-refresh" data-phem-sender="t1"></turbo-stream>')
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(3))
    expect(JSON.parse(fetch.mock.calls[2][1].body).stale).toEqual(["c1"])
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
  })

  it("skips the content of a refresh when its own event rendered all of it", async () => {
    const markup = '<p id="stats" data-phem-c="s1">1 entry</p><button data-phem-on-click="c1.post">Post</button>'
    const source = await subscribe(markup)
    respond(markup, {}, { sender: "t1", rendered: ["c1", "s1"] })
    const done = nextDone()
    click("button")
    await done

    source.push('<turbo-stream action="phem-refresh" data-phem-sender="t1"></turbo-stream>')
    await rendered()

    expect(fetch).toHaveBeenCalledTimes(1)
    expect(document.getElementById("phem-root").hasAttribute("data-phem-push-pending")).toBe(false)
  })

  it("morphs the keys of a component with #[Broadcast] after an event", async () => {
    await subscribe('<button id="next" data-phem-on-click="b1.next" data-phem-c="b1" data-phem-broadcast="all post1">Next</button>')
    const streams = '<turbo-stream action="replace" method="morph" targets="[data-phem-c=b1]"><template><button id="next" data-phem-on-click="b1.next" data-phem-c="b1" data-phem-broadcast="all post2">Next</button></template></turbo-stream>'
    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ streams, changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
    const button = document.getElementById("next")
    const done = nextDone()
    click("button")
    await done

    expect(document.getElementById("next")).toBe(button)
    expect(button.getAttribute("data-phem-broadcast")).toBe("all post2")
  })

  it("refreshes the content of the root element for a refresh with no target", async () => {
    const source = await subscribe("<p>Hi</p>")
    respond("<p>Hi again</p>")
    source.push('<turbo-stream action="phem-refresh"></turbo-stream>')
    const body = await nextBody()

    expect(body.stale).toEqual(["c1"])
  })

  // Listens to `phem:error` during a test, and removes the listener after it, also when it fails.
  async function withErrors(listener, test) {
    window.addEventListener("phem:error", listener)
    try {
      await test()
    } finally {
      window.removeEventListener("phem:error", listener)
    }
  }

  it("loads the page again when a refresh gets a 409, with no error banner", async () => {
    const source = await subscribe("<p>Product</p>")
    const errors = vi.fn()
    await withErrors(errors, async () => {
      fetch.mockResolvedValueOnce(new Response(JSON.stringify({ reload: true }), { status: 409 }))
      source.push('<turbo-stream action="phem-refresh"></turbo-stream>')

      // A refresh has no element that sent it: no phem:done.
      await vi.waitFor(() => expect(Turbo.visit).toHaveBeenCalledExactlyOnceWith(window.location.href, { action: "replace" }))
      expect(JSON.parse(fetch.mock.calls[0][1].body).handler).toBeNull()
      expect(errors).not.toHaveBeenCalled()
    })
  })

  it("keeps the changes of a guard when a refresh gets a 409: it shows the error", async () => {
    const source = await subscribe('<form data-phem-guard><input data-phem-bind="c1.text" value=""></form>')
    document.querySelector("input").value = "A long text"
    const errors = vi.fn((e) => e.preventDefault())
    await withErrors(errors, async () => {
      fetch.mockResolvedValueOnce(new Response(JSON.stringify({ reload: true }), { status: 409 }))
      source.push('<turbo-stream action="phem-refresh"></turbo-stream>')

      await vi.waitFor(() => expect(errors).toHaveBeenCalledOnce())
      expect(errors.mock.calls[0][0].detail).toEqual({ handler: null, status: 409 })
      expect(Turbo.visit).not.toHaveBeenCalled()
      expect(document.querySelector("input").value).toBe("A long text")
    })
  })

  it("shows the error banner when a refresh gets a 404: only the server says when the page goes", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {})
    const source = await subscribe("<p>Product</p>")
    const errors = vi.fn((e) => e.preventDefault())
    await withErrors(errors, async () => {
      fetch.mockResolvedValueOnce(new Response("Not found", { status: 404 }))
      source.push('<turbo-stream action="phem-refresh"></turbo-stream>')

      await vi.waitFor(() => expect(errors).toHaveBeenCalledOnce())
      expect(Turbo.visit).not.toHaveBeenCalled()
    })
  })

  it("shows the error banner when a handler gets a 404: the user made the event", async () => {
    vi.spyOn(console, "error").mockImplementation(() => {})
    await mount(`<button data-phem-on-click="c1.save">Save</button>`)
    const errors = vi.fn((e) => e.preventDefault())
    await withErrors(errors, async () => {
      fetch.mockResolvedValueOnce(new Response("Not found", { status: 404 }))
      const done = nextDone()
      click("button")

      expect((await done).detail).toMatchObject({ handler: "c1.save", ok: false, status: 404 })
      expect(Turbo.visit).not.toHaveBeenCalled()
      expect(errors).toHaveBeenCalledOnce()
    })
  })

  it("marks the root element while a refresh of a push waits and runs", async () => {
    const source = await subscribe('<p id="stats">1 entry</p>')
    const root = document.getElementById("phem-root")
    let answer
    fetch.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))
    source.push(stream("phem-refresh", "stats"))

    // At once: the refresh waits REFRESH_WAIT, and a test with a real browser cannot see that.
    expect(root.hasAttribute("data-phem-push-pending")).toBe(true)
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())
    expect(root.hasAttribute("data-phem-push-pending")).toBe(true)

    answer(new Response(JSON.stringify({ streams: "", changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
  })

  it("marks the root element while a push waits for an event and Turbo renders it", async () => {
    const source = await subscribe('<ul id="entries"></ul><button data-phem-on-click="c1.save">Save</button>')
    const root = document.getElementById("phem-root")
    let answer
    fetch.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))
    const done = nextDone()
    click("button")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    source.push(stream("append", "entries", '<li id="entry-1">One</li>'))
    expect(root.hasAttribute("data-phem-push-pending")).toBe(true)

    answer(new Response(JSON.stringify({ streams: "", changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
    await done
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
    expect(document.getElementById("entry-1")).not.toBeNull()
  })

  it("keeps the mark until the last refresh when a push comes while a refresh runs", async () => {
    const source = await subscribe('<p id="stats">1 entry</p>')
    const root = document.getElementById("phem-root")
    let first
    let second
    fetch.mockReturnValueOnce(new Promise((resolve) => (first = resolve)))
    fetch.mockReturnValueOnce(new Promise((resolve) => (second = resolve)))
    source.push(stream("phem-refresh", "stats"))
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1))

    // A second push while the first refresh runs: its refresh comes after it.
    source.push(stream("phem-refresh", "stats"))
    first(new Response(JSON.stringify({ streams: "", changes: {}, signature: "s2" }), { status: 200, headers: { "Content-Type": "application/json" } }))
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
    expect(root.hasAttribute("data-phem-push-pending")).toBe(true)

    second(new Response(JSON.stringify(answer('<p id="stats">3 entries</p>')), { status: 200, headers: { "Content-Type": "application/json" } }))
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
    await vi.waitFor(() => expect(document.getElementById("stats").textContent).toBe("3 entries"))
  })

  it("renders a push that comes while an event runs after the morph of the event", async () => {
    const source = await subscribe('<ul id="entries"><li id="entry-1">One</li></ul><button data-phem-on-click="c1.save">Save</button>')
    let answer
    fetch.mockReturnValueOnce(new Promise((resolve) => (answer = resolve)))
    const done = nextDone()
    click("button")
    await vi.waitFor(() => expect(fetch).toHaveBeenCalled())

    // The event of this user added entry 2; the push of the same change comes first.
    source.push(stream("append", "entries", '<li id="entry-2">Two</li>'))
    await rendered()
    expect(document.getElementById("entry-2")).toBeNull()

    const markup = '<ul id="entries"><li id="entry-1">One</li><li id="entry-2">Two</li></ul><button data-phem-on-click="c1.save">Save</button>'
    answer(new Response(JSON.stringify({ streams: `<turbo-stream action="replace" method="morph" targets="[data-phem-c=c1]"><template><phem-c data-phem-c="c1">${markup}</phem-c></template></turbo-stream>`, changes: {}, signature: "s2" }), { status: 200 }))
    await done
    await rendered()

    expect([...document.querySelectorAll("li")].map((li) => li.textContent)).toEqual(["One", "Two"])
  })

  it("refreshes the content of the root element after a lost connection", async () => {
    const source = await subscribe("<p>Hi</p>")
    respond("<p>Hi again</p>")
    source.emit("open")
    source.emit("error")
    source.emit("open")
    const body = await nextBody()

    expect(body.stale).toEqual(["c1"])
    expect(body.handler).toBeNull()
  })

  it("marks the root element while the refresh after a lost connection waits and runs", async () => {
    const source = await subscribe("<p>Hi</p>")
    const root = document.getElementById("phem-root")
    respond("<p>Hi again</p>")
    source.emit("open")
    source.emit("error")
    source.emit("open")

    expect(root.hasAttribute("data-phem-push-pending")).toBe(true)
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("removes the mark when the page goes, so the Turbo cache does not keep it", async () => {
    const source = await subscribe('<p id="stats">1 entry</p>')
    const root = document.getElementById("phem-root")
    source.push(stream("phem-refresh", "stats"))
    expect(root.hasAttribute("data-phem-push-pending")).toBe(true)

    // The page goes before its refresh: the refresh never runs.
    root.removeAttribute("data-controller")
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))

    // A page from the cache with the mark: the controller removes it.
    root.setAttribute("data-phem-push-pending", "")
    root.setAttribute("data-controller", "phem")
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
    expect(fetch).not.toHaveBeenCalled()
  })

  it("removes the mark when the render of a push throws", async () => {
    const source = await subscribe('<ul id="entries"></ul>')
    const root = document.getElementById("phem-root")
    vi.spyOn(Turbo, "renderStreamMessage").mockImplementationOnce(() => {
      throw new Error("A broken stream")
    })

    expect(() => source.push(stream("append", "entries", "<li>One</li>"))).toThrow("A broken stream")
    expect(root.hasAttribute("data-phem-push-pending")).toBe(false)
  })

  it("sends the content of the root element when more than 100 components are stale", async () => {
    const rows = Array.from({ length: 101 }, (_, i) => `<p id="cell${i}" data-phem-c="row${i}">${i}</p>`).join("")
    const source = await subscribe(`<p id="top">Rows</p>${rows}`)
    source.push(Array.from({ length: 101 }, (_, i) => stream("update", `cell${i}`, "x")).join(""))
    source.push(stream("phem-refresh", "top"))
    respond(rows)
    const body = await nextBody()

    expect(body.stale).toEqual(["c1"])
  })

  it("renews the token with a refresh and connects again after the hub refused the connection", async () => {
    const source = await subscribe("<p>Hi</p>")
    respond("<p>Hi</p>")
    vi.useFakeTimers({ toFake: ["setTimeout"] })
    try {
      source.readyState = FakeEventSource.CLOSED
      source.emit("error")
      vi.advanceTimersByTime(5000)
    } finally {
      vi.useRealTimers()
    }

    const body = await nextBody()

    expect(body.handler).toBeNull()
    expect(body.stale).toEqual(["c1"])
    await vi.waitFor(() => expect(FakeEventSource.count).toBe(2))
  })

  // The hub refuses the connection, and a refresh does not change that.
  async function refuse(source) {
    vi.useFakeTimers({ toFake: ["setTimeout"] })
    try {
      source.readyState = FakeEventSource.CLOSED
      source.emit("error")
      vi.advanceTimersByTime(5000)
    } finally {
      vi.useRealTimers()
    }
  }

  it("stops with a warning when the hub refuses three connections in a row", async () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {})
    try {
      await subscribe("<p>Hi</p>")
      for (let i = 1; i <= 3; i++) {
        respond("<p>Hi</p>")
        await refuse(FakeEventSource.last)
        if (i < 3) await vi.waitFor(() => expect(FakeEventSource.count).toBe(i + 1))
      }

      await rendered()

      expect(FakeEventSource.count).toBe(3)
      expect(fetch).toHaveBeenCalledTimes(2)
      expect(warn).toHaveBeenCalledWith(expect.stringContaining("the Mercure hub at /.well-known/mercure refused 3 connections"))
    } finally {
      warn.mockRestore()
    }
  })

  it("counts again the refused connections after an open one", async () => {
    await subscribe("<p>Hi</p>")
    for (let i = 1; i <= 3; i++) {
      respond("<p>Hi</p>")
      FakeEventSource.last.emit("open")
      await refuse(FakeEventSource.last)
      await vi.waitFor(() => expect(FakeEventSource.count).toBe(i + 1))
    }
  })

  it("keeps a dirty bound field only in a pushed morph", async () => {
    await mount('<input data-phem-bind="c1.name" value="Ada">')
    const input = document.querySelector("input")
    input.value = "Grace"
    const event = new CustomEvent("turbo:before-morph-attribute", { cancelable: true, bubbles: true, detail: { attributeName: "value" } })
    input.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(false)
  })
})


describe("phem_test_hub.js", () => {
  const script = readFileSync(createRequire(import.meta.url).resolve("../../src/Test/Resources/phem_test_hub.js"), "utf8")
  const now = () => Date.now() / 1000
  let original

  beforeEach(() => {
    original = window.EventSource
    window.eval(script)
  })

  afterEach(() => {
    window.EventSource = original
    delete window.phemTestHub
  })

  it("gives each connection the updates of its topics, one time each", async () => {
    const lobby = new EventSource("/.well-known/mercure?match=phem%3Alobby&match=phem%3Aall", { withCredentials: true })
    const hall = new EventSource("/.well-known/mercure?match=phem%3Ahall")
    const opened = vi.fn()
    lobby.addEventListener("open", opened)
    const got = { lobby: [], hall: [] }
    lobby.addEventListener("message", (e) => got.lobby.push(e.data))
    hall.onmessage = (e) => got.hall.push(e.data)
    await Promise.resolve()

    const updates = [
      { index: 4, time: now(), topics: ["phem:lobby"], data: "one" },
      { index: 5, time: now(), topics: ["phem:all", "phem:hall"], data: "two" },
    ]
    window.phemTestHub.deliver(updates)
    // The same script again, for another page: this page has the updates already.
    window.phemTestHub.deliver([...updates, { index: 6, time: now(), topics: ["phem:hall"], data: "three" }])

    expect(opened).toHaveBeenCalledOnce()
    expect(lobby.readyState).toBe(EventSource.OPEN)
    expect(lobby.withCredentials).toBe(true)
    expect(got).toEqual({ lobby: ["one", "two"], hall: ["two", "three"] })

    hall.close()
    window.phemTestHub.deliver([{ index: 7, time: now(), topics: ["phem:hall"], data: "four" }])
    expect(got.hall).toEqual(["two", "three"])
  })

  it("gives a connection only the updates published after it opened, as the hub keeps no history", () => {
    const before = now() - 10
    const source = new EventSource("/.well-known/mercure?match=phem%3Alobby")
    const got = []
    source.onmessage = (e) => got.push(e.data)

    window.phemTestHub.deliver([
      { index: 0, time: before, topics: ["phem:lobby"], data: "old" },
      { index: 1, time: now(), topics: ["phem:lobby"], data: "new" },
    ])

    expect(got).toEqual(["new"])
  })

  it("renders a push in the page, and the page loses its mark when it is done", async () => {
    vi.stubGlobal("fetch", vi.fn())
    await mount('<ul id="entries"></ul>', 'data-phem-stream-value="/.well-known/mercure?match=phem%3AApp%5CGuestbook"')
    await Promise.resolve()
    const root = document.getElementById("phem-root")

    window.phemTestHub.deliver([{ index: 0, time: now(), topics: ["phem:App\\Guestbook"], data: '<turbo-stream action="append" target="entries"><template><li id="entry-1">One</li></template></turbo-stream>' }])

    expect(root.hasAttribute("data-phem-push-pending")).toBe(true)
    await vi.waitFor(() => expect(root.hasAttribute("data-phem-push-pending")).toBe(false))
    expect(document.getElementById("entry-1").textContent).toBe("One")
  })
})
