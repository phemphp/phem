import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { modal } from "./phem_modal.js"
import { isDisabled, movesFocus, nextItem } from "./phem_roving.js"

const MENU = '[data-controller~="phem-menu"]'
const TRIGGER = '[data-phem-menu-target~="trigger"]'

// The `phem-menubar` controller: a bar of menus, as the menu bar of an application. Each menu of
// the bar is a `phem-menu` (phem_menu_controller.js) in the menubar element. The menubar moves the
// focus between the triggers of the menus, and between the open menus. Each menu keeps its own
// open state (its `open` value), also after the morph of an event.
//
//     <div data-controller="phem-menubar">
//         <div data-controller="phem-menu">
//             <button data-phem-menu-target="trigger">File</button>
//             <div data-phem-menu-target="popup" hidden>
//                 <button data-phem-menu-target="item" onClick={{ create }}>New</button>
//                 <button data-phem-menu-target="item" onClick={{ save }}>Save</button>
//             </div>
//         </div>
//         <div data-controller="phem-menu">
//             <button data-phem-menu-target="trigger">Edit</button>
//             <div data-phem-menu-target="popup" hidden>
//                 <button data-phem-menu-target="item" onClick={{ undo }}>Undo</button>
//             </div>
//         </div>
//     </div>
//
// The triggers have one tab stop: the trigger of the last menu that had the focus. The arrow keys
// of the `orientation` value (`horizontal` by default: ArrowLeft and ArrowRight) move the focus
// between the triggers, and skip a disabled trigger. Home and End go to the first and to the last
// trigger. With the `loopFocus` value (the default), the last trigger goes to the first one.
// ArrowDown on a trigger opens its menu on the first item (ArrowRight in a vertical menubar), as
// Enter and Space do.
//
// While a menu is open, ArrowLeft and ArrowRight in its popup close it and open the menu before or
// after it, on its first item. ArrowRight on the trigger of a submenu opens the submenu, and
// ArrowLeft in a submenu closes the submenu (phem-menu does it). In a vertical menubar, ArrowLeft
// in the popup closes the menu and gives the focus back to its trigger. While a menu is open, the
// pointer on another trigger opens its menu at once, and closes the first menu. A click on that
// trigger keeps its menu open. Only one menu of the bar is open at a time.
//
// With the `modal` value (the default), the page does not scroll and the elements out of the
// menubar get no pointer events while a menu is open (phem_modal.js): the pointer can go to the
// other triggers. With the `disabled` value, no menu of the bar opens.
//
// The menubar element gets `role="menubar"`, `aria-orientation`, `data-orientation`, `data-modal`
// (with the `modal` value), `data-disabled` and `aria-disabled` (with the `disabled` value), and
// `data-has-submenu-open` while a menu is open. The triggers get `role="menuitem"`, `tabindex`
// and `data-disabled`.
export default class PhemMenubarController extends Controller {
  static values = {
    orientation: { type: String, default: "horizontal" },
    loopFocus: { type: Boolean, default: true },
    modal: { type: Boolean, default: true },
    disabled: Boolean,
  }

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("keydown", this.onKeydownCapture, { ...options, capture: true })
    this.element.addEventListener("click", this.onClickCapture, { ...options, capture: true })
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusin", this.onFocusIn, options)
    this.element.addEventListener("pointerover", this.onPointerOver, options)
    this.element.addEventListener("phem-menu:open", this.onOpen, options)
    this.element.addEventListener("phem-menu:close", this.onClose, options)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.unlock?.()
    this.unlock = null
    this.hovered = null
  }

  orientationValueChanged() {
    if (this.listening) this.#render()
  }

  modalValueChanged() {
    if (this.listening) this.#render()
  }

  disabledValueChanged() {
    if (!this.listening) return

    if (this.disabledValue) for (const menu of this.#openMenus) menu.close()
    this.#render()
  }

  // A disabled menubar: the keys and the clicks on a trigger do nothing (phem-menu ignores an
  // event with `preventDefault()`). Tab still moves the focus.
  onKeydownCapture = (event) => {
    if (!this.disabledValue || event.key === "Tab" || !this.#triggerOf(event.target)) return

    event.preventDefault()
  }

  onClickCapture = (event) => {
    const trigger = this.#triggerOf(event.target)
    if (!trigger) return

    if (this.disabledValue) {
      event.preventDefault()

      return
    }

    // The first click on the trigger of a menu that the pointer opened keeps it open.
    const menu = this.#menuOf(trigger)
    if (menu && menu === this.hovered && menu.openValue) event.preventDefault()
    this.hovered = null
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || this.disabledValue) return

    const trigger = this.#triggerOf(event.target)
    const open = this.#openMenus[0] ?? null
    if (!trigger && !open?.popupTargets.some((popup) => popup.contains(event.target))) return

    // In a vertical menubar, the arrow back to the triggers closes the menu. Its trigger gets the
    // focus back.
    if (!trigger && this.orientationValue === "vertical" && event.key === this.#closeKey) {
      event.preventDefault()
      open.close()

      return
    }

    if (!movesFocus(event.key, this.orientationValue)) return

    const current = trigger ?? this.#triggerOfMenu(open.element)
    const next = nextItem(this.#triggers, current, event.key, { orientation: this.orientationValue, loop: this.loopFocusValue, rtl: this.#rtl })
    if (!next || next === current) return

    event.preventDefault()
    this.active = next

    if (!open) {
      next.focus()
      this.#render()

      return
    }

    // A menu is open: the next menu opens on its first item.
    for (const menu of this.#openMenus) menu.close()

    const menu = this.#menuOf(next)
    if (menu) menu.open("first")
    else next.focus()
  }

  onFocusIn = (event) => {
    const trigger = this.#triggerOf(event.target)
    if (!trigger || trigger === this.active) return

    this.active = trigger
    this.#render()
  }

  // While a menu is open, the pointer on another trigger opens its menu at once.
  onPointerOver = (event) => {
    if (event.pointerType === "touch" || this.disabledValue) return

    const trigger = this.#triggerOf(event.target)
    if (!trigger || isDisabled(trigger)) return

    const menu = this.#menuOf(trigger)
    const open = this.#openMenus
    if (!menu || open.length === 0 || open.includes(menu)) return

    for (const other of open) other.close()
    this.hovered = menu
    menu.open()
  }

  // A menu of the bar opened: the other menus close, and its trigger is the tab stop. A submenu
  // also sends the event: it changes nothing.
  onOpen = (event) => {
    if (!this.#isMenu(event.target)) return

    const trigger = this.#triggerOfMenu(event.target)
    const opened = this.#controller(event.target)
    for (const menu of this.#openMenus) if (menu !== opened) menu.close()

    if (opened !== this.hovered) this.hovered = null
    if (trigger) this.active = trigger
    this.#render()
  }

  onClose = (event) => {
    if (!this.#isMenu(event.target)) return

    if (this.hovered?.element === event.target) this.hovered = null
    this.#render()
  }

  // The attributes that are not the state stay when the server render gives them.
  #render() {
    const el = this.element
    const open = this.#openMenus.length > 0
    const triggers = this.#triggers

    if (!el.hasAttribute("role")) el.setAttribute("role", "menubar")
    if (!el.hasAttribute("aria-orientation")) el.setAttribute("aria-orientation", this.orientationValue)
    el.setAttribute("data-orientation", this.orientationValue)
    el.toggleAttribute("data-modal", this.modalValue)
    el.toggleAttribute("data-disabled", this.disabledValue)
    el.toggleAttribute("data-has-submenu-open", open)

    if (this.disabledValue) el.setAttribute("aria-disabled", "true")
    else if (this.ariaDisabled) el.removeAttribute("aria-disabled")
    this.ariaDisabled = this.disabledValue

    // The tab stop: the last trigger with the focus, else the trigger of the open menu, else the
    // first trigger that is not disabled.
    if (!triggers.includes(this.active) || isDisabled(this.active)) {
      const opened = this.#openMenus[0]
      this.active = (opened && this.#triggerOfMenu(opened.element)) || nextItem(triggers, null, "Home") || null
    }

    for (const trigger of triggers) {
      if (!trigger.hasAttribute("role")) trigger.setAttribute("role", "menuitem")
      trigger.tabIndex = trigger === this.active ? 0 : -1
      trigger.toggleAttribute("data-disabled", this.disabledValue || isDisabled(trigger))
    }

    this.#renderModal(open)
  }

  // A modal menubar locks the page while one of its menus is open. The menubar and the popups in
  // it keep the pointer events.
  #renderModal(open) {
    const locked = open && this.modalValue && !this.disabledValue

    if (locked && !this.unlock) this.unlock = modal(this.element)
    else if (!locked && this.unlock) {
      this.unlock()
      this.unlock = null
    }
  }

  // The attributes of the state: `data-has-submenu-open` and the `style` of the lock on the
  // menubar, and the tab stop of a trigger.
  #owns(el, name) {
    if (el === this.element) return name === "data-has-submenu-open" || (name === "style" && Boolean(this.unlock))

    return name === "tabindex" && this.#triggers.includes(el)
  }

  // The menus of the bar: the `phem-menu` elements in the menubar that are not submenus.
  get #menuElements() {
    return [...this.element.querySelectorAll(MENU)].filter((el) => this.#isMenu(el))
  }

  #isMenu(el) {
    return el instanceof Element && el.matches(MENU) && el.parentElement?.closest(`${MENU}, [data-controller~="${this.identifier}"]`) === this.element
  }

  get #triggers() {
    return this.#menuElements.map((el) => this.#triggerOfMenu(el)).filter((el) => el !== null)
  }

  get #openMenus() {
    return this.#menuElements.map((el) => this.#controller(el)).filter((menu) => menu?.openValue)
  }

  #triggerOfMenu(el) {
    if (!el) return null

    return [...el.querySelectorAll(TRIGGER)].find((trigger) => trigger.closest(MENU) === el) ?? null
  }

  #triggerOf(el) {
    return this.#triggers.find((trigger) => trigger.contains(el)) ?? null
  }

  #menuOf(trigger) {
    const el = trigger?.closest(MENU)

    return el && this.#isMenu(el) ? this.#controller(el) : null
  }

  #controller(el) {
    return this.application.getControllerForElementAndIdentifier(el, "phem-menu")
  }

  get #closeKey() {
    return this.#rtl ? "ArrowRight" : "ArrowLeft"
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }
}
