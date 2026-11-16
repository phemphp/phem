// The open and the closed state of a part (a popup, a panel, a backdrop), with its animations.
//
// A part that opens gets `data-open`, and `data-starting-style` for its first frame: a CSS
// transition starts from the styles of `[data-starting-style]`. A part that closes gets
// `data-closed` and `data-ending-style`, and gets `hidden` when its animations end: a CSS
// transition goes to the styles of `[data-ending-style]`, and a keyframe animation can run on
// `[data-closed]`.
//
//     .popup { transition: opacity .15s, transform .15s }
//     .popup[data-starting-style], .popup[data-ending-style] { opacity: 0; transform: scale(.95) }
//
// A part that opens again before the end of its close animation stops the close. With `instant`,
// the part changes at once, with `data-instant` (for example a tooltip of a group).

// part => the AbortController of its open or close.
const running = new WeakMap()

// The properties that move the content of a panel in it.
const ALIGNMENTS = ["justify-content", "align-items", "align-content", "justify-items"]

// Opens `part`. Resolves after its first frame. `reveal` is the function that puts the part on the
// screen when `hidden` does not do it (`showPopover()`, `showModal()`). It runs after the part has
// its state, so the first styles that the browser computes for the part are the styles of
// `[data-starting-style]`. If the browser computes the open styles first, the transition goes to
// the starting styles and back, and the part shows with no animation.
export function show(part, { instant = false, hidden = true, reveal = null } = {}) {
  const signal = restart(part)

  part.removeAttribute("data-closed")
  part.removeAttribute("data-ending-style")
  part.setAttribute("data-open", "")
  instantly(part, instant)
  if (instant) part.removeAttribute("data-starting-style")
  else part.setAttribute("data-starting-style", "")
  if (hidden !== false) part.hidden = false
  reveal?.()
  if (instant) return Promise.resolve()

  // The browser reads the styles of the first frame, then the transition starts.
  void part.offsetWidth

  return new Promise((resolve) => {
    requestAnimationFrame(() => {
      if (!signal.aborted) part.removeAttribute("data-starting-style")
      resolve()
    })
  })
}

// Closes `part`. Resolves when it is hidden, or when a new show() or hide() stops it. `hidden` is
// the value of the `hidden` attribute of the closed part (for example "until-found"). `onEnd` runs
// when the part is hidden: at once with no animation, else at the end of its animations, and not
// when a new show() or hide() stops the close.
export function hide(part, { instant = false, hidden = true, onEnd = () => {} } = {}) {
  const signal = restart(part)

  part.removeAttribute("data-open")
  part.removeAttribute("data-starting-style")
  part.setAttribute("data-closed", "")
  instantly(part, instant)

  if (instant || (hidden !== false && part.hidden)) {
    end(part, hidden)
    onEnd()

    return Promise.resolve()
  }

  part.setAttribute("data-ending-style", "")

  // No animation: the part closes at once.
  const animations = animationsOf(part)
  if (animations.length === 0) {
    end(part, hidden)
    onEnd()

    return Promise.resolve()
  }

  return Promise.allSettled(animations.map((animation) => animation.finished)).then(() => {
    if (signal.aborted) return

    end(part, hidden)
    onEnd()
  })
}

// The state of `part` with no animation: for the first render, and after the morph.
export function render(part, open, { hidden = true } = {}) {
  if (open === part.hasAttribute("data-open") && open === !part.hasAttribute("data-closed") && (hidden === false || open !== isHidden(part))) return

  restart(part)
  part.removeAttribute("data-starting-style")
  part.removeAttribute("data-ending-style")
  part.toggleAttribute("data-open", open)
  part.toggleAttribute("data-closed", !open)

  if (open) {
    if (hidden !== false) part.hidden = false
  } else end(part, hidden)
}

// Gives a panel the size of its content in the `--<name>-panel-height` and `--<name>-panel-width`
// CSS variables, for a transition of its height (`phem-collapsible`, `phem-accordion`).
//
// The size is the scroll size of the panel with no alignment of its content, as in Base UI: the
// content of a panel with `justify-content: flex-end` overflows to the top while the height is 0,
// and the scroll size does not count an overflow to the top or to the start.
export function measure(panel, name) {
  const style = panel.style
  const before = ALIGNMENTS.map((property) => [property, style.getPropertyValue(property), style.getPropertyPriority(property)])
  for (const property of ALIGNMENTS) style.setProperty(property, "initial", "important")

  const height = panel.scrollHeight
  const width = panel.scrollWidth

  for (const [property, value, priority] of before) {
    if (value === "") style.removeProperty(property)
    else style.setProperty(property, value, priority)
  }

  style.setProperty(`--${name}-panel-height`, `${height}px`)
  style.setProperty(`--${name}-panel-width`, `${width}px`)
}

function restart(part) {
  running.get(part)?.abort()
  const controller = new AbortController()
  running.set(part, controller)

  return controller.signal
}

function instantly(part, instant) {
  if (instant) part.setAttribute("data-instant", typeof instant === "string" ? instant : "")
  else part.removeAttribute("data-instant")
}

// `hidden: false` keeps the part in the page: a `<dialog>` closes with close().
function end(part, hidden) {
  part.removeAttribute("data-ending-style")
  if (hidden === false) return

  if (hidden === true) part.hidden = true
  else part.setAttribute("hidden", hidden)
}

function isHidden(part) {
  return part.hasAttribute("hidden")
}

// The animations of the part and of its content. The browser starts a transition when it reads
// the new styles.
function animationsOf(part) {
  if (typeof part.getAnimations !== "function") return []

  getComputedStyle(part).getPropertyValue("opacity")

  return part.getAnimations({ subtree: true })
}
