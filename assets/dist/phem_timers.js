// Timers that stop and go on with the time that remains, as the toast timers of Base UI
// (`{ timeout, start, remaining }` for each key).
//
//     const timers = new PausableTimers((key) => close(key))
//     timers.set(toast, 5000) // a stopped timer with the full time
//     timers.resume(toast)    // the time runs
//     timers.pause(toast)     // the time stops, and the time that remains stays
export class PausableTimers {
  #timers = new Map()

  // `onEnd(key)` runs when the time of a key ends.
  constructor(onEnd) {
    this.onEnd = onEnd
  }

  // A new stopped timer with the full time. A duration that is not more than 0 gives no timer.
  set(key, duration) {
    this.delete(key)
    if (!(duration > 0)) return

    this.#timers.set(key, { timeout: null, start: 0, remaining: duration })
  }

  has(key) {
    return this.#timers.has(key)
  }

  // Runs the time that remains. A timer that runs does not change.
  resume(key) {
    const timer = this.#timers.get(key)
    if (!timer || timer.timeout !== null) return

    timer.start = Date.now()
    timer.timeout = setTimeout(() => {
      this.#timers.delete(key)
      this.onEnd(key)
    }, timer.remaining)
  }

  // Stops the time, and keeps the time that remains. A stopped timer does not change.
  pause(key) {
    const timer = this.#timers.get(key)
    if (!timer || timer.timeout === null) return

    clearTimeout(timer.timeout)
    timer.remaining = Math.max(timer.remaining - (Date.now() - timer.start), 0)
    timer.timeout = null
  }

  delete(key) {
    clearTimeout(this.#timers.get(key)?.timeout)
    this.#timers.delete(key)
  }

  clear() {
    for (const timer of this.#timers.values()) clearTimeout(timer.timeout)
    this.#timers.clear()
  }
}
