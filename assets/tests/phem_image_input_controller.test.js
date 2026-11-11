import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemImageInputController from "../dist/phem_image_input_controller.js"
import { createRequire } from "node:module"
import { answer, mount, nextDone } from "./support.js"

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-image-input", PhemImageInputController)
})

// jsdom has no object URLs, no image decoder, no canvas and no DataTransfer: stubs with the same
// API. An image file has its size in its content ("800x600"). The canvas gives a blob of the type
// that it gets, with its size as the content.
const { implForWrapper } = createRequire(import.meta.url)("jsdom/lib/generated/idl/utils.js")
function fileList(files) {
  const list = Object.assign(document.createElement("input"), { type: "file" }).files
  implForWrapper(list).push(...files.map((file) => implForWrapper(file)))
  return list
}

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  let urls = 0
  vi.stubGlobal("URL", Object.assign(class extends URL {}, { createObjectURL: vi.fn(() => `blob:${++urls}`), revokeObjectURL: vi.fn() }))
  vi.stubGlobal("createImageBitmap", vi.fn(async (file) => {
    const [width, height] = (await file.text()).split("x").map(Number)
    if (!width) throw new DOMException("The source image cannot be decoded.", "InvalidStateError")
    return { width, height, close: vi.fn() }
  }))
  vi.stubGlobal("DataTransfer", class {
    #files = []
    items = { add: (file) => this.#files.push(file) }
    get files() {
      return fileList(this.#files)
    }
  })
  vi.spyOn(HTMLCanvasElement.prototype, "getContext").mockImplementation(() => ({ drawImage: vi.fn() }))
  vi.spyOn(HTMLCanvasElement.prototype, "toBlob").mockImplementation(function (callback, type) {
    callback(new Blob([`${this.width}x${this.height}`], { type: type === "image/avif" ? "image/png" : type }))
  })
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

const input = (values = "", preview = '<div id="preview" data-phem-image-input-target="preview"></div>') => `<form data-phem-on-submit="c1.save">
    <div id="picker" data-controller="phem-image-input" data-phem-image-input-preview-class="thumb" ${values}>
      <input type="file" name="photos[]" multiple data-phem-image-input-target="input">
      ${preview}
    </div>
    <button type="submit">Save</button>
  </form>`

function pick(files) {
  const el = document.querySelector("input[type=file]")
  el.files = fileList(files)
  el.dispatchEvent(new Event("change", { bubbles: true }))
  return el
}

const image = (size, name = "a.jpg", type = "image/jpeg") => new File([size], name, { type, lastModified: 5 })
const resized = () => new Promise((resolve) => document.addEventListener("phem-image-input:resized", (e) => resolve(e.detail.files), { once: true }))

describe("the previews", () => {
  it("shows an image for each image file, with its name", async () => {
    await mount(input())
    pick([image("800x600"), new File(["notes"], "notes.txt", { type: "text/plain" }), image("10x10", "b.png", "image/png")])

    await vi.waitFor(() => expect(document.querySelectorAll("#preview img")).toHaveLength(2))
    const imgs = [...document.querySelectorAll("#preview img")]
    expect(imgs.map((img) => [img.getAttribute("src"), img.alt, img.className])).toEqual([["blob:1", "a.jpg", "thumb"], ["blob:2", "b.png", "thumb"]])
  })

  it("shows the first image in an img target, and its own image with no file", async () => {
    await mount(input("", '<img id="avatar" src="/default.png" data-phem-image-input-target="preview">'))
    const el = pick([image("8x8")])
    await vi.waitFor(() => expect(document.getElementById("avatar").getAttribute("src")).toBe("blob:1"))

    el.files = fileList([])
    el.dispatchEvent(new Event("change", { bubbles: true }))
    await vi.waitFor(() => expect(document.getElementById("avatar").getAttribute("src")).toBe("/default.png"))
    expect(URL.revokeObjectURL).toHaveBeenCalledWith("blob:1")
  })

  it("keeps the previews after the morph of an event, and removes them when the handler takes the files", async () => {
    await mount(input())
    pick([image("8x8")])
    await vi.waitFor(() => expect(document.querySelectorAll("#preview img")).toHaveLength(1))

    vi.stubGlobal("XMLHttpRequest", class extends EventTarget {
      upload = new EventTarget()
      open() {}
      setRequestHeader() {}
      getAllResponseHeaders() {
        return "content-type: application/json"
      }
      send() {
        Object.assign(this, { status: 200, statusText: "OK", response: JSON.stringify(answer(input(), {}, this.constructor.clear ? { clearFiles: true } : {})) })
        setTimeout(() => this.dispatchEvent(new Event("load")), 0)
      }
    })
    let done = nextDone()
    document.querySelector("form").requestSubmit()
    await done
    expect(document.querySelectorAll("#preview img")).toHaveLength(1)

    XMLHttpRequest.clear = true
    done = nextDone()
    document.querySelector("form").requestSubmit()
    await done
    expect(document.querySelector("input[type=file]").files).toHaveLength(0)
    expect(document.querySelectorAll("#preview img")).toHaveLength(0)
  })
})

describe("the resize", () => {
  it("makes the larger images smaller, with the same ratio, and keeps the smaller ones", async () => {
    await mount(input('data-phem-image-input-max-width-value="400" data-phem-image-input-max-height-value="400"'))
    const small = image("300x200", "small.jpg")
    const files = resized()
    const el = pick([image("800x600"), small, image("1000x2000", "tall.png", "image/png")])

    const result = await files
    expect(await Promise.all(result.map(async (f) => [f.name, f.type, await f.text()]))).toEqual([
      ["a.jpg", "image/jpeg", "400x300"],
      ["small.jpg", "image/jpeg", "300x200"],
      ["tall.png", "image/png", "200x400"],
    ])
    expect(result[1]).toBe(small)
    expect([...el.files].map((f) => f.name)).toEqual(["a.jpg", "small.jpg", "tall.png"])
    expect(await el.files[0].text()).toBe("400x300")
    expect(HTMLCanvasElement.prototype.toBlob).toHaveBeenCalledWith(expect.any(Function), "image/jpeg", 0.85)
  })

  it("changes the type of the images, with the extension, and keeps a file that it cannot read", async () => {
    await mount(input('data-phem-image-input-type-value="image/webp" data-phem-image-input-quality-value="0.7"'))
    const broken = image("not an image", "c.jpg")
    const files = resized()
    pick([image("40x30", "a.photo.jpeg"), broken, image("5x5", "g.gif", "image/gif")])

    const result = await files
    expect(result.map((f) => [f.name, f.type])).toEqual([["a.photo.webp", "image/webp"], ["c.jpg", "image/jpeg"], ["g.gif", "image/gif"]])
    expect(result[1]).toBe(broken)
    expect(HTMLCanvasElement.prototype.toBlob).toHaveBeenCalledWith(expect.any(Function), "image/webp", 0.7)
  })

  it("keeps an image that the canvas cannot resize, and shows its preview", async () => {
    await mount(input('data-phem-image-input-max-width-value="400"'))
    HTMLCanvasElement.prototype.getContext.mockImplementationOnce(() => null)
    const big = image("800x600")
    const files = resized()
    pick([big, image("900x600", "b.jpg")])

    const result = await files
    expect(result[0]).toBe(big)
    expect(await result[1].text()).toBe("400x267")
    await vi.waitFor(() => expect(document.querySelectorAll("#preview img")).toHaveLength(2))
    expect(document.getElementById("picker").hasAttribute("data-resizing")).toBe(false)
  })

  it("does not change the files when no image is too large", async () => {
    await mount(input('data-phem-image-input-max-width-value="400"'))
    const listener = vi.fn()
    document.addEventListener("phem-image-input:resized", listener)
    const el = pick([image("300x200")])
    const files = el.files
    await vi.waitFor(() => expect(document.querySelectorAll("#preview img")).toHaveLength(1))

    expect(listener).not.toHaveBeenCalled()
    expect(el.files).toBe(files)
    document.removeEventListener("phem-image-input:resized", listener)
  })

  it("sends a submit during the resize with the smaller files", async () => {
    await mount(input('data-phem-image-input-max-width-value="400"'))
    let decode
    createImageBitmap.mockImplementationOnce(() => new Promise((resolve) => (decode = () => resolve({ width: 800, height: 600, close() {} }))))
    pick([image("800x600")])
    await vi.waitFor(() => expect(decode).toBeDefined())
    expect(document.getElementById("picker").hasAttribute("data-resizing")).toBe(true)

    const bodies = []
    vi.stubGlobal("XMLHttpRequest", class extends EventTarget {
      upload = new EventTarget()
      open() {}
      setRequestHeader() {}
      getAllResponseHeaders() {
        return "content-type: application/json"
      }
      send(body) {
        bodies.push(body)
        Object.assign(this, { status: 200, statusText: "OK", response: JSON.stringify(answer(input())) })
        setTimeout(() => this.dispatchEvent(new Event("load")), 0)
      }
    })
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    expect(bodies).toHaveLength(0)

    decode()
    await done
    expect(bodies).toHaveLength(1)
    expect(await bodies[0].get("photos[]").text()).toBe("400x300")
    expect(document.getElementById("picker").hasAttribute("data-resizing")).toBe(false)
  })
})
