# TODO

The work that is still open in `phem/ui-base`. The bundle has no workarounds. A missing feature goes into phem core first, then the bundle uses it.

## Gaps of phem

A gap is a behavior of Base UI v1.9.0 that needs a change in a phem controller. Fix it in phem and in the bundle in the same change: the bundle does not work around it.

- **Submenu: the close after a move to a sibling item.** In phem v0.4.8, it took about 19 ms (Base UI: about 60 ms). No phem change names this timing: measure it again.

These results are different from Base UI, and phem keeps them:

- **Toast: a new toast for the same message.** Base UI shows a new toast for each `add`, unless the `id` is the same. `phem-toast` does not show a toast with the same type, title and message as a toast on the screen: it starts the time of that toast again.
- **Menu: `data-instant="trigger-change"`.** Base UI sets it when a menu with more than one trigger changes its trigger. A phem menu has one trigger, so the value does not apply.

## Open differences in the bundle

These differences need no phem change. The hero demos show the first one, and do not use the others.

- **The two variants of Base UI.** base-ui.com shows the CSS Modules variant of each demo. The bundle copies the Tailwind variant. They are different in three places, and the bundle is equal to the Tailwind variant:
  - the curve of a transition: CSS `ease`, `ease-out` and `ease-in-out` on the page, the default curve of Tailwind in the bundle (the indicator of Tabs, Meter and Progress, and the popups);
  - the Menubar trigger: the page has a hover color and an active color, and no background with a keyboard focus;
  - the Tooltip trigger with a keyboard focus and an open tooltip: transparent on the page, `neutral-100` in the bundle.
- **Drawer: the viewport and the bleed.** The Base UI drawer has a Viewport part and a "bleed" behind the popup (`--bleed`, `--viewport-padding`, and the iOS variants). The bundle has neither, so a drag to the left shows a gap at the edge. `phem-dialog` already has the `viewport` part: this is a change of the bundle classes.
- **Props that Base UI has and the bundle does not have:** `modal` and `disablePointerDismissal` on `Drawer`; `format` and `locale` on `Progress` and `Meter`.
- **Defaults:** the Positioner `align` of Base UI is `center` for a root menu, a select and a combobox. The bundle uses `start`. The hero demos set `start` or have the width of the anchor, so the look is equal.
- **Elements:** `FieldsetLegend` is a native `<legend>` (Base UI: a `<div>`), and it gets no `data-disabled`. The popup and the arrow of a tooltip are `<span>` elements (Base UI: `<div>`). An item of a menu is a `<button>` (Base UI: a `<div>`).
- **Font:** base-ui.com uses its own font ("Die Grotesk A"). The demo uses the system font, so a text is about 1% wider and the description of the OTP field has two lines.

## Browser tests of the demo

`make test-e2e` runs them with Playwright PHP and the Playwright Symfony bundle, with no web server. Each component has at least one test of its main behavior. This plan lists what each test checks now (done) and what to add (open).

| Component | Test | Checks now | To add |
|---|---|---|---|
| Accordion | `DisclosureTest` | Open an item, ArrowDown to the next trigger, one item open at a time, `multiple`, `hiddenUntilFound` (`beforematch`) | Home and End with `rovingFocus` |
| Alert Dialog | `OverlaysTest` | A click outside does not close it, Escape and a close button close it | Focus goes back to the trigger |
| Autocomplete | `PickersTest` | The browser filters the 41 tags of the Base UI demo, a click fills the input, the empty state | Keyboard selection |
| Avatar | `ControlsTest` | The fallback text shows | Image load and error (`data-loading`/`data-error`), the fallback `delay` in a browser (route the image request) |
| Button | `BasicsTest` | `type="button"`, focus, a `<div>` button, the loading state with `focusableWhenDisabled` | — |
| Checkbox | `ControlsTest` | Initial state, the accessible name, a click unchecks it, an enclosing label, a native button with a sibling label and Space, the hidden input in a Field | — |
| Checkbox Group | `ControlsTest` | The group value stays when an item changes, native buttons, the parent checkbox and its mixed state, a nested parent | — |
| Collapsible | `DisclosureTest` | Open and close with a click, `hiddenUntilFound` (`beforematch`) | Keyboard (Enter and Space), the `--collapsible-panel-height` variable |
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
| OTP Field | `FormsTest` | Typing moves to the next slot, a letter is refused, Backspace, the hidden input, `data-complete`, the slot labels, a Field, `alphanumeric`, the separator, `normalize`, `mask` | Paste fills all the slots |
| Popover | `OverlaysTest` | Open with a click, `aria-expanded`, `data-side`, the title names it, a click outside closes it | Escape |
| Preview Card | `OverlaysTest` | Hover opens it, leaving closes it | The `delay` and `closeDelay` values |
| Progress | `BasicsTest` | `data-progressing`, the value grows each second | `data-complete` at 100 |
| Radio Group | `ControlsTest` | A click checks, the arrow keys check and focus, native buttons with sibling labels, the hidden input in a Field | — |
| Scroll Area | `PickersTest` | `data-has-overflow-y`, the `tabindex` of a viewport that scrolls, the overflow edges after a scroll, both scrollbars and the corner, the overflow variables of the fade, a tab list in a viewport | Thumb drag, a click on the track, `data-scrolling` |
| Select | `PickersTest` | Select with a click, the hidden input, the keyboard opens on the selected item, a popup under a pointer that does not move | Typeahead, the scroll arrows |
| Separator | `BasicsTest` | `aria-orientation` | — |
| Slider | `FormsTest` | ArrowRight, End, Home, a range, `step` and `largeStep`, `thumbAlignment="edge"`, vertical, `name` | Pointer drag on the track, `data-dragging` |
| Switch | `ControlsTest` | Initial state, the accessible name, a click on the label, Space, a native button, the hidden input in a Field | — |
| Tabs | `DisclosureTest` | A click selects, the arrow keys move the focus, Enter selects, `data-activation-direction` of the animated panels, link tabs (click and Enter) | The indicator variables |
| Toast | `PickersTest` | A server event shows a toast with a title, one toast for each click, the text behind the last toast hides until hover, F6 moves the focus to the region, each toast is a dialog | Close button, swipe, the limit of visible toasts |
| Toggle | `ControlsTest` | A click and Space toggle `aria-pressed` | — |
| Toggle Group | `ControlsTest` | One pressed item, ArrowRight, `multiple` | Home and End |
| Toolbar | `ControlsTest` | ArrowRight, End, Home, the arrow keys leave a toggle group, a menu trigger, tooltip triggers and a number field input as items | The select in the toolbar |
| Tooltip | `OverlaysTest` | Hover and focus open it, leaving closes it, no `role` and no `aria-describedby` | The delay between two tooltips |

Other open work for the browser tests:

- **Dark mode.** Run a test of each overlay with `colorScheme: dark`, and compare with base-ui.com.
- **Visual check.** Add screenshot tests (`toHaveScreenshot`) for each demo, after the look is final.

## To build here

- **Check the phem fixes.** phem now has a fix for the 15 groups of gaps that the comparison of 2026-11-16 found. Compare each demo page with base-ui.com again in light mode. Fix each gap that is still open in phem and in the bundle.
- **Dark mode check.** Compare each demo page with base-ui.com in dark mode.
