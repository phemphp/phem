import { Controller } from "@hotwired/stimulus"
import "./phem_announcer.js"
import { ClientState } from "./phem_client_state.js"
import { INTERACTIVE, noClick } from "./phem_press.js"

// The distance (px) of the pointer before a press becomes a drag: a click stays a click.
const THRESHOLD = 5

const LIST = '[data-controller~="phem-sortable"]'
const ITEM = '[data-phem-sortable-target~="item"]'
const HANDLE = '[data-phem-sortable-target~="handle"]'

// The drag of the page: one at a time.
let drag = null

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
// press on a handle starts it. The item moves in the list while the pointer moves, and also into
// another list with the same `group` value. A drop dispatches a `phem:call` event on the list of
// the drop, with the detail `{ id, index, list }`: the `data-phem-sortable-id` of the item, its
// new position in the list (from 0), and the `list` value of the list. There is no call when the
// item is at its first place. Escape cancels the drag: the item goes back.
//
// With the keyboard: Space or Enter on an item (or on its handle) takes it, the arrow keys of the
// `orientation` value (vertical by default) move it, the other arrow keys move it to the list
// before or after in the group, and Space or Enter drops it. Escape and the blur cancel it. A
// screen reader reads each step in the live region of the page (phem_announcer.js): the
// `pickedUpMessage`, `movedMessage`, `droppedMessage` and `cancelledMessage` values of the list
// where the drag starts, with `{item}` (the `aria-label` or the text of the item), `{position}`,
// `{count}` and `{list}` (the `aria-label` of the list, or its `list` value). A list with no
// name has no " in {list}" part in the texts.
//
// The new order is optimistic: the morph after the event shows the order of the server render, so
// an item that the handler did not move goes back. A morph of a list during a drag cancels it.
//
// The item gets `data-dragging` during the drag, and the lists of the group get `data-sorting`.
// The root of the page gets `data-phem-sorting`: phem.css stops the text selection. phem.css also
// sets `touch-action: none` on the handles (or on the items with no handle), so that a touch drag
// does not scroll the page.
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
        if (name === "data-sorting") return el === this.element && drag !== null
        if (name === "data-dragging") return el === drag?.item

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

    // A press with no drag: the click goes on.
    document.addEventListener("pointermove", (move) => {
      if (move.pointerId !== event.pointerId) return

      if (!drag) {
        if (Math.hypot(move.clientX - start.x, move.clientY - start.y) < THRESHOLD) return

        begin(this, item, "pointer", pressing)
      }

      move.preventDefault()
      drag.moveTo(move.clientX, move.clientY)
    }, options)

    document.addEventListener("pointerup", (up) => {
      if (up.pointerId !== event.pointerId) return

      if (drag?.pressing === pressing) {
        drop()
        noClick()
      }

      pressing.abort()
    }, options)

    // The native drag of an image or of a text would cancel the pointer (pointercancel).
    document.addEventListener("dragstart", (drag) => drag.preventDefault(), options)

    document.addEventListener("pointercancel", () => {
      if (drag?.pressing === pressing) cancel()

      pressing.abort()
    }, options)
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

  // The item, or its first handle: the element that gets the focus of the keyboard.
  #grip(item) {
    return [...item.querySelectorAll(HANDLE)].find((el) => listOf(el) === this.element) ?? item
  }

  #canStart(item, target) {
    const handles = [...item.querySelectorAll(HANDLE)].filter((el) => listOf(el) === this.element)
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

function begin(controller, item, mode, pressing) {
  const list = controller.element
  const origin = { list, parent: item.parentElement, next: item.nextSibling, index: itemsOf(list).indexOf(item) }

  drag = {
    controller,
    item,
    mode,
    pressing,
    origin,
    moving: false,
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

// The pointer is at (x, y): the item goes before the first item of the list under the pointer
// whose middle is after the pointer.
function moveTo(x, y) {
  const under = document.elementFromPoint(x, y)
  const list = under?.closest(LIST)
  if (!list || !drag.lists().includes(list)) return

  const items = itemsOf(list).filter((el) => el !== drag.item)
  const isVertical = vertical(list)
  const before = items.find((el) => {
    const box = el.getBoundingClientRect()

    return isVertical ? y < box.top + box.height / 2 : x < box.left + box.width / 2
  }) ?? null

  insert(list, before)
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

  const focused = item.contains(document.activeElement) ? document.activeElement : null
  drag.moving = true
  parent.insertBefore(item, next)
  drag.moving = false
  focused?.focus({ preventScroll: true })
  item.scrollIntoView?.({ block: "nearest", inline: "nearest" })
}

// The end of a drag: a `phem:call` on the list of the drop, when the place changed.
function drop() {
  const { item, origin } = drag
  const list = listOf(item)
  const index = itemsOf(list).indexOf(item)
  if (drag.mode === "keyboard") announce("dropped")
  end()

  if (list === origin.list && index === origin.index) return

  const detail = { id: item.getAttribute("data-phem-sortable-id"), index, list: list.getAttribute("data-phem-sortable-list-value") ?? "" }
  list.dispatchEvent(new CustomEvent("phem:call", { detail }))
}

// The item goes back to its first place.
function cancel() {
  const { item, origin } = drag
  const focused = item.contains(document.activeElement) ? document.activeElement : null
  drag.moving = true
  if (origin.next && origin.next.parentNode === origin.parent) origin.parent.insertBefore(item, origin.next)
  else if (origin.parent.isConnected) origin.parent.append(item)
  drag.moving = false
  if (drag.mode === "keyboard") announce("cancelled")
  end()
  focused?.focus({ preventScroll: true })
}

function end() {
  const { item, pressing } = drag
  item.removeAttribute("data-dragging")
  for (const el of drag.lists()) el.removeAttribute("data-sorting")
  document.documentElement.removeAttribute("data-phem-sorting")
  pressing?.abort()
  drag = null
}

// The live text of a step of a keyboard drag, from the values of the list where it started.
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

  window.dispatchEvent(new CustomEvent("phem:announce", { detail: message }))
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
