import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { dismissable } from "./phem_dismiss.js"
import { Popup } from "./phem_popup.js"
import { PopupSize } from "./phem_popup_size.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, movesFocus, nextItem } from "./phem_roving.js"
import { hide, render, show } from "./phem_transition.js"
import { link } from "./phem_aria.js"

// The elements that the Tab key goes to in a content.
const TABBABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

// The styles of a content that leaves the viewport: it does not take space while it animates out.
const EXIT_STYLES = ["position", "inset-block-start", "inset-inline-start", "width"]

// The time (ms) after a hover open in which a click on the trigger keeps the menu open, as in
// Base UI. A later click closes it.
const PATIENT_CLICK = 500

// The parts of a menu in the page, for a menu nested in a content.
const CONTENT = '[data-phem-navigation-menu-target~="content"]'
const FOCUS_ITEM = '[data-phem-navigation-menu-target~="link"], [data-phem-navigation-menu-target~="trigger"]'
const ARROWS = ["ArrowDown", "ArrowUp", "ArrowRight", "ArrowLeft"]

// The causes of a close that do not give the focus back to the trigger, as in Base UI.
const KEEPS_FOCUS = new Set(["hover", "outside", "focusout"])

// The `phem-navigation-menu` controller: the links of a site, with a panel of links (a content)
// under some items. The content of the open item shows in one shared popup. The open item stays
// open after the morph of an event.
//
//     <nav data-controller="phem-navigation-menu">
//         <ul data-phem-navigation-menu-target="list">
//             <li data-phem-navigation-menu-target="item" data-value="overview">
//                 <button data-phem-navigation-menu-target="trigger">
//                     Overview <span data-phem-navigation-menu-target="icon">▾</span>
//                 </button>
//                 <div data-phem-navigation-menu-target="content" hidden>
//                     <a data-phem-navigation-menu-target="link" href="/start">Quick start</a>
//                 </div>
//             </li>
//             <li data-phem-navigation-menu-target="item">
//                 <a data-phem-navigation-menu-target="link" href="/blog" aria-current="page">Blog</a>
//             </li>
//         </ul>
//         <div data-phem-navigation-menu-target="positioner" hidden>
//             <div data-phem-navigation-menu-target="popup">
//                 <div data-phem-navigation-menu-target="arrow"></div>
//                 <div data-phem-navigation-menu-target="viewport"></div>
//             </div>
//         </div>
//     </nav>
//
// Each item has a trigger and a content, or a link. The value of an item is its `data-value`
// attribute, its key (`data-phem-key`), or its index. The `value` value is the state: the value of
// the open item, or empty when the menu is closed. The server render gives its first value, and a
// server render with a new `value` sets it.
//
// The pointer on a trigger opens its content after the `delay` value (50 ms), also when another
// item is open. The pointer out of the triggers and of the popup closes the menu after the
// `closeDelay` value (50 ms). A click on a trigger opens and closes its content. A click on a
// trigger that the pointer opened less than 500 ms before keeps it open; a later click closes it.
// A menu that a click or a key opened does not close when the pointer goes out. A click outside,
// Escape and the focus out of the menu close it. A link with
// `data-phem-navigation-menu-close-on-click="true"` closes the menu. Escape, the link and the
// `close` action give the focus back to the trigger when the focus is in the content, in the
// popup, on that trigger or on the page body, as in Base UI. A focus on another trigger stays. The
// pointer out, a click outside and the focus out do not move the focus.
//
// The arrow keys of the `orientation` value (`horizontal` by default) move the focus between the
// triggers and the links of the list, and skip a disabled trigger. They stop at the first and at
// the last one, as in Base UI: with the `loopFocus` value, the last one goes to the first one.
// Home and End do not move the focus. ArrowDown on a trigger (ArrowRight in a vertical menu,
// ArrowLeft in a right-to-left page), Enter and Space open the content, and the focus stays on
// the trigger. Tab on the trigger of the open item goes into the content, Shift+Tab on the first
// element of the content goes back to the trigger, and Tab on the last element goes to the next
// trigger: the menu stays open until the focus goes out of it. Tab on the last entry of the list
// goes to the next element of the page, and the menu closes. In a content, the four arrow keys
// move the focus between its links and the triggers and the links of a menu in it, from the last
// one to the first one. A field in the content keeps its arrow keys. A menu in the content of
// another menu (a nested menu) gives its arrow keys to that content, as in Base UI: they do not
// move the focus in its list, and they do not open its items.
//
// The contents stay in their items in the server render. With a `viewport` target, the content of
// the open item moves into the viewport while it is open, as in Base UI, and goes back to its item
// when it closes and before a morph: the morph sees the markup of the server render. With no
// viewport, the content shows in its item, and the CSS of the app places it. The `positioner`
// target (or the `popup` target, with no positioner) goes next to the trigger of the open item
// (phem_position.js, the `side`, `align`, `sideOffset`, `alignOffset`, `collisionPadding` and
// `arrowPadding` values), with the `--anchor-*`, `--available-*` and `--transform-origin`
// variables, `data-side` and `data-align`. The popup in a positioner also gets the `data-side` and
// the `data-align`. The popup gets `tabindex="-1"`: a click on its space gives it the focus, and
// Tab does not stop on it.
//
// The `popup` target gets the size of the open content in `--popup-width` and `--popup-height`, and
// the positioner in `--positioner-width` and `--positioner-height` (phem_popup_size.js). At rest,
// they are `auto`. When the open item changes, the popup goes from the old size to the new size
// in px for a CSS transition, and the variables go back to `auto` when the transition ends.
//
// When the open item changes, the old and the new contents get `data-activation-direction`
// (`left` or `right`, `up` or `down` in a vertical menu): the direction of the new item from the
// old one. A content that leaves the viewport gets `position: absolute` at the start of the
// viewport until its animation ends.
//
// A trigger gets `aria-expanded`, `aria-controls` (with an element reference when its content has
// no id), `data-popup-open` and `data-pressed` when its item is open, and `data-disabled`. An icon
// gets `data-popup-open`. The `nav`, the lists and the arrow get `data-open` while an item is
// open. The contents, the positioner, the popup and the backdrop get
// `data-open`, `data-closed` and the animations of phem_transition.js. A link with
// `aria-current="page"` or `data-active` from the server gets both. The controller sends
// `phem-navigation-menu:change` with `{ value }` when the user opens, changes or closes an item.
export default class PhemNavigationMenuController extends Controller {
  static targets = ["list", "item", "trigger", "icon", "content", "link", "backdrop", "positioner", "popup", "arrow", "viewport"]
  static values = {
    value: String,
    delay: { type: Number, default: 50 },
    closeDelay: { type: Number, default: 50 },
    orientation: { type: String, default: "horizontal" },
    loopFocus: Boolean,
    ...positionValues("bottom", "center"),
  }

  initialize() {
    // content => its place in the item: { parent, next }, while the content is in the viewport.
    this.homes = new Map()
    this.exiting = new Set()
    this.generation = 0
    this.openedBy = null
    this.openedAt = 0
    this.popup = new Popup({
      anchor: () => this.#triggerOf(this.openItem),
      popup: () => this.#surface,
      arrow: () => this.arrowTargets[0] ?? null,
      options: () => positionOptions(this),
      inside: () => [this.element],
      mirror: () => this.#layers.filter((el) => this.popupTargets.includes(el)),
    })
    this.size = new PopupSize({ popup: () => this.popupTargets[0] ?? null, positioner: () => this.positionerTargets[0] ?? null })
  }

  connect() {
    this.state = new ClientState(this, {
      value: "value",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("pointerover", this.onPointerOver, options)
    this.element.addEventListener("pointerout", this.onPointerOut, options)
    document.addEventListener("turbo:before-morph-element", this.onBeforeMorph, options)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.#cancelHover()
    this.#renderDismiss(false)
    this.popup.destroy()
    for (const content of [...this.homes.keys()]) this.#home(content)
    this.rendered = undefined
    this.openItem = null
  }

  close() {
    this.#close()
  }

  // A server render with a new `value`, or a change of the value by a script.
  valueValueChanged() {
    if (this.rendered === undefined || this.rendered === this.valueValue) return

    this.#apply()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const link = this.linkTargets.find((el) => el.contains(event.target))
    if (link) {
      if (link.getAttribute(`data-${this.identifier}-close-on-click`) === "true") this.#close("link")

      return
    }

    const trigger = this.#listTrigger(event.target)
    if (!trigger || isDisabled(trigger)) return

    const item = this.#itemOf(trigger)
    const keyboard = event.detail === 0

    if (item !== this.openItem) {
      this.#select(item, keyboard ? "keyboard" : "click")
    } else if (this.openedBy === "hover" && !keyboard && Date.now() - this.openedAt < PATIENT_CLICK) {
      this.openedBy = "click"
    } else this.#close("trigger")
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return

    const entries = this.#entries
    const entry = entries.find((el) => el.contains(event.target))
    if (entry) {
      this.#entryKeydown(event, entry, entries)

      return
    }

    const content = this.openItem && this.#contentOf(this.openItem)
    if (content?.contains(event.target) && this.#contentKeydown(event, content)) return

    // The Tab key in the content of the viewport: the content is after the list in the page.
    if (event.key !== "Tab" || !content?.contains(event.target) || !this.homes.has(content)) return

    const tabbables = this.#tabbables(content)
    if (event.shiftKey && event.target === tabbables[0]) {
      event.preventDefault()
      this.#triggerOf(this.openItem)?.focus()
    } else if (!event.shiftKey && event.target === tabbables.at(-1)) {
      const next = entries[entries.indexOf(this.#triggerOf(this.openItem)) + 1]
      if (!next) return

      // The menu stays open: it closes when the focus goes out of it.
      event.preventDefault()
      next.focus()
    }
  }

  // The four arrow keys move the focus between the links of a content, from the last one to the
  // first one. Returns true when the key moved the focus.
  #contentKeydown(event, content) {
    const step = { ArrowDown: 1, ArrowUp: -1, ArrowRight: this.#rtl ? -1 : 1, ArrowLeft: this.#rtl ? 1 : -1 }[event.key]
    if (!step) return false

    // The arrow keys of a field move its caret or change its value.
    if (event.target.closest?.("input, textarea, select, [contenteditable]")) return false

    const links = focusItems(content)
    if (links.length === 0) return false

    const at = links.findIndex((el) => el.contains(event.target))
    const next = at < 0 ? links.at(step === 1 ? 0 : -1) : links[(at + step + links.length) % links.length]
    event.preventDefault()
    next.focus()

    return true
  }

  // A key on a trigger or on a link of the list.
  #entryKeydown(event, entry, entries) {
    const trigger = this.triggerTargets.includes(entry) ? entry : null
    const item = trigger && this.#itemOf(trigger)

    // The arrow keys of a menu in a content move the focus in that content, as in Base UI: they do
    // not open an item.
    if (ARROWS.includes(event.key) && this.#nested) return

    if (trigger && !isDisabled(trigger) && event.key === this.#openKey) {
      event.preventDefault()
      if (item !== this.openItem) this.#select(item, "keyboard")

      return
    }

    // A trigger that is not a button: Enter and Space click it.
    if (trigger && (event.key === "Enter" || event.key === " ") && !trigger.matches("button, a[href], input")) {
      event.preventDefault()
      trigger.click()

      return
    }

    // Tab on the trigger of the open item goes into its content in the viewport.
    if (trigger && event.key === "Tab" && !event.shiftKey && item === this.openItem && this.homes.has(this.#contentOf(item))) {
      const first = this.#tabbables(this.#contentOf(item))[0]
      if (!first) return

      event.preventDefault()
      first.focus()

      return
    }

    // Tab on the last entry of the list goes out of the menu, and the menu closes, as in Base UI:
    // the content in the viewport is after the list in the page, so it is inert for this key. The
    // focus can go to the browser UI, with no `focusout` to another element.
    if (event.key === "Tab" && !event.shiftKey && this.openItem && this.homes.has(this.#contentOf(this.openItem)) && this.#isLast(entry, entries)) {
      this.#skip(this.#surface, () => {
        if (!this.element.contains(document.activeElement)) this.#close("focusout")
      })

      return
    }

    if (event.key === "Home" || event.key === "End" || !movesFocus(event.key, this.orientationValue)) return

    const next = nextItem(entries, entry, event.key, { orientation: this.orientationValue, loop: this.loopFocusValue, rtl: this.#rtl })
    if (!next) return

    event.preventDefault()
    next.focus()
  }

  // Whether Tab goes from `entry` out of the list: the entries after it are disabled.
  #isLast(entry, entries) {
    return entries.slice(entries.indexOf(entry) + 1).every((el) => el.disabled === true)
  }

  // The Tab key of the browser does not go into `el`: it is inert until the next task, which then
  // calls `after`.
  #skip(el, after) {
    if (!el || el.inert) return

    el.inert = true
    setTimeout(() => {
      el.inert = false
      after()
    }, 0)
  }

  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (!this.openItem || !(to instanceof Node) || this.element.contains(to)) return

    this.#close("focusout")
  }

  // The pointer on a trigger opens its item after the `delay` value.
  onPointerOver = (event) => {
    if (event.pointerType === "touch") return

    const trigger = this.#listTrigger(event.target)
    if (!trigger) {
      if (this.#inside(event.target)) clearTimeout(this.closing)

      return
    }

    clearTimeout(this.closing)
    clearTimeout(this.opening)
    const item = this.#itemOf(trigger)
    if (isDisabled(trigger) || item === this.openItem) return

    this.opening = setTimeout(() => this.#select(item, "hover"), this.delayValue)
  }

  // The pointer out of the triggers and of the popup closes a menu that the pointer opened.
  onPointerOut = (event) => {
    if (event.pointerType === "touch") return

    const to = event.relatedTarget
    if (to instanceof Node && this.#inside(to)) return

    clearTimeout(this.opening)
    if (!this.openItem || this.openedBy !== "hover") return

    clearTimeout(this.closing)
    this.closing = setTimeout(() => this.#close("hover"), this.closeDelayValue)
  }

  // Before a morph of this menu (or of an element around it): the contents go back to their items,
  // so the morph sees the markup of the server render. The open content goes back to the viewport
  // after the morph.
  onBeforeMorph = (event) => {
    const target = event.target
    if (this.homes.size === 0 || !(target instanceof Element)) return

    const viewport = this.viewportTargets[0]
    const around = target.contains(this.element) || (viewport && target.contains(viewport))
    if (!around && ![...this.homes.values()].some((home) => target.contains(home.parent))) return

    for (const content of [...this.homes.keys()]) this.#home(content)
    queueMicrotask(() => this.listening && !this.listening.signal.aborted && this.#render())
  }

  // Opens `item`. `by` is "hover", "click" or "keyboard".
  #select(item, by) {
    this.#cancelHover()
    const trigger = this.#triggerOf(item)
    if (!item || !trigger || isDisabled(trigger) || !this.#contentOf(item)) return

    this.openedBy = by
    this.openedAt = Date.now()
    this.valueValue = valueOf(item, this.itemTargets.indexOf(item))
    this.#apply()
    this.dispatch("change", { detail: { value: this.valueValue } })
  }

  // Closes the open item. `reason` is "escape", "outside", "hover", "focusout", "trigger", "link"
  // or "close".
  #close(reason = "close") {
    this.#cancelHover()
    if (!this.openItem) return

    const trigger = this.#triggerOf(this.openItem)
    const back = !KEEPS_FOCUS.has(reason) && this.#focusIn(this.openItem)

    this.valueValue = ""
    this.#apply()
    this.dispatch("change", { detail: { value: "" } })

    if (back) trigger?.focus()
  }

  // Whether the focus is in the content of `item`, in the popup, on its trigger or on the body: the
  // focus that goes back to the trigger at a close. A focus on another trigger stays.
  #focusIn(item) {
    const active = document.activeElement
    if (!active || active === document.body) return true

    return [this.#contentOf(item), this.#surface, this.#triggerOf(item)].some((el) => el?.contains(active))
  }

  // Renders a change of the open item, with the animations.
  #apply() {
    const previous = this.openItem ?? null
    const item = this.#activeItem
    this.rendered = this.valueValue
    this.openItem = item
    this.#renderParts()
    if (previous === item) return

    const token = ++this.generation

    if (item) {
      const switching = previous !== null
      const direction = switching ? this.#direction(previous, item) : null
      const old = switching ? this.#contentOf(previous) : null
      const content = this.#contentOf(item)

      // The size before the change: the old content takes no space after #exit().
      const from = switching ? this.size.measure() : null

      if (old) {
        setDirection(old, direction)
        this.#exit(old)
      }

      setDirection(content, direction)
      this.#enter(content, { animate: true })

      // Another item: the size of the popup goes from the old size to the new one, and the
      // positioner gets the new size at once. Then the popup stays open and goes to the new
      // trigger, with the animation of its position.
      if (switching) {
        this.size.change(from)
        this.#surface?.removeAttribute("data-instant")
        this.popup.reanchor()
      } else {
        // The size of the content, and the popup in the positioner shows first: the first
        // placement of the arrow uses its size, and the arrow does not move after it.
        this.size.rest()
        for (const part of this.#layers) show(part)
        this.popup.show()

        // The first open places the surface at once: only the move from one trigger to another
        // animates its position. show() removes `data-instant`, so it goes on after it.
        this.#surface?.setAttribute("data-instant", "")
      }

      this.#renderDismiss(true)

      return
    }

    this.#renderDismiss(false)
    const old = this.#contentOf(previous)
    const done = () => {
      if (token !== this.generation) return

      this.#unexit(old)
      render(old, false)
      this.#home(old)
    }

    if (!this.#surface) {
      hide(old, { onEnd: () => token === this.generation && this.#home(old) })

      return
    }

    for (const part of this.#layers) hide(part)
    const hidden = this.popup.hide()

    // No animation: the surface is hidden now.
    if (this.#surface.hidden) done()
    else hidden.then(done)
  }

  // Renders the state with no animation: the first render, and after the morph.
  #render() {
    const item = this.#activeItem
    this.rendered = this.valueValue
    this.openItem = item
    this.generation++
    this.#renderParts()

    const active = item ? this.#contentOf(item) : null
    for (const content of this.contentTargets) {
      if (content === active) {
        this.#enter(content, { animate: false })
        continue
      }

      this.#unexit(content)
      render(content, false)
      this.#home(content)
    }

    this.popup.render(item !== null)
    for (const part of this.#layers) render(part, item !== null)
    if (item) {
      this.size.rest()
      this.popup.reposition()
    }

    this.#renderDismiss(item !== null)
  }

  // The attributes that are not the state stay when the server render gives them.
  #renderParts() {
    for (const item of this.itemTargets) {
      const trigger = this.#triggerOf(item)
      const content = this.#contentOf(item)
      const open = item === this.openItem

      if (trigger) {
        trigger.setAttribute("aria-expanded", String(open))
        trigger.toggleAttribute("data-popup-open", open)
        trigger.toggleAttribute("data-pressed", open)
        trigger.toggleAttribute("data-disabled", isDisabled(trigger))
        if (content) link(trigger, "aria-controls", "ariaControlsElements", content)
      }

      for (const icon of this.iconTargets) if (item.contains(icon)) icon.toggleAttribute("data-popup-open", open)
    }

    for (const el of this.linkTargets) {
      const active = el.getAttribute("aria-current") === "page" || el.hasAttribute("data-active")
      el.toggleAttribute("data-active", active)
      if (active && !el.hasAttribute("aria-current")) el.setAttribute("aria-current", "page")
    }

    // The open state of the menu on the nav, the lists and the arrow.
    const open = this.openItem !== null
    for (const el of [this.element, ...this.listTargets, ...this.arrowTargets]) el.toggleAttribute("data-open", open)

    // The popup gets the focus of a click on its space, and is not a stop of the Tab key.
    const popup = this.popupTargets[0]
    if (popup && !popup.hasAttribute("tabindex")) popup.setAttribute("tabindex", "-1")
  }

  // Shows a content: in the viewport, when the menu has one.
  #enter(content, { animate }) {
    this.#unexit(content)
    this.#move(content)

    if (animate) show(content)
    else render(content, true)
  }

  // Hides the content of the item that was open. In the viewport, it takes no space while it
  // animates out, then goes back to its item.
  #exit(content) {
    if (!this.homes.has(content)) {
      hide(content)

      return
    }

    content.style.setProperty("width", `${content.offsetWidth}px`)
    content.style.setProperty("position", "absolute")
    content.style.setProperty("inset-block-start", "0")
    content.style.setProperty("inset-inline-start", "0")
    this.exiting.add(content)

    hide(content, {
      onEnd: () => {
        this.#unexit(content)
        this.#home(content)
      },
    })
  }

  #unexit(content) {
    if (!this.exiting.delete(content)) return

    for (const name of EXIT_STYLES) content.style.removeProperty(name)
    if (content.getAttribute("style") === "") content.removeAttribute("style")
  }

  // Moves a content into the viewport, and keeps its place in its item.
  #move(content) {
    const viewport = this.viewportTargets[0]
    if (!viewport || viewport.contains(content)) return

    this.homes.set(content, { parent: content.parentNode, next: content.nextSibling })
    moveNode(viewport, content, null)
  }

  // Moves a content back to its place in its item.
  #home(content) {
    const home = this.homes.get(content)
    if (!home) return

    this.homes.delete(content)
    if (!home.parent?.isConnected) return

    const next = home.next?.parentNode === home.parent ? home.next : null
    moveNode(home.parent, content, next)
  }

  // An open menu closes on Escape and on a click outside (phem_dismiss.js).
  #renderDismiss(open) {
    if (open && !this.undismiss) {
      this.undismiss = dismissable({ inside: () => [this.element], dismiss: (reason) => this.#close(reason) })
    } else if (!open && this.undismiss) {
      this.undismiss()
      this.undismiss = null
    }
  }

  // The attributes of the state: `aria-expanded`, `data-popup-open` and `data-pressed` on a
  // trigger and on an icon, `data-open` on the nav, the lists and the arrow, the open state, the
  // position and the placement of the popup parts (phem_popup.js), the size of an open popup, and
  // the open state, the direction and the exit styles of a content.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (el === this.element || this.listTargets.includes(el)) return name === "data-open"
    if (this.arrowTargets.includes(el) && name === "data-open") return true
    if (el === this.popupTargets[0]) {
      if (this.#layers.includes(el) && OPEN_ATTRIBUTES.has(name)) return true

      return this.openItem !== null && name === "style"
    }

    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-popup-open" || name === "data-pressed"
    if (this.iconTargets.includes(el)) return name === "data-popup-open"
    if (this.contentTargets.includes(el)) return OPEN_ATTRIBUTES.has(name) || name === "data-activation-direction" || (name === "style" && this.exiting.has(el))
    if (this.#layers.includes(el)) return OPEN_ATTRIBUTES.has(name)

    return false
  }

  #tabbables(content) {
    if (!content) return []

    return [...content.querySelectorAll(TABBABLE)].filter((el) => !el.closest("[hidden]"))
  }

  #cancelHover() {
    clearTimeout(this.opening)
    clearTimeout(this.closing)
  }

  // The element that goes next to the trigger: the positioner, or the popup.
  get #surface() {
    return this.positionerTargets[0] ?? this.popupTargets[0] ?? null
  }

  // The parts that open and close with the surface: the popup in a positioner, and the backdrop.
  get #layers() {
    const layers = [...this.backdropTargets]
    if (this.hasPositionerTarget && this.hasPopupTarget) layers.push(this.popupTarget)

    return layers
  }

  // The item of the `value` value, when it has a trigger and a content.
  get #activeItem() {
    if (this.valueValue === "") return null

    return this.itemTargets.find((item, index) => valueOf(item, index) === this.valueValue && this.#triggerOf(item) && this.#contentOf(item)) ?? null
  }

  // The triggers and the links of the list, in the order of the page: not the links of a content.
  get #entries() {
    const links = this.linkTargets.filter((el) => !this.contentTargets.some((content) => content.contains(el)))

    return [...this.triggerTargets, ...links].sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
  }

  get #openKey() {
    if (this.orientationValue !== "vertical") return "ArrowDown"

    return this.#rtl ? "ArrowLeft" : "ArrowRight"
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }

  // Whether the menu is in the content of another menu.
  get #nested() {
    return this.element.parentElement?.closest(CONTENT) != null
  }

  // The direction of the new item from the old one.
  #direction(from, to) {
    const forward = this.itemTargets.indexOf(to) > this.itemTargets.indexOf(from)
    if (this.orientationValue === "vertical") return forward ? "down" : "up"

    return forward !== this.#rtl ? "right" : "left"
  }

  #triggerOf(item) {
    if (!item) return null

    return this.triggerTargets.find((el) => item.contains(el)) ?? null
  }

  // The content of an item: in the item, or in the viewport.
  #contentOf(item) {
    if (!item) return null

    for (const [content, home] of this.homes) if (item.contains(home.parent)) return content

    return this.contentTargets.find((el) => item.contains(el)) ?? null
  }

  #itemOf(el) {
    return this.itemTargets.find((item) => item.contains(el)) ?? null
  }

  // A trigger of the list that has `el`.
  #listTrigger(el) {
    return this.triggerTargets.find((trigger) => trigger.contains(el)) ?? null
  }

  // An element of the menu that keeps it open under the pointer: a trigger, a content, the popup.
  #inside(el) {
    const parts = [...this.triggerTargets, ...this.contentTargets, ...this.positionerTargets, ...this.popupTargets]

    return parts.some((part) => part.contains(el))
  }
}

// The links of a content, and the triggers and the links of the menus in it, as in Base UI: not
// the elements of a content of a nested menu.
function focusItems(content) {
  const items = [...content.querySelectorAll(FOCUS_ITEM)]

  return items.filter((el) => el.parentElement.closest(CONTENT) === content && !isDisabled(el))
}

// The value of an item: its `data-value`, its key, or its index.
function valueOf(el, index) {
  return el.getAttribute("data-value") ?? el.getAttribute("data-phem-key") ?? String(index)
}

function setDirection(content, direction) {
  if (!content) return

  if (direction) content.setAttribute("data-activation-direction", direction)
  else content.removeAttribute("data-activation-direction")
}

// Moves a node and keeps its state (the focus) when the browser can: moveBefore().
function moveNode(parent, node, next) {
  if (typeof parent.moveBefore === "function") {
    try {
      parent.moveBefore(node, next)

      return
    } catch {
      // The browser cannot move it with its state here: insertBefore() below.
    }
  }

  const focused = node.contains(document.activeElement) ? document.activeElement : null
  parent.insertBefore(node, next)
  focused?.focus({ preventScroll: true })
}
