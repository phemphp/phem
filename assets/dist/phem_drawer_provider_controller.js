import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"

// The drawers of a `phem-dialog` with a `swipe-direction` value.
const DRAWER = '[data-controller~="phem-dialog"][data-phem-dialog-swipe-direction-value]'

// The `phem-drawer-provider` controller: the indent of the page while a drawer in it is open, as
// the Provider, the Indent and the IndentBackground of the Base UI drawer.
//
//     <div data-controller="phem-drawer-provider">
//         <div data-phem-drawer-provider-target="background"></div>
//         <div data-phem-drawer-provider-target="indent">
//             ... the page, with drawers (`phem-dialog` with a `swipe-direction` value)
//         </div>
//     </div>
//
// While a drawer in the element is open, the `indent` and `background` targets get `data-active`,
// else `data-inactive`. They also get `--drawer-swipe-progress`: 0, and during the swipe of a
// drawer the part of the way to its close (from 0 to 1), from the `phem-dialog:swipe` events. Use
// them in the CSS, for a page that gets smaller behind the drawer:
//
//     .indent { transition: transform 0.4s; }
//     .indent[data-active] { transform: scale(calc(0.98 + 0.02 * var(--drawer-swipe-progress))); }
export default class PhemDrawerProviderController extends Controller {
  static targets = ["indent", "background"]

  connect() {
    // The open drawers: the drawers that are open at the connect, then the events.
    this.open = new Set([...this.element.querySelectorAll(DRAWER)].filter((el) => el.getAttribute("data-phem-dialog-open-value") === "true"))
    this.progress = 0

    this.state = new ClientState(this, {
      owns: (el, name) => this.#parts.includes(el) && (name === "data-active" || name === "data-inactive" || name === "style"),
      morphed: () => this.#render(),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("phem-dialog:open", this.onOpen, options)
    this.element.addEventListener("phem-dialog:close", this.onClose, options)
    this.element.addEventListener("phem-dialog:swipe", this.onSwipe, options)

    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
  }

  indentTargetConnected() {
    if (this.state) this.#render()
  }

  backgroundTargetConnected() {
    if (this.state) this.#render()
  }

  onOpen = (event) => {
    if (!event.target.matches?.(DRAWER)) return

    this.open.add(event.target)
    this.progress = 0
    this.#render()
  }

  onClose = (event) => {
    if (!event.target.matches?.(DRAWER)) return

    this.open.delete(event.target)
    this.progress = 0
    this.#render()
  }

  onSwipe = (event) => {
    if (!event.target.matches?.(DRAWER)) return

    this.progress = Number(event.detail?.progress) || 0
    this.#render()
  }

  get #parts() {
    return [...this.indentTargets, ...this.backgroundTargets]
  }

  #render() {
    for (const el of this.open) if (!el.isConnected) this.open.delete(el)

    const active = this.open.size > 0
    for (const el of this.#parts) {
      el.toggleAttribute("data-active", active)
      el.toggleAttribute("data-inactive", !active)
      el.style.setProperty("--drawer-swipe-progress", String(this.progress))
    }
  }
}
