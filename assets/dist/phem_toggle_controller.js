import { Controller } from "@hotwired/stimulus"

// The `phem-toggle` controller: shows and hides its `content` targets on the client, for a
// dropdown, a menu or a section that opens. The state stays open after the morph of an event.
//
//     <span data-controller="phem-toggle">
//         <button data-action="phem-toggle#toggle" data-phem-toggle-target="button" aria-expanded="false">⋯</button>
//         <span data-phem-toggle-target="content" hidden>
//             <button onClick={{ star }}>Star</button>
//         </span>
//     </span>
//
// The `open` value is the state: the server render gives its first value. The `content` targets
// get `hidden`, and the `button` targets `aria-expanded`. The morph does not change these
// attributes and the `open` value: they belong to the client. A server render with a new `open`
// value sets the state.
//
// When the `dismiss` value is true (the default), Escape and a click outside the controller element
// close it.
export default class PhemToggleController extends Controller {
  static targets = ["button", "content"]
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

    // The path of the event, not contains(): an action can remove the element of the click.
    this.onClick = (event) => {
      if (this.dismissValue && !event.composedPath().includes(this.element)) this.close()
    }

    this.onKeydown = (event) => {
      if (event.key !== "Escape" || event.defaultPrevented || event.isComposing || !this.dismissValue) return

      // The focus goes back to the button, so it does not stay on a hidden element.
      const focused = this.contentTargets.some((el) => el.contains(document.activeElement))
      this.close()
      if (focused) this.buttonTargets.find((el) => !el.disabled && !this.contentTargets.some((c) => c.contains(el)))?.focus()
    }

    // Stimulus calls openValueChanged() before connect().
    this.#listen(this.openValue)
  }

  disconnect() {
    this.element.removeEventListener("turbo:before-morph-element", this.readServer)
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepState)
    this.#listen(false)
    this.rendered = undefined
  }

  toggle() {
    this.#set(!this.openValue)
  }

  open() {
    this.#set(true)
  }

  close() {
    this.#set(false)
  }

  // Stimulus calls openValueChanged() in a mutation observer: show the state at once. Only the
  // first call renders the targets.
  openValueChanged() {
    if (this.rendered === this.openValue) return

    this.rendered = this.openValue
    for (const el of this.contentTargets) this.#showContent(el)
    for (const el of this.buttonTargets) this.#showButton(el)
    this.#listen(this.openValue)
  }

  // A target that the morph adds gets the state.
  contentTargetConnected(el) {
    this.#showContent(el)
  }

  buttonTargetConnected(el) {
    this.#showButton(el)
  }

  #set(open) {
    this.openValue = open
    this.openValueChanged()
  }

  #showContent(el) {
    el.hidden = !this.openValue
  }

  #showButton(el) {
    el.setAttribute("aria-expanded", String(this.openValue))
  }

  // Only an open controller listens to the clicks and the keys of the page.
  #listen(open) {
    const method = open ? "addEventListener" : "removeEventListener"
    document[method]("click", this.onClick)
    document[method]("keydown", this.onKeydown)
  }

  get #openAttribute() {
    return `data-${this.identifier}-open-value`
  }

  // The attributes of the state: `open` on the controller element (unless the server render gave a
  // new value), `hidden` on a content target and `aria-expanded` on a button target.
  #owns(el, name) {
    if (el === this.element && name === this.#openAttribute) return !this.serverChanged
    if (name === "hidden") return this.contentTargets.includes(el)
    if (name === "aria-expanded") return this.buttonTargets.includes(el)

    return false
  }
}
