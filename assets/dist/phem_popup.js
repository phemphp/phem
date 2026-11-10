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
//     })
export class Popup {
  #parts
  #placing = null
  #undismiss = null
  #update = null
  #open = false

  constructor({ anchor, popup, arrow = () => null, options, inside, dismiss = null, outside = () => true }) {
    this.#parts = { anchor, popup, arrow, options, inside, dismiss, outside }
  }

  get open() {
    return this.#open
  }

  // Opens the popup with its animation, or at once with `instant`.
  show({ instant = false } = {}) {
    const popup = this.#parts.popup()
    if (!popup) return

    this.#open = true
    topLayer(popup, true)
    const shown = show(popup, { instant })
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

  // After the morph: the morph resets the `style` attribute of the popup and of the arrow.
  reposition() {
    this.#update?.()
  }

  // The attributes of the popup and of the arrow that belong to the client.
  owns(el, name) {
    if (el === this.#parts.popup()) return OPEN_ATTRIBUTES.has(name) || (this.#open && POSITION_ATTRIBUTES.has(name))
    if (el === this.#parts.arrow()) return this.#open && POSITION_ATTRIBUTES.has(name)

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
      })
    }

    const anchor = this.#parts.anchor()
    if (!anchor) return

    this.#placing?.abort()
    this.#placing = new AbortController()
    this.#update = position(anchor, popup, () => ({ ...this.#parts.options(), arrow: this.#parts.arrow() }), this.#placing.signal)
  }

  #stop(popup) {
    this.#placing?.abort()
    this.#placing = null
    this.#update = null
    unposition(popup, this.#parts.arrow())
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
