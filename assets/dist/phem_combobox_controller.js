import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, nextItem } from "./phem_roving.js"

// Each part with no id gets one, for `aria-controls` and `aria-activedescendant`. The morph
// removes an id that the server render does not have: the part gets the same id again.
let count = 0
const ids = new WeakMap()

// The `phem-combobox` controller: a text field with a list of suggestions that the server gives,
// for a search, a city or a user. The list stays open after the morph of an event.
//
//     <div data-controller="phem-combobox">
//         <input data-phem-combobox-target="input" phem:bind={{ query }} phem:debounce={{ 200 }} aria-label="City" />
//         <div data-phem-combobox-target="popup" hidden>
//             <ul data-phem-combobox-target="list">
//                 {% for city in results %}
//                     <li key={{ city.id }} data-phem-combobox-target="item" onClick={{ () => choose(city.id) }}>{{ city.name }}</li>
//                 {% endfor %}
//             </ul>
//             <p data-phem-combobox-target="empty">No city.</p>
//         </div>
//     </div>
//
// The server filters: the input sends the text (with `phem:bind` or `onInput`), and the render of
// the server gives the items. The controller adds the roles and the keyboard. The popup opens when
// the user types, on ArrowDown or ArrowUp, and on a click on the `trigger` target. It shows when
// it has an item, an `empty` target or a `status` target. The focus stays in the input: ArrowDown
// and ArrowUp move the highlight (from the last item to the first one with the `loopFocus` value),
// and Enter chooses the highlighted item. With the `autoHighlight` value, the first item gets the
// highlight when the items change. The pointer on an item highlights it.
//
// To choose an item is a click on it: its handler (`onClick`) runs, or its link opens. The handler
// sets the state (the text of the input, the selected value), and the morph shows it. Then the popup
// closes, and the controller dispatches `phem-combobox:select` with the `item` in the detail.
// Escape closes the popup, and a second Escape clears the input. A click on the `clear` target
// clears the input. A click outside and the focus out of the combobox close the popup.
//
// The input gets `role="combobox"`, `aria-autocomplete="list"`, `aria-expanded`, `aria-controls`
// and `aria-activedescendant`. The list (or the popup, with no list) gets `role="listbox"`, the
// items `role="option"` and an id, and the highlighted item `aria-selected="true"` and
// `data-highlighted`. The popup and the list get `data-empty` when there is no item, and the
// `empty` targets show only then. A `status` target gets `role="status"`: a screen reader reads
// it (for example "3 results"). The popup is positioned as a `phem-popover` (phem_position.js),
// below the input by default: use `var(--anchor-width)` for its width.
export default class PhemComboboxController extends Controller {
  static targets = ["input", "trigger", "clear", "popup", "list", "item", "empty", "status"]
  static values = {
    autoHighlight: Boolean,
    loopFocus: { type: Boolean, default: true },
    ...positionValues("bottom", "start"),
  }

  initialize() {
    // The user wants the popup: they typed, or pressed an arrow key.
    this.wanted = false
    // The id of the highlighted item: it stays through the morph.
    this.activeId = null
    this.popup = new Popup({
      anchor: () => (this.hasInputTarget ? this.inputTarget : null),
      popup: () => (this.hasPopupTarget ? this.popupTarget : null),
      options: () => positionOptions(this),
      inside: () => [this.element, ...this.popupTargets],
      dismiss: () => this.#dismiss(),
    })
  }

  connect() {
    this.state = new ClientState(this, {
      owns: (el, name) => this.#owns(el, name),
      morphed: () => {
        this.#render()
        this.popup.reposition()
      },
    })
    this.state.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("input", this.onInput, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("pointerdown", this.onPointerDown, options)
    this.element.addEventListener("pointerover", this.onPointerOver, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)

    this.popup.render(false)
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.popup.destroy()
  }

  // A target that the morph adds or removes: one render after all the changes.
  itemTargetConnected() {
    this.#later()
  }

  itemTargetDisconnected() {
    this.#later()
  }

  emptyTargetConnected() {
    this.#later()
  }

  statusTargetConnected() {
    this.#later()
  }

  inputTargetConnected() {
    this.#later()
  }

  onInput = (event) => {
    if (event.target !== this.#input) return

    this.wanted = true
    this.activeId = null
    this.#render()
  }

  onKeydown = (event) => {
    if (event.target !== this.#input || event.defaultPrevented || event.isComposing) return

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault()
      const opening = !this.popup.open
      this.wanted = true
      const items = this.#items
      const current = opening ? null : this.#active
      this.#highlight(nextItem(items, current, event.key, { loop: this.loopFocusValue }) ?? current)

      return
    }

    if (event.key === "Enter") {
      const item = this.popup.open ? this.#active : null
      if (!item) return

      event.preventDefault()
      item.click()

      return
    }

    // Escape with no open popup clears the input. Escape on an open popup closes it (see
    // phem_dismiss.js).
    if (event.key === "Escape" && !this.popup.open && this.#input.value !== "") {
      event.preventDefault()
      this.#clear()
    }
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const item = this.#itemOf(event.target)
    if (item) {
      if (isDisabled(item)) return

      this.wanted = false
      this.activeId = null
      this.#render()
      this.#input?.focus({ preventScroll: true })
      this.dispatch("select", { detail: { item } })

      return
    }

    if (this.clearTargets.some((el) => el.contains(event.target))) {
      this.#clear()
      this.#input?.focus()

      return
    }

    if (this.triggerTargets.some((el) => el.contains(event.target))) {
      this.wanted = !this.popup.open
      this.#render()
      this.#input?.focus()
    }
  }

  // A press on an item does not move the focus out of the input.
  onPointerDown = (event) => {
    if (this.#itemOf(event.target) || this.triggerTargets.some((el) => el.contains(event.target))) event.preventDefault()
  }

  onPointerOver = (event) => {
    const item = this.#itemOf(event.target)
    if (item && !isDisabled(item)) this.#highlight(item)
  }

  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (to instanceof Node && (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to)))) return

    this.#dismiss()
  }

  #dismiss() {
    if (!this.wanted && !this.popup.open) return

    this.wanted = false
    this.activeId = null
    this.#render()
  }

  // An empty input sends its event, as when the user deletes the text.
  #clear() {
    const input = this.#input
    if (!input) return

    input.value = ""
    input.dispatchEvent(new Event("input", { bubbles: true }))
  }

  #highlight(item) {
    this.activeId = item ? this.#idOf(item) : null
    this.#render()
    item?.scrollIntoView?.({ block: "nearest" })
  }

  #later() {
    if (this.pending) return

    this.pending = true
    queueMicrotask(() => {
      this.pending = false
      if (this.listening) this.#render()
    })
  }

  // The state: the open popup, the highlight and the ARIA attributes.
  #render() {
    const input = this.#input
    const list = this.listTargets[0] ?? this.popupTargets[0] ?? null
    const items = this.#items
    const empty = items.length === 0

    if (list) {
      if (!list.hasAttribute("role")) list.setAttribute("role", "listbox")
      if (!list.id) list.id = this.listId ??= `phem-combobox-${++count}`
    }

    for (const el of [...this.popupTargets, ...this.listTargets]) el.toggleAttribute("data-empty", empty)
    for (const el of this.emptyTargets) el.hidden = !empty
    for (const el of this.statusTargets) if (!el.hasAttribute("role")) el.setAttribute("role", "status")

    // The highlight stays on the item with the same id, or goes to the first item.
    let active = items.find((el) => el.id === this.activeId && !isDisabled(el)) ?? null
    if (!active && this.autoHighlightValue && this.wanted) active = nextItem(items, null, "Home") ?? null
    this.activeId = active?.id ?? null

    for (const item of items) {
      if (!item.hasAttribute("role")) item.setAttribute("role", "option")
      this.#idOf(item)
      item.setAttribute("aria-selected", String(item === active))
      item.toggleAttribute("data-highlighted", item === active)
      item.toggleAttribute("data-disabled", isDisabled(item))
    }

    const content = !empty || this.emptyTargets.length > 0 || this.statusTargets.length > 0
    const open = this.wanted && content

    if (input) {
      if (!input.hasAttribute("role")) input.setAttribute("role", "combobox")
      if (!input.hasAttribute("aria-autocomplete")) input.setAttribute("aria-autocomplete", "list")
      if (!input.hasAttribute("autocomplete")) input.setAttribute("autocomplete", "off")
      if (list && !input.hasAttribute("aria-controls")) input.setAttribute("aria-controls", list.id)
      input.setAttribute("aria-expanded", String(open))
      if (active && open) input.setAttribute("aria-activedescendant", active.id)
      else input.removeAttribute("aria-activedescendant")
      input.toggleAttribute("data-popup-open", open)
    }

    if (open !== this.popup.open) open ? this.popup.show() : this.popup.hide()
  }

  // The attributes of the state.
  #owns(el, name) {
    if (this.popup.owns(el, name)) return true
    if (el === this.#input) return ["aria-expanded", "aria-activedescendant", "data-popup-open"].includes(name)
    if (this.itemTargets.includes(el)) return name === "aria-selected" || name === "data-highlighted"
    if (this.emptyTargets.includes(el)) return name === "hidden"

    return false
  }

  #idOf(item) {
    if (item.id) return item.id

    if (!ids.has(item)) ids.set(item, `phem-combobox-option-${++count}`)
    item.id = ids.get(item)

    return item.id
  }

  get #input() {
    return this.inputTargets[0] ?? null
  }

  get #items() {
    return this.itemTargets.filter((el) => !el.hidden)
  }

  get #active() {
    return this.#items.find((el) => el.id === this.activeId) ?? null
  }

  #itemOf(el) {
    return this.itemTargets.find((item) => item.contains(el))
  }
}
