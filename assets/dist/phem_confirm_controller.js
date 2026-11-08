import { Controller } from "@hotwired/stimulus"

// The open question of each dialog (see DialogQuestion).
const questions = new WeakMap()

// Asks with `window.confirm()`, in the dispatch of `phem:before-queue`.
class NativeQuestion {
  ask(event, message) {
    if (!window.confirm(message)) event.preventDefault()

    return true
  }
}

// Asks in a `<dialog>` of the page. It cancels the event, puts the message and the accept label in
// the dialog, and opens it with `showModal()`. When the dialog closes with the return value
// "confirm", `detail.resume()` of `phem:before-queue` sends the event. Any other close sends no
// event.
//
// While it asks, the morph does not change the dialog. When the `phem` controller drops the event
// (`detail.signal`: a morph or a removal of the element), the dialog closes. When the question
// ends, the texts of the dialog get their labels again, so the next question starts from the
// labels of the page. A new question replaces the open question of the dialog.
class DialogQuestion {
  constructor(dialog) {
    this.dialog = dialog
  }

  // False when it cannot ask: the dialog is open with no question (a server render or `show()`),
  // or `showModal()` fails. Then the event is not cancelled.
  ask(event, message, accept) {
    const dialog = this.dialog
    const open = questions.get(dialog)
    if (dialog.open && !open) {
      console.error("[phem] phem-confirm: the dialog is open with no question")
      return false
    }

    open?.abort()
    const texts = [...fill(dialog, "[data-phem-confirm-message]", message), ...(accept ? fill(dialog, "[data-phem-confirm-accept]", accept) : [])]
    const restore = () => texts.forEach(([el, text]) => (el.textContent = text))

    // The return value of the last close stays on the dialog.
    dialog.returnValue = ""
    if (!dialog.open) {
      try {
        dialog.showModal()
      } catch (error) {
        console.error("[phem] phem-confirm: the dialog does not open", error)
        restore()
        return false
      }
    }

    event.preventDefault()
    this.#wait(event.detail, restore)

    return true
  }

  // Sends the event when the dialog closes with "confirm".
  #wait({ resume, signal }, restore) {
    const dialog = this.dialog
    const question = new AbortController()
    const options = { signal: question.signal }
    questions.set(dialog, question)

    question.signal.addEventListener("abort", () => {
      restore()
      if (questions.get(dialog) === question) questions.delete(dialog)
    })
    dialog.addEventListener("close", () => {
      const confirmed = "confirm" === dialog.returnValue
      question.abort()
      if (confirmed) resume()
    }, options)

    // The morph does not change the dialog, its texts and its `open` attribute.
    dialog.addEventListener("turbo:before-morph-element", (e) => e.target === dialog && e.preventDefault(), options)

    // The event dropped: no answer can send it.
    signal.addEventListener("abort", () => {
      question.abort()
      dialog.close()
    }, options)
  }
}

// Puts `text` in the `selector` elements of the dialog. Returns their texts before.
function fill(dialog, selector, text) {
  return [...dialog.querySelectorAll(selector)].map((el) => {
    const before = el.textContent
    el.textContent = text

    return [el, before]
  })
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
// place of `window.confirm()` (see DialogQuestion). The `accept` value is the label of its
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

      const asked = this.#dialogQuestion()?.ask(event, this.messageValue, this.acceptValue)
      if (!asked) new NativeQuestion().ask(event, this.messageValue)
    }
    this.element.addEventListener("phem:before-queue", this.ask)
  }

  disconnect() {
    this.element.removeEventListener("phem:before-queue", this.ask)
  }

  // The question in the `<dialog>` of the `dialog` value, or null with no value or with no dialog.
  #dialogQuestion() {
    if (!this.dialogValue) return null

    let dialog = null
    try {
      dialog = document.querySelector(this.dialogValue)
    } catch {
      // An invalid selector: no dialog.
    }

    if (dialog instanceof HTMLDialogElement) return new DialogQuestion(dialog)

    console.error(`[phem] phem-confirm: "${this.dialogValue}" is not a <dialog> of the page`)

    return null
  }
}
