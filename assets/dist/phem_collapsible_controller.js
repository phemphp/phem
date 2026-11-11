import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { hide, measure, render, show } from "./phem_transition.js"

// Each controller has a prefix for the id of a panel with no id (for `aria-controls`). The morph
// removes an id that the server render does not have, or replaces the panel: the panel at the same
// position gets the same id again.
let count = 0

// The triggers whose `aria-controls` the controller set.
const controlled = new WeakSet()

// The `phem-collapsible` controller: shows and hides its `panel` targets in place, for a section
// that opens, a "more" text or an accordion item. The state stays after the morph of an event.
//
//     <div data-controller="phem-collapsible">
//         <button data-phem-collapsible-target="trigger">Details</button>
//         <div data-phem-collapsible-target="panel" hidden>…</div>
//     </div>
//
// A click on a trigger opens and closes the panels. The `toggle`, `open` and `close` actions do
// the same from another element.
//
// The `open` value is the state: the server render gives its first value, and a server render
// with a new `open` value sets it. The controller element and the panels get `data-open` or
// `data-closed`; see phem_transition.js for the animations. A trigger gets `aria-expanded`,
// `aria-controls` and `data-panel-open`. A panel gets the `--collapsible-panel-height` and
// `--collapsible-panel-width` CSS variables (the size of its content), for a transition of its
// height:
//
//     .panel { height: var(--collapsible-panel-height); overflow: hidden; transition: height .2s }
//     .panel[data-starting-style], .panel[data-ending-style] { height: 0 }
//
// With the `hiddenUntilFound` value, a closed panel has `hidden="until-found"`: the search of the
// browser (Ctrl+F) finds its text, and opens it.
export default class PhemCollapsibleController extends Controller {
  static targets = ["trigger", "panel"]
  static values = { open: Boolean, hiddenUntilFound: Boolean }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#renderParts(),
    })
    this.state.connect()

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("beforematch", this.onBeforeMatch)
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("beforematch", this.onBeforeMatch)
    this.rendered = undefined
  }

  toggle() {
    this.#set(!this.openValue)
  }

  open() {
    this.#set(true)
  }

  close() {
    this.#set(false)
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    this.#apply()
  }

  // A target that the morph adds gets the state.
  panelTargetConnected(el) {
    if (this.rendered === undefined) return

    render(el, this.openValue, { hidden: this.#hidden })
    this.#renderParts()
  }

  triggerTargetConnected() {
    if (this.rendered !== undefined) this.#renderParts()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const trigger = this.triggerTargets.find((el) => el.contains(event.target))
    if (trigger && !trigger.disabled) this.toggle()
  }

  // The search of the browser found a text in a closed panel.
  onBeforeMatch = (event) => {
    if (this.panelTargets.includes(event.target)) this.#set(true)
  }

  #set(open) {
    if (open === this.openValue && this.rendered === open) return

    this.openValue = open
    this.#apply()
  }

  // The state with its animations.
  #apply() {
    this.rendered = this.openValue

    for (const panel of this.panelTargets) {
      if (this.openValue) {
        show(panel)
        measure(panel, "collapsible")
      } else {
        measure(panel, "collapsible")
        hide(panel, { hidden: this.#hidden })
      }
    }

    this.#renderParts()
  }

  // The state with no animation.
  #render() {
    this.rendered = this.openValue
    for (const panel of this.panelTargets) render(panel, this.openValue, { hidden: this.#hidden })
    this.#renderParts()
  }

  #renderParts() {
    const open = this.openValue
    this.element.toggleAttribute("data-open", open)
    this.element.toggleAttribute("data-closed", !open)

    this.prefix ??= `phem-collapsible-${++count}`
    const ids = this.panelTargets.map((panel, i) => {
      if (!panel.id) panel.id = `${this.prefix}-${i}`

      return panel.id
    })

    for (const panel of this.panelTargets) if (open) measure(panel, "collapsible")

    for (const trigger of this.triggerTargets) {
      // The server render can give its own `aria-controls`.
      if (ids.length > 0 && (!trigger.hasAttribute("aria-controls") || controlled.has(trigger))) {
        trigger.setAttribute("aria-controls", ids.join(" "))
        controlled.add(trigger)
      }

      trigger.setAttribute("aria-expanded", String(open))
      trigger.toggleAttribute("data-panel-open", open)
    }
  }

  get #hidden() {
    return this.hiddenUntilFoundValue ? "until-found" : true
  }

  // The attributes of the state: the open state of the controller element and of the panels,
  // `aria-expanded` and `data-panel-open` on a trigger.
  #owns(el, name) {
    if (el === this.element) return name === "data-open" || name === "data-closed"
    if (this.panelTargets.includes(el)) return OPEN_ATTRIBUTES.has(name)
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-panel-open"

    return false
  }
}
