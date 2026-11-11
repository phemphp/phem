import { PhemStorage } from "./phem_storage.js"

// The uploads of the files of an input with `data-phem-upload`, before their event (see
// Phem\Upload\UploadController). The event sends a token in the place of each file, and the
// handler gets the file (see Phem\Upload\UploadResolver).
//
//     <input type="file" name="video" data-phem-upload="chunked" />
//     <input type="file" name="photos[]" multiple data-phem-upload="direct" />
//
// - `chunked`: the browser sends the file to the server in chunks (`phem.upload_chunk_size`),
//   one request each. After a network error, it sends the chunk again, with the retries of the
//   phem controller, and it waits while the browser is offline. The id of the upload stays in
//   `localStorage`: after a reload of the page, the same file continues at the end of the last
//   chunk that arrived. The same file has the same fingerprint: a hash of its size, its first and
//   its last bytes.
// - `direct`: the server gives a URL of a storage (see Phem\Upload\DirectUploader), for example a
//   presigned URL of S3, and the browser sends the file there. The file does not go through the
//   server.

// The upload modes of `data-phem-upload`.
export const UPLOAD_MODES = new Set(["chunked", "direct"])

const HEADER = "X-Phem-Upload"
const OFFSET_HEADER = "X-Phem-Upload-Offset"
// The bytes at the start and at the end of a file in its fingerprint.
const FINGERPRINT_BYTES = 65536

// The tokens of the files that went to a storage: an event that the browser sends again does not
// upload them again. A chunked upload ends with its event, so it has no token to keep.
const directTokens = new WeakMap()

// The retries of a request after a network error, a 502 or a 503, as the events of the phem
// controller have them: `retries`, `retryDelay` (ms, doubled at each try), and `waitOnline()`,
// which resolves when the browser is online.
const DEFAULT_OPTIONS = { retries: 3, retryDelay: 500, waitOnline: () => new Promise((resolve) => window.addEventListener("online", resolve, { once: true })) }

// An upload that the server did not take: the event ends with its response (for example a 413).
export class UploadFailed extends Error {
  constructor(response) {
    super(`upload failed (${response.status})`)
    this.response = response
  }
}

// The token of an upload of `file` in `mode`. `onBytes(loaded)` gets the bytes that went up.
// Rejects with UploadFailed, or with a TypeError for a network error.
export async function upload(file, mode, onBytes = () => {}, options = {}) {
  options = { ...DEFAULT_OPTIONS, ...options }

  if (mode !== "direct") return chunked(file, onBytes, options)

  const done = directTokens.get(file)
  if (done) {
    onBytes(file.size)
    return done
  }

  const token = await direct(file, onBytes, options)
  directTokens.set(file, token)

  return token
}

// Sends a body with XHR, so `onProgress(loaded, total)` gets the progress of the upload (`fetch`
// cannot give it). Resolves with a Response, as `fetch()`, or rejects with a TypeError for a
// network error.
export function sendXhr(method, url, headers, body, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest()
    const fail = () => reject(new TypeError("Network request failed"))

    xhr.open(method, url)
    for (const [name, value] of Object.entries(headers)) xhr.setRequestHeader(name, value)
    xhr.responseType = "blob"

    // Before send(): a listener that comes later gets no progress.
    xhr.upload.addEventListener("progress", (e) => onProgress(e.loaded, e.lengthComputable ? e.total : 0))
    xhr.addEventListener("load", () => {
      if (xhr.status < 200) return fail()

      const responseHeaders = new Headers()
      for (const line of xhr.getAllResponseHeaders().split(/\r?\n/)) {
        const colon = line.indexOf(":")
        if (colon > 0) responseHeaders.append(line.slice(0, colon).trim(), line.slice(colon + 1).trim())
      }

      const body = [204, 205, 304].includes(xhr.status) ? null : xhr.response
      resolve(new Response(body, { status: xhr.status, statusText: xhr.statusText, headers: responseHeaders }))
    })

    xhr.addEventListener("error", fail)
    xhr.addEventListener("abort", fail)
    xhr.addEventListener("timeout", fail)

    xhr.send(body)
  })
}

// Sends a request again after a network error, a 502 or a 503, with a longer wait each time, and
// waits while the browser is offline. The last try returns its response, or throws its error.
async function withRetries(send, { retries, retryDelay, waitOnline }) {
  for (let attempt = 0; ; attempt++) {
    while (!navigator.onLine) await waitOnline()

    let response = null
    let error = null
    try {
      response = await send()
    } catch (e) {
      error = e
    }

    const temporary = error !== null || response.status === 502 || response.status === 503
    if (!temporary || attempt >= retries) {
      if (error) throw error
      return response
    }

    let delay = retryDelay * 2 ** attempt
    const retryAfter = Number.parseInt(response?.headers.get("Retry-After") ?? "", 10)
    if (!Number.isNaN(retryAfter)) delay = Math.max(delay, retryAfter * 1000)
    await new Promise((resolve) => setTimeout(resolve, delay))
  }
}

// Starts an upload: the answer of the server, or UploadFailed.
async function start(file, mode, options, extra = {}) {
  const headers = { Accept: "application/json", "Content-Type": "application/json", [HEADER]: "start" }
  const body = JSON.stringify({ mode, name: file.name, type: file.type, size: file.size, ...extra })
  const response = await withRetries(() => fetch(window.location.href, { method: "POST", headers, body }), options)
  if (!response.ok) throw new UploadFailed(response)

  return response.json()
}

// A hash of the size, the first and the last bytes of a file, or null when the browser has no
// Web Crypto (a page that is not secure): then the upload cannot continue after a reload.
async function fingerprint(file) {
  if (!globalThis.crypto?.subtle) return null

  try {
    const head = file.slice(0, FINGERPRINT_BYTES)
    const tail = file.size > FINGERPRINT_BYTES ? file.slice(Math.max(FINGERPRINT_BYTES, file.size - FINGERPRINT_BYTES)) : new Blob()
    const bytes = await new Blob([String(file.size), "\n", head, tail]).arrayBuffer()
    const hash = new Uint8Array(await crypto.subtle.digest("SHA-256", bytes))

    return [...hash].map((b) => b.toString(16).padStart(2, "0")).join("")
  } catch {
    return null
  }
}

async function chunked(file, onBytes, options) {
  // The same file after a reload: its name and its fingerprint.
  const print = await fingerprint(file)
  const storage = print ? new PhemStorage(`upload:${file.name}:${print}`) : null
  const started = await start(file, "chunked", options, { fingerprint: print, resume: storage?.get() ?? null })
  const { id, chunkSize } = started
  let { offset, token } = started
  // An empty file has its token at the start, with no upload to continue.
  if (id) storage?.set(id)
  onBytes(offset)

  while (!token) {
    const headers = { Accept: "application/json", "Content-Type": "application/octet-stream", [HEADER]: id, [OFFSET_HEADER]: String(offset) }
    const body = file.slice(offset, offset + chunkSize)
    const response = await withRetries(() => fetch(window.location.href, { method: "POST", headers, body }), options)
    // 409: the server has other bytes (a chunk that arrived two times): continue at its offset.
    if (!response.ok && response.status !== 409) throw new UploadFailed(response)

    ;({ offset, token } = await response.json())
    onBytes(offset)
  }

  storage?.remove()

  return token
}

async function direct(file, onBytes, options) {
  const { url, method, headers, token } = await start(file, "direct", options)
  const response = await withRetries(() => sendXhr(method, url, headers, file, (loaded) => onBytes(Math.min(loaded, file.size))), options)
  if (!response.ok) throw new UploadFailed(response)
  onBytes(file.size)

  return token
}
