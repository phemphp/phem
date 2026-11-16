import PhemMenuController from "./phem_menu_controller.js"

// The time (ms) of a touch on the trigger before the menu opens: iOS has no `contextmenu` event.
const LONG_PRESS = 500

// The distance (px) of a touch that is a scroll, not a long press.
const MOVE = 10

// The `phem-context-menu` controller: a `phem-menu` that opens at the point of the pointer, on a
// right click or a long press on its trigger (an area of the page). The menu stays open after the
// morph of an event.
//
//     <div data-controller="phem-context-menu">
//         <div data-phem-context-menu-target="trigger">Right click here</div>
//         <div data-phem-context-menu-target="popup" hidden>
//             <button data-phem-context-menu-target="item" onClick={{ copy }}>Copy</button>
//             <button data-phem-context-menu-target="item" onClick={{ delete }}>Delete</button>
//         </div>
//     </div>
//
// The `contextmenu` event on the trigger (a right click, the Menu key, Shift+F10, a long press on
// Android) opens the menu at its point, in place of the menu of the browser. A touch of 500 ms
// with no move also opens it (iOS). Another `contextmenu` on the trigger moves the open menu to
// the new point. The focus goes to the popup: the arrow keys go to the items.
//
// The parts, the keys of the items, the submenus and the values are the ones of `phem-menu`. A
// click on the trigger does not open the menu, and the trigger gets no ARIA attribute: it is not a
// button. It gets `data-popup-open` while the menu is open. Escape gives the focus back to the
// element that had it before the menu opened. Tab does nothing: the menu stays open. The popup goes
// at the bottom right of the point (`side` `bottom`, `align` `start`). Near an edge of the
// viewport, it keeps its side, moves on the two axes and flips its align (the `context`
// collision of phem_position.js), as in Base UI. A menu that opens with no point (the `open`
// action, or the `open` value of the server) goes below the trigger, as a `phem-menu`.
//
// A release of the right button on an item, 500 ms after the open and more than 1 px from the
// point of the open, clicks the item: a right press, a move to the item and a release run it. The
// popup gets `data-instant="click"` at the open.
export default class PhemContextMenuController extends PhemMenuController {
  // The point of the menu, in the viewport, and whether the menu opened at it.
  point = { x: 0, y: 0 }
  pointed = false

  connect() {
    super.connect()

    const options = { signal: this.listening.signal }
    this.element.addEventListener("contextmenu", this.onContextMenu, options)
    this.element.addEventListener("pointerdown", this.onPointerDown, options)
  }

  disconnect() {
    clearTimeout(this.pressing)
    super.disconnect()
  }

  // A virtual element at the point: phem_position.js places the popup next to it. It is the same
  // object for each place, so a new point moves the open popup. Its direction is the one of the
  // trigger of now: the morph can replace the trigger.
  get anchor() {
    const trigger = this.triggerTargets[0] ?? null
    if (!trigger || !this.pointed) return trigger

    const controller = this
    this.virtual ??= {
      getBoundingClientRect: () => {
        const { x, y } = this.point

        return { x, y, top: y, left: x, right: x, bottom: y, width: 0, height: 0 }
      },
      get contextElement() {
        return controller.triggerTargets[0] ?? controller.element
      },
    }

    return this.virtual
  }

  // An open with no point (the `open` action, open() of a script, the `open` value of the server)
  // gives the focus back to no element: only #openAt() sets `returnFocus`.
  open(to = null) {
    if (!this.openingAt) this.returnFocus = null
    super.open(to)
  }

  // The next open with no point goes below the trigger.
  openValueChanged() {
    if (this.openValue && !this.pointed) this.returnFocus = null
    super.openValueChanged()
    if (!this.openValue) this.pointed = false
  }

  // The root menu keeps its side near an edge. A submenu goes next to its trigger, as in a
  // `phem-menu`.
  placementOptions() {
    const options = super.placementOptions()

    return this.isSubmenu ? options : { ...options, collision: "context" }
  }

  // Tab does nothing in an open context menu, as in Base UI.
  get tabCloses() {
    return false
  }

  triggerPressed() {}

  triggerClicked() {}

  triggerKeydown() {}

  // The area has `data-pressed` while its menu is open, as the trigger of a menu.
  renderTrigger(trigger) {
    trigger.toggleAttribute("data-pressed", this.openValue)
  }

  // `returnFocus` stays until the next open: a press outside restores the focus two times (at the
  // press and in the frame after it).
  restoreFocus() {
    const to = this.returnFocus
    if (to?.isConnected) to.focus({ preventScroll: true })
  }

  onContextMenu = (event) => {
    const trigger = this.triggerTargets.find((el) => el.contains(event.target))
    if (!trigger || event.defaultPrevented || this.popupTargets.some((el) => el.contains(event.target))) return

    event.preventDefault()
    this.#openAt(trigger, event.clientX, event.clientY)
  }

  // A long press of a touch, for iOS. A move, a release or a cancel stops it.
  onPointerDown = (event) => {
    if (event.pointerType !== "touch" || !event.isPrimary) return

    const trigger = this.triggerTargets.find((el) => el.contains(event.target))
    if (!trigger || this.popupTargets.some((el) => el.contains(event.target))) return

    const start = { x: event.clientX, y: event.clientY }
    const pressing = new AbortController()
    const options = { signal: pressing.signal }
    const stop = () => {
      clearTimeout(this.pressing)
      pressing.abort()
    }

    clearTimeout(this.pressing)
    this.pressing = setTimeout(() => {
      stop()
      this.#openAt(trigger, start.x, start.y)
    }, LONG_PRESS)

    document.addEventListener("pointermove", (move) => {
      if (move.pointerId === event.pointerId && Math.hypot(move.clientX - start.x, move.clientY - start.y) > MOVE) stop()
    }, options)
    document.addEventListener("pointerup", stop, options)
    document.addEventListener("pointercancel", stop, options)
  }

  // A `contextmenu` of the keyboard has the point of the element: the menu opens at it. It has no
  // release, so no item waits for one.
  #openAt(trigger, x, y) {
    const keyboard = x === 0 && y === 0
    if (keyboard) {
      const box = (trigger.contains(document.activeElement) ? document.activeElement : trigger).getBoundingClientRect()
      x = box.left
      y = box.bottom
    }

    this.point = { x, y }
    this.pointed = true

    if (this.openValue) {
      this.popup.reposition()
      this.popupTargets[0]?.focus({ preventScroll: true })
    } else {
      const active = document.activeElement
      this.returnFocus = active instanceof HTMLElement && active !== document.body ? active : null

      this.instant = "click"
      this.openingAt = true
      try {
        this.open()
      } finally {
        this.instant = null
        this.openingAt = false
      }
    }

    if (keyboard) return

    this.release.arm({ delay: LONG_PRESS, accepts: (e) => e.button === 2 && (Math.abs(e.clientX - x) > 1 || Math.abs(e.clientY - y) > 1) })
  }
}
