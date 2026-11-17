import { hide, show } from "./phem_transition.js"

// The `viewport` part of a popup that several triggers share (`phem-tooltip`, `phem-popover`), as
// the Viewport of Base UI: it shows the payload of the active trigger, and animates the change of
// the content.
//
// The payload is the `data-<identifier>-payload` attribute of the trigger. When the viewport has a
// `<template data-payload>` with this value, the content is a copy of the template. Else the
// content is the payload as text. A trigger with no payload shows the content of the server
// render, with no template. The content is in a `div` with
// `data-current`. When the active trigger changes while the popup is open, the old content stays
// in a `div` with `data-previous` and `data-ending-style` until its animations end, and the new
// content gets `data-starting-style` for its first frame. The viewport gets
// `data-activation-direction`: the direction of the new trigger from the old one (`left` or
// `right`, and `up` or `down`). The old content gets `position: absolute` at its place, so the
// two contents can cross.
export class PopupViewport {
  #viewport
  #payload
  // The content of the server render, and its templates.
  #server = []
  #templates = []

  // `viewport` gives the element, or null. `payload` gives the payload of a trigger, or null.
  constructor({ viewport, payload }) {
    this.#viewport = viewport
    this.#payload = payload
  }

  // Keeps the content of the server render: at connect, and after the morph.
  capture() {
    const viewport = this.#viewport()
    if (!viewport || [...viewport.children].some((el) => el.matches("[data-current], [data-previous]"))) return

    const nodes = [...viewport.childNodes]
    this.#templates = nodes.filter((node) => node instanceof HTMLTemplateElement && node.hasAttribute("data-payload"))
    this.#server = nodes.filter((node) => !this.#templates.includes(node)).map((node) => node.cloneNode(true))
  }

  // Shows the content of `trigger` at once.
  render(trigger) {
    const viewport = this.#viewport()
    if (!viewport) return

    viewport.removeAttribute("data-activation-direction")
    if (this.#payload(trigger) === null && !viewport.querySelector(":scope > [data-current]")) return

    viewport.replaceChildren(...this.#templates, this.#content(trigger))
  }

  // Shows the content of `trigger` after the content of `from`, with the animations.
  change(from, trigger, direction) {
    const viewport = this.#viewport()
    if (!viewport) return

    if (direction) viewport.setAttribute("data-activation-direction", direction)
    else viewport.removeAttribute("data-activation-direction")

    const current = viewport.querySelector(":scope > [data-current]")
    if (this.#payload(from) === null && this.#payload(trigger) === null && !current) return

    for (const old of viewport.querySelectorAll(":scope > [data-previous]")) old.remove()

    const next = this.#content(trigger)
    if (!current) {
      viewport.replaceChildren(...this.#templates, next)

      return
    }

    // The old content stays at its place while it leaves.
    const place = { top: current.offsetTop, left: current.offsetLeft, width: current.offsetWidth }
    current.removeAttribute("data-current")
    current.setAttribute("data-previous", "")
    Object.assign(current.style, { position: "absolute", top: `${place.top}px`, left: `${place.left}px`, width: `${place.width}px` })
    viewport.append(next)

    show(next, { hidden: false })
    hide(current, { hidden: false, onEnd: () => current.remove() })
  }

  #content(trigger) {
    const content = document.createElement("div")
    content.setAttribute("data-current", "")

    const payload = this.#payload(trigger)
    const template = payload === null ? null : this.#templates.find((el) => el.getAttribute("data-payload") === payload)
    if (template) content.append(template.content.cloneNode(true))
    else if (payload === null) content.append(...this.#server.map((node) => node.cloneNode(true)))
    else content.textContent = payload

    return content
  }
}
