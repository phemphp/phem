import { Controller } from "@hotwired/stimulus"

// Hides an element at once, before the server answers (optimistic UI). Give
// `data-phem-client="hidden"` to the element, so the morph of another event does not show it.
// On `phem:done` of the event, show it again: when the server still renders it, the server did
// not remove it (or the event failed). When the server removed it, the morph removed it too.
export default class extends Controller {
  hide() {
    this.element.hidden = true
  }

  show() {
    this.element.hidden = false
  }
}
