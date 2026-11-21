# TODO

The work that is still open in `phem/ui-base`. The bundle has no workarounds. A missing feature goes into phem core first, then the bundle uses it.

## Gaps of phem

A gap is a behavior of Base UI v1.9.0 that needs a change in a phem controller. Fix it in phem and in the bundle in the same change: the bundle does not work around it.

- **Submenu: the close after a move to a sibling item.** In phem v0.4.8, it took about 19 ms (Base UI: about 60 ms). No phem change names this timing: measure it again.

These results are different from Base UI, and phem keeps them:

- **Toast: a new toast for the same message.** Base UI shows a new toast for each `add`, unless the `id` is the same. `phem-toast` does not show a toast with no `id` and the same type, title and message as a toast on the screen: it starts the time of that toast again. A toast with an `id` follows Base UI.
- **Toast: the promise and the custom data.** Base UI `toastManager.promise()` and `data` (any object, also functions) are JavaScript. In phem, a `data-phem-toast-show` button shows the loading toast at the click, the handler answers with a toast with the same `id`, and `data` fills the `ToastData` parts of the template.
- **Menu: `data-instant="trigger-change"`.** Base UI sets it when a menu with more than one trigger changes its trigger. A phem menu that moves to another trigger removes `data-instant`, so the CSS transitions of the move, of the size and of the content run (see the "Animating the Menu" example).

## Open differences in the bundle

These differences need no phem change. The hero demos show the first one, and do not use the others.

- **The two variants of Base UI.** base-ui.com shows the CSS Modules variant of each demo. The bundle copies the Tailwind variant. They are different in three places, and the bundle is equal to the Tailwind variant:
  - the curve of a transition: CSS `ease`, `ease-out` and `ease-in-out` on the page, the default curve of Tailwind in the bundle (the indicator of Tabs, Meter and Progress, and the popups);
  - the Menubar trigger: the page has a hover color and an active color, and no background with a keyboard focus;
  - the Tooltip trigger with a keyboard focus and an open tooltip: transparent on the page, `neutral-100` in the bundle.
- **Drawer: the iOS variants.** The bundle gives the popup a bleed of 3rem past its edge, and has the `DrawerViewport` part. It does not have the iOS variants of the Base UI classes (`supports-[-webkit-touch-callout:none]`, `--viewport-padding`).
- **Props that Base UI has and the bundle does not have:** `format` and `locale` on `Progress` and `Meter`.
- **Defaults:** the Positioner `align` of Base UI is `center` for a root menu, a select and a combobox. The bundle uses `start`. The hero demos set `start` or have the width of the anchor, so the look is equal.
- **Elements:** `FieldsetLegend` is a native `<legend>` (Base UI: a `<div>`), and it gets no `data-disabled`. The popup and the arrow of a tooltip are `<span>` elements (Base UI: `<div>`). An item of a menu is a `<button>` (Base UI: a `<div>`).
- **Font:** base-ui.com uses its own font ("Die Grotesk A"). The demo uses the system font, so a text is about 1% wider and the description of the OTP field has two lines.
- **Theme tokens:** one token has one value, so three colors of the Tailwind variant changed a little. The descriptions and the labels of Tabs use `--muted-foreground` (`neutral-500`, Base UI: `neutral-600`; in dark mode the Tabs labels are `neutral-400`, Base UI: `neutral-300`). The track of ScrollArea uses `--muted` (`neutral-200` / `neutral-800`, Base UI: `black/12` / `white/12`). The `active:` color is a mix of `--accent` and `--foreground`, not `neutral-200` / `neutral-700`.

## Browser tests of the demo

`make test-e2e` runs them with Playwright PHP and the Playwright Symfony bundle, with no web server. Each component has at least one test of its main behavior. This plan lists what each test checks now (done) and what to add (open).

| Component | Test | Checks now | To add |
|---|---|---|---|
| Accordion | `DisclosureTest` | Open an item, ArrowDown to the next trigger, one item open at a time, `multiple`, `hiddenUntilFound` (`beforematch`) | Home and End with `rovingFocus` |
| Alert Dialog | `OverlaysTest` | A click outside does not close it, Escape and a close button close it; an item of a menu opens it, the close confirmation, a detached trigger (focus goes back to it), the payloads, the `open` action on the second trigger | Focus trap |
| Autocomplete | `PickersTest` | The browser filters the 41 tags of the Base UI demo, a click fills the input, the arrow keys, the two Escapes, no request; async search in the handler of a repository with the status of each state and a choice that writes the label; the inline `mode`; the named groups and an emptied input that closes the popup; fuzzy matching in the browser with the keywords and the marks; `limit` with the number of hidden matches; `autoHighlight` and a pointer that leaves the items; a command palette (inline list that scrolls, `autoHighlight="always"`, a new search at each open); a grid that fills its rows again and inserts the emoji at the caret (`insertInto`); Virtualized: 10,000 items, the search of the handler, a choice writes the label | `keepHighlight` with a pointer |
| Avatar | `ControlsTest` | The fallback text shows | Image load and error (`data-loading`/`data-error`), the fallback `delay` in a browser (route the image request) |
| Button | `BasicsTest` | `type="button"`, focus, a `<div>` button, the loading state with `focusableWhenDisabled` | — |
| Checkbox | `ControlsTest` | Initial state, the accessible name, a click unchecks it, an enclosing label, a native button with a sibling label and Space, the hidden input in a Field | — |
| Checkbox Group | `ControlsTest` | The group value stays when an item changes, native buttons, the parent checkbox and its mixed state, a nested parent | — |
| Collapsible | `DisclosureTest` | Open and close with a click, `hiddenUntilFound` (`beforematch`) | Keyboard (Enter and Space), the `--collapsible-panel-height` variable |
| Combobox | `PickersTest` | Client filter, arrow keys and Enter select, the empty state, IDs as values, multiple with chips and Backspace, the input in the popup, async search (single: the search of the handler, the selection of the browser, the selected person that the browser keeps, Escape, the clear button, the status, the error and the empty message; and multiple: a choice with a text closes the popup, the highlight on open, Escape, the status, the error and the empty message), creatable with a dialog, 10,000 items on the server, the named groups | The clear button, the trigger button, Escape |
| Context Menu | `MenusTest` | A right click opens it, a click on an item closes it, the card with a menu button, a submenu | Keyboard, the menu position at the pointer |
| Dialog | `OverlaysTest` | Open, close with a button and Escape, focus back to the trigger; a form that closes it, an item of a menu opens it, a nested dialog, the close confirmation (Go back, Discard resets the text), `autofocus` and `finalFocus`, the outside and the inside scroll, a click outside the box of an uncontained popup, a detached trigger, the payloads, the `open` action on the second trigger | Focus trap, a click on the backdrop closes it |
| Drawer | `OverlaysTest` | Open and close, `data-swipe-direction`, the bleed, a swipe to dismiss (pointer drag); a bottom sheet in a viewport, the nested drawers, a swipe to the full snap point, the indent, a non-modal drawer that a click outside does not close, the mobile navigation, a swipe from the swipe area opens it, the close confirmation, the action sheet, a detached trigger with its payload | The keyboard of a phone (no `visualViewport` change in the browser of the tests) |
| Field | `FormsTest` | `data-focused`, `data-touched`, `data-dirty`, `data-filled`, no error on blur | The error and `aria-describedby` after a submit |
| Fieldset | `BasicsTest` | The legend names the group | `disabled` on all the fields |
| Form | `FormsTest` | The error of the server after a submit, an empty value focuses the field, a valid submit removes the error, the errors of a handler on each field, the values of the form in the handler | The `typeMismatch`/`patternMismatch` messages |
| Input | `BasicsTest` | Fill a value | `phem:bind` with a server event |
| Menu | `MenusTest` | Open, ArrowDown and End highlight, typeahead, the scroll lock of the modal menu, Escape, a click on an item closes; open on hover, the checkbox items (server state), the radio items, `closeOnClick`, the group labels, hover opens the submenu and ArrowLeft closes it, the link items, an item opens a dialog, a detached trigger, the payloads, the `open` action on the second trigger, the arrow, the move to another trigger of a menu that is not modal, the filter (the input keeps the focus, the arrow keys, the empty part, the clear part, the filter of a submenu) | Home, the disabled item, the checkbox items with the browser state |
| Menubar | `MenusTest` | Hover moves between open menus, ArrowRight, Escape, a disabled trigger | ArrowLeft, the submenus |
| Meter | `BasicsTest` | `aria-valuenow`, the value text | — |
| Navigation Menu | `MenusTest` | A click opens the content, hover changes it, Escape closes it, a nested menu (click, Escape, the arrow keys, pointer), a nested inline menu, a large menu in a Scroll Area | The arrow keys between the triggers, `data-activation-direction`, `--popup-width` |
| Number Field | `FormsTest` | The buttons, ArrowUp, Shift+ArrowUp | Press and hold, the scrub area, the wheel, `min`/`max` |
| OTP Field | `FormsTest` | Typing moves to the next slot, a letter is refused, Backspace, the hidden input, `data-complete`, the slot labels, a Field, `alphanumeric`, the separator, `normalize`, `mask` | Paste fills all the slots |
| Popover | `OverlaysTest` | Open with a click, `aria-expanded`, `data-side`, the title names it, a click outside closes it; open on hover, a detached trigger, the payloads of two triggers, the `open` action on the second trigger, the move, the size and the name of the new panel | Escape |
| Preview Card | `OverlaysTest` | Hover opens it, leaving closes it; a detached link, the payloads of two links, the `open` action on the second link keeps the focus, the move, the size and the content of the next card | The `delay` and `closeDelay` values |
| Progress | `BasicsTest` | `data-progressing`, the value grows each second | `data-complete` at 100 |
| Radio Group | `ControlsTest` | A click checks, the arrow keys check and focus, native buttons with sibling labels, the hidden input in a Field | — |
| Scroll Area | `PickersTest` | `data-has-overflow-y`, the `tabindex` of a viewport that scrolls, the overflow edges after a scroll, both scrollbars and the corner, the overflow variables of the fade, a tab list in a viewport | Thumb drag, a click on the track, `data-scrolling` |
| Select | `PickersTest` | Select with a click, the hidden input, the keyboard opens on the selected item, a popup under a pointer that does not move, the label, the placeholder, the template of `multiple`, the markup of an item, the groups | Typeahead, the scroll arrows |
| Separator | `BasicsTest` | `aria-orientation` | — |
| Slider | `FormsTest` | ArrowRight, End, Home, a range, `step` and `largeStep`, `thumbAlignment="edge"`, vertical, `name` | Pointer drag on the track, `data-dragging` |
| Switch | `ControlsTest` | Initial state, the accessible name, a click on the label, Space, a native button, the hidden input in a Field | — |
| Tabs | `DisclosureTest` | A click selects, the arrow keys move the focus, Enter selects, `data-activation-direction` of the animated panels, link tabs (click and Enter) | The indicator variables |
| Toast | `PickersTest` | A server event shows a toast with a title, one toast for each click, the text behind the last toast hides until hover, F6 moves the focus to the region, each toast is a dialog; the anchored toast above the copy button, the top position, the undo action calls the handler, the loading toast becomes the answer, the custom data, the update of a toast with the same id, the varying heights | Close button, swipe, the limit of visible toasts |
| Toggle | `ControlsTest` | A click and Space toggle `aria-pressed` | — |
| Toggle Group | `ControlsTest` | One pressed item, ArrowRight, `multiple` | Home and End |
| Toolbar | `ControlsTest` | ArrowRight, End, Home, the arrow keys leave a toggle group, a menu trigger, tooltip triggers and a number field input as items | The select in the toolbar |
| Tooltip | `OverlaysTest` | Hover and focus open it, leaving closes it, no `role` and no `aria-describedby`; a detached trigger, the payloads of two triggers, the `show` action on the second trigger, the move, the size and the content animation between two triggers | The delay between two tooltips |

Other open work for the browser tests:

- **Dark mode.** Run a test of each overlay with `colorScheme: dark`, and compare with base-ui.com.
- **Visual check.** Add screenshot tests (`toHaveScreenshot`) for each demo, after the look is final.

## Customization

The components take their colors from the theme tokens of `assets/theme.css` (see the README). These changes make them easier to customize.

- **Parts with no rest prop.** `Autocomplete`, `ContextMenu`, `MenuSubmenu`, `ComboboxChipTemplate`, `MenuPayload`, `PopoverPayload` and `PreviewCardPayload` take no `className`. Add a rest prop where the part renders an element.
- **The triggers do not render `Button`.** `DialogTrigger`, `DialogClose`, `PopoverTrigger`, `MenuTrigger`… render a `<button>` with `Style::BUTTON` or with their own copy of its classes. So an app that replaces `Button` (`#[Component(replaces: Button::class)]`) does not change them. Let them render `<Button>`, and one replacement changes all the buttons:

  ```php
  #[Component(replaces: Button::class)]
  function AppButton(?Html $children = null, mixed ...$rest): Node
  {
      return <Button className="h-9 px-4" {{ ...rest }}>{{ children }}</Button>;
  }
  // Today: <Button> changes, <DialogTrigger> and <MenuTrigger> do not.
  ```
- **A state chain wins over the `hover:` of a caller.** `hover:not-data-disabled:not-aria-disabled:bg-accent` is more specific than `hover:bg-blue-700`, and the Tailwind merger keeps both (they have different variants). So `<Button className="bg-blue-600 hover:bg-blue-700">` keeps the hover of the theme. Stop the hover of a disabled part another way (for example `data-disabled:pointer-events-none`, and `aria-disabled:pointer-events-none` with `focusableWhenDisabled`), and use a plain `hover:`.
- **More role tokens.** The Base UI look and the shadcn roles differ in other places too: the border of a popup is `--border` (ink in Base UI, light gray in shadcn), and a disabled control has gray colors (shadcn: `opacity-50`). Check each one, and add a token like `--highlight` where a theme needs another value.
- **The radius of joined controls.** The input and the buttons of `NumberField`, and the bordered `TooltipTrigger`s that a demo joins with `border-l-0`, have no radius, so `--radius` does not change them. A joined group needs the radius on its outer corners only (for example `first:rounded-l-control last:rounded-r-control`, or a group part that rounds and clips).
- **Density.** `h-8`, `px-3` and `text-sm` are fixed in each control (shadcn: `h-9`). A `--control-height` token, or a `size` prop with a recipe, would change all of them together.
- **`data-slot` on each part.** shadcn gives each part a `data-slot` (`data-slot="dialog-popup"`). App CSS, and a parent with `has-data-[slot=dialog-description]:`, can then style a part without `className`. Many parts have a `data-phem-*-target` today, but that is the API of the controller, not a style hook.
- **The fixed colors that are left.** The link of `Toolbar` (`hover:text-blue-700`), the backdrop of `Drawer` (`bg-black` with an opacity), and the cursor of the scrub area of `NumberField` (`drop-shadow-[0_1px_1px_#0008]`).
- **The Tailwind merger.** Without `phem.class_merger.tailwind`, a `className` adds classes and removes none: `<Button className="bg-blue-600">` keeps `bg-background`, and the CSS order picks the color. The README says so. The bundle could warn in debug mode when the merger is off.
- **A second theme in the demo.** A shadcn theme and a theme picker next to the dark switch. It shows the tokens, and an e2e test can check that each component follows the theme.

## To build here

- **Dark mode check.** Compare each demo page with base-ui.com in dark mode.
