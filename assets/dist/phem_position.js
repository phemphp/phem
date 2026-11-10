// Places a popup next to its anchor (a trigger, an input), for `phem-popover`, `phem-tooltip`,
// `phem-menu` and `phem-combobox`.
//
// The popup gets `position: fixed` and its `top` and `left`, so a transform of the popup stays
// free for an animation. It goes on the `side` of the anchor (`top`, `bottom`, `left`, `right`,
// `inline-start` or `inline-end`), with the `align` (`start`, `center` or `end`) on the other
// axis. When there is no space on the side, the popup goes to the other side (a flip). On the
// other axis, it moves to stay in the viewport (a shift). `collisionPadding` is the space to keep
// between the popup and the edges of the viewport.
//
// The popup and its arrow get the real side and align (after a flip) in `data-side` and
// `data-align`, and the popup gets these CSS variables:
//
// - `--anchor-width`, `--anchor-height`: the size of the anchor;
// - `--available-width`, `--available-height`: the space from the anchor to the edge of the
//   viewport, for a `max-height` of a long list;
// - `--transform-origin`: the point of the anchor, for a scale animation from the anchor.
//
// The arrow gets its `left` (on a top or bottom side) or its `top` (on a left or right side), so
// that it points at the center of the anchor. It gets `data-uncentered` when the popup is too far
// to the side. The CSS of the app puts the arrow on the edge of the popup with `[data-side]`.
// The popup gets `data-anchor-hidden` when the anchor is out of the viewport.
//
// The popup follows its anchor on a scroll, a resize of the window and a resize of the anchor or
// of the popup, until the signal aborts. The morph resets the `style` attribute: the controllers
// call the update again after it.

const SIDES = new Set(["top", "bottom", "left", "right", "inline-start", "inline-end"])
const ALIGNS = new Set(["start", "center", "end"])
const OPPOSITE = { top: "bottom", bottom: "top", left: "right", right: "left", "inline-start": "inline-end", "inline-end": "inline-start" }

// The CSS properties and the attributes that the position sets.
const POPUP_STYLES = ["position", "top", "left", "right", "bottom", "--anchor-width", "--anchor-height", "--available-width", "--available-height", "--transform-origin"]
const ARROW_STYLES = ["top", "left"]
export const POSITION_ATTRIBUTES = new Set(["style", "data-side", "data-align", "data-uncentered", "data-anchor-hidden"])

// Reads the position options of a controller: its `side`, `align`, `sideOffset`, `alignOffset`,
// `collisionPadding` and `arrowPadding` values.
export function positionOptions(controller, arrow = null) {
  return {
    side: controller.sideValue,
    align: controller.alignValue,
    sideOffset: controller.sideOffsetValue,
    alignOffset: controller.alignOffsetValue,
    collisionPadding: controller.collisionPaddingValue,
    arrowPadding: controller.arrowPaddingValue,
    arrow,
  }
}

// The Stimulus values of the position, with the defaults of a popover.
export function positionValues(side = "bottom", align = "center") {
  return {
    side: { type: String, default: side },
    align: { type: String, default: align },
    sideOffset: { type: Number, default: 0 },
    alignOffset: { type: Number, default: 0 },
    collisionPadding: { type: Number, default: 5 },
    arrowPadding: { type: Number, default: 5 },
  }
}

// Places `popup` next to `anchor` now, and again on each change until `signal` aborts. Returns
// the function that places it again. `options` is a function, so a change of a value applies.
export function position(anchor, popup, options, signal) {
  let frame = 0
  const update = () => place(anchor, popup, options())
  const later = () => {
    if (frame) return

    frame = requestAnimationFrame(() => {
      frame = 0
      if (!signal.aborted) update()
    })
  }

  update()

  addEventListener("scroll", later, { capture: true, passive: true, signal })
  addEventListener("resize", later, { signal })

  if (typeof ResizeObserver === "function") {
    const observer = new ResizeObserver(later)
    observer.observe(anchor)
    observer.observe(popup)
    signal.addEventListener("abort", () => observer.disconnect(), { once: true })
  }

  signal.addEventListener("abort", () => cancelAnimationFrame(frame), { once: true })

  return update
}

// Removes the position of `popup` and of its arrow.
export function unposition(popup, arrow = null) {
  for (const name of POPUP_STYLES) popup.style.removeProperty(name)
  for (const name of ["data-side", "data-align", "data-anchor-hidden"]) popup.removeAttribute(name)
  if (popup.getAttribute("style") === "") popup.removeAttribute("style")

  if (!arrow) return

  for (const name of ARROW_STYLES) arrow.style.removeProperty(name)
  for (const name of ["data-side", "data-align", "data-uncentered"]) arrow.removeAttribute(name)
  if (arrow.getAttribute("style") === "") arrow.removeAttribute("style")
}

export function place(anchor, popup, { side = "bottom", align = "center", sideOffset = 0, alignOffset = 0, collisionPadding = 5, arrowPadding = 5, arrow = null } = {}) {
  side = SIDES.has(side) ? side : "bottom"
  align = ALIGNS.has(align) ? align : "center"

  const rtl = getComputedStyle(anchor).direction === "rtl"
  const a = anchor.getBoundingClientRect()
  // The size with no transform: a scale animation does not change the place.
  const size = { width: popup.offsetWidth, height: popup.offsetHeight }
  const view = { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight }
  const pad = collisionPadding

  // The flip: the other side when the popup does not fit on its side, and fits better there.
  const physical = physicalSide(side, rtl)
  const space = spaceOn(a, view, sideOffset, pad)
  const needed = isVertical(physical) ? size.height : size.width
  const flipped = space[physical] < needed && space[physicalSide(OPPOSITE[side], rtl)] > space[physical]
  const finalSide = flipped ? OPPOSITE[side] : side
  const finalPhysical = physicalSide(finalSide, rtl)

  let { x, y } = coordinates(a, size, finalPhysical, align, sideOffset, alignOffset, rtl)

  // The shift: on the other axis, the popup stays in the viewport.
  if (isVertical(finalPhysical)) x = clamp(x, pad, view.width - pad - size.width)
  else y = clamp(y, pad, view.height - pad - size.height)

  x = Math.round(x)
  y = Math.round(y)

  const available = isVertical(finalPhysical)
    ? { width: view.width - 2 * pad, height: space[finalPhysical] }
    : { width: space[finalPhysical], height: view.height - 2 * pad }

  const style = popup.style
  style.setProperty("position", "fixed")
  style.setProperty("top", `${y}px`)
  style.setProperty("left", `${x}px`)
  style.setProperty("right", "auto")
  style.setProperty("bottom", "auto")
  style.setProperty("--anchor-width", `${a.width}px`)
  style.setProperty("--anchor-height", `${a.height}px`)
  style.setProperty("--available-width", `${Math.max(0, Math.round(available.width))}px`)
  style.setProperty("--available-height", `${Math.max(0, Math.round(available.height))}px`)
  style.setProperty("--transform-origin", origin(a, size, x, y, finalPhysical, sideOffset))

  setAttribute(popup, "data-side", finalSide)
  setAttribute(popup, "data-align", align)
  popup.toggleAttribute("data-anchor-hidden", a.bottom < 0 || a.top > view.height || a.right < 0 || a.left > view.width)

  if (arrow) placeArrow(arrow, a, size, x, y, finalPhysical, finalSide, align, arrowPadding)

  return { side: finalSide, align, x, y }
}

function placeArrow(arrow, a, size, x, y, physical, side, align, padding) {
  const vertical = isVertical(physical)
  const length = vertical ? arrow.offsetWidth : arrow.offsetHeight
  const room = (vertical ? size.width : size.height) - padding - length
  const center = vertical ? a.left + a.width / 2 - x - length / 2 : a.top + a.height / 2 - y - length / 2
  const at = clamp(center, padding, room)

  arrow.style.setProperty(vertical ? "left" : "top", `${Math.round(at)}px`)
  arrow.style.removeProperty(vertical ? "top" : "left")
  setAttribute(arrow, "data-side", side)
  setAttribute(arrow, "data-align", align)
  arrow.toggleAttribute("data-uncentered", Math.abs(at - center) > 0.5)
}

function coordinates(a, size, physical, align, sideOffset, alignOffset, rtl) {
  const vertical = isVertical(physical)

  // On a top or a bottom side, `start` is the left edge, or the right edge in a right-to-left page.
  const start = vertical && rtl ? "end" : "start"
  const cross = align === "center" ? "center" : align === start ? "start" : "end"
  const anchorStart = vertical ? a.left : a.top
  const anchorLength = vertical ? a.width : a.height
  const popupLength = vertical ? size.width : size.height
  const shift = vertical && rtl ? -alignOffset : alignOffset
  let along = { start: anchorStart, center: anchorStart + (anchorLength - popupLength) / 2, end: anchorStart + anchorLength - popupLength }[cross]
  along += cross === "end" ? -shift : shift

  const main = {
    top: a.top - size.height - sideOffset,
    bottom: a.bottom + sideOffset,
    left: a.left - size.width - sideOffset,
    right: a.right + sideOffset,
  }[physical]

  return vertical ? { x: along, y: main } : { x: main, y: along }
}

// The point of the anchor in the box of the popup.
function origin(a, size, x, y, physical, sideOffset) {
  const cx = Math.round(a.left + a.width / 2 - x)
  const cy = Math.round(a.top + a.height / 2 - y)

  return {
    top: `${cx}px ${size.height + sideOffset}px`,
    bottom: `${cx}px ${-sideOffset}px`,
    left: `${size.width + sideOffset}px ${cy}px`,
    right: `${-sideOffset}px ${cy}px`,
  }[physical]
}

// The space from the anchor to each edge of the viewport.
function spaceOn(a, view, sideOffset, pad) {
  return {
    top: a.top - sideOffset - pad,
    bottom: view.height - a.bottom - sideOffset - pad,
    left: a.left - sideOffset - pad,
    right: view.width - a.right - sideOffset - pad,
  }
}

function physicalSide(side, rtl) {
  if (side === "inline-start") return rtl ? "right" : "left"
  if (side === "inline-end") return rtl ? "left" : "right"

  return side
}

function isVertical(physical) {
  return physical === "top" || physical === "bottom"
}

// A popup that is larger than the viewport starts at its start edge.
function clamp(value, min, max) {
  return Math.max(min, Math.min(value, Math.max(min, max)))
}

function setAttribute(el, name, value) {
  if (el.getAttribute(name) !== value) el.setAttribute(name, value)
}
