import { Controller } from "@hotwired/stimulus"
import { ClientState } from "./phem_client_state.js"
import { realId } from "./phem_ids.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, nextItem } from "./phem_roving.js"
import { link } from "./phem_aria.js"

// The collators of the client filter, by locale.
const collators = new Map()

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
// and ArrowUp move the highlight. With the `loopFocus` value, a move after the last item goes back to
// the input (no highlight), and the next move goes to the first item (with `autoHighlight`, it
// goes to the first item at once),
// and Enter chooses the highlighted item. With the `autoHighlight` value, the first item gets the
// highlight when the items change. A move of the pointer on an item highlights it: an item that
// comes under a pointer that does not move (the list changes) does not get the highlight.
//
// To choose an item is a click on it: its handler (`onClick`) runs, or its link opens. The handler
// sets the state (the text of the input, the selected value), and the morph shows it. Then the popup
// closes, and the controller dispatches `phem-combobox:select` with the `item` in the detail.
// Escape closes the popup, and a second Escape clears the input. A click on the `clear` target
// clears the input. A click outside and the focus out of the combobox close the popup.
//
// The input gets `role="combobox"`, `aria-autocomplete="list"`, `aria-expanded`, `aria-controls`
// and `aria-activedescendant`. The list (or the popup, with no list) gets `role="listbox"`, the
// items `role="option"`, and the highlighted item `aria-selected="true"` and `data-highlighted`.
// A list or an item with no `id` gets no id: the input points to it with an element reference
// (`ariaControlsElements`, `ariaActiveDescendantElement`). The popup and the list get `data-empty` when there is no item, and the
// `empty` targets show only then. A `status` target gets `role="status"`: a screen reader reads
// it (for example "3 results"). The popup is positioned as a `phem-popover` (phem_position.js),
// below the input by default: use `var(--anchor-width)` for its width.
//
// With the `multiple` value, the user chooses more than one item. The server gives the chosen
// items `aria-selected="true"`, and the list gets `aria-multiselectable="true"`. A choice keeps
// the popup open and the highlight. The `chip` targets show the chosen values, each with a
// `chipRemove` target whose handler removes its value. ArrowLeft at the start of the input goes
// to the last chip, ArrowLeft and ArrowRight move between the chips and back to the input, and
// Backspace or Delete on a chip clicks its `chipRemove`. Backspace in an empty input clicks the
// `chipRemove` of the last chip. The chips and their `chipRemove` targets get `tabindex="-1"`,
// and a chosen item gets `data-selected`.
//
// With the `filter` value (`contains` or `starts-with`), the browser filters the items that the
// server rendered one time: an item that does not match the text of the input gets `hidden`, and
// a `group` target with no visible item too. The match ignores the case and the accents
// (`Intl.Collator` with the `locale` value, or the language of the page). The label of an item is
// its `data-label` attribute, or its text. The `limit` value shows only the first matches.
//
// The `mode` value is the `aria-autocomplete` of the input: `list` (the default), `both`,
// `inline` or `none`. With `both` and `inline`, the arrow keys write the label of the highlighted
// item in the input, and Escape gives back the text of the user. With `inline` and `none`, the
// client filter is off. With the `filter` value or a `mode` other than `list`, the list belongs to
// the client: a choice also writes the label of the item in the input, and sends its `input` and
// `change` events. The text of an input with no `phem:bind` then stays through the morph.
//
// With the `selectionMode` value `single` and a list of the client, the combobox has one selected
// item, as the Combobox of Base UI. A click on an item selects it: the item gets `data-selected`
// and `aria-selected="true"`, and a `value` target (a hidden input) gets its `data-value`. The
// first selection is the item that the server renders with `data-selected`. The input has no free
// text: when the popup closes, the input gets the label of the selected item again, or no text.
// When the popup opens with the label of the selection in the input, the list shows all the
// items, until the user types. A click on the `clear` target also removes the selection.
//
// The `clear` targets show only when there is something to clear: a selected item with the
// `single` selection mode, a chip with the `multiple` value, and else a text in the input. A
// hidden `clear` target has `hidden`.
//
// With the `openOnInputClick` value, a click on the input opens the popup. With an `inputGroup`
// target (the box around the input and its buttons), the popup is positioned against it.
//
// While an event of phem runs, the input gets `aria-busy` (see the loading state of phem): the
// popup gets it too.
export default class PhemComboboxController extends Controller {
  static targets = ["input", "inputGroup", "value", "trigger", "clear", "popup", "list", "item", "group", "empty", "status", "chip", "chipRemove"]
  static values = {
    autoHighlight: Boolean,
    multiple: Boolean,
    loopFocus: { type: Boolean, default: true },
    filter: String,
    mode: { type: String, default: "list" },
    limit: { type: Number, default: -1 },
    locale: String,
    selectionMode: { type: String, default: "none" },
    openOnInputClick: Boolean,
    ...positionValues("bottom", "start"),
  }

  initialize() {
    // The user wants the popup: they typed, or pressed an arrow key.
    this.wanted = false
    // The highlighted item. It stays through the morph: the same element, or an item with its id.
    this.highlighted = null
    // The text of the user while the input shows the label of the highlighted item (an inline
    // `mode`), or null.
    this.typed = null
    // The items and the groups that the client filter hid.
    this.filtered = new WeakSet()
    // The selected item of the `single` selection mode. It stays through the morph: the same
    // element, or an item with its id. `undefined` until the first render reads the server render.
    this.selected = undefined
    // The user changed the text of the input after the popup opened.
    this.queryChanged = false
    // The `data-selected` of each item in the last server render, and the selection of a server
    // render that changed it (`undefined`: no change).
    this.serverSelected = new WeakMap()
    this.serverSelection = undefined
    this.popup = new Popup({
      anchor: () => this.inputGroupTargets[0] ?? (this.hasInputTarget ? this.inputTarget : null),
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
        this.#takeServerSelection()
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
    this.element.addEventListener("pointermove", this.onPointerMove, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("turbo:before-morph-element", this.onBeforeMorph, options)

    // The loading state of phem on the input goes to the popup.
    this.busy = new MutationObserver((records) => {
      if (records.some((record) => record.target === this.#input)) this.#render()
    })
    this.busy.observe(this.element, { attributes: true, attributeFilter: ["aria-busy"], subtree: true })

    this.popup.render(false)
    this.#render()
  }

  disconnect() {
    this.state.disconnect()
    this.listening.abort()
    this.busy.disconnect()
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

  chipTargetConnected() {
    this.#later()
  }

  chipRemoveTargetConnected() {
    this.#later()
  }

  onInput = (event) => {
    if (event.target !== this.#input || this.filling) return

    this.typed = null
    delete event.target.phemValue
    this.wanted = true
    this.queryChanged = true
    this.highlighted = null
    this.#render()
  }

  onKeydown = (event) => {
    if (event.defaultPrevented || event.isComposing) return

    const chip = this.#chipOf(event.target)
    if (chip) {
      this.#chipKeydown(event, chip)

      return
    }

    if (event.target !== this.#input) return

    if (this.#chipsKeydown(event)) return

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault()
      const opening = !this.popup.open
      this.wanted = true
      const items = this.#items
      const current = opening ? null : this.#active
      // With `autoHighlight`, an item always has the highlight: the keys go from the last item
      // to the first one. Else, after the last item (or before the first one), the highlight goes
      // back to the input.
      let next = nextItem(items, current, event.key, { loop: this.loopFocusValue && this.autoHighlightValue })
      if (!next && current && !this.loopFocusValue) next = current
      this.#highlight(next)
      this.#complete(next)

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

    if (this.chipRemoveTargets.some((el) => el.contains(event.target))) return

    const item = this.#itemOf(event.target)
    if (item) {
      if (isDisabled(item)) return

      // A choice of more than one item keeps the popup open, with its highlight.
      if (!this.multipleValue) {
        this.wanted = false
        this.queryChanged = false
        this.highlighted = null
        this.typed = null
        if (this.#input) delete this.#input.phemValue
        if (this.#selecting) this.#select(item)
        if (this.#clientList) this.#fill(this.#labelOf(item))
      }

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

      return
    }

    if (this.openOnInputClickValue && event.target === this.#input && !this.popup.open) {
      this.wanted = true
      this.#render()
    }
  }

  // A press on an item, a trigger, a clear button or the remove button of a chip does not move the
  // focus out of the input.
  onPointerDown = (event) => {
    const parts = [...this.triggerTargets, ...this.clearTargets, ...this.chipRemoveTargets]
    if (this.#itemOf(event.target) || parts.some((el) => el.contains(event.target))) event.preventDefault()
  }

  // A move of the pointer highlights the item under it. The browser also sends `pointerover` when
  // an item comes under a pointer that does not move (the list changes or scrolls): that item does
  // not take the highlight of the keyboard.
  onPointerMove = (event) => {
    // The moves on the highlighted item: no lookup of the items.
    if (this.highlighted?.contains(event.target)) return

    // The item is under the pointer: a scroll would move the list under it.
    const item = this.#itemOf(event.target)
    if (item && !isDisabled(item)) this.#highlight(item, { scroll: false })
  }

  // The morph of an item in the `single` selection mode: a server render that changes the
  // `data-selected` of an item sets the selection, as a new state value does (phem_client_state.js).
  onBeforeMorph = (event) => {
    const item = event.target
    if (!this.#selecting || !this.serverSelected.has(item)) return

    const next = event.detail.newElement?.hasAttribute("data-selected") ?? false
    if (next === this.serverSelected.get(item)) return

    this.serverSelected.set(item, next)
    if (next) this.serverSelection = item
    else if (this.serverSelection === undefined) this.serverSelection = null
  }

  // After a morph that changed the selection of the server: the selection, the text of the input
  // and the `value` targets take it, with no event.
  #takeServerSelection() {
    const item = this.serverSelection
    this.serverSelection = undefined
    if (item === undefined || !this.#selecting) return

    this.selected = item
    this.queryChanged = false
    const input = this.#input
    if (input) input.value = item ? this.#labelOf(item) : ""
    for (const el of this.valueTargets) el.value = item?.getAttribute("data-value") ?? ""
  }

  onFocusOut = (event) => {
    const to = event.relatedTarget
    if (to instanceof Node && (this.element.contains(to) || this.popupTargets.some((el) => el.contains(to)))) return

    this.#dismiss()
  }

  // The keys of the input that go to the chips. Returns true when the key went to a chip.
  #chipsKeydown(event) {
    const chips = this.chipTargets
    const input = this.#input
    if (chips.length === 0) return false

    const start = input.selectionStart === 0 && input.selectionEnd === 0
    const back = this.#rtl ? "ArrowRight" : "ArrowLeft"

    if (event.key === back && start) {
      event.preventDefault()
      chips.at(-1).focus()

      return true
    }

    if (event.key === "Backspace" && input.value === "") {
      event.preventDefault()
      this.#removeOf(chips.at(-1))?.click()

      return true
    }

    return false
  }

  // ArrowLeft and ArrowRight move between the chips and to the input. Backspace and Delete remove
  // the chip: the focus goes to the chip before it (Backspace) or after it (Delete), or to the
  // input.
  #chipKeydown(event, chip) {
    const chips = this.chipTargets
    const at = chips.indexOf(chip)
    const [back, forward] = this.#rtl ? ["ArrowRight", "ArrowLeft"] : ["ArrowLeft", "ArrowRight"]

    if (event.key === back || event.key === forward) {
      event.preventDefault()
      const to = chips[at + (event.key === forward ? 1 : -1)]
      if (to) to.focus()
      else if (event.key === forward) this.#input?.focus()

      return
    }

    if (event.key !== "Backspace" && event.key !== "Delete") return

    event.preventDefault()
    const to = event.key === "Backspace" ? (chips[at - 1] ?? chips[at + 1]) : chips[at + 1]
    ;(to ?? this.#input)?.focus()
    this.#removeOf(chip)?.click()
  }

  #dismiss() {
    if (!this.wanted && !this.popup.open) return

    this.wanted = false
    this.queryChanged = false
    this.highlighted = null
    this.#complete(null)
    this.#restore()
    this.#render()
  }

  // The `single` selection mode has no free text: the input gets the label of the selected item
  // again, or no text.
  #restore() {
    const input = this.#input
    if (!input || !this.#selecting) return

    const label = this.#selectedItem ? this.#labelOf(this.#selectedItem) : ""
    if (input.value !== label) this.#fill(label)
  }

  // Selects an item, or no item, and gives its value to the `value` targets.
  #select(item) {
    this.selected = item ?? null
    const value = item?.getAttribute("data-value") ?? ""

    for (const el of this.valueTargets) {
      if (el.value === value) continue

      el.value = value
      el.dispatchEvent(new Event("change", { bubbles: true }))
    }

    this.dispatch("change", { detail: { item: item ?? null, value: item ? value : null } })
  }

  // An inline `mode`: the input shows the label of the highlighted item, or the text of the user
  // again when no item has the highlight.
  #complete(item) {
    const input = this.#input
    if (!input || (this.modeValue !== "both" && this.modeValue !== "inline")) return

    // phem sends the text of the user for a bound input, not the label (see phem_fields.js).
    if (item) {
      this.typed ??= input.value
      input.phemValue = this.typed
      input.value = this.#labelOf(item)
    } else if (this.typed !== null) {
      input.value = this.typed
      this.typed = null
      delete input.phemValue
    }
  }

  // Writes a value in the input and sends its events, as when the user types it.
  #fill(value) {
    const input = this.#input
    if (!input) return

    this.filling = true
    try {
      input.value = value
      input.dispatchEvent(new Event("input", { bubbles: true }))
      input.dispatchEvent(new Event("change", { bubbles: true }))
    } finally {
      this.filling = false
    }
  }

  // An empty input sends its event, as when the user deletes the text. The selection goes too.
  #clear() {
    const input = this.#input
    if (!input) return

    if (this.#selecting && this.#selectedItem) this.#select(null)
    this.queryChanged = false
    input.value = ""
    input.dispatchEvent(new Event("input", { bubbles: true }))
  }

  #highlight(item, { scroll = true } = {}) {
    this.highlighted = item ?? null
    this.#render()
    if (scroll) item?.scrollIntoView?.({ block: "nearest" })
  }

  #later() {
    if (this.pending) return

    this.pending = true
    queueMicrotask(() => {
      this.pending = false
      if (this.listening) this.#render()
    })
  }

  // The client filter: the items that do not match the text of the user get `hidden`, and the
  // groups with no visible item too.
  #filter() {
    const filtering = this.#filtering

    // The popup shows all the items while the input has the label of the selected item.
    const text = (this.typed ?? this.#input?.value ?? "").trim()
    const selected = this.#selecting ? this.#selectedItem : null
    const query = selected && !this.queryChanged && text === this.#labelOf(selected) ? "" : text

    // The same text and the same items (a highlight, an arrow key): the filter stays.
    const items = this.itemTargets
    const last = this.lastFilter
    const same = last && last.filtering === filtering && last.query === query && last.limit === this.limitValue && last.items.length === items.length && last.items.every((el, i) => el === items[i] && el.hidden === last.hidden[i])
    if (same) return

    const matches = filtering ? this.#matcher() : null
    let shown = 0

    for (const item of this.itemTargets) {
      const match = !filtering || ((query === "" || matches(this.#labelOf(item), query)) && (this.limitValue < 0 || shown < this.limitValue))
      if (match && filtering) shown++
      this.#hide(item, !match)
    }

    for (const group of this.groupTargets) {
      const inGroup = items.filter((el) => group.contains(el))
      this.#hide(group, filtering && inGroup.length > 0 && inGroup.every((el) => el.hidden))
    }

    this.lastFilter = { filtering, query, limit: this.limitValue, items, hidden: items.map((el) => el.hidden) }
  }

  // Hides or shows a part. The client shows again only the parts that it hid.
  #hide(el, hide) {
    if (hide) {
      if (!el.hidden) this.filtered.add(el)
      el.hidden = true
    } else if (this.filtered.has(el)) {
      this.filtered.delete(el)
      el.hidden = false
    }
  }

  #matcher() {
    const locale = this.localeValue || document.documentElement.lang || ""
    if (!collators.has(locale)) collators.set(locale, new Intl.Collator(locale || undefined, { usage: "search", sensitivity: "base" }))
    const collator = collators.get(locale)
    const same = (a, b) => collator.compare(a, b) === 0

    if (this.filterValue === "starts-with") return (text, query) => same(text.slice(0, query.length), query)

    return (text, query) => {
      for (let at = 0; at + query.length <= text.length; at++) if (same(text.slice(at, at + query.length), query)) return true

      return false
    }
  }

  // The state: the open popup, the highlight and the ARIA attributes.
  #render() {
    // The first selection is the selection of the server render.
    if (this.selected === undefined) this.selected = this.itemTargets.find((el) => el.hasAttribute("data-selected")) ?? null
    for (const item of this.itemTargets) if (!this.serverSelected.has(item)) this.serverSelected.set(item, item.hasAttribute("data-selected"))

    // The selected item left the page (a new list of the server): no item is selected.
    if (this.#selecting && this.selected && !this.#selectedItem) this.#select(null)

    this.#filter()

    const input = this.#input
    const list = this.listTargets[0] ?? this.popupTargets[0] ?? null
    const items = this.#items
    const empty = items.length === 0

    if (list) {
      if (!list.hasAttribute("role")) list.setAttribute("role", "listbox")
      if (this.multipleValue) list.setAttribute("aria-multiselectable", "true")
    }

    for (const chip of this.chipTargets) chip.tabIndex = -1
    for (const remove of this.chipRemoveTargets) remove.tabIndex = -1

    for (const el of [...this.popupTargets, ...this.listTargets]) el.toggleAttribute("data-empty", empty)
    for (const el of this.emptyTargets) el.hidden = !empty
    for (const el of this.statusTargets) if (!el.hasAttribute("role")) el.setAttribute("role", "status")

    const busy = input?.getAttribute("aria-busy") === "true"
    for (const el of this.popupTargets) {
      if (busy && el.getAttribute("aria-busy") !== "true") el.setAttribute("aria-busy", "true")
      else if (!busy) el.removeAttribute("aria-busy")
    }

    // The highlight stays on the same item, or goes to the first item.
    let active = this.#active
    if (active && isDisabled(active)) active = null
    if (!active && this.autoHighlightValue && this.wanted) active = nextItem(items, null, "Home") ?? null
    this.highlighted = active

    for (const item of items) {
      if (!item.hasAttribute("role")) item.setAttribute("role", "option")
      item.toggleAttribute("data-highlighted", item === active)
      item.toggleAttribute("data-disabled", isDisabled(item))

      // A choice of more than one item: `aria-selected` is the choice of the server.
      if (this.multipleValue) {
        if (!item.hasAttribute("aria-selected")) item.setAttribute("aria-selected", "false")
        item.toggleAttribute("data-selected", item.getAttribute("aria-selected") === "true")

        continue
      }

      // One selected item: `aria-selected` is the selection, and the highlight is the active
      // descendant of the input.
      if (this.#selecting) {
        const selected = item === this.#selectedItem
        item.setAttribute("aria-selected", String(selected))
        item.toggleAttribute("data-selected", selected)

        continue
      }

      item.setAttribute("aria-selected", String(item === active))
    }

    // A clear button shows only when there is something to clear.
    const clearable = this.#selecting ? this.#selectedItem !== null : this.multipleValue ? this.chipTargets.length > 0 : (input?.value ?? "") !== ""
    for (const el of this.clearTargets) {
      el.hidden = !clearable
      el.toggleAttribute("data-visible", clearable)
    }

    const content = !empty || this.emptyTargets.length > 0 || this.statusTargets.length > 0
    const open = this.wanted && content

    if (input) {
      if (!input.hasAttribute("role")) input.setAttribute("role", "combobox")
      if (!input.hasAttribute("aria-autocomplete")) input.setAttribute("aria-autocomplete", this.modeValue)
      if (!input.hasAttribute("autocomplete")) input.setAttribute("autocomplete", "off")
      if (list) link(input, "aria-controls", "ariaControlsElements", list)
      input.setAttribute("aria-expanded", String(open))
      activeDescendant(input, open ? active : null)
      input.toggleAttribute("data-popup-open", open)
    }

    if (open !== this.popup.open) open ? this.popup.show() : this.popup.hide()
  }

  // The attributes of the state.
  #owns(el, name) {
    if (this.popupTargets.includes(el) && name === "aria-busy") return true
    if (this.popup.owns(el, name)) return true
    if (name === "hidden" && this.filtered.has(el)) return true
    if (el === this.#input) {
      // A client list: the text of an input with no `phem:bind` belongs to the client.
      if (name === "value" && this.#clientList && !el.hasAttribute("data-phem-bind")) return true

      return ["aria-expanded", "aria-activedescendant", "data-popup-open"].includes(name)
    }
    if (this.itemTargets.includes(el)) return (name === "aria-selected" && !this.multipleValue) || name === "data-highlighted" || (name === "data-selected" && this.#selecting)
    if (this.emptyTargets.includes(el)) return name === "hidden"
    if (this.clearTargets.includes(el)) return name === "hidden" || name === "data-visible"
    if (this.valueTargets.includes(el)) return name === "value" && this.#selecting

    return false
  }

  // The browser filters the items: the `filter` value, with no inline `mode`.
  get #filtering() {
    return this.filterValue !== "" && this.modeValue !== "inline" && this.modeValue !== "none"
  }

  // The list belongs to the client: a choice writes the label of the item in the input.
  get #clientList() {
    return this.filterValue !== "" || this.modeValue !== "list"
  }

  // One selected item: the `single` selection mode, with a list of the client.
  get #selecting() {
    return this.selectionModeValue === "single" && !this.multipleValue && this.#clientList
  }

  // The selected item in the page: the same element, or the item with its id after a morph.
  get #selectedItem() {
    const selected = this.selected
    if (!selected) return null
    if (selected.isConnected) return selected

    return this.itemTargets.find((el) => selected.id !== "" && el.id === selected.id) ?? null
  }

  #labelOf(item) {
    return (item.getAttribute("data-label") ?? item.textContent).trim()
  }

  get #input() {
    return this.inputTargets[0] ?? null
  }

  get #items() {
    return this.itemTargets.filter((el) => !el.hidden)
  }

  get #active() {
    const highlighted = this.highlighted
    if (!highlighted) return null

    return this.#items.find((el) => el === highlighted || (highlighted.id !== "" && el.id === highlighted.id)) ?? null
  }

  #itemOf(el) {
    return this.itemTargets.find((item) => item.contains(el))
  }

  #chipOf(el) {
    return this.chipTargets.find((chip) => chip === el)
  }

  #removeOf(chip) {
    return this.chipRemoveTargets.find((el) => chip.contains(el)) ?? null
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }
}

// The `aria-activedescendant` of the input: the id of the item, or an element reference when the
// item has no id or a temporary id of the morph (phem_ids.js). No item removes it.
function activeDescendant(input, item) {
  const id = item ? realId(item) : ""
  if (!item) input.removeAttribute("aria-activedescendant")
  else if (id) input.setAttribute("aria-activedescendant", id)
  else if ("ariaActiveDescendantElement" in input && input.ariaActiveDescendantElement !== item) input.ariaActiveDescendantElement = item
}
