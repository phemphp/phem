import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { Hover } from "./phem_hover.js"
import { modal } from "./phem_modal.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, movesFocus, nextItem, Typeahead } from "./phem_roving.js"

// Each part with no id gets one, for `aria-controls` and `aria-labelledby`.
let count = 0

// The controllers of a menu: a submenu is a menu in the popup of one of them.
const IDENTIFIERS = ["phem-menu", "phem-context-menu"]
const MENU = IDENTIFIERS.map((name) => `[data-controller~="${name}"]`).join(", ")

// The time (ms) of the pointer on another item before it gets the highlight, while a submenu is
// open: the pointer can cross an item on its way to the submenu.
const SUBMENU_GRACE = 150

// The `phem-menu` controller: a list of actions in a popup, with the keyboard of a menu. The menu
// stays open after the morph of an event.
//
//     <div data-controller="phem-menu">
//         <button data-phem-menu-target="trigger">Actions</button>
//         <div data-phem-menu-target="popup" hidden>
//             <button data-phem-menu-target="item" onClick={{ rename }}>Rename</button>
//             <div data-controller="phem-menu">
//                 <button data-phem-menu-target="trigger">Move to</button>
//                 <div data-phem-menu-target="popup" hidden>
//                     <button data-phem-menu-target="item" onClick={{ move('inbox') }}>Inbox</button>
//                 </div>
//             </div>
//             <div data-phem-menu-target="separator"></div>
//             <button data-phem-menu-target="item" onClick={{ delete }}>Delete</button>
//         </div>
//     </div>
//
// A click on the trigger opens and closes the menu. ArrowDown on the trigger opens it on the first
// item, and ArrowUp on the last item. A click with the keyboard (Enter or Space) also opens it on
// the first item. In the menu, the arrow keys (of the `orientation` value) move the focus, and go
// from the last item to the first item with the `loopFocus` value (the default). Home and End go
// to the first and to the last item, and a letter goes to the next item that starts with it. The
// keys skip a disabled item (`disabled` or `aria-disabled="true"`). With the
// `highlightItemOnHover` value (the default), the pointer on an item gives it the focus.
//
// A click on an item runs its own handler (`onClick`), and closes the menu, unless the item has
// `data-phem-menu-close-on-click="false"`. Escape closes the menu and gives the focus back to the
// trigger. Tab, a click outside and the focus out of the menu close it. With the `modal` value,
// the page does not scroll and the elements out of the menu get no pointer events while it is
// open (phem_modal.js).
//
// A `phem-menu` in the popup of a menu is a submenu: its trigger is an item of the menu around it.
// The pointer on the trigger opens it after the `delay` value, and a click, Enter, Space and
// ArrowRight (ArrowLeft in a right-to-left page) open it on its first item. ArrowLeft and Escape
// close it and give the focus back to its trigger. Its popup goes on the `inline-end` side of the
// trigger. While a submenu is open, the pointer on another item gives it the highlight after
// 150 ms, so the pointer can cross an item on its way to the submenu. A click on an item of a
// submenu closes all the menus. A disabled trigger (`aria-disabled="true"`) does not open it.
//
// The trigger gets `aria-haspopup="menu"`, `aria-expanded`, `aria-controls` and `data-popup-open`.
// The popup gets `role="menu"`, the items `role="menuitem"` (an item can have the role
// `menuitemcheckbox` or `menuitemradio` with `aria-checked`: it gets `data-checked`), the groups
// `role="group"` with `aria-labelledby` (its `groupLabel` target), and the separators
// `role="separator"`. The item with the focus gets `data-highlighted`, and a disabled item gets
// `data-disabled`. The popup is positioned as a `phem-popover` (phem_position.js).
//
// `phem-context-menu` (phem_context_menu_controller.js) extends this class: it changes the
// methods of the trigger (`anchor`, `triggerClicked()`, `triggerKeydown()`, `renderTrigger()`
// and `restoreFocus()`).
export default class PhemMenuController extends Controller {
  static targets = ["trigger", "popup", "arrow", "item", "group", "groupLabel", "separator"]
  static values = {
    open: Boolean,
    openOnHover: Boolean,
    delay: { type: Number, default: 100 },
    closeDelay: { type: Number, default: 0 },
    loopFocus: { type: Boolean, default: true },
    orientation: { type: String, default: "vertical" },
    highlightItemOnHover: { type: Boolean, default: true },
    modal: Boolean,
    ...positionValues("bottom", "start"),
  }

  initialize() {
    this.typeahead = new Typeahead()
    this.popup = new Popup({
      anchor: () => this.anchor,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => this.#positionOptions(),
      inside: () => [this.element, ...this.popupTargets],
      dismiss: (reason) => this.#close({ focus: reason === "escape" }),
    })
    this.hover = new Hover({
      enabled: () => this.#openOnHover && !(this.#trigger && isDisabled(this.#trigger)),
      triggers: () => this.triggerTargets,
      popups: () => this.popupTargets,
      isOpen: () => this.openValue,
      open: () => this.#open(null, { focus: !this.isSubmenu }),
      close: () => this.#close({ focus: false }),
      delay: () => this.delayValue,
      closeDelay: () => this.closeDelayValue,
    })
  }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        this.#renderParts()
        this.popup.reposition()
      },
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusin", this.onFocusIn, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("pointerover", this.onPointerOver, options)
    this.element.addEventListener("pointerout", this.onPointerOut, options)

    this.rendered = this.openValue
    this.popup.render(this.openValue)
    this.#renderModal()
    this.#renderParts()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.hover.cancel()
    clearTimeout(this.highlighting)
    this.typeahead.reset()
    this.popup.destroy()
    this.unlock?.()
    this.unlock = null
    this.rendered = undefined
  }

  toggle() {
    this.openValue ? this.close() : this.open()
  }

  open() {
    this.#open(null)
  }

  close() {
    this.#close({ focus: false })
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    this.#apply()
  }

  itemTargetConnected() {
    this.#renderParts()
  }

  groupTargetConnected() {
    this.#renderParts()
  }

  separatorTargetConnected() {
    this.#renderParts()
  }

  popupTargetConnected() {
    if (this.rendered === undefined) return

    this.popup.render(this.openValue)
    this.#renderModal()
    this.#renderParts()
  }

  // The menu around this one, when it is a submenu.
  get isSubmenu() {
    return this.#parent !== null
  }

  // The anchor of the popup: the trigger.
  get anchor() {
    return this.#trigger
  }

  // A click on a trigger: it opens and closes the menu. A click of the keyboard (Enter or Space)
  // has no pointer: the focus goes to the first item. A click opens a submenu, and does not
  // close it: the pointer on its trigger opened it.
  triggerClicked(event) {
    if (this.isSubmenu) this.#open(event.detail === 0 ? "first" : null, { focus: event.detail === 0 })
    else if (this.openValue) this.#close({ focus: false })
    else this.#open(event.detail === 0 ? "first" : null)
  }

  // A key on a trigger: ArrowDown and ArrowUp open the menu. Enter, Space and the arrow to the
  // popup (ArrowRight, or ArrowLeft in a right-to-left page) open a submenu.
  triggerKeydown(event) {
    const keys = this.isSubmenu ? { Enter: "first", " ": "first", [this.#openKey]: "first" } : { ArrowDown: "first", ArrowUp: "last" }
    const to = keys[event.key]
    if (!to) return

    event.preventDefault()
    this.#open(to)
  }

  // The attributes of a trigger that are not the state stay when the server render gives them.
  renderTrigger(trigger, popup) {
    if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "menu")
    if (popup && !trigger.hasAttribute("aria-controls")) trigger.setAttribute("aria-controls", popup.id)
    trigger.setAttribute("aria-expanded", String(this.openValue))

    if (!this.isSubmenu) return

    if (!trigger.hasAttribute("role")) trigger.setAttribute("role", "menuitem")
    trigger.tabIndex = -1
    trigger.toggleAttribute("data-disabled", isDisabled(trigger))
  }

  // The focus after a close of the menu with the focus in it.
  restoreFocus() {
    this.#trigger?.focus()
  }

  onClick = (event) => {
    if (event.defaultPrevented || this.#inSubmenu(event.target)) return

    const item = this.#itemOf(event.target)
    if (item) {
      if (isDisabled(item) || this.#isSubmenuTrigger(item)) return
      if (item.getAttribute(`data-${this.identifier}-close-on-click`) !== "false") this.#closeAll()

      return
    }

    const trigger = this.#triggerOf(event.target)
    if (!trigger || isDisabled(trigger)) return

    this.triggerClicked(event)
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return

    if (this.#triggerOf(event.target)) {
      if (!isDisabled(this.#triggerOf(event.target))) this.triggerKeydown(event)

      return
    }

    if (!this.popupTargets.some((el) => el.contains(event.target)) || this.#inSubmenu(event.target)) return

    if (event.key === "Tab") {
      this.#close({ focus: false })

      return
    }

    // The arrow back to the menu around a submenu closes it.
    if (this.isSubmenu && event.key === this.#closeKey) {
      event.preventDefault()
      this.#close({ focus: true })

      return
    }

    const item = this.#itemOf(event.target)

    // An item that is not a button or a link: Enter and Space click it.
    if (item && (event.key === "Enter" || event.key === " ") && !item.matches("button, a[href], input")) {
      event.preventDefault()
      item.click()

      return
    }

    const items = this.#items
    const next = movesFocus(event.key, this.orientationValue)
      ? nextItem(items, item, event.key, { orientation: this.orientationValue, loop: this.loopFocusValue, rtl: this.#rtl })
      : this.typeahead.find(items, item, event)
    if (!next) return

    event.preventDefault()
    next.focus()
  }

  onFocusIn = (event) => {
    if (this.#inSubmenu(event.target)) return

    const item = this.#itemOf(event.target)
    for (const el of this.#items) el.toggleAttribute("data-highlighted", el === item)
  }

  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (!(to instanceof Node) || !this.#itemOf(to)) for (const el of this.#items) el.removeAttribute("data-highlighted")

    if (!this.openValue || !(to instanceof Node)) return
    if (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to))) return

    this.#close({ focus: false })
  }

  onPointerOver = (event) => {
    if (event.pointerType === "touch") return

    this.hover.over(event)

    // The pointer reached the submenu: the item that it crossed does not get the highlight.
    if (this.#inSubmenu(event.target)) {
      clearTimeout(this.highlighting)

      return
    }

    const item = this.#itemOf(event.target)
    if (!item || !this.highlightItemOnHoverValue || isDisabled(item) || document.activeElement === item) return

    clearTimeout(this.highlighting)
    const highlight = () => item.isConnected && this.openValue && item.focus({ preventScroll: true })
    const open = this.#submenus.some((menu) => menu.openValue && menu.#trigger !== item)
    if (open) this.highlighting = setTimeout(highlight, SUBMENU_GRACE)
    else highlight()
  }

  onPointerOut = (event) => this.hover.out(event)

  // Opens the menu. `to` is the item that gets the focus: "first", "last", or none (the popup).
  // A submenu that the pointer opens keeps the focus on its trigger (`focus: false`).
  #open(to, { focus = true } = {}) {
    this.hover.cancel()

    if (!this.openValue) {
      this.openValue = true
      this.#apply()
    }

    if (!focus) return

    const items = this.#items
    const item = to === "first" ? nextItem(items, null, "Home") : to === "last" ? nextItem(items, null, "End") : null
    if (item) {
      item.focus()

      return
    }

    const popup = this.popupTargets[0]
    popup?.focus({ preventScroll: true })
  }

  #close({ focus }) {
    this.hover.cancel()
    clearTimeout(this.highlighting)
    if (!this.openValue) return

    for (const submenu of this.#submenus) submenu.#close({ focus: false })

    const inside = this.popupTargets.some((el) => el.contains(document.activeElement))
    this.openValue = false
    this.#apply()
    this.typeahead.reset()
    if (focus || inside) this.restoreFocus()
  }

  // A click on an item closes the menu and the menus around it.
  #closeAll() {
    let root = this
    while (root.#parent) root = root.#parent

    root.#close({ focus: true })
  }

  #apply() {
    this.rendered = this.openValue
    this.#renderParts()

    if (this.openValue) this.popup.show()
    else this.popup.hide()

    this.#renderModal()
  }

  // A modal menu locks the page while it is open. A submenu is in the popup of its menu.
  #renderModal() {
    const popup = this.popupTargets[0]
    const locked = this.openValue && this.modalValue && !this.isSubmenu && popup !== undefined

    if (locked && !this.unlock) this.unlock = modal(popup)
    else if (!locked && this.unlock) {
      this.unlock()
      this.unlock = null
    }
  }

  // The attributes that are not the state stay when the server render gives them.
  #renderParts() {
    const popup = this.popupTargets[0]

    if (popup) {
      if (!popup.hasAttribute("role")) popup.setAttribute("role", "menu")
      if (!popup.id) popup.id = this.popupId ??= `phem-menu-${++count}`
      if (!popup.hasAttribute("tabindex")) popup.tabIndex = -1
      if (this.orientationValue === "horizontal") popup.setAttribute("aria-orientation", "horizontal")
    }

    for (const trigger of this.triggerTargets) {
      this.renderTrigger(trigger, popup)
      trigger.toggleAttribute("data-popup-open", this.openValue)
    }

    for (const item of this.itemTargets) {
      if (!item.hasAttribute("role")) item.setAttribute("role", "menuitem")
      item.tabIndex = -1
      item.toggleAttribute("data-disabled", isDisabled(item))
      if (item.hasAttribute("aria-checked")) item.toggleAttribute("data-checked", item.getAttribute("aria-checked") === "true")
    }

    for (const group of this.groupTargets) {
      if (!group.hasAttribute("role")) group.setAttribute("role", "group")

      const label = this.groupLabelTargets.find((el) => group.contains(el))
      if (!label || group.hasAttribute("aria-labelledby")) continue

      if (!label.id) label.id = `phem-menu-label-${++count}`
      group.setAttribute("aria-labelledby", label.id)
    }

    for (const separator of this.separatorTargets) {
      if (!separator.hasAttribute("role")) separator.setAttribute("role", "separator")
    }
  }

  // The attributes of the state: the open state of the popup and its position, `aria-expanded`
  // and `data-popup-open` on a trigger, and `data-highlighted` on an item (also on the trigger
  // of a submenu).
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (this.triggerTargets.includes(el) && (name === "aria-expanded" || name === "data-popup-open")) return true
    if (this.#items.includes(el)) return name === "data-highlighted"

    return false
  }

  #positionOptions() {
    const options = positionOptions(this)
    if (!this.isSubmenu || this.element.hasAttribute(`data-${this.identifier}-side-value`)) return options

    return { ...options, side: this.#parent.orientationValue === "horizontal" ? "bottom" : "inline-end" }
  }

  // A submenu opens on hover, unless its `openOnHover` value is false.
  get #openOnHover() {
    if (this.isSubmenu && !this.element.hasAttribute(`data-${this.identifier}-open-on-hover-value`)) return true

    return this.openOnHoverValue
  }

  // The arrow keys of a submenu: to its popup, and back to the menu around it.
  get #openKey() {
    if (this.#parent?.orientationValue === "horizontal") return "ArrowDown"

    return this.#rtl ? "ArrowLeft" : "ArrowRight"
  }

  get #closeKey() {
    if (this.#parent?.orientationValue === "horizontal") return null

    return this.#rtl ? "ArrowRight" : "ArrowLeft"
  }

  // The menu around this one: the controller of a menu whose popup has this element.
  get #parent() {
    return this.#memo("parent", () => this.#findParent())
  }

  #findParent() {
    const el = this.element.parentElement?.closest(MENU)
    if (!el) return null

    for (const identifier of IDENTIFIERS) {
      const menu = this.application.getControllerForElementAndIdentifier(el, identifier)
      if (menu instanceof PhemMenuController && menu.popupTargets.some((popup) => popup.contains(this.element))) return menu
    }

    return null
  }

  // The submenus in the popup of this menu.
  get #submenus() {
    return this.#memo("submenus", () => this.#findSubmenus())
  }

  #findSubmenus() {
    const submenus = []

    for (const popup of this.popupTargets) {
      for (const el of popup.querySelectorAll(MENU)) {
        for (const identifier of IDENTIFIERS) {
          const menu = this.application.getControllerForElementAndIdentifier(el, identifier)
          if (menu instanceof PhemMenuController && menu.#parent === this) submenus.push(menu)
        }
      }
    }

    return submenus
  }

  // A value of the DOM for the time of one task: the events of a pointer move, a key or a morph
  // read the menus around and in this one many times.
  #memo(name, find) {
    this.memos ??= new Map()
    if (this.memos.has(name)) return this.memos.get(name)

    const value = find()
    this.memos.set(name, value)
    if (this.memos.size === 1) queueMicrotask(() => this.memos.clear())

    return value
  }

  // The items and the triggers of the submenus, in the order of the page.
  get #items() {
    const triggers = this.#submenus.map((menu) => menu.#trigger).filter((el) => el !== null)
    if (triggers.length === 0) return this.itemTargets

    return [...this.itemTargets, ...triggers].sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
  }

  get #trigger() {
    return this.triggerTargets[0] ?? null
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }

  #triggerOf(el) {
    return this.triggerTargets.find((trigger) => trigger.contains(el))
  }

  #itemOf(el) {
    return this.#items.find((item) => item.contains(el))
  }

  #isSubmenuTrigger(item) {
    return !this.itemTargets.includes(item)
  }

  // An element in the popup of a submenu: the submenu handles its events.
  #inSubmenu(el) {
    return this.#submenus.some((menu) => menu.popupTargets.some((popup) => popup.contains(el)))
  }
}
