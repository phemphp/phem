import { INTERACTIVE, noClick } from "./phem_press.js"

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
// A press on the dialog in that direction starts a swipe. The dialog gets `data-swiping`, the
// distance in `--dialog-swipe-movement-x` and `--dialog-swipe-movement-y`, and the part of its
// size in `--dialog-swipe-progress` (from 0 to 1). The release closes the dialog after a swipe of
// half its size, or after a fast swipe: the dialog gets `data-swipe-direction`, so its
// `[data-ending-style]` transition goes on from its place. After a shorter swipe, the dialog goes
// back to its place.
//
// A press on a field, a button, a link or a label does not start a swipe. A press in content that
// scrolls does not start a swipe until the content is at its start: the list of a bottom sheet
// scrolls up first. A press on the backdrop is not a swipe.
//
//     swipeable(dialog, { direction: () => "down", close: () => this.close() }, signal)

// The attributes of a swipe: the morph keeps them, and the `style` of the dialog with them.
export const SWIPE_ATTRIBUTES = new Set(["data-swiping", "data-swipe-direction"])

// The speed (px/ms) of a fast swipe.
const SPEED = 0.5

const VARIABLES = ["--dialog-swipe-movement-x", "--dialog-swipe-movement-y", "--dialog-swipe-progress"]

// The listeners stop when `signal` aborts.
export function swipeable(dialog, { direction, close }, signal) {
  dialog.addEventListener("pointerdown", (event) => {
    const name = direction()
    if (!AXES[name] || !event.isPrimary || event.button !== 0 || !dialog.open) return
    if (!inBox(dialog, event) || onControl(dialog, event.target) || scrolledBack(dialog, event.target, name)) return

    const [axis, sign] = AXES[name]
    const box = dialog.getBoundingClientRect()
    const size = axis === "x" ? box.width : box.height

    swipe(event, [name], {
      move: (movement) => {
        const distance = movement[axis] * sign
        dialog.setAttribute("data-swiping", "")
        dialog.style.setProperty(`--dialog-swipe-movement-${axis}`, `${movement[axis]}px`)
        dialog.style.setProperty("--dialog-swipe-progress", String(size > 0 ? Math.max(0, Math.min(1, distance / size)) : 0))
      },
      release: ({ distance, speed }) => {
        dialog.removeAttribute("data-swiping")
        if (distance < size / 2 && speed < SPEED) {
          unswipe(dialog)

          return
        }

        dialog.setAttribute("data-swipe-direction", name)
        close()
      },
      cancel: () => unswipe(dialog),
    })
  }, { signal })
}

// The dialog without the state of a swipe: when it opens, and at the end of its close.
export function unswipe(dialog) {
  for (const name of VARIABLES) dialog.style.removeProperty(name)
  for (const name of SWIPE_ATTRIBUTES) dialog.removeAttribute(name)
  if (dialog.getAttribute("style") === "") dialog.removeAttribute("style")
}

// A press on the dialog, not on its backdrop (see phem_backdrop.js).
function inBox(dialog, event) {
  if (event.target !== dialog) return true

  const box = dialog.getBoundingClientRect()

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

function scrolls(el, axis) {
  const overflow = getComputedStyle(el)[axis === "x" ? "overflowX" : "overflowY"]

  return overflow === "auto" || overflow === "scroll"
}

function atStart(el, name) {
  if (name === "down") return el.scrollTop <= 0
  if (name === "up") return el.scrollTop + el.clientHeight >= el.scrollHeight - 1
  if (name === "right") return Math.abs(el.scrollLeft) <= 0

  return Math.abs(el.scrollLeft) + el.clientWidth >= el.scrollWidth - 1
}
