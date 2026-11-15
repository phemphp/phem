import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { render, show } from "./phem_transition.js"

// The `phem-avatar` controller: shows the `image` target when it loads, else the `fallback`
// target.
//
//     <span data-controller="phem-avatar" data-phem-avatar-delay-value="600">
//         <img data-phem-avatar-target="image" src="/avatars/ada.png" alt="Ada Lovelace">
//         <span data-phem-avatar-target="fallback">AL</span>
//     </span>
//
// The image is hidden until it loads. Its load status is `idle` (no image), `loading`, `loaded` or
// `error` (an image that does not load, or an image with no `src`). An image that the browser has
// already (in its cache) shows at once. A new `src` or `srcset`, for example in the server render
// of an event, loads the image again. The fallback shows while the status is not `loaded`, after
// the `delay` value (in milliseconds, 0 by default) from the connection of the controller: a fast
// image does not show the fallback for a short time.
//
// The image gets `data-loading` while it loads and `data-error` when it does not load. When it
// loads, it gets `data-open` and `data-starting-style` for its first frame; see phem_transition.js
// for the animations. A hidden image or fallback has `hidden`. Each change of the status
// dispatches `phem-avatar:status`, with the `status` in its detail.
//
// The image loads while it is hidden, so do not give it `loading="lazy"`: the browser does not load
// a lazy image that is not shown.
export default class PhemAvatarController extends Controller {
  static targets = ["image", "fallback"]
  static values = { delay: Number }

  connect() {
    this.status = "idle"
    this.delayed = this.delayValue <= 0
    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render({ animate: false }),
    })
    this.state.connect()

    // The `load` and `error` events of an image do not bubble: the controller reads them in the
    // capture phase.
    this.listening = new AbortController()
    const options = { signal: this.listening.signal, capture: true }
    this.element.addEventListener("load", this.onLoad, options)
    this.element.addEventListener("error", this.onError, options)

    // A new `src` loads the image again.
    this.observer = new MutationObserver((records) => {
      if (records.some((record) => this.imageTargets.includes(record.target))) this.#probe()
    })
    this.observer.observe(this.element, { subtree: true, attributes: true, attributeFilter: ["src", "srcset"] })

    if (!this.delayed) {
      this.timer = setTimeout(() => {
        this.delayed = true
        this.#render({ animate: false })
      }, this.delayValue)
    }

    this.#probe()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.observer.disconnect()
    clearTimeout(this.timer)
  }

  imageTargetConnected() {
    if (this.state) this.#probe()
  }

  imageTargetDisconnected() {
    if (this.state) this.#probe()
  }

  fallbackTargetConnected() {
    if (this.state) this.#render({ animate: false })
  }

  onLoad = (event) => {
    if (event.target === this.#image) this.#change("loaded", { animate: true })
  }

  onError = (event) => {
    if (event.target === this.#image) this.#change("error", { animate: false })
  }

  // The status of the image now: an image in the cache is `loaded` at once.
  #probe() {
    const image = this.#image

    if (!image) this.#change("idle", { animate: false })
    else if (!image.getAttribute("src") && !image.getAttribute("srcset")) this.#change("error", { animate: false })
    else if (image.complete && image.naturalWidth > 0) this.#change("loaded", { animate: false })
    else if (image.complete) this.#change("error", { animate: false })
    else this.#change("loading", { animate: false })
  }

  #change(status, { animate }) {
    const changed = status !== this.status
    this.status = status
    this.#render({ animate })

    if (changed) this.dispatch("status", { detail: { status } })
  }

  #render({ animate }) {
    const loaded = this.status === "loaded"

    for (const image of this.imageTargets) {
      const shown = loaded && image === this.#image
      image.toggleAttribute("data-loading", this.status === "loading" && image === this.#image)
      image.toggleAttribute("data-error", this.status === "error" && image === this.#image)

      if (shown && animate && image.hidden) show(image)
      else render(image, shown)
    }

    for (const fallback of this.fallbackTargets) render(fallback, !loaded && this.delayed)
  }

  // The image of the avatar: the first `image` target.
  get #image() {
    return this.imageTargets[0]
  }

  // The attributes of the state.
  #owns(el, name) {
    if (this.imageTargets.includes(el)) return OPEN_ATTRIBUTES.has(name) || name === "data-loading" || name === "data-error"
    if (this.fallbackTargets.includes(el)) return OPEN_ATTRIBUTES.has(name)

    return false
  }
}
