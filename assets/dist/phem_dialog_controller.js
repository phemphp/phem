import { Controller } from "@hotwired/stimulus"
import { onBackdropTap } from "./phem_backdrop.js"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { dismissable } from "./phem_dismiss.js"
import { SWIPE_ATTRIBUTES, snapPointSize, swipeOpenable, swipeable, unswipe } from "./phem_swipe.js"
import { hide, render, show } from "./phem_transition.js"
import { controlWhileOpen, link } from "./phem_aria.js"
import { edgeTabbable, nextTabbable } from "./phem_focus.js"
import { scrollLock } from "./phem_modal.js"
import { PopupViewport } from "./phem_popup_viewport.js"
import { isTouchClick } from "./phem_press.js"
import { Triggers } from "./phem_triggers.js"

// The attributes of a dialog in a dialog.
const NESTED_ATTRIBUTES = new Set(["data-nested", "data-nested-dialog-open", "data-nested-drawer-open", "data-nested-drawer-swiping", "style"])

// The popups that got `tabindex="-1"` for the focus of a touch open.
const focused = new WeakSet()

// The attributes of a drawer.
const DRAWER_ATTRIBUTES = new Set(["data-expanded", "data-swipe-direction"])

const AXES = { up: "y", down: "y", left: "x", right: "x" }

// The `phem-dialog` controller: opens a native `<dialog>`, as a modal with `showModal()` by
// default. The dialog stays open after the morph of an event.
//
//     <div data-controller="phem-dialog">
//         <button data-phem-dialog-target="trigger">Edit</button>
//         <dialog data-phem-dialog-target="popup">
//             <h2 data-phem-dialog-target="title" id="edit-title">Edit</h2>
//             <button onClick={{ save }} data-phem-dialog-target="close">Save</button>
//             <button data-phem-dialog-target="close">Cancel</button>
//         </dialog>
//     </div>
//
// A click on a trigger opens the dialog, and a click on a `close` target closes it. The `open`
// and `close` actions do the same from another element. The browser gives the focus to the first
// element of the dialog that can have it. After an open with a touch, the focus goes to the popup
// (it gets `tabindex="-1"`), so the keyboard of the device does not show. A drawer always gives
// the focus to its popup, unless an element in it has `autofocus`.
//
// A dialog can have several triggers: `trigger` targets, and elements anywhere in the page with
// `data-phem-dialog-for` and the `id` of the controller element (phem_triggers.js), for example an
// item of a menu. The trigger that opened the dialog is the active trigger: it gets
// `aria-expanded="true"`, and the `triggerId` value gets its `id`. The `payload` part shows the
// `data-phem-dialog-payload` of the active trigger (phem_popup_viewport.js).
//
// With the `confirmClose` value (the `id` of another `phem-dialog` element, an alert dialog in
// the popup), a request to close the dialog (Escape, a tap on the backdrop, a `close` part, the
// `close` action) opens that dialog in place, when a field of the popup has a value that the user
// changed. A click on a `[data-phem-dialog-discard]` element in that dialog closes the two
// dialogs and resets the fields. The `finalFocus` value is a CSS selector: the element that gets
// the focus after the close, in place of the element that had it before the open.
//
// The `open` value is the state: the server render gives its first value, and a server render
// with a new `open` value sets it. When the `dismiss` value is true (the default), Escape and a
// tap on the backdrop (see phem_backdrop.js) close it. With the `disablePointerDismissal` value,
// only Escape closes it (an alert dialog). A dialog that closes in another way (a
// `<form method="dialog">`) also sets the state.
//
// The `modal` value: `true` (the default) opens the dialog with `showModal()`: the page is inert
// and does not scroll (phem_modal.js), and the dialog is in the top layer. `false` opens it with `show()`: the page stays active, and
// Escape and a press outside close it (see phem_dismiss.js). `trap-focus` is as `false`, but Tab
// stays in the dialog, as with `true`: Tab goes from the last field to the first one.
//
// The `viewport` target is the `<dialog>` when the `popup` is an element in it (Base UI
// Dialog.Viewport): the viewport fills the screen and places the popup, and a press on the
// viewport outside the popup is a tap on the backdrop. A `backdrop` target (an element outside
// the dialog) opens and closes with the dialog.
//
// The popup, the viewport and the backdrop get `data-open` or `data-closed`, with the animations
// of phem_transition.js. The `<dialog>` gets `aria-labelledby` (the `title` target) and
// `aria-describedby` (the `description` target), with their ids or with element references. A
// close waits for the animations of `[data-ending-style]` of the popup before `close()`. A
// trigger gets `aria-haspopup="dialog"`, `aria-expanded`, `data-popup-open`, and `aria-controls`
// while the dialog is open. A dialog in an
// open dialog gets `data-nested`, and the dialog around it gets `data-nested-dialog-open` and the
// `--nested-dialogs` CSS variable (the number of open dialogs in it), for a stack of dialogs. A
// drawer around it also gets `data-nested-drawer-open` and `--nested-drawers`, and
// `data-nested-drawer-swiping` during the swipe of a drawer in it. The popup of a drawer gets
// `--drawer-frontmost-height`: the height of the last open drawer in it, or else its own height.
//
// With the `swipeDirection` value (`up`, `down`, `left` or `right`), the dialog is a drawer: a
// swipe in that direction closes it (see phem_swipe.js). The popup gets `data-swipe-direction`.
// A mouse press in a `content` target (the text of the drawer) selects its text, and does not
// start a swipe.
// The `snapPoints` value (a list: a fraction of the height of the viewport, px, or a string with
// `px` or `rem`) gives the heights where a vertical drawer stops. The `snapPoint` value is the
// state: the snap point of the drawer (the first one by default). The popup gets
// `--drawer-snap-point-offset` (the distance from its full height), `--drawer-height`, and
// `data-expanded` at its full height. With `snapToSequentialPoints`, a fast swipe does not skip a
// snap point. A new snap point dispatches `phem-dialog:snap` with the `snapPoint` in the detail.
// A `swipeArea` target (an element at the edge of the screen) opens the drawer with a swipe away
// from the edge: the popup follows the pointer (see swipeOpenable() in phem_swipe.js). During a
// swipe, the controller dispatches `phem-dialog:swipe` with the `progress` in the detail (0 when
// open, 1 when closed), for an indent of the page (phem_drawer_provider_controller.js).
//
// With the `virtualKeyboard` value, the popup of an open drawer gets `--drawer-keyboard-inset`
// while the keyboard of the device covers the bottom of the screen: the height of the keyboard
// (window.visualViewport). The field with the focus scrolls into view when the keyboard opens.
//
// The dialog dispatches `phem-dialog:open` when it opens and `phem-dialog:close` when it closes.
export default class PhemDialogController extends Controller {
  static targets = ["trigger", "popup", "viewport", "backdrop", "close", "title", "description", "content", "payload", "swipeArea"]
  static values = {
    open: Boolean,
    triggerId: String,
    confirmClose: String,
    finalFocus: String,
    dismiss: { type: Boolean, default: true },
    disablePointerDismissal: Boolean,
    modal: { type: String, default: "true" },
    swipeDirection: String,
    snapPoints: Array,
    snapPoint: String,
    snapToSequentialPoints: Boolean,
    virtualKeyboard: Boolean,
  }

  // dialog => the AbortController of its listeners.
  #listening = new Map()
  // swipe area => the AbortController of its listeners.
  #areas = new Map()
  // dialog => the function that removes its dismiss layer (a dialog that is not a modal).
  #layers = new Map()

  initialize() {
    this.triggers = new Triggers(this)
    this.payload = new PopupViewport({
      viewport: () => this.payloadTargets[0] ?? null,
      payload: (trigger) => trigger?.getAttribute("data-phem-dialog-payload") ?? null,
    })
  }

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        // A dialog that the morph moved is out of the top layer: show it again.
        for (const el of this.#dialogs) this.#render(el)
        for (const el of this.backdropTargets) render(el, this.openValue)
        this.payload.capture()
        if (this.openValue) this.payload.render(this.triggers.active)
        this.#renderAria()
        this.#alignKeyboard()
      },
    })
    this.state.connect()

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("keydown", this.onKeydown)
    // A click on a detached trigger.
    document.addEventListener("click", this.onDocumentClick)
    window.addEventListener("resize", this.onResize)
    this.rendered = this.openValue
    if (this.openValue) this.triggers.activate(this.triggers.byId(this.triggerIdValue))
    this.payload.capture()
    if (this.openValue) this.payload.render(this.triggers.active)
    for (const el of this.backdropTargets) render(el, this.openValue)
    this.#renderAria()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("keydown", this.onKeydown)
    document.removeEventListener("click", this.onDocumentClick)
    window.removeEventListener("resize", this.onResize)
    this.#watchKeyboard(false)
    for (const listening of this.#areas.values()) listening.abort()
    this.#areas.clear()
    for (const off of this.#layers.values()) off()
    this.#layers.clear()
    this.unlockScroll?.()
    this.unlockScroll = null
    this.rendered = undefined
  }

  // Opens the dialog, for the trigger of the `triggerId` param, or else the active trigger.
  open(event) {
    const trigger = this.triggers.byId(event?.params?.triggerId)
    if (trigger) this.#activate(trigger)
    this.#set(true)
  }

  // A request to close the dialog: it can open the dialog of the `confirmClose` value.
  close() {
    if (this.#confirm()) return

    this.#set(false)
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    if (this.openValue) this.#activate(this.triggers.byId(this.triggerIdValue))
    this.#apply()
  }

  payloadTargetConnected() {
    this.payload.capture()
  }

  snapPointValueChanged() {
    if (this.rendered !== undefined) this.#renderDrawer()
  }

  // A dialog that the morph adds gets the state.
  popupTargetConnected(el) {
    this.#connectDialog(this.#dialogOf(el))
  }

  viewportTargetConnected(el) {
    this.#connectDialog(el)
  }

  popupTargetDisconnected(el) {
    this.#disconnectDialog(el)
  }

  viewportTargetDisconnected(el) {
    this.#disconnectDialog(el)
  }

  swipeAreaTargetConnected(el) {
    this.#areas.get(el)?.abort()
    const listening = new AbortController()
    this.#areas.set(el, listening)

    swipeOpenable(el, {
      direction: () => this.swipeDirectionValue,
      isOpen: () => this.openValue,
      open: () => this.#set(true),
      close: () => this.#set(false),
      popup: () => (this.#dialogs[0] ? this.#popupOf(this.#dialogs[0]) : null),
      progress: (progress) => this.#renderProgress(progress),
    }, listening.signal)
  }

  swipeAreaTargetDisconnected(el) {
    this.#areas.get(el)?.abort()
    this.#areas.delete(el)
  }

  backdropTargetConnected(el) {
    if (this.rendered !== undefined) render(el, this.openValue)
  }

  titleTargetConnected() {
    this.#renderAria()
  }

  descriptionTargetConnected() {
    this.#renderAria()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const trigger = this.triggers.of(event.target)
    if (this.closeTargets.some((el) => el.contains(event.target))) this.close()
    else if (this.#discards(event.target)) this.#discard()
    else if (trigger) this.#openFrom(trigger, event)
  }

  onDocumentClick = (event) => {
    if (event.defaultPrevented || this.element.contains(event.target) || !this.triggers.detached(event.target)) return

    this.#openFrom(this.triggers.of(event.target), event)
  }

  // A modal and `trap-focus`: Tab goes from the last field of the dialog to the first one, and
  // back, as in Base UI. A modal `<dialog>` makes the page inert, but Tab still goes from its last
  // field to the browser. The controller lets the browser move the focus, and only stops a Tab
  // that goes out of the dialog: from the last field, from the checked radio of a last radio
  // group, from an element with `tabindex="-1"` after the last field (a menu item), or Shift+Tab
  // from the popup itself. With no field, Tab does nothing.
  onKeydown = (event) => {
    if (event.key !== "Tab" || event.defaultPrevented || this.modalValue === "false" || !this.openValue) return

    // The dialog of the focus: a dialog in this dialog has its own controller.
    const dialog = event.target.closest?.("dialog")
    if (!this.#dialogs.includes(dialog)) return

    const backward = event.shiftKey
    if (nextTabbable(dialog, event.target, { backward })) return

    event.preventDefault()
    edgeTabbable(dialog, { backward })?.focus()
  }

  // The snap points of a fraction of the viewport, and the height of the popup, change with the
  // size of the viewport (a rotation, the keyboard of a phone).
  onResize = () => {
    if (this.openValue && this.snapPointsValue.length > 0) this.#renderDrawer()
  }

  // Escape on a modal: the controller closes the dialog itself, after its animations.
  onCancel = (event) => {
    event.preventDefault()
    if (this.dismissValue) this.close()
  }

  // The `close` event comes in a later task: a dialog that is open again (shown again, or opened
  // by an action) keeps the state.
  onClose = (event) => {
    const dialog = event.target
    if (dialog.open) return

    for (const part of this.#parts(dialog)) render(part, false, { hidden: false })
    this.#unlayer(dialog)
    this.#renderNesting(dialog, false)
    if (this.openValue) this.#set(false)
    if (this.finalFocusValue) document.querySelector(this.finalFocusValue)?.focus({ preventScroll: true })
  }

  #openFrom(trigger, event) {
    this.touch = isTouchClick(event)
    this.#activate(trigger)
    this.#set(true)
    this.touch = false
  }

  // The trigger of a dialog that opens: the active trigger, its id and its payload.
  #activate(trigger) {
    if (this.openValue && this.rendered) return

    this.triggers.activate(trigger)
    const id = this.triggers.active?.id ?? ""
    if (this.triggerIdValue !== id) this.triggerIdValue = id
  }

  // A field of the popup has a value that the user changed: the dialog of the `confirmClose` value
  // opens, and this dialog stays open.
  #confirm() {
    const confirm = this.#confirmDialog
    if (!confirm || !this.openValue || !this.#changedFields().length) return false

    this.application.getControllerForElementAndIdentifier(confirm, this.identifier)?.open()

    return true
  }

  // A `[data-phem-dialog-discard]` element of the dialog of the `confirmClose` value.
  #discards(el) {
    const confirm = this.#confirmDialog

    return Boolean(confirm && el.closest?.("[data-phem-dialog-discard]") && confirm.contains(el))
  }

  // The changes go: the fields get their first values, and the dialog closes.
  #discard() {
    for (const field of this.#changedFields()) {
      if (field.type === "checkbox" || field.type === "radio") field.checked = field.defaultChecked
      else if (field instanceof HTMLSelectElement) for (const option of field.options) option.selected = option.defaultSelected
      else field.value = field.defaultValue
    }

    this.#set(false)
  }

  // The fields of the popup (not of a dialog in it) with a value that the user changed.
  #changedFields() {
    const fields = this.#dialogs.flatMap((dialog) => [...dialog.querySelectorAll("input, textarea, select")].filter((field) => field.closest("dialog") === dialog))

    return fields.filter((field) => {
      if (field.type === "checkbox" || field.type === "radio") return field.checked !== field.defaultChecked
      if (field instanceof HTMLSelectElement) return [...field.options].some((option) => option.selected !== option.defaultSelected)

      return field.value !== field.defaultValue
    })
  }

  get #confirmDialog() {
    return this.confirmCloseValue ? document.getElementById(this.confirmCloseValue) : null
  }

  #connectDialog(el) {
    if (!(el instanceof HTMLDialogElement) || !this.#dialogs.includes(el)) return

    this.#listening.get(el)?.abort()
    const listening = new AbortController()
    this.#listening.set(el, listening)
    const options = { signal: listening.signal }
    el.addEventListener("cancel", this.onCancel, options)
    el.addEventListener("close", this.onClose, options)

    const popup = this.#popupOf(el)
    const viewport = popup === el ? {} : {
      outside: (event) => !this.#popupOf(el).contains(event.target),
      backdrop: (event) => event.target === el,
    }
    onBackdropTap(el, () => this.#pointerDismiss(), listening.signal, viewport)

    swipeable(popup, {
      direction: () => this.swipeDirectionValue,
      open: () => el.open,
      close: () => {
        this.close()
        // The close confirmation keeps the drawer open: it goes back to its place.
        if (this.openValue) {
          unswipe(popup, { strength: true })
          this.#renderProgress(0)
        }
      },
      snap: this.#snap(popup),
      content: () => this.contentTargets,
      swiping: (active) => this.#renderNestedSwiping(el, active),
      progress: (progress) => this.#renderProgress(progress),
    }, listening.signal)

    this.#render(el)
    this.#renderAria()
  }

  #disconnectDialog(el) {
    const listening = this.#listening.get(el)
    if (!listening) return

    listening.abort()
    this.#listening.delete(el)
    this.#unlayer(el)
    this.#renderNestedSwiping(el, false)
    this.#renderNesting(el, false)
  }

  #pointerDismiss() {
    if (this.dismissValue && !this.disablePointerDismissalValue) this.close()
  }

  #set(open) {
    if (this.openValue === open && this.rendered === open) return

    this.openValue = open
    if (open) this.payload.render(this.triggers.active)
    this.#apply()
  }

  // The state with its animations.
  #apply() {
    this.rendered = this.openValue
    this.#renderAria()

    // The lock of the scroll starts with the open, and ends at the end of the close animation:
    // the scroll bar does not come back while the dialog still shows.
    if (this.openValue) this.#renderScrollLock()
    let closing = 0

    for (const el of this.backdropTargets) {
      if (this.openValue) show(el)
      else hide(el)
    }

    for (const el of this.#dialogs) {
      const popup = this.#popupOf(el)

      if (this.openValue) {
        unswipe(popup)
        this.#renderProgress(0)
        // The dialog opens after its parts have `data-starting-style`, so the open animation of
        // the popup and of the `::backdrop` runs. The dialog is the first part, and the last to show.
        const [dialog, ...parts] = this.#parts(el)
        for (const part of parts) show(part, { hidden: false })
        show(dialog, { hidden: false, reveal: () => this.#showDialog(el) })
        this.#renderNesting(el, true)
        this.#renderDrawer()

        continue
      }

      if (!el.open) continue

      for (const part of this.#parts(el)) if (part !== popup) hide(part, { hidden: false })

      // The drawers around a drawer that closes get their own height back when the close starts,
      // so they change with its animation.
      for (let drawer = el.parentElement?.closest("dialog"); drawer; drawer = drawer.parentElement?.closest("dialog")) this.#renderFrontmost(drawer, el)

      closing++
      hide(popup, {
        hidden: false,
        onEnd: () => {
          if (this.openValue) return

          unswipe(popup)
          this.#renderProgress(0)
          if (el.open) el.close()
          this.#renderScrollLock()
        },
      })
    }

    if (!this.openValue && closing === 0) this.#renderScrollLock()
    this.#watchKeyboard(this.openValue)
    this.dispatch(this.openValue ? "open" : "close")
  }

  // The swipe of a drawer: the viewport and the backdrop get `--drawer-swipe-progress`, for the
  // `::backdrop` of the viewport and the opacity of the backdrop. 0 removes it.
  #renderProgress(progress) {
    const parts = [...this.viewportTargets, ...this.backdropTargets]
    for (const el of parts) {
      if (progress > 0) el.style.setProperty("--drawer-swipe-progress", String(progress))
      else el.style.removeProperty("--drawer-swipe-progress")
      if (el.getAttribute("style") === "") el.removeAttribute("style")
    }

    this.dispatch("swipe", { detail: { progress } })
  }

  // The keyboard of the device while a drawer with the `virtualKeyboard` value is open.
  #watchKeyboard(on) {
    const viewport = window.visualViewport
    on = on && this.virtualKeyboardValue && viewport !== undefined && viewport !== null

    if (on && !this.keyboard) {
      this.keyboard = new AbortController()
      const options = { signal: this.keyboard.signal }
      viewport.addEventListener("resize", this.#alignKeyboard, options)
      viewport.addEventListener("scroll", this.#alignKeyboard, options)
      this.element.addEventListener("focusin", this.#alignKeyboard, options)
      this.#alignKeyboard()
    } else if (!on && this.keyboard) {
      this.keyboard.abort()
      this.keyboard = null
      this.keyboardInset = 0
      for (const el of this.#dialogs) this.#popupOf(el).style.removeProperty("--drawer-keyboard-inset")
    }
  }

  // The popup gets the height of the keyboard in `--drawer-keyboard-inset`, and the field with the
  // focus scrolls into view.
  #alignKeyboard = () => {
    const viewport = window.visualViewport
    if (!this.keyboard || !viewport) return

    const inset = Math.max(0, Math.round(window.innerHeight - viewport.height - viewport.offsetTop))
    this.keyboardInset = inset
    for (const el of this.#dialogs) {
      const popup = this.#popupOf(el)
      if (inset > 0) popup.style.setProperty("--drawer-keyboard-inset", `${inset}px`)
      else popup.style.removeProperty("--drawer-keyboard-inset")
    }

    const field = document.activeElement
    if (inset > 0 && field?.matches?.("input, textarea, select, [contenteditable]") && this.#dialogs.some((el) => el.contains(field))) {
      field.scrollIntoView?.({ block: "nearest" })
    }
  }

  // The state with no animation. An open dialog in another mode (the server render gave `open`,
  // or the morph moved it) closes first.
  #render(el) {
    for (const part of this.#parts(el)) render(part, this.openValue, { hidden: false })
    this.#renderScrollLock()

    if (!this.openValue) {
      if (el.open) el.close()

      return
    }

    this.#showDialog(el)
    this.#renderNesting(el, true)
    this.#renderDrawer()
  }

  #showDialog(el) {
    if (!el.isConnected) return

    if (this.#modal) {
      if (el.matches(":modal")) return

      if (el.open) el.close()
      el.showModal()
      this.#focusPopup(el)

      return
    }

    if (!el.open) {
      el.show()
      this.#focusPopup(el)
    }

    if (this.#layers.has(el)) return

    this.#layers.set(el, dismissable({
      inside: () => [el, ...this.triggers.all],
      dismiss: (reason) => {
        if (!this.dismissValue || (reason === "outside" && this.disablePointerDismissalValue)) return false

        this.close()
      },
    }))
  }

  // After an open with a touch, the focus goes to the popup: a field with the focus shows the
  // keyboard of the device. A drawer always gives the focus to its popup, as in Base UI, so no
  // button of the drawer shows a focus ring. An `[autofocus]` element in the drawer keeps the
  // focus that the browser gives it.
  #focusPopup(el) {
    const drawer = Boolean(AXES[this.swipeDirectionValue]) && !el.querySelector("[autofocus]")
    if (!this.touch && !drawer) return

    // The first focus of the browser scrolled the dialog (a viewport) and the content of the popup
    // to its element. A drawer gives the focus to its popup: its content starts at the top.
    if (drawer) {
      for (let node = document.activeElement; node && el.contains(node); node = node.parentElement) {
        node.scrollTop = 0
        node.scrollLeft = 0
      }
    }

    const popup = this.#popupOf(el)
    if (!popup.hasAttribute("tabindex")) {
      popup.tabIndex = -1
      focused.add(popup)
    }

    popup.focus({ preventScroll: true })
  }

  #unlayer(el) {
    this.#layers.get(el)?.()
    this.#layers.delete(el)
  }

  get #modal() {
    return this.modalValue !== "false" && this.modalValue !== "trap-focus"
  }

  // An open modal dialog locks the scroll of the page.
  #renderScrollLock() {
    const locked = this.openValue && this.#modal && this.#dialogs.length > 0

    if (locked && !this.unlockScroll) this.unlockScroll = scrollLock()
    else if (!locked && this.unlockScroll) {
      this.unlockScroll()
      this.unlockScroll = null
    }
  }

  // A dialog in a dialog: the dialog around it shows how many dialogs are open in it.
  #renderNesting(el, open) {
    const parent = el.parentElement?.closest("dialog")
    for (const part of this.#parts(el)) part.toggleAttribute("data-nested", open && Boolean(parent))
    if (!parent) return

    const nested = [...parent.querySelectorAll("dialog[open]")].filter((d) => d !== el || open).length
    const parts = popupsOf(parent)
    const drawer = parts.some((part) => part.hasAttribute("data-swipe-direction"))

    for (const part of parts) {
      part.toggleAttribute("data-nested-dialog-open", nested > 0)
      part.style.setProperty("--nested-dialogs", String(nested))
      if (!drawer) continue

      part.toggleAttribute("data-nested-drawer-open", nested > 0)
      part.style.setProperty("--nested-drawers", String(nested))
    }

    for (let drawer = parent; drawer; drawer = drawer.parentElement?.closest("dialog")) this.#renderFrontmost(drawer, open ? null : el)
  }

  // The popup of a drawer gets the height of the last open drawer in it, or else its own height.
  // `closing` is a dialog that does not count: its `open` attribute goes after its animation.
  #renderFrontmost(dialog, closing = null) {
    const parts = popupsOf(dialog)
    if (!parts.some((part) => part.hasAttribute("data-swipe-direction"))) return

    const open = [...dialog.querySelectorAll("dialog[open]")].filter((el) => el !== closing && !closing?.contains(el))
    const frontmost = popupsOf(open.at(-1) ?? dialog).at(-1)
    const height = frontmost.offsetHeight
    for (const part of parts) {
      if (height > 0) part.style.setProperty("--drawer-frontmost-height", `${height}px`)
      else part.style.removeProperty("--drawer-frontmost-height")
    }
  }

  // The drawers around a drawer show its swipe.
  #renderNestedSwiping(el, active) {
    for (let dialog = el.parentElement?.closest("dialog"); dialog; dialog = dialog.parentElement?.closest("dialog")) {
      const parts = popupsOf(dialog)
      if (!parts.some((part) => part.hasAttribute("data-swipe-direction"))) continue

      for (const part of parts) part.toggleAttribute("data-nested-drawer-swiping", active)
    }
  }

  // A drawer: its direction, and the snap point of the `snapPoint` value.
  #renderDrawer() {
    for (const el of this.#dialogs) {
      const popup = this.#popupOf(el)
      const direction = this.swipeDirectionValue
      if (AXES[direction]) popup.setAttribute("data-swipe-direction", direction)
      else popup.removeAttribute("data-swipe-direction")

      for (let drawer = el; drawer; drawer = drawer.parentElement?.closest("dialog")) this.#renderFrontmost(drawer)

      const snap = this.#snap(popup)
      if (snap.offsets().length === 0) {
        popup.removeAttribute("data-expanded")
        popup.style.removeProperty("--drawer-snap-point-offset")

        continue
      }

      const offset = snap.offset()
      const sign = direction === "up" ? -1 : 1
      popup.style.setProperty("--drawer-height", `${Math.round(this.#size(popup))}px`)
      popup.style.setProperty("--drawer-snap-point-offset", `${Math.round(offset * sign)}px`)
      popup.toggleAttribute("data-expanded", offset <= 0)
    }
  }

  // The snap points of a vertical drawer, as offsets (px) from the full height of the popup.
  #snap(popup) {
    const points = () => (AXES[this.swipeDirectionValue] === "y" ? this.snapPointsValue.map(String) : [])
    const offsetOf = (point) => {
      const size = this.#size(popup)

      return Math.max(0, size - Math.min(size, snapPointSize(point) ?? size))
    }

    return {
      offsets: () => points().map(offsetOf),
      offset: () => {
        const all = points()
        const current = all.includes(this.snapPointValue) ? this.snapPointValue : all[0]

        return current === undefined ? 0 : offsetOf(current)
      },
      set: (offset) => {
        const point = points().find((p) => Math.abs(offsetOf(p) - offset) < 0.5)
        if (point === undefined) return

        this.snapPointValue = point
        this.#renderDrawer()
        this.dispatch("snap", { detail: { snapPoint: point } })
      },
      sequential: () => this.snapToSequentialPointsValue,
    }
  }

  #size(popup) {
    return popup.getBoundingClientRect().height || popup.offsetHeight || 0
  }

  // The `<dialog>` elements: the viewports, or the popups with no viewport.
  get #dialogs() {
    const viewports = this.viewportTargets.filter((el) => el instanceof HTMLDialogElement)

    return viewports.length > 0 ? viewports : this.popupTargets.filter((el) => el instanceof HTMLDialogElement)
  }

  #dialogOf(el) {
    return el instanceof HTMLDialogElement ? el : (el.closest("dialog") ?? el)
  }

  // The popup of a dialog: the `popup` target in a viewport, or the dialog itself.
  #popupOf(dialog) {
    return this.popupTargets.find((el) => el !== dialog && dialog.contains(el)) ?? dialog
  }

  // The parts that open and close with a dialog: the dialog, and its popup.
  #parts(dialog) {
    const popup = this.#popupOf(dialog)

    return popup === dialog ? [dialog] : [dialog, popup]
  }

  // The attributes that are not the state stay when the server render gives them.
  #renderAria() {
    const dialog = this.#dialogs[0]
    const title = this.titleTargets[0]
    const description = this.descriptionTargets[0]

    if (dialog && title) link(dialog, "aria-labelledby", "ariaLabelledByElements", title)
    if (dialog && description) link(dialog, "aria-describedby", "ariaDescribedByElements", description)

    // The active trigger is the trigger of the open dialog.
    const active = this.openValue ? this.triggers.active : null
    for (const trigger of this.triggers.all) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "dialog")
      trigger.setAttribute("aria-expanded", String(trigger === active))
      trigger.toggleAttribute("data-popup-open", trigger === active)
      controlWhileOpen(trigger, dialog, trigger === active)
    }
  }

  // The attributes of the state: `open` and the open state of a dialog, its nesting, its swipe,
  // its snap point, and `aria-expanded` and `data-popup-open` on a trigger.
  #owns(el, name) {
    if (NESTED_ATTRIBUTES.has(name) && (this.popupTargets.includes(el) || this.viewportTargets.includes(el)) && this.#dialogOf(el).querySelector?.("dialog[open]")) return true
    if (name === "tabindex" && focused.has(el)) return true
    if (this.backdropTargets.includes(el)) return OPEN_ATTRIBUTES.has(name)
    if (this.triggers.all.includes(el)) return name === "aria-expanded" || name === "data-popup-open"
    if (!this.popupTargets.includes(el) && !this.viewportTargets.includes(el)) return false

    // The `style` of a swipe: the popup stays at its place. The variables of a snap point and of
    // the nesting render again after the morph (see `morphed`), so the style of the server applies.
    if (name === "style") return [...SWIPE_ATTRIBUTES].some((attribute) => el.hasAttribute(attribute)) || this.keyboardInset > 0

    return name === "open" || OPEN_ATTRIBUTES.has(name) || name === "data-nested" || SWIPE_ATTRIBUTES.has(name) || DRAWER_ATTRIBUTES.has(name)
  }
}

// A dialog and its popup, when the popup is an element in it (a `viewport` target).
function popupsOf(dialog) {
  return [dialog, ...[...dialog.querySelectorAll("[data-phem-dialog-target~='popup']")].filter((popup) => popup !== dialog && popup.closest("dialog") === dialog)]
}
