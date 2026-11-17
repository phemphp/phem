# Phem gaps that are still open

Each item is a behavior of Base UI v1.9.0 (the release that base-ui.com shows) that needs a change in a `phem/phem` controller. The bundle does not work around them.

Status (2026-11-16): phem v0.4.8 is the installed release. Items 1 and 2 below come from a comparison with the Base UI source. Items 3 to 15 come from a comparison of each demo page with base-ui.com in Chromium (Playwright, real mouse and keyboard input, light and dark mode). Each item was measured two times or more. The paths are in `assets/dist/` of phem.

## Open

1. **Number field: the scrub area.** phem has the scrub math of Base UI, the center of the virtual cursor, the `teleportDistance` value, and the stop of the button repeat at the limit. It does not have:
   - the pointer lock on the `<body>`, and no pointer lock on WebKit;
   - the click after a press with no movement;
   - the 50 ms touch check of the buttons;
   - a step from an empty value.

2. **OTP field: the input.** phem has the keys of Base UI (Backspace, Ctrl+Backspace, the arrow keys, the press of the mouse). It does not have:
   - the buffer for the composition of an IME;
   - the complete event for a paste of an equal value;
   - the input of two characters in one slot.

3. **Accordion and Collapsible: the size of an open panel.**
   - Accordion and Collapsible: an open panel keeps its height in px, so content that grows is cut. Base UI sets `--*-panel-height` and `--*-panel-width` to `auto` at the end of the open transition, and sets the px size again before a close.
   - Accordion: the header gets no `data-closed`.

4. **Toast.**
   - A hover starts the full time again. Base UI continues with the time that remained (`pauseTimers`, `resumeTimers`).
   - The toast is behind the pointer during a swipe: the transform transition stays active. Base UI sets `transition: none` inline while it swipes.
   - A diagonal swipe moves on both axes. Base UI locks the axis at the first move.
   - A `blur` of the window does not stop the timers.
   - The Tab order in the stack is the oldest toast first (Base UI: the newest first). A mouse click on the close button moves the focus to another toast (Base UI: only with a visible focus).
   - Attributes: no `aria-hidden` on the close button of a stack that is not expanded, `data-swipe-direction` only at the release, and `data-type="info"` on a toast with no type.

5. **Dialog, Alert Dialog, Drawer.**
   - Tab goes out of the modal to the browser UI. Base UI keeps the focus in the popup. `phem_dialog_controller.js:189` wraps Tab only for `trap-focus`.
   - Drawer: the first focus goes to the first button. Base UI focuses the popup (`tabindex="-1"`), so no button shows a focus ring.
   - The open trigger has no `aria-controls`.
   - After a keyboard open and a press on the backdrop, the trigger gets the focus with no focus ring.

6. **Popover and Preview Card.**
   - Preview Card: `data-instant` stops the animation of a focus open, a blur close and an Escape close (`phem_transition.js:27` and `:53`). Base UI only sets the attribute, and the CSS decides. The same applies to a menu: the Menu and Context Menu classes of Base UI have no `data-instant:transition-none`, so a keyboard open and an Escape close must animate.
   - Popover: no focus return to the trigger after a press outside.
   - Preview Card: the popup has `aria-hidden="true"`.

7. **Tooltip.**
   - Two tooltips show for about 100 ms when the pointer or the focus moves between triggers. Base UI ends the close of the old tooltip at once with `data-instant="delay"`.
   - A press on the trigger closes the tooltip at `click`. Base UI closes it at `pointerdown`.

8. **Menu, Context Menu, Menubar.**
   - The menu opens at `click` (`phem_menu_controller.js:164`). Base UI opens at `mousedown`, and a press, a move to an item and a release after 200 ms activates the item.
   - A press outside closes the menu at `click` (`phem_dismiss.js:29`), and the focus goes to `<body>`. Base UI closes at `mousedown` (also the right button) and gives the focus to the trigger.
   - Menubar: ArrowRight and ArrowLeft from an open menu highlight the first item of the next menu (`phem_menubar_controller.js:159-164`). Base UI highlights no item. ArrowUp on a closed trigger opens the menu (`phem_menu_controller.js:259`). Base UI does not open it.
   - Context Menu: the collision is the opposite on the two axes. Base UI shifts on the side axis and flips the align (`MenuPositioner.tsx:122-128`). Tab closes the menu (Base UI: Tab does nothing). A release of the right button on an item does nothing (Base UI: it activates the item).
   - Submenu: it closes when the pointer goes to empty space, and its trigger keeps the highlight. Base UI keeps it open, with no highlight. The close after a move to a sibling item takes about 19 ms (Base UI: about 60 ms).
   - Shift+Tab in an open menu moves the focus to the element before the trigger. Base UI: the trigger gets the focus (a menubar menu stays open).
   - Typeahead (`phem_roving.js:63-75`): a key with no match does not clear the text.
   - `--transform-origin` of a popup with no arrow uses the center of the anchor. Base UI uses `0%` for the align `start` and `100%` for `end`.
   - Attributes: no `data-instant="click"` on a context menu popup, no `data-nested` on a submenu popup, `tabindex="-1"` on the highlighted item, and an empty `aria-controls=""` on a closed trigger.

9. **Select.**
    - The highlighted item goes under a scroll arrow: `scrollIntoList()` (`phem_select_controller.js:846-853`) does not use the `scroll-padding` of the list.
    - A selection with the mouse leaves a focus ring on the trigger. The controller opens the popup and moves the focus in `pointerdown`. Base UI opens at `mousedown`.
    - With the popup over the trigger, the content is 1 px too low: `#placeAligned` (`:548`) does not subtract the bottom border of the popup (`SelectPopup.tsx:321`).
    - With little space, the popup changes to the side `bottom`. Base UI keeps it over the trigger with a smaller height, and changes only under three conditions (`SelectPopup.tsx:290-374`).
    - A mouse open with no value highlights no item (Base UI: the first item). A pointer that goes out of the popup keeps the highlight (Base UI removes it).
    - Typeahead: the reset time is 500 ms (Base UI: 750 ms), and new text starts after the highlighted item (Base UI: after the selected item).
    - A hover on a scroll arrow scrolls 4 px for each frame. Base UI scrolls one item each 40 ms.
    - A click outside does not give the focus to the trigger. `data-popup-side` on the trigger is `none` (Base UI: `bottom`). The highlighted option has `tabindex="-1"`.

10. **Combobox and Autocomplete.**
    - A clear on a closed popup opens the popup (`#clear()` dispatches `input`, and `onInput` sets `wanted`).
    - Enter with an open popup and no highlighted item does nothing. Base UI closes the popup (and the Combobox goes back to the selected label).
    - The highlight stays when the pointer goes out of the list.
    - Autocomplete: ArrowDown and ArrowUp on a closed popup highlight an item. Base UI only opens the popup.
    - Attributes: no `aria-haspopup` on the input, no `aria-expanded`, `aria-haspopup`, `aria-controls` and `data-popup-open` on the trigger, no `role="group"` and `data-popup-open` on the input group, and no `data-list-empty` on the input.

11. **Navigation Menu.**
    - The first move between two triggers after an open has no slide: `data-instant` stays on the positioner (`phem_navigation_menu_controller.js:376-387`).
    - The arrow slides in from the left at each open: its first `left` comes before the size of the popup.
    - A click on a trigger that a hover opened always keeps the menu open. Base UI closes it when the click is 500 ms or more after the open.
    - Escape with the focus on a different trigger moves the focus to the open trigger.
    - Attributes: no `data-open` on the nav, the list and the arrow, no `data-side`, `data-align` and `tabindex="-1"` on the popup, and `--popup-width` and `--popup-height` stay in px after the transition.

12. **Tabs, Toolbar, Slider, Scroll Area.**
    - Tabs: `tabindex="0"` stays on the selected tab (`phem_tabs_controller.js:196`). Base UI moves it to the tab with the focus. Shift + an arrow key moves the focus (`:116`). The size of the indicator is a full number of px (`:244`).
    - Toolbar: Home and End move the focus (`phem_toolbar_controller.js:114`). Base UI has these keys only in a toggle group.
    - Slider: a mouse press shows the focus ring on the thumb (`phem_slider_controller.js:155`). Base UI uses `focusVisible: false` for a pointer. The `z-index` of the thumb stays after the release.
    - Scroll Area: `data-scrolling` stays about 500 ms after the release of the thumb or the track. The thumb size and offset are full numbers of px.

13. **Number field and OTP field: more input cases** (in addition to items 1 and 2).
    - Number field: PageUp and PageDown change the value (`phem_number_field_controller.js:177`). Enter formats the text (`:170-173`). The input accepts `%`, a space, a second sign and a second decimal point, and refuses the digits of other scripts (`:159-164`, `:468-475`). `inputmode` is `decimal` (Base UI: `numeric`, but not on iOS).
    - OTP field: no `role="group"` and `aria-labelledby` on the root, and no `enterkeyhint`, `autocorrect`, `maxlength` and `type="text"` on the slots.

14. **Field and Form.**
    - A submit with the button: phem sets `disabled` on the button (`phem_controller.js:668-675`), so the focus goes to `<body>`. Base UI uses `aria-disabled`, and focuses the invalid control after the error.
    - The label and the description use element references, so `aria-labelledby=""` and `aria-describedby=""` are empty attributes. Base UI uses ids and `for`.

15. **Other.**
    - A link with `href="#"` starts a visit of the same page, and the focus goes to `<body>` (the link of the Toolbar demo).
    - `phem:poll` starts the next interval at the end of the response, so a 1000 ms poll takes about 1.2 s (the Progress demo).

## Design decisions of phem

These results are different from Base UI, and phem keeps them.

- **Toast: a new toast for the same message.** Base UI shows a new toast for each `add`, unless the `id` is the same. `phem-toast` does not show a toast with the same type, title and message as a toast on the screen: it starts the time of that toast again.
- **Menu: `data-instant="trigger-change"`.** Base UI sets it when a menu with more than one trigger changes its trigger. A phem menu has one trigger, so the value does not apply.

## Not gaps of phem

These differences need a change in the bundle only. They are in `TODO.md` under "Open differences in the bundle": the viewport and the bleed of the drawer, the props that the bundle does not have (`Slider`, `SwitchRoot`, `Drawer`, `Progress`, `Meter`, `CollapsiblePanel`, `AvatarFallback`), the `align` defaults, the elements of some parts, the disabled look of the Submit button in the form demo, and the font of base-ui.com.
