# TODO

The work that is still open in `phem/ui-base`. The bundle has no workarounds. A missing feature goes into phem core first, then the bundle uses it.

## Waiting for a phem fix

phem v0.4.3 fixes 11 of the 13 gaps from the comparison with the Base UI v1.9.0 docs and hero demos (the release that base-ui.com shows). The two most visible open gaps are below. See [notes/phem-gaps.md](notes/phem-gaps.md) for the full list, with the items to confirm. After each phem release, update `phem/phem` (`composer update phem/phem` in the bundle and in `demo/`) and check this list again.

- **Toast: F6.** In Base UI, F6 moves the focus to the toast region. `phem-toast` has no key for it.
- **Button with another tag: Enter and Space.** A Base UI button that is not a native `<button>` keeps the keyboard. A `Button tag="div"` gets `role="button"` and `tabindex="0"`, but no key handler. Phem has no button controller: decide between a new controller and a native `<button>` only.

## Open differences in the bundle

These differences need no phem change. The hero demos do not use them.

- **Drawer: the viewport and the bleed.** The Base UI drawer has a Viewport part and a "bleed" behind the popup (`--bleed`, `--viewport-padding`, and the iOS variants). The bundle has neither, so a drag to the left shows a gap at the edge. `phem-dialog` already has the `viewport` part: this is a change of the bundle classes.
- **Props that Base UI has and the bundle does not have:** `name`, `thumbAlignment` and `thumbCollisionBehavior` on `Slider`; `uncheckedValue` and `form` on `SwitchRoot`; `modal` and `disablePointerDismissal` on `Drawer`; `format` and `locale` on `Progress` and `Meter`; `hiddenUntilFound` on `CollapsiblePanel`; `delay` on `AvatarFallback` (the bundle has it on `Avatar`).
- **Defaults:** the Positioner `align` of Base UI is `center` for a root menu, a select and a combobox. The bundle uses `start`. The hero demos set `start` or have the width of the anchor, so the look is equal.
- **Elements:** `FieldsetLegend` is a native `<legend>` (Base UI: a `<div>`), and it gets no `data-disabled`. The popup and the arrow of a tooltip are `<span>` elements (Base UI: `<div>`). An item of a menu is a `<button>` (Base UI: a `<div>`).
- **Form demo:** the Submit button does not have the disabled look during the 1 second of the request.
- **Font:** base-ui.com uses its own font ("Die Grotesk A"). The demo uses the system font, so a text is about 1% wider and the description of the OTP field has two lines.

## Browser tests of the demo

`make test-e2e` runs them with Playwright PHP and the Playwright Symfony bundle, with no web server. Each component has at least one test of its main behavior. This plan lists what each test checks now (done) and what to add (open).

| Component | Test | Checks now | To add |
|---|---|---|---|
| Accordion | `DisclosureTest` | Open an item, ArrowDown to the next trigger, one item open at a time | Home and End, `multiple` |
| Alert Dialog | `OverlaysTest` | A click outside does not close it, Escape and a close button close it | Focus goes back to the trigger |
| Autocomplete | `PickersTest` | The browser filters the 41 tags of the Base UI demo, a click fills the input, the empty state | Keyboard selection |
| Avatar | `ControlsTest` | The fallback text shows | Image load and error (`data-loading`/`data-error`), the fallback `delay` (route the image request) |
| Button | `BasicsTest` | `type="button"`, focus | `disabled` and `focusableWhenDisabled` |
| Checkbox | `ControlsTest` | Initial state, the accessible name, a click unchecks it | The hidden input in a form, Space |
| Checkbox Group | `ControlsTest` | The group value stays when an item changes | The parent checkbox and the indeterminate state (needs a demo) |
| Collapsible | `DisclosureTest` | Open and close with a click | Keyboard (Enter and Space), the `--collapsible-panel-height` variable |
| Combobox | `PickersTest` | Client filter, arrow keys and Enter select, the empty state | The clear button, the trigger button, Escape |
| Context Menu | `MenusTest` | A right click opens it, a click on an item closes it | Keyboard, the menu position at the pointer |
| Dialog | `OverlaysTest` | Open, close with a button and Escape, focus back to the trigger | Focus trap, a click on the backdrop closes it |
| Drawer | `OverlaysTest` | Open and close, `data-swipe-direction` | A swipe to dismiss (pointer drag) |
| Field | `FormsTest` | `data-focused`, `data-touched`, `data-dirty`, `data-filled`, no error on blur | The error and `aria-describedby` after a submit |
| Fieldset | `BasicsTest` | The legend names the group | `disabled` on all the fields |
| Form | `FormsTest` | The error of the server after a submit, an empty value focuses the field, a valid submit removes the error | The `typeMismatch`/`patternMismatch` messages |
| Input | `BasicsTest` | Fill a value | `phem:bind` with a server event |
| Menu | `MenusTest` | Open, ArrowDown and End highlight, typeahead, the scroll lock of the modal menu, Escape, a click on an item closes. On the `menu-check-items` page: hover opens the submenu, ArrowLeft closes it, the checkbox items (browser state and server state), the radio items | Home, the disabled item |
| Menubar | `MenusTest` | Hover moves between open menus, ArrowRight, Escape, a disabled trigger | ArrowLeft, the submenus |
| Meter | `BasicsTest` | `aria-valuenow`, the value text | — |
| Navigation Menu | `MenusTest` | A click opens the content, hover changes it, Escape closes it | The arrow keys between the triggers, `data-activation-direction`, `--popup-width` |
| Number Field | `FormsTest` | The buttons, ArrowUp, Shift+ArrowUp | Press and hold, the scrub area, the wheel, `min`/`max` |
| OTP Field | `FormsTest` | Typing moves to the next slot, a letter is refused, Backspace, the hidden input, `data-complete` | Paste fills all the slots |
| Popover | `OverlaysTest` | Open with a click, `aria-expanded`, `data-side`, the title names it, a click outside closes it | Escape |
| Preview Card | `OverlaysTest` | Hover opens it, leaving closes it | The `delay` and `closeDelay` values |
| Progress | `BasicsTest` | `data-progressing`, the value grows each second | `data-complete` at 100 |
| Radio Group | `ControlsTest` | A click checks, the arrow keys check and focus | The hidden input in a form |
| Scroll Area | `PickersTest` | `data-has-overflow-y`, the overflow edges after a scroll | Thumb drag, a click on the track, `data-scrolling` |
| Select | `PickersTest` | Select with a click, the hidden input, the keyboard opens on the selected item, a popup under a pointer that does not move | Typeahead, the scroll arrows |
| Separator | `BasicsTest` | `aria-orientation` | — |
| Slider | `FormsTest` | ArrowRight, End, Home | Pointer drag on the track, `data-dragging` |
| Switch | `ControlsTest` | Initial state, the accessible name, a click on the label, Space | — |
| Tabs | `DisclosureTest` | A click selects, the arrow keys move the focus, Enter selects | The indicator variables |
| Toast | `PickersTest` | A server event shows a toast with a title, one toast for each click, the text behind the last toast hides until hover | Close button, swipe, the limit of visible toasts |
| Toggle | `ControlsTest` | A click and Space toggle `aria-pressed` | — |
| Toggle Group | `ControlsTest` | One pressed item, ArrowRight | `multiple`, Home and End |
| Toolbar | `ControlsTest` | ArrowRight, End, Home, the arrow keys leave a toggle group | The select in the toolbar |
| Tooltip | `OverlaysTest` | Hover and focus open it, leaving closes it | The delay between two tooltips |

Other open work for the browser tests:

- **Dark mode.** Run a test of each overlay with `colorScheme: dark`, and compare with base-ui.com.
- **Visual check.** Add screenshot tests (`toHaveScreenshot`) for each demo, after the look is final.

## To build here

- **Dark mode check.** Compare each demo page with base-ui.com in dark mode. The light mode check matched.
