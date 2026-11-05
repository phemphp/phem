// The Mercure hub of a test with a real browser (Playwright) and the kernel in the process of the
// test: Phem publishes to memory there (see Phem\Stream\MemoryPublisher), and the browser has no
// hub. Add this script to each page before its scripts (Playwright `addInitScript()`). It replaces
// `EventSource` with a fake that connects at once. `phemTestHub.deliver(updates)` gives each
// connection the updates of its `match` topics, as the hub does (see Phem\Test\BrowserHub):
//
// - each connection gets each update one time (its `index`), so the same script goes to each page;
// - a connection gets only the updates published after it opened (their `time`), as the hub keeps
//   no history. The browser and the test run on the same machine, with the same clock;
// - it does not check the subscriber key: a page gets the private updates of its topics also when
//   the real hub would not authorize it.
//
// For the tests only: a page with this script gets no pushes from a real hub.
;(() => {
  const sources = new Set()

  class PhemTestEventSource extends EventTarget {
    static CONNECTING = 0
    static OPEN = 1
    static CLOSED = 2

    constructor(url, options = {}) {
      super()
      this.url = new URL(url, window.location.href).href
      this.withCredentials = Boolean(options.withCredentials)
      this.readyState = PhemTestEventSource.OPEN
      this.onopen = null
      this.onmessage = null
      this.onerror = null
      // The topics of the connection, as the hub reads them from the URL.
      this.topics = new URL(this.url).searchParams.getAll("match")
      // The time when it opened (in seconds), and the index of the last update that it got.
      this.opened = Date.now() / 1000
      this.last = -1
      sources.add(this)
      queueMicrotask(() => this.readyState === PhemTestEventSource.OPEN && this.emit(new Event("open")))
    }

    close() {
      this.readyState = PhemTestEventSource.CLOSED
      sources.delete(this)
    }

    emit(event) {
      this.dispatchEvent(event)
      this[`on${event.type}`]?.(event)
    }

    // Gets the update when it is new for the connection, and of its topics.
    take({ index, time, topics, data }) {
      if (index <= this.last) return
      this.last = index
      if (time >= this.opened && topics.some((topic) => this.topics.includes(topic))) this.emit(new MessageEvent("message", { data }))
    }
  }

  window.EventSource = PhemTestEventSource
  window.phemTestHub = {
    // Gives each open connection its new updates, in their order: `[{index, time, topics, data}]`.
    deliver(updates) {
      for (const update of updates) {
        for (const source of [...sources]) source.take(update)
      }
    },
  }
})()
