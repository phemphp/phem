// The keyboard shortcuts of `phem:shortcut`: a key on the page runs an element, where the focus is.
//
//     <button onClick={{ openPalette }} phem:shortcut="mod+k">Search</button>
//     <input type="search" name="q" phem:shortcut="/" />
//
// The keys have the syntax of `phem:key` (see phem_keys.js): `mod+k` is Command+K on a Mac and
// Ctrl+K on the other systems. A shortcut clicks its element, as a user does: the click runs its
// `onClick` handler, opens its link, or runs the controller of a trigger. A field gets the focus.
// The key does not do its default action.
//
// A shortcut does not run:
//
// - when an element on the way of the key, or another listener of the document, cancels it first
//   (a menu or a popover that takes `Escape`);
// - for a key with no `ctrl`, `alt`, `meta` or `mod` (`Escape` runs), when the focus is on a
//   control that uses the key: a field that takes text, a `<select>`, a radio button, a range, or
//   `Enter` and `Space` on a button, a link or another control;
// - for the repeats of a held key;
// - for an element that is disabled, hidden or inert. In an open modal dialog, only the elements of
//   the dialog run.
//
// When two elements have the same shortcut, the first one that can run gets the key.
//
// phem_controller.js imports this module, so it listens on each page.

import { matchesKey } from "./phem_keys.js"
import { takesText } from "./phem_fields.js"

const SHORTCUT = "data-phem-shortcut"

// The controls that use each key with no modifier.
const KEY_CONTROLS = "select, input[type=radio], input[type=range]"

// The controls that use `Enter` and `Space`.
const ACTIVE_CONTROLS = "button, a[href], summary, input, [role=button], [role=link], [role=checkbox], [role=menuitem], [role=option], [role=tab], [tabindex]"

// The open dialogs, in the order that they opened: the last modal one is on top.
const opened = [...document.querySelectorAll("dialog[open]")]
const dialogs = new MutationObserver(track)
dialogs.observe(document.documentElement, { attributes: true, attributeFilter: ["open"], subtree: true })

// The listener of the bubble phase of the window: an element with the focus, and the listeners of
// the document (the Escape of a popup), can take the key first.
window.addEventListener("keydown", (event) => {
  if (event.defaultPrevented || event.isComposing || event.repeat) return
  if (usesKey(event.target, event)) return

  const el = shortcutElement(event)
  if (!el) return

  event.preventDefault()
  run(el)
})

// The first element whose shortcut matches the key and can run.
function shortcutElement(event) {
  const scope = topModal() ?? document

  for (const el of scope.querySelectorAll(`[${SHORTCUT}]`)) {
    if (matchesKey(el.getAttribute(SHORTCUT), event) && canRun(el)) return el
  }

  return null
}

// A field gets the focus. Another element gets a click.
function run(el) {
  if (takesText(el) || el.matches(KEY_CONTROLS)) {
    el.focus()
    return
  }

  el.click()
}

function canRun(el) {
  if (el.matches(":disabled") || el.getAttribute("aria-disabled") === "true" || el.closest("[inert]")) return false

  return typeof el.checkVisibility === "function" ? el.checkVisibility() : !el.closest("[hidden]")
}

// The last modal dialog that opened: the rest of the page is inert.
function topModal() {
  track(dialogs.takeRecords())

  return opened.filter((d) => d.isConnected && d.open && isModal(d)).at(-1) ?? null
}

function track(records) {
  for (const { target } of records) {
    if (!(target instanceof HTMLDialogElement)) continue

    const at = opened.indexOf(target)
    if (at !== -1) opened.splice(at, 1)
    if (target.open) opened.push(target)
  }
}

function isModal(dialog) {
  try {
    return dialog.matches(":modal")
  } catch {
    // A browser with no `:modal` selector.
    return false
  }
}

// Whether the control with the focus uses a key with no modifier: the user types it, or it moves
// the value, or it activates the control.
function usesKey(el, event) {
  if (!(el instanceof Element) || event.ctrlKey || event.metaKey || event.altKey || event.key === "Escape") return false
  if (takesText(el) || el.matches(KEY_CONTROLS)) return true

  return (event.key === "Enter" || event.key === " ") && el.matches(ACTIVE_CONTROLS)
}
