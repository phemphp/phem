import { Controller } from "@hotwired/stimulus"
import { DialogQuestion } from "./phem_dialog_question.js"

// Asks with `window.confirm()`, in the dispatch of `phem:before-queue`.
class NativeQuestion {
  ask(event, message) {
    if (!window.confirm(message)) event.preventDefault()

    return true
  }
}

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
//
// The `dialog` value is the selector of a `<dialog>` of the page: the dialog asks the question in
// place of `window.confirm()` (see phem_dialog_question.js). It cancels the event, and
// `detail.resume()` of `phem:before-queue` sends it when the user confirms. When the `phem`
// controller drops the event (`detail.signal`: a morph or a removal of the element, or a Turbo
// visit that caches the page), the dialog closes. The `accept` value is the label of its
// `[data-phem-confirm-accept]` elements. When the dialog cannot ask (no `<dialog>` for the
// selector, an invalid selector, or a dialog that is open with no question), the controller logs
// an error and `window.confirm()` asks, so the user still confirms.
//
//     <button onClick={{ clear }} data-controller="phem-confirm"
//             data-phem-confirm-message-value="Empty the bag?"
//             data-phem-confirm-accept-value="Empty bag"
//             data-phem-confirm-dialog-value="#confirm">Empty bag</button>
//
//     <dialog id="confirm" aria-describedby="confirm-message">
//         <form method="dialog">
//             <p id="confirm-message" data-phem-confirm-message></p>
//             <button value="cancel">Back</button>
//             <button value="confirm" data-phem-confirm-accept>Yes</button>
//         </form>
//     </dialog>
export default class PhemConfirmController extends Controller {
  static values = {
    message: { type: String, default: "Are you sure?" },
    accept: String,
    dialog: String,
  }

  connect() {
    this.ask = (event) => {
      event.stopPropagation()

      const { resume, signal } = event.detail
      const asked = DialogQuestion.find(this.dialogValue, "phem-confirm")?.ask(this.messageValue, this.acceptValue, { confirm: resume, signal })
      if (asked) event.preventDefault()
      else new NativeQuestion().ask(event, this.messageValue)
    }
    this.element.addEventListener("phem:before-queue", this.ask)
  }

  disconnect() {
    this.element.removeEventListener("phem:before-queue", this.ask)
  }
}
