// The open and the close of a popup on hover, for `phem-popover` and `phem-menu` with their
// `openOnHover` value.
//
// The pointer on a trigger opens the popup after `delay()` ms. The pointer out of the triggers and
// of the popups closes it after `closeDelay()` ms, but at least 100 ms, so the pointer can go from
// the trigger to the popup. A touch does nothing: a tap opens the popup with its click.
//
//     this.hover = new Hover({
//       enabled: () => this.openOnHoverValue,
//       triggers: () => this.triggerTargets,
//       popups: () => this.popupTargets,
//       isOpen: () => this.openValue,
//       open: () => this.open(),
//       close: () => this.close(),
//       delay: () => this.delayValue,
//       closeDelay: () => this.closeDelayValue,
//     })
//     element.addEventListener("pointerover", (event) => this.hover.over(event))
//     element.addEventListener("pointerout", (event) => this.hover.out(event))
export class Hover {
  #options
  #timer = null

  constructor(options) {
    this.#options = options
  }

  over(event) {
    const { enabled, triggers, popups, isOpen, open, delay } = this.#options
    if (!enabled() || event.pointerType === "touch") return

    const onTrigger = within(triggers(), event.target)
    if (!onTrigger && !within(popups(), event.target)) return

    this.cancel()
    if (!isOpen() && onTrigger) this.#timer = setTimeout(open, delay())
  }

  out(event) {
    const { enabled, triggers, popups, isOpen, close, closeDelay } = this.#options
    if (!enabled() || event.pointerType === "touch") return

    const to = event.relatedTarget
    if (to instanceof Node && (within(triggers(), to) || within(popups(), to))) return

    this.cancel()
    if (isOpen()) this.#timer = setTimeout(close, Math.max(100, closeDelay()))
  }

  // Stops a pending open or close: a click, a key or a disconnect.
  cancel() {
    clearTimeout(this.#timer)
    this.#timer = null
  }
}

function within(elements, node) {
  return elements.some((el) => el.contains(node))
}
