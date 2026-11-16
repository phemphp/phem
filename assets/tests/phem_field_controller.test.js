import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemFieldController from "../dist/phem_field_controller.js"
import PhemFormController from "../dist/phem_form_controller.js"
import { mount, nextDone, respond, settle } from "./support.js"

// A redirect is a Turbo visit: jsdom cannot load a page.
vi.mock("@hotwired/turbo", async (original) => ({ ...(await original()), visit: vi.fn() }))

let application

beforeAll(() => {
  application = Application.start()
  application.register("phem", PhemController)
  application.register("phem-field", PhemFieldController)
  application.register("phem-form", PhemFormController)
})

beforeEach(() => {
  vi.stubGlobal("fetch", vi.fn())
})

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.innerHTML = ""
})

// A field with a label, a required control, a description and two errors.
function field(attributes = "", control = 'name="name" required') {
  return `<div id="field" data-controller="phem-field" ${attributes}>
      <label id="label" data-phem-field-target="label">Name</label>
      <input id="name" ${control} data-phem-field-target="control" />
      <p id="help" data-phem-field-target="description">Visible on your profile</p>
      <p id="missing" data-phem-field-target="error" data-match="valueMissing" hidden>Enter your name.</p>
      <p id="message" data-phem-field-target="error" hidden></p>
    </div>`
}

// A form with an `onSubmit` handler, the `fields` and a button that submits.
function form(fields, attributes = "") {
  return `<form id="form" data-phem-on-submit="c1.save" data-controller="phem-form" ${attributes}>
      ${fields}
      <button id="submit" type="submit">Save</button>
    </form>`
}

const el = (id) => document.getElementById(id)

function type(id, value) {
  el(id).value = value
  el(id).dispatchEvent(new Event("input", { bubbles: true }))
}

function submit() {
  const event = new SubmitEvent("submit", { bubbles: true, cancelable: true, submitter: el("submit") })
  el("form").dispatchEvent(event)

  return event
}

describe("phem-field", () => {
  it("links the label and the description, and shows no error before a validation", async () => {
    await mount(field())

    expect(el("label").htmlFor).toBe("name")
    expect(el("name").getAttribute("aria-describedby")).toBe("help")
    expect(el("name").hasAttribute("aria-invalid")).toBe(false)
    expect(el("missing").hidden).toBe(true)
    expect(el("field").hasAttribute("data-valid")).toBe(false)
    expect(el("field").hasAttribute("data-invalid")).toBe(false)
  })

  it("sets the state attributes on all the parts", async () => {
    await mount(field())

    el("name").focus()
    for (const id of ["field", "label", "name", "help", "missing"]) expect(el(id).hasAttribute("data-focused")).toBe(true)

    type("name", "Ada")
    expect(el("field").hasAttribute("data-dirty")).toBe(true)
    expect(el("help").hasAttribute("data-filled")).toBe(true)

    el("name").blur()
    expect(el("label").hasAttribute("data-touched")).toBe(true)
    expect(el("label").hasAttribute("data-focused")).toBe(false)
  })

  it("validates on blur and shows the error of the key", async () => {
    await mount(field('data-phem-field-validation-mode-value="onBlur"'))

    el("name").focus()
    el("name").blur()

    expect(el("field").hasAttribute("data-invalid")).toBe(true)
    expect(el("name").getAttribute("aria-invalid")).toBe("true")
    expect(el("missing").hidden).toBe(false)
    expect(el("missing").hasAttribute("data-open")).toBe(true)
    expect(el("message").hidden).toBe(false)
    expect(el("message").textContent).toBe(el("name").validationMessage)
    expect(el("name").getAttribute("aria-describedby")).toBe("help missing message")

    // An invalid field validates again on each change.
    type("name", "Ada")
    expect(el("field").hasAttribute("data-valid")).toBe(true)
    expect(el("name").hasAttribute("aria-invalid")).toBe(false)
    expect(el("name").getAttribute("aria-describedby")).toBe("help")
  })

  it("validates on Enter in the input, in the onSubmit mode", async () => {
    await mount(field())
    document.getElementById("name").dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true }))

    expect(document.getElementById("missing").hidden).toBe(false)
    expect(document.getElementById("name").hasAttribute("data-touched")).toBe(true)
  })

  it("validates on change after the debounce time", async () => {
    await mount(field('data-phem-field-validation-mode-value="onChange" data-phem-field-validation-debounce-time-value="100"', 'name="code" pattern="[0-9]{4}"'))
    vi.useFakeTimers()
    try {
      type("name", "12a")
      expect(el("field").hasAttribute("data-invalid")).toBe(false)

      vi.advanceTimersByTime(100)
      expect(el("field").hasAttribute("data-invalid")).toBe(true)
      expect(el("missing").hidden).toBe(true)
      expect(el("message").hidden).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })

  it("shows the error with the valid match when the field is valid", async () => {
    await mount(`<div id="field" data-controller="phem-field" data-phem-field-validation-mode-value="onChange">
        <input id="name" name="name" required data-phem-field-target="control" />
        <p id="ok" data-phem-field-target="error" data-match="valid" hidden>Looks good.</p>
      </div>`)

    type("name", "Ada")
    expect(el("ok").hidden).toBe(false)
    expect(el("ok").textContent).toBe("Looks good.")
  })

  it("disables the control with the disabled value", async () => {
    await mount(field('data-phem-field-disabled-value="true"'))

    expect(el("name").disabled).toBe(true)
    expect(el("label").hasAttribute("data-disabled")).toBe(true)

    el("field").setAttribute("data-phem-field-disabled-value", "false")
    await settle()
    expect(el("name").disabled).toBe(false)
    expect(el("label").hasAttribute("data-disabled")).toBe(false)
  })

  it("gets data-disabled from a disabled fieldset", async () => {
    await mount(`<fieldset disabled><legend>Address</legend>${field()}</fieldset>`)

    expect(el("field").hasAttribute("data-disabled")).toBe(true)
  })

  it("focuses the control on a click on a label that is not a native label", async () => {
    await mount(`<div id="field" data-controller="phem-field">
        <span id="label" data-phem-field-target="label">Name</span>
        <input id="name" name="name" data-phem-field-target="control" />
      </div>`)

    expect(el("name").getAttribute("aria-labelledby")).toBe("label")
    el("label").click()
    expect(document.activeElement).toBe(el("name"))
  })

  it("keeps the server error until the user changes the value", async () => {
    await mount(`<div id="field" data-controller="phem-field">
        <input id="name" name="name" aria-invalid="true" aria-describedby="name-error" data-phem-field-target="control" />
        <p id="name-error" data-phem-field-target="error">This name is taken.</p>
        <p id="message" data-phem-field-target="error" hidden></p>
      </div>`)

    expect(el("field").hasAttribute("data-invalid")).toBe(true)
    expect(el("name-error").hidden).toBe(false)
    // The no-match error does not repeat the message of the server.
    expect(el("message").hidden).toBe(true)
    expect(el("name").validity.customError).toBe(true)
    expect(el("name").validationMessage).toBe("This name is taken.")

    type("name", "Boots")
    expect(el("field").hasAttribute("data-invalid")).toBe(false)
    expect(el("name").hasAttribute("aria-invalid")).toBe(false)
    expect(el("name-error").hidden).toBe(true)
    expect(el("name").hasAttribute("aria-describedby")).toBe(false)
    expect(el("name").validity.valid).toBe(true)
  })

  it("keeps the state after the morph", async () => {
    await mount(`${field('data-phem-field-validation-mode-value="onBlur"')}<button id="save" data-phem-on-click="c1.save">Save</button>`)

    el("name").focus()
    el("name").blur()
    expect(el("missing").hidden).toBe(false)

    respond(`${field('data-phem-field-validation-mode-value="onBlur"')}<button id="save" data-phem-on-click="c1.save">Save</button>`)
    const done = nextDone()
    el("save").click()
    await done
    await settle()

    expect(el("field").hasAttribute("data-invalid")).toBe(true)
    expect(el("field").hasAttribute("data-touched")).toBe(true)
    expect(el("missing").hidden).toBe(false)
    expect(el("name").getAttribute("aria-invalid")).toBe("true")
  })
})

describe("phem-field with a bound control with no id", () => {
  it("does not point the label to the temporary id of the morph", async () => {
    const markup = `<div id="field" data-controller="phem-field">
        <label id="label" data-phem-field-target="label">Name</label>
        <input name="name" data-phem-bind="name" data-phem-field-target="control" />
      </div>
      <button id="save" data-phem-on-click="c1.save">Save</button>`
    await mount(markup)
    const control = () => document.querySelector("[data-phem-bind]")

    expect(el("label").htmlFor).toBe("")
    expect(control().getAttribute("aria-labelledby")).toBe("label")

    respond(markup)
    const done = nextDone()
    el("save").click()
    await done
    await settle()

    expect(control().id).toBe("")
    expect(el("label").htmlFor).toBe("")
    expect(control().getAttribute("aria-labelledby")).toBe("label")
  })
})

describe("phem-form", () => {
  it("sets novalidate on the form", async () => {
    await mount(form(field()))

    expect(el("form").noValidate).toBe(true)
  })

  it("stops the submit of an invalid field before the phem event, and focuses it", async () => {
    await mount(form(field()))
    const invalid = vi.fn()
    el("form").addEventListener("phem-form:invalid", invalid)

    const event = submit()
    await settle()

    expect(event.defaultPrevented).toBe(true)
    expect(fetch).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(el("name"))
    expect(el("missing").hidden).toBe(false)
    expect(invalid.mock.calls[0][0].detail.fields).toEqual([el("field")])

    // In the onSubmit mode, the field validates on each change after the submit.
    type("name", "Ada")
    expect(el("field").hasAttribute("data-valid")).toBe(true)
  })

  it("sends the submit of a valid form", async () => {
    await mount(form(field()))
    type("name", "Ada")

    respond(form(field()))
    const done = nextDone()
    submit()
    await done

    expect(fetch).toHaveBeenCalledTimes(1)
  })

  it("gives its validation mode to the fields", async () => {
    await mount(form(field(), 'data-phem-form-validation-mode-value="onBlur"'))

    el("name").focus()
    el("name").blur()
    expect(el("field").hasAttribute("data-invalid")).toBe(true)
  })

  it("validates a control that is not in a field", async () => {
    await mount(form('<input id="loose" name="loose" required />'))

    const event = submit()
    expect(event.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(el("loose"))
  })

  it("does not submit a field with a server error", async () => {
    await mount(form(`<div id="field" data-controller="phem-field">
        <input id="name" name="name" value="Shoes" aria-invalid="true" aria-describedby="name-error" data-phem-field-target="control" />
        <p id="name-error" data-phem-field-target="error">This name is taken.</p>
      </div>`))

    expect(submit().defaultPrevented).toBe(true)
    expect(fetch).not.toHaveBeenCalled()
  })

  // The form of the Base UI demo: one error part, with the message of the server, and of the client.
  const homepage = (url, error = "") => form(`<div id="field" data-controller="phem-field">
      <input id="url" type="url" name="url" required value="${url}" ${error ? 'aria-invalid="true" aria-describedby="url-error"' : ""} data-phem-field-target="control" />
      <p id="url-error" data-phem-field-target="error">${error}</p>
    </div>`)

  // `markup` is null for an answer with no stream: the server renders the same HTML.
  async function send(markup) {
    if (markup === null) respond("", {}, { streams: "" })
    else respond(markup)
    const done = nextDone()
    expect(submit().defaultPrevented).toBe(true)
    await done
    await settle()
  }

  it("selects the text of the first invalid control on a submit", async () => {
    await mount(form(field("", 'name="code" pattern="[0-9]{4}"')))
    type("name", "abc")

    expect(submit().defaultPrevented).toBe(true)
    expect(fetch).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(el("name"))
    expect([el("name").selectionStart, el("name").selectionEnd]).toEqual([0, 3])
  })

  it("focuses and selects the first control with a server error after the submit", async () => {
    await mount(homepage("https://example.com"))
    el("submit").focus()
    // A URL input has no selectionStart: check the call of select().
    const select = vi.spyOn(HTMLInputElement.prototype, "select")

    await send(homepage("https://example.com", "The example domain is not allowed"))

    expect(el("url").getAttribute("aria-invalid")).toBe("true")
    expect(document.activeElement).toBe(el("url"))
    expect(select.mock.contexts).toContain(el("url"))
  })

  it("does not move the focus after a submit with no server error", async () => {
    await mount(homepage("https://example.com"))
    el("submit").focus()

    await send(homepage("https://example.com"))

    expect(document.activeElement).toBe(el("submit"))
  })

  it("does not move the focus for the phem:done of a phem:click button of the form", async () => {
    const markup = (error = "") => form(`<div id="field" data-controller="phem-field">
        <input id="url" type="url" name="url" value="https://example.com" ${error ? 'aria-invalid="true"' : ""} data-phem-field-target="control" />
      </div>
      <button id="check" type="button" data-phem-on-click="c1.check">Check</button>`)
    await mount(markup())
    el("check").focus()

    respond(markup("The example domain is not allowed"))
    const done = nextDone()
    el("check").click()
    await done
    await settle()

    expect(el("url").getAttribute("aria-invalid")).toBe("true")
    expect(document.activeElement).toBe(el("check"))
  })

  // The form of the Base UI demo with an `onChange` of the form, and a checkbox that sends it.
  const checked = (error = "") => homepage("https://example.com", error)
    .replace('data-phem-on-submit="c1.save"', 'data-phem-on-submit="c1.save" data-phem-on-change="c1.check"')
    .replace('<button id="submit"', '<input id="agree" type="checkbox" name="agree" /><button id="submit"')

  it("does not move the focus for the phem:done of an onChange of the form", async () => {
    await mount(checked())
    el("submit").focus()

    respond(checked("The example domain is not allowed"))
    const done = nextDone()
    el("agree").click()
    await done
    await settle()

    expect(el("url").getAttribute("aria-invalid")).toBe("true")
    expect(document.activeElement).toBe(el("submit"))
  })

  it("does not move the focus later after a submit that a listener cancels", async () => {
    await mount(checked())
    el("submit").focus()
    // A confirm dialog that the user cancels: the submit sends nothing.
    window.addEventListener("phem:before-queue", (event) => event.preventDefault(), { once: true })
    submit()
    expect(fetch).not.toHaveBeenCalled()

    respond(checked("The example domain is not allowed"))
    const done = nextDone()
    el("agree").click()
    await done
    await settle()

    expect(document.activeElement).toBe(el("submit"))
  })

  it("does not move the focus to a control with an error of the browser after the submit", async () => {
    const markup = form(field('data-phem-field-validation-mode-value="onChange"', 'name="name" pattern="[0-9]+" value="abc"') + '<button id="skip" type="submit" formnovalidate>Skip</button>')
    await mount(markup)
    type("name", "abc")
    expect(el("name").getAttribute("aria-invalid")).toBe("true")
    el("skip").focus()

    respond(markup)
    const done = nextDone()
    el("form").dispatchEvent(new SubmitEvent("submit", { bubbles: true, cancelable: true, submitter: el("skip") }))
    await done
    await settle()

    expect(document.activeElement).toBe(el("skip"))
  })

  it("focuses the first visible control with a server error", async () => {
    const markup = (error) => form(`<div id="hidden-field" data-controller="phem-field" hidden>
        <input id="code" name="code" value="1" ${error ? 'aria-invalid="true"' : ""} data-phem-field-target="control" />
      </div>
      <div id="field" data-controller="phem-field">
        <input id="url" type="url" name="url" value="https://example.com" ${error ? 'aria-invalid="true"' : ""} data-phem-field-target="control" />
      </div>`)
    await mount(markup(false))
    el("submit").focus()

    respond(markup(true))
    const done = nextDone()
    submit()
    await done
    await settle()

    expect(document.activeElement).toBe(el("url"))
  })

  it("shows the same server error again after a second submit", async () => {
    await mount(homepage("https://example.com"))

    await send(homepage("https://example.com", "The example domain is not allowed"))
    expect(el("field").hasAttribute("data-invalid")).toBe(true)

    // The user types the same value again: the answer has the same HTML.
    type("url", "https://example.co")
    type("url", "https://example.com")
    expect(el("field").hasAttribute("data-invalid")).toBe(false)

    await send(null)
    expect(el("field").hasAttribute("data-invalid")).toBe(true)
    expect(el("url").getAttribute("aria-invalid")).toBe("true")
    expect(el("url-error").hidden).toBe(false)
    expect(el("url-error").textContent).toBe("The example domain is not allowed")
  })

  it("keeps the change of the user during the request of a submit", async () => {
    await mount(homepage("https://example.com"))

    await send(homepage("https://example.com", "The example domain is not allowed"))
    type("url", "https://www.example.com")
    respond("", {}, { streams: "" })
    const done = nextDone()
    submit()
    type("url", "https://phem.dev")
    await done
    await settle()

    expect(el("field").hasAttribute("data-invalid")).toBe(false)
    expect(el("url-error").hidden).toBe(true)
  })

  it("keeps the state after a redirect", async () => {
    await mount(homepage("https://example.com"))
    await send(homepage("https://example.com", "The example domain is not allowed"))

    type("url", "https://example.co")
    type("url", "https://example.com")
    fetch.mockResolvedValueOnce(new Response(JSON.stringify({ redirect: "/done" }), { status: 200 }))
    const done = nextDone()
    submit()
    await done
    await settle()

    expect(el("field").hasAttribute("data-invalid")).toBe(false)
  })

  it("shows the server error of a bound control again after an event with no new HTML", async () => {
    const markup = (error = "") => `<div id="field" data-controller="phem-field">
        <input id="name" name="name" data-phem-bind="name" ${error ? 'aria-invalid="true" aria-describedby="name-error"' : ""} data-phem-field-target="control" />
        <p id="name-error" data-phem-field-target="error">${error}</p>
      </div>
      <button id="save" data-phem-on-click="c1.save">Save</button>`
    await mount(markup("This name is taken."))

    type("name", "Shoes")
    expect(el("field").hasAttribute("data-invalid")).toBe(false)

    respond("", {}, { streams: "" })
    const done = nextDone()
    el("save").click()
    await done
    await settle()

    expect(el("field").hasAttribute("data-invalid")).toBe(true)
    expect(el("name-error").textContent).toBe("This name is taken.")
  })

  it("does not show a client message two times in a field with a part of the server", async () => {
    await mount(form(`<div id="field" data-controller="phem-field">
        <input id="url" name="url" required aria-invalid="true" aria-describedby="url-error" data-phem-field-target="control" />
        <p id="url-error" data-phem-field-target="error">Not allowed</p>
        <p id="message" data-phem-field-target="error" hidden></p>
      </div>`))

    type("url", "")
    submit()

    expect(el("url-error").hidden).toBe(false)
    expect(el("url-error").textContent).toBe(el("url").validationMessage)
    expect(el("message").hidden).toBe(true)
  })

  it("keeps the elements in the text of an error", async () => {
    await mount(form(`<div id="field" data-controller="phem-field">
        <input id="name" name="name" required data-phem-field-target="control" />
        <p id="missing" data-phem-field-target="error" data-match="valueMissing" hidden>
          Enter <a id="help" href="#help">a name</a>.
        </p>
      </div>`))

    submit()

    expect(el("missing").hidden).toBe(false)
    expect(el("help")).not.toBeNull()
  })

  it("shows a client error in the error part of the server after a server error", async () => {
    await mount(homepage("https://example.com"))

    await send(homepage("https://example.com", "The example domain is not allowed"))
    type("url", "")
    const event = submit()
    await settle()

    expect(event.defaultPrevented).toBe(true)
    expect(el("field").hasAttribute("data-invalid")).toBe(true)
    expect(el("url-error").hidden).toBe(false)
    expect(el("url-error").textContent).toBe(el("url").validationMessage)
    expect(el("url").getAttribute("aria-describedby")).toBe("url-error")

    // A valid value hides it.
    type("url", "https://phem.dev")
    expect(el("field").hasAttribute("data-valid")).toBe(true)
    expect(el("url-error").hidden).toBe(true)

    // The same server error again, with no morph: the part shows the message of the server.
    type("url", "https://example.com")
    await send(null)
    expect(el("url-error").hidden).toBe(false)
    expect(el("url-error").textContent).toBe("The example domain is not allowed")
  })
})
