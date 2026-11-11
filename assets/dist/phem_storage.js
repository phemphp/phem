// The state of a built-in controller in `localStorage`, for a state that stays after a reload.
// The keys have the `phem-` prefix. A read or a write never throws: a private window or a full
// storage gives null, and the state lives until the reload.
//
//     const storage = new PhemStorage("collapsible:promo-2026")
//     storage.get()          // "false", or null
//     storage.set("false")   // tells the other tabs and the other controllers of the page
//     storage.listen((value) => …, signal)
export class PhemStorage {
  #key

  constructor(key) {
    this.#key = `phem-${key}`
  }

  get() {
    try {
      return localStorage.getItem(this.#key)
    } catch {
      return null
    }
  }

  set(value) {
    try {
      localStorage.setItem(this.#key, value)
    } catch {
      return
    }

    // The `storage` event only goes to the other tabs.
    window.dispatchEvent(new CustomEvent("phem:storage", { detail: { key: this.#key, value } }))
  }

  // Calls `callback` with the new value when another tab or another controller of the page sets it.
  listen(callback, signal) {
    window.addEventListener("storage", (event) => {
      if (event.key === this.#key && event.newValue !== null) callback(event.newValue)
    }, { signal })

    window.addEventListener("phem:storage", (event) => {
      if (event.detail.key === this.#key) callback(event.detail.value)
    }, { signal })
  }
}
