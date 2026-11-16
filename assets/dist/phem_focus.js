// The elements of a part that Tab goes to, for the focus of a popup that opens (`phem-popover`)
// and for the focus trap of `phem-dialog`.
//
//     nextTabbable(dialog, document.activeElement)     // null: Tab goes out of the dialog
//     edgeTabbable(dialog, { backward: true })          // the last element of the dialog

const FOCUSABLE = "a[href], button, input, select, textarea, [tabindex], [contenteditable]"

// The elements in `root` that Tab goes to, in the order of the page: not a disabled element, not
// an element with a negative `tabindex`, and not a hidden element.
export function tabbables(root) {
  return [...root.querySelectorAll(FOCUSABLE)].filter(isTabbable)
}

export function isTabbable(el) {
  if (!el.matches(FOCUSABLE) || el.disabled || el.tabIndex < 0) return false

  return !el.closest("[hidden], [inert]") && (el.checkVisibility?.() ?? true)
}

// The element in `root` that Tab goes to from `from`, or Shift+Tab with `backward`: the next (or
// the previous) element in the order of the page, or null when Tab goes out of `root`. `from` can
// be any element in `root`, also one with `tabindex="-1"`, or `root` itself.
//
// The browser goes to one radio of a group: the other radios of the group of `from` are not a
// stop. The result for a radio group is its checked radio (see tabStop()).
export function nextTabbable(root, from, { backward = false } = {}) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT)
  walker.currentNode = from

  for (let el = step(walker, backward); el; el = step(walker, backward)) {
    if (isTabbable(el) && !sameRadioGroup(el, from)) return tabStop(el, root)
  }

  return null
}

// The first element in `root` that Tab goes to, or the last one with `backward`.
export function edgeTabbable(root, { backward = false } = {}) {
  const all = tabbables(root)
  const el = backward ? all.at(-1) : all[0]

  return el ? tabStop(el, root) : null
}

// The element of a radio group that Tab goes to: its checked radio. Another element is its own stop.
function tabStop(el, root) {
  if (!isRadio(el)) return el

  return [...root.querySelectorAll("input[type=radio]")].find((radio) => radio.checked && sameRadioGroup(radio, el)) ?? el
}

function step(walker, backward) {
  return backward ? walker.previousNode() : walker.nextNode()
}

function isRadio(el) {
  return el instanceof HTMLInputElement && el.type === "radio" && el.name !== ""
}

function sameRadioGroup(a, b) {
  return a !== b && isRadio(a) && isRadio(b) && a.name === b.name && a.form === b.form
}

export function firstTabbable(root) {
  return tabbables(root)[0] ?? null
}

// Whether the element has the focus of the keyboard. jsdom does not know `:focus-visible`: there,
// each focus is a focus of the keyboard.
export function isFocusVisible(el) {
  if (!(el instanceof Element)) return false

  try {
    return el.matches(":focus-visible")
  } catch {
    return true
  }
}
