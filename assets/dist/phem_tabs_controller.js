import { Controller } from "@hotwired/stimulus"
import { ClientState, OPEN_ATTRIBUTES } from "./phem_client_state.js"
import { isDisabled, movesFocus, nextItem } from "./phem_roving.js"
import { hide, render, show } from "./phem_transition.js"

// The `phem-tabs` controller: shows the `panel` target of the selected `tab` target on the client,
// with the ARIA roles and the keyboard of a tab list. The selected tab stays after the morph of an
// event.
//
//     <div data-controller="phem-tabs">
//         <div data-phem-tabs-target="list" aria-label="Settings">
//             <button id="tab-profile" data-phem-tabs-target="tab">Profile</button>
//             <button id="tab-security" data-phem-tabs-target="tab">Security</button>
//             <span data-phem-tabs-target="indicator"></span>
//         </div>
//         <div id="profile" data-phem-tabs-target="panel">…</div>
//         <div id="security" data-phem-tabs-target="panel" hidden>…</div>
//     </div>
//
// The `index` value is the state: the server render gives its first value, and a server render
// with a new `index` value sets it. A click on a tab selects it. The arrow keys of the
// `orientation` value (`horizontal` by default) move the focus between the tabs, and skip a
// disabled tab; Home and End go to the first and to the last tab. With the `loopFocus` value (the
// default), the last tab goes to the first one. Enter or Space selects the tab with the focus, or
// the focus selects it at once with the `activateOnFocus` value.
//
// The `list` target gets `role="tablist"`, the tabs get `role="tab"`, `aria-selected`, `tabindex`
// and `aria-controls`, and the panels get `role="tabpanel"`, `hidden`, `tabindex` and
// `aria-labelledby`. For the styles, all the parts get `data-orientation` and
// `data-activation-direction` (the direction of the last change: `left`, `right`, `up`, `down` or
// `none`). The selected tab gets `data-active`, a disabled tab `data-disabled`, and a panel
// `data-index` and `data-hidden` when it is hidden; see phem_transition.js for the animations of
// the panels. The `indicator` target gets the box of the selected tab in the list:
// `--active-tab-left`, `--active-tab-top`, `--active-tab-right`, `--active-tab-bottom`,
// `--active-tab-width` and `--active-tab-height`.
export default class PhemTabsController extends Controller {
  static targets = ["list", "tab", "panel", "indicator"]
  static values = {
    index: Number,
    orientation: { type: String, default: "horizontal" },
    activateOnFocus: Boolean,
    loopFocus: { type: Boolean, default: true },
  }

  connect() {
    this.direction = "none"
    this.state = new ClientState(this, {
      value: "index",
      owns: (el, name) => this.#owns(el, name),
      morphed: () => this.#render({ animate: false }),
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("keydown", this.onKeydown, options)

    if (typeof ResizeObserver === "function") {
      this.resizing = new ResizeObserver(() => this.#renderIndicator())
      this.listening.signal.addEventListener("abort", () => this.resizing.disconnect())

      // Stimulus connects the first targets before connect().
      for (const el of [...this.listTargets, ...this.tabTargets]) this.resizing.observe(el)
    }

    this.#render({ animate: false })
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.rendered = undefined
  }

  // A server render with a new `index` value, or a change of the value by a script.
  indexValueChanged(index, previous) {
    if (this.rendered === undefined || this.rendered === this.#selected) return

    this.#direction(previous ?? this.rendered, index)
    this.#render({ animate: true })
  }

  // A target that the morph adds gets the state.
  listTargetConnected(el) {
    this.resizing?.observe(el)
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  tabTargetConnected(el) {
    this.resizing?.observe(el)
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  panelTargetConnected() {
    if (this.rendered !== undefined) this.#render({ animate: false })
  }

  indicatorTargetConnected() {
    if (this.rendered !== undefined) this.#renderIndicator()
  }

  onClick = (event) => {
    const tab = this.#tabOf(event.target)
    if (tab && !isDisabled(tab)) this.#select(tab)
  }

  onKeydown = (event) => {
    const tab = this.#tabOf(event.target)
    if (!tab || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return

    // A button selects on its click. Another element selects on Enter and Space.
    if ((event.key === "Enter" || event.key === " ") && !tab.matches("button")) {
      event.preventDefault()
      if (!isDisabled(tab)) this.#select(tab)

      return
    }

    if (!movesFocus(event.key, this.orientationValue)) return

    const next = nextItem(this.tabTargets, tab, event.key, { orientation: this.orientationValue, loop: this.loopFocusValue, rtl: this.#rtl })
    event.preventDefault()
    if (!next) return

    next.focus()
    if (this.activateOnFocusValue) this.#select(next)
  }

  #select(tab) {
    const index = this.tabTargets.indexOf(tab)
    if (index === this.#selected && this.rendered === index) return

    this.#direction(this.#selected, index)
    this.indexValue = index
    this.#render({ animate: true })
  }

  // The direction of the change, from the boxes of the two tabs.
  #direction(from, to) {
    const a = this.tabTargets[from]?.getBoundingClientRect()
    const b = this.tabTargets[to]?.getBoundingClientRect()
    if (!a || !b || from === to) return

    if (this.orientationValue === "vertical") this.direction = b.top >= a.top ? "down" : "up"
    else this.direction = (b.left >= a.left) ? "right" : "left"
  }

  // The `index` value of a server render that has less tabs selects the last tab.
  get #selected() {
    return Math.max(0, Math.min(this.indexValue, this.tabTargets.length - 1))
  }

  // The attributes that are not the state (`aria-controls`, `aria-labelledby` and the `tabindex` of
  // a panel) stay when the server render gives them.
  #render({ animate }) {
    const tabs = this.tabTargets
    const panels = this.panelTargets
    const selected = this.#selected
    const orientation = this.orientationValue
    this.rendered = selected

    for (const el of [this.element, ...this.listTargets, ...this.indicatorTargets]) this.#common(el)

    for (const el of this.listTargets) {
      el.setAttribute("role", "tablist")
      if (orientation === "vertical") el.setAttribute("aria-orientation", "vertical")
      else el.removeAttribute("aria-orientation")
    }

    tabs.forEach((tab, i) => {
      this.#common(tab)
      tab.setAttribute("role", "tab")
      tab.setAttribute("aria-selected", String(i === selected))
      tab.toggleAttribute("data-active", i === selected)
      tab.toggleAttribute("data-disabled", isDisabled(tab))
      tab.tabIndex = i === selected ? 0 : -1
      if (panels[i]?.id && !tab.hasAttribute("aria-controls")) tab.setAttribute("aria-controls", panels[i].id)
    })

    panels.forEach((panel, i) => {
      const open = i === selected
      this.#common(panel)
      panel.setAttribute("role", "tabpanel")
      panel.setAttribute("data-index", String(i))
      panel.toggleAttribute("data-hidden", !open)
      if (!panel.hasAttribute("tabindex")) panel.tabIndex = 0
      if (tabs[i]?.id && !panel.hasAttribute("aria-labelledby")) panel.setAttribute("aria-labelledby", tabs[i].id)

      if (!animate) render(panel, open)
      else if (open) show(panel)
      else if (!panel.hidden) hide(panel)
    })

    this.#renderIndicator()
  }

  #common(el) {
    el.setAttribute("data-orientation", this.orientationValue)
    el.setAttribute("data-activation-direction", this.direction)
  }

  // The box of the selected tab, in the box of the list.
  #renderIndicator() {
    const tab = this.tabTargets[this.#selected]
    const list = this.listTargets[0] ?? this.element
    if (!tab || this.indicatorTargets.length === 0) return

    const box = tab.getBoundingClientRect()
    const parent = list.getBoundingClientRect()
    const left = box.left - parent.left + list.scrollLeft
    const top = box.top - parent.top + list.scrollTop
    const values = {
      "--active-tab-left": left,
      "--active-tab-top": top,
      "--active-tab-right": list.scrollWidth - left - box.width,
      "--active-tab-bottom": list.scrollHeight - top - box.height,
      "--active-tab-width": box.width,
      "--active-tab-height": box.height,
    }

    for (const el of this.indicatorTargets) {
      for (const [name, value] of Object.entries(values)) el.style.setProperty(name, `${Math.round(value)}px`)
    }
  }

  // The attributes of the state.
  #owns(el, name) {
    if (name === "data-activation-direction") return el === this.element || this.#isPart(el)
    if (this.tabTargets.includes(el)) return ["aria-selected", "tabindex", "data-active"].includes(name)
    if (this.panelTargets.includes(el)) return OPEN_ATTRIBUTES.has(name) || name === "data-hidden"

    return false
  }

  #tabOf(el) {
    return this.tabTargets.find((tab) => tab.contains(el))
  }

  #isPart(el) {
    return this.listTargets.includes(el) || this.tabTargets.includes(el) || this.panelTargets.includes(el) || this.indicatorTargets.includes(el)
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }
}
