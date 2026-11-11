import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The triggers whose `aria-controls` the controller set.
const controlled = new WeakSet()

// The `phem-reveal` controller: shows and hides the text of a password field.
//
//     <div data-controller="phem-reveal">
//         <input type="password" name="password" data-phem-reveal-target="field">
//         <button type="button" data-phem-reveal-target="trigger">Show</button>
//     </div>
//
// A click on a trigger shows the text of the `field` targets (`type="text"`), and a second click
// hides it again (`type="password"`). The `toggle`, `show` and `hide` actions do the same from
// another element. The field keeps its value, its focus and its selection.
//
// The `revealed` value is the state: the server render gives its first value, and a server render
// with a new value sets it. The controller element, the fields and the triggers get
// `data-revealed`. A trigger gets `aria-pressed`, and `aria-controls` (the fields with an id).
// The state stays after the morph of an event, and the morph keeps the value of a shown password
// (see phem_controller.js).
export default class PhemRevealController extends Controller {
  static targets = ["field", "trigger"]
  static values = { revealed: Boolean }

  connect() {
    this.state = new ClientState(this, {
      value: "revealed",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.element.addEventListener("click", this.onClick)
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("click", this.onClick)
  }

  toggle() {
    this.#set(!this.revealedValue)
  }

  show() {
    this.#set(true)
  }

  hide() {
    this.#set(false)
  }

  // A server render with a new value, or a change of the value by a script.
  revealedValueChanged() {
    if (this.state) this.#render()
  }

  #set(revealed) {
    this.revealedValue = revealed
    this.#render()
  }

  fieldTargetConnected() {
    if (this.state) this.#render()
  }

  triggerTargetConnected() {
    if (this.state) this.#render()
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const trigger = this.triggerTargets.find((el) => el.contains(event.target))
    if (trigger && !trigger.disabled) this.toggle()
  }

  #render() {
    const revealed = this.revealedValue
    this.element.toggleAttribute("data-revealed", revealed)

    // A form field gets no id from the controller: the morph matches a bound field by its id.
    const ids = this.fieldTargets.map((field) => field.id).filter(Boolean)

    for (const field of this.fieldTargets) {
      setType(field, revealed ? "text" : "password")
      field.toggleAttribute("data-revealed", revealed)
    }

    for (const trigger of this.triggerTargets) {
      // The server render can give its own `aria-controls`.
      if (ids.length > 0 && (!trigger.hasAttribute("aria-controls") || controlled.has(trigger))) {
        trigger.setAttribute("aria-controls", ids.join(" "))
        controlled.add(trigger)
      }

      trigger.setAttribute("aria-pressed", String(revealed))
      trigger.toggleAttribute("data-revealed", revealed)
    }
  }

  // The attributes of the state: `type` on a field, `aria-pressed` on a trigger, and
  // `data-revealed` on all the parts.
  #owns(el, name) {
    if (el === this.element) return name === "data-revealed"
    if (this.fieldTargets.includes(el)) return name === "type" || name === "data-revealed"
    if (this.triggerTargets.includes(el)) return name === "aria-pressed" || name === "data-revealed"

    return false
  }
}

// Changes the type of a field, and keeps its selection: a browser can move the caret to the end.
function setType(field, type) {
  if (field.type === type) return

  const focused = field === document.activeElement
  const { selectionStart: start, selectionEnd: end, selectionDirection: direction } = field
  field.type = type
  if (focused && start !== null) field.setSelectionRange(start, end, direction ?? "none")
}
