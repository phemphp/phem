import { Controller } from "@hotwired/stimulus"

// The `phem-dialog` controller: opens its `dialog` target, a native `<dialog>`, as a modal with
// `showModal()`. The dialog stays open after the morph of an event.
//
//     <div data-controller="phem-dialog">
//         <button data-action="phem-dialog#open">Edit</button>
//         <dialog data-phem-dialog-target="dialog" aria-labelledby="edit-title">
//             <h2 id="edit-title">Edit</h2>
//             <button onClick={{ save }} data-action="phem-dialog#close">Save</button>
//         </dialog>
//     </div>
//
// The `open` value is the state: the server render gives its first value. The morph does not
// change the `open` attribute of the dialog and the `open` value: they belong to the client. A
// server render with a new `open` value sets the state.
//
// When the `dismiss` value is true (the default), Escape and a click on the backdrop close it. A
// dialog that closes in another way (a `<form method="dialog">`) also sets the state.
export default class PhemDialogController extends Controller {
  static targets = ["dialog"]
  static values = { open: Boolean, dismiss: { type: Boolean, default: true } }

  connect() {
    // The last `open` value of the server render.
    this.server = this.element.getAttribute(this.#openAttribute)

    // The morph of the controller element: a new server value of `open` sets the state.
    this.readServer = (event) => {
      if (event.target !== this.element) return

      const next = event.detail.newElement?.getAttribute(this.#openAttribute) ?? null
      this.serverChanged = next !== this.server
      this.server = next
    }
    this.element.addEventListener("turbo:before-morph-element", this.readServer)

    // The morph sets the attributes of the server render: keep the attributes of the state.
    this.keepState = (event) => {
      if (this.#owns(event.target, event.detail.attributeName)) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-attribute", this.keepState)

    // A dialog that the morph moved is out of the top layer: show it as a modal again.
    this.showAgain = (event) => {
      if (this.dialogTargets.includes(event.target)) this.#render(event.target)
    }
    this.element.addEventListener("turbo:morph-element", this.showAgain)
  }

  disconnect() {
    this.element.removeEventListener("turbo:before-morph-element", this.readServer)
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepState)
    this.element.removeEventListener("turbo:morph-element", this.showAgain)
    this.rendered = undefined
  }

  open() {
    this.#set(true)
  }

  close() {
    this.#set(false)
  }

  // Stimulus calls openValueChanged() in a mutation observer: show the state at once. Only the
  // first call renders the dialogs.
  openValueChanged() {
    if (this.rendered === this.openValue) return

    this.rendered = this.openValue
    for (const el of this.dialogTargets) this.#render(el)
  }

  // A dialog that the morph adds gets the state. The `cancel` and `close` events do not bubble.
  dialogTargetConnected(el) {
    el.addEventListener("cancel", this.onCancel)
    el.addEventListener("close", this.onClose)
    el.addEventListener("click", this.onClick)
    this.#render(el)
  }

  dialogTargetDisconnected(el) {
    el.removeEventListener("cancel", this.onCancel)
    el.removeEventListener("close", this.onClose)
    el.removeEventListener("click", this.onClick)
  }

  // Escape.
  onCancel = (event) => {
    if (!this.dismissValue) event.preventDefault()
  }

  // The `close` event comes in a later task: a dialog that is open again (shown as a modal again,
  // or opened by an action) keeps the state.
  onClose = (event) => {
    if (!event.target.open) this.#set(false)
  }

  // A click on the backdrop has the dialog as its target, and a point outside the box of the dialog.
  // A click on the padding of the dialog is inside the box.
  onClick = (event) => {
    if (!this.dismissValue || event.target !== event.currentTarget) return

    const box = event.currentTarget.getBoundingClientRect()
    const outside = event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom
    if (outside) this.close()
  }

  #set(open) {
    this.openValue = open
    this.openValueChanged()
  }

  // An open dialog that is not a modal (the server render gave `open`, or the morph moved it)
  // closes first: showModal() throws on it.
  #render(el) {
    if (!this.openValue) {
      if (el.open) el.close()

      return
    }

    if (el.matches(":modal") || !el.isConnected) return

    if (el.open) el.close()
    el.showModal()
  }

  get #openAttribute() {
    return `data-${this.identifier}-open-value`
  }

  // The attributes of the state: `open` on the controller element (unless the server render gave a
  // new value), and `open` on a dialog.
  #owns(el, name) {
    if (el === this.element && name === this.#openAttribute) return !this.serverChanged
    if (name === "open") return this.dialogTargets.includes(el)

    return false
  }
}
