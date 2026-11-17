import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { realId } from "./phem_ids.js"
import { isDisabled, nextItem } from "./phem_roving.js"
import { fit, hide, measure, render, settle, show } from "./phem_transition.js"

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
// the other items. With the `multiple` value, more than one item can be open. Tab moves the focus
// between the triggers, as the accordion pattern of the APG says now. With the `rovingFocus`
// value, ArrowDown, ArrowUp, Home and End also move the focus between the triggers (ArrowRight and
// ArrowLeft with the `horizontal` orientation, and from the last trigger to the first one with the
// `loopFocus` value). A disabled trigger (`disabled` or `aria-disabled="true"`) does not open: its
// item, its header, the trigger and its panel get `data-disabled`. A `header` target (the heading
// around a trigger) gets `data-open` or `data-closed`, and each part of an item gets
// `data-index`. The controller element and each panel get `data-orientation` (the `orientation`
// value).
//
// The `open` value is the state: the list of the open items. An item is its `data-value`
// attribute, its key (`data-phem-key`), or its index. The server render gives its first value, and
// a server render with a new value sets it: `data-phem-accordion-open-value={{ ['shipping'] }}`.
//
// An item and its panel get `data-open` or `data-closed`; see phem_transition.js for the
// animations. A trigger gets `aria-expanded` and `data-panel-open`. A panel gets `role="region"`,
// and the `--accordion-panel-height` and `--accordion-panel-width` CSS variables, for a transition
// of its height: the size of its content in px during the open and the close, and `auto` when the
// panel is open. When the trigger and the panel have an id, the trigger gets `aria-controls` and
// the panel gets `aria-labelledby`. The controller gives them no id: the morph does not match an
// element with an id that the server render does not have, and replaces it. With the
// `hiddenUntilFound` value, a closed panel has `hidden="until-found"`: the search of the browser
// (Ctrl+F) finds its text, and opens its item. A panel with `data-phem-accordion-hidden-until-found`
// does the same, for this panel only.
export default class PhemAccordionController extends Controller {
  static targets = ["item", "header", "trigger", "panel"]
  static values = {
    open: Array,
    multiple: Boolean,
    orientation: { type: String, default: "vertical" },
    loopFocus: { type: Boolean, default: true },
    rovingFocus: Boolean,
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

  orientationValueChanged() {
    if (this.rendered !== undefined) this.#render()
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

  // The arrow keys move the focus between the triggers only with the `rovingFocus` value: the
  // accordion pattern of the APG has no arrow keys now, and Tab moves between the triggers.
  onKeyDown = (event) => {
    if (!this.rovingFocusValue) return

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
        settle(panel, "accordion")
      } else {
        // A transition does not start from `auto`: the close starts from the size in px.
        measure(panel, "accordion")
        void panel.offsetHeight
        hide(panel, { hidden: this.#hiddenOf(panel) })
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
      if (!panel) continue

      const isOpen = open.includes(value)
      render(panel, isOpen, { hidden: this.#hiddenOf(panel) })
      if (isOpen) fit(panel, "accordion")
    }

    this.#renderParts(open, parts)
  }

  #renderParts(open, parts) {
    this.element.setAttribute("data-orientation", this.orientationValue)

    parts.forEach(({ item, value, trigger, panel }, index) => {
      const isOpen = open.includes(value)
      item.toggleAttribute("data-open", isOpen)
      item.toggleAttribute("data-closed", !isOpen)

      // The header of the item has its state, and each part has the index of its item.
      const header = this.headerTargets.find((el) => item.contains(el))
      header?.toggleAttribute("data-open", isOpen)
      header?.toggleAttribute("data-closed", !isOpen)
      const disabled = trigger ? isDisabled(trigger) : false
      for (const part of [item, header, trigger, panel]) {
        part?.setAttribute("data-index", String(index))
        part?.toggleAttribute("data-disabled", disabled)
      }

      panel?.setAttribute("data-orientation", this.orientationValue)

      if (trigger) {
        trigger.setAttribute("aria-expanded", String(isOpen))
        trigger.toggleAttribute("data-panel-open", isOpen)
      }

      if (panel && !panel.hasAttribute("role")) panel.setAttribute("role", "region")

      // A temporary id of the morph (phem_ids.js) is no id.
      const triggerId = trigger ? realId(trigger) : ""
      const panelId = panel ? realId(panel) : ""
      if (triggerId && panelId) {
        trigger.setAttribute("aria-controls", panelId)
        panel.setAttribute("aria-labelledby", triggerId)
      }
    })
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

  // The `hidden` value of a closed panel: `until-found` with the value of the root, or with the
  // attribute of the panel.
  #hiddenOf(panel) {
    return this.hiddenUntilFoundValue || panel.hasAttribute("data-phem-accordion-hidden-until-found") ? "until-found" : true
  }

  // The attributes of the state: the open state of an item, of its header and of a panel, and
  // the ARIA attributes of a trigger and of a panel.
  #owns(el, name) {
    if (el === this.element) return name === "data-orientation"
    if (this.itemTargets.includes(el)) return name === "data-open" || name === "data-closed" || name === "data-index" || name === "data-disabled"
    if (this.headerTargets.includes(el)) return name === "data-open" || name === "data-closed" || name === "data-index" || name === "data-disabled"
    if (this.panelTargets.includes(el)) return OPEN_ATTRIBUTES.has(name) || name === "role" || name === "aria-labelledby" || name === "data-index" || name === "data-disabled" || name === "data-orientation"
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "aria-controls" || name === "data-panel-open" || name === "data-index" || name === "data-disabled"

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
