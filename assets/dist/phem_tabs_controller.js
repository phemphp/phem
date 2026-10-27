import { Controller } from "@hotwired/stimulus"

// The `phem-tabs` controller: shows the `panel` target of the selected `tab` target on the client,
// with the ARIA roles and the keyboard of a tab list. The selected tab stays after the morph of an
// event.
//
//     <div data-controller="phem-tabs">
//         <div data-phem-tabs-target="list" aria-label="Settings">
//             <button id="tab-profile" data-phem-tabs-target="tab">Profile</button>
//             <button id="tab-security" data-phem-tabs-target="tab">Security</button>
//         </div>
//         <div id="profile" data-phem-tabs-target="panel">…</div>
//         <div id="security" data-phem-tabs-target="panel" hidden>…</div>
//     </div>
//
// The `index` value is the state: the server render gives its first value. A click on a tab, or an
// arrow key, Home or End on a tab, selects it. The `list` target gets `role="tablist"`, the tabs get
// `role="tab"`, `aria-selected`, `tabindex` and `aria-controls`, and the panels get
// `role="tabpanel"`, `hidden`, `tabindex` and `aria-labelledby`. The morph removes the attributes
// that the server render does not have: the controller sets them again after the morph. The morph
// does not change the `index` value: it belongs to the client. A server render with a new `index`
// value sets the state.
export default class PhemTabsController extends Controller {
  static targets = ["list", "tab", "panel"]
  static values = { index: Number }

  connect() {
    // The last `index` value of the server render.
    this.server = this.element.getAttribute(this.#indexAttribute)

    // The morph of the controller element: a new server value of `index` sets the state.
    this.readServer = (event) => {
      if (event.target !== this.element) return

      const next = event.detail.newElement?.getAttribute(this.#indexAttribute) ?? null
      this.serverChanged = next !== this.server
      this.server = next
    }
    this.element.addEventListener("turbo:before-morph-element", this.readServer)

    this.keepState = (event) => {
      if (event.target === this.element && event.detail.attributeName === this.#indexAttribute && !this.serverChanged) event.preventDefault()
    }
    this.element.addEventListener("turbo:before-morph-attribute", this.keepState)

    // The morph of a target, or of the controller element after its children.
    this.renderAgain = (event) => {
      if (event.target === this.element || this.#isTarget(event.target)) this.#render()
    }
    this.element.addEventListener("turbo:morph-element", this.renderAgain)

    this.element.addEventListener("click", this.onClick)
    this.element.addEventListener("keydown", this.onKeydown)
  }

  disconnect() {
    this.element.removeEventListener("turbo:before-morph-element", this.readServer)
    this.element.removeEventListener("turbo:before-morph-attribute", this.keepState)
    this.element.removeEventListener("turbo:morph-element", this.renderAgain)
    this.element.removeEventListener("click", this.onClick)
    this.element.removeEventListener("keydown", this.onKeydown)
  }

  // Stimulus calls indexValueChanged() in a mutation observer, and before connect().
  indexValueChanged() {
    this.#render()
  }

  // A target that the morph adds gets the state.
  listTargetConnected() {
    this.#render()
  }

  tabTargetConnected() {
    this.#render()
  }

  panelTargetConnected() {
    this.#render()
  }

  onClick = (event) => {
    const tab = this.#tabOf(event.target)
    if (tab && !this.#disabled(tab)) this.#select(tab)
  }

  // Automatic activation: the arrow keys move the focus and select the tab. The arrow keys follow
  // the `aria-orientation` of the list (horizontal by default), and they skip a disabled tab.
  onKeydown = (event) => {
    const tab = this.#tabOf(event.target)
    if (!tab || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) return

    const tabs = this.tabTargets.filter((el) => !this.#disabled(el) || el === tab)
    const vertical = this.listTargets[0]?.getAttribute("aria-orientation") === "vertical"
    const at = tabs.indexOf(tab)
    const keys = {
      [vertical ? "ArrowDown" : "ArrowRight"]: (at + 1) % tabs.length,
      [vertical ? "ArrowUp" : "ArrowLeft"]: (at - 1 + tabs.length) % tabs.length,
      Home: 0,
      End: tabs.length - 1,
    }
    if (!(event.key in keys)) return

    event.preventDefault()
    const next = tabs[keys[event.key]]
    if (this.#disabled(next)) return

    this.#select(next)
    next.focus()
  }

  #select(tab) {
    this.indexValue = this.tabTargets.indexOf(tab)
    this.#render()
  }

  // The `index` value of a server render that has less tabs selects the last tab.
  get #selected() {
    return Math.max(0, Math.min(this.indexValue, this.tabTargets.length - 1))
  }

  // The attributes that are not the state (`aria-controls`, `aria-labelledby` and the `tabindex` of
  // a panel) stay when the server render gives them.
  #render() {
    const tabs = this.tabTargets
    const panels = this.panelTargets
    const selected = this.#selected

    for (const el of this.listTargets) el.setAttribute("role", "tablist")

    tabs.forEach((tab, i) => {
      tab.setAttribute("role", "tab")
      tab.setAttribute("aria-selected", String(i === selected))
      tab.tabIndex = i === selected ? 0 : -1
      if (panels[i]?.id && !tab.hasAttribute("aria-controls")) tab.setAttribute("aria-controls", panels[i].id)
    })

    panels.forEach((panel, i) => {
      panel.setAttribute("role", "tabpanel")
      panel.hidden = i !== selected
      if (!panel.hasAttribute("tabindex")) panel.tabIndex = 0
      if (tabs[i]?.id && !panel.hasAttribute("aria-labelledby")) panel.setAttribute("aria-labelledby", tabs[i].id)
    })
  }

  #tabOf(el) {
    return this.tabTargets.find((tab) => tab.contains(el))
  }

  #disabled(tab) {
    return tab.disabled || tab.getAttribute("aria-disabled") === "true"
  }

  #isTarget(el) {
    return this.listTargets.includes(el) || this.tabTargets.includes(el) || this.panelTargets.includes(el)
  }

  get #indexAttribute() {
    return `data-${this.identifier}-index-value`
  }
}
