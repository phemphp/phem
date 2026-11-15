import { INTERACTIVE, noClick } from "./phem_press.js"
import { scrolls } from "./phem_scroll.js"

// The swipe of a part with the pointer: a toast (`phem-toast`) and a drawer (`phem-dialog` with
// its `swipeDirection` value).
//
// A press and a move of 5 px start a swipe. The part follows the pointer: the move in a direction
// of the list is the full distance, and the move in another direction has a resistance. The
// release gives the direction with the longest move of the list, its distance and its speed.
//
// A touch does not need `touch-action`: the first `touchmove` in a direction of the list stops
// the scroll of the page, and a `touchmove` in another direction lets the browser scroll (then
// the browser cancels the pointer, and the swipe stops). So the content of a drawer scrolls with
// a touch.
//
//     const stop = swipe(event, ["down", "right"], { move: (movement) => …, release: (end) => …, cancel: () => … })

// The distance (px) before a press becomes a swipe.
const START = 5

// The time window (ms) of the speed of a swipe.
const WINDOW = 100

const AXES = { up: ["y", -1], down: ["y", 1], left: ["x", -1], right: ["x", 1] }

// Follows the swipe of the press `down`. `move({ x, y })` runs on each move after the start,
// `release({ direction, distance, speed, movement })` on the release of a swipe, `cancel()` when
// the browser cancels the pointer during a swipe, and `end()` first at the end of each press.
// Returns the function that stops the swipe.
export function swipe(down, directions, { move, release, cancel = () => {}, end = () => {} }) {
  const swiping = new AbortController()
  const options = { signal: swiping.signal }
  const samples = [{ time: down.timeStamp, x: 0, y: 0 }]
  let movement = { x: 0, y: 0 }
  let started = false

  document.addEventListener("pointermove", (event) => {
    if (event.pointerId !== down.pointerId) return

    const delta = { x: event.clientX - down.clientX, y: event.clientY - down.clientY }
    samples.push({ time: event.timeStamp, ...delta })
    movement = resisted(delta, directions)
    if (!started && Math.hypot(movement.x, movement.y) < START) return

    started = true
    event.preventDefault()
    move(movement)
  }, options)

  // The first move of a touch decides: a swipe, or a scroll of the browser.
  if (down.pointerType === "touch") {
    document.addEventListener("touchmove", (event) => {
      const touch = event.touches?.[0]
      if (!touch || !event.cancelable) return

      const delta = { x: touch.clientX - down.clientX, y: touch.clientY - down.clientY }
      if (started || directions.includes(dominant(delta))) event.preventDefault()
    }, { ...options, passive: false })
  }

  document.addEventListener("pointerup", (event) => {
    if (event.pointerId !== down.pointerId) return

    swiping.abort()
    end()
    if (!started) return

    noClick()
    release(ended(movement, directions, samples, event.timeStamp))
  }, options)

  document.addEventListener("pointercancel", (event) => {
    if (event.pointerId !== down.pointerId) return

    swiping.abort()
    end()
    if (started) cancel()
  }, options)

  return () => {
    swiping.abort()
    end()
  }
}

// A press on a field, a button, a link or a label of `part` does not start a swipe.
export function onControl(part, target) {
  const control = target.closest?.(INTERACTIVE)

  return control !== null && control !== undefined && part.contains(control)
}

// The direction of a move: the axis with the longest distance.
function dominant({ x, y }) {
  if (x === 0 && y === 0) return null
  if (Math.abs(x) > Math.abs(y)) return x > 0 ? "right" : "left"

  return y > 0 ? "down" : "up"
}

// The full distance in a direction of the list, and a resistance in the other directions.
function resisted({ x, y }, directions) {
  const along = (d, positive, negative) => {
    if (d === 0) return 0
    if (directions.includes(d > 0 ? positive : negative)) return d

    return Math.round(Math.sign(d) * Math.sqrt(Math.abs(d)))
  }

  return { x: along(x, "right", "left"), y: along(y, "down", "up") }
}

// The direction of the list with the longest move, its distance and its speed (px/ms) in the
// last time window.
function ended(movement, directions, samples, now) {
  const distanceOf = (name, point) => {
    const [axis, sign] = AXES[name]

    return point[axis] * sign
  }

  const [direction] = directions.filter((name) => AXES[name]).sort((a, b) => distanceOf(b, movement) - distanceOf(a, movement))
  if (!direction) return { direction: null, distance: 0, speed: 0, movement }

  const distance = distanceOf(direction, movement)
  const last = samples.at(-1)
  const first = samples.find((sample) => now - sample.time <= WINDOW) ?? last
  const time = last.time - first.time
  const speed = time > 0 ? (distanceOf(direction, last) - distanceOf(direction, first)) / time : 0

  return { direction, distance, speed, movement }
}

// --- The drawer: a `phem-dialog` with its `swipeDirection` value. ---
//
// A press on the popup in that direction starts a swipe. The popup gets `data-swiping`, the
// distance in `--drawer-swipe-movement-x` and `--drawer-swipe-movement-y`, and the part of the
// way to the close in `--drawer-swipe-progress` (from 0 to 1). The release closes the drawer after
// a swipe of half its size, or after a fast swipe: the popup gets `data-swipe-dismiss`, so its
// `[data-ending-style]` transition goes on from its place. After a shorter swipe, the popup goes
// back to its place. `--drawer-swipe-strength` (from 0.1 to 1) is smaller after a faster swipe:
// multiply the duration of the transition of the release with it.
//
// With snap points, the popup stops at the snap point nearest to the release (`snap.set()`). A
// fast swipe goes to the next snap point in its direction, unless `snap.sequential()`. The snap
// points are offsets (px) in the direction of the swipe: 0 is the full size of the popup.
//
// A press on a field, a button, a link or a label does not start a swipe. A press in content that
// scrolls does not start a swipe until the content is at its start: the list of a bottom sheet
// scrolls up first. A press on the backdrop is not a swipe.
//
//     swipeable(popup, { direction: () => "down", open: () => dialog.open, close: () => this.close() }, signal)

// The attributes of a swipe: the morph keeps them, and the `style` of the popup with them.
export const SWIPE_ATTRIBUTES = new Set(["data-swiping", "data-swipe-dismiss"])

// The speed (px/ms) of a fast swipe.
const SPEED = 0.5

const VARIABLES = ["--drawer-swipe-movement-x", "--drawer-swipe-movement-y", "--drawer-swipe-progress", "--drawer-swipe-strength"]

const OPPOSITE = { up: "down", down: "up", left: "right", right: "left" }

// The listeners stop when `signal` aborts.
export function swipeable(popup, { direction, open = () => popup.open, close, snap = null }, signal) {
  popup.addEventListener("pointerdown", (event) => {
    const name = direction()
    if (!AXES[name] || !event.isPrimary || event.button !== 0 || !open()) return
    if (!inBox(popup, event) || onControl(popup, event.target) || scrolledBack(popup, event.target, name)) return

    const [axis, sign] = AXES[name]
    const box = popup.getBoundingClientRect()
    const size = axis === "x" ? box.width : box.height
    const offsets = snap?.offsets() ?? []
    const from = offsets.length > 0 ? snap.offset() : 0

    // A snap drawer below its full size also opens in the other direction, unless the content
    // under the press scrolls in that direction: at its full size, a list in it scrolls.
    const opens = offsets.length > 0 && from > 0 && !scrolledBack(popup, event.target, OPPOSITE[name])

    swipe(event, opens ? [name, OPPOSITE[name]] : [name], {
      move: (movement) => {
        let at = from + movement[axis] * sign
        // A snap drawer opens to its full size, then resists.
        if (at < 0 && opens) at = -Math.sqrt(-at)

        popup.setAttribute("data-swiping", "")
        popup.style.setProperty(`--drawer-swipe-movement-${axis}`, `${Math.round((at - from) * sign)}px`)
        popup.style.setProperty("--drawer-swipe-progress", String(size > from ? Math.max(0, Math.min(1, (at - from) / (size - from))) : 0))
      },
      release: ({ movement, speed }) => {
        const distance = movement[axis] * sign
        const at = from + distance
        popup.removeAttribute("data-swiping")
        popup.style.setProperty("--drawer-swipe-strength", String(Math.max(0.1, Math.min(1, 1 - Math.abs(speed) / 4))))

        const to = offsets.length > 0 ? snapTarget(offsets, size, from, at, distance, speed, snap.sequential?.() ?? false) : (distance >= size / 2 || speed >= SPEED ? size : 0)
        if (to >= size) {
          popup.setAttribute("data-swipe-dismiss", "")
          close()

          return
        }

        unswipe(popup, { strength: true })
        if (offsets.length > 0) snap.set(to)
      },
      cancel: () => unswipe(popup),
    })
  }, { signal })
}

// The snap point (an offset, or `size` for the close) of a release at `at`: the nearest one, or
// after a fast swipe the next one in its direction.
function snapTarget(offsets, size, from, at, distance, speed, sequential) {
  const points = [...new Set([...offsets, size])].sort((a, b) => a - b)

  if (!sequential && Math.abs(speed) >= SPEED) {
    const next = distance > 0 ? points.find((p) => p > from) : points.findLast((p) => p < from)

    return next ?? from
  }

  return points.reduce((best, p) => (Math.abs(p - at) < Math.abs(best - at) ? p : best), points[0])
}

// The popup without the state of a swipe: when it opens, and at the end of its close. With
// `strength`, `--drawer-swipe-strength` stays for the transition back to the place.
export function unswipe(popup, { strength = false } = {}) {
  for (const name of VARIABLES) if (!strength || name !== "--drawer-swipe-strength") popup.style.removeProperty(name)
  for (const name of SWIPE_ATTRIBUTES) popup.removeAttribute(name)
  if (popup.getAttribute("style") === "") popup.removeAttribute("style")
}

// The size (px) of a snap point: a fraction of the height of the viewport (0 to 1), a number of
// px (more than 1), or a string with `px` or `rem`.
export function snapPointSize(point) {
  const text = String(point).trim()
  const number = Number.parseFloat(text)
  if (!Number.isFinite(number)) return null

  if (text.endsWith("rem")) return number * Number.parseFloat(getComputedStyle(document.documentElement).fontSize || "16")
  if (text.endsWith("px") || number > 1) return number

  return number * window.innerHeight
}

// A press on the popup, not on the backdrop of its dialog (see phem_backdrop.js).
function inBox(popup, event) {
  if (event.target !== popup) return true

  const box = popup.getBoundingClientRect()

  return event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom
}

// Content between the press and the dialog that scrolls, and is not at the start of the swipe:
// a list of a bottom sheet that is not at its top.
function scrolledBack(dialog, target, name) {
  for (let el = target; el && el !== dialog.parentElement; el = el.parentElement) {
    if (scrolls(el, AXES[name][0]) && !atStart(el, name)) return true
  }

  return false
}

function atStart(el, name) {
  if (name === "down") return el.scrollTop <= 0
  if (name === "up") return el.scrollTop + el.clientHeight >= el.scrollHeight - 1
  if (name === "right") return Math.abs(el.scrollLeft) <= 0

  return Math.abs(el.scrollLeft) + el.clientWidth >= el.scrollWidth - 1
}
