# TODO

The work that is still open in `phem/ui-base`. The bundle has no workarounds. A missing feature goes into phem core first, then the bundle uses it.

## Waiting for a phem fix

See [notes/phem-gaps.md](notes/phem-gaps.md) for the full list.

- **Menu checkbox and radio items in the browser (gap 5).** Base UI toggles `Menu.CheckboxItem` and `Menu.RadioItem` in the browser (`defaultChecked`, `defaultValue`). `phem-menu` has no client state for them. Now the server gives `checked`, with an `onClick` handler. After the fix, add `defaultChecked` to `MenuCheckboxItem`, `defaultValue` to `MenuRadioGroup`, and the `data-checked`/`data-unchecked` attributes from the controller.
- **Toast title and `data-behind` (gap 6).** `phem-toast` has one message part only. Base UI has `Toast.Title` and `Toast.Description`, and `data-behind` on the content of a stacked toast. After the fix, add `ToastTitle` to the template of `ToastViewport`, and the `data-behind` classes.

phem v0.4.1 does not fix these gaps. It fixes gaps 14 to 17. After each phem release, update `phem/phem` (`composer update phem/phem` in the bundle and in `demo/`) and check this list again.

## Browser tests of the demo

`make test-e2e` runs them with Playwright PHP and the Playwright Symfony bundle, with no web server. Each component has at least one test of its main behavior. This plan lists what each test checks now (done) and what to add (open).

| Component | Test | Checks now | To add |
|---|---|---|---|
| Accordion | `DisclosureTest` | Open an item, ArrowDown to the next trigger, one item open at a time | Home and End, `multiple` |
| Alert Dialog | `OverlaysTest` | A click outside does not close it, Escape and a close button close it | Focus goes back to the trigger |
| Autocomplete | `PickersTest` | The server gives the items for the typed text, a click fills the input | Keyboard selection, the empty state |
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
| Form | `FormsTest` | A submit focuses the first invalid field | The `typeMismatch`/`patternMismatch` messages, a valid submit |
| Input | `BasicsTest` | Fill a value | `phem:bind` with a server event |
| Menu | `MenusTest` | Open, ArrowDown highlights, hover opens the submenu, the checkbox item, Escape | Typeahead, ArrowLeft closes the submenu |
| Menubar | `MenusTest` | Hover moves between open menus, ArrowRight, Escape, a disabled trigger | ArrowLeft, the submenus |
| Meter | `BasicsTest` | `aria-valuenow`, the value text | — |
| Navigation Menu | `MenusTest` | A click opens the content, hover changes it, Escape closes it | The arrow keys between the triggers, `data-activation-direction`, `--popup-width` |
| Number Field | `FormsTest` | The buttons, ArrowUp, Shift+ArrowUp | Press and hold, the scrub area, the wheel, `min`/`max` |
| OTP Field | `FormsTest` | Typing moves to the next slot, a letter is refused, Backspace, the hidden input, `data-complete` | Paste fills all the slots |
| Popover | `OverlaysTest` | Open with a click, `aria-expanded`, `data-side`, the title names it, a click outside closes it | Escape |
| Preview Card | `OverlaysTest` | Hover opens it, leaving closes it | The `delay` and `closeDelay` values |
| Progress | `BasicsTest` | `aria-valuenow`, `data-progressing` | — |
| Radio Group | `ControlsTest` | A click checks, the arrow keys check and focus | The hidden input in a form |
| Scroll Area | `PickersTest` | `data-has-overflow-y`, the overflow edges after a scroll | Thumb drag, a click on the track, `data-scrolling` |
| Select | `PickersTest` | Select with a click, the hidden input, the keyboard opens on the selected item, a popup under a pointer that does not move | Typeahead, the scroll arrows |
| Separator | `BasicsTest` | `aria-orientation` | — |
| Slider | `FormsTest` | ArrowRight, End, Home | Pointer drag on the track, `data-dragging` |
| Switch | `ControlsTest` | Initial state, the accessible name, a click on the label, Space | — |
| Tabs | `DisclosureTest` | A click selects, the arrow keys move the focus, Enter selects | The indicator variables |
| Toast | `PickersTest` | A server event shows a toast, one toast for each click | Close button, swipe, the limit of visible toasts |
| Toggle | `ControlsTest` | A click and Space toggle `aria-pressed` | — |
| Toggle Group | `ControlsTest` | One pressed item, ArrowRight | `multiple`, Home and End |
| Toolbar | `ControlsTest` | ArrowRight, End, Home, the arrow keys leave a toggle group | The select in the toolbar |
| Tooltip | `OverlaysTest` | Hover and focus open it, leaving closes it | The delay between two tooltips |

Other open work for the browser tests:

- **Dark mode.** Run a test of each overlay with `colorScheme: dark`, and compare with base-ui.com.
- **Visual check.** Add screenshot tests (`toHaveScreenshot`) for each demo, after the look is final.

## To build here

- **Dark mode check.** Compare each demo page with base-ui.com in dark mode. The light mode check matched.
- **README status.** After gaps 5 and 6 are fixed, remove the menu and toast exceptions from the README status.
