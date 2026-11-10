import { Controller } from "@hotwired/stimulus"
import { onBackdropTap } from "./phem_backdrop.js"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { hide, render, show } from "./phem_transition.js"

// The attributes of a dialog in a dialog.
const NESTED_ATTRIBUTES = new Set(["data-nested", "data-nested-dialog-open", "style"])

// The `phem-dialog` controller: opens its `popup` target, a native `<dialog>`, as a modal with
// `showModal()`. The dialog stays open after the morph of an event.
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
// tap on the backdrop (see phem_backdrop.js) close it. A dialog that closes in another way (a
// `<form method="dialog">`) also sets the state.
//
// The dialog gets `data-open` or `data-closed`, and `aria-labelledby` (the `title` target) and
// `aria-describedby` (the `description` target). A close waits for the animations of
// `[data-ending-style]` before `close()` (see phem_transition.js). A trigger gets
// `aria-haspopup="dialog"`, `aria-expanded` and `data-popup-open`. A dialog in an open dialog gets
// `data-nested`, and the dialog around it gets `data-nested-dialog-open` and the
// `--nested-dialogs` CSS variable (the number of open dialogs in it), for a stack of dialogs.
export default class PhemDialogController extends Controller {
  static targets = ["trigger", "popup", "close", "title", "description"]
  static values = { open: Boolean, dismiss: { type: Boolean, default: true } }

  // dialog => the AbortController of its listeners.
  #listening = new Map()

  connect() {
    this.state = new ClientState(this, {
      value: "open",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        // A dialog that the morph moved is out of the top layer: show it as a modal again.
        for (const el of this.popupTargets) this.#render(el)
        this.#renderAria()
      },
    })
    this.state.connect()

    this.element.addEventListener("click", this.onClick)
    this.rendered = this.openValue
    this.#renderAria()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
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

  // A dialog that the morph adds gets the state. The `cancel` and `close` events do not bubble.
  popupTargetConnected(el) {
    this.#listening.get(el)?.abort()
    const listening = new AbortController()
    this.#listening.set(el, listening)
    el.addEventListener("cancel", this.onCancel, { signal: listening.signal })
    el.addEventListener("close", this.onClose, { signal: listening.signal })
    onBackdropTap(el, () => this.dismissValue && this.close(), listening.signal)

    this.#render(el)
    this.#renderAria()
  }

  popupTargetDisconnected(el) {
    this.#listening.get(el)?.abort()
    this.#listening.delete(el)
    this.#renderNesting(el, false)
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

  // Escape: the controller closes the dialog itself, after its animations.
  onCancel = (event) => {
    event.preventDefault()
    if (this.dismissValue) this.close()
  }

  // The `close` event comes in a later task: a dialog that is open again (shown as a modal again,
  // or opened by an action) keeps the state.
  onClose = (event) => {
    if (event.target.open) return

    render(event.target, false, { hidden: false })
    this.#renderNesting(event.target, false)
    if (this.openValue) this.#set(false)
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

    for (const el of this.popupTargets) {
      if (this.openValue) {
        this.#showModal(el)
        show(el, { hidden: false })
        this.#renderNesting(el, true)
      } else if (el.open) {
        hide(el, { hidden: false, onEnd: () => !this.openValue && el.open && el.close() })
      }
    }
  }

  // The state with no animation. An open dialog that is not a modal (the server render gave
  // `open`, or the morph moved it) closes first: showModal() throws on it.
  #render(el) {
    render(el, this.openValue, { hidden: false })

    if (!this.openValue) {
      if (el.open) el.close()

      return
    }

    this.#showModal(el)
    this.#renderNesting(el, true)
  }

  #showModal(el) {
    if (el.matches(":modal") || !el.isConnected) return

    if (el.open) el.close()
    el.showModal()
  }

  // A dialog in a dialog: the dialog around it shows how many dialogs are open in it.
  #renderNesting(el, open) {
    const parent = el.parentElement?.closest("dialog")
    el.toggleAttribute("data-nested", open && parent !== null && parent !== undefined)
    if (!parent) return

    const nested = [...parent.querySelectorAll("dialog[open]")].filter((d) => d !== el || open).length
    parent.toggleAttribute("data-nested-dialog-open", nested > 0)
    parent.style.setProperty("--nested-dialogs", String(nested))
  }

  // The attributes that are not the state stay when the server render gives them.
  #renderAria() {
    const popup = this.popupTargets[0]
    const title = this.titleTargets[0]
    const description = this.descriptionTargets[0]

    if (popup && title?.id && !popup.hasAttribute("aria-labelledby")) popup.setAttribute("aria-labelledby", title.id)
    if (popup && description?.id && !popup.hasAttribute("aria-describedby")) popup.setAttribute("aria-describedby", description.id)

    for (const trigger of this.triggerTargets) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", "dialog")
      trigger.setAttribute("aria-expanded", String(this.openValue))
      trigger.toggleAttribute("data-popup-open", this.openValue)
    }
  }

  // The attributes of the state: `open` and the open state of a dialog, its nesting, and
  // `aria-expanded` and `data-popup-open` on a trigger.
  #owns(el, name) {
    if (el instanceof HTMLDialogElement && NESTED_ATTRIBUTES.has(name) && el.querySelector("dialog[open]")) return true
    if (this.popupTargets.includes(el)) return name === "open" || OPEN_ATTRIBUTES.has(name) || name === "data-nested"
    if (this.triggerTargets.includes(el)) return name === "aria-expanded" || name === "data-popup-open"

    return false
  }
}
