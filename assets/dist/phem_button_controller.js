import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The elements that the browser already clicks on Enter and Space.
const NATIVE = "button, input, select, textarea, a[href], summary"

// The `phem-button` controller: gives an element that is not a `<button>` the keyboard of a
// button, as a Base UI button with `nativeButton={false}`.
//
//     <div data-controller="phem-button" onClick={{ save }}>Save</div>
//
// Enter clicks the element when the key goes down. Space clicks it when the key goes up. The click
// runs the handler of the element (`onClick`), as a click of the pointer. The element gets
// `role="button"` and `tabindex="0"`, unless the server render gives them. They stay after the
// morph of an event.
//
// A button with `aria-disabled="true"` keeps the focus, but it does nothing: the keys do not
// click it, and a click of the pointer or of a script does not run its handler. This is also
// true for a native `<button>` with the controller, which gets no role and no `tabindex`.
export default class PhemButtonController extends Controller {
  connect() {
    // The attributes that the controller added: the morph keeps them.
    this.added = new Set()
    this.state = new ClientState(this, { owns: (el, name) => el === this.element && this.added.has(name) })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("keyup", this.onKeyup, options)
    // In the capture phase: before the handlers of the element and of the page.
    this.element.addEventListener("click", this.onClick, { ...options, capture: true })

    if (this.#native) return

    for (const [name, value] of [["role", "button"], ["tabindex", "0"]]) {
      if (this.element.hasAttribute(name)) continue

      this.element.setAttribute(name, value)
      this.added.add(name)
    }
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
  }

  onKeydown = (event) => {
    if (!this.#handles(event)) return

    const disabled = this.#disabled

    // Space does not scroll the page: the click comes when the key goes up. The browser clicks an
    // enabled native button itself. A disabled button does not click.
    if ((event.key === " " && !this.#native) || disabled) event.preventDefault()
    if (event.key === "Enter" && !disabled && !this.#native) {
      event.preventDefault()
      this.element.click()
    }
  }

  onKeyup = (event) => {
    if (!this.#handles(event) || event.key !== " ") return

    if (this.#disabled) event.preventDefault()
    else if (!this.#native) this.element.click()
  }

  // A click on a disabled button stops here: no handler runs.
  onClick = (event) => {
    if (!this.#disabled) return

    event.preventDefault()
    event.stopImmediatePropagation()
  }

  // Enter and Space on the element itself, not on a field in it.
  #handles(event) {
    if (event.target !== this.element || event.defaultPrevented) return false

    return event.key === "Enter" || event.key === " "
  }

  get #native() {
    return this.element.matches(NATIVE)
  }

  get #disabled() {
    return this.element.getAttribute("aria-disabled") === "true"
  }
}
