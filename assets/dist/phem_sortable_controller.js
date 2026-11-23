import { Controller } from "@hotwired/stimulus"
import "./phem_announcer.js"
import { ClientState } from "./phem_client_state.js"
import { INTERACTIVE, noClick } from "./phem_press.js"

// The distance (px) of the pointer before a press becomes a drag: a click stays a click.
const THRESHOLD = 5

// The time (ms) of a touch on an item with no handle before it becomes a drag: a touch that moves
// before it scrolls the page.
const HOLD = 250

// The time (ms) before a live text: the screen reader reads the focus of a moved item first. A new
// step in this time replaces the text.
const ANNOUNCE_DELAY = 500

const LIST = '[data-controller~="phem-sortable"]'
const ITEM = '[data-phem-sortable-target~="item"]'
const HANDLE = '[data-phem-sortable-target~="handle"]'

// The custom properties of the root for the drop marker: its center and its size in the viewport.
const MARKER = ["--sortable-drop-x", "--sortable-drop-y", "--sortable-drop-width", "--sortable-drop-height"]

// The drag of the page: one at a time.
let drag = null

// The timer of the next live text.
let announcing = null

// The `phem-sortable` controller: drag and drop of the items of a list, with the pointer (mouse,
// touch, pen) and with the keyboard. A drop calls the handler of the list (`onCall`).
//
//     <ul data-controller="phem-sortable" data-phem-sortable-group-value="board"
//         data-phem-sortable-list-value={{ column.id }} onCall={{ move }}>
//         {% for card in column.cards %}
//             <li key={{ card.id }} data-phem-sortable-target="item" data-phem-sortable-id={{ card.id }}>{{ card.title }}</li>
//         {% endfor %}
//     </ul>
//
// A press on an item and a move of 5 px start a drag. With `handle` targets in an item, only a
// press on a handle starts it. A touch on an item with no handle starts it after a hold of 250 ms:
// a touch that moves before scrolls the page. The item stays at its place while the pointer
// moves: the list of the drop place gets `data-drop`, and phem.css draws a line (the drop marker)
// in the gap of the drop place. The drop place can also be in another list with the same `group`
// value. The release moves the item one time, and dispatches a `phem:call` event on the list of
// the drop, with the detail `{ id, index, list }`: the `data-phem-sortable-id` of the item, its
// new position in the list (from 0), and the `list` value of the list. There is no marker and no
// call when the drop place is the place of the item. Escape cancels the drag.
//
// With the keyboard: Space or Enter on an item (or on its handle) takes it, the arrow keys of the
// `orientation` value (vertical by default) move it, the other arrow keys move it to the list
// before or after in the group, and Space or Enter drops it. Escape and the blur cancel it: the
// item goes back. A handle gets `aria-pressed="true"` while the keyboard holds its item.
//
// A screen reader reads each step in the live region of the page (phem_announcer.js), 500 ms after
// the step: the `pickedUpMessage`, `movedMessage`, `droppedMessage` and `cancelledMessage` values
// of the list where the drag starts, with `{item}` (the `aria-label` or the text of the item),
// `{position}`, `{count}` and `{list}` (the `aria-label` of the list, or its `list` value). A list
// with no name has no " in {list}" part in the texts. For a drag with the pointer, it reads the
// drop (when the place changed) and the cancel.
//
// The moves use `moveBefore()` when the browser has it: the item keeps its state (the focus). The
// new order is optimistic: the morph after the event shows the order of the server render, so an
// item that the handler did not move goes back. A morph of a list during a drag cancels it.
//
// The item gets `data-dragging` during the drag, and the lists of the group get `data-sorting`.
// The root of the page gets `data-phem-sorting`: phem.css stops the text selection. phem.css also
// sets `touch-action: none` on the handles, so that a touch drag on a handle does not scroll the
// page.
export default class PhemSortableController extends Controller {
  static targets = ["item", "handle"]
  static values = {
    group: String,
    list: String,
    orientation: { type: String, default: "vertical" },
    pickedUpMessage: { type: String, default: "Picked up {item}. Position {position} of {count} in {list}." },
    movedMessage: { type: String, default: "{item}: position {position} of {count} in {list}." },
    droppedMessage: { type: String, default: "Dropped {item} at position {position} of {count} in {list}." },
    cancelledMessage: { type: String, default: "Cancelled. {item} is back at position {position} of {count} in {list}." },
  }

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => {
        if (name === "data-sorting" || name === "data-drop") return el === this.element && drag !== null
        if (name === "data-dragging") return el === drag?.item
        if (name === "aria-pressed") return el === drag?.pressed

        return false
      },
      morphed: () => this.#renderFocus(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("pointerdown", this.onPointerDown, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("turbo:before-morph-element", this.onMorph, options)
  }

  disconnect() {
    if (drag?.lists().includes(this.element)) cancel()

    this.state.disconnect()
    this.listening.abort()
  }

  itemTargetConnected() {
    this.#renderFocus()
  }

  handleTargetConnected() {
    this.#renderFocus()
  }

  onPointerDown = (event) => {
    if (drag || !event.isPrimary || event.button !== 0) return

    const item = this.#itemOf(event.target)
    if (!item || !this.#canStart(item, event.target)) return

    const start = { x: event.clientX, y: event.clientY }
    const pressing = new AbortController()
    const options = { signal: pressing.signal }
    const active = () => drag?.pressing === pressing

    // A touch on an item with no handle: a hold starts the drag, and a move before scrolls.
    const hold = event.pointerType === "touch" && this.#handles(item).length === 0
    if (hold) {
      const timer = setTimeout(() => {
        if (!drag) begin(this, item, "pointer", pressing, start)
      }, HOLD)
      pressing.signal.addEventListener("abort", () => clearTimeout(timer))
    }

    // A press with no drag: the click goes on.
    document.addEventListener("pointermove", (move) => {
      if (move.pointerId !== event.pointerId) return

      if (!drag) {
        if (Math.hypot(move.clientX - start.x, move.clientY - start.y) < THRESHOLD) return

        // The touch moves before the end of the hold: the page scrolls.
        if (hold) {
          pressing.abort()

          return
        }

        begin(this, item, "pointer", pressing, start)
      }

      if (!active()) return

      move.preventDefault()
      drag.moveTo(move.clientX, move.clientY)
    }, options)

    document.addEventListener("pointerup", (up) => {
      if (up.pointerId !== event.pointerId) return

      if (active()) {
        drop()
        noClick()
      }

      pressing.abort()
    }, options)

    // The native drag of an image or of a text would cancel the pointer (pointercancel).
    document.addEventListener("dragstart", (drag) => drag.preventDefault(), options)

    document.addEventListener("pointercancel", (cancelled) => {
      if (cancelled.pointerId !== event.pointerId) return

      if (active()) cancel()

      pressing.abort()
    }, options)

    // A touch drag after a hold: the page does not scroll, and there is no context menu.
    document.addEventListener("touchmove", (touch) => {
      if (active()) touch.preventDefault()
    }, { ...options, passive: false })
    document.addEventListener("contextmenu", (menu) => {
      if (active()) menu.preventDefault()
    }, options)

    // A scroll moves the items under the pointer: the drop place changes.
    document.addEventListener("scroll", () => {
      if (active()) drag.moveTo(drag.pointer.x, drag.pointer.y)
    }, { ...options, capture: true, passive: true })
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return

    if (drag?.mode === "keyboard" && drag.item.contains(event.target)) {
      const move = drag.keys[event.key]
      if (event.key === " " || event.key === "Enter") drop()
      else if (event.key === "Escape") cancel()
      else if (move) {
        if (move()) announce("moved")
      } else return

      event.preventDefault()

      return
    }

    if (drag || (event.key !== " " && event.key !== "Enter")) return

    // Space or Enter on the item or on its handle, not on a button in the item.
    const item = this.#itemOf(event.target)
    if (!item || event.target !== this.#grip(item)) return

    event.preventDefault()
    begin(this, item, "keyboard", null)

    // A handle is pressed while the keyboard holds its item. An item is not: a list item has no
    // pressed state.
    if (event.target !== item) {
      drag.pressed = event.target
      drag.wasPressed = event.target.getAttribute("aria-pressed")
      event.target.setAttribute("aria-pressed", "true")
    }

    announce("pickedUp")
  }

  onFocusOut = (event) => {
    if (drag?.mode !== "keyboard" || !drag.item.contains(event.target)) return

    // The move of the item in the DOM takes the focus away for a moment: the item gets it back.
    if (drag.moving) return

    const to = event.relatedTarget
    if (!(to instanceof Node) || !drag.item.contains(to)) cancel()
  }

  onMorph = (event) => {
    if (drag && event.target === this.element) cancel()
  }

  // The handles of an item, not the handles of a list in it.
  #handles(item) {
    return [...item.querySelectorAll(HANDLE)].filter((el) => listOf(el) === this.element)
  }

  // The item, or its first handle: the element that gets the focus of the keyboard.
  #grip(item) {
    return this.#handles(item)[0] ?? item
  }

  #canStart(item, target) {
    const handles = this.#handles(item)
    if (handles.length > 0) return handles.some((el) => el.contains(target))

    const interactive = target.closest(INTERACTIVE)

    return !interactive || !item.contains(interactive) || interactive === item
  }

  #renderFocus() {
    for (const item of this.itemTargets) {
      const grip = this.#grip(item)
      if (grip.tabIndex < 0 && !grip.hasAttribute("tabindex")) grip.tabIndex = 0
    }
  }

  #itemOf(el) {
    const item = el instanceof Element ? el.closest(ITEM) : null

    return item && listOf(item) === this.element ? item : null
  }
}

// The list (the controller element) of an item or of a handle.
function listOf(el) {
  return el.parentElement?.closest(LIST) ?? null
}

// The items of a list, not the items of a list in it.
function itemsOf(list) {
  return [...list.querySelectorAll(ITEM)].filter((el) => listOf(el) === list)
}

// The lists of the group of `list`, in the order of the page.
function groupOf(list) {
  const group = list.getAttribute("data-phem-sortable-group-value")
  if (!group) return [list]

  return [...document.querySelectorAll(LIST)].filter((el) => el.getAttribute("data-phem-sortable-group-value") === group)
}

function vertical(list) {
  return list.getAttribute("data-phem-sortable-orientation-value") !== "horizontal"
}

// A row from right to left: its first item is at the right.
function rightToLeft(list) {
  return !vertical(list) && getComputedStyle(list).direction === "rtl"
}

// `point` is the place of the press of a drag with the pointer: a scroll before the first move
// finds the drop place there.
function begin(controller, item, mode, pressing, point = { x: 0, y: 0 }) {
  const list = controller.element
  const origin = { list, parent: item.parentElement, next: item.nextSibling, index: itemsOf(list).indexOf(item) }

  drag = {
    controller,
    item,
    mode,
    pressing,
    origin,
    moving: false,
    // The drop place of a drag with the pointer ({ list, before }), or null for the place of the item.
    target: null,
    pointer: point,
    // The handle with `aria-pressed` during a drag with the keyboard, and its value before.
    pressed: null,
    wasPressed: null,
    lists: () => groupOf(origin.list),
    moveTo: (x, y) => moveTo(x, y),
  }
  drag.keys = keyboardMoves()

  item.setAttribute("data-dragging", "")
  for (const el of drag.lists()) el.setAttribute("data-sorting", "")
  document.documentElement.setAttribute("data-phem-sorting", "")
}

// The arrow keys of a keyboard drag: along the list, and to the list before or after.
function keyboardMoves() {
  const list = () => listOf(drag.item)
  const along = (step) => () => {
    const items = itemsOf(list())
    const at = items.indexOf(drag.item)
    const to = items[at + step]
    if (!to) return false

    place(list(), step > 0 ? to.nextSibling : to, step > 0 ? to : null)

    return true
  }
  const across = (step) => () => {
    const lists = drag.lists()
    const to = lists[lists.indexOf(list()) + step]
    if (!to) return false

    // The same position in the other list, or its end.
    const at = itemsOf(list()).indexOf(drag.item)
    const items = itemsOf(to)
    const before = items[at] ?? null
    insert(to, before)

    return true
  }

  const isVertical = vertical(listOf(drag.item))
  const rtl = getComputedStyle(drag.item).direction === "rtl"
  const [prev, next] = isVertical ? ["ArrowUp", "ArrowDown"] : rtl ? ["ArrowRight", "ArrowLeft"] : ["ArrowLeft", "ArrowRight"]
  const [prevList, nextList] = isVertical ? (rtl ? ["ArrowRight", "ArrowLeft"] : ["ArrowLeft", "ArrowRight"]) : ["ArrowUp", "ArrowDown"]

  return { [prev]: along(-1), [next]: along(1), [prevList]: across(-1), [nextList]: across(1) }
}

// The pointer is at (x, y): the drop place is before the first item of the list under the pointer
// whose middle is after the pointer (in a row from right to left, the first item whose middle the
// pointer passed). The item does not move: the marker shows the place.
function moveTo(x, y) {
  drag.pointer = { x, y }

  const under = document.elementFromPoint(x, y)
  const list = under?.closest(LIST)
  if (!list || !drag.lists().includes(list)) return

  const items = itemsOf(list).filter((el) => el !== drag.item)
  const isVertical = vertical(list)
  const rtl = rightToLeft(list)
  const before = items.find((el) => {
    const box = el.getBoundingClientRect()
    if (isVertical) return y < box.top + box.height / 2

    return rtl ? x > box.left + box.width / 2 : x < box.left + box.width / 2
  }) ?? null

  // The place of the item is not a drop place.
  const own = listOf(drag.item) === list && nextItem(drag.item) === before
  drag.target = own ? null : { list, before }
  renderMarker()
}

// The item after `item` in its list, or null for the last item.
function nextItem(item) {
  const items = itemsOf(listOf(item))

  return items[items.indexOf(item) + 1] ?? null
}

// The drop marker: `data-drop` on the list of the drop place, and the line in the gap of the place
// in the custom properties of the root.
function renderMarker() {
  clearMarker()
  if (!drag.target) return

  const { list, before } = drag.target
  const items = itemsOf(list).filter((el) => el !== drag.item)
  const after = before ? items[items.indexOf(before) - 1] : items.at(-1)
  const line = gap(list, after?.getBoundingClientRect(), before?.getBoundingClientRect())

  const root = document.documentElement.style
  root.setProperty("--sortable-drop-x", `${line.x}px`)
  root.setProperty("--sortable-drop-y", `${line.y}px`)
  root.setProperty("--sortable-drop-width", `${line.width}px`)
  root.setProperty("--sortable-drop-height", `${line.height}px`)
  list.setAttribute("data-drop", "")
}

function clearMarker() {
  for (const el of document.querySelectorAll(`${LIST}[data-drop]`)) el.removeAttribute("data-drop")
  for (const name of MARKER) document.documentElement.style.removeProperty(name)
}

// The line between the box `prev` and the box `next` of a list (or at the start of an empty list):
// its center, and its length across the list.
function gap(list, prev, next) {
  const box = next ?? prev ?? contentBox(list)

  if (vertical(list)) {
    const y = prev && next ? (prev.bottom + next.top) / 2 : next ? next.top : prev ? prev.bottom : box.top

    return { x: box.left + box.width / 2, y, width: box.width, height: 0 }
  }

  const rtl = rightToLeft(list)
  const start = (b) => (rtl ? b.right : b.left)
  const end = (b) => (rtl ? b.left : b.right)
  const x = prev && next ? (end(prev) + start(next)) / 2 : next ? start(next) : prev ? end(prev) : start(box)

  return { x, y: box.top + box.height / 2, width: 0, height: box.height }
}

// The box of a list in the viewport, with no border and no padding.
function contentBox(list) {
  const box = list.getBoundingClientRect()
  const style = getComputedStyle(list)
  const px = (name) => parseFloat(style[name]) || 0
  const left = box.left + px("borderLeftWidth") + px("paddingLeft")
  const top = box.top + px("borderTopWidth") + px("paddingTop")
  const right = box.right - px("borderRightWidth") - px("paddingRight")
  const bottom = box.bottom - px("borderBottomWidth") - px("paddingBottom")

  return { left, top, right, bottom, width: right - left, height: bottom - top }
}

// The item goes before `before`, an item of `list`, or after the last item of `list`.
function insert(list, before) {
  if (before) {
    place(list, before, null)

    return
  }

  const items = itemsOf(list).filter((el) => el !== drag.item)
  const last = items.at(-1)
  if (last) place(list, last.nextSibling, last)
  else place(list, null, null)
}

// Puts the item before the node `next` of the parent of `after` (or of the list). The focus stays
// on the item.
function place(list, next, after) {
  const item = drag.item
  const parent = next?.parentElement ?? after?.parentElement ?? list
  if (item.nextSibling === next && item.parentElement === parent) return

  move(parent, item, next)
  item.scrollIntoView?.({ block: "nearest", inline: "nearest" })
}

// Moves the item with its state (the focus) when the browser can: moveBefore(). With
// insertBefore(), the item loses the focus for a moment, and gets it back.
function move(parent, item, next) {
  const focused = item.contains(document.activeElement) ? document.activeElement : null
  drag.moving = true

  try {
    if ("moveBefore" in Element.prototype) {
      try {
        parent.moveBefore(item, next)

        return
      } catch {
        // The browser cannot move it with its state here: insertBefore() below.
      }
    }

    parent.insertBefore(item, next)
    focused?.focus({ preventScroll: true })
  } finally {
    drag.moving = false
  }
}

// The end of a drag: the item goes to the drop place of the pointer, and a `phem:call` on the list
// of the drop, when the place changed.
function drop() {
  const { item, origin, mode, target } = drag
  if (mode === "pointer" && target) insert(target.list, target.before)

  const list = listOf(item)
  const index = itemsOf(list).indexOf(item)
  const moved = list !== origin.list || index !== origin.index
  if (mode === "keyboard" || moved) announce("dropped")
  end()

  if (!moved) return

  const detail = { id: item.getAttribute("data-phem-sortable-id"), index, list: list.getAttribute("data-phem-sortable-list-value") ?? "" }
  list.dispatchEvent(new CustomEvent("phem:call", { detail }))
}

// The item goes back to its first place. A drag with the pointer did not move it.
function cancel() {
  const { item, origin } = drag
  const home = origin.next && origin.next.parentNode === origin.parent
  if (home && item.nextSibling !== origin.next) move(origin.parent, item, origin.next)
  else if (!home && origin.parent.isConnected && origin.parent.lastChild !== item) move(origin.parent, item, null)

  announce("cancelled")
  end()
}

function end() {
  const { item, pressing, pressed, wasPressed } = drag
  item.removeAttribute("data-dragging")
  for (const el of drag.lists()) el.removeAttribute("data-sorting")
  document.documentElement.removeAttribute("data-phem-sorting")
  clearMarker()

  if (wasPressed === null) pressed?.removeAttribute("aria-pressed")
  else pressed.setAttribute("aria-pressed", wasPressed)

  pressing?.abort()
  drag = null
}

// The live text of a step of a drag, from the values of the list where it started. It goes to the
// live region after a delay: the screen reader reads the focus of a moved item first, and a new
// step in the delay replaces the text.
function announce(step) {
  const { controller, item } = drag
  const list = listOf(item)
  const items = itemsOf(list)
  const words = {
    item: labelOf(item),
    position: String(items.indexOf(item) + 1),
    count: String(items.length),
    list: list.getAttribute("aria-label") || list.getAttribute("data-phem-sortable-list-value") || "",
  }

  // A list with no name: the text has no " in {list}".
  let message = controller[`${step}MessageValue`]
  if (words.list === "") message = message.replace(/ in \{list\}/g, "")

  message = message.replace(/\{(item|position|count|list)\}/g, (_, name) => words[name])

  clearTimeout(announcing)
  announcing = setTimeout(() => window.dispatchEvent(new CustomEvent("phem:announce", { detail: message })), ANNOUNCE_DELAY)
}

// The name of an item: its `aria-label`, or the one of its handle, or its text.
function labelOf(item) {
  const handle = [...item.querySelectorAll(HANDLE)].find((el) => listOf(el) === listOf(item))

  return item.getAttribute("aria-label") || handle?.getAttribute("aria-label") || item.textContent.trim().replace(/\s+/g, " ")
}

// Escape cancels a drag with the pointer.
document.addEventListener("keydown", (event) => {
  if (event.key !== "Escape" || drag?.mode !== "pointer") return

  event.preventDefault()
  cancel()
})
