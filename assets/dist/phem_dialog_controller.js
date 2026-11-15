import { Controller } from "@hotwired/stimulus"
import { onBackdropTap } from "./phem_backdrop.js"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { dismissable } from "./phem_dismiss.js"
import { SWIPE_ATTRIBUTES, snapPointSize, swipeable, unswipe } from "./phem_swipe.js"
import { hide, render, show } from "./phem_transition.js"
import { link } from "./phem_aria.js"

// The attributes of a dialog in a dialog.
const NESTED_ATTRIBUTES = new Set(["data-nested", "data-nested-dialog-open", "data-nested-drawer-open", "style"])

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
// and `close` actions do the same from another element.
//
// The `open` value is the state: the server render gives its first value, and a server render
// with a new `open` value sets it. When the `dismiss` value is true (the default), Escape and a
// tap on the backdrop (see phem_backdrop.js) close it. With the `disablePointerDismissal` value,
// only Escape closes it (an alert dialog). A dialog that closes in another way (a
// `<form method="dialog">`) also sets the state.
//
// The `modal` value: `true` (the default) opens the dialog with `showModal()`: the page is inert,
// and the dialog is in the top layer. `false` opens it with `show()`: the page stays active, and
// Escape and a press outside close it (see phem_dismiss.js). `trap-focus` is `false`, and Tab
// stays in the dialog.
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
// trigger gets `aria-haspopup="dialog"`, `aria-expanded` and `data-popup-open`. A dialog in an
// open dialog gets `data-nested`, and the dialog around it gets `data-nested-dialog-open` and the
// `--nested-dialogs` CSS variable (the number of open dialogs in it), for a stack of dialogs. A
// drawer around it also gets `data-nested-drawer-open` and `--nested-drawers`.
//
// With the `swipeDirection` value (`up`, `down`, `left` or `right`), the dialog is a drawer: a
// swipe in that direction closes it (see phem_swipe.js). The popup gets `data-swipe-direction`.
// The `snapPoints` value (a list: a fraction of the height of the viewport, px, or a string with
// `px` or `rem`) gives the heights where a vertical drawer stops. The `snapPoint` value is the
// state: the snap point of the drawer (the first one by default). The popup gets
// `--drawer-snap-point-offset` (the distance from its full height), `--drawer-height`, and
// `data-expanded` at its full height. With `snapToSequentialPoints`, a fast swipe does not skip a
// snap point. A new snap point dispatches `phem-dialog:snap` with the `snapPoint` in the detail.
export default class PhemDialogController extends Controller {
  static targets = ["trigger", "popup", "viewport", "backdrop", "close", "title", "description"]
  static values = {
    open: Boolean,
    dismiss: { type: Boolean, default: true },
    disablePointerDismissal: Boolean,
    modal: { type: String, default: "true" },
    swipeDirection: String,
    snapPoints: Array,
    snapPoint: String,
    snapToSequentialPoints: Boolean,
  }

  // dialog => the AbortController of its listeners.
  #listening = new Map()
  // dialog => the function that removes its dismiss layer (a dialog that is not a modal).
  #layers = new Map()

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        // A dialog that the morph moved is out of the top layer: show it again.
        for (const el of this.#dialogs) this.#render(el)
        for (const el of this.backdropTargets) render(el, this.openValue)
        this.#renderAria()
      },
    })
    this.state.connect()

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("keydown", this.onKeydown)
    window.addEventListener("resize", this.onResize)
    this.rendered = this.openValue
    for (const el of this.backdropTargets) render(el, this.openValue)
    this.#renderAria()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("keydown", this.onKeydown)
    window.removeEventListener("resize", this.onResize)
    for (const off of this.#layers.values()) off()
    this.#layers.clear()
    this.rendered = undefined
  }

  open() {
    this.#set(true)
  }

  close() {
    this.#set(false)
  }

  // A server render with a new `open` value, or a change of the value by a script.
  openValueChanged() {
    if (this.rendered === undefined || this.rendered === this.openValue) return

    this.#apply()
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

    if (this.closeTargets.some((el) => el.contains(event.target))) this.close()
    else if (this.triggerTargets.some((el) => el.contains(event.target))) this.open()
  }

  // `trap-focus`: Tab goes from the last field of the dialog to the first one, and back.
  onKeydown = (event) => {
    if (event.key !== "Tab" || this.modalValue !== "trap-focus" || !this.openValue) return

    const dialog = this.#dialogs.find((el) => el.contains(event.target))
    if (!dialog) return

    const visible = (el) => !el.closest("[hidden]") && (el.checkVisibility?.() ?? true)
    const fields = [...dialog.querySelectorAll("a[href], button, input, select, textarea, [tabindex]")].filter((el) => !el.disabled && el.tabIndex >= 0 && visible(el))
    const [first, last] = [fields[0], fields.at(-1)]
    if (!first) return

    if (event.shiftKey && event.target === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && event.target === last) {
      event.preventDefault()
      first.focus()
    }
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
    if (popup === el) onBackdropTap(el, () => this.#pointerDismiss(), listening.signal)
    else this.#onViewportTap(el, listening.signal)

    swipeable(popup, {
      direction: () => this.swipeDirectionValue,
      open: () => el.open,
      close: () => this.close(),
      snap: this.#snap(popup),
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
    this.#renderNesting(el, false)
  }

  // A press on the viewport outside the popup. The press must start there too, as on a backdrop.
  #onViewportTap(viewport, signal) {
    const outside = (event) => !this.#popupOf(viewport).contains(event.target)
    let pressed = false

    viewport.addEventListener("pointerdown", (event) => (pressed = outside(event)), { signal })
    viewport.addEventListener("click", (event) => {
      const tapped = pressed && outside(event)
      pressed = false
      if (tapped) this.#pointerDismiss()
    }, { signal })
  }

  #pointerDismiss() {
    if (this.dismissValue && !this.disablePointerDismissalValue) this.close()
  }

  #set(open) {
    if (this.openValue === open && this.rendered === open) return

    this.openValue = open
    this.#apply()
  }

  // The state with its animations.
  #apply() {
    this.rendered = this.openValue
    this.#renderAria()

    for (const el of this.backdropTargets) {
      if (this.openValue) show(el)
      else hide(el)
    }

    for (const el of this.#dialogs) {
      const popup = this.#popupOf(el)

      if (this.openValue) {
        unswipe(popup)
        this.#showDialog(el)
        for (const part of this.#parts(el)) show(part, { hidden: false })
        this.#renderNesting(el, true)
        this.#renderDrawer()

        continue
      }

      if (!el.open) continue

      for (const part of this.#parts(el)) if (part !== popup) hide(part, { hidden: false })

      hide(popup, {
        hidden: false,
        onEnd: () => {
          if (this.openValue) return

          unswipe(popup)
          if (el.open) el.close()
        },
      })
    }
  }

  // The state with no animation. An open dialog in another mode (the server render gave `open`,
  // or the morph moved it) closes first.
  #render(el) {
    for (const part of this.#parts(el)) render(part, this.openValue, { hidden: false })

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

      return
    }

    if (!el.open) el.show()
    if (this.#layers.has(el)) return

    this.#layers.set(el, dismissable({
      inside: () => [el, ...this.triggerTargets],
      dismiss: (reason) => {
        if (!this.dismissValue || (reason === "outside" && this.disablePointerDismissalValue)) return false

        this.close()
      },
    }))
  }

  #unlayer(el) {
    this.#layers.get(el)?.()
    this.#layers.delete(el)
  }

  get #modal() {
    return this.modalValue !== "false" && this.modalValue !== "trap-focus"
  }

  // A dialog in a dialog: the dialog around it shows how many dialogs are open in it.
  #renderNesting(el, open) {
    const parent = el.parentElement?.closest("dialog")
    for (const part of this.#parts(el)) part.toggleAttribute("data-nested", open && Boolean(parent))
    if (!parent) return

    const nested = [...parent.querySelectorAll("dialog[open]")].filter((d) => d !== el || open).length
    const parts = [parent, ...[...parent.querySelectorAll("[data-phem-dialog-target~='popup']")].filter((p) => p.closest("dialog") === parent)]
    const drawer = parts.some((part) => part.hasAttribute("data-swipe-direction"))

    for (const part of parts) {
      part.toggleAttribute("data-nested-dialog-open", nested > 0)
      part.style.setProperty("--nested-dialogs", String(nested))
      if (!drawer) continue

      part.toggleAttribute("data-nested-drawer-open", nested > 0)
      part.style.setProperty("--nested-drawers", String(nested))
    }
  }

  // A drawer: its direction, and the snap point of the `snapPoint` value.
  #renderDrawer() {
    for (const el of this.#dialogs) {
      const popup = this.#popupOf(el)
      const direction = this.swipeDirectionValue
      if (AXES[direction]) popup.setAttribute("data-swipe-direction", direction)
      else popup.removeAttribute("data-swipe-direction")

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

    for (const trigger of this.triggerTargets) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "dialog")
      trigger.setAttribute("aria-expanded", String(this.openValue))
      trigger.toggleAttribute("data-popup-open", this.openValue)
    }
  }

  // The attributes of the state: `open` and the open state of a dialog, its nesting, its swipe,
  // its snap point, and `aria-expanded` and `data-popup-open` on a trigger.
  #owns(el, name) {
    if (el instanceof HTMLDialogElement && NESTED_ATTRIBUTES.has(name) && el.querySelector("dialog[open]")) return true
    if (this.backdropTargets.includes(el)) return OPEN_ATTRIBUTES.has(name)
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-popup-open"
    if (!this.popupTargets.includes(el) && !this.viewportTargets.includes(el)) return false

    // The `style` of a swipe: the popup stays at its place. The variables of a snap point and of
    // the nesting render again after the morph (see `morphed`), so the style of the server applies.
    if (name === "style") return [...SWIPE_ATTRIBUTES].some((attribute) => el.hasAttribute(attribute))

    return name === "open" || OPEN_ATTRIBUTES.has(name) || name === "data-nested" || SWIPE_ATTRIBUTES.has(name) || DRAWER_ATTRIBUTES.has(name)
  }
}
