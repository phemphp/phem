import { Controller } from "@hotwired/stimulus"

// The `phem-confirm` controller: asks the user to confirm before an event of a handler goes to the
// server. When the user cancels, no event goes.
//
//     <button onClick={{ delete }} data-controller="phem-confirm"
//             data-phem-confirm-message-value="Delete this item?">Delete</button>
//
// The `phem` controller dispatches the cancelable `phem:before-queue` on the element of a handler
// before its event goes. The controller asks on this event, on the element of the handler or on an
// element around it: only an event of a handler asks, with the event type that the handler uses.
// On a cancel, the DOM event does not do its default action, and a field that changed gets the
// value of the server render again. Only the closest `phem-confirm` controller asks.
//
// The `message` value is the text of the question. `window.confirm()` asks it.
export default class PhemConfirmController extends Controller {
  static values = { message: { type: String, default: "Are you sure?" } }

  connect() {
    this.ask = (event) => {
      event.stopPropagation()
      if (!window.confirm(this.messageValue)) event.preventDefault()
    }
    this.element.addEventListener("phem:before-queue", this.ask)
  }

  disconnect() {
    this.element.removeEventListener("phem:before-queue", this.ask)
  }
}
