import { onBackdropTap } from "./phem_backdrop.js"

// A question in a `<dialog>` of the page, in place of `window.confirm()`. The `phem-confirm`
// controller asks it before an event, and the `phem` controller before a click on a Turbo link
// that leaves a dirty `data-phem-guard` element.
//
//     <dialog id="confirm" aria-describedby="confirm-message">
//         <form method="dialog">
//             <p id="confirm-message" data-phem-confirm-message></p>
//             <button value="cancel">Back</button>
//             <button value="confirm" data-phem-confirm-accept>Yes</button>
//         </form>
//     </dialog>
//
// It puts the message and the accept label in the dialog, and opens it with `showModal()`. When
// the dialog closes with the return value "confirm", it calls `confirm`. Any other close (a
// button, Escape, or a tap on the backdrop: see phem_backdrop.js) calls nothing.
//
// While it asks, the morph does not change the dialog. When the `signal` of the question aborts
// (the event dropped, or Turbo caches the page), the question ends and the dialog closes. When the
// question ends, the texts of the dialog get their labels again, so the next question starts from
// the labels of the page. A new question replaces the open question of the dialog.

// The open question of each dialog.
const questions = new WeakMap()

export class DialogQuestion {
  // `source` names the asker in the logs: "phem-confirm" or "data-phem-guard".
  constructor(dialog, source) {
    this.dialog = dialog
    this.source = source
  }

  // The question in the `<dialog>` of `selector`, or null with no selector, or with no dialog
  // (an invalid selector, or an element that is not a `<dialog>`: it logs an error).
  static find(selector, source) {
    if (!selector) return null

    let dialog = null
    try {
      dialog = document.querySelector(selector)
    } catch {
      // An invalid selector: no dialog.
    }

    if (dialog instanceof HTMLDialogElement) return new DialogQuestion(dialog, source)

    console.error(`[phem] ${source}: "${selector}" is not a <dialog> of the page`)

    return null
  }

  // Asks `message`. `accept` is the label of the `[data-phem-confirm-accept]` elements: with no
  // label, they keep the label of the page. False when it cannot ask: the dialog is open with no
  // question (a server render or `show()`), or `showModal()` fails. Then the caller asks in
  // another way.
  ask(message, accept, { confirm, signal }) {
    const dialog = this.dialog
    const open = questions.get(dialog)
    if (dialog.open && !open) {
      console.error(`[phem] ${this.source}: the dialog is open with no question`)
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
        console.error(`[phem] ${this.source}: the dialog does not open`, error)
        restore()
        return false
      }
    }

    this.#wait(confirm, signal, restore)

    return true
  }

  // Calls `confirm` when the dialog closes with "confirm".
  #wait(confirm, signal, restore) {
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
      if (confirmed) confirm()
    }, options)

    // A tap on the backdrop closes the dialog with no return value.
    onBackdropTap(dialog, () => dialog.close(), question.signal)

    // The morph does not change the dialog, its texts and its `open` attribute.
    dialog.addEventListener("turbo:before-morph-element", (e) => e.target === dialog && e.preventDefault(), options)

    // No answer can confirm now. The `close` event comes in a later task, so the texts of the
    // dialog get their labels at once: when Turbo caches the page, the snapshot has a closed
    // dialog with its labels, and a visit back shows no question.
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
