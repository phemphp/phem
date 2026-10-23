import { Controller } from "@hotwired/stimulus"

// Shows the message of a `toast` browser event for some seconds.
// A handler sends it with `$e->dispatch('toast', ['message' => '...'])`.
export default class extends Controller {
  show({ detail }) {
    this.element.textContent = detail.message
    this.element.classList.add("toast-visible")

    clearTimeout(this.timer)
    this.timer = setTimeout(() => this.element.classList.remove("toast-visible"), 3000)
  }

  disconnect() {
    clearTimeout(this.timer)
  }
}
