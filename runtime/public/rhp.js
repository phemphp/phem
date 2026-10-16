// rephp client: Turbo (navigation + stream morphing) and one Stimulus controller that keeps
// the component state in the browser.
//
// The server renders the page with the state in `data-rhp-state-value` (signed JSON).
// On an event, the controller posts {page, state, signature, handler, event}. The server runs
// the PHP handler and answers with Turbo Streams (morph of the changed components) and the
// new state. Events wait in a queue, so each one uses the newest state.

import * as Turbo from "./vendor/turbo.js"
import { Application, Controller } from "./vendor/stimulus.js"

const TEXT_INPUTS = new Set(["text", "search", "email", "url", "tel", "password", "number", "date", "time", "datetime-local", "month", "week", "color", "range"])

function isTextLike(el) {
  return el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && TEXT_INPUTS.has(el.type))
}

class RhpController extends Controller {
  static values = { page: String, endpoint: String, state: String, signature: String }

  connect() {
    this.queue = Promise.resolve()
  }

  // data-action="click->rhp#dispatch input->rhp#dispatch ..." on the root element.
  dispatch(event) {
    const el = this.handlerElement(event)
    if (!el) return
    if (event.type === "submit") event.preventDefault()
    const handler = el.getAttribute(el._rhpAttr)
    const payload = {
      type: event.type,
      value: "value" in el ? el.value : null,
      checked: "checked" in el ? el.checked : null,
      key: event.key ?? null,
      form: el instanceof HTMLFormElement ? Object.fromEntries(new FormData(el)) : null,
    }
    this.queue = this.queue.then(() => this.send(handler, payload)).catch((e) => console.error("[rephp]", e))
  }

  handlerElement(event) {
    const find = (name) => {
      const el = event.target.closest?.(`[data-rh-on-${name}]`)
      if (el && this.element.contains(el)) {
        el._rhpAttr = `data-rh-on-${name}`
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
    const response = await fetch(this.endpointValue, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({ page: this.pageValue, state: this.stateValue, signature: this.signatureValue, handler, event }),
    })
    if (response.status === 409) {
      // The state is not valid any more (a new build, or a new secret): load the page again.
      Turbo.visit(window.location.href, { action: "replace" })
      return
    }
    if (!response.ok) {
      const text = await response.text()
      console.error(`[rephp] event failed (${response.status})`, text)
      return
    }
    const data = await response.json()
    if (data.redirect) {
      // The handler returned a RedirectResponse.
      Turbo.visit(data.redirect)
      return
    }
    this.stateValue = data.state
    this.signatureValue = data.signature
    if (data.streams) Turbo.renderStreamMessage(data.streams)
  }
}

window.Stimulus ??= Application.start()
window.Stimulus.register("rhp", RhpController)
