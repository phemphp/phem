// The open and the close of a popup on hover, for `phem-popover` and `phem-menu` with their
// `openOnHover` value.
//
// The pointer on a trigger opens the popup after `delay()` ms. The pointer out of the triggers and
// of the popups closes it after `closeDelay()` ms (0 closes it at once). The pointer can go from
// the trigger to the popup, as the `safePolygon` of Base UI: while it is in the safe space, the
// popup stays open, and the time of the close starts when the pointer goes out of that space. The
// safe space is the triangle from the point where the pointer left the trigger to the popup (also
// a diagonal path), and the gap between the trigger and the popup. A pointer that goes away from
// the popup, for example down to the next item of a menu when the submenu opens on the right,
// closes it at once. A touch does nothing: a tap opens the popup with its click.
//
// With `stayAfterLanding` (`() => false`), a popup that the pointer entered (`landed`) stays open
// when the pointer goes out of it to a node that is not a trigger or a popup, or out of the page,
// as the `safePolygon` of Base UI. The pointer back on a trigger and reset() (at the close of the
// popup) reset `landed`. `phem-menu` uses it for a submenu: it closes the submenu when the pointer
// gets to another item of its menu.
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
//       stayAfterLanding: () => false,
//     })
//     element.addEventListener("pointerover", (event) => this.hover.over(event))
//     element.addEventListener("pointerout", (event) => this.hover.out(event))
export class Hover {
  #options
  #timer = null
  #tracking = null
  #landed = false
  #leave = null

  constructor(options) {
    this.#options = { stayAfterLanding: () => false, ...options }
  }

  // Whether the pointer entered the open popup after it was on a trigger.
  get landed() {
    return this.#landed && this.#options.isOpen()
  }

  over(event) {
    const { enabled, triggers, popups, isOpen, open, delay } = this.#options
    if (!enabled() || event.pointerType === "touch") return

    const onTrigger = within(triggers(), event.target)
    if (!onTrigger && !within(popups(), event.target)) return

    this.#landed = isOpen() && !onTrigger
    this.cancel()
    if (!isOpen() && onTrigger) this.#timer = setTimeout(open, delay())
  }

  out(event) {
    const { enabled, triggers, popups, isOpen, stayAfterLanding } = this.#options
    if (!enabled() || event.pointerType === "touch") return

    const to = event.relatedTarget
    if (to instanceof Node && (within(triggers(), to) || within(popups(), to))) return

    this.cancel()
    if (!isOpen()) return

    // The point where the pointer left a trigger or a popup: the apex of the triangle.
    this.#leave = [event.clientX, event.clientY]

    // The pointer entered the popup, and goes out of it to empty space: the popup stays open.
    if (this.landed && stayAfterLanding() && within(popups(), event.target)) return

    // The pointer went out of the page, or away from the popup.
    if (!(to instanceof Node) || !this.#toward(event)) {
      this.#close()

      return
    }

    this.#track()
  }

  // The popup closed: a pending open or close stops, and the next open starts with no landing.
  reset() {
    this.cancel()
    this.#landed = false
  }

  // Stops a pending open or close: a click, a key or a disconnect.
  cancel() {
    clearTimeout(this.#timer)
    this.#timer = null
    this.#tracking?.abort()
    this.#tracking = null
  }

  #close() {
    const { close, closeDelay } = this.#options
    this.#timer = setTimeout(close, Math.max(0, closeDelay()))
  }

  // Follows the pointer in the space between the trigger and the popup: the time of the close
  // starts when it goes out. The pointer on a trigger or a popup stops it (see over()).
  #track() {
    const { triggers, popups } = this.#options
    this.#tracking = new AbortController()
    const options = { signal: this.#tracking.signal }

    const leave = () => {
      this.cancel()
      this.#close()
    }

    document.addEventListener("pointermove", (event) => {
      if (within(triggers(), event.target) || within(popups(), event.target) || this.#between(event)) return

      leave()
    }, options)
    document.documentElement.addEventListener("pointerleave", leave, options)
  }

  // Whether the pointer that leaves goes toward a popup: it is in the smallest convex shape around
  // the boxes of a trigger and a popup. A pointer out of the other sides goes away from it.
  #toward({ clientX: x, clientY: y }) {
    const { triggers, popups } = this.#options
    const popupBoxes = boxes(popups())

    return boxes(triggers()).some((a) => popupBoxes.some((b) => inHull([...corners(a), ...corners(b)], x, y)))
  }

  // Whether the pointer is in the safe space: the triangle from the point where it left to a popup
  // (the smallest convex shape around the point and the box of the popup), or the gap between a
  // trigger and a popup. A pointer that left a popup has only the gap: the triangle is in the popup.
  #between({ clientX: x, clientY: y }) {
    const { triggers, popups } = this.#options
    const popupBoxes = boxes(popups())
    const leave = this.#leave

    if (leave && popupBoxes.some((box) => inHull([leave, ...corners(box)], x, y))) return true

    return boxes(triggers()).some((a) => popupBoxes.some((b) => inGap(a, b, x, y)))
  }
}

// The boxes of the elements that show.
function boxes(elements) {
  return elements.map((el) => el.getBoundingClientRect()).filter((box) => box.width > 0 && box.height > 0)
}

function corners({ left, top, right, bottom }) {
  return [[left, top], [right, top], [right, bottom], [left, bottom]]
}

// Whether the point is in the gap between two boxes that do not overlap on one axis: the band
// between their facing sides, as wide as the two boxes together.
function inGap(a, b, x, y) {
  const [top, bottom] = [Math.min(a.bottom, b.bottom), Math.max(a.top, b.top)]
  if (top <= bottom) return y >= top && y <= bottom && x >= Math.min(a.left, b.left) && x <= Math.max(a.right, b.right)

  const [left, right] = [Math.min(a.right, b.right), Math.max(a.left, b.left)]
  if (left <= right) return x >= left && x <= right && y >= Math.min(a.top, b.top) && y <= Math.max(a.bottom, b.bottom)

  return false
}

// Whether the point is in the convex hull of `points` (the monotone chain algorithm).
function inHull(points, x, y) {
  const cross = (o, a, b) => (a[0] - o[0]) * (b[1] - o[1]) - (a[1] - o[1]) * (b[0] - o[0])
  const sorted = [...points].sort((a, b) => a[0] - b[0] || a[1] - b[1])
  const half = (list) => {
    const chain = []

    for (const point of list) {
      while (chain.length >= 2 && cross(chain.at(-2), chain.at(-1), point) <= 0) chain.pop()
      chain.push(point)
    }

    return chain.slice(0, -1)
  }
  const hull = [...half(sorted), ...half(sorted.reverse())]

  // The point is on the same side of each edge.
  return hull.every((point, i) => cross(point, hull[(i + 1) % hull.length], [x, y]) >= 0)
}

function within(elements, node) {
  return elements.some((el) => el.contains(node))
}
