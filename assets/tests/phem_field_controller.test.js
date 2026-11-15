import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest"
import { Application } from "@hotwired/stimulus"
import PhemController from "../dist/phem_controller.js"
import PhemFieldController from "../dist/phem_field_controller.js"
import PhemFormController from "../dist/phem_form_controller.js"
import { mount, nextDone, respond, settle } from "./support.js"

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
})
