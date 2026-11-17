import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { Hover } from "./phem_hover.js"
import { modal } from "./phem_modal.js"
import { Popup } from "./phem_popup.js"
import { PopupSize } from "./phem_popup_size.js"
import { PopupViewport } from "./phem_popup_viewport.js"
import { ReleaseClick } from "./phem_release.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, movesFocus, nextItem, Typeahead } from "./phem_roving.js"
import { controlWhileOpen, link } from "./phem_aria.js"
import { Triggers, activationDirection } from "./phem_triggers.js"

// The controllers of a menu: a submenu is a menu in the popup of one of them.
const IDENTIFIERS = ["phem-menu", "phem-context-menu"]
const MENU = IDENTIFIERS.map((name) => `[data-controller~="${name}"]`).join(", ")

// A menubar around a menu: it moves the focus between the menus (phem_menubar_controller.js).
const MENUBAR = '[data-controller~="phem-menubar"]'

// The time (ms) of the pointer on another item before it gets the highlight, while a submenu is
// open and the pointer did not enter it yet: the pointer can cross an item on its way to the
// submenu. When the submenu closes before this time, the item gets the highlight at once.
const SUBMENU_GRACE = 150

// The time (ms) after the press on the trigger before a release on an item clicks it, as in Base
// UI: a quick click on the trigger does not run the item that opens under the pointer.
const RELEASE_DELAY = 200

// An item that the keys and the pointer skip: an item with `disabled`, which cannot get the
// focus, and an item that the filter hides. An item with `aria-disabled="true"` gets the focus, as
// in Base UI.
const skipped = (el) => el.matches(":disabled") || el.hidden

// The text of an item for the filter: no case, no accents, no punctuation.
const normalize = (text) =>
  text
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .toLowerCase()
    .replace(/[\p{P}\p{S}]/gu, "")
    .replace(/\s+/g, " ")
    .trim()

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
// A press of the primary mouse button on the trigger opens and closes the menu, as in Base UI. A
// move to an item and a release 200 ms after the press click the item. A touch, Enter and Space use
// the click. ArrowDown on the trigger opens the menu on the first item. A click with the keyboard
// (Enter or Space) also opens it on the first item. In the menu, the arrow keys (of the
// `orientation` value) move the focus, and go from the last item to the first item with the
// `loopFocus` value (the default). Home and End go to the first and to the last item, and a letter
// goes to the next item that starts with it. The keys and the pointer skip an item with `disabled`:
// it cannot get the focus. An item with `aria-disabled="true"` gets the focus and the highlight,
// but a click on it does nothing. The first focus of a menu that opens skips the two kinds. With
// the `highlightItemOnHover` value (the default), a move of the pointer on an item gives it the
// focus: an item that comes under a pointer that does not move (the menu opens under it) does not
// get it. When the pointer goes out of the item, and not to another item, the item loses the
// highlight: the focus goes to the popup.
//
// A click on an item runs its own handler (`onClick`), and closes the menu, unless the item has
// `data-phem-menu-close-on-click="false"`. Escape closes the menu and gives the focus back to the
// trigger. Tab, a press outside (each button) and the focus out of the menu close it. A press
// outside gives the focus to the trigger, unless the press gives the focus to another element.
// Shift+Tab closes the menu and gives the focus to the trigger. With the `modal` value,
// the page does not scroll and the elements out of the menu get no pointer events while it is
// open (phem_modal.js). A menu that the pointer opened (`openOnHover`) does not lock the page.
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
// 150 ms, so the pointer can cross an item on its way to the submenu. When the pointer is in the
// submenu, its trigger loses the highlight (the focus stays on it), the pointer in empty space
// keeps the submenu open, and the pointer on another item closes it at once. Shift+Tab closes the
// submenu and gives the focus back to its trigger. A click on an item of a submenu closes all the
// menus. A disabled trigger (`aria-disabled="true"`) does not open it.
//
// A `phem-menu` in a `phem-menubar` is a menu of the menubar (phem_menubar_controller.js). In a
// vertical menubar, ArrowRight (ArrowLeft in a right-to-left page) opens it in place of ArrowDown
// and ArrowUp, and its popup goes on the `inline-end` side of the trigger. Shift+Tab in its popup
// gives the focus to its trigger, and the menu stays open.
//
// After a change with the keyboard, the popup gets the cause in `data-instant`, and a
// `[data-instant] { transition: none }` rule stops its animation, as in Base UI. The value is
// `click` after Enter or Space on the trigger or on an item, `dismiss` after Escape, and `group`
// for a menu of a menubar that an arrow key opens or that the focus closes. A change with the
// pointer removes the attribute.
//
// The menu sends `phem-menu:open` when it opens and `phem-menu:close` when it closes. The `open`
// action and the open() method open it with the focus on the popup. A script can give open() the
// item that gets the focus: `open("first")` or `open("last")`.
//
// The trigger gets `aria-haspopup="menu"`, `aria-expanded`, `data-popup-open` and `data-pressed`
// (not the trigger of a submenu), and `aria-controls` while the menu is open.
// The popup gets `role="menu"`, the items `role="menuitem"` (an item with `aria-checked` gets
// `data-checked` or `data-unchecked`), the groups
// `role="group"` with `aria-labelledby` (its `groupLabel` target), and the separators
// `role="separator"`. A popup or a group label with no `id` gets no id: the trigger and the group
// point to it with an element reference (`ariaControlsElements`, `ariaLabelledByElements`). The
// item with the focus gets `data-highlighted` and `tabindex="0"` (the other items `tabindex="-1"`),
// and a disabled item gets `data-disabled`. The popup of a submenu gets `data-nested`. The popup is
// positioned as a `phem-popover` (phem_position.js).
//
// One menu can have several triggers: `trigger` targets, and elements anywhere in the page with
// `data-phem-menu-for` and the `id` of the controller element (phem_triggers.js). A submenu has
// only its own trigger. The popup goes next to the trigger that opened it: the active trigger. A
// press on another trigger (or the pointer, with `openOnHover`) while the menu is open makes it the
// active trigger: the popup moves (a CSS transition of `top` and `left`), its `viewport` part shows
// the `data-phem-menu-payload` of the trigger (phem_popup_viewport.js), and it gets the size of its
// content in `--popup-width` and `--popup-height` (phem_popup_size.js). Only the active trigger
// gets `aria-expanded="true"`. The `triggerId` value is the `id` of the active trigger: a server
// render with a new value moves the open menu. The `open` action takes a `triggerId` param.
//
// A menu with an `input` target filters its items, as the FilterProvider of Base UI. The text of
// the input hides the items (and the triggers of the submenus) of the `list` target whose text
// does not contain it, with no case, no accents and no punctuation. The `data-label` attribute of
// an item replaces its text. A `group` target with no item to show gets `hidden`, and the `empty`
// target shows only when no item matches. A click on the `clear` target empties the input. With
// the `filter` value false, the controller hides nothing: the server renders the items of the
// query (an `onInput` handler on the input). The query resets when the menu closes.
//
// An open with a click or the keyboard gives the focus to the input. An open on hover or with a
// touch gives it to the popup, so the keyboard of the device does not show. The arrow keys move
// the highlight while the input keeps the focus (`aria-activedescendant`), and Enter clicks the
// item with the highlight. The input has the highlight (`data-highlighted`) when no item has it.
// With the `autoHighlight` value `input`, the first match gets the highlight while the query is
// not empty, and with `always` also when it is empty. The popup gets `role="dialog"`, the list
// `role="menu"`, and the input `role="searchbox"`.
//
// `phem-context-menu` (phem_context_menu_controller.js) extends this class: it changes the
// methods of the trigger (`anchor`, `triggerPressed()`, `triggerClicked()`, `triggerKeydown()`,
// `renderTrigger()` and `restoreFocus()`), the position (`placementOptions()`) and the Tab key
// (`tabCloses`).
export default class PhemMenuController extends Controller {
  static targets = ["trigger", "popup", "arrow", "item", "group", "groupLabel", "separator", "viewport", "input", "list", "empty", "clear"]
  static values = {
    open: Boolean,
    triggerId: String,
    filter: { type: Boolean, default: true },
    autoHighlight: String,
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
    this.triggers = new Triggers(this)
    this.popup = new Popup({
      anchor: () => this.anchor,
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => this.placementOptions(),
      inside: () => [this.element, ...this.popupTargets, ...this.triggers.all],
      dismiss: (reason) => this.#dismiss(reason),
      event: () => "pointerdown",
    })
    this.hover = new Hover({
      enabled: () => this.#openOnHover && !(this.#trigger && isDisabled(this.#trigger)),
      triggers: () => this.triggers.all,
      popups: () => this.popupTargets,
      isOpen: () => this.openValue,
      open: () => this.#open(null, { focus: !this.isSubmenu, hover: true, trigger: this.hoverTrigger }),
      close: () => this.#close({ focus: false }),
      delay: () => this.delayValue,
      closeDelay: () => this.closeDelayValue,
      // A submenu that the pointer entered stays open in empty space (see onPointerMove).
      stayAfterLanding: () => this.isSubmenu,
    })
    // Only a popup with a viewport changes its size between triggers.
    this.size = new PopupSize({ popup: () => (this.hasPopupTarget && this.hasViewportTarget ? this.popupTarget : null) })
    this.viewport = new PopupViewport({
      viewport: () => this.viewportTargets[0] ?? null,
      payload: (trigger) => trigger?.getAttribute(`data-${this.identifier}-payload`) ?? null,
    })
    this.release = new ReleaseClick({
      itemOf: (target) => {
        const item = this.#itemOf(target)

        return item && !this.#isSubmenuTrigger(item) && !isDisabled(item) ? item : null
      },
      isOpen: () => this.openValue,
    })
  }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        this.viewport.capture()
        if (this.rendered) this.viewport.render(this.#trigger)
        this.#settleRadios()
        this.#renderParts()
        this.#filter()
        this.popup.reposition()
      },
    })
    this.state.connect()

    // The events of the element, and of the detached triggers.
    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    for (const target of [this.element, document]) {
      const own = target === this.element
      const listen = (type, handler) => target.addEventListener(type, (event) => (own || (!this.element.contains(event.target) && this.triggers.detached(event.target))) && handler(event), options)
      listen("pointerdown", this.onTriggerPointerDown)
      listen("click", this.onClick)
      listen("keydown", this.onKeydown)
      listen("focusout", this.onFocusOut)
      listen("pointerover", this.onPointerOver)
      listen("pointerout", this.onPointerOut)
    }
    this.element.addEventListener("focusin", this.onFocusIn, options)
    this.element.addEventListener("pointermove", this.onPointerMove, options)
    this.element.addEventListener("input", this.onInput, options)
    this.element.addEventListener("turbo:before-morph-element", this.onBeforeMorph, options)
    document.addEventListener("turbo:before-cache", this.onBeforeCache, options)

    if (this.openValue) this.triggers.activate(this.triggers.byId(this.triggerIdValue))
    this.viewport.capture()
    if (this.openValue) this.viewport.render(this.#trigger)
    this.rendered = this.openValue
    this.popup.render(this.openValue)
    this.#renderModal()
    this.#renderParts()
    this.#filter()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.release.disarm()
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
  // goes to the popup, and the menu opens next to the trigger of its `triggerId` param.
  open(to = null) {
    if (typeof to === "string") this.#open(to)
    else this.#open(null, { trigger: this.triggers.byId(to?.params?.triggerId) })
  }

  close() {
    this.#close({ focus: false })
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    if (this.openValue) this.#activate(this.triggers.byId(this.triggerIdValue))
    this.#apply()
  }

  // A server render with a new `triggerId` value: the open menu moves to that trigger.
  triggerIdValueChanged() {
    if (!this.rendered) return

    this.#switch(this.triggers.byId(this.triggerIdValue))
  }

  inputTargetConnected() {
    this.#renderParts()
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

  // Whether Tab closes the menu. A submenu reads the value of its root menu.
  get tabCloses() {
    return true
  }

  // A press of the primary mouse button on a trigger opens and closes the menu, as in Base UI. The
  // click after the press does nothing. A touch uses the click. A submenu opens on hover and on
  // click, and a menu of a menubar on click (phem_menubar_controller.js keeps the menu that the
  // pointer opened).
  triggerPressed(event) {
    this.pressed = false
    if (event.pointerType === "touch" || event.button !== 0 || event.isPrimary === false) return
    if (this.isSubmenu || this.#menubarOrientation !== null) return

    this.pressed = true
    const trigger = this.#triggerOf(event.target)

    // A press on another trigger moves the open menu to it.
    if (this.openValue && trigger && trigger !== this.#trigger) {
      this.#switch(trigger)
      this.#focusPopupAfterPress()

      return
    }

    if (this.openValue) {
      this.#close({ focus: false })

      return
    }

    this.#open(null, { focus: false, trigger })
    this.#focusPopupAfterPress()

    this.release.arm({ delay: RELEASE_DELAY, accepts: (e) => e.button === 0 })
  }

  // The mousedown after the pointerdown focuses the trigger: the popup (or the input of the
  // filter) gets the focus after it.
  #focusPopupAfterPress() {
    requestAnimationFrame(() => {
      const popup = this.popupTargets[0]
      if (!this.openValue || !popup || popup.contains(document.activeElement)) return

      if (this.#filterInput) this.#focusInput()
      else popup.focus({ preventScroll: true })
    })
  }

  // A click on a trigger: it opens and closes the menu. The click after a press of the mouse does
  // nothing: the press opened or closed the menu (see triggerPressed()). A click of the keyboard
  // (Enter or Space) has no pointer: the focus goes to the first item. A click opens a submenu,
  // and does not close it: the pointer on its trigger opened it.
  triggerClicked(event) {
    if (event.detail > 0 && this.pressed) {
      this.pressed = false

      return
    }

    const instant = event.detail === 0 ? "click" : null
    const trigger = this.#triggerOf(event.target)
    const touch = event.pointerType === "touch"

    if (this.isSubmenu) this.#open(event.detail === 0 ? "first" : null, { focus: event.detail === 0, instant })
    else if (this.openValue && trigger && trigger !== this.#trigger) this.#switch(trigger)
    else if (this.openValue) this.#close({ focus: false, instant })
    else this.#open(event.detail === 0 ? "first" : null, { instant, touch, trigger })
  }

  // A key on a trigger: ArrowDown opens the menu (ArrowUp does nothing, as in Base UI). Enter,
  // Space and the arrow to the popup (ArrowRight, or ArrowLeft in a right-to-left page) open a
  // submenu. In a vertical menubar, the arrow to the popup opens the menu: ArrowDown and ArrowUp
  // go to the other menus of the menubar.
  triggerKeydown(event) {
    let keys = { ArrowDown: "first" }
    if (this.isSubmenu) keys = { Enter: "first", " ": "first", [this.#openKey]: "first" }
    else if (this.#menubarOrientation === "vertical") keys = { [this.#openKey]: "first" }

    const to = keys[event.key]
    if (!to) return

    // Enter and Space on the trigger of a submenu are a click of the keyboard.
    const click = this.isSubmenu && (event.key === "Enter" || event.key === " ")

    event.preventDefault()
    this.#open(to, { instant: click ? "click" : this.#group, trigger: this.#triggerOf(event.target) })
  }

  // The attributes of a trigger that are not the state stay when the server render gives them.
  // `open` is the state of this trigger: only the active trigger is open.
  renderTrigger(trigger, popup, open = this.openValue) {
    if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "menu")
    controlWhileOpen(trigger, popup, open)
    trigger.setAttribute("aria-expanded", String(open))
    trigger.toggleAttribute("data-pressed", open && !this.isSubmenu)

    if (!this.isSubmenu) return

    if (!trigger.hasAttribute("role")) trigger.setAttribute("role", "menuitem")
    trigger.tabIndex = this.#parent.openValue && trigger.hasAttribute("data-highlighted") ? 0 : -1
    trigger.toggleAttribute("data-disabled", isDisabled(trigger))
  }

  // The focus after a close of the menu with the focus in it.
  restoreFocus() {
    this.#trigger?.focus()
  }

  // The placement of the popup (phem_position.js).
  placementOptions() {
    const options = positionOptions(this)
    if (this.element.hasAttribute(`data-${this.identifier}-side-value`)) return options
    if (this.#menubarOrientation === "vertical") return { ...options, side: "inline-end" }
    if (!this.isSubmenu) return options

    return { ...options, side: this.#parent.orientationValue === "horizontal" ? "bottom" : "inline-end" }
  }

  // A press of the pointer on a trigger.
  onTriggerPointerDown = (event) => {
    const trigger = this.#triggerOf(event.target)
    if (!trigger || isDisabled(trigger) || event.defaultPrevented) return

    this.triggerPressed(event)
  }

  onClick = (event) => {
    if (event.defaultPrevented || this.#inSubmenu(event.target)) return

    if (this.clearTargets.some((el) => el.contains(event.target))) {
      this.#clearFilter()

      return
    }

    const item = this.#itemOf(event.target)
    if (item) {
      // A click on a disabled item does nothing: its handler (`onClick`) does not run.
      if (isDisabled(item)) {
        event.preventDefault()
        event.stopPropagation()

        return
      }

      if (this.#isSubmenuTrigger(item)) return

      this.#check(item)
      if (item.getAttribute(`data-${this.identifier}-close-on-click`) !== "false") this.#closeAll(event.detail === 0 ? "click" : null)

      return
    }

    const trigger = this.#triggerOf(event.target)
    if (!trigger || isDisabled(trigger)) return

    this.triggerClicked(event)
  }

  onKeydown = (event) => {
    this.pointing = false
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return

    if (this.#triggerOf(event.target)) {
      if (!isDisabled(this.#triggerOf(event.target))) this.triggerKeydown(event)

      return
    }

    if (!this.popupTargets.some((el) => el.contains(event.target)) || this.#inSubmenu(event.target)) return

    if (event.key === "Tab") {
      this.#tab(event)

      return
    }

    if (this.#filterInput && event.target === this.#filterInput) {
      this.#inputKeydown(event)

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
      ? nextItem(items, item, event.key, { orientation: this.orientationValue, loop: this.loopFocusValue, rtl: this.#rtl, skip: skipped })
      : this.typeahead.find(items, item, event, undefined, skipped)
    if (!next) return

    event.preventDefault()
    next.focus()
  }

  onFocusIn = (event) => {
    // The input of the filter keeps the highlight of the arrow keys.
    if (this.#inSubmenu(event.target) || event.target === this.#filterInput) return

    this.#highlight(this.#itemOf(event.target) ?? null)
  }

  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (!(to instanceof Node) || !this.#itemOf(to)) this.#highlight(null)

    if (!this.openValue || !(to instanceof Node)) return
    if (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to)) || this.#triggerOf(to)) return

    this.#close({ focus: false, instant: this.#group })
  }

  // With `openOnHover`, the pointer on another trigger moves the open menu to it at once.
  onPointerOver = (event) => {
    if (event.pointerType === "touch") return

    const trigger = this.#triggerOf(event.target)
    if (trigger) this.hoverTrigger = trigger
    if (this.#openOnHover && this.openValue && trigger && trigger !== this.#trigger) this.#switch(trigger)
    this.hover.over(event)

    // The pointer reached the submenu: the item that it crossed does not get the highlight, and
    // the trigger of the submenu loses it, as in Base UI. The focus stays on the trigger.
    if (this.#inSubmenu(event.target)) {
      this.#cancelHighlight()
      this.#highlight(null)
    }
  }

  // A move of the pointer highlights the item under it. The browser also sends `pointerover` when
  // an item comes under a pointer that does not move (the popup opens under it): that item does
  // not take the highlight of the keyboard.
  onPointerMove = (event) => {
    if (event.pointerType === "touch") return

    this.pointing = true
    if (this.#inSubmenu(event.target)) return

    this.#closeLandedSubmenus(event.target)
    if (!this.highlightItemOnHoverValue) return

    // The moves on the item with the focus, or on the item that waits for its grace time: no
    // lookup of the items, and the time does not start again.
    const last = this.pointerItem
    if (last?.contains(event.target) && (document.activeElement === last || this.highlightingItem === last)) {
      if (document.activeElement === last) this.#highlight(last)

      return
    }

    const item = this.#itemOf(event.target)
    this.pointerItem = item
    if (!item || skipped(item) || item === this.highlightingItem) return

    // The pointer came back to the item with the focus: the item that it crossed does not get it.
    this.#cancelHighlight()
    if (document.activeElement === item) {
      this.#highlight(item)

      return
    }

    this.highlightingItem = item
    const highlight = () => {
      this.#cancelHighlight()
      if (!item.isConnected || !this.openValue) return

      // The input of the filter keeps the focus.
      if (this.#filterInput) this.#highlight(item)
      else item.focus({ preventScroll: true })
    }
    const open = this.#submenus.some((menu) => menu.openValue && menu.#trigger !== item)
    if (open) {
      this.pendingHighlight = highlight
      this.highlighting = setTimeout(highlight, SUBMENU_GRACE)
    } else {
      highlight()
    }
  }

  onPointerOut = (event) => {
    this.hover.out(event)
    this.#unhighlight(event)
  }

  // The pointer goes out of an item, and not to another item: the item loses the highlight, and
  // the focus goes to the popup. The keyboard keeps its highlight: the last input must be the
  // pointer. The trigger of an open submenu keeps the focus while the pointer is in the submenu.
  #unhighlight(event) {
    if (event.pointerType === "touch" || !this.pointing || !this.highlightItemOnHoverValue || !this.openValue) return
    if (this.#inSubmenu(event.target)) return

    const item = this.#itemOf(event.target)
    const to = event.relatedTarget
    if (!item || (to instanceof Node && (this.#itemOf(to) || this.#inSubmenu(to)))) return

    // The trigger of an open submenu keeps the focus: the focus out of it closes the submenu, and
    // the pointer is on its way to the popup of the submenu (it loses the highlight there, see
    // onPointerOver()).
    if (this.#submenus.some((menu) => menu.openValue && menu.#trigger === item)) return

    this.pointerItem = null
    this.#cancelHighlight()

    if (this.#filterInput) {
      this.#highlight(null)

      return
    }

    const popup = this.popupTargets[0]
    if (popup?.contains(document.activeElement)) popup.focus({ preventScroll: true })
  }

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
    this.pendingHighlight = null
  }

  // A submenu closed: the item that waits for its grace time gets the highlight at once, as in
  // Base UI. The trigger of the submenu does not keep it.
  #highlightNow() {
    const highlight = this.pendingHighlight
    if (!highlight || this.#submenus.some((menu) => menu.openValue)) return

    highlight()
  }

  // The pointer entered a submenu, and came to another item of this menu: the submenu closes at
  // once, as in Base UI. Also with no highlight on hover (`highlightItemOnHover` false).
  #closeLandedSubmenus(target) {
    const landed = this.#submenus.filter((submenu) => submenu.openValue && submenu.hover.landed)
    if (landed.length === 0) return

    const item = this.#itemOf(target)
    if (!item) return

    for (const submenu of landed) if (submenu.#trigger !== item) submenu.#close({ focus: false })
  }

  // The item with the highlight gets `data-highlighted` and the tab stop (`tabindex="0"`), as in
  // Base UI. null removes the highlight from all the items.
  #highlight(item) {
    for (const el of this.#items) {
      el.toggleAttribute("data-highlighted", el === item)
      el.tabIndex = el === item ? 0 : -1
    }

    const input = this.#filterInput
    if (!input) return

    input.toggleAttribute("data-highlighted", item === null && document.activeElement === input)
    if (item?.id) input.setAttribute("aria-activedescendant", item.id)
    else if ("ariaActiveDescendantElement" in input) input.ariaActiveDescendantElement = item
    else input.removeAttribute("aria-activedescendant")
  }

  // The keys in the input of the filter: the arrow keys move the highlight, and Enter clicks the
  // item with the highlight. The other keys write the query.
  #inputKeydown(event) {
    const items = this.#items.filter((el) => !el.hidden)
    const current = items.find((el) => el.hasAttribute("data-highlighted")) ?? null

    if (event.key === "Enter") {
      if (!current) return

      event.preventDefault()
      current.click()

      return
    }

    if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return

    event.preventDefault()
    const next = current ? nextItem(items, current, event.key, { orientation: "vertical", loop: this.loopFocusValue, skip: skipped }) : nextItem(items, null, event.key === "ArrowDown" ? "Home" : "End", { skip: skipped })
    if (next) {
      this.#highlight(next)
      next.scrollIntoView?.({ block: "nearest" })
    }
  }

  onInput = (event) => {
    if (event.target === this.#filterInput) this.#filter()
  }

  // The input of the filter of this menu: not the input of a submenu.
  get #filterInput() {
    return this.inputTargets[0] ?? null
  }

  #focusInput() {
    this.#filterInput?.focus({ preventScroll: true })
    this.#filter()
  }

  #clearFilter() {
    const input = this.#filterInput
    if (!input) return

    input.value = ""
    input.dispatchEvent(new Event("input", { bubbles: true }))
    this.#focusInput()
  }

  // Hides the items that do not match the query, the groups with no item to show, and the empty
  // part when an item matches. The item with the highlight keeps it when it still shows.
  #filter() {
    const input = this.#filterInput
    if (!input) return

    const query = normalize(input.value)
    const items = this.#items.filter((el) => !this.hasListTarget || this.listTarget.contains(el))

    if (this.filterValue) {
      for (const item of items) item.hidden = query !== "" && !normalize(item.getAttribute("data-label") ?? item.textContent).includes(query)
      for (const group of this.groupTargets) group.hidden = query !== "" && !items.some((el) => group.contains(el) && !el.hidden)
    }

    const shown = items.filter((el) => !el.hidden)
    for (const empty of this.emptyTargets) empty.hidden = shown.length > 0

    const current = shown.find((el) => el.hasAttribute("data-highlighted")) ?? null
    const auto = this.autoHighlightValue === "always" || (this.autoHighlightValue === "input" && query !== "")
    this.#highlight(current ?? (auto ? (nextItem(shown, null, "Home", { skip: skipped }) ?? null) : null))
  }

  // Escape closes the menu with the focus on the trigger. A press outside closes it at the press,
  // with each button: the focus in the popup goes to the trigger at once. The mousedown on an
  // element that cannot get the focus then moves the focus to the body: the frame after it gives
  // the focus back to the trigger. A press on an element that gets the focus keeps it.
  #dismiss(reason) {
    if (reason === "escape") {
      this.#close({ focus: true, instant: "dismiss" })

      return
    }

    this.#close({ focus: false })

    requestAnimationFrame(() => {
      if (this.openValue) return

      const active = document.activeElement
      if (active === null || active === document.body || this.popupTargets.some((el) => el.contains(active))) this.restoreFocus()
    })
  }

  // Tab closes the menu, unless the root menu keeps it (`phem-context-menu`: Tab does nothing).
  // Shift+Tab gives the focus to the trigger: a menu of a menubar stays open, another menu (also a
  // submenu) closes.
  #tab(event) {
    if (!this.#root.tabCloses) {
      event.preventDefault()

      return
    }

    if (!event.shiftKey) {
      this.#close({ focus: false })

      return
    }

    event.preventDefault()

    if (this.#menubarOrientation !== null) this.#trigger?.focus()
    else this.#close({ focus: true })
  }

  // Opens the menu. `to` is the item that gets the focus: "first", "last", or none (the popup).
  // A submenu that the pointer opens keeps the focus on its trigger (`focus: false`).
  #open(to, { focus = true, hover = false, instant = null, touch = false, trigger = null } = {}) {
    this.hover.cancel()

    if (this.openValue && trigger && trigger !== this.#trigger) this.#switch(trigger)

    if (!this.openValue) {
      this.#activate(trigger)
      this.cause = instant
      // A menu that the pointer opened does not lock the page (see #renderModal()).
      this.hovered = hover
      this.openValue = true
      this.#apply()
    }

    if (!focus) return

    // A menu with a filter gives the focus to its input, unless a touch or the pointer opened it.
    if (this.#filterInput && !touch && !hover) {
      this.#focusInput()
      if (to === "first" && !this.#items.some((el) => el.hasAttribute("data-highlighted"))) this.#highlight(nextItem(this.#items, null, "Home", { skip: skipped }))

      return
    }

    const items = this.#items
    const item = to === "first" ? nextItem(items, null, "Home") : to === "last" ? nextItem(items, null, "End") : null
    if (item) {
      item.focus()

      return
    }

    const popup = this.popupTargets[0]
    popup?.focus({ preventScroll: true })
  }

  #close({ focus, instant = null }) {
    this.hover.reset()
    this.#cancelHighlight()
    if (!this.openValue) return

    for (const submenu of this.#submenus) submenu.#close({ focus: false, instant: instant === "group" ? null : instant })

    const inside = this.popupTargets.some((el) => el.contains(document.activeElement))
    this.cause = instant
    this.openValue = false
    this.#apply()
    this.typeahead.reset()
    if (focus || inside) this.restoreFocus()
    this.#parent?.#highlightNow()
  }

  // A click on an item closes the menu and the menus around it.
  #closeAll(instant = null) {
    this.#root.#close({ focus: true, instant })
  }

  // The root menu: the menu that is not a submenu.
  get #root() {
    let root = this
    while (root.#parent) root = root.#parent

    return root
  }

  #apply() {
    this.rendered = this.openValue
    this.#renderParts()

    // The popup gets the cause in `data-instant`, and the CSS decides the animation: `click` (Enter
    // or Space on the trigger or on an item), `dismiss` (Escape) and `group` (a menubar that goes
    // from one menu to another sets `instant`, and a key or the focus opens or closes its menu).
    const instant = this.instant ?? this.cause ?? false
    this.cause = null
    if (this.openValue) {
      this.viewport.render(this.#trigger)
      this.size.rest()
      this.popup.show({ instant })
    } else {
      // The query resets after the close animation.
      Promise.resolve(this.popup.hide({ instant })).then(() => {
        if (this.openValue || !this.#filterInput) return

        this.#filterInput.value = ""
        this.#filter()
      })
    }

    // Also for a close by a server render: the next open is not an open by the pointer.
    if (!this.openValue) this.hovered = false

    this.#renderModal()
    this.dispatch(this.openValue ? "open" : "close")
  }

  // Makes `trigger` the active trigger of a menu that opens.
  #activate(trigger) {
    this.triggers.activate(trigger)
    const id = this.#trigger?.id ?? ""
    if (!this.isSubmenu && this.triggerIdValue !== id) this.triggerIdValue = id
  }

  // The open menu goes to another trigger: its position, its size and its content change with
  // their animations.
  #switch(trigger) {
    const from = this.#trigger
    if (!trigger || !this.openValue || trigger === from) return

    const size = this.size.measure()
    this.#activate(trigger)
    this.popupTargets[0]?.removeAttribute("data-instant")
    this.viewport.change(from, trigger, activationDirection(from, trigger))
    this.size.change(size)
    this.popup.reanchor()
    this.#renderParts()
    if (this.#filterInput) {
      this.#filterInput.value = ""
      this.#filter()
    }
  }

  // A modal menu locks the page while it is open. A submenu is in the popup of its menu. A menu
  // that the pointer opened (`openOnHover`) does not lock the page: the lock removes the pointer
  // from the trigger, and the menu would close.
  #renderModal() {
    const popup = this.popupTargets[0]
    const locked = this.openValue && this.modalValue && !this.hovered && !this.isSubmenu && popup !== undefined

    if (locked && !this.unlock) this.unlock = modal(popup)
    else if (!locked && this.unlock) {
      this.unlock()
      this.unlock = null
    }
  }

  // The attributes that are not the state stay when the server render gives them.
  #renderParts() {
    const popup = this.popupTargets[0]

    // With a filter, the popup is a dialog with the input and the list of the menu.
    const input = this.#filterInput
    const menu = input && this.hasListTarget ? this.listTarget : popup

    if (popup) {
      const role = popup.getAttribute("role")
      if (role === null || role === "menu" || role === "dialog") popup.setAttribute("role", input ? "dialog" : "menu")
      if (!popup.hasAttribute("tabindex")) popup.tabIndex = -1
      popup.toggleAttribute("data-nested", this.isSubmenu)
    }

    if (menu) {
      if (menu !== popup && !menu.hasAttribute("role")) menu.setAttribute("role", "menu")
      if (this.orientationValue === "horizontal") menu.setAttribute("aria-orientation", "horizontal")
    }

    if (input) {
      if (!input.hasAttribute("role")) input.setAttribute("role", "searchbox")
      if (menu !== popup) link(input, "aria-controls", "ariaControlsElements", menu)
    }

    const active = this.#trigger
    for (const trigger of this.triggers.all) {
      const open = this.openValue && trigger === active
      this.renderTrigger(trigger, popup, open)
      trigger.toggleAttribute("data-popup-open", open)
    }

    for (const item of this.itemTargets) {
      if (!item.hasAttribute("role")) item.setAttribute("role", "menuitem")
      item.tabIndex = this.openValue && item.hasAttribute("data-highlighted") ? 0 : -1
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
  // `data-popup-open` and `data-pressed` on a trigger, and `data-highlighted` and `tabindex` on an
  // item (also on the trigger of a submenu). A checkbox item and a radio item with no handler keep
  // `aria-checked`, `data-checked` and `data-unchecked`, unless the server render gives a new
  // `aria-checked`.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (this.triggers.all.includes(el) && (name === "aria-expanded" || name === "data-popup-open" || name === "data-pressed")) return true
    if (name === "data-activation-direction" && this.viewportTargets.includes(el)) return true
    // The filter hides the items, the groups and the empty part (see #filter()).
    if (name === "hidden" && this.#filterInput && (this.#items.includes(el) || this.groupTargets.includes(el) || this.emptyTargets.includes(el))) return true
    if (this.#filterInput === el && (name === "data-highlighted" || name === "aria-activedescendant")) return true
    if (!this.#items.includes(el)) return false
    if (name === "data-highlighted" || name === "tabindex") return true

    const check = this.checks.get(el)

    if (check === undefined || check.serverChanged || el.hasAttribute("data-phem-on-click")) return false

    return name === "aria-checked" || name === "data-checked" || name === "data-unchecked"
  }

  // `group` for a menu of a menubar: a key on its trigger and the focus out of it change it with
  // no animation.
  get #group() {
    return this.#menubarOrientation === null ? null : "group"
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

  // The active trigger: the trigger that opened the menu, or else the first trigger.
  get #trigger() {
    return this.triggers.active
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }

  #triggerOf(el) {
    return this.triggers.of(el) ?? undefined
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
