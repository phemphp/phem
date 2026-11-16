// The elements of a part that Tab goes to, for the focus of a popup that opens (`phem-popover`)
// and for the focus trap of `phem-dialog`.

const FOCUSABLE = "a[href], button, input, select, textarea, [tabindex], [contenteditable]"

// The elements in `root` that Tab goes to, in the order of the page: not a disabled element, not
// an element with a negative `tabindex`, and not a hidden element.
export function tabbables(root) {
  const visible = (el) => !el.closest("[hidden], [inert]") && (el.checkVisibility?.() ?? true)

  return [...root.querySelectorAll(FOCUSABLE)].filter((el) => !el.disabled && el.tabIndex >= 0 && visible(el))
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
