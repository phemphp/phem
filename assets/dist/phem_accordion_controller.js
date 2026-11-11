import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { isDisabled, nextItem } from "./phem_roving.js"
import { hide, measure, render, show } from "./phem_transition.js"

// The `phem-accordion` controller: a list of items that open and close, with one open item at a
// time.
//
//     <div data-controller="phem-accordion">
//         <div data-phem-accordion-target="item">
//             <h3><button id="shipping-trigger" data-phem-accordion-target="trigger">Shipping</button></h3>
//             <div id="shipping-panel" data-phem-accordion-target="panel" hidden>…</div>
//         </div>
//         <div data-phem-accordion-target="item">…</div>
//     </div>
//
// Each `item` has one `trigger` and one `panel`. A click on a trigger opens its item, and closes
// the other items. With the `multiple` value, more than one item can be open. ArrowDown, ArrowUp,
// Home and End move the focus between the triggers (ArrowRight and ArrowLeft with the `horizontal`
// orientation). A disabled trigger does not open, and does not get the focus.
//
// The `open` value is the state: the list of the open items. An item is its `data-value`
// attribute, its key (`data-phem-key`), or its index. The server render gives its first value, and
// a server render with a new value sets it: `data-phem-accordion-open-value={{ ['shipping'] }}`.
//
// An item and its panel get `data-open` or `data-closed`; see phem_transition.js for the
// animations. A trigger gets `aria-expanded` and `data-panel-open`. A panel gets `role="region"`,
// and the `--accordion-panel-height` and `--accordion-panel-width` CSS variables, for a transition
// of its height. When the trigger and the panel have an id, the trigger gets `aria-controls` and
// the panel gets `aria-labelledby`. The controller gives them no id: the morph does not match an
// element with an id that the server render does not have, and replaces it. With the
// `hiddenUntilFound` value, a closed panel has `hidden="until-found"`: the search of the browser
// (Ctrl+F) finds its text, and opens its item.
export default class PhemAccordionController extends Controller {
  static targets = ["item", "trigger", "panel"]
  static values = {
    open: Array,
    multiple: Boolean,
    orientation: { type: String, default: "vertical" },
    loopFocus: { type: Boolean, default: true },
    hiddenUntilFound: Boolean,
  }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("keydown", this.onKeyDown)
    this.element.addEventListener("beforematch", this.onBeforeMatch)
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("keydown", this.onKeyDown)
    this.element.removeEventListener("beforematch", this.onBeforeMatch)
    this.rendered = undefined
  }

  // A server render with a new value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || sameList(this.rendered, this.#open)) return

    this.#apply()
  }

  // A part that the morph adds gets the state, with no animation.
  itemTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  triggerTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  panelTargetConnected() {
    if (this.rendered !== undefined) this.#render()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const part = this.#parts().find(({ trigger }) => trigger?.contains(event.target))
    if (part && !isDisabled(part.trigger)) this.#toggle(part.value)
  }

  onKeyDown = (event) => {
    const triggers = this.triggerTargets
    if (!triggers.includes(event.target)) return

    const next = nextItem(triggers, event.target, event.key, {
      orientation: this.orientationValue,
      loop: this.loopFocusValue,
      rtl: getComputedStyle(this.element).direction === "rtl",
    })
    if (!next) return

    event.preventDefault()
    next.focus()
  }

  // The search of the browser found a text in a closed panel.
  onBeforeMatch = (event) => {
    const part = this.#parts().find(({ panel }) => panel === event.target)
    if (part) this.#set(part.value, true)
  }

  #toggle(value) {
    this.#set(value, !this.#open.includes(value))
  }

  #set(value, open) {
    const values = this.#open.filter((v) => v !== value)
    if (open) values.push(value)

    this.openValue = open && !this.multipleValue ? [value] : values
    this.#apply()
  }

  // The state with its animations.
  #apply() {
    const open = this.#open
    const parts = this.#parts()
    this.rendered = open

    for (const { value, panel } of parts) {
      const isOpen = open.includes(value)
      if (!panel || panel.hasAttribute("data-open") === isOpen) continue

      if (isOpen) {
        show(panel)
        measure(panel, "accordion")
      } else {
        measure(panel, "accordion")
        hide(panel, { hidden: this.#hidden })
      }
    }

    this.#renderParts(open, parts)
  }

  // The state with no animation: for the first render, a part that the morph adds, and after the
  // morph.
  #render() {
    const open = this.#open
    const parts = this.#parts()
    this.rendered = open

    for (const { value, panel } of parts) {
      if (panel) render(panel, open.includes(value), { hidden: this.#hidden })
    }

    this.#renderParts(open, parts)
  }

  #renderParts(open, parts) {
    for (const { item, value, trigger, panel } of parts) {
      const isOpen = open.includes(value)
      item.toggleAttribute("data-open", isOpen)
      item.toggleAttribute("data-closed", !isOpen)

      if (trigger) {
        trigger.setAttribute("aria-expanded", String(isOpen))
        trigger.toggleAttribute("data-panel-open", isOpen)
      }

      if (panel) {
        if (!panel.hasAttribute("role")) panel.setAttribute("role", "region")
        if (isOpen) measure(panel, "accordion")
      }

      if (trigger?.id && panel?.id) {
        trigger.setAttribute("aria-controls", panel.id)
        panel.setAttribute("aria-labelledby", trigger.id)
      }
    }
  }

  // Each item with its value, its trigger and its panel: the first one of each kind in the item,
  // and not in an accordion inside it. It reads each list of targets one time.
  #parts() {
    const items = this.itemTargets
    const parts = new Map(items.map((item, i) => [item, { item, value: valueOf(item, i), trigger: null, panel: null }]))
    const itemOf = (el) => {
      for (let node = el.parentElement; node && node !== this.element; node = node.parentElement) {
        if (parts.has(node)) return parts.get(node)
      }

      return null
    }

    for (const trigger of this.triggerTargets) {
      const part = itemOf(trigger)
      if (part && !part.trigger) part.trigger = trigger
    }

    for (const panel of this.panelTargets) {
      const part = itemOf(panel)
      if (part && !part.panel) part.panel = panel
    }

    return [...parts.values()]
  }

  // The values of the open items, as strings.
  get #open() {
    return this.openValue.map(String)
  }

  get #hidden() {
    return this.hiddenUntilFoundValue ? "until-found" : true
  }

  // The attributes of the state: the open state of an item and of a panel, and the ARIA
  // attributes of a trigger and of a panel.
  #owns(el, name) {
    if (this.itemTargets.includes(el)) return name === "data-open" || name === "data-closed"
    if (this.panelTargets.includes(el)) return OPEN_ATTRIBUTES.has(name) || name === "role" || name === "aria-labelledby"
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "aria-controls" || name === "data-panel-open"

    return false
  }
}

// The value of an item: its `data-value`, its key, or its index.
function valueOf(item, index) {
  return item.getAttribute("data-value") ?? item.getAttribute("data-phem-key") ?? String(index)
}

function sameList(a, b) {
  return a.length === b.length && a.every((v, i) => v === b[i])
}
