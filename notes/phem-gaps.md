# Phem gaps that are still open

Each item is a behavior of Base UI v1.9.0 (the release that base-ui.com shows) that needs a change in a `phem/phem` controller. The bundle does not work around them.

Status (2026-11-15): phem v0.4.3 is the installed release. It fixes 11 gaps of the audit. The items below are not fixed.

The items come from the comparison of each component with the Base UI docs and hero demos. "Confirmed" items are checked in the Base UI source or in the browser. "To confirm" items come from the docs only: check them in the Base UI source (`packages/react/src/<component>`) or in the live demo before a fix.

## Confirmed

1. **Toast: the F6 key.** In Base UI, F6 moves the focus to the toast region, which is a landmark. `phem-toast` has no key handler. The viewport gets only `aria-live="polite"`: it has no landmark role, no label and no `tabindex`. Fix: make the viewport a labelled region, and move the focus to it on F6.

2. **Button with another tag: Enter and Space.** A Base UI button with `nativeButton={false}` keeps the keyboard. In the bundle, `Button tag="div"` gets `role="button"` and `tabindex="0"`, but no key handler, so Enter and Space do not click it. Phem has no button controller. Fix: a small controller (or a shared helper) that clicks the element on Enter and Space, and that ignores the click of a button with `aria-disabled="true"`. Alternative: the bundle accepts only a native `<button>`.

3. **Menu: a menu that the pointer opens must not lock the page.** `phem-menu` locks the page (no scroll, no pointer events out of the popup) when its `modal` value is true, also when the pointer opened the menu (`openOnHover`). The lock removes the pointer events of the trigger, so the trigger gets `pointerout` and the menu closes, then opens again. Base UI does not lock the page for a hover open. The bundle gives `modal` to each menu by default, but not to a menu with `openOnHover`, so the bundle does not show this gap. A page that sets the two values on the controller shows it. Not tested in the browser: no demo has a hover menu. Fix: in `#renderModal()`, lock only when a click or a key opened the menu.

4. **Combobox: the first selection of the server render.** With the `single` selection mode, the controller takes the selection from the item with `data-selected`, but it does not write the label in the input and the value in the `value` part at connect. The bundle renders the two values itself, from the `defaultValue` and `defaultInputValue` props of `Combobox`, so this is a gap only for markup that does not use the bundle. Fix: at the first render, give the `value` parts and an empty input the values of the selected item.

5. **Combobox: the open popup shows the selected item.** When a Base UI combobox opens with a selection, the selected item has the highlight and is in view. `phem-combobox` opens with no highlight, at the top of the list. Fix: on an open with a selection and no typed text, highlight the selected item and scroll to it.

6. **Combobox: the Empty part.** Base UI keeps the Empty root in the page as a live region, and changes only its content, so a screen reader reads "No fruits found." when the list becomes empty. `phem-combobox` sets `hidden` on the `empty` target, and gives it no role. Fix: keep the target in the accessibility tree with `role="status"`, and hide its content.

7. **Popover: the focus on open.** A Base UI popover gives the focus to its first tabbable element, or to the popup. `phem-popover` gives the focus to an `[autofocus]` element, or to the popup. The hero demo has no tabbable element, so it is equal. Fix: with no `[autofocus]`, focus the first tabbable element.

8. **Popover: the close delay of a hover open.** The Base UI default is 0 ms. `phem_hover.js` uses 100 ms as a minimum. Fix: allow 0.

9. **Dialog: the focus after a touch open.** Base UI gives the focus to the popup itself when a touch opened the dialog, so the keyboard of the device does not show. The native `showModal()` always gives the focus to the first focusable element. Fix: for a touch open, focus the `<dialog>` (it needs `tabindex="-1"`).

10. **Accordion: `data-orientation` and `data-disabled` on the panel, `data-disabled` on the header.** Base UI gives these attributes. `phem-accordion` sets only the open state and `data-index`. The bundle can render them, so this is a gap only for an accordion with a `disabled` value that changes in the browser.

11. **Drawer: `data-nested-drawer-swiping` and `--drawer-frontmost-height`.** Base UI gives them to a drawer that has an open drawer in it. `phem-dialog` has `data-nested-drawer-open` and `--nested-drawers` only.

12. **Collapsible: `data-starting-style` and `data-ending-style` on the root.** Base UI gives them to the root. `phem-collapsible` gives the root only `data-open` and `data-closed`.

13. **Toast: the swipe variables.** The Base UI classes use `var(--toast-swipe-movement-x)` with no fallback. `phem-toast` sets the two variables only during a swipe, so the bundle classes need a `,0px` fallback. Fix: set the two variables to `0px` on each toast when it shows and after a swipe.

14. **Toast: a new toast for the same message.** Base UI shows a new toast for each `add`, unless the `id` is the same. `phem-toast` does not show a toast with the same type, title and message as a toast on the screen: it starts the time of that toast again. This is a design decision of phem. Record it here, because the result is different from Base UI.

## To confirm

15. **Toast: each toast is a dialog with the focus.** The CSS Modules demo of Base UI has a `:focus-visible` rule on the toast root, so the root is probably focusable with dialog ARIA attributes. The `phem-toast` root has no `tabindex`, role or label.

16. **Switch: Enter.** A Base UI switch probably toggles on Enter, as a button. `phem-toggle` toggles a switch on Space only.

17. **Scroll area: the viewport with no overflow.** The Base UI changelog has "Fix focus trapping with a non-scrollable viewport". The viewport is probably out of the tab order when it has no overflow. The bundle always renders `tabindex="0"`, and `phem-scroll-area` does not change it.

18. **Menu: the highlight when the pointer leaves an item.** `phem-menu` keeps `data-highlighted` until another item gets the focus. Check what Base UI does when the pointer leaves the popup.

19. **Menu: the focus on a disabled item.** A Base UI item is a `<div role="menuitem">`, so a disabled item can probably get the focus of the keyboard. The bundle item is a `<button disabled>`, which the keys skip.

20. **Tooltip: `role="tooltip"` and `aria-describedby`.** The Base UI docs say that a tooltip is visual only, and that the trigger must have its own `aria-label`. `phem-tooltip` gives the popup `role="tooltip"` and gives the trigger `aria-describedby`. Check what Base UI v1.9.0 renders.

21. **Preview card: the open on the focus of the keyboard.** `phem-popover` opens the card after the `delay` (600 ms) when the link has `:focus-visible`. Base UI lists `data-instant: 'focus'`, so the focus open has probably no delay and no animation.

22. **Number field: the scrub area.** The place of the virtual cursor, the `teleportDistance` prop, and the hold timings of the buttons (400 ms, then 60 ms) are not in the Base UI docs. Compare them with the source.

23. **Navigation menu and OTP field: the keyboard.** The Base UI docs do not give the keys (the arrow keys between the triggers, Tab into the content, the keys of the OTP slots). Compare them with the source.

24. **Menubar: when `data-instant` is set.** phem v0.4.3 sets `data-instant="group"` when the bar goes from one menu to another. Base UI has more values (`click`, `dismiss`, `trigger-change`). Check the cases.

## Not gaps of phem

These differences need a change in the bundle only. They are in `TODO.md` under "Open differences in the bundle": the viewport and the bleed of the drawer, the props that the bundle does not have (`Slider`, `SwitchRoot`, `Drawer`, `Progress`, `Meter`, `CollapsiblePanel`, `AvatarFallback`), the `align` defaults, the elements of some parts, the disabled look of the Submit button in the form demo, and the font of base-ui.com.
