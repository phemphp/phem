import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import { createRequire } from "node:module"
import { answer, mount, nextDone } from "./support.js"

// The files of an input with `data-phem-upload`: they go up before the event (see
// phem_upload.js), and the event sends their tokens.

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
  localStorage.clear()
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

const form = (mode, other = "") => `<form data-phem-on-submit="c1.save">
    <input type="file" name="video" data-phem-upload="${mode}">${other}<button type="submit">Save</button>
  </form>`

const { implForWrapper } = createRequire(import.meta.url)("jsdom/lib/generated/idl/utils.js")
function pick(input, files) {
  const list = Object.assign(document.createElement("input"), { type: "file" }).files
  implForWrapper(list).push(...files.map((file) => implForWrapper(file)))
  input.files = list
  input.dispatchEvent(new Event("change", { bubbles: true }))
}

const json = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { "Content-Type": "application/json" } })

// The requests of fetch: [headers, body].
const requests = () => fetch.mock.calls.map(([, init]) => [init.headers, init.body])

describe("data-phem-upload=chunked", () => {
  it("sends the file in chunks, then the event with its token", async () => {
    await mount(form("chunked"))
    const file = new File(["abcdefghij"], "v.mp4", { type: "video/mp4", lastModified: 1 })
    pick(document.querySelector("input"), [file])
    fetch
      .mockResolvedValueOnce(json({ id: "u1", offset: 0, chunkSize: 4 }))
      .mockResolvedValueOnce(json({ offset: 4 }))
      .mockResolvedValueOnce(json({ offset: 8 }))
      .mockResolvedValueOnce(json({ offset: 10, token: "t1" }))
      .mockResolvedValueOnce(json(answer(form("chunked"))))
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    const [start, ...rest] = requests()
    expect(start[0]["X-Phem-Upload"]).toBe("start")
    expect(JSON.parse(start[1])).toEqual({ mode: "chunked", name: "v.mp4", type: "video/mp4", size: 10, fingerprint: expect.stringMatching(/^[0-9a-f]{64}$/), resume: null })

    const chunks = rest.slice(0, 3)
    expect(chunks.map(([headers]) => [headers["X-Phem-Upload"], headers["X-Phem-Upload-Offset"]])).toEqual([["u1", "0"], ["u1", "4"], ["u1", "8"]])
    expect(await Promise.all(chunks.map(([, body]) => body.text()))).toEqual(["abcd", "efgh", "ij"])

    const [headers, body] = rest[3]
    expect(headers["X-Phem-Event"]).toBe("1")
    expect(body.get("video")).toBeNull()
    expect(body.getAll("_phem_uploads[]").map((part) => JSON.parse(part))).toEqual([{ field: "video", token: "t1" }])
    expect(JSON.parse(body.get("_phem")).state).toBe("{}")
    expect(localStorage.length).toBe(0)
  })

  it("continues at the offset of the server after a 409 and a network error", async () => {
    await mount(form("chunked"))
    pick(document.querySelector("input"), [new File(["abcdef"], "v.mp4")])
    fetch
      .mockResolvedValueOnce(json({ id: "u1", offset: 0, chunkSize: 4 }))
      .mockResolvedValueOnce(json({ offset: 2 }, 409))
      .mockRejectedValueOnce(new TypeError("Network request failed"))
      .mockResolvedValueOnce(json({ offset: 6, token: "t1" }))
      .mockResolvedValueOnce(json(answer(form("chunked"))))
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    const offsets = requests().slice(1, 4).map(([headers]) => headers["X-Phem-Upload-Offset"])
    expect(offsets).toEqual(["0", "2", "2"])
  })

  it("starts with the id of the last upload of the same file, after a reload", async () => {
    // The first upload stops after a chunk: the server refuses the next one.
    await mount(form("chunked"))
    pick(document.querySelector("input"), [new File(["abcdef"], "v.mp4")])
    vi.spyOn(console, "error").mockImplementation(() => {})
    fetch
      .mockResolvedValueOnce(json({ id: "u1", offset: 0, chunkSize: 4 }))
      .mockResolvedValueOnce(json({ offset: 4 }))
      .mockResolvedValueOnce(new Response("", { status: 500 }))
    let done = nextDone()
    document.querySelector("form").requestSubmit()
    await done
    const first = JSON.parse(requests()[0][1])

    // After a reload: another File object with the same bytes.
    document.body.innerHTML = ""
    fetch.mockReset()
    await mount(form("chunked"))
    pick(document.querySelector("input"), [new File(["abcdef"], "v.mp4")])
    fetch
      .mockResolvedValueOnce(json({ id: "u1", offset: 4, chunkSize: 4 }))
      .mockResolvedValueOnce(json({ offset: 6, token: "t1" }))
      .mockResolvedValueOnce(json(answer(form("chunked"))))
    done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    const [start, chunk] = requests()
    expect(JSON.parse(start[1])).toMatchObject({ resume: "u1", fingerprint: first.fingerprint })
    expect(chunk[0]["X-Phem-Upload-Offset"]).toBe("4")
    expect(await chunk[1].text()).toBe("ef")
  })

  it("gives another fingerprint to another file with the same name and size", async () => {
    await mount(form("chunked"))
    const input = document.querySelector("input")
    const prints = []
    for (const bytes of ["abcdef", "uvwxyz"]) {
      pick(input, [new File([bytes], "v.mp4", { lastModified: 7 })])
      fetch.mockResolvedValueOnce(json({ id: "u1", offset: 6, chunkSize: 4, token: "t1" })).mockResolvedValueOnce(json(answer(form("chunked"))))
      const done = nextDone()
      document.querySelector("form").requestSubmit()
      await done
      prints.push(JSON.parse(fetch.mock.calls.at(-2)[1].body).fingerprint)
    }

    expect(prints[0]).not.toBe(prints[1])
  })

  it("uploads the file again for the next submit: the server ends an upload with its event", async () => {
    await mount(form("chunked"))
    pick(document.querySelector("input"), [new File(["abcdef"], "v.mp4")])
    for (const token of ["t1", "t2"]) {
      fetch.mockResolvedValueOnce(json({ id: "u1", offset: 6, chunkSize: 4, token })).mockResolvedValueOnce(json(answer(form("chunked"))))
      const done = nextDone()
      document.querySelector("form").requestSubmit()
      await done
    }

    const events = fetch.mock.calls.filter(([, init]) => init.headers["X-Phem-Event"])
    expect(events.map(([, init]) => JSON.parse(init.body.get("_phem_uploads[]")).token)).toEqual(["t1", "t2"])
  })

  it("waits while the browser is offline, then sends the chunk", async () => {
    await mount(form("chunked"))
    pick(document.querySelector("input"), [new File(["abcdef"], "v.mp4")])
    fetch.mockResolvedValueOnce(json({ id: "u1", offset: 0, chunkSize: 8 }))
    let online = true
    vi.spyOn(navigator, "onLine", "get").mockImplementation(() => online)
    fetch.mockImplementationOnce(async () => {
      online = false
      throw new TypeError("Failed to fetch")
    })
    fetch.mockResolvedValueOnce(json({ offset: 6, token: "t1" })).mockResolvedValueOnce(json(answer(form("chunked"))))
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2))
    await new Promise((resolve) => setTimeout(resolve, 50))
    expect(fetch).toHaveBeenCalledTimes(2)

    online = true
    window.dispatchEvent(new Event("online"))
    await done
    expect(fetch).toHaveBeenCalledTimes(4)
  })

  it("uploads up to three files at the same time, and sends their tokens in their order", async () => {
    await mount(`<form data-phem-on-submit="c1.save"><input type="file" name="v[]" multiple data-phem-upload="chunked"><button type="submit">Save</button></form>`)
    pick(document.querySelector("input"), ["a", "b", "c", "d"].map((name) => new File([name], name)))
    const starts = []
    const finish = []
    fetch.mockImplementation(async (url, init) => {
      if (init.headers["X-Phem-Upload"] !== "start") return json(answer(form("chunked")))
      const { name } = JSON.parse(init.body)
      starts.push(name)
      await new Promise((resolve) => finish.push(resolve))
      return json({ id: name, offset: 1, chunkSize: 4, token: `t-${name}` })
    })
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await vi.waitFor(() => expect(starts).toHaveLength(3))
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(starts).toHaveLength(3)

    // The end of one upload starts the fourth one.
    finish[0]()
    await vi.waitFor(() => expect(starts).toHaveLength(4))
    for (const resolve of finish) resolve()
    await done

    const event = fetch.mock.calls.find(([, init]) => init.headers["X-Phem-Event"])[1].body
    expect(event.getAll("_phem_uploads[]").map((part) => JSON.parse(part).token)).toEqual(["t-a", "t-b", "t-c", "t-d"])
  })

  it("ends the event with the response of an upload that the server does not take", async () => {
    await mount(form("chunked"))
    pick(document.querySelector("input"), [new File(["abcdef"], "v.mp4")])
    fetch.mockResolvedValueOnce(new Response("Too large", { status: 413 }))
    vi.spyOn(console, "error").mockImplementation(() => {})
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    const event = await done

    expect(event.detail).toMatchObject({ ok: false, status: 413 })
    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("sends the other files of the form with the event, and the progress counts all the files", async () => {
    await mount(form("chunked", '<input type="file" name="photo">'))
    const [video, photo] = document.querySelectorAll("input[type=file]")
    pick(video, [new File(["abcd"], "v.mp4")])
    pick(photo, [new File(["xyzw"], "p.png")])
    fetch
      .mockResolvedValueOnce(json({ id: "u1", offset: 0, chunkSize: 4 }))
      .mockResolvedValueOnce(json({ offset: 4, token: "t1" }))
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
          Object.assign(this, { status: 200, statusText: "OK", response: JSON.stringify(answer(form("chunked", '<input type="file" name="photo">'))) })
          this.upload.dispatchEvent(Object.assign(new Event("progress"), { loaded: 50, total: 100, lengthComputable: true }))
          setTimeout(() => this.dispatchEvent(new Event("load")), 0)
        }
      },
    )
    const progress = []
    window.addEventListener("phem:upload-progress", (e) => progress.push(e.detail.progress))
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(bodies[0].get("photo").name).toBe("p.png")
    expect(bodies[0].getAll("_phem_uploads[]")).toHaveLength(1)
    expect(progress).toEqual([0, 0.5, 0.75])
  })
})

describe("data-phem-upload=direct", () => {
  it("sends the file to the URL of the server, then the event with its token", async () => {
    await mount(form("direct"))
    pick(document.querySelector("input"), [new File(["abcd"], "v.mp4", { type: "video/mp4" })])
    fetch
      .mockResolvedValueOnce(json({ url: "https://bucket.example/v?sig=1", method: "PUT", headers: { "Content-Type": "video/mp4" }, token: "t1" }))
      .mockResolvedValueOnce(json(answer(form("direct"))))
    const sent = []
    vi.stubGlobal(
      "XMLHttpRequest",
      class extends EventTarget {
        upload = new EventTarget()
        headers = {}
        open(method, url) {
          Object.assign(this, { method, url })
        }
        setRequestHeader(name, value) {
          this.headers[name] = value
        }
        getAllResponseHeaders() {
          return ""
        }
        send(body) {
          sent.push({ method: this.method, url: this.url, headers: this.headers, body })
          Object.assign(this, { status: 200, statusText: "OK", response: null })
          setTimeout(() => this.dispatchEvent(new Event("load")), 0)
        }
      },
    )
    const done = nextDone()
    document.querySelector("form").requestSubmit()
    await done

    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({ method: "PUT", url: "https://bucket.example/v?sig=1", headers: { "Content-Type": "video/mp4" } })
    expect(sent[0].body.name).toBe("v.mp4")
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toMatchObject({ mode: "direct", name: "v.mp4", size: 4 })
    expect(JSON.parse(fetch.mock.calls[1][1].body.get("_phem_uploads[]"))).toEqual({ field: "video", token: "t1" })
  })

  it("keeps the token of a file in the storage for the next submit", async () => {
    await mount(form("direct"))
    pick(document.querySelector("input"), [new File(["abcd"], "v.mp4")])
    vi.stubGlobal("XMLHttpRequest", class extends EventTarget {
      upload = new EventTarget()
      open() {}
      setRequestHeader() {}
      getAllResponseHeaders() {
        return ""
      }
      send() {
        Object.assign(this, { status: 200, statusText: "OK", response: null })
        setTimeout(() => this.dispatchEvent(new Event("load")), 0)
      }
    })
    fetch.mockResolvedValueOnce(json({ url: "https://bucket.example/v", method: "PUT", headers: {}, token: "t1" }))
    for (let i = 0; i < 2; i++) {
      fetch.mockResolvedValueOnce(json(answer(form("direct"))))
      const done = nextDone()
      document.querySelector("form").requestSubmit()
      await done
    }

    expect(fetch.mock.calls.filter(([, init]) => init.headers["X-Phem-Upload"] === "start")).toHaveLength(1)
  })

  it("uploads the files of a drop on an element with the attribute", async () => {
    await mount(`<div id="zone" data-phem-on-drop="c1.add" data-phem-upload="chunked">Drop</div>`)
    const root = document.getElementById("phem-root")
    root.setAttribute("data-action", `${root.getAttribute("data-action")} drop->phem#dispatch`)
    await new Promise((resolve) => setTimeout(resolve, 0))
    fetch
      .mockResolvedValueOnce(json({ id: "u1", offset: 0, chunkSize: 8 }))
      .mockResolvedValueOnce(json({ offset: 2, token: "t1" }))
      .mockResolvedValueOnce(json(answer("<div></div>")))
    // jsdom has no DragEvent: a plain event with its data.
    const drop = Object.assign(new Event("drop", { bubbles: true, cancelable: true }), { dataTransfer: { files: [new File(["ab"], "a.png")], getData: () => "" } })
    const done = nextDone()
    document.getElementById("zone").dispatchEvent(drop)
    await done

    expect(JSON.parse(fetch.mock.calls[2][1].body.get("_phem_uploads[]"))).toEqual({ field: "_phem_files[]", token: "t1" })
  })
})
