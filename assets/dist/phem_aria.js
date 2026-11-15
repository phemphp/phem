// The ARIA links between the parts of a built-in controller.
//
// The morph matches the elements by their id, and replaces an element with an id that the server
// render does not have. So a controller gives no id to a part: with no id, the link is an element
// reference of ARIA (`ariaControlsElements`, `ariaLabelledByElements`, …).
//
//     link(trigger, "aria-controls", "ariaControlsElements", popup)

// Points an ARIA attribute of `el` to `to`: with its id, or with an element reference when `to` has
// no id. A value that the server render gives stays.
export function link(el, attribute, property, to) {
  const given = el.getAttribute(attribute)
  if (given && given !== to.id) return

  if (to.id) el.setAttribute(attribute, to.id)
  else if (property in el) el[property] = [to]
}
