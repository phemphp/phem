import { Controller } from "@hotwired/stimulus"

// The `phem-clipboard` controller: copies a text to the clipboard with
// `navigator.clipboard.writeText()`, and shows a short feedback. The feedback stays correct after
// the morph of an event.
//
//     <div data-controller="phem-clipboard">
//         <code data-phem-clipboard-target="source">{{ token }}</code>
//         <button data-action="phem-clipboard#copy">Copy</button>
//         <span data-phem-clipboard-target="feedback"></span>
//     </div>
//
// The `text` value is the text to copy. When it is not set, the controller copies the value of the
// first `source` target (an `<input>`, a `<textarea>` or a `<select>`), or its text with no
// whitespace at the start and at the end.
//
// For the `duration` value (in milliseconds, 2000 by default), the `feedback` targets contain the
// `label` value ("Copied" by default), and the controller element has `data-copied`. Else the
// feedback targets are empty. A feedback target gets `role="status"` when it has no role: it is a
// live region that stays in the page, so a screen reader reads the label when it comes.
//
// The feedback belongs to the client: before the morph of an event, the controller puts the state
// (the label, the role and `data-copied`) in the server render, so the morph does not change it.
//
// The controller dispatches `phem-clipboard:copied` (with the `text` in the detail), or
// `phem-clipboard:error` (with the `error` in the detail) when the browser does not copy the text.
export default class PhemClipboardController extends Controller {
  static targets = ["source", "feedback"]
  static values = { text: String, label: { type: String, default: "Copied" }, duration: { type: Number, default: 2000 } }

  connect() {
    this.keepState = (event) => {
      const next = event.detail.newElement
      if (!next) return

      if (event.target === this.element) this.#renderElement(next)
      else if (this.feedbackTargets.includes(event.target)) this.#renderFeedback(next)
    }
    this.element.addEventListener("turbo:before-morph-element", this.keepState)
  }

  // A copy that is pending does not show its feedback after a disconnect.
  disconnect() {
    this.element.removeEventListener("turbo:before-morph-element", this.keepState)
    this.copies = (this.copies ?? 0) + 1
    this.#show(false)
  }

  async copy() {
    const text = this.#text
    const copies = this.copies

    try {
      await navigator.clipboard.writeText(text)
    } catch (error) {
      if (copies !== this.copies) return

      this.#show(false)
      this.dispatch("error", { detail: { error } })

      return
    }

    if (copies !== this.copies) return

    // A second copy starts the duration again.
    this.#show(true)
    this.timer = setTimeout(() => this.#show(false), this.durationValue)

    this.dispatch("copied", { detail: { text } })
  }

  // A feedback target that the morph adds gets the state.
  feedbackTargetConnected(el) {
    this.#renderFeedback(el)
  }

  // Stimulus disconnects the targets before disconnect(): a target that leaves has no feedback.
  feedbackTargetDisconnected(el) {
    el.textContent = ""
  }

  get #text() {
    if (this.hasTextValue) return this.textValue

    const source = this.sourceTargets[0]
    if (!source) return ""

    return source.matches("input, textarea, select") ? source.value : source.textContent.trim()
  }

  #show(copied) {
    clearTimeout(this.timer)
    this.copied = copied
    this.#renderElement(this.element)

    for (const el of this.feedbackTargets) this.#renderFeedback(el)
  }

  // The state on the controller element, in the page or in the server render of the morph.
  #renderElement(el) {
    el.toggleAttribute("data-copied", !!this.copied)
  }

  // The state on a feedback target, in the page or in the server render of the morph. The same text
  // does not change the live region again.
  #renderFeedback(el) {
    if (!el.hasAttribute("role")) el.setAttribute("role", "status")

    const label = this.copied ? this.labelValue : ""
    if (el.textContent !== label) el.textContent = label
  }
}
