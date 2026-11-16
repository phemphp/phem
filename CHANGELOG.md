# Changelog

The changes of the `phem/phem` package, its client (`assets/`) and the `phemc` compiler. The
editor extensions have their own changelogs (for example `editors/vscode/CHANGELOG.md`).

The project has no stable release yet: a minor version can break the public API. The entries
that break the code of an application start with **Breaking**.

## Unreleased

- Tab in a modal `phem-dialog` goes from the last element of the dialog to the first one, and
  Shift+Tab goes back, as in Base UI. Before, only `modal="trap-focus"` kept the focus in the
  dialog: Tab in a `showModal()` dialog went from its last element to the browser.
- A drawer (`phem-dialog` with `swipeDirection`) gives the focus to its popup on open, unless an
  element in it has `autofocus`. Before, the first button got the focus and showed a focus ring.
- The trigger of an open `phem-dialog` gets `aria-controls`.
- A press on the backdrop of a `phem-dialog` does not move the focus to the page. After an open
  with the keyboard, the trigger gets the focus back with its focus ring.

- Escape on a `phem-popover` gives the focus back to the trigger only when the focus is in the
  popup. Before, Escape on a preview card that the pointer opened focused the link, and the
  keyboard focus then opened the card again after its delay.
- The tab stop of a `phem-otp-field` is the slot with the focus. Before, Tab from a filled slot
  went to the first empty slot, not out of the field.
- Tab on the last trigger or link of a `phem-navigation-menu` list goes to the next element of the
  page, and the menu closes. Before, the focus went into the content of the viewport again.
- `phem-field` shows a server error again when an event sends the same value again and the answer
  has no new HTML for the field: a submit of the form, an event of the control, or each event for
  a control with `phem:bind`. Before, the field showed as valid.
- The `error` part of the server in a `phem-field` shows the errors of the browser after the user
  changes the value. Before, a cleared control showed no `valueMissing` message after a server
  error. In a field with a part of the server, an `error` part with no `data-match` no longer
  shows, so a message does not show two times.

- A field of the application in the popup of a `phem-combobox` (an input, a textarea, a select,
  editable content) gets the focus of a mouse press again. v0.4.6 kept the focus in the input of
  the combobox for each press in the popup.

- `phem-combobox` keeps the focus in the input on each press in the popup: on the space around the
  items, on a group label, and on a list with `tabindex="-1"`. Before, only a press on an item kept
  the focus, so the text that the user typed after the press did not go into the input.
- The arrow of a popup with a border points at the center of the anchor. `phem_position.js` gives
  the `left` or the `top` of the arrow from the padding box of the popup. Before, the value was
  from the border box, so a border of 1 px moved the arrow 1 px.

- The open animation of a popup in the top layer runs. `phem-popover`, `phem-tooltip`, `phem-menu`,
  `phem-select`, `phem-combobox` and `phem-dialog` give the popup `data-open` and
  `data-starting-style` before `showPopover()` and `showModal()`. Before, the browser computed the
  open styles first, so the popup (and the `::backdrop` of a dialog) showed with no animation.
- `phem-collapsible` and `phem-accordion` measure the content of a panel with no alignment
  (`justify-content`, `align-items`, `align-content`, `justify-items`), as Base UI. Before, a
  panel with `justify-content: flex-end` got `--collapsible-panel-height: 0px`, and showed nothing.
- `phem-toast` measures the height of the content of a toast (its height with `height: auto`) for
  `--toast-height` and `--toast-frontmost-height`, and removes `--toast-frontmost-height` from the
  element when no toast remains, as Base UI. Before, with the CSS
  `height: var(--toast-frontmost-height, var(--toast-height))`, the first toast after the last
  dismiss had a height of 0.
- **Breaking** `phem-tooltip` is only visual, as the Tooltip of Base UI v1.9.0: the popup gets no
  `role="tooltip"`, and the trigger gets no `aria-describedby`. For a description that a screen
  reader reads, give the popup an `id` and `role="tooltip"`, and give the trigger
  `aria-describedby`, in the markup.
- **Breaking** The `empty` part of `phem-combobox` is a live region that stays in the page, as the
  Empty part of Base UI: it gets `role="status"`, `aria-live="polite"` and `aria-atomic="true"`,
  and it has its content only when the list has no item. The controller does not set `hidden` on
  it: remove `hidden` from the markup of the part, and use `:empty` to remove its padding.
- **Breaking** In `phem-menu`, the keys and the pointer go to an item with `aria-disabled="true"`,
  which gets the highlight, as in Base UI. A click or Enter on it does nothing: its handler does
  not run. The keys still skip an item with `disabled`, and the first focus of a menu skips the
  two kinds. An item loses the highlight when the pointer goes out of it, and not to another
  item: the focus goes to the popup.
- **Breaking** In `phem-navigation-menu`, ArrowDown, Enter and Space on a trigger open the content
  with the focus on the trigger, as in Base UI. Tab on the last element of the content goes to
  the next trigger with the menu open. The arrow keys of the list stop at the last trigger (the
  `loopFocus` value is `false` by default), and Home and End do not move the focus. The four
  arrow keys move the focus between the links of a content.
- **Breaking** In `phem-number-field`, a scrub changes the value by one step for each pixel of the
  last movement, after a movement of `pixelSensitivity` pixels, as in Base UI. Before, it was one
  step for each `pixelSensitivity` pixels. The virtual cursor has its center at the pointer, and
  a new `teleportDistance` value keeps it near the scrub area. The scrub area gets
  `role="presentation"`, and `phem.css` stops the scroll of a touch in it. The repeat of a button
  stops at the limit, and while the pointer is out of the button.
- **Breaking** In `phem-otp-field`, Backspace on a slot with a character moves the focus to the
  slot before, as in Base UI. Ctrl+Backspace removes all the characters, ArrowUp and ArrowDown go
  to the first and to the last slot, Ctrl with an arrow key does the same, and a press of the
  mouse selects the character of a slot.
- **Breaking** `phem-popover` gives the focus to the first element of the popup that Tab goes to
  (after `[autofocus]`), and to the popup after an open with a touch. The pointer out of a
  popover or a menu with `openOnHover` closes it after the `closeDelay` value with no minimum of
  100 ms (0 ms by default): the popup stays open while the pointer is in the space between the
  trigger and the popup. A preview card closes at once on the focus out, and gets
  `data-instant="focus"` (an open by the focus) and `data-instant="dismiss"` (Escape).
- **Breaking** `phem-toast` makes its element a landmark and each toast a dialog, as in Base UI.
  The element gets `role="region"`, `aria-label` (the new `label` value, "Notifications"),
  `tabindex="-1"`, `aria-atomic` and `aria-relevant`. A toast gets `role="dialog"`,
  `tabindex="0"`, `aria-modal="false"`, `aria-labelledby` and `aria-describedby`. F6 moves the
  focus to the element, Escape closes the toast with the focus, and only the focus of the
  keyboard expands the stack. Each toast always has `--toast-swipe-movement-x` and
  `--toast-swipe-movement-y` (`0px` with no swipe). The mark `data-phem-toast-live` is now
  `data-phem-toast-owned`.
- New `phem-button` controller: Enter and Space click an element that is not a `<button>`, as a
  Base UI button with `nativeButton={false}`. A button with `aria-disabled="true"` does nothing.
- More behavior of the Base UI v1.9.0 components. A modal `phem-menu` that the pointer opened does
  not lock the page. A menu popup gets `data-instant="click"` (Enter or Space) and
  `data-instant="dismiss"` (Escape), and a menu of a `phem-menubar` gets `data-instant="group"`
  for an arrow key, the focus out and the open of another menu. `phem-combobox` with the `single`
  selection mode gives the input and the `value` part the selection of the server render, and
  opens with the highlight on the selected item, in view. `phem-dialog` gives the focus to the
  popup after an open with a touch. A drawer gets `--drawer-frontmost-height`, and
  `data-nested-drawer-swiping` during the swipe of a drawer in it, which does not move the drawer
  around it. `phem-accordion` gives `data-disabled` to the parts of an item with a disabled
  trigger, and `data-orientation` to its element and its panels. The element of
  `phem-collapsible` gets `data-starting-style` and `data-ending-style`. Enter toggles a switch
  of `phem-toggle`. The viewport of `phem-scroll-area` gets `tabindex="0"` only when it scrolls.
  See docs/controllers.md.
- **Breaking** `phem-accordion` does not move the focus between the triggers with the arrow keys
  by default, as the accordion pattern of the ARIA Authoring Practices says now (and Base UI
  v1.9.0). Set `data-phem-accordion-roving-focus-value="true"` for the arrow keys, Home and End.
- **Breaking** In `phem-combobox`, an arrow key after the last item goes back to the input (no
  highlight), and the next key goes to the first item. A `clear` part has `hidden` when there is
  nothing to clear. A press on a `clear` part keeps the focus in the input.
- Behavior of the Base UI v1.9.0 components that the controllers did not have, from an audit of
  the `phem/ui-base` kit. `phem-combobox` has a `selectionMode` value (`single`: one selected
  item with `data-selected`, a `value` part for a form, no free text, and all the items when the
  popup opens with the label of the selection), an `openOnInputClick` value, and an `inputGroup`
  part that is the anchor of the popup. A modal `phem-dialog` locks the scroll of the page. A
  mouse press in a `content` part of a drawer selects its text, and `data-phem-swipe-ignore`
  stops a swipe. `phem-accordion` has a `header` part with `data-open`, and its parts get
  `data-index`. The triggers of `phem-popover` and `phem-context-menu` get `data-pressed`.
  `phem-menubar` gives `data-instant="group"` to the popups when it goes from one menu to
  another. `phem-select` has a `label` part that names the trigger and gives it the focus on a
  click. The position has the `collision-padding-inline` and `flip` values. `phem-field`
  validates on Enter in an `<input>` control. A limited toast gets `inert`, and the title, the
  description and the close button of a toast get `data-type`. See docs/controllers.md.
- **Breaking** The default markup of a toast has a content part (`[data-phem-toast-content]`)
  around its text: the description is not a child of the toast root now. A CSS rule such as
  `[data-phem-toast] > [data-phem-toast-description]` does not match. A template of the app does
  not change.
- A toast can have a title: `$e->toast('The file is in your downloads.', title: 'Export complete')`,
  the `title` argument of the `Phem\Toasts` methods, and `title` in the detail of the
  `phem:toast` event. `phem-toast` shows it in a new title part (`[data-phem-toast-title]`), and
  removes this part from a toast with no title. A new content part (`[data-phem-toast-content]`)
  gets `data-behind` and `data-expanded`, as the `Toast.Title` and `Toast.Content` of Base UI.
  `assertToast()` takes a `title` argument. See docs/controllers.md.
- `phem-menu` and `phem-context-menu` keep the state of a checkbox item and of a radio item with
  no handler in the browser, as Base UI does. A click on an item with `role="menuitemcheckbox"`
  changes its `aria-checked`. A click on an item with `role="menuitemradio"` checks it and
  unchecks the other radio items of its group. The item gets `data-checked` or `data-unchecked`,
  and sends `phem-menu:change` with `{ checked, value }`. The state stays after the morph of an
  event, unless the server render gives a new `aria-checked`. An item with an `onClick` handler
  takes its state from the server, as before. See docs/controllers.md.
- Accessibility fixes of the controllers, from the browser tests of a Base UI kit. `phem-toggle`
  links a `<span role="checkbox">` or `role="switch"` to the `<label>` around it
  (`aria-labelledby`, or `ariaLabelledByElements` when the label has no id), so a screen reader
  names it. `phem-popover` links a `title`, a `description` and a popup with no id with element
  references, as `phem-dialog` does. A `phem-toggle-group` in a `phem-toolbar` leaves the arrow
  keys and the tab order to the toolbar, when its items are toolbar items. `phem-select`,
  `phem-menu`, `phem-context-menu` and `phem-combobox` highlight an item on a move of the pointer
  (`pointermove`), not when it comes under a pointer that does not move: a popup that opens over
  the pointer keeps the highlight of the keyboard, and a pointer highlight in a combobox does not
  scroll the list. The indicators of a switch and of a checkbox get `aria-hidden="true"`, so the
  check mark is not in the name. A popover keeps the `aria-label` of its popup. In a toolbar, a
  toggle group takes the `data-orientation` of the toolbar. See docs/controllers.md.
- The ARIA links of the controllers do not use the temporary id that the morph gives an element
  with a `key` or a bound field with no id (`phem_ids.js`). Before, after the morph of an event,
  the label of a bound field pointed to an id that was gone (`for="phem-key:bind:…"`), and so did
  the `aria-controls` of the number field buttons and the `aria-activedescendant` of a combobox
  with keyed items. `phem_number.js` uses the `link()` of `phem_aria.js`. See docs/controllers.md.
- Fixes after a review of the new controllers. `phem-combobox` with an inline `mode` sends the
  text of the user for a bound input, not the label of the highlighted item (a field can give
  phem its value in `phemValue`). A state that a controller changes in the click of an event is
  a change before the request, and each answer renders with the time of its own request, also
  when two answers render at the same time. A drawer with snap points lets a touch scroll its
  content at its full height, and places its snap point again after a resize. A bound hidden
  input gets `data-phem-value` (the server value), so a reset gives it back. `trap-focus` skips
  the hidden fields. A drawer with snap points takes the `style` of the server after the morph.
  The controllers share the ARIA links in `phem_aria.js`.
- `phemc` gives E068 for a class or a function with a reserved name of PHP, such as
  `class Switch` or `function List()`. PHP cannot declare it, so the error came only when PHP
  loaded the file. See docs/compiler.md.
- A field with an optional `Bound` prop (`?Bound $value = null`) can have its own `value`, or
  `checked` for a checkbox or a radio button, next to `phem:bind`. The field renders it only when
  the prop is null, as the `defaultValue` of a UI kit input. A bound field ignores it. Before,
  `phemc` gave E033. See docs/forms.md.
- `phem-navigation-menu` gives `data-instant` to the positioner (or to the popup, with no
  positioner) on the first open, and removes it when the open item changes. So a CSS transition
  of the position moves the popup only from one trigger to another, and the first open does not
  slide in from the corner of the page. See docs/controllers.md.
- `phem-checkbox-group` and `phem-toggle-group` give their `value` to a `phem-toggle` item that
  connects after them. Before, the item took its first state from its input or its
  `aria-checked` when it connected, and the group lost a checked value of the server render.
- `phem-menu`, `phem-context-menu`, `phem-tooltip`, `phem-combobox` and `phem-collapsible` give
  no `id` to a part. Before, they gave an `id` to a popup, a group label, a list, an option or a
  panel with no `id`. The morph of an event removed this id, because the server render did not
  have it, so `aria-controls`, `aria-labelledby`, `aria-describedby` and `aria-activedescendant`
  pointed to an id that was not on the page. Now the controller uses the element references of
  ARIA (`ariaControlsElements`, `ariaLabelledByElements`, `ariaDescribedByElements` and
  `ariaActiveDescendantElement`) for a part with no `id`. A part with an `id` from the markup
  still gets its id in the attribute. The highlight of a combobox stays on the same item through
  the morph, or on an item with the same `id`. See docs/controllers.md.
- The state value of a built-in controller (for example the `open` value of `phem-dialog`, or
  the `value` of `phem-radio-group`) keeps the value of the client through the morph of an
  answer when the client changed it after the request of the event left, as a bound field does.
  Before, the answer of an event that left before the last change of the user gave the old
  value back. A bound `<input type="hidden">` that a script changes (with an `input` or `change`
  event) is now dirty, so `phem:bind` and `phem:live` send its value. See docs/controllers.md.
- New `phem-field` and `phem-form` controllers, as Base UI Field, Fieldset, Form and Input. A
  field links its `label`, `control`, `description` and `error` parts, and validates the control
  with the constraint validation of the browser in the `onSubmit`, `onBlur` or `onChange` mode
  (`validationDebounceTime`). An `error` part with `data-match` shows for one `ValidityState`
  key, and an error with no text gets the `validationMessage`. All the parts get `data-valid`,
  `data-invalid`, `data-dirty`, `data-touched`, `data-filled`, `data-focused` and
  `data-disabled`. A server error (a control that the server renders with `aria-invalid="true"`,
  as `phem:bind` does) stays until the user changes the value. `phem-form` adds `novalidate`,
  stops an invalid submit before its `onSubmit` handler sends the event, and focuses the first
  invalid control.
- New `phem-otp-field` controller, as Base UI OTPField: one input for each character of a code,
  with a hidden input that has the full value. Typing moves to the next slot, Backspace removes a
  character and goes back, the arrow keys, Home and End move, and a paste fills the slots. The
  `validationType` (`numeric`, `alpha`, `alphanumeric`, `none`), `mask`, `autoSubmit`,
  `disabled`, `readOnly` and `required` values, `autocomplete="one-time-code"` on the first slot,
  and `data-complete` when all the slots have a value.
- Add the `phem-toggle` controller: a toggle button (`aria-pressed`, `data-pressed`), a switch or
  a checkbox (`role="switch"` or `role="checkbox"`, `aria-checked`, `data-checked`,
  `data-unchecked`), with the `checked`, `indeterminate`, `disabled`, `readOnly` and `required`
  values. A click, a label and Space toggle it. The `input` target keeps a checkbox or a hidden
  input for forms (`name`, `value`, `uncheckedValue`, `form`), and the `indicator` target gets the
  state and the open and close animations. See docs/controllers.md.
- `phem-checkbox-group` works with `phem-toggle` checkboxes, for the items and the parent. It has
  a `value` value (the values of the checked items) and an `allValues` value (the items of the
  parent). The third click on the parent gives back the items that were checked, as Base UI
  CheckboxGroup does. A disabled or read-only item keeps its state. Each change dispatches
  `phem-checkbox-group:change`. See docs/controllers.md.
- Add the `phem-toggle-group` controller: a group of toggle buttons with one `value` state (the
  values of the pressed items), `multiple`, `orientation`, `loopFocus` and `disabled`, and a roving
  focus with the arrow keys, Home and End. An item can be a `phem-toggle` button. Each change
  dispatches `phem-toggle-group:change`. See docs/controllers.md.
- The new `phem-toolbar` controller gives a row of `item` parts (buttons, links, inputs) one tab
  stop. The arrow keys of the `orientation` value, Home and End move the focus, with the
  `loopFocus` value. In a text input, the arrow keys move the caret until it is at the edge of
  the text. An item with `aria-disabled="true"`, an item of a disabled `group`, or an item of a
  toolbar with the `disabled` value stays in the focus order, but a click on it does nothing.
  The parts get `role="toolbar"`, `role="group"`, `role="separator"`, `aria-orientation` and
  `data-orientation`. See docs/controllers.md.
- The new `phem-radio-group` controller makes a radio group from elements that are not native
  inputs: `role="radiogroup"`, `role="radio"` items with `aria-checked`, `data-checked` and
  `data-unchecked`, and an `indicator` part with the animations. The `value` value is the
  state, and it stays through the morph. A click, a click on a `<label>`, Space and the arrow
  keys check a radio, and the arrow keys skip a disabled radio. The `disabled`, `readOnly` and
  `required` values set the ARIA and the data attributes. An `input` part (a hidden input or
  native radio inputs) gets the value and sends `input` and `change`, and the controller
  dispatches `phem-radio-group:change`. See docs/controllers.md.
- The new `phem-avatar` controller hides the `image` part until it loads, and shows the
  `fallback` part while the image is not loaded, after the `delay` value. An image in the cache
  shows at once, and a new `src` loads the image again. The image gets `data-loading` and
  `data-error`, and each change of the status (`idle`, `loading`, `loaded`, `error`) dispatches
  `phem-avatar:status`. See docs/controllers.md.
- The new `phem-number-field` controller edits a number in a text input, as the NumberField of
  Base UI. It has increment and decrement buttons that repeat the step while the user holds
  them, the arrow keys (Alt for `smallStep`, Shift for `largeStep`), PageUp, PageDown, Home and
  End, the mouse wheel (`allowWheelScrub`), and a scrub area with the pointer lock and a virtual
  cursor. It reads and shows the value in the format of the `locale` and `format` values
  (`Intl.NumberFormat`). A hidden input gets the raw value. The value stays after the morph. See
  docs/controllers.md.
- The new `phem-slider` controller selects one value or a range on a track, as the Slider of
  Base UI. A press on the control moves the nearest thumb, and a drag moves it. The keys of the
  native range input in each thumb step the value. The thumbs do not cross
  (`minStepsBetweenValues`, `thumbCollisionBehavior`). The positions are inline styles, as in Base
  UI, and the `value` part shows the values with `Intl.NumberFormat`. The values stay after the
  morph. See docs/controllers.md.
- New `phem-scroll-area` controller: custom scrollbars over a native scroll container, as the
  ScrollArea of Base UI. A drag of a thumb and a press on a scrollbar scroll the viewport. A
  scrollbar with no overflow on its axis gets `hidden`, unless it has `data-keep-mounted`. The
  parts get `data-has-overflow-x`/`-y`, `data-overflow-x-start`/`-end`,
  `data-overflow-y-start`/`-end`, `data-scrolling` and `data-hovering`, and the
  `--scroll-area-thumb-*`, `--scroll-area-corner-*` and `--scroll-area-overflow-*` CSS variables.
  The `overflowEdgeThreshold` value sets the distance before an edge attribute shows. The scroll
  position and the state stay after the morph. See docs/controllers.md.
- New `phem-select` controller: a button that opens a list of options, as the Select of Base
  UI. The `value` value is the state (a JSON array with `multiple`), and the `value` part shows
  the label of the selected item or its `placeholder` (`data-placeholder`). With
  `alignItemWithTrigger` (the default), the selected item goes over the trigger
  (`data-side="none"`). The controller has the keyboard of a listbox (the arrow keys, Home, End,
  a typeahead, Enter, Space, Escape and Tab), a select on a release after a drag from the
  trigger, scroll arrows, groups, an item indicator, the `modal`, `disabled`, `readOnly`,
  `required` and `loopFocus` values, and `data-popup-open`/`data-pressed` on the trigger. An
  `input` part (an `<input>` or a `<select>`) gets the value and sends `input` and `change`
  events, and the controller dispatches `phem-select:change`. The value and the open popup stay
  after the morph. See docs/controllers.md.
- `phem-menu` sends `phem-menu:open` and `phem-menu:close`, and its trigger gets `data-pressed`
  while the menu is open, as in Base UI (not the trigger of a submenu). `open("first")` and
  `open("last")` open the menu with the focus on the first or on the last item. In a vertical
  `phem-menubar`, ArrowRight opens the menu and its popup goes on the `inline-end` side. See
  docs/controllers.md.
- New `phem-menubar` controller (Base UI Menubar): a bar of `phem-menu` menus with one tab stop.
  The arrow keys of the `orientation` value move between the triggers, Home and End go to the
  first and to the last trigger, and `loopFocus` goes from the last trigger to the first one.
  While a menu is open, ArrowLeft and ArrowRight open the menu before or after it on its first
  item, and the pointer on another trigger opens its menu at once. The `modal` value (the default)
  locks the page and keeps the pointer events of the menubar. The `disabled` value opens no menu.
  The menubar gets `role="menubar"`, `aria-orientation`, `data-orientation`, `data-modal` and
  `data-has-submenu-open`. See docs/controllers.md.
- New `phem-navigation-menu` controller (Base UI NavigationMenu): the triggers of a list open
  their content in one shared popup, with the pointer (`delay` and `closeDelay`, 50 ms) and with
  a click. With a `viewport` part, the open content moves into the viewport, and goes back to its
  item when it closes and before a morph. The popup gets `--popup-width` and `--popup-height`
  (the positioner `--positioner-width` and `--positioner-height`) for a transition of the size,
  and the contents get `data-activation-direction`. The arrow keys move between the triggers
  and the links of the list, ArrowDown opens a content, and Escape closes it. The `value` value
  is the state. A link with `aria-current="page"` gets `data-active`. See docs/controllers.md.
- A `phem-popover` with `openOnHover` and a link trigger (`<a href>`) is a preview card, as the
  PreviewCard of Base UI. A click on the link opens the link, not the popup. The keyboard focus
  on the link opens the popup after the `delay`, and the focus out closes it after the
  `closeDelay`. The link stays the only part for the keyboard and a screen reader: it gets no
  `aria-haspopup` and `aria-expanded`, the popup gets `aria-hidden="true"` and no role, and its
  links and fields get `tabindex="-1"`. See docs/controllers.md.
- `phem-dialog` has the parts and the values of Base UI Dialog, AlertDialog and Drawer. The
  `disablePointerDismissal` value keeps an alert dialog open on a tap on the backdrop: only Escape
  closes it. The `modal` value: `false` opens the dialog with `show()` (the page stays active, and
  Escape and a press outside close it), and `trap-focus` also keeps Tab in the dialog. The
  `backdrop` part opens and closes with the dialog, and a `<dialog>` as the `viewport` part holds
  the `popup` part (a press on the viewport outside the popup closes it). A title or a
  description with no id links with the element references of ARIA. A drawer stops at the
  heights of the `snapPoints` value: the `snapPoint` value is the state, and the popup gets
  `--drawer-snap-point-offset`, `--drawer-height` and `data-expanded`. See docs/controllers.md.
- **Breaking** The swipe variables of a drawer are now the variables of Base UI:
  `--drawer-swipe-movement-x`, `--drawer-swipe-movement-y` and `--drawer-swipe-progress` (before
  `--dialog-swipe-*`), and the new `--drawer-swipe-strength`. The popup of a drawer always has
  `data-swipe-direction`, and a swipe that closes it gives `data-swipe-dismiss`. A drawer in a
  drawer gives `data-nested-drawer-open` and `--nested-drawers`.
- In the markup of a class component, a call of a name that is a method of the class calls the
  method: `aria-valuetext={{ text(10) }}` compiles to `this.text(10)`. Before, it compiled to a
  Twig function `text()`, and the render failed with `Unknown "text" function`. A method hides a
  Twig function with the same name, except `render()`. A handler call (`onClick={{ remove(item) }}`)
  does not change. `phemc` gives E067 for a call of a private method. See docs/syntax.md.
- A `Bound` prop can be optional: `?Bound $value = null`. When the tag of the child has no
  `phem:bind`, `phem:bind={{ value }}` binds nothing, and the field is a plain field. Before, the
  render failed with a `TypeError` in `PhemRuntime::bind()`. `phem:error={{ value }}` renders
  nothing for a null prop. See docs/forms.md.
- **Breaking** The default toast styles of `phem.css` apply only to a part with no `class`
  attribute: the `phem-toast` container, `[data-phem-toast]`, `[data-phem-toast-description]`
  and `[data-phem-toast-close]`. A part with classes gets all its look from them. Before, a
  Tailwind utility could not replace a default style, because the utilities are in a cascade
  layer and `phem.css` is not. A toast with a class now hides with `data-limited` and moves with
  the swipe variables only with the styles of the app. See docs/styling.md.
- **Breaking** The state of `phem-tabs` is now the `active` value: the value of the selected tab,
  not its index. Replace `data-phem-tabs-index-value="1"` with the value of the tab. The value
  of a tab and of a panel is its `data-value`, its key, or its position, and a tab shows the
  panel with the same value, so the panels can be in another order. With no `active` value, the
  first tab that is not disabled is selected. A tab and its panel need no `id` now: with no id,
  the controller links them with `ariaControlsElements` and `ariaLabelledByElements`, so a
  function component can render the tabs with no id. See docs/controllers.md.
- `phem-combobox` can filter in the browser a list that the server renders one time: set the
  `filter` value to `contains` or `starts-with`. The match ignores the case and the accents
  (`Intl.Collator`, with the `locale` value). An item that does not match gets `hidden`, a
  `group` part with no visible item too, and the `limit` value shows only the first matches. The
  new `mode` value (`list`, `both`, `inline`, `none`) is the `aria-autocomplete` of the input:
  with `both` and `inline`, the arrow keys write the label of the highlighted item in the input,
  and Escape gives back the text of the user. With a client list, a choice writes the label of
  the item in the input and sends its `input` and `change` events, and the text stays through
  the morph. The popup gets `aria-busy` while an event of the input runs. The server filter does
  not change. See docs/controllers.md.
- **Breaking** A named slot tag is now `<Card.footer>`, not `<Card:footer>`. `phemc` gives E013
  for the old form, with the new name in the hint. The compiled Twig does not change.
- A named slot can be a prop with markup: `<Card footer={{ <em>Saved</em> }} />` is the same as
  `<Card><Card.footer><em>Saved</em></Card.footer></Card>`. The value follows the rules of markup
  in `{{ }}` (one literal, a branch of `?:`, or markup after `and`). `children={{ <p>Body</p> }}`
  also works. The compiler checks a slot prop as a slot tag (variables, feeds, `phem:error`). A
  slot that the tag gives two times, or a slot tag outside its component tag, is E013. Markup in
  a prop that is not an `Html` slot stays E031. See docs/syntax.md.
- `phem:announce` on an element tells a screen reader its new text after the morph of an event
  of the user, also in a component with no handler, such as a grid with no `#[State]` that loads
  its data in `mount()`. The browser announces each text of these elements that the answer
  renders and that was not there before, in the live region of `$e->announce()`. The same text
  again, the page load, a boundary that loads, a poll, `onVisible`, a push and a refresh announce
  nothing. The text is what a screen reader reads: hidden content is not in it, and an element
  in another `phem:announce` element is part of the outer text.
  `ComponentTester::assertAnnounced()` also sees these texts. `phemc` gives E032 for a text value
  of `phem:announce`. See docs/events.md.
- **Security** The `#[IsGranted]` on the class of a component now applies to a handler method
  of a parent class. A subject of `#[IsGranted]` that names a parameter of the handler must come
  from the call in the markup: before, it fell back to a component property with the same name,
  and the voter checked another object than the handler used.
- The `#[IsGranted]` attribute of Symfony protects one event handler: on a handler method, on a
  closure in `render()`, or on the class of a component for all its handlers. The attribute of
  the class and of the method both apply. The subject is the argument of the call in the markup
  (`delete(todo)`), or else a property of the component. Before, Phem ignored the attribute on
  a handler, and the handler ran for every user. See docs/security.md.
- The new `HandlerCallMiddleware` interface gives a middleware the arguments of the call in the
  markup. A Closure or an Expression key of `#[RateLimit]` now gets them in `args`.
- A route file can route a page, as a controller: `controller: App\Ui\BlogPage`. The route gets
  the route values, the events and the access rules of the page. A `#[Page]` with no path has no
  route of its own, and its first route in a route file has the name of the page as an alias.
  A page of the application with no path and no route is an error. See docs/pages.md.
- The events of a page under an import with a localized prefix or a localized host now have the
  route name of the page (`_route`), so the `access_control` rules on route names cover them.
  Phem adds the event routes after all the imports. The event route of a page under a name
  prefix is now `phem_event.acme_blog`, not `acme_phem_event.blog`.
- **Breaking** The default value of a `#[Path]` property or of a parameter of a function page
  makes its route value optional, as for a Symfony controller: `#[Page('/blog/{page}')]` and
  `#[Path] public int $page = 1` match `/blog`. Only the last route values of a path can be
  optional. A requirement without a placeholder name is an error. See docs/pages.md.
- `data-phem-sync-tabs-value="true"` on the `<Root>` element refreshes the other tabs of the same
  page in the browser after an event of the user, with a `BroadcastChannel`: no `#[Broadcast]`
  and no Mercure hub. A hidden tab refreshes when it becomes visible. A page that comes back from
  the Turbo cache refreshes when a message came for it, and a page from the back/forward cache
  always refreshes. A refresh, a `phem:live` update, a load, a poll, an `onVisible` event and an
  event that pushed a `refresh()` through an open hub connection send no message. See
  docs/real-time.md.
- The new `phem-theme` controller keeps the choice of the user between a light, a dark and the
  system theme in `localStorage`, and gives `<html>` `data-theme="light"` or `data-theme="dark"`.
  The new `theme_script()` Twig function goes in the `<head>`: it sets the theme before the first
  paint, so a dark page does not flash white, and gives its `default` to the switches. See
  docs/controllers.md.
- More upload work in the browser. The new `phem-image-input` controller shows a preview of the
  images of a file input, and makes the large images smaller before the submit (`maxWidth`,
  `maxHeight`, `type`, `quality`). `data-phem-upload="chunked"` on a file input (or on the element
  of a drop or a paste) sends the files in chunks before the event: a file can be larger than
  `post_max_size`, a chunk goes again after a network error, and the upload continues after a
  reload. `data-phem-upload="direct"` sends the files to a storage, such as a presigned URL of S3,
  from a `Phem\Upload\DirectUploader` service, which autoconfiguration finds; the handler gets a
  `Phem\Upload\DirectFile`. The browser uploads up to 3 files at the same time, with the retries
  of the events. A chunked upload continues only for a file with the same fingerprint. The files
  of `data-phem-upload` count in `max_upload_size`. The pending chunked uploads have a maximum
  size (each one counts at least one chunk) and a maximum number, and an upload with no chunk
  goes after 15 minutes. New options: `upload_chunk_size`, `upload_dir`, `upload_dir_max_size`
  and `upload_dir_max_files`. `ComponentTester` sends the files of
  `data-phem-upload` as the browser does. `clear-files` is a reserved name of a browser event.
  See docs/forms.md.
- The new `phem-scrollspy` controller marks the link of the section in view, for a table of
  contents: the link gets `aria-current="true"` and `data-active`. A section is current from its
  `scroll-margin-top`, so a click on a link marks the clicked link. The controller dispatches
  `phem-scrollspy:change`. See docs/controllers.md.
- `phem-collapsible` takes a `persist` value: a key for the state in `localStorage`. A banner
  that the user closed stays closed after a reload. Only a click or an action of the user stores
  the state, and the other collapsibles with the same key, also in the other tabs, get it. See
  docs/controllers.md.
- More parts for the built-in controllers. A `phem-menu` in the popup of a menu is a submenu,
  which opens on hover, with a click and with ArrowRight. `data-phem-menu-modal-value="true"`
  locks the scroll and the pointer events of the page while the menu is open. The new
  `phem-context-menu` controller opens a menu at the pointer on a right click or a long press.
  `phem-combobox` takes `multiple`, with `chip` and `chipRemove` parts and their keys.
  `phem-toast` expands the stack on hover (`data-expanded`, `--toast-offset-y`) and closes a
  toast with a swipe (`swipeDirection`). `phem-dialog` takes `swipeDirection` for a drawer that
  closes with a swipe. A touch swipe needs no `touch-action`: a touch in another direction
  scrolls. `phem-sortable` tells each step of a keyboard drag to a screen reader. See
  docs/controllers.md.
- A positioned popup (`phem-popover`, `phem-tooltip`, `phem-menu`, `phem-combobox`) has its
  right place at once: the first place measured a block popup as wide as its container, and only
  a later resize gave it its place.
- **Breaking** The pointer or the focus on the `phem-toast` element stops the time of all the
  toasts, not only of the toast under the pointer.
- Keyboard shortcuts of the page: `phem:shortcut="mod+k"` on an element clicks it, or focuses it
  when it is a field, when the user presses the keys where the focus is. A shortcut runs a
  server handler, a link, or a trigger of a built-in controller. A key with no modifier does not
  run while the user types in a field, and only the shortcuts of an open modal dialog run. The
  tester has `shortcut($key, $modifiers)`. See docs/events.md.
- `phem:key` and `phem:shortcut` take the `mod+` modifier: the Command key on a Mac, an iPhone
  and an iPad, and Ctrl on other systems.
- Built-in controllers for state that belongs to the browser, with no round trip. `phem-count`
  shows the number of characters of a field (`{count}/{max}`, `data-over` above the max) or of
  checked boxes. `phem-reveal` shows and hides the text of a password field. `phem-checkbox-group`
  adds a "select all" box that is checked or indeterminate from its items. `phem-accordion` opens
  one item at a time, with the arrow keys between the triggers. `phem-depends` shows a panel from
  the value of a field, and disables the controls of a hidden panel. `phem-time` shows a
  `<time datetime>` in the time zone and the language of the user, as a relative time that
  updates, and dispatches `phem-time:reached` when a future time comes. See
  docs/controllers.md.
- The morph keeps the value of a touched password field of a Symfony form also when the client
  shows it as a text field.
- The `phem:live` events of the fields of one `phem:bind` property that wait in the queue go in
  one event: a "select all" of 100 checkboxes queues one event, with one `phem:done`, not 100.
- After the morph, a bound field that gets the server value again (a value that the server did
  not take) dispatches `phem:reset`, so the built-in controllers show the new value.

- Scroll and screen-reader support after a morph. `phem:scroll-into-view` on an element that an
  event adds (a new comment) scrolls the browser to it after the morph of an event of the user,
  but not on page load, for a boundary that loads, a poll, `onVisible`, a push or a refresh.
  `phem:keep-scroll` on an element that scrolls keeps the element in view at its place in each
  render, also in a push and when the render replaces the element. `phem:keep-scroll="end"` also
  keeps a chat at its end. `$e->announce('3 results')` and the `Phem\Announcements` service put
  a message in a hidden live region for screen readers, with no toast.
  `ComponentTester::assertAnnounced()` checks it in a test. `phemc` gives E032 for a text value of
  `phem:scroll-into-view`, and for a text other than `"end"` of `phem:keep-scroll`.
- Built-in controllers for complex input, with the design of Base UI: no CSS, named parts, and the
  state in data attributes and CSS variables. `phem-popover` shows a popup next to its trigger,
  `phem-tooltip` a short text on hover and on the focus of the keyboard, `phem-menu` a list of
  actions with the keyboard of a menu (roving focus, typeahead), and `phem-combobox` a list of
  suggestions that the server gives, with the keyboard in the input. `phem-sortable` moves items
  with the pointer and with the keyboard, also to another list of the same `group`, and calls the
  `onCall` handler of the list with `{ id, index, list }`. The popups go next to their anchor with
  `side`, `align`, the offsets and `collision-padding`, flip and shift in the viewport, and get
  `data-side`, `data-align`, `--anchor-width`, `--available-height` and `--transform-origin`.
  A popup with `popover="manual"` shows in the top layer. See docs/controllers.md.
- **Breaking:** the built-in controllers use the conventions of Base UI. `phem-toggle` is now
  `phem-collapsible`, with the `trigger` and `panel` targets, and no Escape or click outside (use
  `phem-popover` or `phem-menu` for a dropdown). The `dialog` target of `phem-dialog` is now
  `popup`, and its `trigger` and `close` targets need no `data-action`. `phem-tabs` moves the focus
  with the arrow keys and selects with Enter or Space (`activate-on-focus` gives the old
  behavior), and takes the orientation from its `orientation` value in place of
  `aria-orientation`. The toasts have the `data-phem-toast`, `data-type`,
  `data-phem-toast-description` and `data-phem-toast-close` attributes in place of the
  `phem-toast-*` classes, a `template` target for the markup of the app, and a `limit` value.
  `phem-clipboard` sets `data-copied` in place of the `copied` class. The parts get `data-open`,
  `data-closed`, `data-starting-style` and `data-ending-style`, and a close waits for the end of
  its animations.
- Load more on view, and polling. `onVisible={{ more }}` runs the handler when the element comes
  into view, and again each time it comes back into view: the trigger of an infinite scroll. An
  element that is still in view after the update of its event sends it again, but only when the
  update moved it (new items before it). `phem:poll={{ 30000 }} onPoll={{ refresh }}` runs
  the handler every 30 seconds while the tab is visible, and `phem:poll={{ null }}` stops it.
  `ComponentTester::visible()` and `poll()` send the events in a test. `phemc` checks the interval
  (E032), and warns about `phem:poll` with no `onPoll` and `onPoll` with no `phem:poll` (W006).
- Call a handler from JavaScript. `onCall={{ move }}` runs the handler when JS code dispatches
  a `phem:call` event on the element or in it, with its own data:
  `el.dispatchEvent(new CustomEvent("phem:call", { detail: { card: 12, index: 0 } }))`. The
  handler gets the detail in `$e->detail`, and must check it. The event needs no `bubbles`.
  `ComponentTester::call()` sends it in a test, and `BrowserEventDispatcher` rejects the name
  `call`.
- Key filters and event modifiers. `phem:key="ctrl+Enter Escape"` sends the `onKeyDown` or
  `onKeyUp` event only for these keys, and the other keys keep their default action.
  `phem:stop` keeps the events of an element and its content from the handlers around it.
  `phem:prevent` cancels the default action of the event of a handler, such as the visit of a
  link. `phemc` checks the keys (E032), and W006 now also warns about `phem:key` with no key
  handler and `phem:prevent` with no handler. `ComponentTester::keyDown()` and `keyUp()` take the
  modifiers, and send no event for a key that `phem:key` does not list.

## 0.3.17 (2026-11-10)

- Feeds for long lists: a public `Phem\Feed` property of a class component, shown with
  `phem:feed={{ messages }}`, keeps its items in the browser. The items are not in the state, and
  an event does not load them again. A handler calls `append()`, `prepend()`, `update()`,
  `delete()` or `reset()`, and the response has a Turbo Stream for each change, after the morph of
  the component. A handler in an item gets the item again: the browser sends its signed item, bound
  to the instance of the component (the page, its route values and the fiber id), and
  the server loads it with `EntityFinder`. A component with a feed runs `mount()` on its first
  render only. `Streams::toComponent(...)->feed('messages')->append($item)` pushes a change to the
  other browsers, and each one renders the item itself, so its handlers work. A feed element
  renders in each render of its component. `phemc` checks the
  feed element: E065 (not a `Feed` property, or another place) and E066 (other content than one
  loop on the feed with one element for each item). `ComponentTester` applies the streams of the
  feeds and the pushes of a feed.
- The internal `Phem\Stream\Stream` class is now `Phem\Stream\TurboStream`.
- `Validation::hasError($field)`: `true` when the field has an error. A template checks it before
  it shows the error: `{% if validation.hasError('email') %}`.
- **Breaking** The `Phem\Component` trait (`setState()`) is removed. No code used it. Set the
  state properties directly.
- A new doc, `docs/configuration.md`, lists all the options of the bundle.
- The client sends 100 `<Suspense>` boundaries in one load event at most, the limit of the
  server. With more boundaries, the server answered `400`, and the client loaded each boundary
  alone.
- The language server completes `onFocusIn` and `onFocusOut`.

## 0.3.16 (2026-11-09)

- Class names in markup: a capitalized name that the file imports with `use` (or a component or
  an enum of the namespace) is the class, not a variable. `OrderStatus.Cancelled` reads a case or
  a constant, `OrderStatus.cases()` the cases of an enum, and `path(ProductPage, {product:
  product})` or `url(...)` links to a page. `phemc` checks the names: E062 (no such case of an
  enum of the sources), E063 (not a page with a route) and E064 (a class name in another
  place). Twig checks the cases of the other classes when it compiles the template
  (`phem:warmup`). The editors complete the cases after `OrderStatus.` and the pages in
  `path(`, with their import.
- `PageUrlGenerator` and `RedirectTo` take the values by prop: a prop gives its route value, an
  entity the value of its mapped field (`['product' => $product]` for `/products/{id:product}`),
  and a backed enum its value. `PageUrlGenerator::url()` gives an absolute URL. The route
  values of each page are in a cache file (`cache:warmup` writes it), so a link doesn't load the
  routes.
- Toast messages from a handler: `$e->toast('Saved')`, or `$e->toast('No stock',
  ToastType::Error, 0)` with a type and a duration in ms. A component or a service injects
  `Phem\Toasts` (`$this->toasts->error('…')`), with a method for each type. An empty message,
  or a duration below 0 or above `Toasts::MAX_DURATION` (2147483647 ms), throws an
  `InvalidArgumentException`. `ComponentTester::assertToast()` checks a toast of the last action,
  as the `phem-toast` controller shows it. The error toast of a `#[MapForm]` without a
  `Validation` property uses it too, with the first error that has a message.
- The worker of an `#[Async]` handler logs a warning when its work dispatches a browser event (a
  toast): the worker has no response, so the browser does not get it.
- Entity broadcasts: `#[Broadcast(on: [Order::class, OrderLine::class])]` on a page or a
  component names the Doctrine entities that it shows. After each flush that inserts, updates
  or deletes one of them, Phem refreshes the page or the component in every browser. A `#[Path]`
  entity selects the URL of a page, and a `#[BroadcastKey]` entity selects the instances of a
  component. A related entity follows its to-one association. With no such prop, all the URLs or
  all the instances refresh. A field list (`[Order::class => ['status']]`) limits the refresh
  after an update. `toPage()` takes the entity of a `#[Path]`. The streams go out at the end of a
  console command and of a Messenger message too.
- Hint H001: a variable that only copies a property for the markup (`$rows = $this->lines;`).
  The markup reads the properties of the class, also the private ones. The editors fade the copy,
  and the quick fix removes it and uses the name of the property in the markup. A copy that can
  keep an older value of the property isn't a hint. `phemc build` and `phemc check` don't print
  hints.

## 0.3.15 (2026-11-08)

- The error of a handler on a field: `Validation::withError()` adds an error that no constraint
  finds, for example a name that the database has already
  (`$this->validation = $this->validation->withError('name', 'This name is taken.')`). The field
  shows it as the error of a constraint: `phem:bind`, `phem:error`, `error()`, `errors()` and
  `valid()` see it, and it goes before the error of a constraint on the same field. The state
  keeps it for the next events. It goes when the user changes the field, on the next submit of a
  handler, or with `new Validation()`. The error of the whole component (`''`) goes only on a
  submit or with `new Validation()`. A path (`draft.name`) and a `Bound` prop work too.
- `data-phem-guard` asks in a `<dialog>` of the page: `data-phem-guard-dialog="#confirm"` gives
  the selector of the dialog, and `data-phem-guard-accept` the label of its accept button. The
  dialog is the same as for `phem-confirm`. The guard cancels the click of a Turbo link, and
  after a confirm it visits the link with its `data-turbo-action` and no second question. Escape,
  a tap on the backdrop and `turbo:before-cache` close the dialog. A visit of a script or of a
  Turbo form, no dialog, or a dialog that cannot ask, asks with `window.confirm()`. The question
  of `beforeunload` stays the question of the browser.
- Fix: a guard whose confirmed visit another `turbo:before-visit` listener cancels asks again.
  Before, the guard and `beforeunload` asked no more on that page.
- **Breaking**: phemc: an `aria-invalid` on a bound field is the error E033, so the build of an
  app with one fails. `phem:bind` sets `aria-invalid` from the `Validation` property: remove the
  attribute, and show the error with `phem:error`. For an error that no constraint finds, use
  `Validation::withError()`.

## 0.3.14 (2026-11-08)

- Fix: the `phem-confirm` dialog closes on a tap on the backdrop, with no event (`phem-dialog`
  too), and on `turbo:before-cache`: a visit back shows no old question.

## 0.3.13 (2026-11-08)

- Draft objects in `#[State]`: a `#[State]` property can keep an object of a plain class (a
  draft of a form, a date). The Serializer encodes it in the state, and `phem:bind` binds its
  public properties with a path (`phem:bind={{ draft.name }}`). A handler can change the
  object in place: a child that gets it renders again only when it changed. A Doctrine entity in
  `#[State]`, also in a property of a draft, throws a `LogicException`.
- A bound date field shows the date in the format of the `type` of the input (`Y-m-d` for
  `<input type="date">`), in the timezone of the date. The server reads the new text in the
  same timezone.
- Redirect before a page renders: a function page, or the `mount()` of a class page, can
  return a `RedirectTo`. The GET of the page ends with a 302, and the page does not render
  (`function VerifyLink(string $token): RedirectTo`). A function page, or a page with no
  `#[State]`, can also redirect in an event. The `mount()` of a component that is not a page
  throws a `LogicException`.
- `RedirectTo` gets a `headers` argument: the headers of the redirect response
  (`new RedirectTo(AccountPage(...), headers: ['Referrer-Policy' => 'no-referrer'])`).
- Fix: the answer of an event that redirects drops the headers and the cookies of the redirect
  response no more. A handler that returns a `RedirectResponse` with a cookie now sets it.
- Typed form data in a handler: `#[MapForm]` on a parameter maps the form fields of the event
  to an object (`addClosed(#[MapForm] ClosedDates $dates)`). The Serializer converts the
  strings to the types of the properties, and the Validator checks the object. When it is not
  valid, the handler does not run: the errors go to the `Validation` property of the component,
  or the first error goes to an error toast. The map runs before the transaction of a
  `#[Transactional]` handler. Each submit replaces the errors of the last one, and a valid
  submit removes them. Options: `name` (a prefix of the fields), `validationGroups` and
  `serializationContext`. A call in the markup can't give a value to a `#[MapForm]` parameter.
  It needs `symfony/serializer`.
- The errors of a bound field. A bound field with a validation error gets `aria-invalid="true"`
  and the id of the error in `aria-describedby`, after its own ids. The new `phem:error={{ email }}`
  directive renders an element only when the field has an error, with the id of the error and
  the message: `<p phem:error={{ email }} className="error" />`. On a component tag, the
  component gets the `id` and `children` props. The id is `<component id>-<field>-error`, so the
  field needs no `id`. A `Bound` prop works too: the error is the error of the parent property.
- phemc: an invalid `phem:error` is the error E061 (not a `#[State]` property or a `Bound` prop,
  a component with no `Validation` property, an element with an `id` or content).
- Handler arguments in the markup: `onClick={{ pickReason(r) }}` runs `pickReason($r)` when the
  user clicks. The arguments are values of the render: the handler id has their fingerprint, so
  the browser can't change them. They go to the first parameters, and the other parameters get
  the event and the services as before. A closure prop works too: `onPick={{ pick('menu') }}`,
  and the child adds its arguments (`onClick={{ onPick(size) }}`). `phem:loading` and
  `phem:progress` accept a call.
- phemc: a handler call whose arguments don't fit the method of the class component is the
  error E047. **Breaking**: the warning W001 ("calls the handler during render") is gone, because
  a call in an `on*` attribute is now a handler call.

## 0.3.12 (2026-11-08)

- phemc: `mount` or `render` passed as a handler (`onClick={{ mount }}`, `phem:loading={{ render }}`)
  in a class component or a page is the error E046. Phem calls these methods itself: they are
  not handlers. A PHP or Twig variable, a prop or a property of the same name is still a value.

## 0.3.11 (2026-11-08)

- Security: an event has the route name of its page (`_route`), not `phem_event.<name>`. An
  `access_control` rule on a route name (`route: ^admin_`) now applies to the events of the page
  and to a `POST` to its URL. Before, a client that the rule denied could send them.
- Security: `$e->form` takes linear time for many fields of the same name. Before, one small
  gzip event with many fields of one name used seconds of CPU.
- `data-phem-autofocus-keyboard` on a link or on the element of an event opens the keyboard of
  iOS for the field that `phem-autofocus` focuses after the visit or the morph. Before, the field
  got the focus, and no keyboard opened. It works in an open `<dialog>`, and gives the focus back
  to the element of the tap when no field gets the focus.

## 0.3.10 (2026-11-07)

- Error pages: `#[ErrorPage]` on a function of a `.phem` file renders the page of the HTTP
  errors (a 404, a 500), in the document of the application. A status (`#[ErrorPage(404)]`,
  `#[ErrorPage([401, 403])]`) and a path (`#[ErrorPage(path: '/admin')]`) choose the page: the
  longest path wins, then the status. The page gets `int $status`, the
  `FlattenException $exception` of Symfony (or the exception itself for `\Throwable $exception`)
  and the arguments of a function page. It has no state and no events, and it can be a plain
  HTML document with no `<Root>`. The status and the headers of the error stay. The preview at
  `/_error/{code}` shows it (`?path=/admin` for a section), and `error:dump` writes it to static
  files at the host of the router. The container build checks the attributes.
- The option `phem.show_exceptions` (the debug mode of the kernel by default) shows the page of
  the exception in place of the error pages. Set `false` in the e2e tests.
- `errorPage()` in the tests renders the error page of an error at a URL, with a session.
- `visit()` in the tests runs Symfony in the web mode, as `WebTestCase`: an HTTP error renders
  the error page of the application, not the output of the CLI error renderer.
- phemc reports E059 for `#[ErrorPage]` on a class, E060 for `#[ErrorPage]` in a package, and
  E053 for `#[Broadcast]` on an error page. An error page used as a tag is E056. The hover of
  the editors shows the statuses and the paths of an error page.

## 0.3.9 (2026-11-07)

- Fix: phemc did not pass a local to the template when only a tag (`{% if missing %}`) used it
  and its name was `missing`, `ignore`, `only`, `key` or `sandboxed`. The page failed at runtime
  with `Variable "missing" does not exist`, and `phemc check` gave no error. The words
  `ignore missing`, `with` and `only` are now keywords only after the expression in
  `{% include %}`, `{% embed %}` and `{% with %}`, as in Twig: `{% with a ~ only %}` reads `only`.
- Fix: phemc did not pass a local named `with`, `if`, `starts`, `ends`, `has`, `some` or `every`.
  These words are keywords only in the operators `starts with`, `ends with`, `has some` and
  `has every`: `{{ with }}` reads `$with`.
- Fix: in `{% if flags b-and 4 %}`, phemc read `b` as a variable and gave an error. It now reads
  `b-and`, `b-or` and `b-xor` as operators.

## 0.3.8 (2026-11-07)

- Fix: after an event, a kept component with slots showed old HTML when its owner rendered again
  and the slots read an object that the handler changed in place (an entity). The slots now
  belong to the component that gives them, as in React: a component with slots renders again each
  time that its owner renders. A component with props only is still kept when it gets the same
  objects. Before, only a function component with a body of one `return <markup>;` (a template
  component) rendered its slots again: a statement in the body, for example `$x = ...;`, made the
  page show old HTML.

## 0.3.7 (2026-11-05)

- Fix: in the tests, the browser kept a cookie with an empty `Domain` (for example the session
  cookie of PHP, with the default `session.cookie_domain`) on no host, so it did not send the
  cookie. As a browser, it now ignores an empty `Domain`: the cookie goes to the host of the page.

## 0.3.6 (2026-11-05)

- The `visit()` of the tests takes cookies for the browser (`cookies: ['currency' => 'EUR']`),
  on `InteractsWithPhem` and on a page. `cookies()` gives the cookies that the browser of a page
  sends to its URL, sorted by name.
- Fix: in the tests, `visit()` and `followRedirect()` of a page opened a relative URL on
  `localhost`. They now open it from the URL of the page, as a link does.

## 0.3.5 (2026-11-05)

- **Breaking** The file extension of the components is now `.phem` (before: `.phpm`). The open
  tag is now `<?phem` (before: `<?phpm`), and the Twig namespace of the compiled templates is now
  `@phemc` (before: `@phpm`). Rename your files, and change each `<?phpm` tag to `<?phem`: an
  old `<?phpm` tag is now an error (E006).

## 0.3.1 (2026-11-03)

- Phem needs PHP 8.4 or later (before: 8.5). A `.phpm` file can use the syntax of PHP 8.5 (the
  `|>` operator, a closure in an attribute such as the key of `#[RateLimit]`) only when the
  application runs on PHP 8.5.

## 0.3.0 (2026-11-03)

- No change in the code. The Flex recipe of `phem/phem` starts at this version
  (`phem/phem/0.3/` in symfony/recipes-contrib): it enables `PhemBundle`, adds the document of
  the pages, the first page at `/`, and the `phemc` worker of `symfony serve`.

## 0.2.7 (2026-11-03)

- Fix: the morph kept the files of a file input, so the next submit sent them again. A handler
  calls `$e->clearFiles()` when it takes the files, and the controller empties the file inputs
  that sent them. A handler that shows a validation error does not call it, so the user does not
  select the files again. A file input with files makes its form dirty (`phem-dirty`, and
  `data-phem-guard`).

## 0.2.6 (2026-11-03)

- Fix: the Tailwind merger removed the custom values of the theme. For example, with
  `--text-caption` in `@theme`, a class list with `text-caption` and then `text-ink-muted` gave
  `text-ink-muted`: tailwind-merge put the size with the text colors. The new
  `phem.class_merger.tailwind.css` option reads the custom names of the `@theme` blocks of the
  Tailwind entry CSS, and the new `phem.class_merger.tailwind.theme` option gives them in the
  configuration (`theme: { text: [caption, price-lg] }`).
- Live validation of a Symfony form. A handler of `onChange` on the `<form>` calls
  `handleRequest()`, and the view of the form shows the errors of the fields that the user
  touched only. The form keeps all its errors, so `isValid()` checks all the fields. The server
  marks the touched fields with `data-phem-touched`, and a submit touches all of them.
  `TouchedFields::touchAll($request)` does the same for a handler of another event (a save with
  a click). Phem decorates the request handler of the forms when `symfony/form` is installed.
- **Breaking:** `onChange` on a `<form>` gets each key of its text fields, as in React. Before, it
  got the native `change` event only (when the user left the field). Add
  `phem:debounce={{ 'blur' }}` to keep the old behavior. A `phem:live` field still sends its own
  events.
- **Breaking:** a submit, a change or a click of an element in a form sends the fields of the
  form (`$e->form` and the POST data of the `Request`), also for a control with the `form`
  attribute. The fields go with their values when the event leaves. Only the submit sends the
  files: before, a change of a form sent them too.
- The morph keeps the values of the form fields with no `phem:bind` that the user changes while
  an event runs. For a field that the server marks as touched, it also keeps the value of the
  focused text field and of a password field.
- Fix: a change that `phem:before-queue` cancels (for example with `phem-confirm`) gives the old
  value back to the field that changed, also when the handler is on its form.
- `phem:debounce={{ 'blur' }}` on a `<form>` sends the event when the user leaves one of its
  fields. Before, the event waited for another event.
- Fix: a morph after an event or a push no longer replaces a bound field that the user edits.
  Before, when a component before the field went away (for example the `<FieldError>` of a
  field that became valid), the next component of the same class got its fiber id. The morph then
  matched the parent of the field with another element, and built the field again: the typed
  value and the focus were lost. While a morph runs, each bound field now has an id from its
  `phem:bind` key (and from its value, for a checkbox or a radio button), so the morph matches the
  field and its parents.

## 0.2.5 (2026-11-02)

- Live validation of the `#[State]` properties. A `#[State]` property of the type
  `Phem\Validation` turns it on for a class component. The bound values of an event touch their
  fields, and the errors of the touched fields go into the `Validation`.
- `#[Validated]` on a handler method or a closure touches all the fields and validates before
  the transaction. When there are errors, the handler does not run. It takes validation groups.

## 0.2.4 (2026-11-02)

- Install Phem in 3 commands: `symfony new --webapp`, `composer require phem/phem` and
  `symfony serve`. The page routes go into the `routing.controllers` resource (Symfony 7.4+),
  so an application needs no routes file. The `type: phem` import still works.
- `config/preload.php` needs no change: the container preload file includes
  `phem-preload.php` from the build directory. `symfony/stimulus-bundle` is now required.
- **Breaking:** `phemc` does not write a `preload.php` file any more.
- Compile the `.phpm` components of the Composer packages with the application, and show them
  in the editor.
- `#[Component(replaces: ...)]`: a component of the application replaces a component of a
  package in all the tags of the project. `phemc` reports E016 for a replacement that does not
  keep the props and the slots of the original, and E017 for a replacement that it cannot use.
- Theme packages: a base package has abstract classes (the props, the state, the handlers and
  `#[Provide]`), and each theme extends them. `#[Provide]` on a parent class applies to its
  children. `#[Component]` or `#[Page]` on an abstract class is E045.
- `<Dynamic>` renders an element with a tag name from a value. It refuses the unsafe elements.
- Merge the classes of a component with the classes of its caller: `classes()` gives a
  `Phem\ClassList`. Set `phem.class_merger.tailwind` to remove the conflicting Tailwind classes.
- Convert a string or an int prop to a case of its backed enum.
- Publish to a standalone Mercure hub (for example with PHP-FPM): set
  `phem.mercure.publisher_key` and `phem.mercure.publish_url`. A page with `#[Broadcast]` and no
  hub logs a warning.
- The client stops after the hub refuses 3 connections in a row, with a warning in the console.
  Before, a page with no hub sent a refresh every 5 seconds.

## 0.2.3 (2026-10-28)

- New Stimulus controllers: `phem-optimistic` (optimistic changes, matched to their events by
  DOM event id), `phem-toggle`, `phem-dialog`, `phem-tabs`, `phem-toast`, `phem-clipboard`,
  `phem-autofocus` and `phem-confirm`.
- Stream the `<Suspense>` boundaries in the response of the page.
- The fiber id goes on the root element of a component. A component that renders a text, more
  than one element or another component still gets a `<phem-c>`.
- Send one placeholder for a run of kept components, and a patch of the render shape.
- Send a patch of each state entry that changed only a bit.
- Check that the browser can send the state of a page back in an event. In debug mode, a state
  larger than `phem.max_event_size` throws. In production, the server logs an error.
- **Breaking:** `phemc` writes its output to `var/phem`, not to `var/cache/phem`, so
  `rm -rf var/cache` keeps it.
- Fix: the markup values render one time in each render pass. Before, a consumer in a slot of
  a component that rendered alone could keep the old value of its context.
- Performance: the fibers of a request are freed with no run of the cycle collector, and the
  loaded templates of the markup literals stay for the request.

## 0.2.2 (2026-10-26)

- After a handler of a component with `#[Broadcast]`, the other browsers render the component
  again. `#[Broadcast]` on some handler methods limits this to these handlers.
- `#[BroadcastKey]` marks the props that identify an instance: the refresh goes only to the
  instances with the same values. `phemc` reports E057 for a `#[BroadcastKey]` that is not on a
  prop of a component that broadcasts.
- **Breaking:** `Streams::toComponent()` takes the values of the key props, and `refresh()`
  takes no target.
- The browser that sent the event does not render the component of the handler again.

## 0.2.1 (2026-10-25)

- **Breaking:** rename the `#[Subscribe]` attribute to `#[Broadcast]`.
- Renew the hub topic of a broadcast component only when it renders in the event. A viewer who
  loses access to a component stops to get its pushes when the topic expires.

## 0.2.0 (2026-10-24)

- `#[Subscribe]` can mark a component.
- **Breaking:** `Streams::to()` is now two methods: `toPage()` and `toComponent()`.

## 0.1.3 (2026-10-24)

- `#[Subscribe]` can mark a function page. `Streams::to()` takes the first-class callable of a
  function page (`Guestbook(...)`).
- The language server finds the references of a component, a function or a method, and shows
  a usage count above each one.

## 0.1.2 (2026-10-24)

- The `<Suspense>` boundaries that start to load together send one load event. After a failed
  load, each boundary loads alone.
- A state smaller than `phem.state_cache_min_size` (4096 bytes) goes with each event and is not
  cached.

## 0.1.1 (2026-10-24)

- No change in the package. The repository has no quickstart app any more, and the dev Docker
  image moved to `docker/`.

## 0.1.0 (2026-10-24)

The first release.

- `.phpm` components: class and function components with JSX-like markup and Twig, compiled by
  `phemc` to PHP and Twig. Props (`#[Prop]`, rest props), slots, `#[State]` and `Phem\Node`.
- Pages: `#[Page]` on a class or a function, the route values as `#[Path]` props (also
  entities), and `#[Url]` properties in the query string.
- Event handlers with the arguments of a Symfony controller, `RedirectTo` and a
  `RedirectResponse`. The client morphs the page with Turbo Streams.
- Form fields bound to `#[State]` properties with `phem:bind` and `phem:live`, also in an array
  path and from a child component. File uploads, with `phem:progress`.
- Loading states (`phem:loading`, `aria-busy`), form locks, `phem:debounce`, `phem:throttle`,
  retries, an offline class and view transitions.
- Render only the components that can change after an event. The signed state goes into a
  cache, and an event sends only its signature.
- `<Suspense>`, `<ErrorBoundary>`, contexts (`#[Provide]` and `#[Context]`) and Stimulus client
  code, with list items morphed by `key`.
- Real-time pushes with `#[Subscribe]` and the Mercure hub of FrankenPHP.
- `#[Transactional]`, `#[RateLimit]` and `#[Async]` handlers, and server-to-browser events.
- Test helpers for components and pages, and a Phem panel in the Symfony profiler.
- The `phemc lsp` language server, used by the VS Code and PhpStorm plugins and by the Claude
  Code plugin.
