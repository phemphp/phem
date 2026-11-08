import { vi } from "vitest"

// The helpers of the controller tests. Each test file stubs `fetch` and registers its controllers.

// A page with one component (`c1`) and the markup of its server render.
export async function mount(markup, rootAttributes = "") {
  document.body.innerHTML = `
    <div id="phem-root" data-controller="phem" data-action="click->phem#dispatch submit->phem#dispatch change->phem#dispatch"
         data-phem-state-value="{}" data-phem-signature-value="s1" data-phem-loading-delay-value="0" ${rootAttributes}>
      <phem-c data-phem-c="c1">${markup}</phem-c>
    </div>`
  // Stimulus connects the controller in a mutation observer callback.
  await new Promise((resolve) => setTimeout(resolve, 0))
}

// The JSON of an answer of the server: a morph of `c1` to `markup` (in its `<phem-c>`), with no
// change of the state, or the `changes` of the state.
export function answer(markup, changes = {}, extra = {}) {
  return {
    streams: `<turbo-stream action="replace" method="morph" targets="[data-phem-c=c1]"><template><phem-c data-phem-c="c1">${markup}</phem-c></template></turbo-stream>`,
    changes,
    signature: "s2",
    ...extra,
  }
}

// The answer of the server to the next event (see answer()).
export function respond(markup, changes = {}, extra = {}) {
  fetch.mockResolvedValueOnce(new Response(JSON.stringify(answer(markup, changes, extra)), { status: 200, headers: { "Content-Type": "application/json" } }))
}

// Resolves with the `phem:done` event of the next event.
export function nextDone() {
  return new Promise((resolve) => window.addEventListener("phem:done", resolve, { once: true }))
}

export function click(selector) {
  document.querySelector(selector).click()
}

// A press on `from` and a click on `to`, at the points of the viewport. A browser gives the click
// to the common ancestor when the press and the release are on different elements.
export function clickAt(from, [x1, y1], to = from, [x2, y2] = [x1, y1]) {
  from.dispatchEvent(new MouseEvent("pointerdown", { bubbles: true, cancelable: true, clientX: x1, clientY: y1 }))
  to.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, clientX: x2, clientY: y2 }))
}

// jsdom has no showModal(): it opens the dialog. close() sets the return value, and the `close`
// event comes in a later task, as in a browser.
export function stubDialogs() {
  const proto = HTMLDialogElement.prototype
  proto.showModal = function () {
    if (this.open) throw new DOMException("The dialog is open", "InvalidStateError")

    this.setAttribute("open", "")
  }
  proto.close = function (returnValue) {
    if (!this.open) return

    if (undefined !== returnValue) this.returnValue = returnValue
    this.removeAttribute("open")
    setTimeout(() => this.dispatchEvent(new Event("close")), 0)
  }
}

// The `#confirm` dialog of the layout, for `phem-confirm` and `data-phem-guard`.
export const DIALOG = `<dialog id="confirm" aria-describedby="confirm-message">
    <form method="dialog">
      <p id="confirm-message" data-phem-confirm-message></p>
      <button value="cancel">Back</button>
      <button value="confirm" data-phem-confirm-accept>Yes</button>
    </form>
  </dialog>`

// The user answers `answer` to the question of `window.confirm()`.
export function stubConfirm(answer) {
  const confirm = vi.fn(() => answer)
  vi.stubGlobal("confirm", confirm)

  return confirm
}

// Lets the tasks that wait run: the `close` event of a dialog, the queue of the phem controller.
export function settle() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}
