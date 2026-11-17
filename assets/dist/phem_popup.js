import { OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { dismissable } from "./phem_dismiss.js"
import { POSITION_ATTRIBUTES, position, unposition } from "./phem_position.js"
import { hide, render, show } from "./phem_transition.js"

// The popup part of `phem-popover`, `phem-tooltip`, `phem-menu` and `phem-combobox`: it opens and
// closes the popup with its animations (phem_transition.js), places it next to its anchor while it
// is open (phem_position.js), and closes it on Escape and on a press outside (phem_dismiss.js).
//
// A popup with the `popover="manual"` attribute shows in the top layer of the page: no
// `overflow`, `z-index` or `transform` of an element around it cuts it or covers it.
//
//     this.popup = new Popup({
//       anchor: () => this.triggerTarget,
//       popup: () => this.popupTarget,
//       arrow: () => this.arrowTargets[0] ?? null,
//       options: () => positionOptions(this),
//       inside: () => [this.element],
//       dismiss: (reason) => this.close(reason),
//       event: () => "pointerdown",
//     })
//
// `outside` (`() => true`) keeps the popup open on a press outside when it gives false. `event`
// (`() => "click"`) is the event of a press outside: `"click"` or `"pointerdown"` (phem_dismiss.js).
// `mirror` (`() => []`) gives the elements that get the `data-side` and the `data-align` of the
// popup (phem_position.js).
export class Popup {
  #parts
  #placing = null
  #undismiss = null
  #update = null
  #open = false

  constructor({ anchor, popup, arrow = () => null, options, inside, dismiss = null, outside = () => true, event = () => "click", mirror = () => [] }) {
    this.#parts = { anchor, popup, arrow, options, inside, dismiss, outside, event, mirror }
  }

  get open() {
    return this.#open
  }

  // Opens the popup with its animation, or at once with `instant: true`. A string `instant` is the
  // cause of the change in `data-instant`: the CSS decides the animation.
  show({ instant = false } = {}) {
    const popup = this.#parts.popup()
    if (!popup) return

    this.#open = true
    // The popup goes to the top layer with its `data-starting-style`, so its open animation runs.
    const shown = show(popup, { instant, reveal: () => topLayer(popup, true) })
    this.#start(popup)

    return shown
  }

  // Closes the popup. The position stays until the end of the animation.
  hide({ instant = false } = {}) {
    const popup = this.#parts.popup()
    this.#open = false
    this.#undismiss?.()
    this.#undismiss = null
    if (!popup) return Promise.resolve()

    const end = () => {
      if (this.#open) return

      this.#stop(popup)
      topLayer(popup, false)
    }

    return hide(popup, { instant, onEnd: end })
  }

  // The state with no animation: the first render, a popup that the morph adds.
  render(open) {
    const popup = this.#parts.popup()
    this.#open = open
    if (!popup) return

    topLayer(popup, open)
    render(popup, open)

    if (open) this.#start(popup)
    else {
      this.#undismiss?.()
      this.#undismiss = null
      this.#stop(popup)
    }
  }

  // The anchor changed (another trigger): the open popup goes next to the new anchor. A CSS
  // transition of `top` and `left` animates the move.
  reanchor() {
    const popup = this.#parts.popup()
    if (this.#open && popup) this.#start(popup)
  }

  // After the morph: the morph resets the `style` attribute of the popup and of the arrow.
  reposition() {
    this.#update?.()
  }

  // The attributes of the popup, of the arrow and of the mirror elements that belong to the client.
  owns(el, name) {
    if (el === this.#parts.popup()) return OPEN_ATTRIBUTES.has(name) || (this.#open && POSITION_ATTRIBUTES.has(name))
    if (el === this.#parts.arrow()) return this.#open && POSITION_ATTRIBUTES.has(name)
    if (this.#parts.mirror().includes(el)) return this.#open && (name === "data-side" || name === "data-align")

    return false
  }

  destroy() {
    this.#undismiss?.()
    this.#undismiss = null
    this.#placing?.abort()
    this.#placing = null
  }

  #start(popup) {
    if (!this.#undismiss && this.#parts.dismiss) {
      this.#undismiss = dismissable({
        inside: this.#parts.inside,
        dismiss: (reason, event) => this.#parts.dismiss(reason, event),
        outside: this.#parts.outside(),
        event: this.#parts.event(),
      })
    }

    const anchor = this.#parts.anchor()
    if (!anchor) return

    this.#placing?.abort()
    this.#placing = new AbortController()
    this.#update = position(anchor, popup, () => ({ ...this.#parts.options(), arrow: this.#parts.arrow(), mirror: this.#parts.mirror() }), this.#placing.signal)
  }

  #stop(popup) {
    this.#placing?.abort()
    this.#placing = null
    this.#update = null
    unposition(popup, this.#parts.arrow(), this.#parts.mirror())
  }
}

// A `popover` popup goes to the top layer while it is open.
function topLayer(popup, open) {
  if (!popup.hasAttribute("popover") || typeof popup.showPopover !== "function") return

  const shown = popup.matches(":popover-open")
  if (open && !shown) {
    popup.hidden = false
    popup.showPopover()
  } else if (!open && shown) popup.hidePopover()
}
