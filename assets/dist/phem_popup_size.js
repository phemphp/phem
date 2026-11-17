// The size of a popup that shows one content of a few (`phem-navigation-menu`), in CSS variables,
// as in Base UI: `--popup-width` and `--popup-height` on the popup, and `--positioner-width` and
// `--positioner-height` on the positioner.
//
//     #popup { width: var(--popup-width); height: var(--popup-height); transition: width .2s, height .2s }
//
// At rest, the variables are `auto`: the popup has the size of its content, also when the content
// grows or shrinks. When the content changes, the popup goes from the old size to the new size in
// px, for a CSS transition of its size, and back to `auto` when this transition ends. The
// positioner gets the new size at once, so its place does not change during the transition.

// The CSS properties of a size transition.
const SIZES = new Set(["width", "height", "inline-size", "block-size"])

export class PopupSize {
  #popup
  #positioner
  #change = 0

  // `popup` and `positioner` are functions that give the element, or null.
  constructor({ popup, positioner = () => null }) {
    this.#popup = popup
    this.#positioner = positioner
  }

  // The size of the popup now: the start of a change.
  measure() {
    const popup = this.#popup()

    return popup ? { width: popup.offsetWidth, height: popup.offsetHeight } : null
  }

  // The variables go to `auto` at once, and a running change stops.
  rest() {
    this.#change++
    this.#set(this.#popup(), "popup", "auto", "auto")
    this.#set(this.#positioner(), "positioner", "auto", "auto")
  }

  // The popup goes from the size `from` (of measure()) to the size of its new content.
  change(from) {
    this.rest()

    const popup = this.#popup()
    if (!popup || !from) return

    const change = this.#change
    const width = `${popup.offsetWidth}px`
    const height = `${popup.offsetHeight}px`
    this.#set(this.#positioner(), "positioner", width, height)

    // The old size for one frame, then the new size.
    this.#set(popup, "popup", `${from.width}px`, `${from.height}px`)
    void popup.offsetWidth
    this.#set(popup, "popup", width, height)

    Promise.allSettled(sizeTransitionsOf(popup).map((animation) => animation.finished)).then(() => {
      if (change === this.#change) this.rest()
    })
  }

  #set(el, name, width, height) {
    if (!el) return

    el.style.setProperty(`--${name}-width`, width)
    el.style.setProperty(`--${name}-height`, height)
  }
}

// The transitions of the size of `el`: not an animation of another property, which can be
// infinite. The browser starts a transition when it reads the new styles.
function sizeTransitionsOf(el) {
  if (typeof el.getAnimations !== "function") return []

  getComputedStyle(el).getPropertyValue("width")

  return el.getAnimations().filter((animation) => SIZES.has(animation.transitionProperty))
}
