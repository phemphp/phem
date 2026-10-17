// The `mamu` Stimulus controller: it keeps the component state in the browser, and it morphs
// the page with Turbo Streams. Turbo also gives the navigation (Turbo Drive).
//
// StimulusBundle registers it from the assets/controllers.json of the application (see
// ../package.json). Without StimulusBundle, register it: `application.register("mamu", MamuController)`.
//
// The server renders the page with the state in `data-mamu-state-value` (signed JSON).
// On an event, the controller posts {state, signature, handler, event} to the URL of the page,
// so the access rules of the page apply. The server runs the PHP handler and answers with
// Turbo Streams (morph of the changed components) and the new state. Events wait in a queue,
// so each one uses the newest state.

import * as Turbo from "@hotwired/turbo"
import { Controller } from "@hotwired/stimulus"

const TEXT_INPUTS = new Set(["text", "search", "email", "url", "tel", "password", "number", "date", "time", "datetime-local", "month", "week", "color", "range"])

function isTextLike(el) {
  return el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && TEXT_INPUTS.has(el.type))
}

// The fields of a form as [name, value] pairs, so a name can have more than one value
// (`<select multiple>`, checkboxes). The button that submits the form is a field too. A file
// cannot go in the JSON body, so it is left out.
function formFields(form, submitter) {
  return [...new FormData(form, submitter ?? null)].filter(([, value]) => typeof value === "string")
}

export default class MamuController extends Controller {
  static values = { state: String, signature: String }

  connect() {
    this.queue = Promise.resolve()
  }

  // data-action="click->mamu#dispatch input->mamu#dispatch ..." on the root element.
  dispatch(event) {
    const el = this.handlerElement(event)
    if (!el) return
    if (event.type === "submit") event.preventDefault()
    const handler = el.getAttribute(el._mamuAttr)
    const payload = {
      type: event.type,
      value: "value" in el ? el.value : null,
      checked: "checked" in el ? el.checked : null,
      key: event.key ?? null,
      form: el instanceof HTMLFormElement ? formFields(el, event.submitter) : null,
    }
    this.queue = this.queue.then(() => this.send(handler, payload)).catch((e) => console.error("[mamu]", e))
  }

  handlerElement(event) {
    const find = (name) => {
      const el = event.target.closest?.(`[data-mamu-on-${name}]`)
      if (el && this.element.contains(el)) {
        el._mamuAttr = `data-mamu-on-${name}`
        return el
      }
      return null
    }
    switch (event.type) {
      // React's onChange fires on each key for text inputs.
      case "input": {
        const el = find("input")
        if (el) return el
        const change = find("change")
        return change && isTextLike(change) ? change : null
      }
      case "change": {
        const el = find("change")
        return el && !isTextLike(el) ? el : null
      }
      default:
        return find(event.type)
    }
  }

  async send(handler, event) {
    // The URL of the page, from the browser: always the same origin.
    const response = await fetch(window.location.href, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-Mamu-Event": "1" },
      body: JSON.stringify({ state: this.stateValue, signature: this.signatureValue, handler, event }),
    })
    if (response.status === 409) {
      // The state is not valid any more (a new build, or a new secret): load the page again.
      Turbo.visit(window.location.href, { action: "replace" })
      return
    }
    if (!response.ok) {
      const text = await response.text()
      console.error(`[mamu] event failed (${response.status})`, text)
      return
    }
    const data = await response.json()
    if (data.redirect) {
      // The handler returned a RedirectResponse. Turbo sets window.location for a URL that it
      // cannot visit, so a javascript: URL would run: visit only http(s) URLs.
      const url = new URL(data.redirect, window.location.href)
      if (url.protocol === "http:" || url.protocol === "https:") Turbo.visit(url.href)
      else console.error("[mamu] redirect to a URL that is not http(s) was blocked", data.redirect)
      return
    }
    this.stateValue = data.state
    this.signatureValue = data.signature
    if (data.streams) Turbo.renderStreamMessage(data.streams)
  }
}
