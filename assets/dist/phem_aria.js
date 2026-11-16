import { realId, TEMPORARY_ID } from "./phem_ids.js"

// The ARIA links between the parts of a built-in controller.
//
// The morph matches the elements by their id, and replaces an element with an id that the server
// render does not have. So a controller gives no id to a part: with no id, the link is an element
// reference of ARIA (`ariaControlsElements`, `ariaLabelledByElements`, …).
//
//     link(trigger, "aria-controls", "ariaControlsElements", popup)
//     controlWhileOpen(trigger, popup, open) // `aria-controls` only while the popup is open

// Points an ARIA attribute of `el` to `to`: with its id, or with an element reference when `to` has
// no id or a temporary id of the morph (phem_ids.js). A value that the server render gives stays.
export function link(el, attribute, property, to) {
  const id = realId(to)
  const given = el.getAttribute(attribute)
  if (given && given !== id && !given.startsWith(TEMPORARY_ID)) return

  if (id) {
    el.setAttribute(attribute, id)
  } else if (property in el) {
    // An element reference sets the attribute to "": the same link stays as it is.
    const linked = given === "" && el[property]?.length === 1 && el[property][0] === to
    if (!linked) el[property] = [to]
  } else if (given) {
    // A temporary id, and the browser has no element references: no link is better than a link
    // to nothing.
    el.removeAttribute(attribute)
  }
}

// The triggers that got `aria-controls` from controlWhileOpen(), not from the server render.
const controlled = new WeakSet()

// Points `trigger` to `popup` with `aria-controls` while the popup is open, as in Base UI: a closed
// popup is not in the page. The attribute goes away when the popup closes. An `aria-controls` of
// the server render stays, also on a closed popup, and a new one that the morph gives too.
export function controlWhileOpen(trigger, popup, open) {
  const given = trigger.getAttribute("aria-controls")
  const ours = controlled.has(trigger) && (given === null || given === "" || (popup && given === realId(popup)))
  if (given !== null && !ours) {
    controlled.delete(trigger)

    return
  }

  if (popup && open) {
    link(trigger, "aria-controls", "ariaControlsElements", popup)
    controlled.add(trigger)

    return
  }

  if (!ours) return

  controlled.delete(trigger)
  if ("ariaControlsElements" in trigger) trigger.ariaControlsElements = null
  trigger.removeAttribute("aria-controls")
}
