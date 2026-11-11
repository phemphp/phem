// The other tabs of the same page in this browser (see `data-phem-sync-tabs-value`). After an event
// of the user, a tab tells the other tabs of its path with a BroadcastChannel. Each of these tabs
// refreshes its page: its signed state gets the changes of the event.
//
//     const sync = new TabSync(() => refresh())
//     sync.announce()   // an event of this tab changed the page
//     sync.close()
//
// A hidden tab does not refresh at once: it refreshes when it becomes visible again, one time for
// all the messages since then. A message goes only to the tabs of the same origin, so to the tabs
// that have the cookies of the same user.
//
// A page that comes back can show old data:
// - Turbo shows a page from its snapshot cache on a visit back. The document keeps the channel
//   open after the page leaves, and remembers the paths of the left pages that got a message.
// - The browser restores a page from its back/forward cache (`pageshow` with `persisted`). The
//   frozen page got no messages, so it refreshes each time.

const CHANNEL = "phem-sync-tabs"
// The number of left pages whose messages the document remembers.
const MAX_LEFT = 20

// The path of the page: the tabs of the same page with another query string or fragment refresh too.
function pagePath() {
  return window.location.pathname
}

// One channel for the document, open while it lives: it hears the messages of a left page too.
let channel = null
// The open TabSync objects of the document.
const syncs = new Set()
// The path of each left page => true when a message came for it after it left.
const left = new Map()

function receive(data) {
  const path = data?.path
  if (typeof path !== "string") return

  if (left.has(path)) left.set(path, true)

  for (const sync of syncs) sync.receive(path)
}

function openChannel() {
  if (channel) return channel

  channel = new BroadcastChannel(CHANNEL)
  channel.addEventListener("message", (event) => receive(event.data))

  return channel
}

export class TabSync {
  static supported() {
    return typeof BroadcastChannel === "function"
  }

  #path
  #onChange
  // A message came while the tab was hidden: it refreshes when it becomes visible.
  #pending = false
  #onVisible
  #onShow

  constructor(onChange) {
    this.#path = pagePath()
    this.#onChange = onChange
    openChannel()
    syncs.add(this)

    this.#onVisible = () => {
      if (!this.#pending || document.visibilityState === "hidden") return

      this.#pending = false
      this.#onChange()
    }
    document.addEventListener("visibilitychange", this.#onVisible)

    this.#onShow = (event) => {
      if (event.persisted) this.#change()
    }
    window.addEventListener("pageshow", this.#onShow)

    // Turbo shows this page again from its snapshot: a message came after it left.
    const missed = left.get(this.#path)
    left.delete(this.#path)
    if (missed) this.#change()
  }

  // Tells the other tabs of this page that an event changed it.
  announce() {
    openChannel().postMessage({ path: this.#path })
  }

  close() {
    syncs.delete(this)
    document.removeEventListener("visibilitychange", this.#onVisible)
    window.removeEventListener("pageshow", this.#onShow)

    left.delete(this.#path)
    left.set(this.#path, false)
    if (left.size > MAX_LEFT) left.delete(left.keys().next().value)
  }

  receive(path) {
    if (path === this.#path) this.#change()
  }

  #change() {
    if (document.visibilityState === "hidden") {
      this.#pending = true
      return
    }

    this.#onChange()
  }
}

// For the tests: a new document.
export function resetTabSync() {
  channel?.close()
  channel = null
  syncs.clear()
  left.clear()
}
