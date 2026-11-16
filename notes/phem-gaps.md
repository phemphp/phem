# Phem gaps that are still open

Each item is a behavior of Base UI v1.9.0 (the release that base-ui.com shows) that needs a change in a `phem/phem` controller. The bundle does not work around them.

Status (2026-11-16): phem v0.4.4 is the installed release. It fixes the 24 gaps that this file had for phem v0.4.3: 14 confirmed items, and 10 items that a check of the Base UI source (`packages/react/src`) confirmed. The items below are the parts that phem v0.4.4 does not have.

## Open

1. **Number field: the scrub area.** phem v0.4.4 has the scrub math of Base UI, the center of the virtual cursor, the `teleportDistance` value, and the stop of the button repeat at the limit. It does not have:
   - the pointer lock on the `<body>`, and no pointer lock on WebKit;
   - the click after a press with no movement;
   - the 50 ms touch check of the buttons;
   - a step from an empty value.

2. **OTP field: the input.** phem v0.4.4 has the keys of Base UI (Backspace, Ctrl+Backspace, the arrow keys, the press of the mouse). It does not have:
   - the buffer for the composition of an IME;
   - the complete event for a paste of an equal value;
   - the input of two characters in one slot.

## Design decisions of phem

These results are different from Base UI, and phem keeps them.

- **Toast: a new toast for the same message.** Base UI shows a new toast for each `add`, unless the `id` is the same. `phem-toast` does not show a toast with the same type, title and message as a toast on the screen: it starts the time of that toast again.
- **Menu: `data-instant="trigger-change"`.** Base UI sets it when a menu with more than one trigger changes its trigger. A phem menu has one trigger, so the value does not apply.

## Not gaps of phem

These differences need a change in the bundle only. They are in `TODO.md` under "Open differences in the bundle": the viewport and the bleed of the drawer, the props that the bundle does not have (`Slider`, `SwitchRoot`, `Drawer`, `Progress`, `Meter`, `CollapsiblePanel`, `AvatarFallback`), the `align` defaults, the elements of some parts, the disabled look of the Submit button in the form demo, and the font of base-ui.com.
