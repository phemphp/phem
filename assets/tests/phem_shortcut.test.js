import { afterEach, describe, expect, it, vi } from "vitest"
import "../dist/phem_shortcut.js"
import { matchesKey } from "../dist/phem_keys.js"

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A key on an element of the page (the body by default). Returns the event.
function press(key, init = {}, target = document.body) {
  const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init })
  target.dispatchEvent(event)

  return event
}

// A page with these elements, and the clicks of each button and field.
function page(markup) {
  document.body.innerHTML = markup
  const clicks = []
  for (const el of document.querySelectorAll("button, input")) el.addEventListener("click", () => clicks.push(el.id))

  return clicks
}

describe("phem:shortcut", () => {
  it("clicks the element of the key, and cancels the default action of the key", () => {
    const clicks = page(`<button id="palette" data-phem-shortcut="ctrl+k">Search</button><button id="other">Other</button>`)

    const event = press("k", { ctrlKey: true })

    expect(clicks).toEqual(["palette"])
    expect(event.defaultPrevented).toBe(true)
  })

  it("does nothing for another key", () => {
    const clicks = page(`<button id="palette" data-phem-shortcut="ctrl+k">Search</button>`)

    const k = press("k")
    press("j", { ctrlKey: true })

    expect(clicks).toEqual([])
    expect(k.defaultPrevented).toBe(false)
  })

  it("takes a list of keys", () => {
    const clicks = page(`<button id="next" data-phem-shortcut="j ArrowDown">Next</button>`)

    press("j")
    press("ArrowDown")

    expect(clicks).toEqual(["next", "next"])
  })

  it("is Ctrl for mod on Linux", () => {
    const clicks = page(`<button id="palette" data-phem-shortcut="mod+k">Search</button>`)

    press("k", { metaKey: true })
    press("k", { ctrlKey: true })

    expect(clicks).toEqual(["palette"])
  })

  it("does not run again for the repeats of a held key", () => {
    const clicks = page(`<button id="save" data-phem-shortcut="ctrl+s">Save</button>`)

    press("s", { ctrlKey: true })
    const repeat = press("s", { ctrlKey: true, repeat: true })

    expect(clicks).toEqual(["save"])
    expect(repeat.defaultPrevented).toBe(false)
  })

  it("does not take a key that a focused select, radio button or range uses", () => {
    const clicks = page(`<select id="status"><option>Open</option></select>
      <input id="size" type="range" /><input id="pick" type="radio" />
      <button id="next" data-phem-shortcut="j ArrowDown">Next</button>`)

    for (const id of ["status", "size", "pick"]) {
      press("ArrowDown", {}, document.getElementById(id))
      press("j", {}, document.getElementById(id))
    }

    expect(clicks).toEqual([])
  })

  it("does not take Enter and Space from a focused button or link", () => {
    const clicks = page(`<button id="other">Other</button><a id="link" href="#x">Link</a>
      <button id="go" data-phem-shortcut="Enter Space">Go</button>`)

    press("Enter", {}, document.getElementById("other"))
    press(" ", {}, document.getElementById("link"))
    press("Enter")

    expect(clicks).toEqual(["go"])
  })

  it("lets a listener of the document take the key first", () => {
    const clicks = page(`<button id="clear" data-phem-shortcut="Escape">Clear</button>`)
    const dismiss = (event) => event.preventDefault()
    document.addEventListener("keydown", dismiss)

    press("Escape")
    document.removeEventListener("keydown", dismiss)

    expect(clicks).toEqual([])
  })

  it("focuses a field", () => {
    const clicks = page(`<input id="search" type="search" data-phem-shortcut="/" />`)

    press("/")

    expect(document.activeElement).toBe(document.getElementById("search"))
    expect(clicks).toEqual([])
  })

  it("does not take a key with no modifier in a field, so the user can type it", () => {
    const clicks = page(`<input id="title" /><textarea id="body"></textarea><button id="next" data-phem-shortcut="j">Next</button>`)

    const j = press("j", {}, document.getElementById("title"))
    press("j", {}, document.getElementById("body"))

    expect(clicks).toEqual([])
    expect(j.defaultPrevented).toBe(false)
  })

  it("takes a key with ctrl, alt or meta, and Escape, in a field", () => {
    const clicks = page(`<input id="title" />
      <button id="save" data-phem-shortcut="ctrl+s">Save</button>
      <button id="close" data-phem-shortcut="Escape">Close</button>`)
    const title = document.getElementById("title")

    press("s", { ctrlKey: true }, title)
    press("Escape", {}, title)

    expect(clicks).toEqual(["save", "close"])
  })

  it("takes a key with no modifier on a checkbox or a button", () => {
    const clicks = page(`<input id="done" type="checkbox" /><button id="next" data-phem-shortcut="j">Next</button>`)

    press("j", {}, document.getElementById("done"))

    expect(clicks).toEqual(["next"])
  })

  it("does not run when an element on the way of the key cancels it", () => {
    const clicks = page(`<div id="menu"><button id="item">Item</button></div><button id="close" data-phem-shortcut="Escape">Close</button>`)
    document.getElementById("menu").addEventListener("keydown", (event) => event.preventDefault())

    press("Escape", {}, document.getElementById("item"))

    expect(clicks).toEqual([])
  })

  it("skips a disabled, hidden or inert element, for the next one with the shortcut", () => {
    const clicks = page(`<button id="disabled" disabled data-phem-shortcut="n">New</button>
      <button id="aria" aria-disabled="true" data-phem-shortcut="n">New</button>
      <div hidden><button id="hidden" data-phem-shortcut="n">New</button></div>
      <div inert><button id="inert" data-phem-shortcut="n">New</button></div>
      <button id="new" data-phem-shortcut="n">New</button>
      <button id="second" data-phem-shortcut="n">New</button>`)

    press("n")

    expect(clicks).toEqual(["new"])
  })

  it("does nothing when no element can run", () => {
    page(`<button id="new" disabled data-phem-shortcut="n">New</button>`)

    expect(press("n").defaultPrevented).toBe(false)
  })

  it("runs only the elements of an open modal dialog", () => {
    const clicks = page(`<button id="page" data-phem-shortcut="Escape s">Page</button>
      <dialog id="dialog"><button id="close" data-phem-shortcut="Escape">Close</button></dialog>`)
    const dialog = document.getElementById("dialog")
    dialog.setAttribute("open", "")
    dialog.matches = (selector) => selector === ":modal" || Element.prototype.matches.call(dialog, selector)

    press("Escape")
    press("s")

    expect(clicks).toEqual(["close"])
  })

  it("runs the elements of the modal dialog that opened last", () => {
    const clicks = page(`<dialog id="a"><button id="in-a" data-phem-shortcut="x">A</button></dialog>
      <dialog id="b"><button id="in-b" data-phem-shortcut="x">B</button></dialog>`)
    for (const dialog of document.querySelectorAll("dialog")) {
      dialog.matches = (selector) => selector === ":modal" || Element.prototype.matches.call(dialog, selector)
    }

    document.getElementById("b").setAttribute("open", "")
    document.getElementById("a").setAttribute("open", "")
    press("x")

    expect(clicks).toEqual(["in-a"])
  })

  it("runs a shortcut of an element that the page adds later", () => {
    const clicks = page(`<div id="list"></div>`)
    document.getElementById("list").innerHTML = `<button id="add" data-phem-shortcut="a">Add</button>`
    document.getElementById("add").addEventListener("click", () => clicks.push("add"))

    press("a")

    expect(clicks).toEqual(["add"])
  })
})

describe("matchesKey", () => {
  it("is the Command key for mod on a Mac", async () => {
    vi.stubGlobal("navigator", { ...navigator, userAgentData: undefined, platform: "MacIntel" })
    vi.resetModules()
    const keys = await import("../dist/phem_keys.js")

    expect(keys.matchesKey("mod+k", new KeyboardEvent("keydown", { key: "k", metaKey: true }))).toBe(true)
    expect(keys.matchesKey("mod+k", new KeyboardEvent("keydown", { key: "k", ctrlKey: true }))).toBe(false)
  })

  const key = (k, init = {}) => new KeyboardEvent("keydown", { key: k, ...init })

  it("matches the modifiers exactly, with a free Shift for a character that is not a letter", () => {
    expect(matchesKey("Enter", key("Enter"))).toBe(true)
    expect(matchesKey("Enter", key("Enter", { shiftKey: true }))).toBe(false)
    expect(matchesKey("?", key("?", { shiftKey: true }))).toBe(true)
    expect(matchesKey("ctrl++", key("+", { ctrlKey: true }))).toBe(true)
    expect(matchesKey("Space", key(" "))).toBe(true)
  })
})
