import { Controller } from "@hotwired/stimulus"

// The `phem-autofocus` controller: gives the focus to its element, or to its first `focus` target,
// when it connects. The native `autofocus` attribute works only when the page loads: this
// controller also works when the morph of an event adds or replaces the element.
//
//     <form onSubmit={{ save }}>
//         <input name="title" data-controller="phem-autofocus" />
//     </form>
//
//     <div data-controller="phem-autofocus">
//         <input name="title" data-phem-autofocus-target="focus" />
//     </div>
//
// The element gets the focus when the page loads, and in the morph of an event that the user
// started. A push or a refresh of the server does not move the focus. An element that the morph
// keeps or moves does not get the focus again. When more elements connect at the same time, the
// first one in the page gets the focus.
//
// The controller does not take the focus from a field that the user edits: a text `input`, a
// `textarea`, a `select` or a `contenteditable` element that is not read-only or disabled. A field
// of the form that the user submitted does not count: the user left it.
export default class PhemAutofocusController extends Controller {
  static targets = ["focus"]

  // Stimulus calls focusTargetConnected() before connect(), and when the morph adds a target.
  connect() {
    if (!this.hasFocusTarget) want(this.element)
  }

  focusTargetConnected(el) {
    if (el === this.focusTarget) want(el)
  }
}

// The input types that the user edits: the same list as `TEXT_INPUTS` in phem_controller.js.
const EDITED_INPUTS = new Set(["text", "search", "email", "url", "tel", "password", "number", "date", "time", "datetime-local", "month", "week", "color", "range"])

// The elements that connected before. The morph can move an element: Stimulus then connects it again.
const seen = new WeakSet()

// The elements that connect in this mutation observer callback.
let pending = []

// The element that sent the event of the user, from the answer of the server to the end of the event.
let sender = null

// The number of Turbo Streams that render now: the answer of an event, a push or a refresh.
let streams = 0

addEventListener("phem:receive", (event) => (sender = event.target))
addEventListener("phem:done", () => (sender = null))

document.addEventListener("turbo:before-stream-render", (event) => {
  const render = event.detail.render
  event.detail.render = async (stream) => {
    streams++

    try {
      await render(stream)
    } finally {
      // After the mutation observer callback of Stimulus, which connects the controllers.
      setTimeout(() => streams--, 0)
    }
  }
})

function want(el) {
  if (seen.has(el)) return

  seen.add(el)

  // A stream with no event of the user: a push or a refresh.
  if (streams && !sender) return

  pending.push(el)
  // Stimulus connects all the controllers of a mutation in one callback.
  if (pending.length === 1) queueMicrotask(focusFirst)
}

function focusFirst() {
  const [first] = pending.filter((el) => el.isConnected).sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
  pending = []

  const active = document.activeElement
  if (!first || (active && keepsFocus(active))) return

  first.focus()
}

// A field that the user edits keeps the focus, unless the user submitted its form.
function keepsFocus(el) {
  if (sender instanceof HTMLFormElement && sender.contains(el)) return false
  if (el.isContentEditable) return true
  if (el.disabled || el.readOnly) return false

  return el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement || (el instanceof HTMLInputElement && EDITED_INPUTS.has(el.type))
}
