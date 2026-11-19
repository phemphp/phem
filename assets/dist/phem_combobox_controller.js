import { Controller } from "@hotwired/stimulus"
import { textMatcher } from "./phem_match.js"
import { ClientState } from "./phem_client_state.js"
import { realId } from "./phem_ids.js"
import { Popup } from "./phem_popup.js"
import { positionOptions, positionValues } from "./phem_position.js"
import { isDisabled, leaveList, nextItem } from "./phem_roving.js"
import { controlWhileOpen, link } from "./phem_aria.js"
import { textValue } from "./phem_fields.js"
import { PendingChoices } from "./phem_pending_choices.js"

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
// and ArrowUp move the highlight. With no selection (an Autocomplete) and no `autoHighlight`, the
// key that opens the popup gives no highlight: the next key moves it. With the `loopFocus` value, a
// move after the last item goes back to the input (no highlight), and the next move goes to the
// first item (with `autoHighlight`, it goes to the first item at once), and Enter chooses the
// highlighted item. Enter on an open popup with no highlight closes the popup, and keeps its
// default action. With the `autoHighlight` value, the first item gets the highlight when the items
// change. A move of the pointer on an item highlights it: an item that comes under a pointer that
// does not move (the list changes) does not get the highlight. The pointer that goes out of the
// items removes the highlight.
//
// To choose an item is a click on it: its handler (`onClick`) runs, or its link opens. The handler
// sets the state (the text of the input, the selected value), and the morph shows it. Then the popup
// closes, and the controller dispatches `phem-combobox:select` with the `item` in the detail.
// Escape closes the popup, and a second Escape clears the input, the selection and the chips, as
// in Base UI (a shown `clear` target gets the click). A click on the `clear` target clears the
// input. A clear (the `clear` target or the second Escape) does not open a closed popup. A click
// outside and the focus out of the combobox close the popup.
//
// The input gets `role="combobox"`, `aria-autocomplete="list"`, `aria-expanded`, `aria-controls`
// and `aria-activedescendant`. The list (or the popup, with no list) gets `role="listbox"`, the
// items `role="option"`, and the highlighted item `aria-selected="true"` and `data-highlighted`.
// A list or an item with no `id` gets no id: the input points to it with an element reference
// (`ariaControlsElements`, `ariaActiveDescendantElement`). The input also gets
// `aria-haspopup="listbox"`, and `data-list-empty` when there is no item. A `trigger` gets
// `aria-haspopup="listbox"`, `aria-expanded`, `data-popup-open`, and `aria-controls` while the
// popup is open. With the input in the popup (a searchable select), the popup gets
// `role="dialog"`, and the trigger gets `aria-haspopup="dialog"` and points to the popup, as in
// Base UI. A trigger with a `value` target gets `data-placeholder` while no item is selected.
// An `inputGroup` gets `role="group"` and `data-popup-open`. A `group` of items gets
// `role="group"` and the name of its `groupLabel` target. The popup and the list
// get `data-empty` when there is no item. An `empty` target is a live region (`role="status"`,
// `aria-live="polite"`, `aria-atomic="true"`) that stays in the page, as the Empty part of Base UI:
// it has its content only when there is no item, so a screen reader reads it then, and `:empty`
// matches it when the list has items. Do not give it `hidden`. A `status` target gets
// `role="status"`: a screen reader reads it (for example "3 results"). The popup is positioned as a `phem-popover` (phem_position.js),
// below the input by default: use `var(--anchor-width)` for its width.
//
// With the `multiple` value, the user chooses more than one item. The server gives the chosen
// items `aria-selected="true"`, and the list gets `aria-multiselectable="true"`. A choice keeps
// the popup open and the highlight. With a list of the server, a choice while the input has a
// text closes the popup, as in Base UI, and then the text goes: the event of the click goes to the
// server first. The input has no free text: when the popup closes, the text goes. The `chip`
// targets show the chosen values, each with a
// `chipRemove` target whose handler removes its value. ArrowLeft at the start of the input goes
// to the last chip, ArrowLeft and ArrowRight move between the chips and back to the input, and
// Backspace or Delete on a chip clicks its `chipRemove`. An input that the user empties gives the
// highlight to the first chosen item, as an open does. Backspace in an empty input clicks the
// `chipRemove` of the last chip. The chips and their `chipRemove` targets get `tabindex="-1"`,
// and a chosen item gets `data-selected`.
//
// With the `selectionMode` value `multiple` and a list of the client, the browser keeps the chosen
// items, as the Combobox of Base UI with `multiple`: the server renders the items and the chips
// one time, and a choice needs no event of the server. A click on an item adds its `data-value` to
// the values, or removes it: the item gets `data-selected` and `aria-selected`. Each `chip` target
// with a `data-value` shows only while its value is chosen (the others get `hidden`), in the order
// of the choices. A click on its `chipRemove` removes the value, and a click on the `clear` target
// removes all the values. A choice clears the text of the input: when the input had a text, the
// popup closes, else it stays open. The first values are the items that the server renders with
// `data-selected`, and a server render that changes the `data-selected` of an item adds or removes
// its value. Each change dispatches `phem-combobox:change` with the `item` (or null) and the
// `value` (the array of the values) in the detail. While a value is chosen, the input has no
// `placeholder`, it gets the `aria-description` of its `data-phem-combobox-description` attribute
// (`{count}` is the number of the values), and a `chips` target (the box of the chips, which gets
// `role="toolbar"`) keeps its `aria-label`. With no value, they lose it. The form fields of a
// hidden chip (a hidden input with the value) are disabled: a form sends only the chosen values.
//
// The `multiple` selection mode also works with a list of the server, for a form that sends the
// choices on submit: the server searches, and the browser keeps the chosen items. The first
// values are also the chips with a `data-value` that the server renders with no `hidden`. A value
// with no chip gets a chip from the `chipTemplate` target, before the template: `{label}` in its
// text and its attributes is the label of the item, and `{value}` in its attributes the value of
// the item. These chips stay through the morph, until a chip of the server for the same value
// replaces them, and a chip leaves with its value. A server render that removes a chip with a
// `data-value` removes the value. A choice shows
// no pending state: an `onClick` handler of the item still runs, but the browser keeps the choice.
//
// With the `filter` value (`contains` or `starts-with`), the browser filters the items that the
// server rendered one time: an item that does not match the text of the input gets `hidden`, and
// a `group` target with no visible item too. The match ignores the case and the accents
// (`Intl.Collator` with the `locale` value, or the language of the page). The label of an item is
// its `data-label` attribute, or its text. The `limit` value shows only the first matches. A
// popup that closes keeps its items and its highlight until the next open, as in Base UI: a choice
// writes the label of the item in the input, and the close animation shows the same items.
//
// The `mode` value is the `aria-autocomplete` of the input: `list` (the default), `both`,
// `inline` or `none`. With `both` and `inline`, the arrow keys write the label of the highlighted
// item in the input, and Escape gives back the text of the user. With `inline` and `none`, the
// client filter is off. With the `filter` value or a `mode` other than `list`, the list belongs to
// the client: a choice also writes the label of the item in the input, and sends its `input` and
// `change` events.
//
// With the `selectionMode` value `single` and a list of the client, the combobox has one selected
// item, as the Combobox of Base UI. A click on an item selects it: the item gets `data-selected`
// and `aria-selected="true"`, and a `value` target (a hidden input) gets its `data-value`. The
// first selection is the item that the server renders with `data-selected`: an empty input gets
// its label, and a `value` target with no value gets its `data-value`. The input has no free
// text: when the popup closes, the input gets the label of the selected item again, or no text.
// When the popup opens with the label of the selection in the input, the list shows all the
// items, until the user types, and the selected item has the highlight and is in view. A click on
// the `clear` target also removes the selection, and so does an input that the user empties (not
// an input in the popup), as in Base UI.
//
// The `single` selection mode also works with a list of the server, as the Async search of Base
// UI: the browser keeps the selection, and the server searches. A click on an item selects it,
// writes its label in the input, and a bound `value` target gets the value of the item. The
// `input` and `change` events of the input go in the next task, after the event of the click: the
// handler of the item runs first. The label of the selection in the input is not a search:
// phem sends no text for it (`phemValue` is empty, see phem_fields.js), so the handler of the
// input and a `phem:bind` input get `""`. The text that the user types goes as it is.
//
// With a list of the server, the browser keeps the selected items in the list, as in Base UI: a
// server render that does not have the selected item (the `single` selection mode) or a chosen
// item (`multiple`, an item with `aria-selected="true"` and a `chip` target with its
// `data-value`) keeps it, at the end of the list. A chosen item whose chip goes leaves the list,
// and so does a kept item that is not selected any more (at once). A server render that removes
// the old selected item of the server does not change the selection of the browser.
// The text of an input with no `phem:bind` belongs to the browser: the morph keeps it.
//
// With a list of the server, a choice shows at once, before the server answers, when the click on
// the item sends an event (its `onClick` handler). With `multiple`, a click on an item that is not
// chosen adds a chip from the `chipTemplate` target (a `<template>` in the box of the chips): the
// chip goes before the template, with the `data-value` of the item, and `{label}` in its text and
// its attributes is the label of the item. The item gets `aria-selected="true"`. With the `single`
// selection mode, the browser already selects the item. While the event runs, the chip (or the
// `clear` targets of the `single` selection mode) gets `data-pending`, and the chip stays through
// the morph of another event. The answer of the event shows the server render: its morph replaces
// the chip with the chip of the server. A failed event gives `data-failed`, and after one second
// the choice goes: the chip leaves, or the selection is cleared. phem shows no error message for
// the event (see phem_pending_choices.js).
//
// A `status` target shows the state of a search of the server with its parts: the elements with
// a `data-phem-combobox-status` attribute. The part of the state shows, and the others get
// `hidden`: `pending` while an event of the input runs (after the loading delay), `error` when the
// last event of the input failed, `idle` when the list has no item and the input has no text to
// search, and `empty` when no item matches the text (the items that the browser keeps do not
// count). From the input of the user until the event of the input runs, the state of a text
// stays: the parts do not show a result of the old text. The content with no attribute always shows. While
// the `error` part shows, the items get `hidden`, and phem shows no error message for the event
// (`phem:error` is cancelled). While the `pending`, `error` or `idle` part shows, the `empty`
// targets have no content.
//
// The `clear` targets show only when there is something to clear: a selected item with the
// `single` selection mode, a chip with the `multiple` value or selection mode, and else a text in the input. A
// hidden `clear` target has `hidden`.
//
// The input can be in the popup, as a search: a `trigger` (a button with a `value` target that is not
// an input, which shows the label of the selection or its `placeholder`) opens the popup, the popup
// goes against the trigger, and the input gets the focus. A choice and a dismiss give the focus
// back to the trigger, and the input has no text at the next open.
//
// With no `popup` target, the list is inline (in a command palette, for example): it is always
// open. With the `autoHighlight` value `always`, an open list always has a highlight on an item
// (the first item at first). With the `keepHighlight` value, the highlight stays when the pointer
// goes out of the items. With the `highlightItemOnHover` value `false`, the pointer does not move
// the highlight.
//
// With the `grid` value, the list is a grid (`role="grid"`): the `row` targets get `role="row"`
// and their items `role="gridcell"`. ArrowLeft and ArrowRight move the highlight to the item before
// or after it, and ArrowUp and ArrowDown to the item in the same column of the row above or below.
//
// With the `openOnInputClick` value, a click on the input opens the popup. With an `inputGroup`
// target (the box around the input and its buttons), the popup is positioned against it.
//
// While an event of phem runs, the input gets `aria-busy` (see the loading state of phem): the
// popup gets it too.
// The elements that take the focus of a press in the popup.
const FIELDS = 'input, textarea, select, [contenteditable]:not([contenteditable="false"])'

export default class PhemComboboxController extends Controller {
  static targets = ["input", "inputGroup", "value", "trigger", "clear", "popup", "list", "row", "item", "group", "groupLabel", "empty", "status", "chips", "chip", "chipRemove", "chipTemplate"]
  static values = {
    autoHighlight: String,
    keepHighlight: Boolean,
    highlightItemOnHover: { type: Boolean, default: true },
    grid: Boolean,
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
    // The chosen values of the `multiple` selection mode, in the order of the choices. `undefined`
    // until the first render reads the server render.
    this.values = undefined
    // The values of a server render that changed the `data-selected` of their item: true (chosen)
    // or false.
    this.serverValues = new Map()
    // The `placeholder` of the input and the `aria-label` of the `chips` targets in the server
    // render: they show only with no chosen value.
    this.labels = new WeakMap()
    // The user changed the text of the input after the popup opened.
    this.queryChanged = false
    // The `data-selected` of each item in the last server render, and the selection of a server
    // render that changed it (`undefined`: no change).
    this.serverSelected = new WeakMap()
    this.serverSelection = undefined
    // The content of each `empty` target while the list has items.
    this.emptyContent = new WeakMap()
    // A clear sends its `input` event: the popup stays as it is.
    this.clearing = false
    // The item that the pointer highlighted. A highlight of the keyboard is another item.
    this.pointerItem = null
    // The selected items that the last morph did not remove (a list of the server), and all the
    // items that the browser keeps until a server render has them again: they are not results.
    this.kept = new Set()
    this.held = new WeakSet()
    // The handlers of the events of the input that run, and whether the last one failed (with an
    // `error` part).
    this.searches = new Set()
    this.failed = false
    // The state of the `status` parts. It stays from the input of the user until the event of the
    // input runs: the parts do not show a result of the old text.
    this.statusState = null
    this.typing = false
    this.awaiting = false
    // The choices that show before the answer of their event.
    this.choices = new PendingChoices()
    // The chips that the browser made from the `chipTemplate` target for the chosen values of the
    // `multiple` selection mode, with a list of the server. They stay through the morph.
    this.made = new WeakSet()
    this.popup = new Popup({
      anchor: () => (this.#inPopup ? this.triggerTargets[0] : null) ?? this.inputGroupTargets[0] ?? (this.hasInputTarget ? this.inputTarget : null),
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
        this.#placeKept()
        this.#takeServerSelection()
        this.#render()
        this.popup.reposition()
      },
    })
    this.state.connect()
    this.choices.connect()

    this.listening = new AbortController()
    const options = { signal: this.listening.signal }
    this.element.addEventListener("input", this.onInput, options)
    this.element.addEventListener("keydown", this.onKeydown, options)
    this.element.addEventListener("click", this.onClick, options)
    this.element.addEventListener("pointerdown", this.onPointerDown, options)
    this.element.addEventListener("pointermove", this.onPointerMove, options)
    this.element.addEventListener("pointerout", this.onPointerOut, options)
    this.element.addEventListener("focusout", this.onFocusOut, options)
    this.element.addEventListener("focusin", this.onFocusIn, options)
    this.element.addEventListener("turbo:before-morph-element", this.onBeforeMorph, options)
    this.element.addEventListener("phem:queue", this.onQueue, options)
    this.element.addEventListener("phem:send", this.onSend, options)
    this.element.addEventListener("phem:done", this.onDone, options)
    // phem dispatches `phem:error` on the element of the component, around the combobox.
    document.addEventListener("phem:error", this.onError, options)

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
    this.choices.disconnect()
    this.listening.abort()
    this.busy.disconnect()
    this.popup.destroy()
    // A new controller (a page from the Turbo cache) reads the content of the server render.
    for (const el of this.emptyTargets) this.#renderEmpty(el, true)
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

  triggerTargetConnected() {
    this.#later()
  }

  inputGroupTargetConnected() {
    this.#later()
  }

  chipsTargetConnected() {
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
    if (!this.clearing) this.wanted = true
    this.queryChanged = true
    this.highlighted = null
    // The user deleted all the text: the selection goes, as in Base UI. A search in the popup
    // keeps it.
    if (this.#selecting && !this.#inPopup && event.target.value === "" && this.#selectedItem) this.#select(null)
    // With `multiple`, an input that the user empties is as an open: the first chosen item gets
    // the highlight, as in Base UI.
    const emptied = this.#multiple && event.target.value === ""
    if (emptied) this.queryChanged = false

    this.typing = true
    try {
      this.#render()
      if (emptied) this.#highlight(this.#selection(this.#items))
    } finally {
      this.typing = false
    }
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

    if (this.gridValue && this.#gridKeydown(event)) return

    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault()
      const opening = !this.#open
      this.wanted = true

      // An Autocomplete (no selection) opens with no highlight: the next key moves it. With
      // `autoHighlight`, the render gives the highlight to the first item.
      if (opening && this.#autocomplete && !this.#autoHighlight) {
        this.highlighted = null
        this.#render()

        return
      }

      // A closed popup has the items of its last open: the filter gives the items of the text now.
      if (opening) this.#filter()
      const items = this.#items
      // A popup that opens with a selection has the highlight on the selected item.
      const selection = opening ? this.#selection(items) : null
      if (selection) {
        this.#highlight(selection)

        return
      }

      const current = opening ? null : this.#active
      // With `autoHighlight`, an item always has the highlight: the keys go from the last item
      // to the first one. Else, after the last item (or before the first one), the highlight goes
      // back to the input.
      let next = nextItem(items, current, event.key, { loop: this.loopFocusValue && this.#autoHighlight })
      if (!next && current && !this.loopFocusValue) next = current
      this.#highlight(next)
      this.#complete(next)

      return
    }

    if (event.key === "Enter") {
      if (!this.#open) return

      const item = this.#active
      // No highlight: the popup closes, and Enter keeps its default action (the submit of the form).
      if (!item) {
        this.#dismiss()

        return
      }

      event.preventDefault()
      item.click()

      return
    }

    // Escape with no open popup clears the input, the selection and the chips, as in Base UI: a
    // shown `clear` target gets the click, with its handler. Escape on an open popup closes it
    // (see phem_dismiss.js).
    if (event.key === "Escape" && !this.#open && (this.#clearable || this.#input.value !== "")) {
      event.preventDefault()
      const clear = this.clearTargets.find((el) => !el.hidden)
      if (clear) {
        clear.click()

        return
      }

      this.#clear()
      // `multiple` with a list of the server: the handlers of the chips remove the chosen items.
      if (this.#multiple && !this.#selectingMany) for (const chip of this.#chips.reverse()) this.#removeOf(chip)?.click()
    }
  }

  onClick = (event) => {
    if (event.defaultPrevented) return

    const remove = this.chipRemoveTargets.find((el) => el.contains(event.target))
    if (remove) {
      // The `multiple` selection mode: the chip of the remove button loses its value.
      const value = this.#selectingMany ? this.chipTargets.find((chip) => chip.contains(remove))?.getAttribute("data-value") : null
      if (value != null) {
        this.#choose(this.values.filter((v) => v !== value), null)
        this.#render()
      }

      return
    }

    const item = this.#itemOf(event.target)
    if (item) {
      if (isDisabled(item)) return

      // The `multiple` selection mode: the item adds or removes its value.
      if (this.#selectingMany) {
        const value = valueOf(item)
        this.#choose(this.values.includes(value) ? this.values.filter((v) => v !== value) : [...this.values, value], item)
      }

      // A choice of more than one item clears the text of the input, and a choice that clears a
      // text closes the popup, as in Base UI. With a list of the server, the text goes after the
      // handler of the item: an `input` event first would send a new list with no item, and lose
      // the click. phem takes the click after this listener.
      if (this.#multiple && (this.#selectingMany || !this.#clientList) && (this.#input?.value ?? "") !== "") {
        this.wanted = false
        this.queryChanged = false
        this.highlighted = null
        if (this.#clientList) this.#fill("")
        else setTimeout(() => this.#fill(""))
      }

      // A choice of more than one item keeps the popup open, with its highlight.
      if (!this.#multiple) {
        this.wanted = false
        this.queryChanged = false
        this.highlighted = null
        this.typed = null
        if (this.#input) delete this.#input.phemValue
        if (this.#selecting) this.#select(item)
        // With a list of the server, the input shows the label at once, and its events go after
        // the click: the handler of the item runs first, as with `multiple`.
        if (this.#clientList || this.#selecting) this.#fill(this.#inPopup ? "" : this.#labelOf(item), { later: !this.#clientList })
      }

      this.#render()
      // With the input in the popup, a choice gives the focus back to the trigger.
      const focus = this.#inPopup && !this.#multiple ? this.triggerTargets[0] : this.#input
      focus?.focus({ preventScroll: true })
      this.dispatch("select", { detail: { item } })

      return
    }

    if (this.clearTargets.some((el) => el.contains(event.target))) {
      this.#clear()
      this.#input?.focus()

      return
    }

    if (this.triggerTargets.some((el) => el.contains(event.target))) {
      this.wanted = !this.#open
      this.#render()
      this.#input?.focus()

      return
    }

    // A press on the input group out of the input and its parts acts as a press on the input.
    if (this.openOnInputClickValue && (event.target === this.#input || this.#inGroupSpace(event.target)) && !this.#open) {
      this.wanted = true
      this.#render()
    }
  }

  // A press in the popup (an item, the space around the items, a list with a `tabindex`), on a
  // trigger, a clear button or the remove button of a chip does not move the focus out of the input.
  // A field of the application in the popup (an input, a textarea, a select, editable content) gets
  // the focus of its press.
  onPointerDown = (event) => {
    const target = event.target instanceof Element ? event.target : null
    if (!this.#itemOf(event.target) && target?.closest(FIELDS) && target.closest(FIELDS) !== this.#input) return

    const parts = [...this.popupTargets, ...this.triggerTargets, ...this.clearTargets, ...this.chipRemoveTargets]
    if (this.#itemOf(event.target) || parts.some((el) => el.contains(event.target))) event.preventDefault()

    // A press on the space of the input group gives the focus to the input, as in Base UI.
    if (this.#inGroupSpace(event.target)) {
      event.preventDefault()
      this.#input?.focus()
    }
  }

  // The focus on a chip closes the popup, as in Base UI.
  onFocusIn = (event) => {
    if (this.#chipOf(event.target)) this.#dismiss()
  }

  // A move of the pointer highlights the item under it. The browser also sends `pointerover` when
  // an item comes under a pointer that does not move (the list changes or scrolls): that item does
  // not take the highlight of the keyboard.
  onPointerMove = (event) => {
    if (!this.highlightItemOnHoverValue) return

    // The moves on the highlighted item: no lookup of the items.
    if (this.highlighted?.contains(event.target)) {
      this.pointerItem = this.highlighted

      return
    }

    // The item is under the pointer: a scroll would move the list under it.
    const item = this.#itemOf(event.target)
    if (!item || isDisabled(item)) return

    this.#highlight(item, { scroll: false })
    this.pointerItem = item
  }

  // The pointer goes out of the items (to the space around them, or out of the list): the
  // highlight goes, as in Base UI. In an inline `mode`, the input gets the text of the user again.
  // A highlight of the keyboard stays.
  onPointerOut = (event) => {
    if (this.keepHighlightValue) return
    if (!this.pointerItem || this.pointerItem !== this.highlighted) return

    leaveList(event, this.itemTargets, () => {
      this.#highlight(null, { scroll: false })
      this.#complete(null)
    })
  }

  // The morph of an item in the `single` or `multiple` selection mode: a server render that changes
  // the `data-selected` of an item sets the selection, as a new state value does
  // (phem_client_state.js).
  onBeforeMorph = (event) => {
    const item = event.target

    // The morph compares the content of an `empty` target with the server render.
    if (this.emptyTargets.includes(item)) this.#renderEmpty(item, true)

    // The chip of a choice that waits for its answer stays, and so does a chip that the browser
    // made for a chosen value.
    if (!event.detail.newElement && (this.choices.keeps(item) || this.made.has(item))) {
      event.preventDefault()

      return
    }

    // A list of the server that does not have a selected item: the item stays.
    if (!event.detail.newElement && this.#keeps(item)) {
      event.preventDefault()
      this.kept.add(item)
      this.held.add(item)

      return
    }

    if (event.detail.newElement) this.held.delete(item)

    // The `multiple` selection mode: a server render that removes a chip of a value removes the
    // value, as a server render that removes the `data-selected` of an item.
    if (!event.detail.newElement && this.#selectingMany && this.values !== undefined && this.chipTargets.includes(item) && item.hasAttribute("data-value")) {
      this.serverValues.set(item.getAttribute("data-value"), false)

      return
    }

    if (!(this.#selecting || this.#selectingMany) || !this.serverSelected.has(item)) return

    // A server render that removes an item does not change the selection: the old selection of
    // the server leaves the list, and the browser keeps its own selection.
    if (this.#selecting && !event.detail.newElement && item !== this.#selectedItem) {
      this.serverSelected.delete(item)

      return
    }

    const next = event.detail.newElement?.hasAttribute("data-selected") ?? false
    if (next === this.serverSelected.get(item)) return

    this.serverSelected.set(item, next)
    if (this.#selectingMany) this.serverValues.set(valueOf(event.detail.newElement ?? item), next)
    // The user changed the selection after the request of this answer left: the server did not
    // know it, and the selection of the browser stays (as a state value, see phem_client_state.js).
    else if (this.#selectedSince(this.#sentAt)) return
    else if (next) this.serverSelection = item
    else if (this.serverSelection === undefined) this.serverSelection = null
  }

  // An event of the input waits: the `status` parts keep their state. The event of a click on an
  // item is a choice that shows at once.
  onQueue = (event) => {
    if (event.target === this.#input) this.awaiting = true
    else this.#showChoice(event.target, event.detail.id)
  }

  // A click on an item of a list of the server sends its event: the choice shows before the
  // answer. With `multiple`, an item that is not chosen gets a chip from the `chipTemplate`
  // target. With the `single` selection mode, the item is the selection already.
  #showChoice(item, id) {
    // The `multiple` selection mode: the browser keeps the choice.
    if (this.#clientList || this.#selectingMany || !this.itemTargets.includes(item)) return

    if (this.#selecting) {
      if (item !== this.#selectedItem) return

      // The selection of the choice goes, also when a morph replaced its item. A new selection
      // stays.
      const revert = () => {
        const selected = this.#selectedItem
        if (selected && valueOf(selected) === valueOf(item)) this.#clear()
        this.#render()
      }
      this.choices.add(id, { item, parts: this.clearTargets, revert })

      return
    }

    const template = this.chipTemplateTargets[0]
    if (!this.#multiple || !template || item.getAttribute("aria-selected") === "true") return

    const chip = this.#chipFrom(template, item)
    template.before(chip)
    item.setAttribute("aria-selected", "true")

    const revert = () => {
      chip.remove()
      if (!this.choices.chooses(item)) item.setAttribute("aria-selected", "false")
      // The browser kept the item for the choice: it leaves the list.
      if (this.held.has(item) && !this.choices.chooses(item)) item.remove()
      this.#render()
    }
    // The morph of the answer replaced the chip with the chip of the server.
    const end = () => chip.remove()
    this.choices.add(id, { item, chip, parts: [chip], revert, end })
    this.#render()
  }

  // A chip of the `chipTemplate` target for the item: `{label}` is the label of the item, and
  // `{value}` its value (in the attributes, for example the `value` of a hidden input). Its id
  // keeps the morph from matching it with a chip of the server. It is a copy, not a component of
  // the server: it has no fiber id (`data-phem-c`).
  #chipFrom(template, item) {
    const chip = template.content.firstElementChild.cloneNode(true)
    const label = this.#labelOf(item)
    const value = valueOf(item)
    const fill = (text) => text.replaceAll("{label}", label).replaceAll("{value}", value)
    const walker = document.createTreeWalker(chip, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT)

    for (let node = chip; node; node = walker.nextNode()) {
      if (node.nodeType === Node.TEXT_NODE) {
        node.textContent = node.textContent.replaceAll("{label}", label)

        continue
      }

      node.removeAttribute("data-phem-c")
      for (const attr of node.attributes) if (attr.value.includes("{label}") || attr.value.includes("{value}")) attr.value = fill(attr.value)
    }

    chip.id = `${template.id || "phem-combobox"}-choice-${++choices}`
    chip.setAttribute("data-value", value)
    chip.setAttribute("data-phem-combobox-target", "chip")

    return chip
  }

  // An event of the input starts: the `pending` part shows with the loading state.
  onSend = (event) => {
    if (event.target === this.#input) this.searches.add(event.detail.handler)
  }

  // An event of the input ends: a failure shows the `error` part, and a success hides it.
  onDone = (event) => {
    if (event.target !== this.#input) return

    this.searches.delete(event.detail.handler)
    this.awaiting = false
    this.failed = event.detail.ok === false && this.#hasPart("error")
    this.#render()
  }

  // The `error` part shows the failure of an event of the input: phem shows no message.
  onError = (event) => {
    if (this.searches.has(event.detail?.handler) && this.#hasPart("error")) event.preventDefault()
  }

  // The item that a server render removes stays: the selected item of the `single` selection
  // mode, or a chosen item of `multiple`, with a list of the server.
  #keeps(item) {
    if (this.#clientList || !this.itemTargets.includes(item)) return false
    if (this.#selecting) return item === this.#selectedItem

    return this.#multiple && item.getAttribute("aria-selected") === "true"
  }

  // After the morph: the kept items go to the end of the list. A chosen item of `multiple` with
  // no chip leaves the list.
  #placeKept() {
    for (const item of this.kept) {
      if (!item.isConnected) continue

      const chosen = !this.#multiple || this.chipTargets.some((chip) => chip.getAttribute("data-value") === valueOf(item))
      if (!chosen) item.remove()
      else if (item.nextElementSibling) item.parentElement.append(item)
    }

    this.kept.clear()
  }

  // After a morph that changed the selection of the server: the selection, the text of the input
  // and the `value` targets take it, with no event.
  #takeServerSelection() {
    if (this.#selectingMany && this.values !== undefined && this.serverValues.size > 0) {
      let values = this.values
      for (const [value, chosen] of this.serverValues) {
        values = values.filter((v) => v !== value)
        if (chosen) values.push(value)
      }
      this.values = values
    }

    this.serverValues.clear()

    const item = this.serverSelection
    this.serverSelection = undefined
    if (item === undefined || !this.#selecting) return

    // The server render has the selection of the browser (for example a bound value): the text
    // that the user types stays.
    if (item === this.#selectedItem) return

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
    const chips = this.#chips
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
    const chips = this.#chips
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

  // A grid (the `grid` value, with `row` targets): ArrowLeft and ArrowRight move the highlight to
  // the item before or after it, and ArrowUp and ArrowDown to the item in the same column of the
  // row above or below. With no highlight, the keys keep their default.
  #gridKeydown(event) {
    const vertical = event.key === "ArrowDown" || event.key === "ArrowUp"
    if (!vertical && event.key !== "ArrowLeft" && event.key !== "ArrowRight") return false

    const current = this.#open ? this.#active : null
    if (!current) return false

    event.preventDefault()
    const items = this.#items
    let next

    if (vertical) {
      const rows = this.rowTargets.map((row) => items.filter((el) => row.contains(el) && !isDisabled(el))).filter((row) => row.length > 0)
      const at = rows.findIndex((row) => row.includes(current))
      const row = rows[at + (event.key === "ArrowDown" ? 1 : -1)]
      // After the last row (or before the first one), the highlight goes back to the input.
      next = at === -1 ? current : row ? row[Math.min(rows[at].indexOf(current), row.length - 1)] : this.loopFocusValue ? null : current
    } else {
      next = nextItem(items, current, event.key, { orientation: "horizontal", loop: false, rtl: this.#rtl }) ?? current
    }

    this.#highlight(next)
    this.#complete(next)

    return true
  }

  #dismiss() {
    if (!this.wanted && !this.#open) return

    // With the input in the popup, a focus in the popup goes back to the trigger.
    const refocus = this.#inPopup && this.popupTargets.some((el) => el.contains(document.activeElement))

    this.wanted = false
    this.queryChanged = false
    this.highlighted = null
    this.#complete(null)
    this.#restore()
    this.#render()
    if (refocus) this.triggerTargets[0]?.focus({ preventScroll: true })
  }

  // A combobox with a selection has no free text, as in Base UI: the input gets the label of the
  // selected item again (the `single` selection mode), or no text (`multiple`).
  #restore() {
    const input = this.#input
    if (!input) return

    // An input in the popup is a search: it starts with no text at the next open. The chips keep
    // the choices of `multiple`.
    if (this.#inPopup || this.#multiple) {
      if (input.value !== "") this.#fill("")

      return
    }

    if (!this.#selecting) return

    const label = this.#selectedItem ? this.#labelOf(this.#selectedItem) : ""
    if (input.value !== label) this.#fill(label)
  }

  // Selects an item, or no item, and gives its value to the `value` targets.
  #select(item) {
    // The browser kept the item of the old selection (a list of the server): it leaves the list.
    const old = this.#selectedItem
    if (old && old !== item && this.held.has(old)) old.remove()

    this.selected = item ?? null
    this.selectedAt = performance.now()
    const value = item?.getAttribute("data-value") ?? ""

    for (const el of this.valueTargets) {
      if (!(el instanceof HTMLInputElement) || el.value === value) continue

      el.value = value
      el.dispatchEvent(new Event("change", { bubbles: true }))
    }

    this.dispatch("change", { detail: { item: item ?? null, value: item ? value : null } })
  }

  // The `multiple` selection mode: the new values, and the item that the user chose (or null).
  #choose(values, item) {
    this.values = values
    this.dispatch("change", { detail: { item, value: [...values] } })
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

  // Writes a value in the input and sends its events, as when the user types it. With `later`, the
  // events go in the next task: phem takes the click of a choice first.
  #fill(value, { later = false } = {}) {
    const input = this.#input
    if (!input) return

    input.value = value
    this.#markLabel()

    const send = () => {
      this.filling = true
      try {
        input.dispatchEvent(new Event("input", { bubbles: true }))
        input.dispatchEvent(new Event("change", { bubbles: true }))
      } finally {
        this.filling = false
      }
    }

    if (later) setTimeout(send)
    else send()
  }

  // The `single` selection mode with a list of the server: the label of the selection in the
  // input is not a search. phem sends no text for it (see phem_fields.js).
  #markLabel() {
    const input = this.#input
    if (!input || !this.#selecting || this.#clientList) return

    const selected = this.#selectedItem
    if (selected && input.value === this.#labelOf(selected)) input.phemValue = ""
    else delete input.phemValue
  }

  // The text that the input searches: the text of the user, with no label of the selection.
  get #searchText() {
    const input = this.#input

    return (this.typed ?? (input ? textValue(input) : "")).trim()
  }

  // A `status` target has a part for this state.
  #hasPart(state) {
    return this.statusTargets.some((el) => el.querySelector(`[data-phem-combobox-status="${state}"]`) !== null)
  }

  // The parts of the `status` targets: the part of the state shows. Returns true when a part
  // other than `empty` shows: the `empty` targets then have no content. From the input of the user
  // until the loading state, the state stays.
  #renderStatus(empty, noResult, busy) {
    const text = this.#searchText
    // No text to search: the state does not wait for the server.
    const waiting = (this.typing || this.awaiting) && !busy && text !== ""
    const state = waiting ? this.statusState : busy ? "pending" : this.failed ? "error" : empty && text === "" ? "idle" : noResult && text !== "" ? "empty" : null
    this.statusState = state
    let shown = false

    for (const status of this.statusTargets) {
      for (const part of status.querySelectorAll("[data-phem-combobox-status]")) {
        const show = part.getAttribute("data-phem-combobox-status") === state
        part.hidden = !show
        if (show) shown = true
      }
    }

    return shown && state !== "empty"
  }

  // An empty input sends its event, as when the user deletes the text. The selection goes too.
  #clear() {
    const input = this.#input
    if (!input) return

    if (this.#selecting && this.#selectedItem) this.#select(null)
    if (this.#selectingMany && this.values.length > 0) this.#choose([], null)
    this.queryChanged = false
    input.value = ""

    this.clearing = true
    try {
      input.dispatchEvent(new Event("input", { bubbles: true }))
    } finally {
      this.clearing = false
    }
  }

  // A highlight that is not of the pointer: `onPointerMove` sets `pointerItem` again after the
  // call. So `onPointerOut` removes only a highlight of the pointer.
  #highlight(item, { scroll = true } = {}) {
    this.highlighted = item ?? null
    this.pointerItem = null
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
    // A popup that closes keeps its items, as in Base UI: a choice writes the label of the item in
    // the input, and the close animation still shows the matches of the text of the user. The next
    // open filters again.
    if (!this.wanted && !this.#inline && this.hasPopupTarget) return

    const filtering = this.#filtering

    // The popup shows all the items while the input has the label of the selected item.
    const text = (this.typed ?? this.#input?.value ?? "").trim()
    const selected = this.#selecting ? this.#selectedItem : null
    const query = selected && !this.queryChanged && text === this.#labelOf(selected) ? "" : text

    // The same text and the same items (a highlight, an arrow key): the filter stays.
    const items = this.itemTargets
    const last = this.lastFilter
    const same = last && last.failed === this.failed && last.filtering === filtering && last.query === query && last.limit === this.limitValue && last.items.length === items.length && last.items.every((el, i) => el === items[i] && el.hidden === last.hidden[i])
    if (same) return

    const matches = filtering ? this.#matcher() : null
    let shown = 0

    for (const item of this.itemTargets) {
      // A failed search shows no item.
      const match = !this.failed && (!filtering || ((query === "" || matches(this.#labelOf(item), query)) && (this.limitValue < 0 || shown < this.limitValue)))
      if (match && filtering) shown++
      this.#hide(item, !match)
    }

    for (const group of this.groupTargets) {
      const inGroup = items.filter((el) => group.contains(el))
      this.#hide(group, filtering && inGroup.length > 0 && inGroup.every((el) => el.hidden))
    }

    this.lastFilter = { failed: this.failed, filtering, query, limit: this.limitValue, items, hidden: items.map((el) => el.hidden) }
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
    return textMatcher({ locale: this.localeValue, mode: this.filterValue === "starts-with" ? "starts-with" : "contains" })
  }

  // The selected item of a popup that opens, when the user did not type: it gets the highlight.
  // With `multiple`, the first chosen item of the list gets it, as in Base UI.
  #selection(items) {
    if (this.queryChanged) return null

    const selected = this.#selecting ? this.#selectedItem : this.#multiple ? (items.find((el) => el.getAttribute("aria-selected") === "true") ?? null) : null

    return selected && items.includes(selected) && !isDisabled(selected) ? selected : null
  }

  // An `empty` target is a live region that stays in the page. It has its content only when the
  // list is empty: a screen reader reads the new content.
  #renderEmpty(el, empty) {
    const content = this.emptyContent.get(el)

    if (empty && content) {
      el.append(content)
      this.emptyContent.delete(el)
    } else if (!empty && !content && el.childNodes.length > 0) {
      const held = document.createDocumentFragment()
      held.append(...el.childNodes)
      this.emptyContent.set(el, held)
    }
  }

  // The first selection is the selection of the server render: an empty input gets its label, and
  // a `value` target with no value gets its value, with no event.
  #takeFirstSelection() {
    this.selected = this.itemTargets.find((el) => el.hasAttribute("data-selected")) ?? null
    if (!this.selected || !this.#selecting) return

    const input = this.#input
    if (input && input.value === "") input.value = this.#labelOf(this.selected)

    const value = this.selected.getAttribute("data-value") ?? ""
    for (const el of this.valueTargets) if (el instanceof HTMLInputElement && el.value === "") el.value = value
  }

  // The first values of the `multiple` selection mode: the items that the server renders with
  // `data-selected`, and the chips with a `data-value` that it shows (a list of the server has no
  // item before the user types).
  #takeFirstValues() {
    const items = this.itemTargets.filter((el) => el.hasAttribute("data-selected")).map(valueOf)
    const chips = this.chipTargets.filter((el) => el.hasAttribute("data-value") && !el.hidden).map((el) => el.getAttribute("data-value"))

    this.values = [...new Set([...chips, ...items])]
  }

  // The state: the open popup, the highlight and the ARIA attributes.
  #render() {
    if (this.selected === undefined) this.#takeFirstSelection()
    if (this.values === undefined && this.#selectingMany) this.#takeFirstValues()
    for (const item of this.itemTargets) if (!this.serverSelected.has(item)) this.serverSelected.set(item, item.hasAttribute("data-selected"))

    // The selected item left the page (a new list of the server): no item is selected.
    if (this.#selecting && this.selected && !this.#selectedItem) this.#select(null)
    this.#makeChips()

    this.#filter()

    const input = this.#input
    const list = this.listTargets[0] ?? this.popupTargets[0] ?? null
    const items = this.#items
    const empty = items.length === 0

    if (list) {
      if (!list.hasAttribute("role")) list.setAttribute("role", this.gridValue ? "grid" : "listbox")
      if (this.#multiple) list.setAttribute("aria-multiselectable", "true")
    }

    // The input is in the popup: the popup is a dialog that the trigger opens, as in Base UI.
    const dialog = this.#dialog
    if (dialog && !dialog.hasAttribute("role")) dialog.setAttribute("role", "dialog")

    // A `value` target that is not an input shows the label of the selection, or its `placeholder`.
    const label = this.#selectedItem ? this.#labelOf(this.#selectedItem) : null
    for (const el of this.valueTargets) {
      if (el instanceof HTMLInputElement) continue

      const text = label ?? el.getAttribute("placeholder") ?? ""
      if (el.textContent !== text) el.textContent = text
      el.toggleAttribute("data-placeholder", label === null)
    }

    this.#renderChips()
    for (const chip of this.chipTargets) chip.tabIndex = -1
    for (const remove of this.chipRemoveTargets) remove.tabIndex = -1
    // The row of the chips is a toolbar while a chip shows.
    const chipped = this.#chips.length > 0
    for (const el of this.chipsTargets) {
      if (chipped) el.setAttribute("role", "toolbar")
      else el.removeAttribute("role")
    }

    this.#markLabel()
    const busy = input?.getAttribute("aria-busy") === "true"
    const status = this.#renderStatus(empty, !items.some((el) => !this.held.has(el)), busy)

    for (const el of [...this.popupTargets, ...this.listTargets]) el.toggleAttribute("data-empty", empty)
    for (const el of this.emptyTargets) {
      if (!el.hasAttribute("role")) el.setAttribute("role", "status")
      if (!el.hasAttribute("aria-live")) el.setAttribute("aria-live", "polite")
      if (!el.hasAttribute("aria-atomic")) el.setAttribute("aria-atomic", "true")
      this.#renderEmpty(el, empty && !status)
    }
    for (const el of this.statusTargets) if (!el.hasAttribute("role")) el.setAttribute("role", "status")

    for (const el of this.popupTargets) {
      if (busy && el.getAttribute("aria-busy") !== "true") el.setAttribute("aria-busy", "true")
      else if (!busy) el.removeAttribute("aria-busy")
    }

    const content = !empty || this.emptyTargets.length > 0 || this.statusTargets.length > 0
    // An inline list (no popup) is always open.
    const open = this.#inline || (this.wanted && content)
    const opening = open && !this.#open

    // The highlight stays on the same item, or goes to the first item. A popup that opens with a
    // selection has the highlight on the selected item.
    let active = this.#active
    if (active && isDisabled(active)) active = null
    if (!active && opening) active = this.#selection(items)
    // With `autoHighlight`, the first item gets the highlight when the user wants the popup. With
    // `always`, an open list always has a highlight.
    if (!active && (this.autoHighlightValue === "always" ? open : this.#autoHighlight && this.wanted)) active = nextItem(items, null, "Home") ?? null
    this.highlighted = active

    for (const row of this.rowTargets) if (!row.hasAttribute("role")) row.setAttribute("role", "row")

    // All the items: a closed popup keeps the items that the filter hid, and they get the selection
    // too.
    for (const item of this.itemTargets) {
      if (!item.hasAttribute("role")) item.setAttribute("role", this.rowTargets.some((row) => row.contains(item)) ? "gridcell" : "option")
      // A popup that closes keeps its highlight, as in Base UI.
      if (open) item.toggleAttribute("data-highlighted", item === active)
      item.toggleAttribute("data-disabled", isDisabled(item))

      // The `multiple` selection mode: `aria-selected` is the choice of the browser.
      if (this.#selectingMany) {
        const chosen = this.values.includes(valueOf(item))
        item.setAttribute("aria-selected", String(chosen))
        item.toggleAttribute("data-selected", chosen)

        continue
      }

      // A choice of more than one item: `aria-selected` is the choice of the server.
      if (this.#multiple) {
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
    const clearable = this.#clearable
    for (const el of this.clearTargets) {
      el.hidden = !clearable
      el.toggleAttribute("data-visible", clearable)
    }

    if (input) {
      if (!input.hasAttribute("role")) input.setAttribute("role", "combobox")
      if (!input.hasAttribute("aria-autocomplete")) input.setAttribute("aria-autocomplete", this.modeValue)
      if (!input.hasAttribute("autocomplete")) input.setAttribute("autocomplete", "off")
      if (!input.hasAttribute("aria-haspopup")) input.setAttribute("aria-haspopup", this.#popupRole)
      if (list) link(input, "aria-controls", "ariaControlsElements", list)
      input.setAttribute("aria-expanded", String(open))
      activeDescendant(input, open ? active : null)
      input.toggleAttribute("data-popup-open", open)
      input.toggleAttribute("data-list-empty", empty)
    }

    // The trigger and the input group show the state of the popup, as in Base UI. The trigger points
    // to the list only while the popup is open.
    for (const trigger of this.triggerTargets) {
      if (!trigger.hasAttribute("aria-haspopup")) trigger.setAttribute("aria-haspopup", dialog ? "dialog" : this.#popupRole)
      trigger.setAttribute("aria-expanded", String(open))
      controlWhileOpen(trigger, dialog ?? list, open)
      trigger.toggleAttribute("data-popup-open", open)
      // A trigger that shows the value has `data-placeholder` while no item is selected.
      if (this.#showsValue(trigger)) trigger.toggleAttribute("data-placeholder", this.#selectedItem === null)
    }

    // The input group has `data-placeholder` while no item is selected, as in Base UI.
    const placeholder = this.#selecting ? this.#selectedItem === null : this.#selectingMany && this.values.length === 0
    for (const group of this.inputGroupTargets) {
      if (!group.hasAttribute("role")) group.setAttribute("role", "group")
      group.toggleAttribute("data-popup-open", open)
      if (this.#selecting || this.#selectingMany) group.toggleAttribute("data-placeholder", placeholder)
    }

    // A group of items gets the name of its `groupLabel`, as the GroupLabel of Base UI.
    for (const group of this.groupTargets) {
      if (!group.hasAttribute("role")) group.setAttribute("role", "group")

      const label = this.groupLabelTargets.find((el) => group.contains(el))
      if (label) link(group, "aria-labelledby", "ariaLabelledByElements", label)
    }

    if (!this.#inline && open !== this.popup.open) open ? this.popup.show() : this.popup.hide()
    this.rendered = true
    // The selected item of a popup that opens is in view.
    if (opening && active && active === this.#selection(items)) active.scrollIntoView?.({ block: "nearest" })
  }

  // The `multiple` selection mode: the chips of the chosen values show, in the order of the
  // choices, and the input and the `chips` targets show the number of the values. The form fields
  // of a hidden chip are disabled: a form sends only the chosen values.
  #renderChips() {
    if (!this.#selectingMany) return

    const chips = this.chipTargets.filter((el) => el.hasAttribute("data-value"))
    for (const chip of chips) {
      chip.hidden = !this.values.includes(chip.getAttribute("data-value"))
      for (const field of chip.querySelectorAll("input, select, textarea")) field.disabled = chip.hidden
    }

    // The chosen chips go after the last chip (before the input), in the order of the choices.
    const shown = this.values.map((value) => chips.find((el) => el.getAttribute("data-value") === value)).filter(Boolean)
    const order = chips.filter((el) => !el.hidden)
    const last = chips.at(-1)
    if (last && order.some((el, i) => el !== shown[i])) {
      const after = last.nextSibling
      for (const chip of shown) last.parentNode.insertBefore(chip, after)
    }

    const count = this.values.length
    const input = this.#input
    if (input) {
      this.#label(input, "placeholder", count > 0)

      const template = input.getAttribute("data-phem-combobox-description")
      if (template && count > 0) input.setAttribute("aria-description", template.replaceAll("{count}", String(count)))
      else if (template) input.removeAttribute("aria-description")
    }

    for (const el of this.chipsTargets) this.#label(el, "aria-label", count === 0)
  }

  // A list of the server in the `multiple` selection mode: a chosen value with no chip gets a chip
  // from the `chipTemplate` target, before the template, and a chip that the browser made leaves
  // with its value. A kept item whose value goes leaves the list.
  #makeChips() {
    if (!this.#selectingMany || this.#clientList) return

    const values = new Set(this.values)
    const chips = this.chipTargets
    // The values of the chips of the server: a chip of the server replaces the chip that the
    // browser made for its value (an `onClick` handler of the item that renders the chip).
    const served = new Set(chips.filter((el) => !this.made.has(el)).map((el) => el.getAttribute("data-value")))
    const shown = new Set(served)

    for (const chip of chips) {
      if (!this.made.has(chip)) continue

      const value = chip.getAttribute("data-value")
      if (!values.has(value) || served.has(value)) chip.remove()
      else shown.add(value)
    }

    const items = this.itemTargets
    for (const item of items) if (this.held.has(item) && !values.has(valueOf(item))) item.remove()

    const template = this.chipTemplateTargets[0]
    if (!template) return

    for (const value of values) {
      if (shown.has(value)) continue

      const item = items.find((el) => el.isConnected && valueOf(el) === value)
      if (!item) continue

      const chip = this.#chipFrom(template, item)
      this.made.add(chip)
      template.before(chip)
    }
  }

  // Removes an attribute of the server render, or gives it back.
  #label(el, name, remove) {
    if (!this.labels.has(el)) this.labels.set(el, el.getAttribute(name))

    const value = this.labels.get(el)
    if (value === null) return

    if (remove) el.removeAttribute(name)
    else el.setAttribute(name, value)
  }

  // The attributes of the state.
  #owns(el, name) {
    if (this.popupTargets.includes(el) && name === "aria-busy") return true
    if (this.popup.owns(el, name)) return true
    if (name === "hidden" && this.filtered.has(el)) return true
    if (name === "hidden" && el.hasAttribute("data-phem-combobox-status")) return true
    if (name === "disabled" && this.#selectingMany && el.matches?.("input, select, textarea") && el.parentElement?.closest('[data-phem-combobox-target~="chip"]')) return true
    if (el === this.#input) {
      // The text of an input with no `phem:bind` belongs to the client.
      if (name === "value" && !el.hasAttribute("data-phem-bind")) return true
      if ((name === "placeholder" || name === "aria-description") && this.#selectingMany) return true

      return ["aria-expanded", "aria-activedescendant", "aria-haspopup", "data-popup-open", "data-list-empty"].includes(name)
    }
    if (this.triggerTargets.includes(el)) return ["aria-expanded", "aria-haspopup", "data-popup-open"].includes(name) || (name === "data-placeholder" && this.#showsValue(el))
    if (this.inputGroupTargets.includes(el)) return name === "role" || name === "data-popup-open" || (name === "data-placeholder" && (this.#selecting || this.#selectingMany))
    if (this.itemTargets.includes(el)) return (name === "aria-selected" && (!this.#multiple || this.#selectingMany || this.choices.chooses(el))) || name === "data-highlighted" || (name === "data-selected" && (this.#selecting || this.#selectingMany || this.choices.chooses(el)))
    if (this.chipTargets.includes(el)) return name === "hidden" && this.#selectingMany && el.hasAttribute("data-value")
    if (this.chipsTargets.includes(el)) return name === "role" || (name === "aria-label" && this.#selectingMany)
    if (this.emptyTargets.includes(el)) return name === "role" || name === "aria-live" || name === "aria-atomic"
    if (this.clearTargets.includes(el)) return name === "hidden" || name === "data-visible" || name === "data-pending" || name === "data-failed"
    if (this.valueTargets.includes(el)) return name === "value" && this.#selecting

    return false
  }

  // There is something to clear: a selected item, a chip, or else a text in the input.
  get #clearable() {
    return this.#selecting ? this.#selectedItem !== null : this.#multiple ? this.#chips.length > 0 : (this.#input?.value ?? "") !== ""
  }

  // The list is open: an inline list (no popup) is open after the first render.
  get #open() {
    return this.#inline ? this.rendered === true : this.popup.open
  }

  // The list is in the page, with no popup: the inline list of a command palette.
  get #inline() {
    return !this.hasPopupTarget && this.hasListTarget
  }

  // The `autoHighlight` value: `true` (when the user wants the popup) or `always`.
  get #autoHighlight() {
    return this.autoHighlightValue !== "" && this.autoHighlightValue !== "false"
  }

  get #popupRole() {
    return this.gridValue ? "grid" : "listbox"
  }

  // The trigger has a `value` target that shows the label of the selected item.
  #showsValue(trigger) {
    return this.#selecting && this.valueTargets.some((el) => !(el instanceof HTMLInputElement) && trigger.contains(el))
  }

  // The popup that has the input and a list: a searchable select. Null when the input is out of
  // the popup.
  get #dialog() {
    const input = this.#input
    if (!this.hasPopupTarget || !this.hasListTarget || !input) return null

    return this.popupTarget.contains(input) ? this.popupTarget : null
  }

  // The browser filters the items: the `filter` value, with no inline `mode`.
  get #filtering() {
    return this.filterValue !== "" && this.modeValue !== "inline" && this.modeValue !== "none"
  }

  // The list belongs to the client: a choice writes the label of the item in the input.
  get #clientList() {
    return this.filterValue !== "" || this.modeValue !== "list"
  }

  // No selected item: the Autocomplete of Base UI. `multiple` is a selection of more than one item.
  get #autocomplete() {
    return this.selectionModeValue === "none" && !this.#multiple
  }

  // More than one chosen item: the `multiple` value, or the `multiple` selection mode.
  get #multiple() {
    return this.multipleValue || this.selectionModeValue === "multiple"
  }

  // The browser keeps the chosen items: the `multiple` selection mode, with a list of the client or
  // of the server.
  get #selectingMany() {
    return this.selectionModeValue === "multiple"
  }

  // One selected item: the `single` selection mode, with a list of the client or of the server.
  get #selecting() {
    return this.selectionModeValue === "single" && !this.#multiple
  }

  // The time of the request of the answer that the morph renders (see phem_controller.js), or null.
  get #sentAt() {
    return this.element.closest("[data-controller~='phem']")?.phemSentAt ?? null
  }

  // The user selected an item (or no item) after this time.
  #selectedSince(time) {
    return time !== null && this.selectedAt !== undefined && this.selectedAt > time
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

  // The input is in the popup: a search of the popup that a `trigger` opens, as the Input inside the
  // Popup of a Base UI combobox.
  get #inPopup() {
    const input = this.hasInputTarget ? this.inputTarget : null

    return input !== null && this.popupTargets.some((el) => el.contains(input))
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

  // A press on the input group out of the input, the chips and the buttons.
  #inGroupSpace(el) {
    if (!(el instanceof Element) || !this.inputGroupTargets.some((group) => group.contains(el))) return false

    return !el.closest('input, textarea, select, button, a, [contenteditable]:not([contenteditable="false"])') && !this.chipTargets.some((chip) => chip.contains(el))
  }

  #chipOf(el) {
    return this.chipTargets.find((chip) => chip === el)
  }

  // The chips that show: the `multiple` selection mode hides the chips of the other values.
  get #chips() {
    return this.chipTargets.filter((el) => !el.hidden)
  }

  #removeOf(chip) {
    return this.chipRemoveTargets.find((el) => chip.contains(el)) ?? null
  }

  get #rtl() {
    return getComputedStyle(this.element).direction === "rtl"
  }
}

// The number of the chips that the choices added: each gets its own id.
let choices = 0

// The value of an item: its `data-value` attribute.
function valueOf(item) {
  return item.getAttribute("data-value") ?? ""
}

// The `aria-activedescendant` of the input: the id of the item, or an element reference when the
// item has no id or a temporary id of the morph (phem_ids.js). No item removes it.
function activeDescendant(input, item) {
  const id = item ? realId(item) : ""
  if (!item) input.removeAttribute("aria-activedescendant")
  else if (id) input.setAttribute("aria-activedescendant", id)
  else if ("ariaActiveDescendantElement" in input && input.ariaActiveDescendantElement !== item) input.ariaActiveDescendantElement = item
}
