import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The attributes of the count on the controller element and on an `output` target.
const COUNT_ATTRIBUTES = new Set(["data-count", "data-max", "data-over"])

// The `phem-count` controller: shows the number of characters of a text field, or the number of
// checked boxes, while the user types or clicks.
//
//     <div data-controller="phem-count">
//         <textarea name="bio" maxlength="160" data-phem-count-target="field"></textarea>
//         <span data-phem-count-target="output"></span>
//     </div>
//
// The `field` targets are a text field (an `<input>` or a `<textarea>`), or checkboxes: the count
// is the length of the value of the first field, or the number of checked boxes. The maximum is
// the `max` value, or the `maxlength` of the text field. The `max` value is a soft limit: the
// user can type more, and the controller element gets `data-over`.
//
// Each `output` target shows the count with its `data-phem-count-template` attribute: `{count}`,
// `{max}` and `{remaining}` get their numbers. The default is `{count}/{max}`, or `{count}` with no
// maximum. The controller element and each output get `data-count`, `data-max` and `data-over`.
//
// The count belongs to the client: after the morph of an event, the controller counts again.
export default class PhemCountController extends Controller {
  static targets = ["field", "output"]
  static values = { max: Number }

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.render(),
    })
    this.state.connect()

    this.element.addEventListener("input", this.onChange)
    this.element.addEventListener("change", this.onChange)
    this.render()
  }

  disconnect() {
    this.state.disconnect()
    this.element.removeEventListener("input", this.onChange)
    this.element.removeEventListener("change", this.onChange)
  }

  fieldTargetConnected() {
    if (this.state) this.render()
  }

  outputTargetConnected() {
    if (this.state) this.render()
  }

  maxValueChanged() {
    if (this.state) this.render()
  }

  onChange = (event) => {
    if (this.fieldTargets.includes(event.target)) this.render()
  }

  // Shows the count on the controller element and in the outputs.
  render() {
    const count = this.#count
    const max = this.#max
    const over = max !== null && count > max

    for (const el of [this.element, ...this.outputTargets]) {
      el.setAttribute("data-count", String(count))
      if (max === null) el.removeAttribute("data-max")
      else el.setAttribute("data-max", String(max))
      el.toggleAttribute("data-over", over)
    }

    for (const output of this.outputTargets) {
      const template = output.getAttribute("data-phem-count-template") ?? (max === null ? "{count}" : "{count}/{max}")
      const text = template
        .replaceAll("{count}", String(count))
        .replaceAll("{max}", max === null ? "" : String(max))
        .replaceAll("{remaining}", max === null ? "" : String(max - count))
      if (output.textContent !== text) output.textContent = text
    }
  }

  // The number of checked boxes, or the length of the value of the text field.
  get #count() {
    const fields = this.fieldTargets
    if (fields.length === 0) return 0
    if (fields.some(isChoice)) return fields.filter((f) => isChoice(f) && f.checked).length

    return fields[0].value.length
  }

  // The `max` value, or the `maxlength` of the text field, or null.
  get #max() {
    if (this.hasMaxValue) return this.maxValue

    const field = this.fieldTargets.find((f) => !isChoice(f))
    const max = field?.maxLength ?? -1

    return max >= 0 ? max : null
  }

  #owns(el, name) {
    if (el === this.element || this.outputTargets.includes(el)) return COUNT_ATTRIBUTES.has(name)

    return false
  }
}

function isChoice(field) {
  return field instanceof HTMLInputElement && (field.type === "checkbox" || field.type === "radio")
}
