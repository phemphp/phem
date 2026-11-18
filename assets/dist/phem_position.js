// Places a popup next to its anchor (a trigger, an input), for `phem-popover`, `phem-tooltip`,
// `phem-menu` and `phem-combobox`.
//
// The popup gets its `top` and `left`, so a transform of the popup stays free for an animation.
// A popup in the top layer (an open `popover`) gets `position: absolute` and a place in the page,
// as in Base UI: a scroll of the page moves it with its anchor at once, in the same frame. Else,
// and for a virtual anchor or an anchor in a `fixed` or `sticky` box, it gets `position: fixed`
// and a place in the viewport. It goes on the `side` of the anchor (`top`, `bottom`, `left`, `right`,
// `inline-start` or `inline-end`), with the `align` (`start`, `center` or `end`) on the other
// axis. When there is no space on the side, the popup goes to the other side (a flip). On the
// other axis, it moves to stay in the viewport (a shift). `collisionPadding` is the space to keep
// between the popup and the edges of the viewport. `collisionPaddingInline` is this space for the
// left and the right edges only, when it is not the same. With `flip: false`, the popup stays on
// its side (Base UI `collisionAvoidance={{ side: 'none' }}`).
//
// With `collision: "context"` (a popup at the point of the pointer, as the Base UI context menu),
// the popup stays on its side, and shifts on both axes to stay in the viewport. Its align flips
// (`start` and `end`) when it does not fit with its align, and the other align has more space.
//
// The popup and its arrow get the real side and align (after a flip) in `data-side` and
// `data-align`, and the popup gets these CSS variables:
//
// - `--anchor-width`, `--anchor-height`: the size of the anchor;
// - `--available-width`, `--available-height`: the space from the anchor to the edge of the
//   viewport, for a `max-height` of a long list;
// - `--transform-origin`: the point of the anchor, for a scale animation from the anchor. A popup
//   with `align` `start` or `end` and no arrow grows from its aligned edge (`0%` or `100%` on the
//   other axis, as in Base UI), until a shift of more than 1 px moves it from its align.
//
// The arrow gets its `left` (on a top or bottom side) or its `top` (on a left or right side), so
// that it points at the center of the anchor. The value is from the padding box of the popup (the
// box of an arrow with `position: absolute`), so a border of the popup does not move the arrow. It
// gets `data-uncentered` when the popup is too far to the side. The CSS of the app puts the arrow on the edge of the popup with `[data-side]`.
// The popup gets `data-anchor-hidden` when the anchor is out of the viewport. The `mirror`
// elements (a popup in a positioner, as in Base UI) also get the `data-side` and the `data-align`
// of the popup.
//
// The anchor can be a virtual element: an object with `getBoundingClientRect()` and a
// `contextElement` (the element of its direction), for a popup at the point of the pointer
// (`phem-context-menu`).
//
// The popup follows its anchor on a scroll, a resize of the window and a resize of the anchor or
// of the popup, until the signal aborts. It goes to its place at once: a CSS transition of `top`
// and `left` does not animate the first place or a move after a scroll. It animates only a move
// to a new anchor (`animate: true`, as in Base UI when one menu moves between its triggers), and
// the updates during this move. The morph resets the `style` attribute: the controllers
// call the update again after it.

const SIDES = new Set(["top", "bottom", "left", "right", "inline-start", "inline-end"])
const ALIGNS = new Set(["start", "center", "end"])
const OPPOSITE = { top: "bottom", bottom: "top", left: "right", right: "left", "inline-start": "inline-end", "inline-end": "inline-start" }

// The CSS properties and the attributes that the position sets.
const POPUP_STYLES = ["position", "top", "left", "right", "bottom", "--anchor-width", "--anchor-height", "--available-width", "--available-height", "--transform-origin"]
const ARROW_STYLES = ["top", "left"]
export const POSITION_ATTRIBUTES = new Set(["style", "data-side", "data-align", "data-uncentered", "data-anchor-hidden"])

// Reads the position options of a controller: its `side`, `align`, `sideOffset`, `alignOffset`,
// `collisionPadding`, `collisionPaddingInline`, `flip` and `arrowPadding` values.
export function positionOptions(controller, arrow = null) {
  return {
    side: controller.sideValue,
    align: controller.alignValue,
    sideOffset: controller.sideOffsetValue,
    alignOffset: controller.alignOffsetValue,
    collisionPadding: controller.collisionPaddingValue,
    collisionPaddingInline: controller.collisionPaddingInlineValue,
    flip: controller.flipValue,
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
    collisionPaddingInline: { type: Number, default: -1 },
    flip: { type: Boolean, default: true },
    arrowPadding: { type: Number, default: 5 },
  }
}

// Places `popup` next to `anchor` now, and again on each change until `signal` aborts. Returns
// the function that places it again. `options` is a function, so a change of a value applies.
// With `animate: true`, a CSS transition of `top` and `left` animates the first place (the move
// from the last place to a new anchor).
export function position(anchor, popup, options, signal, { animate = false } = {}) {
  let frame = 0
  const update = (move = false) => place(anchor, popup, { ...options(), animate: move || moving(popup) })
  const later = () => {
    if (frame) return

    frame = requestAnimationFrame(() => {
      frame = 0
      if (!signal.aborted) update()
    })
  }

  update(animate)

  addEventListener("scroll", later, { capture: true, passive: true, signal })
  addEventListener("resize", later, { signal })

  if (typeof ResizeObserver === "function") {
    const observer = new ResizeObserver(later)
    if (anchor instanceof Element) observer.observe(anchor)
    observer.observe(popup)
    signal.addEventListener("abort", () => observer.disconnect(), { once: true })
  }

  signal.addEventListener("abort", () => cancelAnimationFrame(frame), { once: true })

  return () => update()
}

// Removes the position of `popup`, of its arrow and of its `mirror` elements.
export function unposition(popup, arrow = null, mirror = []) {
  for (const name of POPUP_STYLES) popup.style.removeProperty(name)
  for (const name of ["data-side", "data-align", "data-anchor-hidden"]) popup.removeAttribute(name)
  if (popup.getAttribute("style") === "") popup.removeAttribute("style")

  for (const el of mirror) {
    el.removeAttribute("data-side")
    el.removeAttribute("data-align")
  }

  if (!arrow) return

  for (const name of ARROW_STYLES) arrow.style.removeProperty(name)
  for (const name of ["data-side", "data-align", "data-uncentered"]) arrow.removeAttribute(name)
  if (arrow.getAttribute("style") === "") arrow.removeAttribute("style")
}

// With `animate: true`, a CSS transition of `top` and `left` animates the move from the last place
// of the popup. Else the popup goes to its place at once, and its other transitions keep running.
export function place(anchor, popup, { side = "bottom", align = "center", sideOffset = 0, alignOffset = 0, collisionPadding = 5, collisionPaddingInline = -1, flip = true, arrowPadding = 5, arrow = null, mirror = [], collision = "flip", animate = false } = {}) {
  side = SIDES.has(side) ? side : "bottom"
  align = ALIGNS.has(align) ? align : "center"

  const rtl = getComputedStyle(anchor.contextElement ?? anchor).direction === "rtl"
  const a = anchor.getBoundingClientRect()

  // A popup that a transition moves now goes to its new place with the same transition. It keeps
  // its place for the measure.
  const retarget = animate && moving(popup)

  // The measure at the corner of the viewport, and a place at once, do not start a transition.
  const release = retarget ? () => {} : holdMove(popup)
  const last = { position: popup.style.getPropertyValue("position"), top: popup.style.getPropertyValue("top"), left: popup.style.getPropertyValue("left") }

  // The size of a fixed box at the corner of the viewport: a popup that is a block in the page is
  // as wide as its container, and a popup at its last place near an edge wraps its text.
  if (!retarget) {
    popup.style.setProperty("position", "fixed")
    popup.style.setProperty("top", "0px")
    popup.style.setProperty("left", "0px")
    popup.style.setProperty("right", "auto")
    popup.style.setProperty("bottom", "auto")
  }

  // The size with no transform: a scale animation does not change the place.
  const size = { width: popup.offsetWidth, height: popup.offsetHeight }
  const view = { width: document.documentElement.clientWidth, height: document.documentElement.clientHeight }
  // The space to the top and the bottom edges, and to the left and the right edges.
  const pad = { block: collisionPadding, inline: collisionPaddingInline >= 0 ? collisionPaddingInline : collisionPadding }

  const context = collision === "context"

  // The flip: the other side when the popup does not fit on its side, and fits better there. A
  // context popup stays on its side, and flips its align.
  const physical = physicalSide(side, rtl)
  const space = spaceOn(a, view, sideOffset, pad)
  const needed = isVertical(physical) ? size.height : size.width
  const flipped = !context && flip && space[physical] < needed && space[physicalSide(OPPOSITE[side], rtl)] > space[physical]
  const finalSide = flipped ? OPPOSITE[side] : side
  const finalPhysical = physicalSide(finalSide, rtl)

  // The align flip of a context popup.
  if (context) align = flipAlign(a, size, view, pad, finalPhysical, align, sideOffset, alignOffset, rtl)

  const placed = coordinates(a, size, finalPhysical, align, sideOffset, alignOffset, rtl)
  let { x, y } = placed

  // The shift: on the other axis, the popup stays in the viewport. A context popup also shifts on
  // its side axis.
  if (isVertical(finalPhysical) || context) x = clamp(x, pad.inline, view.width - pad.inline - size.width)
  if (!isVertical(finalPhysical) || context) y = clamp(y, pad.block, view.height - pad.block - size.height)

  // The shift on each axis, before the round: a shift on the other axis breaks the align of the
  // origin, and a shift on the side axis moves the popup from the anchor.
  const shift = isVertical(finalPhysical) ? x - placed.x : y - placed.y
  const sideShift = isVertical(finalPhysical) ? y - placed.y : x - placed.x

  x = Math.round(x)
  y = Math.round(y)

  const available = isVertical(finalPhysical)
    ? { width: view.width - 2 * pad.inline, height: space[finalPhysical] }
    : { width: space[finalPhysical], height: view.height - 2 * pad.block }

  const style = popup.style

  // The move from the last place: the popup goes back to it, and the transition starts from it.
  if (animate && !retarget) {
    restore(style, "position", last.position)
    restore(style, "top", last.top)
    restore(style, "left", last.left)
    release()
  }

  // A place in the page starts at the top left corner of the page, not of the viewport.
  const page = inPage(anchor, popup)
  style.setProperty("position", page ? "absolute" : "fixed")
  style.setProperty("top", `${page ? y + scrollY : y}px`)
  style.setProperty("left", `${page ? x + scrollX : x}px`)
  style.setProperty("right", "auto")
  style.setProperty("bottom", "auto")
  style.setProperty("--anchor-width", `${a.width}px`)
  style.setProperty("--anchor-height", `${a.height}px`)
  style.setProperty("--available-width", `${Math.max(0, Math.round(available.width))}px`)
  style.setProperty("--available-height", `${Math.max(0, Math.round(available.height))}px`)
  style.setProperty("--transform-origin", origin(a, size, x, y, finalPhysical, sideOffset, { align, rtl, arrow, shift, sideShift }))

  setAttribute(popup, "data-side", finalSide)
  setAttribute(popup, "data-align", align)
  popup.toggleAttribute("data-anchor-hidden", a.bottom < 0 || a.top > view.height || a.right < 0 || a.left > view.width)

  for (const el of mirror) {
    setAttribute(el, "data-side", finalSide)
    setAttribute(el, "data-align", align)
  }

  if (arrow) placeArrow(arrow, popup, a, size, x, y, finalPhysical, finalSide, align, arrowPadding)

  if (!animate) release()

  return { side: finalSide, align, x, y }
}

// The transition properties that move the popup.
const MOVE_PROPERTIES = new Set(["top", "left", "right", "bottom", "inset"])
const TRANSITION_STYLES = ["transition-property", "transition-duration", "transition-timing-function", "transition-delay"]

// Removes the move properties from the transition of `popup`, and returns the function that
// puts them back. This function first applies the styles, so the new place does not start a
// transition when the move properties come back.
function holdMove(popup) {
  const computed = getComputedStyle(popup)
  const properties = computed.transitionProperty.split(",").map((name) => name.trim())
  const kept = properties.map((name, i) => [name, i]).filter(([name]) => !MOVE_PROPERTIES.has(name))
  if (kept.length === properties.length) return () => {}

  const style = popup.style
  const saved = TRANSITION_STYLES.map((name) => [name, style.getPropertyValue(name)])

  // Each list of the transition repeats for each property: the kept properties keep their values.
  const values = TRANSITION_STYLES.slice(1).map((name) => {
    const items = computed.getPropertyValue(name).split(/,(?![^(]*\))/).map((item) => item.trim())

    return [name, kept.map(([, i]) => items[i % items.length]).join(", ")]
  })

  if (kept.length === 0) style.setProperty("transition-property", "none")
  else {
    style.setProperty("transition-property", kept.map(([name]) => name).join(", "))
    for (const [name, value] of values) style.setProperty(name, value)
  }

  return () => {
    // The styles apply with no transition of the move.
    void popup.offsetWidth

    for (const [name, value] of saved) restore(style, name, value)
    if (popup.getAttribute("style") === "") popup.removeAttribute("style")
  }
}

// Whether `popup` goes in the page: it is in the top layer, where `position: absolute` starts at
// the corner of the page, and its anchor scrolls with the page.
function inPage(anchor, popup) {
  if (!(anchor instanceof Element) || !topLayered(popup)) return false

  for (let el = anchor; el; el = el.parentElement) {
    const { position } = getComputedStyle(el)
    if (position === "fixed" || position === "sticky") return false
  }

  return true
}

function topLayered(el) {
  try {
    return el.matches(":popover-open")
  } catch {
    return false
  }
}

// Whether a CSS transition moves `popup` now: an update during the move to a new anchor moves it
// to the new place with the transition.
function moving(popup) {
  return popup.getAnimations?.().some((animation) => MOVE_PROPERTIES.has(animation.transitionProperty)) ?? false
}

function restore(style, name, value) {
  if (value === "") style.removeProperty(name)
  else style.setProperty(name, value)
}

function placeArrow(arrow, popup, a, size, x, y, physical, side, align, padding) {
  const vertical = isVertical(physical)
  const length = vertical ? arrow.offsetWidth : arrow.offsetHeight
  const room = (vertical ? size.width : size.height) - padding - length
  const center = vertical ? a.left + a.width / 2 - x - length / 2 : a.top + a.height / 2 - y - length / 2
  const at = clamp(center, padding, room)
  // `left` and `top` start at the padding box of the popup, after its border.
  const border = (vertical ? popup.clientLeft : popup.clientTop) || 0

  arrow.style.setProperty(vertical ? "left" : "top", `${Math.round(at - border)}px`)
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

// The align of a context popup: the other align when the popup does not fit on the other axis with
// its align, and the other align has more space. `center` stays.
function flipAlign(a, size, view, pad, physical, align, sideOffset, alignOffset, rtl) {
  if (align === "center") return align

  const vertical = isVertical(physical)
  const length = vertical ? size.width : size.height
  const min = vertical ? pad.inline : pad.block
  const max = (vertical ? view.width - pad.inline : view.height - pad.block) - length
  const overflow = (value) => {
    const { x, y } = coordinates(a, size, physical, value, sideOffset, alignOffset, rtl)
    const along = vertical ? x : y

    return Math.max(0, min - along) + Math.max(0, along - max)
  }

  const other = align === "start" ? "end" : "start"
  const here = overflow(align)

  return here > 0 && overflow(other) < here ? other : align
}

// The point of the anchor in the box of the popup. An aligned popup with no arrow grows from its
// aligned edge, until a shift breaks the align (Base UI). After a shift on the side axis (a context
// popup), the origin is on the edge of the anchor, not at `sideOffset` from the popup.
function origin(a, size, x, y, physical, sideOffset, { align, rtl, arrow, shift, sideShift }) {
  const vertical = isVertical(physical)
  const aligned = !arrow && align !== "center" && Math.abs(shift) <= 1

  // On a top or a bottom side, `start` is the right edge in a right-to-left page.
  const edge = (align === "start") === (vertical && rtl) ? "100%" : "0%"
  const cx = aligned && vertical ? edge : `${Math.round(a.left + a.width / 2 - x)}px`
  const cy = aligned && !vertical ? edge : `${Math.round(a.top + a.height / 2 - y)}px`

  if (sideShift !== 0) {
    return {
      top: `${cx} ${Math.round(a.top - y)}px`,
      bottom: `${cx} ${Math.round(a.top + a.height - y)}px`,
      left: `${Math.round(a.left - x)}px ${cy}`,
      right: `${Math.round(a.left + a.width - x)}px ${cy}`,
    }[physical]
  }

  return {
    top: `${cx} ${size.height + sideOffset}px`,
    bottom: `${cx} ${-sideOffset}px`,
    left: `${size.width + sideOffset}px ${cy}`,
    right: `${-sideOffset}px ${cy}`,
  }[physical]
}

// The space from the anchor to each edge of the viewport.
function spaceOn(a, view, sideOffset, pad) {
  return {
    top: a.top - sideOffset - pad.block,
    bottom: view.height - a.bottom - sideOffset - pad.block,
    left: a.left - sideOffset - pad.inline,
    right: view.width - a.right - sideOffset - pad.inline,
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
