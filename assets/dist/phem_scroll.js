// The scroll position through the morph: `phem:scroll-into-view` and `phem:keep-scroll`.
//
//     <div className="comments" phem:keep-scroll>
//         <ul>
//             {% for comment in comments %}
//                 <li key={{ comment.id }} phem:scroll-into-view={{ comment.id is same as(added) }}>…</li>
//             {% endfor %}
//         </ul>
//     </div>
//
// `data-phem-scroll-into-view`: after the morph of an event of the user, the browser scrolls to
// the first element that has the attribute now and did not have it before the morph (a new
// comment). The phem controller calls scrollTargets() before the morph and scrollToNew() after
// it. A push, a refresh and the events of the browser (`load`, `poll`, `visible`) do not scroll.
//
// `data-phem-keep-scroll`: on an element that scrolls. In each Turbo Stream render (the answer of
// an event, a refresh, a push), the first item in view stays at its place, also when the render
// replaces its element. `data-phem-keep-scroll="end"`: when the element is at its end before the
// render, it stays at its end, so the new messages of a chat show. An element that does not
// overflow yet is at its end.

const SCROLL_INTO_VIEW = "[data-phem-scroll-into-view]"
const KEEP_SCROLL = "[data-phem-keep-scroll]"

// The temporary ids that the phem controller gives during the render (KEY_ID in
// phem_controller.js): they do not identify an item after the render.
const TEMPORARY_ID = "phem-key:"

// A container at most this many pixels from its end is at its end: a scroll position can be a
// fraction of a pixel.
const END = 2

// The `data-phem-scroll-into-view` elements of the root before the morph.
export function scrollTargets(root) {
  return new Set(root.querySelectorAll(SCROLL_INTO_VIEW))
}

// Scrolls to the first `data-phem-scroll-into-view` element that was not there before the morph.
// "nearest" scrolls only as much as needed. The CSS `scroll-behavior` of the page decides the
// animation. jsdom has no scrollIntoView().
export function scrollToNew(root, before) {
  const el = [...root.querySelectorAll(SCROLL_INTO_VIEW)].find((el) => !before.has(el))
  el?.scrollIntoView?.({ block: "nearest" })
}

// The innermost wrapper of the render: this module loads before the phem controller connects
// and wraps the render too (see keyMorph in phem_controller.js).
document.addEventListener("turbo:before-stream-render", (event) => {
  const render = event.detail.render
  event.detail.render = async (stream) => {
    // All the positions first, then all the changes: one layout.
    const positions = containers(stream).map(position)

    try {
      await render(stream)
    } finally {
      for (const p of positions) restore(p)
    }
  }
})

// Whether the element is at the end of its scroll. An element that does not overflow is at its
// end.
export function atEnd(el) {
  return el.scrollHeight - el.scrollTop - el.clientHeight <= END
}

// Whether the CSS of the element lets it scroll on the axis (`x` or `y`).
export function scrolls(el, axis = "y") {
  const overflow = getComputedStyle(el)[axis === "x" ? "overflowX" : "overflowY"]

  return overflow === "auto" || overflow === "scroll"
}

// The `data-phem-keep-scroll` elements that the stream can change: in a target, or around one.
function containers(stream) {
  const all = [...document.querySelectorAll(KEEP_SCROLL)]
  const targets = stream.targetElements
  if (!targets) return all

  return all.filter((c) => targets.some((t) => t.contains(c) || c.contains(t)))
}

// The scroll position of a container: at its end, or the element in view at its top. This is
// the deepest element at the top of the container (never a list around the items), with its
// distance from the top. The item is the innermost element with a key around it: the render
// can replace the element, and the browser finds the item again by its keys.
function position(container) {
  const id = realId(container)
  const end = container.getAttribute("data-phem-keep-scroll") === "end"
  if (end && atEnd(container)) return { container, id, atEnd: true }

  const top = container.getBoundingClientRect().top
  const chain = []
  for (let el = firstInView(container, top); el; el = firstInView(el, top)) chain.push(el)

  const anchor = chain.at(-1)
  const item = chain.findLast(keyOf)
  const offset = (el) => el.getBoundingClientRect().top - top

  return { container, id, atEnd: false, anchor, offset: anchor && offset(anchor), path: item && path(item, container), itemOffset: item && offset(item) }
}

// The first child in view: an element with no box (`hidden`, a `<template>`) is not in view.
function firstInView(parent, top) {
  for (const el of parent.children) {
    const r = el.getBoundingClientRect()
    if (r.bottom > r.top && r.bottom > top) return el
  }

  return null
}

function restore({ container, id, atEnd, anchor, offset, path, itemOffset }) {
  // A render that replaced the container: the new element with the same id.
  const el = container.isConnected ? container : id ? document.getElementById(id) : null
  if (!el) return

  if (atEnd) {
    if (el.scrollTop !== el.scrollHeight - el.clientHeight) el.scrollTop = el.scrollHeight
    return
  }

  // The element in view, when the render kept it in its item. Else the element of the item,
  // found by its keys: the morph can give an element to another item.
  const item = path ? find(el, path) : null
  const [target, distance] = anchor?.isConnected && el.contains(anchor) && (!path || item?.contains(anchor)) ? [anchor, offset] : item ? [item, itemOffset] : []
  if (!target) return

  const delta = target.getBoundingClientRect().top - el.getBoundingClientRect().top - distance
  if (Math.abs(delta) >= 1) el.scrollTop += delta
}

// The key of an element: its `data-phem-key`, or an id that is not temporary.
function keyOf(el) {
  if (el.hasAttribute("data-phem-key")) return { name: "data-phem-key", value: el.getAttribute("data-phem-key") }

  const id = realId(el)

  return id ? { name: "id", value: id } : null
}

function realId(el) {
  return el.id && !el.id.startsWith(TEMPORARY_ID) ? el.id : null
}

// The keys of the item and of the keyed elements around it in the container, the outermost
// first: a key is unique only in its list (the replies of two comments can have the same keys).
function path(item, container) {
  const keys = []
  for (let el = item; el && el !== container; el = el.parentElement) {
    const key = keyOf(el)
    if (key) keys.unshift(key)
  }

  return keys
}

// The element of the keys of path(): at each step, the element with the key whose closest keyed
// ancestor is the element of the step before.
function find(container, keys) {
  let scope = container
  for (const { name, value } of keys) {
    scope = [...scope.querySelectorAll(`[${name}]`)].find((el) => el.getAttribute(name) === value && keyedParent(el, container) === scope)
    if (!scope) return null
  }

  return scope
}

function keyedParent(el, container) {
  for (let parent = el.parentElement; parent && parent !== container; parent = parent.parentElement) {
    if (keyOf(parent)) return parent
  }

  return container
}
