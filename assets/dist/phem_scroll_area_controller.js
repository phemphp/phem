import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The time (ms) after the last scroll before `data-scrolling` goes.
const SCROLL_TIMEOUT = 500

// The smallest size (px) of a thumb.
const MIN_THUMB_SIZE = 16

// The attributes of the overflow state.
const OVERFLOW_ATTRIBUTES = ["data-has-overflow-x", "data-has-overflow-y", "data-overflow-x-start", "data-overflow-x-end", "data-overflow-y-start", "data-overflow-y-end", "data-scrolling"]

// The `phem-scroll-area` controller: custom scrollbars over a native scroll container. The scroll
// position and the state stay after the morph of an event.
//
//     <div data-controller="phem-scroll-area">
//         <div data-phem-scroll-area-target="viewport">
//             <div data-phem-scroll-area-target="content">…</div>
//         </div>
//         <div data-phem-scroll-area-target="scrollbar" data-orientation="vertical">
//             <div data-phem-scroll-area-target="thumb"></div>
//         </div>
//         <div data-phem-scroll-area-target="scrollbar" data-orientation="horizontal">
//             <div data-phem-scroll-area-target="thumb"></div>
//         </div>
//         <div data-phem-scroll-area-target="corner"></div>
//     </div>
//
// The `viewport` target is the native scroll container: it gets `overflow: scroll` and no native
// scrollbar (`scrollbar-width: none`), so the wheel, the touch and the keyboard scroll it as
// usual. The `content` target gets `min-width: fit-content`, so a wide content overflows on the
// horizontal axis. A `scrollbar` target has the `data-orientation` of the markup: `vertical` (the
// default) or `horizontal`. Its `thumb` target shows the visible part of the content. A drag of a
// thumb scrolls the viewport. A press on a scrollbar out of its thumb scrolls the viewport, so
// that the center of the thumb goes to the pointer, and the drag continues from there. A
// scrollbar gets `hidden` when its axis has no overflow, unless it has `data-keep-mounted`. The
// `corner` target is the square between two scrollbars: it gets `hidden` when one axis has no
// overflow.
//
// The `overflowEdgeThreshold` value is the distance (px) from an edge before its
// `data-overflow-*` attribute shows: a number for all the edges, or a JSON object with `xStart`,
// `xEnd`, `yStart` and `yEnd`. The default is 0.
//
// The viewport gets `tabindex="0"` when its content is wider or taller than it, so Tab goes to it
// and the arrow keys scroll it, and `tabindex="-1"` when it has no overflow.
//
// The root, the viewport, the content and the scrollbars get `data-has-overflow-x` and
// `data-has-overflow-y` when the content is wider or taller than the viewport, and
// `data-overflow-x-start`, `data-overflow-x-end`, `data-overflow-y-start` and
// `data-overflow-y-end` when the content continues past that edge. They get `data-scrolling`
// while the user scrolls (a scrollbar and its thumb only for a scroll on their axis), and the
// scrollbars get `data-hovering` while the pointer is over the root. The thumbs get
// `data-orientation`. The CSS variables are:
//
// - on the root: `--scroll-area-corner-width` and `--scroll-area-corner-height`;
// - on the viewport: `--scroll-area-overflow-x-start`, `--scroll-area-overflow-x-end`,
//   `--scroll-area-overflow-y-start` and `--scroll-area-overflow-y-end` (the distance to each
//   edge);
// - on a scrollbar: `--scroll-area-thumb-height` (vertical) or `--scroll-area-thumb-width`
//   (horizontal).
//
// As in Base UI, the parts get their layout styles: the root `position: relative`, a scrollbar
// `position: absolute` on its edge, a thumb its size and its `transform`, the corner its size.
// The `style` attribute of the parts belongs to the client.
export default class PhemScrollAreaController extends Controller {
  static targets = ["viewport", "content", "scrollbar", "thumb", "corner"]
  static values = {
    overflowEdgeThreshold: { type: String, default: "0" },
  }

  connect() {
    this.scrolling = { x: false, y: false }
    this.timers = { x: null, y: null }
    this.hovering = false
    this.position = null
    this.drag = null

    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.update(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("scroll", this.onScroll, { ...options, capture: true, passive: true })
    this.element.addEventListener("pointerenter", this.onPointerEnter, options)
    this.element.addEventListener("pointerleave", this.onPointerLeave, options)
    this.element.addEventListener("pointerdown", this.onPointerDown, options)
    this.element.addEventListener("pointermove", this.onPointerMove, options)
    this.element.addEventListener("pointerup", this.onPointerUp, options)
    this.element.addEventListener("pointercancel", this.onPointerUp, options)
    this.element.addEventListener("lostpointercapture", this.onPointerUp, options)

    if (typeof ResizeObserver === "function") {
      this.resizing = new ResizeObserver(() => this.update())
      this.listening.signal.addEventListener("abort", () => this.resizing.disconnect())

      for (const el of [...this.viewportTargets, ...this.contentTargets, ...this.scrollbarTargets]) this.resizing.observe(el)
    }

    this.update()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.listening = null
    clearTimeout(this.timers.x)
    clearTimeout(this.timers.y)
  }

  // A target that the morph adds gets the state.
  viewportTargetConnected(el) {
    this.#observe(el)
  }

  contentTargetConnected(el) {
    this.#observe(el)
  }

  scrollbarTargetConnected(el) {
    this.#observe(el)
  }

  thumbTargetConnected() {
    if (this.listening) this.update()
  }

  cornerTargetConnected() {
    if (this.listening) this.update()
  }

  overflowEdgeThresholdValueChanged() {
    if (this.listening) this.update()
  }

  onScroll = (event) => {
    const viewport = this.#viewport
    if (event.target !== viewport) return

    const position = { x: viewport.scrollLeft, y: viewport.scrollTop }
    const last = this.position ?? position

    for (const axis of ["x", "y"]) {
      if (position[axis] === last[axis]) continue

      this.scrolling[axis] = true
      clearTimeout(this.timers[axis])
      this.timers[axis] = setTimeout(() => {
        this.scrolling[axis] = false
        if (this.listening) this.update()
      }, SCROLL_TIMEOUT)
    }

    this.update()
  }

  onPointerEnter = (event) => {
    if (event.pointerType === "touch") return

    this.hovering = true
    this.update()
  }

  onPointerLeave = () => {
    this.hovering = false
    this.update()
  }

  // A press on a thumb starts a drag. A press on a scrollbar out of its thumb scrolls the thumb to
  // the pointer first.
  onPointerDown = (event) => {
    const viewport = this.#viewport
    const bar = this.scrollbarTargets.find((el) => el.contains(event.target))
    if (!viewport || !bar || event.button !== 0) return

    const vertical = orientationOf(bar) === "vertical"
    const thumb = this.#thumbOf(bar)
    const track = trackOf(bar, vertical)
    const size = thumbSize(viewport, track, vertical)
    const max = vertical ? viewport.scrollHeight - viewport.clientHeight : viewport.scrollWidth - viewport.clientWidth
    if (max <= 0 || track.length <= size) return

    event.preventDefault()
    const rtl = !vertical && this.#rtl

    if (!thumb || !thumb.contains(event.target)) {
      const box = bar.getBoundingClientRect()
      const at = vertical ? event.clientY - box.top - track.start : rtl ? box.right - track.end - event.clientX : event.clientX - box.left - track.start
      const ratio = clamp((at - size / 2) / (track.length - size), 0, 1)
      if (vertical) viewport.scrollTop = ratio * max
      else viewport.scrollLeft = (rtl ? -1 : 1) * ratio * max
    }

    bar.setPointerCapture?.(event.pointerId)
    this.drag = {
      bar,
      vertical,
      pointer: event.pointerId,
      from: vertical ? event.clientY : event.clientX,
      scroll: vertical ? viewport.scrollTop : viewport.scrollLeft,
      scale: max / (track.length - size),
    }
  }

  onPointerMove = (event) => {
    const drag = this.drag
    const viewport = this.#viewport
    if (!drag || !viewport || event.pointerId !== drag.pointer) return

    const delta = (drag.vertical ? event.clientY : event.clientX) - drag.from
    if (drag.vertical) viewport.scrollTop = drag.scroll + delta * drag.scale
    else viewport.scrollLeft = drag.scroll + delta * drag.scale
  }

  onPointerUp = (event) => {
    if (!this.drag || event.pointerId !== this.drag.pointer) return

    const bar = this.drag.bar
    this.drag = null
    if (bar.hasPointerCapture?.(event.pointerId)) bar.releasePointerCapture(event.pointerId)
  }

  // Renders the state from the sizes and the scroll position of the viewport.
  update() {
    const viewport = this.#viewport
    if (!viewport) return

    const threshold = this.#threshold
    const left = Math.abs(viewport.scrollLeft)
    const top = viewport.scrollTop
    const maxX = Math.max(0, viewport.scrollWidth - viewport.clientWidth)
    const maxY = Math.max(0, viewport.scrollHeight - viewport.clientHeight)
    this.position = { x: viewport.scrollLeft, y: top }

    const distances = {
      xStart: Math.round(Math.min(left, maxX)),
      xEnd: Math.round(Math.max(0, maxX - left)),
      yStart: Math.round(Math.min(top, maxY)),
      yEnd: Math.round(Math.max(0, maxY - top)),
    }

    const hasX = maxX >= 1
    const hasY = maxY >= 1
    const state = {
      "data-has-overflow-x": hasX,
      "data-has-overflow-y": hasY,
      "data-overflow-x-start": hasX && distances.xStart > threshold.xStart,
      "data-overflow-x-end": hasX && distances.xEnd > threshold.xEnd,
      "data-overflow-y-start": hasY && distances.yStart > threshold.yStart,
      "data-overflow-y-end": hasY && distances.yEnd > threshold.yEnd,
      "data-scrolling": this.scrolling.x || this.scrolling.y,
    }

    for (const el of [this.element, viewport, ...this.contentTargets]) toggleAll(el, state)

    // Tab goes to a viewport that scrolls, so the arrow keys scroll it. A viewport with no
    // overflow is out of the Tab order, as in Base UI.
    const tabindex = hasX || hasY ? "0" : "-1"
    if (viewport.getAttribute("tabindex") !== tabindex) viewport.setAttribute("tabindex", tabindex)

    setStyle(this.element, "position", "relative")
    setStyle(viewport, "overflow", "scroll")
    setStyle(viewport, "scrollbar-width", "none")
    setStyle(viewport, "--scroll-area-overflow-x-start", `${distances.xStart}px`)
    setStyle(viewport, "--scroll-area-overflow-x-end", `${distances.xEnd}px`)
    setStyle(viewport, "--scroll-area-overflow-y-start", `${distances.yStart}px`)
    setStyle(viewport, "--scroll-area-overflow-y-end", `${distances.yEnd}px`)
    for (const el of this.contentTargets) setStyle(el, "min-width", "fit-content")

    this.#renderScrollbars(state, hasX, hasY)
    this.#renderCorner(hasX, hasY)
    this.#renderThumbs(viewport)
  }

  #renderScrollbars(state, hasX, hasY) {
    for (const bar of this.scrollbarTargets) {
      const orientation = orientationOf(bar)
      const vertical = orientation === "vertical"
      const axis = vertical ? "y" : "x"
      const hidden = !(vertical ? hasY : hasX) && !bar.hasAttribute("data-keep-mounted")
      if (bar.hidden !== hidden) bar.hidden = hidden

      if (!bar.hasAttribute("data-orientation")) bar.setAttribute("data-orientation", orientation)
      toggleAll(bar, { ...state, "data-scrolling": this.scrolling[axis], "data-hovering": this.hovering })

      setStyle(bar, "position", "absolute")
      setStyle(bar, "touch-action", "none")
      setStyle(bar, "user-select", "none")
      setStyle(bar, "-webkit-user-select", "none")

      if (vertical) {
        setStyle(bar, "top", "0px")
        setStyle(bar, "bottom", "var(--scroll-area-corner-height)")
        setStyle(bar, "inset-inline-end", "0px")
      } else {
        setStyle(bar, "inset-inline-start", "0px")
        setStyle(bar, "inset-inline-end", "var(--scroll-area-corner-width)")
        setStyle(bar, "bottom", "0px")
      }
    }
  }

  // The corner shows when the two axes overflow. Its size is the width of the vertical scrollbar
  // and the height of the horizontal scrollbar.
  #renderCorner(hasX, hasY) {
    const both = hasX && hasY
    const vertical = this.scrollbarTargets.find((el) => orientationOf(el) === "vertical" && !el.hidden)
    const horizontal = this.scrollbarTargets.find((el) => orientationOf(el) === "horizontal" && !el.hidden)
    const width = both && vertical ? vertical.offsetWidth : 0
    const height = both && horizontal ? horizontal.offsetHeight : 0

    setStyle(this.element, "--scroll-area-corner-width", `${width}px`)
    setStyle(this.element, "--scroll-area-corner-height", `${height}px`)

    for (const corner of this.cornerTargets) {
      if (corner.hidden === both) corner.hidden = !both
      setStyle(corner, "position", "absolute")
      setStyle(corner, "bottom", "0px")
      setStyle(corner, "inset-inline-end", "0px")
      setStyle(corner, "width", "var(--scroll-area-corner-width)")
      setStyle(corner, "height", "var(--scroll-area-corner-height)")
    }
  }

  // The size of a thumb is the part of the content in view. Its place is the scroll position.
  #renderThumbs(viewport) {
    const rtl = this.#rtl

    for (const bar of this.scrollbarTargets) {
      const vertical = orientationOf(bar) === "vertical"
      const track = trackOf(bar, vertical)
      const size = thumbSize(viewport, track, vertical)
      const max = vertical ? viewport.scrollHeight - viewport.clientHeight : viewport.scrollWidth - viewport.clientWidth
      const scroll = vertical ? viewport.scrollTop : Math.abs(viewport.scrollLeft)
      let offset = max > 0 ? clamp(scroll / max, 0, 1) * Math.max(0, track.length - size) : 0
      if (!vertical && rtl) offset = -offset

      const variable = vertical ? "--scroll-area-thumb-height" : "--scroll-area-thumb-width"
      setStyle(bar, variable, `${Math.round(size)}px`)

      const thumb = this.#thumbOf(bar)
      if (!thumb) continue

      const orientation = vertical ? "vertical" : "horizontal"
      if (thumb.getAttribute("data-orientation") !== orientation) thumb.setAttribute("data-orientation", orientation)
      if (thumb.hasAttribute("data-scrolling") !== this.scrolling[vertical ? "y" : "x"]) thumb.toggleAttribute("data-scrolling")
      setStyle(thumb, vertical ? "height" : "width", `var(${variable})`)
      setStyle(thumb, "transform", vertical ? `translate3d(0px, ${Math.round(offset)}px, 0px)` : `translate3d(${Math.round(offset)}px, 0px, 0px)`)
    }
  }

  #observe(el) {
    this.resizing?.observe(el)
    if (this.listening) this.update()
  }

  // The attributes of the state, and the `style` of the parts.
  #owns(el, name) {
    if (name === "tabindex" && this.viewportTargets.includes(el)) return true
    if (el === this.element || this.viewportTargets.includes(el) || this.contentTargets.includes(el)) return name === "style" || OVERFLOW_ATTRIBUTES.includes(name)
    if (this.scrollbarTargets.includes(el)) return ["style", "hidden", "data-hovering"].includes(name) || OVERFLOW_ATTRIBUTES.includes(name)
    if (this.thumbTargets.includes(el)) return ["style", "data-scrolling", "data-orientation"].includes(name)
    if (this.cornerTargets.includes(el)) return name === "style" || name === "hidden"

    return false
  }

  // The thresholds of the four edges, from a number or a JSON object.
  get #threshold() {
    let parsed = 0

    try {
      parsed = JSON.parse(this.overflowEdgeThresholdValue.trim() || "0")
    } catch {
      parsed = 0
    }

    if (typeof parsed === "number") return { xStart: parsed, xEnd: parsed, yStart: parsed, yEnd: parsed }

    const edge = (name) => (typeof parsed?.[name] === "number" ? parsed[name] : 0)

    return { xStart: edge("xStart"), xEnd: edge("xEnd"), yStart: edge("yStart"), yEnd: edge("yEnd") }
  }

  get #viewport() {
    return this.viewportTargets[0] ?? null
  }

  #thumbOf(bar) {
    return this.thumbTargets.find((el) => bar.contains(el)) ?? null
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }
}

function orientationOf(bar) {
  return bar.getAttribute("data-orientation") === "horizontal" ? "horizontal" : "vertical"
}

// The content box of a scrollbar on its axis: the thumb moves in it.
function trackOf(bar, vertical) {
  const style = getComputedStyle(bar)
  const start = parseFloat(vertical ? style.paddingTop : style.paddingLeft) || 0
  const end = parseFloat(vertical ? style.paddingBottom : style.paddingRight) || 0
  const length = (vertical ? bar.clientHeight : bar.clientWidth) - start - end

  return { start, end, length: Math.max(0, length) }
}

function thumbSize(viewport, track, vertical) {
  const visible = vertical ? viewport.clientHeight : viewport.clientWidth
  const total = vertical ? viewport.scrollHeight : viewport.scrollWidth
  if (track.length <= 0 || total <= 0) return 0

  return Math.min(track.length, Math.max(MIN_THUMB_SIZE, (track.length * visible) / total))
}

function toggleAll(el, state) {
  for (const [name, on] of Object.entries(state)) {
    if (el.hasAttribute(name) !== on) el.toggleAttribute(name, on)
  }
}

// Sets a CSS property only when it changes: a write of the same value does not start a new
// style computation.
function setStyle(el, name, value) {
  if (el.style.getPropertyValue(name) !== value) el.style.setProperty(name, value)
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(value, max))
}
