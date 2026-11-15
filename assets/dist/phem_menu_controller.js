import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { Hover } from "./phem_hover.js"
import { modal } from "./phem_modal.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, movesFocus, nextItem, Typeahead } from "./phem_roving.js"
import { link } from "./phem_aria.js"

// The controllers of a menu: a submenu is a menu in the popup of one of them.
const IDENTIFIERS = ["phem-menu", "phem-context-menu"]
const MENU = IDENTIFIERS.map((name) => `[data-controller~="${name}"]`).join(", ")

// A menubar around a menu: it moves the focus between the menus (phem_menubar_controller.js).
const MENUBAR = '[data-controller~="phem-menubar"]'

// The time (ms) of the pointer on another item before it gets the highlight, while a submenu is
// open: the pointer can cross an item on its way to the submenu.
const SUBMENU_GRACE = 150

// The roles of an item that the user checks: a checkbox item and a radio item.
const CHECKABLE = ["menuitemcheckbox", "menuitemradio"]

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
// `highlightItemOnHover` value (the default), a move of the pointer on an item gives it the focus:
// an item that comes under a pointer that does not move (the menu opens under it) does not get it.
//
// A click on an item runs its own handler (`onClick`), and closes the menu, unless the item has
// `data-phem-menu-close-on-click="false"`. Escape closes the menu and gives the focus back to the
// trigger. Tab, a click outside and the focus out of the menu close it. With the `modal` value,
// the page does not scroll and the elements out of the menu get no pointer events while it is
// open (phem_modal.js).
//
// An item with the role `menuitemcheckbox` or `menuitemradio` is an item that the user checks.
// With no handler (`onClick`), its state belongs to the browser. A click on a checkbox item
// changes its `aria-checked`. A click on a radio item checks it, and unchecks the other radio
// items of its group: the radio items in the same `group` target, or the radio items in no group.
// The item sends `phem-menu:change`, with `{ checked, value }` (the `data-value` of the item) in
// the detail. The first state is the `aria-checked` of the server render. The state stays after
// the morph of an event, unless the server render gives a new `aria-checked`: then the new value
// sets the state, and a radio item that the server checks unchecks the others of its group. A
// page in the Turbo cache has the state of the server render.
//
// An item with a handler takes its state from the server: a click does not change it in the
// browser, and the morph of its event shows the new `aria-checked`.
//
// A `phem-menu` in the popup of a menu is a submenu: its trigger is an item of the menu around it.
// The pointer on the trigger opens it after the `delay` value, and a click, Enter, Space and
// ArrowRight (ArrowLeft in a right-to-left page) open it on its first item. ArrowLeft and Escape
// close it and give the focus back to its trigger. Its popup goes on the `inline-end` side of the
// trigger. While a submenu is open, the pointer on another item gives it the highlight after
// 150 ms, so the pointer can cross an item on its way to the submenu. A click on an item of a
// submenu closes all the menus. A disabled trigger (`aria-disabled="true"`) does not open it.
//
// A `phem-menu` in a `phem-menubar` is a menu of the menubar (phem_menubar_controller.js). In a
// vertical menubar, ArrowRight (ArrowLeft in a right-to-left page) opens it in place of ArrowDown
// and ArrowUp, and its popup goes on the `inline-end` side of the trigger.
//
// The menu sends `phem-menu:open` when it opens and `phem-menu:close` when it closes. The `open`
// action and the open() method open it with the focus on the popup. A script can give open() the
// item that gets the focus: `open("first")` or `open("last")`.
//
// The trigger gets `aria-haspopup="menu"`, `aria-expanded`, `aria-controls`, `data-popup-open` and
// `data-pressed` (not the trigger of a submenu).
// The popup gets `role="menu"`, the items `role="menuitem"` (an item with `aria-checked` gets
// `data-checked` or `data-unchecked`), the groups
// `role="group"` with `aria-labelledby` (its `groupLabel` target), and the separators
// `role="separator"`. A popup or a group label with no `id` gets no id: the trigger and the group
// point to it with an element reference (`ariaControlsElements`, `ariaLabelledByElements`). The
// item with the focus gets `data-highlighted`, and a disabled item gets `data-disabled`. The popup is positioned as a `phem-popover` (phem_position.js).
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
    // The checked state of each checkbox item and radio item: the last `aria-checked` of a server
    // render, and the time of the last change by the client. An item connects before the menu.
    this.checks = new WeakMap()
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
        this.#settleRadios()
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
    this.element.addEventListener("pointermove", this.onPointerMove, options)
    this.element.addEventListener("pointerout", this.onPointerOut, options)
    this.element.addEventListener("turbo:before-morph-element", this.onBeforeMorph, options)
    document.addEventListener("turbo:before-cache", this.onBeforeCache, options)

    this.rendered = this.openValue
    this.popup.render(this.openValue)
    this.#renderModal()
    this.#renderParts()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.hover.cancel()
    this.#cancelHighlight()
    this.typeahead.reset()
    this.popup.destroy()
    this.unlock?.()
    this.unlock = null
    this.rendered = undefined
  }

  toggle() {
    this.openValue ? this.close() : this.open()
  }

  // `to` is the item that gets the focus: "first" or "last". An action gives an event: the focus
  // goes to the popup.
  open(to = null) {
    this.#open(typeof to === "string" ? to : null)
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
  // In a vertical menubar, the arrow to the popup opens the menu: ArrowDown and ArrowUp go to the
  // other menus of the menubar.
  triggerKeydown(event) {
    let keys = { ArrowDown: "first", ArrowUp: "last" }
    if (this.isSubmenu) keys = { Enter: "first", " ": "first", [this.#openKey]: "first" }
    else if (this.#menubarOrientation === "vertical") keys = { [this.#openKey]: "first" }

    const to = keys[event.key]
    if (!to) return

    event.preventDefault()
    this.#open(to)
  }

  // The attributes of a trigger that are not the state stay when the server render gives them.
  renderTrigger(trigger, popup) {
    if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "menu")
    if (popup) link(trigger, "aria-controls", "ariaControlsElements", popup)
    trigger.setAttribute("aria-expanded", String(this.openValue))
    trigger.toggleAttribute("data-pressed", this.openValue && !this.isSubmenu)

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

      this.#check(item)
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
    if (this.#inSubmenu(event.target)) this.#cancelHighlight()
  }

  // A move of the pointer highlights the item under it. The browser also sends `pointerover` when
  // an item comes under a pointer that does not move (the popup opens under it): that item does
  // not take the highlight of the keyboard.
  onPointerMove = (event) => {
    if (event.pointerType === "touch" || !this.highlightItemOnHoverValue || this.#inSubmenu(event.target)) return

    // The moves on the item with the focus, or on the item that waits for its grace time: no
    // lookup of the items, and the time does not start again.
    const last = this.pointerItem
    if (last?.contains(event.target) && (document.activeElement === last || this.highlightingItem === last)) return

    const item = this.#itemOf(event.target)
    this.pointerItem = item
    if (!item || isDisabled(item) || item === this.highlightingItem) return

    // The pointer came back to the item with the focus: the item that it crossed does not get it.
    this.#cancelHighlight()
    if (document.activeElement === item) return

    this.highlightingItem = item
    const highlight = () => {
      this.highlightingItem = null
      if (item.isConnected && this.openValue) item.focus({ preventScroll: true })
    }
    const open = this.#submenus.some((menu) => menu.openValue && menu.#trigger !== item)
    if (open) this.highlighting = setTimeout(highlight, SUBMENU_GRACE)
    else highlight()
  }

  onPointerOut = (event) => this.hover.out(event)

  // The morph of a checkbox item or a radio item: a new `aria-checked` of the server render sets
  // the state, unless the client changed it after the request of the answer left (`phemSentAt` of
  // the phem root, see phem_client_state.js).
  onBeforeMorph = (event) => {
    const check = this.checks.get(event.target)
    if (!check) return

    const sentAt = this.element.closest("[data-controller~='phem']")?.phemSentAt ?? null
    const next = event.detail.newElement?.getAttribute("aria-checked") ?? null
    check.serverChanged = next !== check.server && !(sentAt !== null && check.changedAt > sentAt)
    check.server = next
  }

  // The page that Turbo keeps in its cache has the state of the server render: a new controller
  // reads the first state of each item from it.
  onBeforeCache = () => {
    for (const item of this.itemTargets) {
      const check = this.checks.get(item)
      if (!check) continue

      if (check.server === null) item.removeAttribute("aria-checked")
      else item.setAttribute("aria-checked", check.server)

      this.#renderChecked(item)
    }
  }

  // After a morph: a radio item that the server render checked unchecks the other radio items of
  // its group, also an item that the browser checked.
  #settleRadios() {
    for (const item of this.itemTargets) {
      const check = this.checks.get(item)
      if (!check?.serverChanged) continue

      check.serverChanged = false
      if (item.getAttribute("role") !== "menuitemradio" || item.getAttribute("aria-checked") !== "true") continue

      for (const el of this.#radios(item)) {
        if (el === item || el.getAttribute("aria-checked") !== "true") continue

        el.setAttribute("aria-checked", "false")
        this.#renderChecked(el)
      }
    }
  }

  // A click on a checkbox item changes its state. A click on a radio item checks it, and unchecks
  // the other radio items of its group. An item with a handler takes its state from the server.
  #check(item) {
    const role = item.getAttribute("role")
    if (!CHECKABLE.includes(role) || item.hasAttribute("data-phem-on-click")) return

    const checked = item.getAttribute("aria-checked") === "true"
    if (role === "menuitemradio" && checked) return

    const items = role === "menuitemradio" ? this.#radios(item) : [item]
    for (const el of items) this.#setChecked(el, el === item ? !checked : false)

    this.dispatch("change", { target: item, detail: { checked: !checked, value: item.getAttribute("data-value") } })
  }

  #setChecked(item, checked) {
    if ((item.getAttribute("aria-checked") === "true") === checked) return

    item.setAttribute("aria-checked", String(checked))
    this.#renderChecked(item)

    const check = this.checks.get(item)
    if (check) check.changedAt = performance.now()
  }

  #renderChecked(item) {
    const checked = item.getAttribute("aria-checked") === "true"
    item.toggleAttribute("data-checked", checked)
    item.toggleAttribute("data-unchecked", !checked)
  }

  // The radio items of the group of a radio item: the radio items in the same `group` target, or
  // the radio items in no group.
  #radios(item) {
    const groupOf = (el) => this.groupTargets.findLast((group) => group.contains(el)) ?? null
    const group = groupOf(item)

    return this.itemTargets.filter((el) => el.getAttribute("role") === "menuitemradio" && groupOf(el) === group)
  }

  #cancelHighlight() {
    clearTimeout(this.highlighting)
    this.highlightingItem = null
  }

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
    this.#cancelHighlight()
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
    this.dispatch(this.openValue ? "open" : "close")
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
      if (item.hasAttribute("aria-checked")) this.#renderChecked(item)

      // The first state of an item that the user checks is the state of the server render.
      if (CHECKABLE.includes(item.getAttribute("role")) && !this.checks.has(item)) {
        this.checks.set(item, { server: item.getAttribute("aria-checked"), serverChanged: false, changedAt: -Infinity })
      }
    }

    for (const group of this.groupTargets) {
      if (!group.hasAttribute("role")) group.setAttribute("role", "group")

      const label = this.groupLabelTargets.find((el) => group.contains(el))
      if (label) link(group, "aria-labelledby", "ariaLabelledByElements", label)
    }

    for (const separator of this.separatorTargets) {
      if (!separator.hasAttribute("role")) separator.setAttribute("role", "separator")
    }
  }

  // The attributes of the state: the open state of the popup and its position, `aria-expanded`,
  // `data-popup-open` and `data-pressed` on a trigger, and `data-highlighted` on an item (also on
  // the trigger of a submenu). A checkbox item and a radio item with no handler keep `aria-checked`,
  // `data-checked` and `data-unchecked`, unless the server render gives a new `aria-checked`.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (this.triggerTargets.includes(el) && (name === "aria-expanded" || name === "data-popup-open" || name === "data-pressed")) return true
    if (!this.#items.includes(el)) return false
    if (name === "data-highlighted") return true

    const check = this.checks.get(el)

    if (check === undefined || check.serverChanged || el.hasAttribute("data-phem-on-click")) return false

    return name === "aria-checked" || name === "data-checked" || name === "data-unchecked"
  }

  #positionOptions() {
    const options = positionOptions(this)
    if (this.element.hasAttribute(`data-${this.identifier}-side-value`)) return options
    if (this.#menubarOrientation === "vertical") return { ...options, side: "inline-end" }
    if (!this.isSubmenu) return options

    return { ...options, side: this.#parent.orientationValue === "horizontal" ? "bottom" : "inline-end" }
  }

  // The orientation of the menubar around this menu, or null: a submenu is not in a menubar.
  get #menubarOrientation() {
    if (this.isSubmenu) return null

    const menubar = this.element.parentElement?.closest(MENUBAR)
    if (!menubar) return null

    return menubar.getAttribute("data-phem-menubar-orientation-value") || "horizontal"
  }

  // A submenu opens on hover, unless its `openOnHover` value is false.
  get #openOnHover() {
    if (this.isSubmenu && !this.element.hasAttribute(`data-${this.identifier}-open-on-hover-value`)) return true

    return this.openOnHoverValue
  }

  // The arrow keys of a submenu (and of a menu in a vertical menubar): to its popup, and back to
  // the menu around it.
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
