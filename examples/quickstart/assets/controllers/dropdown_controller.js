import { Controller } from "@hotwired/stimulus"

// Opens and closes a menu in the browser, with no server event. Give `data-phem-client="hidden"`
// to the menu and `data-phem-client="aria-expanded"` to the button, so the morph of a server event
// does not close the menu.
export default class extends Controller {
  static targets = ["button", "menu"]

  toggle() {
    this.open(this.menuTarget.hidden)
  }

  close() {
    this.open(false)
  }

  // A click outside the menu closes it.
  closeOutside(event) {
    if (!this.element.contains(event.target)) this.close()
  }

  open(on) {
    this.menuTarget.hidden = !on
    this.buttonTarget.setAttribute("aria-expanded", String(on))
  }
}
