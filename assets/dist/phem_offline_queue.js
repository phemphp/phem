// The events of a page that wait for the connection, in IndexedDB, so they stay after a reload or
// after the tab closes (see `data-phem-offline-queue-value`). Each page keeps one record: its URL,
// the signed state before the first event, and the events in their order.
//
//     const queue = new OfflineQueue()
//     queue.save({ url, state, signature, events })   // replaces the record of this page
//     queue.remove()                                  // the events went to the server
//     await queue.take(url, maxAge)                   // the records of other pages, with their events
//
// A page with a record holds a Web Lock, so take() does not take the record of an open tab: that
// tab sends its events itself. The browser releases the lock when the page unloads. The page
// holds no lock while no event waits: a lock keeps the page out of the back/forward cache.
//
// A write never throws: a private window or a full storage keeps the events in memory only.

const DATABASE = "phem"
const STORE = "offline-queue"
const LOCK = "phem-offline-queue:"
// In `localStorage` while the database can have a record: with no mark, take() does not open the
// database, and the events of the page do not wait for it.
const MARK = "phem-offline-queue"

function request(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
}

function done(transaction) {
  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve()
    transaction.onerror = () => reject(transaction.error)
    transaction.onabort = () => reject(transaction.error)
  })
}

function marked() {
  try {
    return localStorage.getItem(MARK) !== null
  } catch {
    // No storage: the database can have a record.
    return true
  }
}

function mark(on) {
  try {
    if (on) localStorage.setItem(MARK, "1")
    else localStorage.removeItem(MARK)
  } catch {
    // No storage: take() opens the database each time.
  }
}

let database = null

function open() {
  database ??= new Promise((resolve, reject) => {
    const req = indexedDB.open(DATABASE, 1)
    req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: "id" })
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  }).catch((e) => {
    database = null
    throw e
  })

  return database
}

// The ids of the pages that live: they hold their lock, or wait for it.
async function livingPages() {
  const ids = new Set()
  if (typeof navigator === "undefined" || !navigator.locks?.query) return ids

  const { held = [], pending = [] } = await navigator.locks.query().catch(() => ({}))
  for (const { name } of [...held, ...pending]) if (name?.startsWith(LOCK)) ids.add(name.slice(LOCK.length))

  return ids
}

export class OfflineQueue {
  #id = `${Date.now()}-${Math.random().toString(36).slice(2)}`
  #created = Date.now()
  // The writes, in their order: a remove() must not come before the save() that it follows.
  #writes = Promise.resolve()
  #saved = false
  #release = null

  static supported() {
    return typeof indexedDB !== "undefined"
  }

  // Replaces the record of this page. A record that the browser cannot clone (a file that is not
  // there any more) or a full storage logs a warning.
  save(record) {
    this.#saved = true
    this.#lock()
    mark(true)
    this.#write((store) => store.put({ ...record, id: this.#id, created: this.#created, saved: Date.now() }))
  }

  // After the delete, the page releases its lock. A save() that comes before the delete ends
  // takes a new lock.
  remove() {
    if (!this.#saved) return

    this.#saved = false
    const release = this.#release
    this.#release = null
    this.#write((store) => store.delete(this.#id))
    this.#writes = this.#writes.then(() => release?.())
  }

  // The records of the pages of `url` that do not live any more, the oldest first. They leave the
  // database: the page that takes them sends their events. The records of any URL that are older
  // than `maxAge` seconds (0 for no limit) leave it too, with their events.
  async take(url, maxAge = 0) {
    if (!marked()) return []

    const living = await livingPages()
    const db = await open()
    const transaction = db.transaction(STORE, "readwrite")
    const store = transaction.objectStore(STORE)
    const all = await request(store.getAll())
    const expired = (r) => maxAge > 0 && Date.now() - (r.saved ?? r.created) > maxAge * 1000
    const mine = (r) => r.id === this.#id || living.has(r.id)

    const records = []
    let left = 0
    for (const record of all) {
      if (mine(record) || (!expired(record) && record.url !== url)) {
        left++
        continue
      }

      store.delete(record.id)
      if (!expired(record)) records.push(record)
    }

    await done(transaction)

    // A page that saves now holds its lock: its record keeps the mark.
    if (left === 0 && (await livingPages()).size === 0) mark(false)

    return records.sort((a, b) => a.created - b.created)
  }

  // This page leaves (a Turbo visit): a page that comes later can take its record.
  close() {
    this.#release?.()
    this.#release = null
  }

  // The lock tells the other pages that this page lives (see take()).
  #lock() {
    if (this.#release || typeof navigator === "undefined" || !navigator.locks?.request) return

    const held = new Promise((resolve) => (this.#release = resolve))
    navigator.locks.request(LOCK + this.#id, () => held).catch(() => {})
  }

  #write(change) {
    this.#writes = this.#writes
      .then(async () => {
        const transaction = (await open()).transaction(STORE, "readwrite")
        change(transaction.objectStore(STORE))
        await done(transaction)
      })
      .catch((e) => console.warn("[phem] the offline queue could not keep the events", e))
  }
}
