// The choices of a `phem-combobox` that the browser shows before the server answers (see
// phem_combobox_controller.js): a click on an item that sends an event of phem. Each choice has
// the item, the chip that the browser added for it (or null), and the `parts` that show the state
// of the event. While the event runs, the parts get `data-pending`. A failed event gives them
// `data-failed`, and after FAILED_TIME the choice goes: `revert()` runs. phem shows no error
// message for the event: the parts show the failure.
//
// The answer of the server ends the choice: its morph shows the server render. A choice stays
// through the morph of another event (for example a search): the chip stays in the page, and the
// item stays chosen.
export const FAILED_TIME = 1000

export class PendingChoices {
  constructor() {
    this.choices = []
    this.timers = new Set()
  }

  // On the document: phem dispatches the `phem:done` of an item that the morph removed on the root
  // of its component, and `phem:error` on the root.
  connect() {
    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    document.addEventListener("phem:send", this.onSend, options)
    document.addEventListener("phem:receive", this.onReceive, options)
    document.addEventListener("phem:error", this.onError, options)
    document.addEventListener("phem:done", this.onDone, options)
  }

  disconnect() {
    this.listening.abort()
    for (const timer of this.timers) clearTimeout(timer)
    this.timers.clear()
    this.choices = []
  }

  // A choice of the DOM event `id` (the id of `phem:queue`).
  add(id, { item, chip = null, parts, revert, end = () => {} }) {
    for (const el of parts) el.setAttribute("data-pending", "")
    this.choices.push({ id, handler: null, received: false, item, chip, parts, revert, end })
  }

  // The browser keeps the chip of a choice until the answer of its event.
  keeps(chip) {
    return this.choices.some((c) => c.chip === chip && !c.received)
  }

  // The item of a choice is chosen until the answer of its event: the server render does not
  // change its `aria-selected`.
  chooses(item) {
    return this.choices.some((c) => c.item === item && !c.received)
  }

  onSend = (event) => {
    for (const choice of this.#of(event)) choice.handler = event.detail.handler
  }

  // The answer came: its morph shows the server render, and removes the chip of the browser.
  onReceive = (event) => {
    for (const choice of this.#of(event)) choice.received = true
  }

  onError = (event) => {
    const handler = event.detail?.handler
    if (handler != null && this.choices.some((c) => c.handler === handler)) event.preventDefault()
  }

  onDone = (event) => {
    for (const choice of this.#of(event)) {
      for (const el of choice.parts) el.removeAttribute("data-pending")

      if (event.detail?.ok !== false) {
        this.#remove(choice)
        choice.end()

        continue
      }

      for (const el of choice.parts) el.setAttribute("data-failed", "")
      choice.id = null
      const timer = setTimeout(() => {
        this.timers.delete(timer)
        this.#remove(choice)
        for (const el of choice.parts) el.removeAttribute("data-failed")
        choice.revert()
      }, FAILED_TIME)
      this.timers.add(timer)
    }
  }

  #remove(choice) {
    this.choices = this.choices.filter((c) => c !== choice)
  }

  // The choices of the DOM events that the event of a `phem:send`, `phem:receive` or `phem:done`
  // carries.
  #of(event) {
    const ids = event.detail?.ids ?? []

    return this.choices.filter((c) => c.id !== null && ids.includes(c.id))
  }
}
