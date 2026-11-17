import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { realId } from "./phem_ids.js"
import { PhemStorage } from "./phem_storage.js"
import { collapse, fit, hide, measure, render, settle, show, showFound } from "./phem_transition.js"

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
// `data-closed`, and `data-starting-style` and `data-ending-style` during an open and a close;
// see phem_transition.js for the animations. A trigger gets `aria-expanded`,
// `aria-controls` and `data-panel-open`. A panel with no `id` gets no id: the trigger points to the
// panels with element references (`ariaControlsElements`). A panel gets the `--collapsible-panel-height` and
// `--collapsible-panel-width` CSS variables, for a transition of its height: the size of its
// content in px during the open and the close, and `auto` when the panel is open:
//
//     .panel { height: var(--collapsible-panel-height); overflow: hidden; transition: height .2s }
//     .panel[data-starting-style], .panel[data-ending-style] { height: 0 }
//
// With the `hiddenUntilFound` value, a closed panel has `hidden="until-found"`: the search of the
// browser (Ctrl+F) finds its text, and opens it. A panel with
// `data-phem-collapsible-hidden-until-found` does the same.
//
// With the `persist` value (a key, for example `promo-2026`), the state goes in `localStorage`:
// a banner that the user closed stays closed after a reload. The stored state wins over the
// `open` value of the server render on load. Only a click or an action of the user stores the
// state: an open by the search of the browser or by a server render does not. The other
// controllers with the same key, also in the other tabs, get the stored state.
export default class PhemCollapsibleController extends Controller {
  static targets = ["trigger", "panel"]
  static values = { open: Boolean, hiddenUntilFound: Boolean, persist: String }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#morphed(),
    })
    this.state.connect()

    this.#listen()
    const stored = this.#stored()
    if (stored !== null) this.openValue = stored

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("beforematch", this.onBeforeMatch)
    this.#render()
  }

  disconnect() {
    this.listening?.abort()
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("beforematch", this.onBeforeMatch)
    this.rendered = undefined
  }

  toggle() {
    this.#set(!this.openValue, { store: true })
  }

  open() {
    this.#set(true, { store: true })
  }

  close() {
    this.#set(false, { store: true })
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    this.#apply()
  }

  // A new key takes its stored state, or the `open` value of the server render.
  persistValueChanged(value, previous) {
    if (this.rendered === undefined || previous === undefined) return

    this.#listen()
    const stored = this.#stored()
    const server = this.state.server
    this.#set(stored ?? (server !== null && server !== "false" && server !== "0"))
  }

  // A target that the morph adds gets the state.
  panelTargetConnected(el) {
    if (this.rendered === undefined) return

    render(el, this.openValue, { hidden: this.#hiddenOf(el) })
    this.#fit([el])
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
    if (this.panelTargets.includes(event.target)) this.#set(true, { found: event.target })
  }

  #set(open, { store = false, found = null } = {}) {
    if (open !== this.openValue || this.rendered !== open) {
      this.openValue = open
      this.#apply({ found })
    }

    if (store) this.#store(open)
  }

  // The state with its animations. The `found` panel, which the search of the browser found,
  // opens with no animation.
  #apply({ found = null } = {}) {
    this.rendered = this.openValue

    const closing = []

    for (const panel of this.panelTargets) {
      if (panel === found) {
        showFound(panel, "collapsible")
      } else if (this.openValue) {
        show(panel)
        measure(panel, "collapsible")
        settle(panel, "collapsible")
      } else {
        // A transition does not start from `auto`: the close starts from the size in px.
        measure(panel, "collapsible")
        void panel.offsetHeight
        closing.push(hide(panel, { hidden: this.#hiddenOf(panel), onEnd: () => collapse(panel, "collapsible") }))
      }
    }

    this.#renderRoot(closing)
    this.#renderParts()
  }

  // The controller element has the state of the animation, as the panels. Its
  // `[data-ending-style]` stays until the panels are hidden: it does not wait for another
  // animation in the element (an icon of the trigger).
  #renderRoot(closing) {
    const change = (this.change = {})

    if (this.openValue) {
      show(this.element, { hidden: false })

      return
    }

    this.element.removeAttribute("data-open")
    this.element.removeAttribute("data-starting-style")
    this.element.setAttribute("data-closed", "")
    this.element.setAttribute("data-ending-style", "")

    Promise.all(closing).then(() => {
      if (this.change === change) this.element.removeAttribute("data-ending-style")
    })
  }

  // The state with no animation.
  #render() {
    this.rendered = this.openValue
    for (const panel of this.panelTargets) render(panel, this.openValue, { hidden: this.#hiddenOf(panel) })
    this.#fit()
    this.#renderParts()
  }

  // The morph can remove the `style` attribute of a panel: the open panel gets its size again.
  #morphed() {
    this.#fit()
    this.#renderParts()
  }

  // The size of the open panels with no animation.
  #fit(panels = this.panelTargets) {
    if (!this.openValue) return

    for (const panel of panels) fit(panel, "collapsible")
  }

  #renderParts() {
    const open = this.openValue
    render(this.element, open, { hidden: false })

    const panels = this.panelTargets
    for (const trigger of this.triggerTargets) {
      // The server render can give its own `aria-controls`.
      if (panels.length > 0 && (!trigger.hasAttribute("aria-controls") || controlled.has(trigger)) && controls(trigger, panels)) controlled.add(trigger)

      trigger.setAttribute("aria-expanded", String(open))
      trigger.toggleAttribute("data-panel-open", open)
    }
  }

  // The stored state, or null with no `persist` value or no stored state.
  #stored() {
    const value = this.#storage?.get() ?? null

    return value === null ? null : value === "true"
  }

  #store(open) {
    this.#storage?.set(String(open))
  }

  // The state that another controller with the same key stored.
  #listen() {
    this.listening?.abort()
    this.listening = new AbortController()
    this.#storage?.listen((value) => this.#set(value === "true"), this.listening.signal)
  }

  get #storage() {
    return this.persistValue ? new PhemStorage(`collapsible:${this.persistValue}`) : null
  }

  // The `hidden` value of a closed panel: `until-found` with the value, or with the attribute of
  // the panel.
  #hiddenOf(panel) {
    return this.hiddenUntilFoundValue || panel.hasAttribute("data-phem-collapsible-hidden-until-found") ? "until-found" : true
  }

  // The attributes of the state: the open state of the controller element and of the panels,
  // `aria-expanded` and `data-panel-open` on a trigger.
  #owns(el, name) {
    if (el === this.element) return name !== "hidden" && OPEN_ATTRIBUTES.has(name)
    if (this.panelTargets.includes(el)) return OPEN_ATTRIBUTES.has(name)
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-panel-open"

    return false
  }
}

// Points the `aria-controls` of the trigger to the panels: with their ids, or with element
// references when a panel has no id. The morph removes an id that the server render does not
// have, so the controller gives no id. Returns false when the browser has no element references.
function controls(trigger, panels) {
  // A temporary id of the morph (phem_ids.js) is no id.
  const ids = panels.map(realId)
  if (ids.every(Boolean)) {
    trigger.setAttribute("aria-controls", ids.join(" "))

    return true
  }

  if (!("ariaControlsElements" in trigger)) return false

  trigger.ariaControlsElements = panels

  return true
}
