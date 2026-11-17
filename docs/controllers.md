# Build UI with the built-in controllers

This guide shows you how to use the Stimulus controllers that Phem ships for common UI patterns: a section that opens, an accordion, a section that shows from the value of a field, a popover, a tooltip, a menu, a menu bar, a navigation menu, a context menu, a combobox, a select, a sortable list, a modal dialog, a drawer, tabs, a toggle button, a switch or a checkbox, a toggle group, a toolbar, a radio group, a button that is not a `<button>`, an avatar, a field with validation, a number field, a one-time code field, a slider, a scroll area, a table of contents that marks the section in view, a theme switch, toasts, a copy button, a count of characters, an image input with a preview, a password that shows, a "select all" box, a local time, the focus after an event and a confirmation. Their state belongs to the client, so it stays after the morph of an event.

The controllers follow the design of [Base UI](https://base-ui.com): they ship no CSS, and your markup gives all the elements. A UI library can put its own styled markup on them.

## Enable the controllers

The controllers are off by default. Enable each one that you use in `assets/controllers.json`:

```json
"@phem/phem": {
    "optimistic": { "enabled": true, "fetch": "lazy" },
    "collapsible": { "enabled": true, "fetch": "lazy" },
    "accordion": { "enabled": true, "fetch": "lazy" },
    "depends": { "enabled": true, "fetch": "lazy" },
    "dialog": { "enabled": true, "fetch": "lazy" },
    "popover": { "enabled": true, "fetch": "lazy" },
    "tooltip": { "enabled": true, "fetch": "lazy" },
    "menu": { "enabled": true, "fetch": "lazy" },
    "context-menu": { "enabled": true, "fetch": "lazy" },
    "combobox": { "enabled": true, "fetch": "lazy" },
    "tabs": { "enabled": true, "fetch": "lazy" },
    "toast": { "enabled": true, "fetch": "lazy" },
    "clipboard": { "enabled": true, "fetch": "lazy" },
    "autofocus": { "enabled": true, "fetch": "lazy" },
    "confirm": { "enabled": true, "fetch": "lazy" },
    "sortable": { "enabled": true, "fetch": "lazy" },
    "count": { "enabled": true, "fetch": "lazy" },
    "reveal": { "enabled": true, "fetch": "lazy" },
    "checkbox-group": { "enabled": true, "fetch": "lazy" },
    "time": { "enabled": true, "fetch": "lazy" },
    "scrollspy": { "enabled": true, "fetch": "lazy" },
    "image-input": { "enabled": true, "fetch": "lazy" },
    "toggle": { "enabled": true, "fetch": "lazy" },
    "toggle-group": { "enabled": true, "fetch": "lazy" },
    "toolbar": { "enabled": true, "fetch": "lazy" },
    "radio-group": { "enabled": true, "fetch": "lazy" },
    "avatar": { "enabled": true, "fetch": "lazy" },
    "button": { "enabled": true, "fetch": "lazy" },
    "field": { "enabled": true, "fetch": "lazy" },
    "form": { "enabled": true, "fetch": "lazy" },
    "number-field": { "enabled": true, "fetch": "lazy" },
    "otp-field": { "enabled": true, "fetch": "lazy" },
    "slider": { "enabled": true, "fetch": "lazy" },
    "scroll-area": { "enabled": true, "fetch": "lazy" },
    "select": { "enabled": true, "fetch": "lazy" },
    "menubar": { "enabled": true, "fetch": "lazy" },
    "navigation-menu": { "enabled": true, "fetch": "lazy" }
}
```

For `phem-optimistic`, see [Update the page before the server answers](client-side.md#update-the-page-before-the-server-answers).

## Learn the conventions

All the controllers use the same rules. When you know them, you can style each controller.

### Parts

Each element of a pattern is a part: a Stimulus target with a name, such as `trigger`, `popup`, `arrow`, `close`, `item` or `panel`. Mark each part with `data-<controller>-target`:

```html
<div data-controller="phem-popover">
    <button data-phem-popover-target="trigger">Share</button>
    <div data-phem-popover-target="popup" hidden>...</div>
</div>
```

The controller adds the roles and the ARIA attributes of the pattern, such as `role="dialog"`, `aria-expanded` and `aria-controls`. An attribute that your markup gives stays. A controller gives no `id` to a part. The morph of an event matches the elements by their id, and removes an id that the server render does not have. When an ARIA attribute must point to a part with no `id`, the controller uses an element reference of ARIA, such as `ariaControlsElements`. The browser then shows the attribute with an empty value, for example `aria-controls=""`. When the part has an `id`, the attribute gets the id.

### State in data attributes

The controller shows its state in data attributes. Use them in your CSS, or as Tailwind variants (`data-open:opacity-100`):

| Attribute | Part | When |
|---|---|---|
| `data-open`, `data-closed` | The root, the popup, the panel | The part is open, or closed. The `nav`, the lists and the arrow of a navigation menu get `data-open` while an item is open |
| `data-popup-open` | A trigger | Its popup is open |
| `data-panel-open` | A trigger of `phem-collapsible` | Its panel is open |
| `data-side`, `data-align` | A positioned popup, its arrow, the popup of a navigation menu | The side and the alignment after a flip: `top`, `bottom`, `left`, `right`, `inline-start`, `inline-end`, and `start`, `center`, `end` |
| `data-highlighted` | An item of a menu or of a combobox | The item has the focus, or the highlight |
| `data-selected` | An item of a combobox with `multiple` | The server chose the item (`aria-selected="true"`) |
| `data-expanded` | The `phem-toast` element, a toast | The pointer or the focus is on the stack |
| `data-swiping`, `data-swipe-direction` | A toast, a drawer | The user swipes the part, and the direction of the swipe (a toast gets the direction at the first move of the swipe) |
| `data-pressed` | A toggle button, an item of a toggle group, the trigger of a menu, a context menu, a popover or a select | The button is pressed, or its popup is open |
| `data-checked`, `data-unchecked`, `data-indeterminate` | A switch, a checkbox, a radio, their indicator | The control is checked, not checked, or mixed |
| `data-valid`, `data-invalid`, `data-dirty`, `data-touched`, `data-filled`, `data-focused` | The parts of a field | The state of the validation and of the input of the user |
| `data-dragging`, `data-scrolling` | A slider, a scroll area | The user drags a thumb, or scrolls |
| `data-disabled` | An item, a tab | The element is disabled |
| `data-readonly`, `data-required` | A form control | The control is read-only, or required |
| `data-orientation` | The parts of `phem-tabs` | `horizontal` or `vertical` |
| `data-active` | A tab, a link of `phem-scrollspy` | The tab is selected, or the section of the link is in view |
| `data-starting-style`, `data-ending-style` | A part that opens or closes | The first frame of the open, and the time of the close |
| `data-instant` | A popup, the positioner of a navigation menu | The cause of a change that Base UI makes with no animation (`click`, `dismiss`, `focus`, `delay`, `group`), or the first open of a navigation menu. The CSS stops the animation with `[data-instant] { transition: none }` |

### Animations

A part that opens gets `data-starting-style` for its first frame. A part that closes gets `data-ending-style`, and gets `hidden` only when its animations end. A popup in the top layer (a `popover` popup, a `<dialog>`) gets `data-starting-style` before it shows, so its open animation and the open animation of its `::backdrop` run. Use a CSS transition between the two states:

```css
.popup {
    transition: opacity 150ms, transform 150ms;
    transform-origin: var(--transform-origin);
}
.popup[data-starting-style],
.popup[data-ending-style] {
    opacity: 0;
    transform: scale(0.95);
}
.popup[data-instant] {
    transition: none;
}
```

A popup gets `data-instant` with the cause of a change that Base UI makes with no animation: `click` (Enter or Space), `dismiss` (Escape), `focus` (the keyboard focus), `delay` (a tooltip of a group) and `group` (a move from one menu of a menubar to another). The attribute does not stop the animation. Add `[data-instant] { transition: none }` (Tailwind `data-instant:transition-none`) to make this change at once, as in Base UI. With no such rule, the change animates. A change with the pointer removes the attribute.

A transition can go back in the middle: a popup that opens again before the end of its close animation does not jump. A keyframe animation on `[data-closed]` also works. A part with no animation closes at once.

### Press outside

A popup, a dialog that is not a modal and a navigation menu close at a press outside them. A press that starts in the popup and ends outside it (for example a text selection with the mouse) does not close it, as in Base UI. A press in a popup closes only the popups that opened after it. A menu and a select close at the press of the mouse (the `pointerdown`). For a touch, they close at the end of the touch (in 1 s), or when the touch moves more than 10 px (a scroll), as in Base UI. A long touch does not close them.

### Position

`phem-popover`, `phem-tooltip`, `phem-menu`, `phem-context-menu`, `phem-combobox`, `phem-select` and `phem-navigation-menu` place their popup next to their anchor (the trigger, or the input). The popup gets `position: fixed` and its `top` and `left`, so a `transform` of the popup stays free for an animation. These values set the position:

| Value | Default | Effect |
|---|---|---|
| `side` | `bottom` (`top` for a tooltip) | The side of the anchor: `top`, `bottom`, `left`, `right`, `inline-start` or `inline-end` |
| `align` | `center` (`start` for a menu and a combobox) | The alignment on the other axis: `start`, `center` or `end` |
| `side-offset` | `0` | The distance (px) from the anchor |
| `align-offset` | `0` | The shift (px) on the other axis |
| `collision-padding` | `5` | The space (px) to keep from the edges of the viewport |
| `collision-padding-inline` | the same as `collision-padding` | The space (px) to keep from the left and the right edges only |
| `flip` | `true` | `false` keeps the popup on its side when it does not fit |
| `arrow-padding` | `5` | The space (px) between the arrow and the corners of the popup |

When the popup does not fit on its side, it goes to the other side (a flip). On the other axis, it moves to stay in the viewport (a shift). It follows its anchor on a scroll and on a resize. The popup gets these CSS variables:

- `--anchor-width` and `--anchor-height`: the size of the anchor, for example for a list as wide as its input
- `--available-width` and `--available-height`: the space from the anchor to the edge of the viewport, for a `max-height`
- `--transform-origin`: the point of the anchor, for a scale animation. A popup with `align` `start` or `end` and no arrow grows from its aligned edge (`0%` or `100%` on the other axis, as in Base UI). On a top or a bottom side in a right-to-left page, `start` is the right edge (`100%`). When the popup shifts more than 1 px to stay in the viewport, the origin goes back to the point of the anchor

The `arrow` part gets its `left` (on a top or bottom side) or its `top` (on a left or right side), so it points at the center of the anchor. The value starts at the padding box of the popup, so give the arrow `position: absolute`. Put it on the edge of the popup with `[data-side]`:

```css
.arrow[data-side="bottom"] { top: -8px; }
.arrow[data-side="top"] { bottom: -8px; rotate: 180deg; }
```

An element around the popup with `overflow: hidden`, a `transform` or a `filter` can cut or move a `position: fixed` popup. Add the `popover="manual"` attribute to the popup: the controller shows it in the top layer of the page, above all the other elements.

### The morph

The state of a controller belongs to the client. The morph after an event does not change the state attributes and the position. The `open` value (or the `active` value of `phem-tabs`) is the state: the server render gives its first value, and a server render with a new value sets the state. For example, a handler opens a dialog with `data-phem-dialog-open-value={{ editing }}`. A state that the user changed after the request of the event left keeps the value of the user, as a bound field does: the server render did not know it. For example, the user presses an arrow key of a radio group with `phem:live` two times, and the answer of the first key does not check the first radio again.

## Show and hide a section

`phem-collapsible` shows and hides its panels in place, for a "more" text, a section of a form or an item of an accordion:

```html
<div data-controller="phem-collapsible">
    <button data-phem-collapsible-target="trigger">Details</button>
    <div data-phem-collapsible-target="panel" hidden>
        <button onClick={{ star }}>Star</button>
    </div>
</div>
```

A click on a trigger opens and closes the panel. The `toggle`, `open` and `close` actions do the same from another element. After a click, at the end of the open animation, the browser shows:

```html
<div data-controller="phem-collapsible" data-phem-collapsible-open-value="true" data-open>
    <button data-phem-collapsible-target="trigger" aria-controls="" aria-expanded="true" data-panel-open>Details</button>
    <div data-phem-collapsible-target="panel" data-open
         style="--collapsible-panel-height: auto; --collapsible-panel-width: auto;">...</div>
</div>
```

The panel has no `id`, so the trigger points to it with an element reference (`ariaControlsElements`). When each panel has an `id`, the trigger gets the ids in `aria-controls`. An `aria-controls` that your markup gives stays.

The controller element and the panel get `data-open` or `data-closed`. During an open and a close, they also get `data-starting-style` and `data-ending-style` (see [Animations](#animations)), as the root of a Base UI Collapsible. So a style of the element can change with the panel, for example the icon of the trigger: `.section[data-ending-style] .icon { rotate: 0deg; }`. The `data-ending-style` of the element stays until the end of the animations of the panel.

The panel gets the size of its content in `--collapsible-panel-height` and `--collapsible-panel-width`, for a transition of its height. During the open, the size is in px, so the transition goes from 0 to the height of the content. At the end of the open animations, the two variables become `auto`, so content that grows after the open (an image that loads, a morph that adds a line) is not cut. A panel that is open on load gets `auto` at once. After the morph, an open panel also gets `auto`, unless its open animation still runs: then it keeps the size in px until the end of the animation. A close measures the panel again in px, so the close starts from its real height. The controller measures the content with no alignment (`justify-content`, `align-items`, `align-content` and `justify-items`), so a panel that puts its content at its end (`justify-content: flex-end`) gets the correct size:

```css
.panel { height: var(--collapsible-panel-height); overflow: hidden; transition: height 200ms; }
.panel[data-starting-style], .panel[data-ending-style] { height: 0; }
```

An open panel has `auto` in the variables, and `auto` is not a length. So do not use the variables in `calc()`, in `max-height`, or in a keyframe of an open panel. Use them only in `height` (or `width`), and put the closed size on `[data-starting-style]` and `[data-ending-style]`, as in the example. For example, change `max-height: calc(var(--collapsible-panel-height) + 1rem)` to `height: var(--collapsible-panel-height)` with a `padding-block` of `0.5rem` on the panel.

Set `data-phem-collapsible-open-value="true"` to open it on load. With `data-phem-collapsible-hidden-until-found-value="true"`, a closed panel has `hidden="until-found"`: the search of the browser (Ctrl+F) finds its text and opens it. A panel with `data-phem-collapsible-hidden-until-found` does the same. A collapsible does not close on Escape or on a click outside. For a dropdown, use `phem-popover` or `phem-menu`.

### Remember a dismissed banner

Set `data-phem-collapsible-persist-value` to a key, and the state goes in `localStorage`. A banner that the user closed stays closed after a reload:

```html
<div data-controller="phem-collapsible"
     data-phem-collapsible-open-value="true"
     data-phem-collapsible-persist-value="promo-2026">
    <div data-phem-collapsible-target="panel">
        New plans for 2026.
        <button data-phem-collapsible-target="trigger" aria-label="Dismiss">×</button>
    </div>
</div>
```

After a click on the trigger, `localStorage` has `phem-collapsible:promo-2026` with the value `false`. On the next load, the stored state wins over the `open` value of the server render, and the panel closes when the controller connects. Use a new key for a new banner. When the key changes, the controller takes the stored state of the new key, or the `open` value of the server render.

Only a click on a trigger and the `toggle`, `open` and `close` actions store the state. An open by the search of the browser (`hiddenUntilFound`) or by a new `open` value of the server render does not. The other collapsibles with the same key get the stored state at once, also in the other tabs. When the browser blocks `localStorage` (for example in some private windows), the state lives until the reload.

The server renders the banner open, so a dismissed banner shows until the controller connects, and then closes.

## Open one item of an accordion at a time

`phem-accordion` shows a list of items that open and close. When one item opens, the other items close:

```html
<div data-controller="phem-accordion">
    <div data-value="shipping" data-phem-accordion-target="item">
        <h3 data-phem-accordion-target="header"><button id="shipping-trigger" data-phem-accordion-target="trigger">Shipping</button></h3>
        <div id="shipping-panel" data-phem-accordion-target="panel" hidden>Two days.</div>
    </div>
    <div data-value="returns" data-phem-accordion-target="item">
        <h3 data-phem-accordion-target="header"><button id="returns-trigger" data-phem-accordion-target="trigger">Returns</button></h3>
        <div id="returns-panel" data-phem-accordion-target="panel" hidden>Thirty days.</div>
    </div>
</div>
```

Each `item` has one `trigger` and one `panel`. A click on a trigger opens and closes its item. Tab moves the focus between the triggers, as the accordion pattern of the ARIA Authoring Practices says now. With `data-phem-accordion-roving-focus-value="true"`, ArrowDown, ArrowUp, Home and End also move the focus between the triggers. Then, with `data-phem-accordion-orientation-value="horizontal"`, ArrowRight and ArrowLeft move it, and from the last trigger ArrowDown goes to the first one, unless `data-phem-accordion-loop-focus-value="false"`. A `header` part (the heading around a trigger) gets `data-open` or `data-closed`, and each part of an item gets `data-index` (the position of the item, from 0). A disabled trigger (`disabled` or `aria-disabled="true"`) does not open, and does not get the focus: its item, its header, the trigger and its panel get `data-disabled`, also when the morph of an event changes the trigger. The controller element and each panel get `data-orientation` (`vertical`, or the `orientation` value). After a click on **Returns**, at the end of the open animation, the browser shows:

```html
<div data-value="returns" data-phem-accordion-target="item" data-open data-index="1">
    <h3 data-phem-accordion-target="header" data-open data-index="1">
        <button id="returns-trigger" data-phem-accordion-target="trigger" aria-expanded="true"
                aria-controls="returns-panel" data-panel-open data-index="1">Returns</button>
    </h3>
    <div id="returns-panel" data-phem-accordion-target="panel" role="region"
         aria-labelledby="returns-trigger" data-open data-index="1" data-orientation="vertical"
         style="--accordion-panel-height: auto; --accordion-panel-width: auto;">Thirty days.</div>
</div>
```

Give each trigger and each panel an `id`, for example `id={{ 'faq-' ~ key ~ '-panel' }}` in a loop. The controller links a trigger and its panel with `aria-controls` and `aria-labelledby` only when both have an id. It gives them no id itself: the morph of an event does not match an element whose id the server render does not have, and replaces it, with the state of its content.

Set `data-phem-accordion-multiple-value="true"` to let more than one item open. The `open` value is the list of the open items. An item is its `data-value` attribute, its `key`, or its index. The server opens an item with `data-phem-accordion-open-value={{ ['returns'] }}`. A panel gets `--accordion-panel-height` and `--accordion-panel-width`, for a transition of its height, with the same px and `auto` values as a panel of `phem-collapsible`. With `data-phem-accordion-hidden-until-found-value="true"`, the search of the browser (Ctrl+F) opens the item that has the text. To do this for one panel only, add `data-phem-accordion-hidden-until-found` to the panel.

## Show a section from the value of a field

`phem-depends` shows a panel when a field has a value, with no round trip. Use it for an "Other" option that asks for a text, or for a checkbox that shows more fields:

```html
<div data-controller="phem-depends">
    <select phem:bind={{ reason }} data-phem-depends-target="field">
        <option value="price">Price</option>
        <option value="other">Other</option>
    </select>
    <div data-phem-depends-target="panel" data-phem-depends-match="other" hidden>
        <input phem:bind={{ reasonOther }} aria-label="Other reason" />
    </div>
</div>
```

A panel shows when the value of the field is one of the words of its `data-phem-depends-match` attribute, for example `data-phem-depends-match="price quality"`. With `data-phem-depends-match` and no value, a panel shows when the field has a value. After the user selects **Other**, the browser shows:

```html
<div data-controller="phem-depends" data-field-value="other">
    ...
    <div data-phem-depends-target="panel" data-phem-depends-match="other" data-open>
        <input aria-label="Other reason" ... />
    </div>
</div>
```

The `field` targets are one field:

| Field | Its value |
|---|---|
| A `<select>`, a text field | The value. An empty text has no value |
| A checkbox | `checked` or `unchecked` |
| A group of checkboxes: more than one checkbox, a `phem:bind` list, or a `name` that ends with `[]` | The checked values: a panel shows when one of its words is checked |
| A group of radio buttons | The value of the checked button |

A hidden panel disables its form controls, so a form submit does not send them. A control that the controller disables gets `data-phem-depends-disabled`, and a control that the server render disables stays disabled. The controller element gets the words of the field in `data-field-value`. A `phem:bind` property keeps its value when its panel hides, so the handler must not use the value of a hidden field. The server must also validate the fields: the controller only changes what the user sees. The state comes from the field, so it stays right after the morph of an event.

## Show a popover

`phem-popover` shows a popup next to its trigger, for a share box, a filter form or a date picker:

```html
<div data-controller="phem-popover" data-phem-popover-align-value="end">
    <button data-phem-popover-target="trigger">Share</button>
    <div data-phem-popover-target="popup" popover="manual" hidden>
        <div data-phem-popover-target="arrow"></div>
        <h2 data-phem-popover-target="title" id="share-title">Share</h2>
        <p data-phem-popover-target="description">Send the link to a friend.</p>
        <button onClick={{ copyLink }}>Copy the link</button>
        <button data-phem-popover-target="close">Close</button>
    </div>
</div>
```

A click on the trigger opens and closes the popup, and a click on a `close` part closes it. The popup is open after the morph of the **Copy the link** event, at its place. After a click, the browser shows:

```html
<button data-phem-popover-target="trigger" aria-haspopup="dialog" aria-expanded="true" data-popup-open data-pressed>Share</button>
<div data-phem-popover-target="popup" popover="manual" role="dialog" aria-labelledby="share-title" aria-describedby="" tabindex="-1"
     data-open data-side="bottom" data-align="end"
     style="position: fixed; top: 61px; left: 240px; --anchor-width: 52px; --available-height: 654px; ...">
```

The popup gets `role="dialog"`, `aria-labelledby` (the `title` part) and `aria-describedby` (the `description` part), so a screen reader names the popup. A popup with an `aria-label` keeps it, and gets no `aria-labelledby`. A part with an `id` gets a link with its id, as the title above. A part with no `id`, as the description above, gets a link with the element references of ARIA (`ariaLabelledByElements`, `ariaDescribedByElements`), and the browser shows `aria-describedby=""`. The trigger points to a popup with no `id` with `ariaControlsElements`. The controller gives the parts no `id`, because the morph must match each element to the server render.

Open moves the focus into the popup: to its `[autofocus]` element, or else to the first element that Tab goes to (a field, a button, a link), or else to the popup itself. After an open with a touch, the focus goes to the popup, so the keyboard of the device does not show. Escape closes the popup. When the focus is in the popup, it goes back to the trigger: Escape on a popup that the pointer opened keeps the focus where it is. A click outside and the focus out of the popover close it too. After a click outside on an element that takes no focus (a text, an image), the focus goes back to the trigger, as in Base UI. After a click outside on an element that takes the focus (a field), the focus stays there. Safari does not give the focus to a button or a link at a click, so there a click on a button outside also gives the focus back to the trigger, as in Base UI. A popup that the hover opened keeps the focus where it is. Each time the focus goes back to the trigger (Escape, a `close` part, a click outside), it uses `preventScroll`: the page does not scroll to the trigger. Set `data-phem-popover-dismiss-value="false"` to keep it open on Escape, on a click outside and on the focus out. Then Escape goes to the popup or the dialog around the popover, which closes.

With `data-phem-popover-open-on-hover-value="true"`, the pointer on the trigger opens the popup after the `delay` value (300 ms), and the pointer out of the trigger and of the popup closes it after the `close-delay` value (0 ms: at once). The pointer can go from the trigger to the popup, also on a diagonal path: while it is in the space between them, the popup stays open. For example, with `data-phem-popover-side-offset-value="8"`, the pointer crosses the 8 px below the trigger, and the popup closes only when the pointer goes out of that space. A popover with `open-on-hover` is good for a preview card, because a touch screen and the keyboard can still open it.

### Open one popover from several triggers

One popover can have several triggers, as the tooltips of [Open one tooltip from several triggers](#open-one-tooltip-from-several-triggers). Each `trigger` part is a trigger, and an element anywhere in the page with `data-phem-popover-for` and the `id` of the controller element is a detached trigger. A click on a trigger opens the popover next to it. While the popover is open, a click on another trigger moves it there, and a click on the same trigger closes it. With `open-on-hover`, the pointer on another trigger moves it at once. A click on a detached trigger is not a click outside.

The `viewport` part shows the content of the trigger: give the trigger a `data-phem-popover-payload`, and put a `<template data-payload>` with the same value in the viewport. The viewport shows a copy of the content of the template. A payload with no template shows as text.

```html
<button data-phem-popover-for="panels" data-phem-popover-payload="notifications">Notifications</button>
<button data-phem-popover-for="panels" data-phem-popover-payload="profile">Profile</button>

<div id="panels" data-controller="phem-popover">
    <div data-phem-popover-target="popup" popover="manual" hidden>
        <div data-phem-popover-target="viewport">
            <template data-payload="notifications">
                <h2 data-phem-popover-target="title">Notifications</h2>
                <p data-phem-popover-target="description">You are all caught up.</p>
            </template>
            <template data-payload="profile">
                <h2 data-phem-popover-target="title">Jason Eventon</h2>
                <a href="/settings">Profile settings</a>
            </template>
        </div>
    </div>
</div>
```

After a click on **Profile**, the browser shows:

```html
<button data-phem-popover-for="panels" data-phem-popover-payload="profile" aria-haspopup="dialog" aria-expanded="true" data-popup-open data-pressed>Profile</button>
<div id="panels" data-controller="phem-popover" data-phem-popover-open-value="true" data-phem-popover-trigger-id-value="">
    <div data-phem-popover-target="popup" role="dialog" aria-labelledby="" data-open ...>
        <div data-phem-popover-target="viewport">
            <template data-payload="notifications">...</template>
            <template data-payload="profile">...</template>
            <div data-current data-open>
                <h2 data-phem-popover-target="title">Jason Eventon</h2>
                <a href="/settings">Profile settings</a>
            </div>
        </div>
    </div>
</div>
```

The popup gets the name of the `title` part of the content. The other triggers get `aria-expanded="false"`. The animations of the move, of the size and of the content, `data-activation-direction`, and the `trigger-id` value are the same as for a tooltip. The `open` action takes a `trigger-id` param: `<button data-action="phem-popover#open" data-phem-popover-trigger-id-param="profile">`.

### Show a preview card of a link

A popover with `open-on-hover` and a link trigger (`<a href>`) is a preview card, as the PreviewCard of Base UI. Base UI waits 600 ms to open it and 300 ms to close it:

```html
<div data-controller="phem-popover" data-phem-popover-open-on-hover-value="true"
     data-phem-popover-delay-value="600" data-phem-popover-close-delay-value="300">
    <a href="/users/ada" data-phem-popover-target="trigger">Ada</a>
    <div data-phem-popover-target="popup" hidden>
        <img src="/ada.png" alt="" /> <a href="/users/ada/posts">Posts</a>
    </div>
</div>
```

A click on the link opens the link, not the popup. The keyboard focus on the link opens the popup after the delay, and the focus out closes it at once: the close delay is for the pointer. A popup that the focus opened gets `data-instant="focus"`, also when the focus out closes it, so a `[data-instant]` rule can stop its animation, as in Base UI. Escape closes the card with `data-instant="dismiss"`, and the pointer removes the attribute. The focus stays on the link. A click outside closes the card, and the focus does not move. The card only adds to the link, for a mouse and for the eyes: the link stays the only part that Tab goes to. So the link gets no `aria-haspopup`, `aria-expanded` or `aria-controls`, and the popup gets no role. The popup stays in the accessibility tree, as in Base UI: a screen reader can read it in the reading order of the page. Its links and fields get `tabindex="-1"`: Tab goes from the link to the next element of the page. Put the information of the card on the page of the link too. When the pointer is on **Ada**, the browser shows:

```html
<a href="/users/ada" data-phem-popover-target="trigger" data-popup-open>Ada</a>
<div data-phem-popover-target="popup" data-open data-side="bottom" data-align="center" style="...">
    <img src="/ada.png" alt=""> <a href="/users/ada/posts" tabindex="-1">Posts</a>
</div>
```

## Show a tooltip

`phem-tooltip` shows a short text next to an element, on hover and on the focus of the keyboard:

```html
<button onClick={{ archive }} data-controller="phem-tooltip" aria-label="Archive">
    <svg aria-hidden="true">...</svg>
    <span data-phem-tooltip-target="popup" hidden>Archive</span>
</button>
```

The trigger is the controller element, or a `trigger` part in it. The pointer on the trigger opens the tooltip after the `delay` value (600 ms), and the pointer out closes it after the `close-delay` value (0 ms). The pointer can go on the tooltip, unless `data-phem-tooltip-hoverable-value="false"`. The focus of the keyboard (`:focus-visible`) opens it at once. Escape, the blur and a press on the trigger close it: the tooltip closes at the press of the pointer (`pointerdown`), before the click, and at the click of Enter or Space, as in Base UI. Set `data-phem-tooltip-close-on-click-value="false"` to keep it open on a press.

A tooltip that opens less than 400 ms after another tooltip closed opens with no delay, and gets `data-instant="delay"`, so a `[data-instant]` rule can stop its animation, as in a toolbar. The tooltip that closed also gets `data-instant="delay"` while its close animation runs, so with the `[data-instant]` rule the page shows one tooltip at a time. A focus that moves from one trigger to the next with Tab also ends the close of the other tooltip at once:

```css
[data-phem-tooltip-target="popup"][data-instant] { transition: none }
```

While the tooltip is open, the browser shows:

```html
<button data-controller="phem-tooltip" aria-label="Archive" data-popup-open>
    <span data-phem-tooltip-target="popup" data-open data-side="top" data-align="center" style="...">Archive</span>
</button>
```

A tooltip is only visual, as the Tooltip of Base UI: the popup gets no `role="tooltip"`, and the trigger gets no `aria-describedby`. The label of the trigger is its name for a screen reader. For a text that a screen reader must also read as a description, write the link in your markup. The controller keeps the attributes of the server render:

```html
<button data-controller="phem-tooltip" aria-label="Archive" aria-describedby="archive-tip">
    <span id="archive-tip" role="tooltip" data-phem-tooltip-target="popup" hidden>Moves the message out of the inbox</span>
</button>
```

A tooltip helps only the users of a pointer and of a keyboard. A touch shows no tooltip. Give the trigger its own label (here `aria-label`) for a touch screen and for a screen reader. Do not put important information only in a tooltip: use a popover with `open-on-hover`.

### Open one tooltip from several triggers

One tooltip can have several triggers. Each `trigger` part in the controller element is a trigger. A trigger can also be anywhere in the page: give it `data-phem-tooltip-for` with the `id` of the controller element, as the detached triggers of Base UI. The tooltip goes next to the trigger that opened it, and shows the `data-phem-tooltip-payload` text of this trigger in its `viewport` part:

```html
<div class="toolbar">
    <button data-phem-tooltip-for="tools" data-phem-tooltip-payload="Listen to audio preview" aria-label="Listen to audio preview">…</button>
    <button data-phem-tooltip-for="tools" data-phem-tooltip-payload="Set a timer" aria-label="Set a timer">…</button>
</div>

<span id="tools" data-controller="phem-tooltip">
    <span data-phem-tooltip-target="popup" popover="manual" hidden>
        <span data-phem-tooltip-target="viewport"></span>
    </span>
</span>
```

While the tooltip is open, the pointer or the keyboard focus on another trigger moves it at once, with no delay. The trigger of the tooltip gets `data-popup-open`. A trigger with no payload shows the content of the server render of the viewport. A payload can also name a `<template data-payload>` in the viewport, for a content with markup (see [Open one popover from several triggers](#open-one-popover-from-several-triggers)). When the pointer goes from **Listen to audio preview** to **Set a timer**, the browser shows:

```html
<span data-phem-tooltip-target="viewport" data-activation-direction="right">
    <div data-previous data-closed data-ending-style style="position: absolute; top: 4px; left: 8px; width: 149px;">Listen to audio preview</div>
    <div data-current data-open data-starting-style>Set a timer</div>
</span>
```

The new content gets `data-starting-style` for its first frame. The old content keeps its place with `position: absolute`, gets `data-ending-style`, and goes when its animations end. `data-activation-direction` is the direction of the new trigger from the old one: up to two words, `left` or `right`, and `up` or `down`. Match one word with `~=`, as `[data-activation-direction~="right"]`. The popup gets the size of its content in `--popup-width` and `--popup-height`: they are `auto` at rest, and px values during a change (see [Build a navigation menu](#build-a-navigation-menu)). Animate the move, the size and the content with CSS:

```css
[data-phem-tooltip-target="popup"] {
    width: var(--popup-width, auto);
    height: var(--popup-height, auto);
    transition: top .35s, left .35s, width .35s, height .35s;
}
[data-current], [data-previous] { transition: translate .35s, opacity .175s }
[data-activation-direction~="right"] [data-current][data-starting-style] { translate: 50%; opacity: 0 }
[data-activation-direction~="right"] [data-previous][data-ending-style] { translate: -50%; opacity: 0 }
```

The `open` value is the state, and the `trigger-id` value is the `id` of the trigger of the open tooltip. The server opens the tooltip next to a trigger with `data-phem-tooltip-open-value="true"` and `data-phem-tooltip-trigger-id-value="timer"`, and a new render moves it or closes it. An element in the controller element opens the tooltip with the `show` action, and a `trigger-id` param for another trigger than the first one:

```html
<button data-action="phem-tooltip#show" data-phem-tooltip-trigger-id-param="timer">Open</button>
```

## Build a menu of actions

`phem-menu` shows a list of actions in a popup, with the keyboard of a menu:

```html
<div data-controller="phem-menu">
    <button data-phem-menu-target="trigger">Actions</button>
    <div data-phem-menu-target="popup" hidden>
        <button data-phem-menu-target="item" onClick={{ rename }}>Rename</button>
        <button data-phem-menu-target="item" onClick={{ duplicate }} disabled={{ locked }}>Duplicate</button>
        <div data-phem-menu-target="separator"></div>
        <div data-phem-menu-target="group">
            <span data-phem-menu-target="groupLabel">View</span>
            <button data-phem-menu-target="item" role="menuitemcheckbox" aria-checked={{ pinned ? 'true' : 'false' }}
                    data-phem-menu-close-on-click="false" onClick={{ togglePin }}>Pin</button>
        </div>
        <button data-phem-menu-target="item" onClick={{ delete }}>Delete</button>
    </div>
</div>
```

The parts get these roles: the popup `menu`, an item `menuitem`, a group `group` (with `aria-labelledby` to its `groupLabel`), and a separator `separator`. An item can have the role `menuitemcheckbox` or `menuitemradio`: see [Add checkbox items and radio items](#add-checkbox-items-and-radio-items).

| Key | On | Action |
|---|---|---|
| Click, Enter, Space | The trigger | A press of the mouse opens or closes the menu. A touch, Enter and Space use the click. The keyboard puts the focus on the first item |
| ArrowDown | The trigger | Opens the menu on the first item |
| ArrowDown, ArrowUp | An item | Moves the focus (ArrowRight and ArrowLeft with `data-phem-menu-orientation-value="horizontal"`) |
| Home, End | An item | Moves the focus to the first item, or to the last item |
| A letter | An item | Moves the focus to the next item that starts with the letter. A letter that matches no item starts a new text |
| Enter, Space | An item | Clicks the item |
| Escape | The menu | Closes the menu, and gives the focus back to the trigger |
| Tab | The menu | Closes the menu |
| Shift+Tab | The menu | Closes the menu, and gives the focus to the trigger |

The menu opens at the press of the mouse on the trigger, as in Base UI, and not at the click. So a press on the trigger, a move to an item and a release 200 ms or more after the press click the item: its handler runs, and the menu closes. A release before 200 ms does nothing, so a quick click on the trigger does not run the item that opens under the pointer. A release outside the menu keeps it open. To stop the open, cancel the `pointerdown` of the trigger: a `click` listener comes after the open. ArrowUp on a closed trigger does nothing.

The keys and the pointer skip an item with the `disabled` attribute, which cannot get the focus. An item with `aria-disabled="true"` gets the focus and `data-highlighted`, as a disabled item of a Base UI menu, so a keyboard user can find it. A click or Enter on it does nothing: its handler does not run, and the menu stays open. The first focus of a menu that opens skips the two kinds. For example, `<div data-phem-menu-target="item" aria-disabled="true" onClick={{ archive }}>Archive</div>` gets `data-disabled`, and ArrowDown stops on it. From the last item, ArrowDown goes to the first item, unless `data-phem-menu-loop-focus-value="false"`. A move of the pointer on an item gives it the focus, unless `data-phem-menu-highlight-item-on-hover-value="false"`. An item that comes under a pointer that does not move, for example when the menu opens under it, does not get the focus: the focus of the keyboard stays. The item with the focus gets `data-highlighted`. When the pointer goes out of the item, and not to another item (to a separator, a label or out of the popup), the item loses the highlight: the focus goes to the popup. The highlight of the keyboard stays when the pointer goes out.

After a change with the keyboard, the popup gets `data-instant` with the cause. Add a `[data-instant] { transition: none }` rule to make the change with no animation, as in Base UI. The value is `click` after Enter or Space on the trigger or on an item, and `dismiss` after Escape. With no such rule, a keyboard change and an Escape close animate. A change with the pointer removes the attribute:

```html
<div data-phem-menu-target="popup" role="menu" data-closed data-instant="dismiss" hidden>...</div>
```

A click on an item runs its own handler, and closes the menu. The item with `data-phem-menu-close-on-click="false"` keeps the menu open, as **Pin** above: after its event, the morph shows the new `aria-checked` in the open menu. A press outside (each button, the right button too) and the focus out of the menu close it. After a press outside, the focus goes to the trigger, unless the press gives the focus to another element, for example a text field. With `data-phem-menu-open-on-hover-value="true"`, the pointer on the trigger opens the menu after the `delay` value (100 ms).

The menu sends `phem-menu:open` when it opens and `phem-menu:close` when it closes. A script can open the menu with the focus on an item: `menu.open("first")` or `menu.open("last")`. The `open` action (`data-action="phem-menu#open"`) puts the focus on the popup.

The trigger gets `aria-haspopup="menu"` and `aria-expanded`, and `aria-controls`, `data-popup-open` and `data-pressed` while the menu is open, as in Base UI. An `aria-controls` of the server render stays, also on a closed menu. The trigger of a submenu does not get `data-pressed`. The popup and a `groupLabel` need no `id`: with no id, the trigger and the group point to them with element references (`ariaControlsElements` and `ariaLabelledByElements`). With an id, they get `aria-controls` and `aria-labelledby`. The item with the highlight gets `tabindex="0"`, and the other items get `tabindex="-1"`.

To put menus in a bar, as the menu bar of an application, see [Build a menu bar](#build-a-menu-bar). In a vertical menubar, ArrowRight (ArrowLeft in a right-to-left page) opens the menu in place of ArrowDown and ArrowUp, and its popup goes on the `inline-end` side of the trigger.

### Add checkbox items and radio items

An item with `role="menuitemcheckbox"` or `role="menuitemradio"` is an item that the user checks. With no handler, the browser keeps its state:

```phem
<div data-controller="phem-menu">
    <button data-phem-menu-target="trigger">View</button>
    <div data-phem-menu-target="popup" hidden>
        <button data-phem-menu-target="item" role="menuitemcheckbox" aria-checked="true" data-phem-menu-close-on-click="false">Word wrap</button>
        <div data-phem-menu-target="separator"></div>
        <div data-phem-menu-target="group">
            <button data-phem-menu-target="item" role="menuitemradio" data-value="list" aria-checked="true" data-phem-menu-close-on-click="false">List</button>
            <button data-phem-menu-target="item" role="menuitemradio" data-value="grid" aria-checked="false" data-phem-menu-close-on-click="false">Grid</button>
        </div>
    </div>
</div>
```

A click on a checkbox item checks or unchecks it. A click on a radio item checks it, and unchecks the other radio items of its group: the radio items in the same `group` part, or the radio items in no group. A disabled item does not change. The item gets `data-checked` or `data-unchecked` with its `aria-checked`. With `data-phem-menu-close-on-click="false"`, the menu stays open, as in a menu of Base UI. After a click on **Grid**, the browser shows:

```html
<button data-phem-menu-target="item" role="menuitemradio" data-value="list" aria-checked="false" tabindex="-1" data-unchecked>List</button>
<button data-phem-menu-target="item" role="menuitemradio" data-value="grid" aria-checked="true" tabindex="-1" data-checked data-highlighted>Grid</button>
```

The `aria-checked` of the server render is the first state. The state of the browser stays after the morph of an event. When the server render gives a new `aria-checked`, the new value sets the state: a radio item that the server checks unchecks the other radio items of its group. A page in the Turbo cache has the state of the server render.

The item sends `phem-menu:change` (`phem-context-menu:change` in a context menu) after a change. The detail has `checked`, and `value` (the `data-value` of the item):

```phem
<div data-controller="phem-menu" data-action="phem-menu:change->layout#set">
```

An item with a handler (`onClick`) takes its state from the server, as **Pin** in the first menu. A click does not change the item in the browser and sends no `phem-menu:change`: the handler changes the state, and the morph shows the new `aria-checked`. So a handler that fails or refuses the change leaves the item as it was.

### Add a submenu

Put a `phem-menu` in the popup of a menu. Its trigger is an item of the menu around it:

```html
<div data-controller="phem-menu">
    <button data-phem-menu-target="trigger">Actions</button>
    <div data-phem-menu-target="popup" hidden>
        <button data-phem-menu-target="item" onClick={{ rename }}>Rename</button>
        <div data-controller="phem-menu">
            <button data-phem-menu-target="trigger">Move to</button>
            <div data-phem-menu-target="popup" hidden>
                {% for folder in folders %}
                    <button key={{ folder.id }} data-phem-menu-target="item" onClick={{ move(folder.id) }}>{{ folder.name }}</button>
                {% endfor %}
            </div>
        </div>
        <button data-phem-menu-target="item" onClick={{ delete }}>Delete</button>
    </div>
</div>
```

The arrow keys of the menu go to **Move to** as to the other items. The pointer on it opens the submenu after the `delay` value (100 ms), and the focus stays on it. While the submenu is open, the pointer on another item of the menu gives it the focus after 150 ms, so the pointer can cross an item on its way to the submenu. When the submenu closes before the end of the 150 ms, the item gets the focus at once. The 150 ms apply only before the pointer enters the submenu. In the submenu, the pointer removes the highlight from **Move to** (the focus stays on it). When the pointer goes from the submenu to empty space, the submenu stays open, as in Base UI. The pointer on another item of the menu closes it at once. A trigger with `aria-disabled="true"` does not open the submenu. Set `data-phem-menu-open-on-hover-value="false"` on the submenu to open it only with a click or a key. After ArrowRight on **Move to**, the browser shows:

```html
<button data-phem-menu-target="trigger" role="menuitem" tabindex="-1" aria-haspopup="menu"
        aria-controls="" aria-expanded="true" data-popup-open>Move to</button>
<div data-phem-menu-target="popup" role="menu" tabindex="-1" data-nested
     data-open data-side="inline-end" data-align="start" style="...">
    <button data-phem-menu-target="item" role="menuitem" tabindex="0" data-highlighted>Inbox</button>
```

The popup of a submenu gets `data-nested`. `aria-controls=""` is the attribute of an element reference: the popup has no id. The trigger has no `aria-controls` while the submenu is closed.

| Key | On | Action |
|---|---|---|
| Click, Enter, Space, ArrowRight | The trigger of the submenu | Opens the submenu. The keyboard puts the focus on its first item |
| ArrowLeft, Escape | The submenu | Closes the submenu, and gives the focus back to its trigger |
| Shift+Tab | The submenu | Closes the submenu, and gives the focus back to its trigger |

In a right-to-left page, ArrowLeft opens the submenu and ArrowRight closes it. The popup of a submenu goes on the `inline-end` side of its trigger. A click on an item of a submenu runs its handler and closes all the menus. When the menu closes, its submenus close too.

### Lock the page while the menu is open

Set `data-phem-menu-modal-value="true"` for a modal menu. While it is open, the page does not scroll, and the elements out of the menu get no hover and no click: the body gets `pointer-events: none`, and the popup `pointer-events: auto`. A press outside closes the menu, and the element under the pointer does not get the click. The width of the scroll bar stays, so the page does not move. A Turbo visit gives the page its styles back before Turbo keeps the page in its cache.

A menu that the pointer opened (`data-phem-menu-open-on-hover-value="true"`) does not lock the page, as in Base UI: the lock would take the pointer from the trigger, and the menu would close. The same menu locks the page when a click or a key opens it.

## Build a menu bar

`phem-menubar` is a bar of menus, as the menu bar of an application. Each menu of the bar is a `phem-menu` in the menubar element:

```html
<div data-controller="phem-menubar">
    <div data-controller="phem-menu">
        <button data-phem-menu-target="trigger">File</button>
        <div data-phem-menu-target="popup" hidden>
            <button data-phem-menu-target="item" onClick={{ create }}>New</button>
            <button data-phem-menu-target="item" onClick={{ save }}>Save</button>
            <div data-controller="phem-menu">
                <button data-phem-menu-target="trigger">Export</button>
                <div data-phem-menu-target="popup" hidden>
                    <button data-phem-menu-target="item" onClick={{ export('pdf') }}>PDF</button>
                </div>
            </div>
        </div>
    </div>
    <div data-controller="phem-menu">
        <button data-phem-menu-target="trigger">Edit</button>
        <div data-phem-menu-target="popup" hidden>
            <button data-phem-menu-target="item" onClick={{ undo }}>Undo</button>
        </div>
    </div>
</div>
```

Each menu keeps its own open state (its `open` value), also after the morph of an event. Only one menu of the bar is open at a time: when a menu opens, the other menus close. The items, the submenus and the keys in a menu are the ones of [`phem-menu`](#build-a-menu-of-actions).

| Value | Default | Description |
|---|---|---|
| `orientation` | `horizontal` | `horizontal` or `vertical`: the arrow keys between the triggers |
| `loopFocus` | `true` | From the last trigger, the arrow key goes to the first trigger |
| `modal` | `true` | While a menu is open, the page does not scroll, and the elements out of the menubar get no pointer events |
| `disabled` | `false` | No menu of the bar opens |

Set a value with `data-phem-menubar-<name>-value`, for example `data-phem-menubar-orientation-value="vertical"`.

| Key | On | Action |
|---|---|---|
| ArrowRight, ArrowLeft | A trigger | Moves the focus to the next or to the previous trigger (ArrowDown and ArrowUp in a vertical menubar) |
| Home, End | A trigger | Moves the focus to the first or to the last trigger |
| ArrowDown, Enter, Space | A trigger | Opens the menu on its first item (ArrowRight in a vertical menubar) |
| ArrowRight, ArrowLeft | An open menu | Closes the menu, and opens the next or the previous menu. Its popup gets the focus, and no item is highlighted |
| Shift+Tab | An open menu | Gives the focus to its trigger. The menu stays open |
| ArrowRight | The trigger of a submenu | Opens the submenu |
| ArrowLeft | A submenu | Closes the submenu |
| ArrowLeft | An open menu of a vertical menubar | Closes the menu, and gives the focus back to its trigger |
| Escape | An open menu | Closes the menu, and gives the focus back to its trigger |

The triggers have one tab stop: the trigger of the last menu that had the focus. The arrow keys skip a disabled trigger. In a right-to-left page, ArrowLeft and ArrowRight go the other way.

While a menu is open, the pointer on another trigger opens its menu at once, and closes the first menu. The two popups get `data-instant="group"` for this change, so a `[data-instant]` rule can stop their animation. With no such rule, the change animates. A menu of the bar also gets `data-instant="group"` when an arrow key on its trigger opens it, when the focus out of it closes it, and when another menu of the bar opens. As a menu with no menubar, it gets `data-instant="click"` for Enter and Space, and `data-instant="dismiss"` for Escape. The first click on that trigger keeps its menu open. A modal menubar locks the page as a [modal menu](#lock-the-page-while-the-menu-is-open) does, but the menubar and its popups keep the pointer events (`pointer-events: auto` on the menubar), so the pointer can go to the other triggers. A press outside closes the menu. A menu of the bar opens at the click on its trigger, not at the press.

The menubar element gets `role="menubar"`, `aria-orientation`, `data-orientation`, `data-modal` (with the `modal` value), `data-disabled` and `aria-disabled="true"` (with the `disabled` value), and `data-has-submenu-open` while a menu is open. The triggers get `role="menuitem"`, `tabindex` and `data-disabled`. In a vertical menubar, the popup of a menu goes on the `inline-end` side of its trigger. After a click on **Edit**, a click on **Undo** (an item with `data-phem-menu-close-on-click="false"`) and ArrowRight, the browser shows:

```html
<div data-controller="phem-menubar" style="pointer-events: auto;" role="menubar" aria-orientation="horizontal"
     data-orientation="horizontal" data-modal="" data-has-submenu-open="">
    <div data-controller="phem-menu">
        <button data-phem-menu-target="trigger" aria-expanded="false" tabindex="-1" aria-haspopup="menu"
                role="menuitem">File</button>
        <div id="file-popup" data-phem-menu-target="popup" hidden="" data-closed="" role="menu" tabindex="-1">...</div>
    </div>
    <div data-controller="phem-menu" data-phem-menu-open-value="false">
        <button data-phem-menu-target="trigger" aria-expanded="false" tabindex="-1" role="menuitem" ...>Edit</button>
        <div id="edit-popup" data-phem-menu-target="popup" role="menu" tabindex="-1" data-closed="" hidden="">...</div>
    </div>
    <div data-controller="phem-menu" data-phem-menu-open-value="true">
        <button data-phem-menu-target="trigger" aria-expanded="true" tabindex="0" role="menuitem" aria-haspopup="menu"
                aria-controls="help-popup" data-pressed="" data-popup-open="">Help</button>
        <div id="help-popup" data-phem-menu-target="popup" role="menu" tabindex="-1" data-open=""
             data-side="bottom" data-align="start" style="position: fixed; inset: 29px auto auto 92px; ...">
            <button data-phem-menu-target="item" role="menuitem" tabindex="-1">About</button>
        </div>
    </div>
</div>
```

## Build a navigation menu

`phem-navigation-menu` shows the links of a site, with a panel of links (a content) under some items. The content of the open item shows in one shared popup:

```html
<nav data-controller="phem-navigation-menu" data-phem-navigation-menu-side-offset-value="8">
    <ul data-phem-navigation-menu-target="list">
        <li data-phem-navigation-menu-target="item" data-value="overview">
            <button data-phem-navigation-menu-target="trigger">
                Overview <span data-phem-navigation-menu-target="icon">▾</span>
            </button>
            <div data-phem-navigation-menu-target="content" hidden>
                <a data-phem-navigation-menu-target="link" href="/start">Quick start</a>
                <a data-phem-navigation-menu-target="link" href="/releases">Releases</a>
            </div>
        </li>
        <li data-phem-navigation-menu-target="item" data-value="guides">
            <button data-phem-navigation-menu-target="trigger">Guides</button>
            <div data-phem-navigation-menu-target="content" hidden>
                <a data-phem-navigation-menu-target="link" href="/styling">Styling</a>
            </div>
        </li>
        <li data-phem-navigation-menu-target="item">
            <a data-phem-navigation-menu-target="link" href="/blog" aria-current="page">Blog</a>
        </li>
    </ul>
    <div data-phem-navigation-menu-target="backdrop" hidden></div>
    <div data-phem-navigation-menu-target="positioner" hidden>
        <div data-phem-navigation-menu-target="popup">
            <div data-phem-navigation-menu-target="arrow"></div>
            <div data-phem-navigation-menu-target="viewport"></div>
        </div>
    </div>
</nav>
```

Each item has a trigger and a content, or a link. The value of an item is its `data-value` attribute, its key (`key={{ ... }}`), or its position. The `value` value is the state: the value of the open item, or empty when the menu is closed. Set it to open an item on load, and a handler can set it in a new render.

The contents stay in their items in the server render. With a `viewport` part, the content of the open item moves into the viewport while it is open, as in Base UI, and goes back to its item when it closes. Before a morph, the open content also goes back to its item, so the morph sees the markup of the server render, and after the morph it goes into the viewport again. The browser keeps the focus in the content when it can move an element with its state (`moveBefore()`); else the controller gives the focus back. With no viewport, the content shows in its item, and the CSS of the app places it.

| Value | Default | Description |
|---|---|---|
| `value` | empty | The value of the open item |
| `delay` | `50` | The time (ms) of the pointer on a trigger before its content opens |
| `closeDelay` | `50` | The time (ms) of the pointer out of the menu before it closes |
| `orientation` | `horizontal` | `horizontal` or `vertical`: the arrow keys of the list |
| `loopFocus` | `true` | From the last trigger or link, the arrow key goes to the first one |
| `side`, `align`, `sideOffset`, `alignOffset`, `collisionPadding`, `arrowPadding` | `bottom`, `center`, `0`, `0`, `5`, `5` | The position of the popup next to the trigger of the open item, as in [Position](#position) |

The pointer on a trigger opens its content after the `delay` value, also when another item is open. The pointer out of the triggers and of the popup closes the menu after the `closeDelay` value. A click on a trigger opens and closes its content. A click less than 500 ms after the pointer opened the content keeps it open, and a later click closes it, as in Base UI. A menu that a click or a key opened does not close when the pointer goes out. A click outside, Escape and the focus out of the menu close it. A link with `data-phem-navigation-menu-close-on-click="true"` closes the menu. Escape, this link and the `close` action give the focus back to the trigger as Escape does in the table below. The pointer out, a click outside and the focus out do not move the focus.

| Key | On | Action |
|---|---|---|
| ArrowRight, ArrowLeft | A trigger or a link of the list | Moves the focus to the next or to the previous one (ArrowDown and ArrowUp in a vertical menu) |
| ArrowDown, Enter, Space | A trigger | Opens the content. The focus stays on the trigger (ArrowRight in a vertical menu) |
| Tab | The trigger of the open item | Moves the focus into the content in the viewport |
| Shift+Tab | The first element of the content | Moves the focus back to the trigger |
| Tab | The last element of the content | Moves the focus to the next trigger. The menu stays open until the focus goes out of it |
| Tab | The last trigger or link of the list | Moves the focus to the next element of the page, not into the content. The menu closes |
| ArrowDown, ArrowUp, ArrowRight, ArrowLeft | A link of a content | Moves the focus to the next or to the previous link of the content, and from the last one to the first one. A field in the content keeps its arrow keys |
| Escape | The menu | Closes the menu. The focus goes back to the trigger when it is in the content, in the popup, on the trigger or on the page body, as in Base UI. The focus on another trigger stays |

The arrow keys of the list skip a disabled trigger, and do not go to the links in a content. They stop at the first and at the last one, as in Base UI: set `data-phem-navigation-menu-loop-focus-value="true"` to go from the last one to the first one. Home and End do not move the focus. In a right-to-left page, ArrowLeft and ArrowRight go the other way.

The `positioner` part (or the `popup` part, with no positioner) goes next to the trigger of the open item, with the attributes and the variables of [Position](#position). The `popup` part gets the size of the open content in `--popup-width` and `--popup-height`, and the positioner in `--positioner-width` and `--positioner-height`. At rest, they are `auto`: the popup has the size of its content. When the open item changes, the popup goes from the old size in px to the new size in px, so a CSS transition of its `width` and `height` animates the size. The positioner gets the new size at once, so its place does not change during the transition. When the `width` or `height` transition of the popup ends, the variables go back to `auto`: content that changes its size in the open popup changes the size of the popup. Other animations of the popup do not delay this. Do not use the variables as a length in `calc()`. On the first open, the positioner (or the popup, with no positioner) gets `data-instant`: it goes to its place at once, and only the move from one trigger to another animates, also the first move. Turn off the transition of the position with `[data-instant] { transition: none }`, or the Tailwind variant `data-instant:transition-none`:

```css
[data-phem-navigation-menu-target="popup"] {
    width: var(--popup-width);
    height: var(--popup-height);
    transition: width 200ms, height 200ms;
}
[data-phem-navigation-menu-target="viewport"] {
    position: relative;
    overflow: hidden;
}
[data-phem-navigation-menu-target="content"] { transition: opacity 200ms, translate 200ms; }
[data-phem-navigation-menu-target="content"][data-starting-style][data-activation-direction="right"] { translate: 50% 0; opacity: 0; }
[data-phem-navigation-menu-target="content"][data-ending-style][data-activation-direction="right"] { translate: -50% 0; opacity: 0; }
```

When the open item changes, the old and the new contents get `data-activation-direction`: `right` or `left` (`down` or `up` in a vertical menu), the direction of the new item from the old one. A content that leaves the viewport gets `position: absolute` at the start of the viewport until its animation ends, so it takes no space. The contents, the positioner, the popup and the backdrop get `data-open`, `data-closed` and the animations of the [conventions](#animations).

The `nav`, the lists and the arrow get `data-open` while an item is open. The popup in a positioner gets the `data-side` and `data-align` of the positioner, also after a flip. The popup gets `tabindex="-1"`, also with no positioner: a click on its space gives it the focus, and Tab does not stop on it. These attributes stay after the morph of an event.

A trigger gets `aria-expanded`, `aria-controls`, `data-popup-open` and `data-pressed` when its item is open, and `data-disabled`. A content needs no `id`: with no id, the controller links the trigger to it with an element reference (`ariaControlsElements`). An icon gets `data-popup-open`. A link with `aria-current="page"` or `data-active` from the server gets both. The controller sends `phem-navigation-menu:change` with `{ value }` when the user opens, changes or closes an item. After the pointer on **Overview** and then on **Guides** (the contents of this page have an `id`), the browser shows:

```html
<nav data-controller="phem-navigation-menu" data-phem-navigation-menu-side-offset-value="8" data-phem-navigation-menu-value-value="guides" data-open="">
    <ul data-phem-navigation-menu-target="list" data-open="">
        <li data-phem-navigation-menu-target="item" data-value="overview">
            <button data-phem-navigation-menu-target="trigger" aria-expanded="false" aria-controls="overview-content">Overview ...</button>
            <div id="overview-content" data-phem-navigation-menu-target="content" data-activation-direction="right" data-closed="" hidden="">...</div>
        </li>
        <li data-phem-navigation-menu-target="item" data-value="guides">
            <button data-phem-navigation-menu-target="trigger" aria-expanded="true" aria-controls="guides-content"
                    data-popup-open="" data-pressed="">Guides</button>
        </li>
        <li data-phem-navigation-menu-target="item">
            <a data-phem-navigation-menu-target="link" href="/blog" aria-current="page" data-active="">Blog</a>
        </li>
    </ul>
    <div data-phem-navigation-menu-target="positioner" data-open="" data-side="bottom" data-align="center"
         style="--positioner-width: auto; --positioner-height: auto; position: fixed; inset: 37px auto auto 26px; --anchor-width: 58.234375px; ...">
        <div data-phem-navigation-menu-target="popup" tabindex="-1" data-open="" data-side="bottom" data-align="center"
             style="--popup-width: auto; --popup-height: auto;">
            <div data-phem-navigation-menu-target="arrow" data-open="" data-side="bottom" data-align="center" style="left: 96px;"></div>
            <div data-phem-navigation-menu-target="viewport">
                <div id="guides-content" data-phem-navigation-menu-target="content" data-activation-direction="right" data-open="">
                    <a data-phem-navigation-menu-target="link" href="/styling">Styling</a>
                </div>
            </div>
        </div>
    </div>
</nav>
```

During the size transition (the first 200 ms of the move to **Guides**), the variables have px values: the popup goes from `--popup-width: 302px; --popup-height: 122px;` to `202px` and `82px`, and the positioner has `--positioner-width: 202px; --positioner-height: 82px;` at once. When the menu is closed, the `nav`, the list and the arrow have no `data-open`, the popup has no `data-side` and `data-align`, and `tabindex="-1"` stays.

A navigation menu in a content (a nested menu of Base UI) is not supported yet.

## Open a menu on a right click

`phem-context-menu` is a `phem-menu` that opens at the point of the pointer, in place of the menu of the browser. Its trigger is an area of the page:

```html
<div data-controller="phem-context-menu">
    <div data-phem-context-menu-target="trigger" class="file">{{ file.name }}</div>
    <div data-phem-context-menu-target="popup" hidden>
        <button data-phem-context-menu-target="item" onClick={{ open(file.id) }}>Open</button>
        <button data-phem-context-menu-target="item" onClick={{ rename(file.id) }}>Rename</button>
        <div data-phem-context-menu-target="separator"></div>
        <button data-phem-context-menu-target="item" onClick={{ delete(file.id) }}>Delete</button>
    </div>
</div>
```

A right click on the trigger opens the menu with its top left corner at the pointer. The Menu key and Shift+F10 open it below the element with the focus. On a touch screen, a long press of 500 ms opens it. A new right click on the trigger moves the open menu to the new point. Put the popup out of the trigger. After a right click, the browser shows:

```html
<div data-phem-context-menu-target="trigger" class="file" data-popup-open data-pressed>report.pdf</div>
<div data-phem-context-menu-target="popup" role="menu" tabindex="-1" data-instant="click"
     data-open data-side="bottom" data-align="start" style="position: fixed; top: 212px; left: 348px; ...">
```

The popup gets `data-instant="click"` at the open, as in Base UI: a `[data-instant]` rule opens it with no animation. Near an edge of the viewport, the popup keeps its side, moves on the two axes to stay in the viewport, and flips its align. A submenu of the context menu goes next to its trigger, as in `phem-menu`.

The focus goes to the popup, so ArrowDown goes to the first item. When the popup opens under the pointer, no item gets the focus until the pointer moves. The parts, the keys, the submenus and the values are the ones of `phem-menu`, with `data-phem-context-menu-` in their names. A click on the trigger does not open the menu, and the trigger gets no ARIA attributes, because it is not a button. Escape gives the focus back to the element that had it before the menu opened. Tab does nothing: the menu stays open. On macOS, a press of the right button, a move to an item and a release of the button click the item, when the release is more than 1 px from the point of the open, as in Base UI. The other systems send `contextmenu` after the release, so the release does not click an item there. A menu that opens with no point, with the `open` action or with `data-phem-context-menu-open-value` from the server, goes below the trigger.

## Suggest values while the user types

`phem-combobox` adds a list of suggestions to a text field. The server gives the suggestions: the input sends its text, and the render of the server gives the items. The controller adds the roles and the keyboard.

```php
#[Component]
final class CityField
{
    private const array CITIES = ['Lyon', 'Paris', 'Parma', 'Porto'];

    #[State] public string $query = '';
    #[State] public string $city = '';

    public function choose(string $city): void
    {
        $this->city = $city;
        $this->query = $city;
    }

    public function render(): Node
    {
        $text = strtolower(trim($this->query));
        $results = '' === $text || $text === strtolower($this->city) ? [] : array_values(array_filter(
            self::CITIES,
            static fn (string $name): bool => str_starts_with(strtolower($name), $text),
        ));

        return (
            <div data-controller="phem-combobox">
                <input data-phem-combobox-target="input" phem:bind={{ query }} phem:live aria-label="City" />
                <div data-phem-combobox-target="popup" hidden>
                    <ul data-phem-combobox-target="list">
                        {% for name in results %}
                            <li key={{ name }} id={{ 'city-' ~ name }} data-phem-combobox-target="item" onClick={{ choose(name) }}>{{ name }}</li>
                        {% endfor %}
                    </ul>
                    <p data-phem-combobox-target="empty">No city.</p>
                </div>
            </div>
        );
    }
}
```

The popup opens when the user types, and on ArrowDown or ArrowUp. It shows when it has an item, an `empty` part or a `status` part. The `empty` part is a live region that stays in the page, as the Empty part of Base UI: it gets `role="status"`, `aria-live="polite"` and `aria-atomic="true"`, and it has its content only when the list has no item. So a screen reader reads "No city." when the list becomes empty, and `:empty` matches the part when the list has items (`.empty:empty { padding: 0; }`). Do not give it `hidden`. The focus stays in the input. When the user types "pa" and presses ArrowDown two times, the browser shows:

```html
<input data-phem-combobox-target="input" role="combobox" aria-autocomplete="list" autocomplete="off"
       aria-haspopup="listbox" aria-controls="" aria-expanded="true" aria-activedescendant="city-Parma" data-popup-open value="pa">
<div data-phem-combobox-target="popup" data-open data-side="bottom" data-align="start" style="...">
    <ul data-phem-combobox-target="list" role="listbox">
        <li id="city-Paris" role="option" aria-selected="false">Paris</li>
        <li id="city-Parma" role="option" aria-selected="true" data-highlighted>Parma</li>
    </ul>
    <p data-phem-combobox-target="empty" hidden>No city.</p>
</div>
```

| Key | Action |
|---|---|
| ArrowDown, ArrowUp | Opens the popup. With no selection (an Autocomplete) and no `auto-highlight`, the key that opens the popup gives no highlight, and the next key goes to the first item (ArrowDown) or the last item (ArrowUp). Then it moves the highlight. After the last item, the highlight goes back to the input, and the next key goes to the first item (with `auto-highlight`, the key goes to the first item at once). With `data-phem-combobox-loop-focus-value="false"`, the highlight stays on the last item |
| Enter | Chooses the highlighted item. With an open popup and no highlight, Enter closes the popup and keeps its default action (the submit of the form). With a closed popup, Enter has its default action |
| Escape | Closes the popup. A second Escape clears the input, and the popup stays closed |

To choose an item is a click on it: its handler runs (here `choose`), or its link opens. The handler sets the state, and the morph shows the new text in the input. Then the popup closes, and the controller dispatches `phem-combobox:select` with the `item` in the detail. A click on a `clear` part clears the input and sends its `input` event, as when the user deletes the text. The popup stays closed when it was closed. A click on a `trigger` part opens or closes the popup. A click outside and the focus out of the combobox close the popup. A `trigger` part gets `aria-haspopup="listbox"`, `aria-expanded` and `data-popup-open`, and `aria-controls` only while the popup is open. An `inputGroup` part gets `role="group"` and `data-popup-open`. The input gets `aria-haspopup="listbox"`, and `data-list-empty` when the list has no visible item.

The input can be in the popup, as a search. A `trigger` button opens the popup, and a `value` part in it that is not an input shows the label of the selection, or its `placeholder` (with `data-placeholder`):

```html
<div data-controller="phem-combobox" data-phem-combobox-filter-value="contains" data-phem-combobox-selection-mode-value="single">
    <button type="button" data-phem-combobox-target="trigger"><span data-phem-combobox-target="value" placeholder="Select country"></span></button>
    <div data-phem-combobox-target="popup" hidden>
        <input data-phem-combobox-target="input" aria-label="Country" />
        <ul data-phem-combobox-target="list">...</ul>
    </div>
</div>
```

The popup goes against the trigger, and the input gets the focus at the open. A choice, Escape and a click outside give the focus back to the trigger, and the input has no text at the next open.

The list has no `id`, so the input points to it with an element reference (`ariaControlsElements`). An item with no `id` gets no id: the input points to the highlighted item with `ariaActiveDescendantElement`, and the browser shows `aria-activedescendant=""`. The highlight stays on the same item through the morph of another event. When the morph replaces the item, the highlight stays only on an item with the same `id`, so give each item a stable `id` in a list that changes. A move of the pointer on an item highlights it. An item that comes under a pointer that does not move, for example when the list changes, does not get the highlight. When the pointer goes out of the items, the highlight goes (a highlight of the keyboard stays). With `both` and `inline`, the input gets the text of the user again. When the user types, the highlight goes. With `data-phem-combobox-auto-highlight-value="true"`, the first item gets the highlight. The popup and the list get `data-empty` when there is no visible item, and the `empty` parts show only then. A `status` part gets `role="status"`, so a screen reader reads its text, for example "3 cities". Use `var(--anchor-width)` to give the popup the width of the input.

### Choose more than one value

Set `data-phem-combobox-multiple-value="true"` to choose more than one item, for a field of tags. Show the chosen values as `chip` parts, each with a `chipRemove` part:

```php
#[Component]
final class TagField
{
    private const array TAGS = ['bug', 'docs', 'feature', 'question'];

    #[State] public string $query = '';
    #[State] public array $tags = [];

    public function toggle(string $tag): void
    {
        $this->tags = \in_array($tag, $this->tags, true)
            ? array_values(array_diff($this->tags, [$tag]))
            : [...$this->tags, $tag];
        $this->query = '';
    }

    public function render(): Node
    {
        $text = strtolower(trim($this->query));
        $results = array_values(array_filter(self::TAGS, static fn (string $tag): bool => str_starts_with($tag, $text)));

        return (
            <div data-controller="phem-combobox" data-phem-combobox-multiple-value="true">
                {% for tag in tags %}
                    <span key={{ tag }} data-phem-combobox-target="chip">
                        {{ tag }}
                        <button data-phem-combobox-target="chipRemove" onClick={{ toggle(tag) }} aria-label={{ 'Remove ' ~ tag }}>×</button>
                    </span>
                {% endfor %}
                <input data-phem-combobox-target="input" phem:bind={{ query }} phem:live aria-label="Tags" />
                <div data-phem-combobox-target="popup" hidden>
                    <ul data-phem-combobox-target="list">
                        {% for tag in results %}
                            <li key={{ tag }} id={{ 'tag-' ~ tag }} data-phem-combobox-target="item"
                                aria-selected={{ tag in tags ? 'true' : 'false' }} onClick={{ toggle(tag) }}>{{ tag }}</li>
                        {% endfor %}
                    </ul>
                </div>
            </div>
        );
    }
}
```

The server gives the chosen items `aria-selected="true"`, and the controller does not change it: the highlight is `data-highlighted` and `aria-activedescendant`. A choice keeps the popup open and the highlight, so the user can choose more items. Enter with no highlight closes the popup, and the form submits. When the user chose **bug** and highlights **docs**, the browser shows:

```html
<span data-phem-combobox-target="chip" tabindex="-1">bug <button data-phem-combobox-target="chipRemove" tabindex="-1" aria-label="Remove bug">×</button></span>
<input data-phem-combobox-target="input" role="combobox" aria-activedescendant="tag-docs" aria-expanded="true" ...>
<ul data-phem-combobox-target="list" role="listbox" aria-multiselectable="true">
    <li id="tag-bug" role="option" aria-selected="true" data-selected>bug</li>
    <li id="tag-docs" role="option" aria-selected="false" data-highlighted>docs</li>
</ul>
```

| Key | On | Action |
|---|---|---|
| ArrowLeft | The start of the input | Moves the focus to the last chip |
| Backspace | An empty input | Clicks the `chipRemove` of the last chip |
| ArrowLeft, ArrowRight | A chip | Moves the focus to the chip before or after it, or back to the input |
| Backspace, Delete | A chip | Clicks its `chipRemove`. The focus goes to the chip before it (Backspace) or after it (Delete), or to the input |

The chips and their `chipRemove` parts get `tabindex="-1"`, so Tab goes from the input out of the combobox. A press on a `chipRemove` keeps the focus in the input. A press in the popup also keeps the focus in the input: on an item, on the space around the items, and on a list with `tabindex="-1"`. A field of your application in the popup (an input, a textarea, a select, editable content) gets the focus of its press.

### Select one item of a list of the browser

With a list of the browser (see the next section) and `data-phem-combobox-selection-mode-value="single"`, the combobox has one selected item, as a select with a search:

```phem
<div data-controller="phem-combobox" data-phem-combobox-filter-value="contains"
     data-phem-combobox-selection-mode-value="single" data-phem-combobox-open-on-input-click-value="true">
    <div data-phem-combobox-target="inputGroup">
        <input data-phem-combobox-target="input" aria-label="Fruit" />
        <button type="button" data-phem-combobox-target="clear" aria-label="Clear">×</button>
    </div>
    <input type="hidden" name="fruit" data-phem-combobox-target="value" />
    <div data-phem-combobox-target="popup" hidden>
        <ul data-phem-combobox-target="list">
            {% for fruit in fruits %}
                <li key={{ fruit.id }} data-value={{ fruit.id }} data-phem-combobox-target="item">{{ fruit.name }}</li>
            {% endfor %}
        </ul>
    </div>
</div>
```

A click on an item selects it. The item gets `data-selected` and `aria-selected="true"`, the input gets its label, the `value` part gets its `data-value`, and the combobox sends `phem-combobox:change` with the `item` and the `value` in the detail. After a click on **Cherry**, the browser shows:

```html
<input data-phem-combobox-target="input" role="combobox" aria-expanded="false" value="Cherry" />
<input type="hidden" name="fruit" data-phem-combobox-target="value" value="cherry" />
<li data-value="cherry" data-phem-combobox-target="item" role="option" aria-selected="true" data-selected>Cherry</li>
```

The first selection is the item that the server renders with `data-selected`: an input with no text gets its label, and a `value` part with no value gets its `data-value`, with no event. So `<li data-value="cherry" data-selected data-phem-combobox-target="item">Cherry</li>` gives `<input value="Cherry">` and `<input type="hidden" name="fruit" value="cherry">` in the browser. The selection of the browser stays after the morph of an event. A server render that changes the `data-selected` of an item sets the selection again, with the text of the input and the `value` part: use it to reset a form. The input has no free text: when the popup closes, the input gets the label of the selected item again, or no text. When the popup opens with the label of the selection in the input, the list shows all the items, until the user types, and the selected item has the highlight (`data-highlighted`) and is in view. Enter with no highlight closes the popup, and the input gets the label of the selected item again. A click on the `clear` part removes the text and the selection.

A `clear` part shows only when there is something to clear, and has `hidden` at other times: a selected item in this mode, a chip with `multiple`, and else a text in the input. With `data-phem-combobox-open-on-input-click-value="true"`, a click on the input opens the popup. With an `inputGroup` part (the box around the input and its buttons), the popup is positioned against this box, so `var(--anchor-width)` is its width.

The `trigger` and the `inputGroup` parts show the state of the popup. An Autocomplete (no selection) with a list of the browser, a trigger and an input group:

```phem
<div data-controller="phem-combobox" data-phem-combobox-filter-value="contains">
    <div data-phem-combobox-target="inputGroup">
        <input data-phem-combobox-target="input" aria-label="Color" />
        <button type="button" data-phem-combobox-target="clear" aria-label="Clear">×</button>
        <button type="button" data-phem-combobox-target="trigger" aria-label="Show the colors">▾</button>
    </div>
    <div data-phem-combobox-target="popup" hidden>
        <ul id="colors" data-phem-combobox-target="list">
            {% for name in colors %}
                <li key={{ name }} id={{ 'color-' ~ name|lower }} data-phem-combobox-target="item">{{ name }}</li>
            {% endfor %}
        </ul>
    </div>
</div>
```

After the connect, with a closed popup, the browser shows:

```html
<div data-phem-combobox-target="inputGroup" role="group">
    <input data-phem-combobox-target="input" aria-label="Color" role="combobox" aria-autocomplete="list"
           autocomplete="off" aria-haspopup="listbox" aria-controls="colors" aria-expanded="false">
    <button type="button" data-phem-combobox-target="clear" aria-label="Clear" hidden>×</button>
    <button type="button" data-phem-combobox-target="trigger" aria-label="Show the colors"
            aria-haspopup="listbox" aria-expanded="false">▾</button>
</div>
```

After ArrowDown in the input, the popup opens, and no item has the highlight:

```html
<div data-phem-combobox-target="inputGroup" role="group" data-popup-open>
    <input ... aria-haspopup="listbox" aria-controls="colors" aria-expanded="true" data-popup-open>
    <button type="button" data-phem-combobox-target="trigger" aria-haspopup="listbox"
            aria-expanded="true" aria-controls="colors" data-popup-open>▾</button>
</div>
<div data-phem-combobox-target="popup" data-open data-side="bottom" data-align="start" style="...">
    <ul id="colors" data-phem-combobox-target="list" role="listbox">
        <li id="color-blue" role="option" aria-selected="false">Blue</li>
        <li id="color-green" role="option" aria-selected="false">Green</li>
        <li id="color-red" role="option" aria-selected="false">Red</li>
    </ul>
</div>
```

A second ArrowDown gives the input `aria-activedescendant="color-blue"`, and Blue gets `aria-selected="true"` and `data-highlighted`. Enter then chooses Blue. With no highlight (for example after the user types "re" and moves the pointer out of the items), Enter closes the popup: the input and the trigger get `aria-expanded="false"`, the trigger loses `aria-controls` and `data-popup-open`, and the form submits. With the text "zz", the input gets `data-list-empty`.

### Filter a list in the browser

A short list that does not change, such as the fruits of a form, does not need a round trip for each letter. The server renders all the items one time, and the browser filters them. Set `data-phem-combobox-filter-value` to `contains` or `starts-with`:

```php
#[Component]
final class FruitField
{
    private const array FRUITS = ['Apple', 'Banana', 'Cherry', 'Éclair', 'Lemon'];

    public function render(): Node
    {
        $fruits = self::FRUITS;

        return (
            <div data-controller="phem-combobox" data-phem-combobox-filter-value="contains">
                <input data-phem-combobox-target="input" name="fruit" aria-label="Fruit" />
                <div data-phem-combobox-target="popup" hidden>
                    <ul data-phem-combobox-target="list">
                        {% for name in fruits %}
                            <li key={{ name }} id={{ 'fruit-' ~ loop.index }} data-phem-combobox-target="item">{{ name }}</li>
                        {% endfor %}
                    </ul>
                    <p data-phem-combobox-target="empty">No fruit.</p>
                </div>
            </div>
        );
    }
}
```

An item that does not match the text of the input gets `hidden`. The match ignores the case and the accents: "ecl" finds "Éclair". It uses `Intl.Collator` with the language of the page, or with `data-phem-combobox-locale-value`. The label of an item is its text, or its `data-label` attribute. Put the items in `group` parts, with a heading in a `groupLabel` part: the group gets `role="group"` and the name of its label, and a group with no visible item gets `hidden` too. `data-phem-combobox-limit-value="10"` shows only the first 10 matches. When the user types "ecl", the browser shows:

```html
<input data-phem-combobox-target="input" role="combobox" aria-autocomplete="list" aria-expanded="true" ... value="ecl">
<ul data-phem-combobox-target="list" role="listbox">
    <li id="fruit-1" role="option" aria-selected="false" hidden>Apple</li>
    <li id="fruit-2" role="option" aria-selected="false" hidden>Banana</li>
    <li id="fruit-3" role="option" aria-selected="false" hidden>Cherry</li>
    <li id="fruit-4" role="option" aria-selected="false">Éclair</li>
    <li id="fruit-5" role="option" aria-selected="false" hidden>Lemon</li>
</ul>
```

The list belongs to the client. A choice writes the label of the item in the input, and sends its `input` and `change` events, so a `phem:bind` or an `onChange` handler gets the value. The text of an input with no `phem:bind` stays through the morph of another event. An item can still have an `onClick` handler.

The `mode` value is the `aria-autocomplete` attribute of the input, as in the Autocomplete of Base UI:

| `mode` | The items | The input on ArrowDown and ArrowUp |
|---|---|---|
| `list` (default) | Filtered | Does not change |
| `both` | Filtered | Shows the label of the highlighted item |
| `inline` | Not filtered | Shows the label of the highlighted item |
| `none` | Not filtered | Does not change |

With `both` and `inline`, Escape closes the popup and gives back the text that the user typed. While the input shows the label of the highlighted item, an event sends the text that the user typed for a `phem:bind` input, not the label. With `inline` and `none`, the list is static: the `filter` value has no effect, and a choice still writes the label in the input.

While an event of the input runs (for example the search of the server), the input gets `aria-busy="true"` (see [Show a loading state](events.md#show-a-loading-state)). The popup gets it too, so a screen reader waits for the new results.

### Highlight, inline lists and grids

These values change the highlight, as the props of the Autocomplete of Base UI:

| Value | Effect |
|---|---|
| `data-phem-combobox-auto-highlight-value="true"` | The first item gets the highlight when the user types or opens the popup |
| `data-phem-combobox-auto-highlight-value="always"` | An open list always has a highlight: the first item at first |
| `data-phem-combobox-keep-highlight-value="true"` | The highlight stays when the pointer goes out of the items |
| `data-phem-combobox-highlight-item-on-hover-value="false"` | The pointer does not move the highlight |

With no `popup` part, the list is inline: it is always open, and the input has `aria-expanded="true"`. Use it for a command palette in a dialog. Give an item `data-phem-dialog-target="close"`, and a choice (a click, or Enter) closes the dialog:

```phem
<div data-controller="phem-combobox" data-phem-combobox-filter-value="contains" data-phem-combobox-auto-highlight-value="always" data-phem-combobox-keep-highlight-value="true">
    <input data-phem-combobox-target="input" aria-label="Search commands" />
    <div data-phem-combobox-target="list">
        <div data-phem-combobox-target="item" data-phem-dialog-target="close">New Window</div>
        <div data-phem-combobox-target="item" data-phem-dialog-target="close">New Tab</div>
    </div>
</div>
```

After the connect, the first item has the highlight:

```html
<input data-phem-combobox-target="input" role="combobox" aria-expanded="true" aria-activedescendant="..." ...>
<div data-phem-combobox-target="list" role="listbox">
    <div data-phem-combobox-target="item" role="option" aria-selected="true" data-highlighted>New Window</div>
    <div data-phem-combobox-target="item" role="option" aria-selected="false">New Tab</div>
</div>
```

With `data-phem-combobox-grid-value="true"`, the list is a grid, for example an emoji picker. Put the items in `row` parts. The list gets `role="grid"`, the input and the trigger `aria-haspopup="grid"`, the rows `role="row"` and their items `role="gridcell"`. With a highlight, ArrowLeft and ArrowRight move it to the item before or after it, and ArrowUp and ArrowDown to the item in the same column of the row above or below (the last item of a shorter row). ArrowUp on the first row gives the highlight back to the input. With no highlight, ArrowLeft and ArrowRight move the caret of the input.

## Sort a list with drag and drop

`phem-sortable` moves the items of a list with the pointer (mouse, touch and pen) and with the keyboard. A drop calls the [`onCall` handler](events.md#call-a-handler-from-your-javascript) of the list. Lists with the same `group` value share their items, for a board:

```php
#[Component]
final class Board
{
    #[State] public array $columns = ['todo' => ['a', 'b', 'c'], 'done' => ['d']];

    public function move(Event $e): void
    {
        // The browser makes the detail: check it.
        $card = $e->detail['id'] ?? null;
        $index = $e->detail['index'] ?? null;
        $to = $e->detail['list'] ?? null;
        if (!\is_string($card) || !\is_int($index) || !\is_string($to) || !isset($this->columns[$to])) {
            return;
        }

        foreach ($this->columns as $name => $cards) {
            $this->columns[$name] = array_values(array_diff($cards, [$card]));
        }

        array_splice($this->columns[$to], max(0, min($index, \count($this->columns[$to]))), 0, [$card]);
    }

    public function render(): Node
    {
        return (
            <div class="board">
                {% for name, cards in columns %}
                    <ul key={{ name }} data-controller="phem-sortable" data-phem-sortable-group-value="board"
                        data-phem-sortable-list-value={{ name }} onCall={{ move }}>
                        {% for card in cards %}
                            <li key={{ card }} data-phem-sortable-target="item" data-phem-sortable-id={{ card }}>{{ card }}</li>
                        {% endfor %}
                    </ul>
                {% endfor %}
            </div>
        );
    }
}
```

A press on an item and a move of 5 px start a drag. The item moves in the list while the pointer moves, and into another list of the same group. With `handle` parts in an item, only a press on a handle starts a drag. When the user drops card `b` at the top of the `done` column, the browser dispatches a `phem:call` event on that list, and the handler gets this detail:

```json
{"id": "b", "index": 0, "list": "done"}
```

`id` is the `data-phem-sortable-id` of the item, `index` is its new position in the list (from 0), and `list` is the `list` value of the list. There is no event when the item is at its first place. The click after a drop does not run the handler of the item. Escape cancels the drag, and the item goes back. During a press, the browser starts no native drag of an image or of a text in the item.

With the keyboard, Space or Enter on an item (or on its handle) takes it. The arrow keys of the `orientation` value (`vertical` by default) move it in its list, and the other arrow keys move it to the list before or after in the group. Space or Enter drops it, and Escape or the blur cancels it. The controller gives `tabindex="0"` to an item (or to its handle) that cannot get the focus. A `tabindex` of your markup stays, also after the morph: give `tabindex="-1"` to an item that the keyboard must skip.

The new order shows at once. The morph after the event shows the order of the server render, so an item that the handler did not move goes back. A morph of a list during a drag cancels the drag. During a drag, the item gets `data-dragging`, and the lists of the group get `data-sorting`:

```css
[data-dragging] { opacity: 0.6; }
[data-sorting] { outline: 2px dashed #94a3b8; }
```

`phem.css` stops the text selection during a drag, and sets `touch-action: none` on the handles (or on the items with no handle), so a touch drag does not scroll the page.

During a drag with the keyboard, a screen reader reads each step in the live region of the page. When the user takes card `b`, moves it to the `done` list and drops it, it reads:

```text
Picked up b. Position 2 of 3 in todo.
b: position 1 of 2 in done.
Dropped b at position 1 of 2 in done.
```

The name of the item is its `aria-label`, the `aria-label` of its handle, or its text. The name of the list is its `aria-label`, or its `list` value, so give a list a label that a user knows, such as `aria-label="To do"`. A list with no name has no ` in {list}` part in the texts: "Picked up b. Position 2 of 3." Change the texts with the values of the list where the drag starts: `picked-up-message`, `moved-message`, `dropped-message` and `cancelled-message`. In a text, `{item}`, `{position}`, `{count}` and `{list}` get their values:

```html
<ul data-controller="phem-sortable" aria-label="Tâches"
    data-phem-sortable-picked-up-message-value="{item} pris, position {position} sur {count}.">
```

## Open a modal dialog

`phem-dialog` opens a native `<dialog>` as a modal:

```html
<div data-controller="phem-dialog">
    <button data-phem-dialog-target="trigger">Edit</button>
    <dialog data-phem-dialog-target="popup">
        <h2 data-phem-dialog-target="title" id="edit-title">Edit the title</h2>
        <input phem:bind={{ title }} />
        <button onClick={{ save }} data-phem-dialog-target="close">Save</button>
        <button data-phem-dialog-target="close">Cancel</button>
    </dialog>
</div>
```

A click on a trigger opens the dialog, and a click on a `close` part closes it. The browser gives the focus to the first element of the dialog that can have it. After an open with a touch, the focus goes to the popup, which gets `tabindex="-1"`, so the keyboard of the device does not show for a first field. A drawer always gives the focus to its popup, as in Base UI, so no button of the drawer shows a focus ring. Put `autofocus` on an element of the drawer to give the focus to it. In a modal dialog (`modal` is `true` or `trap-focus`), Tab goes from the last element of the dialog to the first one, and Shift+Tab goes back: the focus does not go out of the dialog to the page or to the browser. This is also true from the checked radio of a last radio group, and from an element with `tabindex="-1"`, such as a menu item. In a dialog with no element for Tab, Tab does nothing. While a modal dialog is open, the page does not scroll. The width of the scroll bar stays, so the page does not move. Add `phem:shortcut="mod+k"` to a trigger to open the dialog with a key (see [Add keyboard shortcuts](events.md#add-keyboard-shortcuts)). The `open` and `close` actions do the same from another element. The dialog gets `aria-labelledby` (the `title` part) and `aria-describedby` (a `description` part), and `data-open` or `data-closed`. A close waits for the animations of `[data-ending-style]`, then calls `close()`. The trigger gets `aria-haspopup="dialog"`, `aria-expanded` and `data-popup-open`, and `aria-controls` while the dialog is open. An `aria-controls` of the server render stays.

Escape and a tap on the backdrop close the dialog. Set `data-phem-dialog-dismiss-value="false"` to keep it open on Escape and on a tap on the backdrop. For an alert dialog, set `data-phem-dialog-disable-pointer-dismissal-value="true"`: only Escape and the `close` parts close it. The server can open it with `data-phem-dialog-open-value={{ editing }}`. A dialog that closes in another way, such as a `<form method="dialog">`, also sets the state.

A tap on the backdrop is a press and a click on the dialog itself, at a point outside the box of the dialog. The press does not move the focus, so the trigger gets the focus back after the close, with its focus ring after an open with the keyboard. A click on the padding of the dialog is inside the box, so the dialog stays open. A text selection that starts in the dialog and ends on the backdrop also keeps the dialog open. Phem does not use `closedby="any"`, because Safari does not support it.

A dialog in an open dialog gets `data-nested`. The dialog around it gets `data-nested-dialog-open` and the `--nested-dialogs` CSS variable (the number of open dialogs in it), for a stack of dialogs:

```css
dialog[data-nested-dialog-open] { scale: calc(1 - 0.05 * var(--nested-dialogs)); }
```

| Value | Default | Effect |
|---|---|---|
| `open` | `false` | The state: the dialog is open |
| `dismiss` | `true` | Escape and a tap on the backdrop close the dialog |
| `disable-pointer-dismissal` | `false` | A tap on the backdrop, or a press outside a dialog that is not a modal, does not close the dialog |
| `modal` | `true` | `true`: `showModal()`, the page is inert. `false`: `show()`, the page stays active, and Escape and a press outside close the dialog. `trap-focus`: as `false`, and Tab stays in the dialog |
| `swipe-direction` | none | The dialog is a drawer: `up`, `down`, `left` or `right` |
| `snap-points` | none | The heights where a drawer stops (see [Stop a drawer at snap points](#stop-a-drawer-at-snap-points)) |
| `snap-point` | the first one | The state: the snap point of the drawer |
| `snap-to-sequential-points` | `false` | A fast swipe does not skip a snap point |

### Add a backdrop and a viewport

The `::backdrop` of a modal `<dialog>` covers the page. For a backdrop with its own animation, add a `backdrop` part: an element outside the dialog. It gets `hidden`, `data-open` or `data-closed`, and the animations of the [conventions](#animations), at the same time as the dialog.

For a popup that a box places on the screen (Base UI `Dialog.Viewport`), make the `<dialog>` the `viewport` part, and put the `popup` part in it. The controller opens the viewport, and the viewport and the popup get the open state. A press on the viewport outside the popup is a tap on the backdrop. The close waits for the animations of the popup:

```html
<div data-controller="phem-dialog">
    <button data-phem-dialog-target="trigger">Edit</button>
    <div class="backdrop" data-phem-dialog-target="backdrop" hidden></div>
    <dialog class="viewport" data-phem-dialog-target="viewport">
        <div class="popup" data-phem-dialog-target="popup">
            <h2 data-phem-dialog-target="title">Edit</h2>
            ...
        </div>
    </dialog>
</div>
```

After a click on **Edit**, the browser shows:

```html
<div class="backdrop" data-phem-dialog-target="backdrop" data-open></div>
<dialog class="viewport" data-phem-dialog-target="viewport" open data-open>
    <div class="popup" data-phem-dialog-target="popup" data-open>...</div>
</dialog>
```

A title or a description with no `id` gets its link with the element references of ARIA (`ariaLabelledByElements` and `ariaDescribedByElements`), so a screen reader names the dialog.

### Close a drawer with a swipe

Set the `swipe-direction` value (`up`, `down`, `left` or `right`) to make the dialog a drawer that closes with a swipe in that direction:

```html
<div data-controller="phem-dialog" data-phem-dialog-swipe-direction-value="down">
    <button data-phem-dialog-target="trigger">Filters</button>
    <dialog data-phem-dialog-target="popup" class="sheet">...</dialog>
</div>
```

A press on the popup and a move of 5 px in the direction start a swipe. The popup gets `data-swipe-direction` (the direction of the value), `data-swiping` during the swipe, the distance in `--drawer-swipe-movement-x` or `--drawer-swipe-movement-y`, and the part of the way to the close in `--drawer-swipe-progress` (from 0 to 1). A move in the other direction has a resistance. A release after half the size of the popup, or after a fast swipe, closes it. A shorter swipe gives the popup its place again. After the release, `--drawer-swipe-strength` (from 0.1 to 1) is smaller for a faster swipe: multiply the duration of the transition with it. Use the variables in your CSS:

```css
.sheet {
    margin: auto 0 0;
    width: 100%;
    transform: translateY(var(--drawer-swipe-movement-y, 0px));
    transition: transform calc(var(--drawer-swipe-strength, 1) * 300ms);
}
.sheet[data-swiping] { transition: none; }
.sheet[data-starting-style], .sheet[data-ending-style] { transform: translateY(100%); }
.sheet::backdrop { opacity: calc(1 - var(--drawer-swipe-progress, 0)); }
```

A mouse press in a `content` part (`data-phem-dialog-target="content"`, around the text of the drawer) selects its text and does not start a swipe. A press on an element with `data-phem-swipe-ignore` never starts a swipe, also in a toast. It also does not start the drag of a `phem-sortable` item.

The popup that a swipe closed gets `data-swipe-dismiss` until the end of its `[data-ending-style]` transition, so the transition goes on from its place. A drawer in an open drawer makes the drawer around it get `data-nested-drawer-open` and `--nested-drawers`, as `data-nested-dialog-open` and `--nested-dialogs`. During the swipe of that drawer, the drawer around it also gets `data-nested-drawer-swiping`, and only the drawer in it follows the pointer. The popup of each drawer gets `--drawer-frontmost-height`: the height of the last open drawer in it, or else its own height. Use it to give the drawer behind the size of the drawer in front, as the nested drawers of Base UI: `.drawer[data-nested-drawer-open] { height: var(--drawer-frontmost-height); }`. A press on a field, a button, a link or a label does not start a swipe. In content that scrolls (`overflow: auto` or `scroll`), the swipe starts only when the content is at its start: the list of a bottom sheet scrolls up first. A touch needs no `touch-action`: a touch that moves in the direction of the swipe swipes the dialog, and a touch that moves the other way scrolls the content of the dialog.

### Stop a drawer at snap points

A bottom sheet can stop at more than one height. Set the `snap-points` value to a list of heights: a number from 0 to 1 is a part of the height of the viewport, a larger number is px, and a string can have `px` or `rem`. The drawer opens at the first snap point:

```php
<div data-controller="phem-dialog" data-phem-dialog-swipe-direction-value="down" data-phem-dialog-snap-points-value={{ ['148px', 1] }}>
    <button data-phem-dialog-target="trigger">Details</button>
    <dialog class="sheet" data-phem-dialog-target="popup">...</dialog>
</div>
```

The popup gets `--drawer-snap-point-offset`: the distance from its full height to the snap point. It gets `--drawer-height` (its height), and `data-expanded` at its full height. Add the offset to the transform:

```css
.sheet { transform: translateY(calc(var(--drawer-snap-point-offset, 0px) + var(--drawer-swipe-movement-y, 0px))); }
```

A swipe moves the drawer up and down from its snap point. The release stops it at the nearest snap point, or closes it when the close is nearer. A fast swipe goes to the next snap point in its direction, unless `data-phem-dialog-snap-to-sequential-points-value="true"`. The `snap-point` value is the state: it keeps the snap point through the morph, and a new snap point dispatches `phem-dialog:snap` with the `snapPoint` in the detail. Snap points apply to a drawer with the `up` or `down` direction. A resize of the viewport (a rotation, the keyboard of a phone) places the snap point again. Below its full height, a swipe in the other direction opens the drawer. At its full height, or on content that can scroll in that direction, a touch scrolls the content. After a swipe up to the full height of a sheet of 400 px, the browser shows:

```html
<div data-controller="phem-dialog" data-phem-dialog-snap-point-value="1" ...>
    <dialog class="sheet" data-phem-dialog-target="popup" open data-open data-swipe-direction="down" data-expanded
            style="--drawer-height: 400px; --drawer-snap-point-offset: 0px;">...</dialog>
</div>
```

## Switch between tabs

`phem-tabs` shows the panel of the selected tab:

```html
<div data-controller="phem-tabs" data-phem-tabs-active-value="profile">
    <div data-phem-tabs-target="list" aria-label="Settings">
        <button data-phem-tabs-target="tab" data-value="profile">Profile</button>
        <button data-phem-tabs-target="tab" data-value="security">Security</button>
        <span data-phem-tabs-target="indicator"></span>
    </div>
    <div data-phem-tabs-target="panel" data-value="profile">...</div>
    <div data-phem-tabs-target="panel" data-value="security" hidden>...</div>
</div>
```

The value of a tab and of a panel is its `data-value` attribute, its key (`key={{ ... }}`), or its position. Each tab shows the panel with the same value. The `active` value is the state: the value of the selected tab. Set it to select a tab on load, and a handler can set it in a new render. With no `active` value, or a value that no tab has, the first tab that is not disabled is selected. A click selects a tab, and the `active` value gets its value. The arrow keys move the focus between the tabs, and Enter or Space selects the tab with the focus, also on a tab that is not a `<button>`. A tab can be a link (`<a href>`): a click, Enter and Space select it and open its link. With `data-phem-tabs-activate-on-focus-value="true"`, the focus selects the tab at once. Home and End go to the first and to the last tab, and the arrow keys skip a disabled tab. From the last tab, the arrow key goes to the first tab, unless `data-phem-tabs-loop-focus-value="false"`. With `data-phem-tabs-orientation-value="vertical"`, the Up and Down keys move between the tabs in place of Left and Right. An arrow key with Shift does not move the focus. One tab is in the Tab order: the tab with the last focus, or the selected tab. It stays after the morph of an event.

The controller sets the ARIA roles and attributes. A tab and its panel need no `id`: the controller links them with the element references of ARIA (`ariaControlsElements` and `ariaLabelledByElements`), so a screen reader names the panel with its tab. It gives them no `id`, because the morph must match each element to the server render. When the tab and the panel have an `id`, they get `aria-controls` and `aria-labelledby`. After a click on **Security**, the browser shows:

```html
<div data-controller="phem-tabs" data-phem-tabs-active-value="security" data-orientation="horizontal" data-activation-direction="right">
    <div data-phem-tabs-target="list" role="tablist" ...>
        <button data-value="profile" role="tab" aria-selected="false" tabindex="-1" ...>Profile</button>
        <button data-value="security" role="tab" aria-selected="true" tabindex="0" data-active ...>Security</button>
        <span data-phem-tabs-target="indicator" style="--active-tab-left: ...; --active-tab-width: ...; ..."></span>
    </div>
    <div data-value="profile" role="tabpanel" data-index="0" data-hidden hidden tabindex="0" ...>...</div>
    <div data-value="security" role="tabpanel" data-index="1" tabindex="0" ...>...</div>
</div>
```

All the parts get `data-orientation` and `data-activation-direction` (the direction of the last change: `left`, `right`, `up`, `down` or `none`). The selected tab gets `data-active`, and a hidden panel gets `data-hidden`. A panel also gets `data-index`, and the animations of the [conventions](#animations).

The `indicator` part gets the box of the selected tab in the list, for a line under the selected tab that moves:

```css
[data-phem-tabs-target="indicator"] {
    position: absolute;
    left: var(--active-tab-left);
    width: var(--active-tab-width);
    bottom: 0;
    height: 2px;
    transition: left 200ms, width 200ms;
}
```

The variables are `--active-tab-left`, `--active-tab-top`, `--active-tab-right`, `--active-tab-bottom`, `--active-tab-width` and `--active-tab-height`. The values keep the fractions of a pixel. They start inside the border of the list, so a border on the list does not move the line. The controller reads the border width from `clientLeft` and `clientTop`, which are whole pixels. In a list with a border of 2px, a selected tab that starts 60.5px from the outer edge of the list and is 80.5px wide gives:

```html
<span data-phem-tabs-target="indicator" style="--active-tab-left: 58.5px; --active-tab-width: 80.5px; ..."></span>
```

After a click on **Profile** and ArrowRight, the focus is on **Security** and **Profile** stays selected. The tab stop is on **Security**, also after the morph of an event:

```html
<div data-phem-tabs-target="list" role="tablist" ...>
    <button data-value="profile" role="tab" aria-selected="true" tabindex="-1" data-active ...>Profile</button>
    <button data-value="security" role="tab" aria-selected="false" tabindex="0" ...>Security</button>
    ...
</div>
```

## Toggle a button, a switch or a checkbox

`phem-toggle` gives a pressed or checked state to a toggle button, a switch or a checkbox:

```html
<button type="button" data-controller="phem-toggle" aria-label="Bold">B</button>

<label>
    <span role="switch" data-controller="phem-toggle">
        <span data-phem-toggle-target="indicator"></span>
        <input type="checkbox" hidden phem:bind={{ alerts }} data-phem-toggle-target="input" />
    </span>
    Alerts
</label>

<label>
    <span role="checkbox" data-controller="phem-toggle" data-phem-toggle-name-value="terms">
        <span data-phem-toggle-target="indicator">✓</span>
        <input type="checkbox" hidden data-phem-toggle-target="input" />
    </span>
    I accept the terms
</label>
```

The role of the element gives its kind. An element with `role="switch"` or `role="checkbox"` is a switch or a checkbox: it gets `aria-checked`, and `data-checked` or `data-unchecked`. Another element is a toggle button: it gets `aria-pressed`, and `data-pressed` when it is pressed. An element that is not a `<button>` gets `role="button"` when it has no role, and `tabindex="0"` when it has no `tabindex`. Space and Enter toggle a toggle button and a switch, as a button. Only Space toggles a checkbox.

The `checked` value is the state (a pressed toggle button is checked). The server render gives the first value, and a server render with a new value sets it. With no `checked` value, the first state comes from the `checked` attribute of the input, or from `aria-checked` or `aria-pressed`. The state belongs to the client, so it stays after the morph of an event.

| Value | Default | Effect |
|---|---|---|
| `checked` | `false` | The state. Base UI `checked`/`defaultChecked`, `pressed`/`defaultPressed` |
| `indeterminate` | `false` | A checkbox in a mixed state: `aria-checked="mixed"`, `data-indeterminate`. A click checks it |
| `disabled` | `false` | Stops the changes by the user. Gives `data-disabled` and `aria-disabled="true"`. The `disabled` attribute of a `<button>` also gives `data-disabled` |
| `read-only` | `false` | Stops the changes by the user. Gives `data-readonly` and `aria-readonly="true"` |
| `required` | `false` | Gives `data-required`, `aria-required="true"`, and `required` on the input |
| `name`, `form` | | The `name` and the `form` of the input |
| `value` | `on` | The value that the input sends when it is checked |
| `unchecked-value` | | The value that an `<input type="hidden">` sends when it is not checked |
| `keep-mounted` | `false` | The indicator of a checkbox does not get `hidden` when the checkbox is not checked |

| Key | Effect |
|---|---|
| Space | Toggles the state |
| Enter | Toggles a toggle button (a `<button>` clicks on Enter). It does not toggle a switch or a checkbox |

A click toggles the state. A click on a `<label>` around the element, or on a `<label for>` the input, also toggles it, one time. The element gets the focus.

A `<label>` names only a native control, so a `<span role="checkbox">` in a label has no name of its own. The controller links the element to the `<label>` around it with `aria-labelledby`: with the id of the label, or with an element reference (`ariaLabelledByElements`) when the label has no id. Then a screen reader reads "I accept the terms, checkbox". An `aria-label` or an `aria-labelledby` of the markup stays. A `<button>` gets no link: a label names a button. The `indicator` parts of a switch and of a checkbox get `aria-hidden="true"` (when they have no `aria-hidden`), so the check mark is not in the name: `aria-checked` gives the state.

The `input` target (or the first checkbox in the element) is the field of a form:

- A checkbox input gets the state in `checked`. Hide it with `hidden` or a visually hidden class.
- An `<input type="hidden">` gets the `value` value when it is checked, and the `unchecked-value` value when it is not checked. With no `unchecked-value`, it is disabled when it is not checked, so a form does not send it, as a native checkbox.
- The input gets `tabindex="-1"` and `aria-hidden="true"` when it has none.
- When the user changes the state, the input dispatches `input` and `change`, so `phem:bind`, `phem:live` and the other controllers see the change.
- An input with `phem:bind` belongs to phem: after the morph of an event, its value (the `checked` of a checkbox, the `value` of a hidden input) gives the state.

The `indicator` targets (the check mark of a checkbox, the thumb of a switch) get the same data attributes as the element. The indicator of a checkbox shows only when the checkbox is checked or indeterminate, with the animations of the [conventions](#animations) (`data-open`, `data-closed`, `data-starting-style`, `data-ending-style`). The indicator of a switch and of a toggle button always shows.

Each change by the user dispatches `phem-toggle:change` with `{ checked, indeterminate, value }`. After a click on the checkbox and on the toggle button, the browser shows:

```html
<label>
    <span role="checkbox" data-controller="phem-toggle" data-phem-toggle-name-value="terms" data-phem-toggle-checked-value="true" tabindex="0" aria-labelledby="" aria-checked="true" data-phem-toggle-indeterminate-value="false" data-checked="">
        <span data-phem-toggle-target="indicator" data-checked="" aria-hidden="true" data-open="" data-starting-style="">✓</span>
        <input type="checkbox" hidden="" data-phem-toggle-target="input" name="terms" tabindex="-1" aria-hidden="true">
    </span>
    I accept the terms
</label>
<button type="button" data-controller="phem-toggle" aria-label="Bold" data-phem-toggle-checked-value="true" aria-pressed="true" data-phem-toggle-indeterminate-value="false" data-pressed="">B</button>
```

A `phem-checkbox-group` and a `phem-toggle-group` set the state of their `phem-toggle` items. See [Select all the rows](#select-all-the-rows) and [Press one button of a group](#press-one-button-of-a-group).

## Press one button of a group

`phem-toggle-group` gives one state to a group of toggle buttons, for example the alignment buttons of a text editor:

```html
<div data-controller="phem-toggle-group" data-phem-toggle-group-value-value='["left"]' aria-label="Alignment">
    <button type="button" data-phem-toggle-group-target="item" data-value="left">Left</button>
    <button type="button" data-phem-toggle-group-target="item" data-value="center">Center</button>
    <button type="button" data-phem-toggle-group-target="item" data-value="right">Right</button>
</div>
```

The value of an item is its `data-value` attribute, the `value` value of a `phem-toggle` item, its key (`key={{ ... }}`), or its position. The `value` value is the state: the values of the pressed items. The server render gives the first value, and a server render with a new value sets it. The state belongs to the client, so it stays after the morph of an event.

| Value | Default | Effect |
|---|---|---|
| `value` | `[]` | The values of the pressed items |
| `multiple` | `false` | More than one item can be pressed. With no `multiple`, a pressed item releases the other items |
| `orientation` | `horizontal` | `horizontal` (Left and Right move the focus) or `vertical` (Up and Down) |
| `loop-focus` | `true` | The arrow key on the last item goes to the first item |
| `disabled` | `false` | No item changes. Gives `data-disabled` and `aria-disabled="true"` |

| Key | Effect |
|---|---|
| ArrowLeft, ArrowRight (or ArrowUp, ArrowDown when vertical) | Moves the focus to the previous or the next item, and skips a disabled item |
| Home, End | Moves the focus to the first or the last item |
| Space, Enter | Presses or releases the item with the focus (a `<button>` clicks on them) |

A click on an item presses it, and a click on a pressed item releases it. The items have a roving focus: one item is in the tab order (the item with the last focus, the first pressed item, or the first item that is not disabled).

In a [`phem-toolbar`](#group-controls-in-a-toolbar), make the group a `group` part of the toolbar, and its items also `item` parts of the toolbar. Then the toolbar moves the focus with the arrow keys, and gives the tab order and `data-orientation` (the `orientation` value of the toolbar, not of the group). The focus goes from the last item of the group to the next item of the toolbar. The group keeps the pressed state:

```html
<div data-controller="phem-toolbar" aria-label="Editor">
    <div data-controller="phem-toggle-group" data-phem-toolbar-target="group" data-phem-toggle-group-value-value='["start"]' aria-label="Justify">
        <button type="button" data-phem-toggle-group-target="item" data-phem-toolbar-target="item" data-value="start">Start</button>
        <button type="button" data-phem-toggle-group-target="item" data-phem-toolbar-target="item" data-value="end">End</button>
    </div>
    <button type="button" data-phem-toolbar-target="item">Bold</button>
</div>
```

ArrowRight on **End** moves the focus to **Bold**, and ArrowRight on **Bold** goes back to **Start**. One item of the toolbar is in the tab order: the item with the last focus, or the first item. In a toolbar, Home and End do not move the focus.

An item can be a [`phem-toggle`](#toggle-a-button-a-switch-or-a-checkbox) button, as a Base UI Toggle in a ToggleGroup:

```html
<div data-controller="phem-toggle-group" data-phem-toggle-group-multiple-value="true" aria-label="Style">
    <button type="button" data-controller="phem-toggle" data-phem-toggle-group-target="item" data-value="bold">Bold</button>
    <button type="button" data-controller="phem-toggle" data-phem-toggle-group-target="item" data-value="italic">Italic</button>
</div>
```

The group owns the state of a `phem-toggle` item: it sets the `checked` value of the toggle, and the toggle gives it `aria-pressed` and `data-pressed`. Do not give a `checked` value to an item. A click on the item changes its state and dispatches `phem-toggle:change`, then the group changes the state of the other items. A disabled group stops the click before the toggle gets it. A `phem-toggle` item with the `disabled` value is a disabled item. The group has no form field: use the `input` of a `phem-toggle` item, or `phem:bind` on a hidden input.

The group gets `role="group"` when it has no role, `data-orientation` and `data-multiple`. The items get `aria-pressed`, `data-pressed`, `data-orientation`, `tabindex`, and `data-disabled` when the group or the item is disabled. Each change by the user dispatches `phem-toggle-group:change` with `{ value }`. After a click on **Center**, the browser shows:

```html
<div data-controller="phem-toggle-group" data-phem-toggle-group-value-value="[&quot;center&quot;]" aria-label="Alignment" role="group" data-orientation="horizontal">
    <button type="button" data-phem-toggle-group-target="item" data-value="left" aria-pressed="false" data-orientation="horizontal" tabindex="-1">Left</button>
    <button type="button" data-phem-toggle-group-target="item" data-value="center" aria-pressed="true" data-orientation="horizontal" tabindex="0" data-pressed="">Center</button>
    <button type="button" data-phem-toggle-group-target="item" data-value="right" aria-pressed="false" data-orientation="horizontal" tabindex="-1">Right</button>
</div>
```

## Group controls in a toolbar

`phem-toolbar` gives a row of controls one tab stop. The arrow keys move the focus between the items:

```html
<div data-controller="phem-toolbar" aria-label="Format">
    <div data-phem-toolbar-target="group" aria-label="Alignment">
        <button type="button" data-phem-toolbar-target="item">Left</button>
        <button type="button" data-phem-toolbar-target="item">Center</button>
    </div>
    <div data-phem-toolbar-target="separator"></div>
    <button type="button" data-phem-toolbar-target="item" aria-disabled="true" onClick={{ bold }}>Bold</button>
    <a href="/help" data-phem-toolbar-target="item">Help</a>
    <input data-phem-toolbar-target="item" aria-label="Zoom" value="100" />
</div>
```

An `item` is a button, a link, an input, or another element that can get the focus. A `group` part holds some of the items, and a `separator` part divides them. A group can be a `phem-toggle-group`: see [Press one button of a group](#press-one-button-of-a-group). Only one item is in the tab order: the item that had the focus last, else the first item. The tab stop stays after the morph of an event.

| Value | Default | Description |
|---|---|---|
| `orientation` | `horizontal` | `horizontal` or `vertical`: the arrow keys that move the focus. |
| `loopFocus` | `true` | From the last item, the arrow key goes to the first item. |
| `disabled` | `false` | Disables all the items. |

| Key | Action |
|---|---|
| ArrowRight, ArrowLeft | Move the focus to the next or to the previous item (`horizontal`). In a right-to-left page, the keys change places. |
| ArrowDown, ArrowUp | Move the focus to the next or to the previous item (`vertical`). |

Home and End do not move the focus, as in Base UI. In a text input, Home, End, ArrowLeft and ArrowRight move the caret. ArrowLeft and ArrowRight move the focus only when the caret is at the start or at the end of the text.

A disabled item stays in the focus order, so a keyboard user can find it. A click on it does nothing, and an input takes no text. Give the item `aria-disabled="true"`, or give its group `aria-disabled="true"`, or set the `disabled` value. An item with the native `disabled` attribute does not get the focus.

The controller sets the ARIA roles. The toolbar gets `role="toolbar"` and `aria-orientation`, a group gets `role="group"`, and a separator gets `role="separator"` and the other orientation in `aria-orientation`. All the parts get `data-orientation` (a separator gets the other orientation). A disabled item gets `data-disabled`, and `data-focusable` when it stays in the focus order. The toolbar and a disabled group get `data-disabled`.

After the arrow keys move the focus to the input, the browser shows:

```html
<div data-controller="phem-toolbar" aria-label="Format" role="toolbar" aria-orientation="horizontal" data-orientation="horizontal">
    <div data-phem-toolbar-target="group" aria-label="Alignment" role="group" data-orientation="horizontal">
        <button type="button" data-phem-toolbar-target="item" data-orientation="horizontal" tabindex="-1">Left</button>
        <button type="button" data-phem-toolbar-target="item" data-orientation="horizontal" tabindex="-1">Center</button>
    </div>
    <div data-phem-toolbar-target="separator" role="separator" aria-orientation="vertical" data-orientation="vertical"></div>
    <button type="button" data-phem-toolbar-target="item" aria-disabled="true" data-orientation="horizontal" data-disabled data-focusable tabindex="-1" ...>Bold</button>
    <a href="/help" data-phem-toolbar-target="item" data-orientation="horizontal" tabindex="-1">Help</a>
    <input data-phem-toolbar-target="item" aria-label="Zoom" value="100" data-orientation="horizontal" tabindex="0">
</div>
```

## Choose one option in a radio group

`phem-radio-group` makes a group of radio buttons from elements that are not native inputs, for a radio with its own style:

```html
<div data-controller="phem-radio-group" data-phem-radio-group-value-value={{ apple }} aria-label="Apple">
    <label>
        <span data-phem-radio-group-target="radio" data-value="fuji">
            <span data-phem-radio-group-target="indicator"></span>
        </span>
        Fuji
    </label>
    <label>
        <span data-phem-radio-group-target="radio" data-value="gala">
            <span data-phem-radio-group-target="indicator"></span>
        </span>
        Gala
    </label>
    <input type="hidden" name="apple" data-phem-radio-group-target="input" phem:bind={{ apple }} phem:live />
</div>
```

The value of a radio is its `data-value` attribute, its key (`key={{ ... }}`), or its position. The `value` value is the state: the value of the checked radio. With no `value` value, no radio is checked. The server render gives its first value, and a handler can set a new value in a new render. The checked radio stays after the morph of an event.

| Value | Default | Description |
|---|---|---|
| `value` | none | The value of the checked radio. |
| `disabled` | `false` | Disables all the radios. |
| `readOnly` | `false` | The arrow keys move the focus, but the user cannot check a radio. |
| `required` | `false` | Gives the group `aria-required`. |

| Key | Action |
|---|---|
| ArrowDown, ArrowRight | Move the focus to the next radio and check it. From the last radio, go to the first radio. |
| ArrowUp, ArrowLeft | Move the focus to the previous radio and check it. |
| Space | Check the radio with the focus. |

A click on a radio, or on a `<label>` around it, checks it. The label also names the radio: the controller links them with `ariaLabelledByElements`, and gives them no `id`. The arrow keys skip a disabled radio: a radio with `disabled` or `aria-disabled="true"`. Only one radio is in the tab order: the checked radio, else the first radio that is not disabled.

The `input` part keeps the value for a form. A hidden input gets the value of the checked radio. A native radio input (`type="radio"`) is checked when its `value` is the value of the checked radio. When the user checks a radio, the input that changes sends `input` and `change`, so `phem:bind` and `phem:live` see the change. The controller also dispatches `phem-radio-group:change`, with the `value` in its detail.

The group gets `role="radiogroup"`, and `aria-disabled`, `aria-readonly` and `aria-required` for the values. A radio gets `role="radio"`, `aria-checked` and `tabindex`. The group, the radios and the indicators get `data-disabled`, `data-readonly` and `data-required`. A radio and its indicator get `data-checked` or `data-unchecked`. The indicator shows only when its radio is checked, with the animations of the [conventions](#animations).

After a click on **Gala**, the browser shows:

```html
<div data-controller="phem-radio-group" data-phem-radio-group-value-value="gala" aria-label="Apple" role="radiogroup">
    <label>
        <span data-phem-radio-group-target="radio" data-value="fuji" role="radio" aria-checked="false" tabindex="-1" data-unchecked aria-labelledby="">
            <span data-phem-radio-group-target="indicator" data-unchecked data-closed hidden></span>
        </span>
        Fuji
    </label>
    <label>
        <span data-phem-radio-group-target="radio" data-value="gala" role="radio" aria-checked="true" tabindex="0" data-checked aria-labelledby="">
            <span data-phem-radio-group-target="indicator" data-checked data-open></span>
        </span>
        Gala
    </label>
    <input type="hidden" name="apple" data-phem-radio-group-target="input" value="gala" ...>
</div>
```

## Give an element the keyboard of a button

A `<button>` is the best element for a button. When a button must be another element (a `<div>` or a `<span>`, as a Base UI button with `nativeButton={false}`), `phem-button` gives it the keyboard of a button:

```html
<div data-controller="phem-button" onClick={{ save }}>Save</div>
```

Enter clicks the element when the key goes down, and Space clicks it when the key goes up. The click runs the handler of the element, as a click of the pointer. The element gets `role="button"` and `tabindex="0"`, unless your markup gives them. The browser shows:

```html
<div data-controller="phem-button" data-phem-on-click="..." role="button" tabindex="0">Save</div>
```

A button with `aria-disabled="true"` keeps the focus, so a keyboard user can find it, but it does nothing: the keys do not click it, and a click of the pointer or of a script does not run its handler.

```html
<div data-controller="phem-button" aria-disabled={{ saving ? 'true' : null }} onClick={{ save }}>Save</div>
```

The controller also works on a native `<button>` that must keep the focus while it is disabled (`aria-disabled="true"` in place of `disabled`): it stops the click and the keys. A native button gets no role and no `tabindex`.

## Show an avatar with a fallback

`phem-avatar` shows the image of a user when it loads, else a fallback (for example the initials):

```html
<span data-controller="phem-avatar" data-phem-avatar-delay-value="600">
    <img data-phem-avatar-target="image" src={{ user.avatarUrl }} alt={{ user.name }} />
    <span data-phem-avatar-target="fallback">{{ user.initials }}</span>
</span>
```

The image is hidden until it loads. An image that the browser has already (in its cache) shows at once. The fallback shows while the image is not loaded, after the `delay` value: a fast image does not show the fallback for a short time. A new `src` or `srcset`, for example in the server render of an event, loads the image again.

| Value | Default | Description |
|---|---|---|
| `delay` | `0` | The time in milliseconds from the load of the page to the fallback. |

A fallback can have its own delay: give it `data-phem-avatar-delay="600"`, and render it with `hidden`, so it does not show before the controller connects:

```html
<span data-controller="phem-avatar">
    <img data-phem-avatar-target="image" src={{ user.avatarUrl }} alt={{ user.name }} />
    <span data-phem-avatar-target="fallback" data-phem-avatar-delay="600" hidden>{{ user.initials }}</span>
</span>
```

The load status of the image is `idle` (no image part), `loading`, `loaded`, or `error` (an image that does not load, or an image with no `src`). Each change of the status dispatches `phem-avatar:status`, with the `status` in its detail:

```html
<span data-controller="phem-avatar" data-action="phem-avatar:status->stats#avatar">...</span>
```

The image gets `data-loading` while it loads, and `data-error` when it does not load. When it loads, it gets `data-open`, with the animations of the [conventions](#animations). A hidden image or fallback gets `hidden`. The status stays after the morph of an event.

The image loads while it is hidden. Do not give it `loading="lazy"`: the browser does not load a lazy image that it does not show.

After an event gives the image a `src` that does not load, the browser shows:

```html
<span data-controller="phem-avatar">
    <img data-phem-avatar-target="image" src="..." alt="Ada Lovelace" data-error data-closed hidden>
    <span data-phem-avatar-target="fallback" data-open>AL</span>
</span>
```

## Validate a field in the browser

`phem-field` gives a form control a label, a description and errors, with the constraint validation of the browser (`required`, `type="email"`, `pattern`, `minlength`…). `phem-form` validates all the fields of a form before its submit:

```html
<form onSubmit={{ save }} data-controller="phem-form">
    <div data-controller="phem-field">
        <label data-phem-field-target="label">Name</label>
        <input id="name" name="name" required data-phem-field-target="control" />
        <p data-phem-field-target="description">Visible on your profile</p>
        <p data-phem-field-target="error" data-match="valueMissing" hidden>Enter your name.</p>
    </div>
    <button type="submit">Save</button>
</form>
```

The parts of a field are Stimulus targets:

| Part | Element |
|---|---|
| `label` | A `<label>`, or another element that names the control |
| `control` | An `<input>`, a `<select>`, a `<textarea>` or a custom control. A field can have more than one control, for example the slots of a [one-time code](#enter-a-one-time-code) |
| `description` | A text that describes the control. Zero or more |
| `error` | An error message. Zero or more |

The values of `phem-field`:

| Value | Default | Description |
|---|---|---|
| `data-phem-field-validation-mode-value` | the mode of the form, else `onSubmit` | `onSubmit`: the field validates on the submit of the form, then on each change. `onBlur`: the field validates when the control loses the focus. `onChange`: the field validates on each change |
| `data-phem-field-validation-debounce-time-value` | `0` | The time in milliseconds after the last change before the validation, in the `onChange` mode |
| `data-phem-field-disabled-value` | `false` | Disables the controls |

In all the modes, an invalid field validates again on each change, so its error goes when the user corrects the value. Enter in an `<input>` control also validates the field in all the modes. In a `phem-form`, Enter submits the form, and the submit validates the field.

The values of `phem-form`:

| Value | Default | Description |
|---|---|---|
| `data-phem-form-validation-mode-value` | `onSubmit` | The validation mode of the fields that have no mode of their own |

### Show the errors

An `error` part with `data-match` shows for one key of the `ValidityState` of the control: `valueMissing`, `typeMismatch`, `patternMismatch`, `tooShort`, `tooLong`, `rangeUnderflow`, `rangeOverflow`, `stepMismatch`, `badInput` or `customError`. With `data-match="valid"`, it shows when the field is valid. With `data-match="true"`, it always shows. An `error` part with no `data-match` shows for all the errors. When it has no text in the markup, it gets the message of the browser (`validationMessage`):

```html
<div data-controller="phem-field" data-phem-field-validation-mode-value="onBlur">
    <label data-phem-field-target="label">Email</label>
    <input name="email" type="email" data-phem-field-target="control" />
    <p data-phem-field-target="error" hidden></p>
</div>
```

Give the errors `hidden` in the markup, so they do not show before the controller starts. An error shows and hides with the animations of the [conventions](#animations) (`data-open`, `data-closed`, `data-starting-style`, `data-ending-style`).

### Submit the form

The form gets `novalidate`: the fields show their errors in place of the messages of the browser. On a submit, each field validates. When a field is invalid, `phem-form` stops the submit before the `onSubmit` handler sends its event, and before the browser sends the form. It focuses the first invalid control, selects its text, and sends `phem-form:invalid`, with the invalid fields in `detail.fields`. A control of the form that is not in a field shows the message of the browser (`reportValidity()`). A submit button with `formnovalidate` submits with no validation.

After a submit with an empty name, the browser shows:

```html
<div id="name-field" data-controller="phem-field" data-invalid="" data-focused="">
    <label id="name-label" data-phem-field-target="label" for="name" data-invalid="" data-focused="">Name</label>
    <input id="name" name="name" required="" value="" data-phem-field-target="control" aria-describedby="" data-invalid="" aria-invalid="true" data-focused="">
    <p class="description" data-phem-field-target="description" data-invalid="" data-focused="">Visible on your profile</p>
    <p id="name-missing" data-phem-field-target="error" data-match="valueMissing" data-invalid="" data-open="" data-focused="">Enter your name.</p>
</div>
```

The `aria-describedby=""` is empty because the description has no id: the control gets the element references of ARIA (`ariaDescribedByElements`) for the description and the error. When all the parts have an id, the control gets the ids in `aria-describedby`.

### Keep the server errors

A server error is a control that the server renders with `aria-invalid="true"`: a field with `phem:bind` and an error (see [Show the error of a bound field](forms.md#show-the-error-of-a-bound-field)), or a Symfony form with `form_row()`. The field is invalid until the user changes the value of the control. While the error stays, the form does not submit the same value again, and the control gets the message of the server as its custom validity. After the submit event of the form (its `onSubmit` handler) ends with a new render, the first control with a server error gets the focus and its text is selected, as in Base UI. An error of the browser, a hidden or disabled control, another event of the form (an `onChange` of the form), and a submit that ends with a redirect do not move the focus. When the user moved the focus out of the form while the request ran, the focus stays there.

An `error` part whose id is in the `aria-describedby` of the server render is the message of the server, for example the element of `phem:error`. It shows while the server error stays. After the user changes the value, it shows the errors of the browser instead, as the `Field.Error` of Base UI: for example the message of `valueMissing` when the user clears the control.

The server sends the HTML of a component only when it changes. When the user submits the same value again, the answer has no new HTML for the field, so the field shows the server error of the last render again. This applies to each event that sends the control: the events of its form and of the control, and each event for a control with `phem:bind`. A change of the value while the request runs, a redirect, and an event that sends no request keep the field with no server error:

```html
<div data-controller="phem-field">
    <label data-phem-field-target="label">Name</label>
    <input name="name" phem:bind={{ name }} data-phem-field-target="control" />
    <p phem:error={{ name }} data-phem-field-target="error" />
</div>
```

After a submit of a name that the server refuses, `#name` has the focus and its text ("Shoes") is selected. The browser shows:

```html
<div id="name-field" data-controller="phem-field" data-filled="" data-touched="" data-invalid="" data-focused="">
    <label id="name-label" data-phem-field-target="label" data-filled="" data-touched="" data-invalid="" data-focused="" for="name">Name</label>
    <input id="name" name="name" required="" value="Shoes" data-phem-field-target="control" aria-describedby="" data-filled="" data-touched="" aria-invalid="true" data-invalid="" data-focused="">
    <p class="description" data-phem-field-target="description" data-filled="" data-touched="" data-invalid="" data-focused="">Visible on your profile</p>
    <p id="name-missing" data-phem-field-target="error" data-match="valueMissing" hidden="" data-closed="" data-filled="" data-touched="" data-invalid="" data-focused="">Enter your name.</p>
    <p id="name-error" data-phem-field-target="error" data-invalid="" data-touched="" data-filled="" data-open="" data-focused="">This name is taken.</p>
</div>
```

In a field with a part of the server, an `error` part with no `data-match` does not show: the part of the server shows these errors, so a message does not show two times. An `error` part keeps the elements of its text, for example a link. Do not write `aria-invalid` on a control yourself: the controller sets it.

### Data attributes and ARIA

All the parts (the field, the labels, the controls, the descriptions and the errors) get these attributes:

| Attribute | When |
|---|---|
| `data-valid` | The last validation found no error |
| `data-invalid` | The last validation found an error, or the control has a server error |
| `data-dirty` | The value is not the value of the server render |
| `data-touched` | The control lost the focus one time |
| `data-filled` | The control has a value |
| `data-focused` | The control has the focus |
| `data-disabled` | The field is disabled: the `disabled` value, or disabled controls (also in a disabled `<fieldset>`) |

Before the first validation, a field has no `data-valid` and no `data-invalid`. The label links to the control: a `<label>` gets `for` when the control has an `id`, else the control gets `aria-labelledby` (an element reference when the label has no id). A bound control with no `id` gets `aria-labelledby`, also after the morph of an event: the temporary id that the morph gives it is not an id to link to. A click on a label that is not a native label of the control focuses the control. The control gets `aria-invalid="true"` when the field is invalid.

The state stays after the morph of an event: the visible errors, `aria-invalid`, `novalidate` and the data attributes.

### Group the fields

A Base UI Fieldset needs no controller. Use a `<fieldset>` with a `<legend>`. A `disabled` fieldset disables its controls, and the fields in it get `data-disabled`:

```html
<fieldset disabled>
    <legend>Address</legend>
    <div data-controller="phem-field">...</div>
</fieldset>
```

Style the legend of a disabled fieldset with `fieldset:disabled > legend`.

## Edit a number with steps

`phem-number-field` is a text input for a number. It has increment and decrement buttons, keys, the mouse wheel, a scrub area and the number format of the locale:

```html
<div data-controller="phem-number-field" data-phem-number-field-value-value="10"
     data-phem-number-field-min-value="0" data-phem-number-field-max-value="5000"
     data-phem-number-field-locale-value="de-DE">
    <span data-phem-number-field-target="scrubArea">
        <label for="amount">Amount</label>
        <span data-phem-number-field-target="scrubAreaCursor" hidden>↔</span>
    </span>
    <div data-phem-number-field-target="group">
        <button data-phem-number-field-target="decrement">−</button>
        <input id="amount" data-phem-number-field-target="input">
        <button data-phem-number-field-target="increment">+</button>
    </div>
    <input type="hidden" name="amount" data-phem-number-field-target="hiddenInput">
</div>
```

The `value` value is the state: the raw number (for example `1234.5`), or an empty string for no value. Set it to give a value on load. A handler can set it in a new render. The value stays after the morph of an event. The `input` part shows the value in the format of the locale. The `hiddenInput` part gets the raw value for the form. With no `hiddenInput`, give the `name` to the `input`: the form then gets the formatted text.

The user types a number in the format of the locale. The controller reads the group and decimal separators of the locale, so `1.234,5` is `1234.5` in `de-DE`. The value changes while the user types. On blur, the input shows the formatted value, and a value out of the limits goes to the nearest limit. Enter does not format the text: it puts the value of the hidden input in the limits, so a form submit gets a valid value, and it ends the change (`phem-number-field:commit` with the reason `keyboard`). A text that is not a number gives the last value again.

A character that no number of the format has does not go in the input. A space goes in only when the format has one, for example the group separator of `fr-FR`: in `en-US`, "1 2" gives "12". The input takes one sign, one decimal separator, one currency sign and one percent (or permille) sign, unless the new character replaces the selected one. A minus sign goes in only when the value can be negative (no `min`, a negative `min`, or `allowOutOfRange`). The digits of other scripts (Arabic-Indic, Persian, fullwidth and Han) are the same as `0`-`9`: `٤٢` is `42`. The Arabic decimal separator (`٫`) is the decimal separator of the locale, and the Arabic group separator (`٬`) is its group separator. The fullwidth `．` and `，` are `.` and `,`: `1，5` is `1.5` in `de-DE`.

| Value | Default | Description |
| --- | --- | --- |
| `value` | empty | The state: the raw number, or empty for no value. |
| `min`, `max` | none | The limits. A step always stays in the limits. |
| `step` | `1` | The step of the arrow keys, the buttons, the wheel and the scrub area. |
| `smallStep` | `0.1` | The step with Alt. |
| `largeStep` | `10` | The step with Shift. |
| `allowOutOfRange` | `false` | A typed value out of the limits stays on blur. |
| `snapOnStep` | `false` | A step goes to a multiple of the step, from `min` (or 0). |
| `allowWheelScrub` | `false` | The mouse wheel changes the value while the input has the focus. |
| `format` | none | The options of `Intl.NumberFormat`, as JSON: `{"style":"currency","currency":"EUR"}`. |
| `locale` | the browser | The locale of the format and of the typed text. |
| `disabled`, `readOnly`, `required` | `false` | The state of the inputs. |
| `pixelSensitivity` | `2` | The pixels of a movement in the scrub area for one step. |
| `direction` | `horizontal` | The movement of the scrub area: `horizontal` or `vertical`. |

| Key (on the input) | Action |
| --- | --- |
| ArrowUp, ArrowDown | Add or remove one step. With Alt, the small step. With Shift, the large step. |
| Home, End | Go to `min` or to `max`. |

The first step from no value goes to 0, in the limits (`min` when `min` is more than 0, `max` when `max` is less than 0). This is one step, also for a wheel step or a scrub of many steps. PageUp and PageDown are keys of the browser, as in Base UI: use Shift with an arrow key for the large step.

A press on a button changes the value one time. When the user holds the button, the value changes again after 400 ms, and then each 60 ms. The changes stop at the limit, and while the pointer is out of the button: they start again when the pointer comes back. The focus stays in the input. Alt and Shift give the small and the large steps. A touch or a pen press waits 50 ms. A movement (3 moves, or 8 px) before then is a scroll: the press does nothing, and the click does one step. A button gets `disabled` at its limit.

A press in the `scrubArea` part locks the pointer on the body (the Pointer Lock API), except on WebKit (Safari), as in Base UI. The movement changes the value as in Base UI: after a movement of `pixelSensitivity` pixels (2 by default), the value changes by one step for each pixel of the last movement. While the pointer is locked, the `scrubAreaCursor` part shows with its center at the position of the pointer, as a virtual cursor, and goes to the opposite edge at an edge of the viewport. With `data-phem-number-field-teleport-distance-value="40"`, the edges are those of the scrub area plus 20 px on each side, so the cursor stays near the label. With no pointer lock, the native pointer stays. A press with no movement is a click: a click on the label focuses the input. While the pointer is locked, the browser sends this click to the body, so the controller sends it to the target. With no lock (a touch, WebKit), the browser sends it. The scrub area gets `role="presentation"`, and `phem.css` gives it `touch-action: none` and `user-select: none`: a touch scrubs and does not scroll the page.

All the parts get `data-disabled`, `data-readonly`, `data-required`, and `data-scrubbing` while the user scrubs. The `group` part gets `role="group"`. The input gets `inputmode="numeric"` (on iOS `decimal` when `min` is 0 or more, else `text`: the numeric keyboard of iOS has no minus and no decimal key), `aria-roledescription="Number field"`, `autocomplete="off"` and `spellcheck="false"`, as Base UI does, unless the markup gives them. The buttons get `type="button"`, `tabindex="-1"`, `aria-label` ("Increase" or "Decrease") and `aria-controls`. The controller links a button to an input with no `id` with an element reference.

A change of the value dispatches `phem-number-field:change` (`detail`: `value`, `reason`) and sends `input` on the inputs. The end of a change (a blur, a key, the release of a button or of the scrub area, a wheel step) dispatches `phem-number-field:commit` and sends `change` on the inputs. The controller sets the value of the hidden input before it sends the events, so a bound hidden input sends its value. The `reason` is `input-change`, `input-clear`, `input-blur`, `keyboard`, `increment-press`, `decrement-press`, `wheel` or `scrub`.

After Shift+ArrowUp and an event, the browser shows:

```html
<div id="field" data-controller="phem-number-field" data-phem-number-field-value-value="20" data-phem-number-field-min-value="0" data-phem-number-field-max-value="5000" data-phem-number-field-locale-value="de-DE" data-phem-number-field-allow-wheel-scrub-value="">
    <span id="area" data-phem-number-field-target="scrubArea" style="...">
        <label for="amount">Amount</label>
        <span id="cursor" data-phem-number-field-target="scrubAreaCursor" hidden="">↔</span>
    </span>
    <div id="group" data-phem-number-field-target="group" role="group">
        <button id="dec" data-phem-number-field-target="decrement" type="button" tabindex="-1" aria-label="Decrease" aria-controls="amount">−</button>
        <input id="amount" data-phem-number-field-target="input" aria-roledescription="Number field" autocomplete="off" autocorrect="off" spellcheck="false" inputmode="numeric" value="20">
        <button id="inc" data-phem-number-field-target="increment" type="button" tabindex="-1" aria-label="Increase" aria-controls="amount">+</button>
    </div>
    <input id="raw" type="hidden" name="amount" data-phem-number-field-target="hiddenInput" value="20">
</div>
```

## Enter a one-time code

`phem-otp-field` shows a code with one input for each character (a slot):

```html
<label id="otp-label" for="otp-1">Verification code</label>
<div data-controller="phem-otp-field" data-phem-otp-field-auto-submit-value="true">
    <input id="otp-1" data-phem-otp-field-target="input" />
    <input data-phem-otp-field-target="input" />
    <input data-phem-otp-field-target="input" />
    <span data-phem-otp-field-target="separator">-</span>
    <input data-phem-otp-field-target="input" />
    <input data-phem-otp-field-target="input" />
    <input data-phem-otp-field-target="input" />
    <input type="hidden" name="code" data-phem-otp-field-target="hiddenInput" />
</div>
```

The `input` parts are the slots. The `hiddenInput` part gets the full value, so a form submit and a phem event send it with its `name`. When the user changes the value, the hidden input gets an `input` and a `change` event. The `separator` part gets `role="separator"` and `data-orientation="horizontal"`.

| Value | Default | Description |
|---|---|---|
| `data-phem-otp-field-value-value` | `""` | The state: the characters of the slots. A server render with a new value sets it |
| `data-phem-otp-field-length-value` | the number of `input` parts | The number of slots |
| `data-phem-otp-field-validation-type-value` | `numeric` | The characters: `numeric` (digits), `alpha` (the letters A to Z), `alphanumeric`, or `none` (all the characters except spaces) |
| `data-phem-otp-field-normalize-value` | | `uppercase` or `lowercase`: changes the accepted characters, for example a recovery code in capital letters. The validation type filters the result again |
| `data-phem-otp-field-input-mode-value` | `numeric` for `numeric`, else `text` | The `inputmode` of the slots: the keyboard of a phone |
| `data-phem-otp-field-mask-value` | `false` | The slots are `type="password"`. Else they are `type="text"`: the controller replaces a `type` of the markup. A change of the value changes the type |
| `data-phem-otp-field-auto-submit-value` | `false` | Submits the form (`requestSubmit()`) when all the slots have a value |
| `data-phem-otp-field-autocomplete-value` | `one-time-code` | The `autocomplete` of the first slot. The others get `off` |
| `data-phem-otp-field-disabled-value` | `false` | Disables the slots and the hidden input |
| `data-phem-otp-field-read-only-value` | `false` | The user cannot change the value |
| `data-phem-otp-field-required-value` | `false` | The slots are `required` |

A character that the user types goes in its slot, and the focus goes to the next slot. When a slot has two characters (its character and a new one), the two characters go in the slots from it on, in the order of the text of the slot, as in Base UI: with the caret before the `3` of `123456`, a `9` gives `129356`. During an IME composition, the slot keeps the text of the IME, and the keys are for the IME. The end of the composition puts the text in the slots one time. A paste fills the slots from the slot with the focus, without the spaces. The browser can fill the code from a message (`autocomplete="one-time-code"`): all the characters go in the slots. A character that the validation type refuses does not go in the slot. The value has no gaps: a click on a slot after the first empty slot focuses the first empty slot.

| Key | Action |
|---|---|
| Backspace | Removes the character of the slot, and focuses the slot before. On an empty slot, removes the character of the slot before |
| Ctrl+Backspace (Command on a Mac) | Removes all the characters, and focuses the first slot |
| Delete | Removes the character of the slot |
| ArrowLeft / ArrowRight | Focuses the slot before / after (up to the first empty slot). With Ctrl or Command: the first slot / the first empty slot |
| Home, ArrowUp / End, ArrowDown | Focuses the first slot / the first empty slot (or the last slot) |

A press of the mouse on a slot selects its character, so the next character replaces it.

The slots have one tab stop: the slot with the focus, or else the first empty slot (the last slot when the code is complete). So Tab and Shift+Tab go out of the field from each slot. Each slot gets `inputmode`, a `pattern` of one character (`[0-9]` for `numeric`), `spellcheck="false"`, `autocorrect="off"` and `enterkeyhint` (`done` on the last slot, else `next`). The first slot gets `maxlength` (the number of slots), so the browser can fill all the code. The root gets `role="group"` and `aria-labelledby` to the label of the first slot, unless it has an `aria-label`. The first slot uses the label of the field. A slot after the first one with no `aria-label` gets `aria-label="Character 2 of 6"`. An attribute that the markup gives stays.

The events:

| Event | Detail |
|---|---|
| `phem-otp-field:change` | `value`, and `reason`: `input-change`, `input-clear`, `input-paste` or `keyboard` |
| `phem-otp-field:complete` | `value`, when a typed character fills the last empty slot, or at each paste after which all the slots have a value (also the same code again, or one character in a complete code), as in Base UI. A typed character in a complete code does not send it |
| `phem-otp-field:invalid` | `value`: the text with a character that the validation type refuses |

After the user types `123`, the browser shows:

```html
<div id="otp" data-controller="phem-otp-field" data-phem-otp-field-auto-submit-value="true" role="group" aria-labelledby="otp-label" data-focused="" data-phem-otp-field-value-value="123" data-filled="">
    <input data-phem-key="1" id="otp-1" data-phem-otp-field-target="input" tabindex="-1" inputmode="numeric" autocomplete="one-time-code" spellcheck="false" type="text" autocorrect="off" enterkeyhint="next" maxlength="6" pattern="[0-9]" data-focused="" data-filled="">
    <input data-phem-key="2" id="otp-2" data-phem-otp-field-target="input" tabindex="-1" inputmode="numeric" autocomplete="off" spellcheck="false" type="text" autocorrect="off" enterkeyhint="next" pattern="[0-9]" aria-label="Character 2 of 6" data-focused="" data-filled="">
    <input data-phem-key="3" id="otp-3" data-phem-otp-field-target="input" tabindex="-1" inputmode="numeric" autocomplete="off" spellcheck="false" type="text" autocorrect="off" enterkeyhint="next" pattern="[0-9]" aria-label="Character 3 of 6" data-focused="" data-filled="">
    <span id="dash" data-phem-otp-field-target="separator" role="separator" data-orientation="horizontal">-</span>
    <input data-phem-key="4" id="otp-4" data-phem-otp-field-target="input" tabindex="0" inputmode="numeric" autocomplete="off" spellcheck="false" type="text" autocorrect="off" enterkeyhint="next" pattern="[0-9]" aria-label="Character 4 of 6" data-focused="">
    <input data-phem-key="5" id="otp-5" data-phem-otp-field-target="input" tabindex="-1" inputmode="numeric" autocomplete="off" spellcheck="false" type="text" autocorrect="off" enterkeyhint="next" pattern="[0-9]" aria-label="Character 5 of 6" data-focused="">
    <input data-phem-key="6" id="otp-6" data-phem-otp-field-target="input" tabindex="-1" inputmode="numeric" autocomplete="off" spellcheck="false" type="text" autocorrect="off" enterkeyhint="done" pattern="[0-9]" aria-label="Character 6 of 6" data-focused="">
    <input id="code" type="hidden" name="code" data-phem-otp-field-target="hiddenInput" value="123" data-phem-touched="">
</div>
```

The controller element and the slots get `data-disabled`, `data-readonly`, `data-required`, `data-complete` (all the slots have a value) and `data-focused` (a slot has the focus). The controller element gets `data-filled` when the value is not empty, and a slot gets it when it has a character. The value stays after the morph of an event.

To validate the code and show its errors, give the slots the `control` target of a [`phem-field`](#validate-a-field-in-the-browser) around it, with `data-phem-otp-field-required-value="true"`.

## Select a value on a slider

`phem-slider` moves one thumb, or more thumbs for a range, on a track, with the pointer and the keys. Each thumb has a native `<input type="range">`. It gives the ARIA and the value of the form:

```html
<div data-controller="phem-slider" data-phem-slider-value-value="[20, 60]"
     data-phem-slider-min-steps-between-values-value="10">
    <div id="price-label" data-phem-slider-target="label">Price</div>
    <output data-phem-slider-target="value"></output>
    <div data-phem-slider-target="control">
        <div data-phem-slider-target="track">
            <div data-phem-slider-target="indicator"></div>
            <div data-phem-slider-target="thumb">
                <input type="range" name="min" aria-label="Minimum price" class="sr-only">
            </div>
            <div data-phem-slider-target="thumb">
                <input type="range" name="max" aria-label="Maximum price" class="sr-only">
            </div>
        </div>
    </div>
</div>
```

The `value` value is the state: one number (`25`) for one thumb, or a JSON list with one number for each thumb (`[20, 60]`). Set it to give the values on load. A handler can set it in a new render. The values stay after the morph of an event. Hide the range inputs visually (for example with `sr-only`): the controller moves the thumbs.

A press on the `control` part moves the nearest thumb to the point and starts a drag. The pointer capture keeps the drag when the pointer goes out of the control. A press on a thumb does not move it: the drag moves it from there. Two thumbs stay at least `minStepsBetweenValues` steps apart, and they do not cross.

The press gives the focus to the thumb input with no focus ring (`focusVisible: false`), as in Base UI. A focus with the keyboard (Tab) shows the ring.

| Value | Default | Description |
| --- | --- | --- |
| `value` | `min` | The state: a number, or a JSON list of numbers. |
| `min`, `max` | `0`, `100` | The limits. |
| `step` | `1` | The step of the values, from `min`. |
| `largeStep` | `10` | The step with Shift, and of PageUp and PageDown. |
| `minStepsBetweenValues` | `0` | The minimum steps between two thumbs of a range. |
| `thumbCollisionBehavior` | `push` | In a drag, a thumb that meets another thumb: `push` pushes it, `swap` changes places with it, `none` stops. |
| `orientation` | `horizontal` | `horizontal` or `vertical`. |
| `thumbAlignment` | `center` | `center`: the center of the thumb is at the edge of the control at `min` and `max`. `edge`: the thumb stays in the control. |
| `disabled` | `false` | The slider ignores the user. |
| `format` | none | The options of `Intl.NumberFormat`, as JSON, for the `value` part and `aria-valuetext`. |
| `locale` | the browser | The locale of the format. |

| Key (on a thumb input) | Action |
| --- | --- |
| ArrowRight, ArrowUp | Add one step. With Shift, the large step. |
| ArrowLeft, ArrowDown | Remove one step. With Shift, the large step. |
| PageUp, PageDown | Add or remove the large step. |
| Home, End | Go to the lowest or to the highest value that the thumb can have. |

In a right-to-left page, ArrowLeft adds and ArrowRight removes. The keys stop a thumb at the other thumb.

The controller gives the positions as inline styles, as Base UI does. The `track` gets `position: relative`, and the `control` gets `touch-action: none`. In the horizontal orientation, a thumb gets `position: absolute`, `inset-inline-start` (the percent of its value), `top: 50%` and `translate: -50% -50%`. The `indicator` gets `inset-inline-start` and `width`, from the first to the last value (from the start to the value with one thumb). In the vertical orientation, they get `bottom` and `height`. While the user presses a thumb, it gets `z-index: 1`. In a range, the thumb of the press gets `z-index: 2`, and the thumb of the last change gets `z-index: 1`.

The thumb inputs get `min`, `max`, `step`, `value`, `aria-valuenow`, `aria-orientation` and `disabled`. With two thumbs, they get `aria-valuetext` ("20 start range" and "60 end range"). With one thumb and a `format`, they get the formatted value. An `aria-valuetext` that the markup gives stays. The root gets `role="group"`. The root and the inputs with no `aria-label` get a link to the `label` part (`aria-labelledby`). The `value` part shows the formatted values, separated by " – ", and gets `aria-live="off"`. All the parts get `data-orientation`, `data-disabled`, and `data-dragging` while the user drags. The thumbs get `data-index`.

A change of a value dispatches `phem-slider:change` (`detail`: `value`, `values`, `index`, `reason`) and sends `input` on the inputs that change. The end of a change (the release of the pointer, a key) dispatches `phem-slider:commit` (Base UI `onValueCommitted`) and sends `change`. The `value` of the detail is a number for one thumb, or the list. The `reason` is `track-press`, `drag`, `keyboard` or `input-change`.

After ArrowRight on the second thumb and an event, the browser shows:

```html
<div id="price" data-controller="phem-slider" data-phem-slider-value-value="[20,61]" data-phem-slider-min-steps-between-values-value="10" data-orientation="horizontal" role="group" aria-labelledby="price-label">
    <div id="price-label" data-phem-slider-target="label">Price</div>
    <output id="price-value" data-phem-slider-target="value" data-orientation="horizontal" aria-live="off">20 – 61</output>
    <div id="price-control" class="control" data-phem-slider-target="control" data-orientation="horizontal" style="touch-action: none;">
        <div class="track" data-phem-slider-target="track" data-orientation="horizontal" style="position: relative;">
            <div id="price-indicator" class="indicator" data-phem-slider-target="indicator" data-orientation="horizontal" style="position: relative; width: 41%; inset-inline-start: 20%; height: inherit;"></div>
            <div id="price-min" class="thumb" data-phem-slider-target="thumb" data-orientation="horizontal" data-index="0" style="position: absolute; inset-inline-start: 20%; top: 50%; translate: -50% -50%;">
                <input id="price-min-input" type="range" name="min" aria-label="Minimum price" class="sr-only" value="20" aria-valuenow="20" aria-valuetext="20 start range" min="0" max="100" step="1" aria-orientation="horizontal">
            </div>
            <div id="price-max" class="thumb" data-phem-slider-target="thumb" data-orientation="horizontal" data-index="1" style="position: absolute; z-index: 1; inset-inline-start: 61%; top: 50%; translate: -50% -50%;">
                <input id="price-max-input" type="range" name="max" aria-label="Maximum price" class="sr-only" value="61" aria-valuenow="61" aria-valuetext="61 end range" min="0" max="100" step="1" aria-orientation="horizontal">
            </div>
        </div>
    </div>
</div>
```

## Show custom scrollbars

`phem-scroll-area` puts custom scrollbars over a native scroll container:

```html
<div data-controller="phem-scroll-area" class="h-32 w-96">
    <div data-phem-scroll-area-target="viewport" class="h-full">
        <div data-phem-scroll-area-target="content">...</div>
    </div>
    <div data-phem-scroll-area-target="scrollbar" data-orientation="vertical" class="flex w-2">
        <div data-phem-scroll-area-target="thumb" class="w-full bg-neutral-900"></div>
    </div>
    <div data-phem-scroll-area-target="scrollbar" data-orientation="horizontal" class="flex h-2">
        <div data-phem-scroll-area-target="thumb" class="h-full bg-neutral-900"></div>
    </div>
    <div data-phem-scroll-area-target="corner"></div>
</div>
```

The `viewport` part is the element that scrolls. The controller gives it `overflow: scroll` and removes its native scrollbar (`scrollbar-width: none`), so the wheel, a touch and the keyboard scroll it as usual. The `content` part gets `min-width: fit-content`, so a wide content overflows on the horizontal axis. A `scrollbar` part has the `data-orientation` of the markup: `vertical` (the default) or `horizontal`. Its `thumb` part shows the visible part of the content, with a minimum size of 16px.

A drag of a thumb scrolls the viewport. The pointer capture keeps the drag when the pointer goes out of the scroll area. A press on a scrollbar out of its thumb scrolls the viewport, so that the center of the thumb goes to the pointer, and the drag continues from there. A scrollbar gets `hidden` when its axis has no overflow, unless it has the `data-keep-mounted` attribute. The `corner` part is the square between the two scrollbars. It gets `hidden` when one axis has no overflow.

| Value | Default | Description |
| :-- | :-- | :-- |
| `overflowEdgeThreshold` | `0` | The distance (px) from an edge before its `data-overflow-*` attribute shows. A number for all the edges, or a JSON object with `xStart`, `xEnd`, `yStart` and `yEnd`, for example `data-phem-scroll-area-overflow-edge-threshold-value='{"yEnd": 20}'`. |

The scroll area has no keys of its own: the viewport is a native scroll container, and the browser scrolls it with the arrow keys, Page Up, Page Down, Home and End. The viewport gets `tabindex="0"` when its content is wider or taller than it, so Tab goes to it. A viewport with no overflow gets `tabindex="-1"`, as in Base UI: Tab does not stop on an area that does not scroll.

The root, the viewport, the content and the scrollbars get these attributes:

| Attribute | Description |
| :-- | :-- |
| `data-has-overflow-x`, `data-has-overflow-y` | The content is wider or taller than the viewport. |
| `data-overflow-x-start`, `data-overflow-x-end` | The content continues past the start or the end edge on the horizontal axis. |
| `data-overflow-y-start`, `data-overflow-y-end` | The content continues past the top or the bottom edge. |
| `data-scrolling` | The user scrolls. It goes 500 ms after the last scroll. A scrollbar and its thumb get it only for a scroll on their axis. The end of a drag of a thumb removes it at once. |
| `data-hovering` | On the scrollbars only: the pointer is over the scroll area. |

The thumbs get `data-orientation` and `data-scrolling`. The CSS variables are:

| Variable | Part | Description |
| :-- | :-- | :-- |
| `--scroll-area-corner-width`, `--scroll-area-corner-height` | root | The size of the corner: the width of the vertical scrollbar and the height of the horizontal scrollbar, or `0px` when one axis has no overflow. |
| `--scroll-area-overflow-x-start`, `--scroll-area-overflow-x-end`, `--scroll-area-overflow-y-start`, `--scroll-area-overflow-y-end` | viewport | The distance to each edge, in px. Use it for a fade at the edges. |
| `--scroll-area-thumb-height`, `--scroll-area-thumb-width` | scrollbar | The size of the thumb of a vertical or a horizontal scrollbar. |

The size and the offset of a thumb keep the fractions of a pixel. In a viewport of 100px with a content of 300px, scrolled to 100px, the thumb gets `--scroll-area-thumb-height: 33.333333333333336px` and `transform: translate3d(0px, 33.333333333333336px, 0px)`.

As in Base UI, the controller gives the parts their layout styles: the root gets `position: relative`, a scrollbar `position: absolute` on its edge (it stops at the corner), a thumb its size and a `transform` to its place, and the corner its size. The `style` attribute of the parts belongs to the client, so the morph does not change it. The scroll position, the attributes and the styles stay after the morph of an event. The controller renders them again after a scroll, a resize of the viewport, of the content or of a scrollbar, and the morph.

To show the scrollbars only while the pointer is over the area or while the user scrolls:

```css
[data-phem-scroll-area-target="scrollbar"] {
    opacity: 0;
    transition: opacity 150ms;
}

[data-phem-scroll-area-target="scrollbar"]:is([data-hovering], [data-scrolling]) {
    opacity: 1;
}
```

After a scroll of 200px in a viewport of 100px with a content of 500px, the browser shows:

```html
<div data-controller="phem-scroll-area" style="...; position: relative; --scroll-area-corner-width: 0px; --scroll-area-corner-height: 0px;" data-has-overflow-y="" data-overflow-y-end="" data-overflow-y-start="" data-scrolling="">
    <div data-phem-scroll-area-target="viewport" style="...; overflow: scroll; scrollbar-width: none; --scroll-area-overflow-x-start: 0px; --scroll-area-overflow-x-end: 0px; --scroll-area-overflow-y-start: 200px; --scroll-area-overflow-y-end: 200px;" data-has-overflow-y="" data-overflow-y-end="" data-overflow-y-start="" data-scrolling="">
        <div data-phem-scroll-area-target="content" style="...; min-width: fit-content;" data-has-overflow-y="" data-overflow-y-end="" data-overflow-y-start="" data-scrolling="">...</div>
    </div>
    <div data-phem-scroll-area-target="scrollbar" data-orientation="vertical" style="...; position: absolute; touch-action: none; user-select: none; top: 0px; bottom: var(--scroll-area-corner-height); inset-inline-end: 0px; --scroll-area-thumb-height: 20px;" data-has-overflow-y="" data-overflow-y-end="" data-overflow-y-start="" data-scrolling="">
        <div data-phem-scroll-area-target="thumb" style="...; height: var(--scroll-area-thumb-height); transform: translate3d(0px, 40px, 0px);" data-orientation="vertical" data-scrolling=""></div>
    </div>
    <div data-phem-scroll-area-target="scrollbar" data-orientation="horizontal" style="..." hidden="" ...>
        <div data-phem-scroll-area-target="thumb" data-orientation="horizontal" style="width: var(--scroll-area-thumb-width); transform: translate3d(0px, 0px, 0px);"></div>
    </div>
    <div data-phem-scroll-area-target="corner" hidden="" style="position: absolute; bottom: 0px; inset-inline-end: 0px; width: var(--scroll-area-corner-width); height: var(--scroll-area-corner-height);"></div>
</div>
```

## Select a value from a list

`phem-select` is a button that opens a list of options, as a native `<select>` that you can style:

```html
<div data-controller="phem-select" data-phem-select-value-value="fuji">
    <button data-phem-select-target="trigger" aria-label="Apple">
        <span data-phem-select-target="value" placeholder="Select apple"></span>
        <span data-phem-select-target="icon">▾</span>
    </button>
    <input data-phem-select-target="input" name="apple" hidden />
    <div data-phem-select-target="popup" popover="manual" hidden>
        <div data-phem-select-target="scrollUpArrow">▴</div>
        <div data-phem-select-target="list">
            <div data-phem-select-target="group">
                <div data-phem-select-target="groupLabel">Red</div>
                <div data-phem-select-target="item" data-value="gala">
                    <span data-phem-select-target="itemIndicator">✓</span>
                    <span data-phem-select-target="itemText">Gala</span>
                </div>
                <div data-phem-select-target="item" data-value="fuji">...</div>
            </div>
            <div data-phem-select-target="separator"></div>
            <div data-phem-select-target="item" data-value="granny" aria-disabled="true">...</div>
        </div>
        <div data-phem-select-target="scrollDownArrow">▾</div>
    </div>
</div>
```

The value of an item is its `data-value` attribute, its key (`key={{ ... }}`), or its position. The `value` value is the state: the value of the selected item. With the `multiple` value, it is a JSON array of the selected values, for example `data-phem-select-value-value='["gala","fuji"]'`. Set it to select an item on load, and a handler can set it in a new render. The value and the open popup belong to the client, so they stay after the morph of an event.

A `label` part (`data-phem-select-target="label"`) names the trigger, with `aria-labelledby` or with an element reference when the label has no id. A click on it gives the focus to the trigger, and does not open the popup.

The `value` part shows the label of the selected item: its `data-label` attribute, the text of its `itemText` part, or its text. With `multiple`, it shows the labels separated by a comma (`Gala, Fuji`). With no value, it shows its `placeholder` attribute, and it gets `data-placeholder`. The controller writes the text of the `value` part: put no other content in it.

Two attributes of the `value` part change what it shows:

- `data-phem-select-template`: the text for two values or more. `{first}` is the first label, `{others}` the number of the other values, `{count}` the number of values and `{labels}` the labels. With `data-phem-select-template="{first} (+{others} more)"`, the part shows `Gala (+2 more)`.
- `data-phem-select-markup`: the part shows a copy of the markup of the `itemText` part of the selected item, with no ids, for an item with more than a text (a name and a price, for example).

| Value | Default | Description |
| :-- | :-- | :-- |
| `value` | | The selected value, or a JSON array with `multiple`. |
| `multiple` | `false` | The user selects more than one item. A click on an item adds or removes its value, and the popup stays open. |
| `alignItemWithTrigger` | `true` | The popup goes over the trigger: the text of the selected item (or of the first item) is on the text of the `value` part, and the popup gets `data-side="none"`. When the popup is too tall for the viewport, it gets smaller (10 px from the top and the bottom of the viewport) and its list scrolls. The popup goes next to the trigger when the trigger is less than 20 px from the top or less than 40 px from the bottom of the viewport, when the popup would be less than 100 px high (or less than the full height of a list that is shorter than 100 px), when its list does not scroll, or after a touch. The popup over the trigger stays 5 px from the left and the right of the viewport: the `collisionPadding` value applies only to a popup next to the trigger. |
| `modal` | `true` | While the popup is open, the page does not scroll and the elements out of the popup get no pointer events. |
| `disabled` | `false` | The select does not open. The trigger gets `disabled` (or `aria-disabled` when it is not a button), and the input gets `disabled`. |
| `readOnly` | `false` | The select does not open and the value does not change. |
| `required` | `false` | The input gets `required`. |
| `loopFocus` | `true` | The arrow keys go from the last item to the first item. |
| `highlightItemOnHover` | `true` | A move of the pointer on an item gives it the focus. An item that comes under a pointer that does not move (the popup opens over the trigger) does not get it. A press of the mouse on the trigger of a select with no value gives the focus to the first item. When the pointer leaves the items, the highlight goes away and the popup gets the focus. |
| `open` | `false` | The popup is open on load. A script can change it. |
| `side`, `align`, `sideOffset`, `alignOffset`, `collisionPadding`, `arrowPadding` | `bottom`, `center`, `0`, `0`, `5`, `5` | The place of the popup next to the trigger, as a [popover](#show-a-popover). |

A press on the trigger opens the popup. The press does not select text, and it gives the trigger a focus of the mouse, with no focus ring, as in Base UI. When the user drags the pointer from the trigger and releases it on an item, the item is selected. A click on an item selects it and closes the popup, and the focus goes back to the trigger with no focus ring. A press of the mouse outside closes the popup at the press, and the focus goes back to the trigger, unless the press gives the focus to another element, for example a text field. A touch outside closes the popup at the end of the touch, or when the touch moves (a scroll). The focus out of the select closes the popup.

| Key | Where | Action |
| :-- | :-- | :-- |
| ArrowDown, Enter, Space | trigger | Opens the popup. The focus goes to the selected item, or to the first item. |
| ArrowUp | trigger | Opens the popup. The focus goes to the selected item, or to the last item. |
| A letter | trigger | Selects the next item that starts with it, with no popup (not with `multiple`). |
| ArrowDown, ArrowUp | popup | Moves the focus to the next or the previous item. |
| Home, End | popup | Moves the focus to the first or the last item. |
| A letter | popup | Moves the focus to the next item that starts with the letters. The typed text stays for 750 ms. A new text starts after the selected item. A letter that matches no item starts a new text. |
| Enter, Space | popup | Selects the item with the focus. |
| Escape | popup | Closes the popup. The focus goes back to the trigger. |
| Tab | popup | Closes the popup. The focus goes to the trigger, and then to the next element. |

The keys skip a disabled item (`disabled` or `aria-disabled="true"`). The focus goes to the items: the controller does not use `aria-activedescendant`.

The `input` part is the field of the form. An `<input>` gets the value, or the values separated by a comma with `multiple`. A `<select>` (for example `<select multiple name="apples[]" hidden>` with an `<option>` for each item) gets its options selected. When the user changes the value, the input sends its `input` and `change` events, and the controller dispatches `phem-select:change` with the `value` in its `detail` (an array with `multiple`). A focus on the input goes to the trigger. For `required`, use an input that is visually hidden (`class="sr-only"`), not `hidden`: the browser does not validate a field that it does not show.

The scroll arrows scroll the list one item each 40 ms while the pointer is on them. Each one shows only when the list can scroll in its direction, unless it has `data-keep-mounted`. The item with the focus does not go under a visible arrow: the list keeps the larger of its `scroll-padding-top` (or `scroll-padding-bottom`) and the height of the arrow free. The `list` part, or the popup with no list, is the element that scrolls: give it a `max-height`, for example `max-height: var(--available-height)`.

A popup over the trigger gets smaller in a short viewport, and its list must then scroll. The controller sets `--available-height` of the popup to the height of its content box (in its border and its padding), so a list with `max-height: var(--available-height)` and `overflow-y: auto` fits. You can also make the open popup a flex column, and let the list scroll. Do not put `display` in the `style` attribute of the popup: it shows the popup when it has `hidden`.

```css
[data-phem-select-target="popup"]:not([hidden]) {
    display: flex;
    flex-direction: column;
}

[data-phem-select-target="list"] {
    overflow-y: auto;
    min-height: 0;
}
```

A margin or a transform of the popup (for example `translate: 0 1px` over the trigger) does not move the text of the selected item from the text of the value: the controller measures the popup with them.

The parts get these attributes:

| Part | Attributes |
| :-- | :-- |
| trigger | `role="combobox"`, `aria-haspopup="listbox"`, `aria-expanded`, `aria-controls`, `data-popup-open` and `data-pressed` while the popup is open, `data-popup-side` (`bottom` when the popup is over the trigger), `data-placeholder`, `data-disabled`, `data-readonly`, `data-required` |
| value | `data-placeholder` |
| icon | `data-popup-open` |
| popup | `data-open`, `data-closed`, `data-starting-style`, `data-ending-style`, `data-side` (`none` over the trigger), `data-align`, and the CSS variables of a popover (`--anchor-width`, `--available-height`, `--transform-origin`...) |
| list (or popup) | `role="listbox"`, `aria-multiselectable` with `multiple` |
| item | `role="option"`, `aria-selected`, `data-selected`, `data-highlighted`, `data-disabled`, `tabindex="0"` on the highlighted item and `tabindex="-1"` on the others |
| itemIndicator | `hidden` when its item is not selected (unless `data-keep-mounted`), and the animation attributes |
| group, groupLabel | `role="group"` with the name of its label |
| separator | `role="separator"`, `data-orientation` |
| scrollUpArrow, scrollDownArrow | `data-direction` (`up` or `down`), `data-side`, `data-visible`, `position: absolute` |
| arrow | `data-side`, `data-align`, `data-uncentered` |
| backdrop | `data-open`, `data-closed`, and the animation attributes |

A popup over the trigger does not animate from the trigger in Base UI. Use `[data-side="none"]` to remove the scale animation there:

```css
[data-phem-select-target="popup"][data-starting-style]:not([data-side="none"]) {
    opacity: 0;
    transform: scale(0.98);
}
```

After a click on the trigger of a select with the value `honeycrisp`, the browser shows:

```html
<div data-controller="phem-select" data-phem-select-value-value="honeycrisp" data-phem-select-open-value="true">
    <button data-phem-select-target="trigger" aria-label="Apple" role="combobox" aria-haspopup="listbox" aria-expanded="true" aria-controls="list" data-popup-open="" data-pressed="" data-popup-side="bottom">
        <span data-phem-select-target="value" placeholder="Select apple">Honeycrisp</span>
        <span data-phem-select-target="icon" data-popup-open="">▾</span>
    </button>
    <input data-phem-select-target="input" name="apple" hidden="" tabindex="-1" aria-hidden="true">
    <div data-phem-select-target="popup" popover="manual" style="pointer-events: auto; position: fixed; inset: 142px auto auto 27px; --anchor-width: 160px; --anchor-height: 22px; --available-width: 1270px; --available-height: 710px; --transform-origin: 62px 70px;" tabindex="-1" data-open="" data-side="none" data-align="center">
        <div id="list" data-phem-select-target="list" role="listbox">
            <div data-phem-select-target="item" data-value="gala" role="option" tabindex="-1" aria-selected="false">
                <span data-phem-select-target="itemIndicator" data-closed="" hidden="">✓</span>
                <span data-phem-select-target="itemText">Gala</span>
            </div>
            <div data-phem-select-target="item" data-value="honeycrisp" role="option" tabindex="0" aria-selected="true" data-selected="" data-highlighted="">
                <span data-phem-select-target="itemIndicator" data-open="">✓</span>
                <span data-phem-select-target="itemText">Honeycrisp</span>
            </div>
            ...
        </div>
    </div>
</div>
```

After ArrowDown and Enter, the value is `granny`, the popup is closed, and the input has the new value:

```html
<div data-controller="phem-select" data-phem-select-value-value="granny" data-phem-select-open-value="false">
    <button data-phem-select-target="trigger" ... aria-expanded="false" aria-controls="list">
        <span data-phem-select-target="value" placeholder="Select apple">Granny Smith</span>
        ...
    </button>
    <input data-phem-select-target="input" name="apple" hidden="" tabindex="-1" aria-hidden="true">
    <div data-phem-select-target="popup" popover="manual" tabindex="-1" data-closed="" hidden="">...</div>
</div>
```

The `value` property of the input is `granny`.

## Mark the section in view

`phem-scrollspy` marks the link of the section in view, for a table of contents:

```html
<nav data-controller="phem-scrollspy" aria-label="On this page">
    <a href="#install" data-phem-scrollspy-target="link">Install</a>
    <a href="#configure" data-phem-scrollspy-target="link">Configure</a>
</nav>

<h2 id="install">Install</h2>
...
<h2 id="configure">Configure</h2>
...
```

The section of a link is the element of the page with the id of its hash. A section is current when its top is at its `scroll-margin-top` or above it, and the current link is the link of the last current section. A click on a link scrolls its section to this place, so a click always marks the clicked link. When the page has a sticky header, give the sections a `scroll-margin-top` (the header can then cover no heading after a click):

```css
h2, h3 {
    scroll-margin-top: 80px;
}
```

While "Configure" is the current section, the browser shows:

```html
<a href="#configure" data-phem-scrollspy-target="link" aria-current="true" data-active>Configure</a>
```

Above the first section, no link is current. At the end of the scroll, the last section is current, also when it is too short to get to the line. A section whose link the user clicked stays current at the end of the scroll while it is in view, until the user scrolls. When two links point to the same section, both get the marks. A change of the height of the content (an image that loads, a section that opens) marks the link again with no scroll. When the sections are in an element that scrolls (`overflow: auto` or `scroll`), the line starts at the top of this element. A link to another page, or to an id that the page does not have, gets no mark.

When the current section changes, the controller dispatches `phem-scrollspy:change`. Its `detail` has the `link` and the `section`, or `null` for the two above the first section. Use it, for example, to keep the current link in view in a long table of contents:

```html
<nav data-controller="phem-scrollspy" data-action="phem-scrollspy:change->toc#follow">...</nav>
```

The marks belong to the client, so they stay after the morph of an event.

## Switch between a light and a dark theme

`phem-theme` keeps the choice of the user between a light and a dark theme. The choice goes in `localStorage`, and `<html>` gets the theme. Put `theme_script()` in the `<head>` of the document. This inline script sets the theme before the first paint, so a dark page does not flash white on load:

```php
#[Component]
function Document(string $title, Html $children): Node
{
    return (
        <html lang="en">
            <head>
                <title>{{ title }}</title>
                {{ theme_script() }}
                {{ importmap('app') }}
            </head>
            <body><Root>{{ children }}</Root></body>
        </html>
    );
}
```

Then put the switch in the page. The `choose` action stores the `theme` param of the button: `light`, `dark` or `system`:

```html
<div data-controller="phem-theme" role="group" aria-label="Theme">
    <button data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="light">Light</button>
    <button data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="dark">Dark</button>
    <button data-phem-theme-target="option" data-action="phem-theme#choose" data-phem-theme-theme-param="system">System</button>
</div>
```

After a click on "Dark", the browser shows:

```html
<html lang="en" data-theme="dark" style="color-scheme: dark;">
...
<div data-controller="phem-theme" role="group" aria-label="Theme" data-theme="dark" data-preference="dark">
    <button ... data-phem-theme-theme-param="light" aria-pressed="false">Light</button>
    <button ... data-phem-theme-theme-param="dark" aria-pressed="true">Dark</button>
    <button ... data-phem-theme-theme-param="system" aria-pressed="false">System</button>
</div>
```

`data-theme` on `<html>` is always `light` or `dark`, so your CSS needs one rule for each theme:

```css
:root { --bg: white; --text: #111; }
:root[data-theme="dark"] { --bg: #111; --text: #eee; }
```

With Tailwind, make the `dark:` variant use the attribute:

```css
@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));
```

With `system`, or with no choice, the theme follows the `prefers-color-scheme` of the system, also when the system changes it. `color-scheme` gives the native controls and the scroll bars the same theme.

Other markup for the switch:

- **One button**: the `toggle` action stores the opposite of the current theme: `<button data-controller="phem-theme" data-action="phem-theme#toggle">Theme</button>`.
- **A `<select>` or radio buttons**: with no `theme` param, `choose` stores the value of the element. Make each `<option>` or radio button an `option` target, and the controller selects or checks the option of the choice:

```html
<select data-controller="phem-theme" data-action="phem-theme#choose" aria-label="Theme">
    <option data-phem-theme-target="option" value="system">System</option>
    <option data-phem-theme-target="option" value="light">Light</option>
    <option data-phem-theme-target="option" value="dark">Dark</option>
</select>
```

The controller element gets `data-theme` (the current theme) and `data-preference` (the choice), for example to show a sun or a moon icon. An `option` target of the choice gets `aria-pressed="true"` (a button), `checked` (a radio button) or `selected` (an `<option>`), and the other options lose it.

The theme with no choice is `system`. To use another default, give it to the script. The script gives it to `<html>` (`data-theme-default`), and the switches take it from there:

```twig
{{ theme_script(default: 'light') }}
```

When your Content Security Policy blocks inline scripts, give the nonce of the response: `{{ theme_script(nonce: csp_nonce('script')) }}` (NelmioSecurityBundle).

A choice changes the theme in the other tabs of the site, and in the other switches of the page. A choice that another tab removes (also with `localStorage.clear()`) gives the default theme again. When the theme or the choice changes, `<html>` dispatches one `phem-theme:change` event, also when the page has more than one switch. Its `detail` has the `theme` and the `preference`. Listen to it on the document:

```html
<div data-controller="chart" data-action="phem-theme:change@document->chart#redraw">...</div>
```

The marks belong to the client, so they stay after the morph of an event.

## Show toast messages

`phem-toast` shows short messages that disappear after a few seconds. Put one element in the layout:

```html
<div data-controller="phem-toast"></div>
```

It shows the toasts from handlers, failed events (`phem:error`) and rate limits (`phem:rate-limited`). A handler shows a toast with `$e->toast()`:

```php
use Phem\ToastType;

public function save(Event $e): void
{
    $this->orders->save($this->order);
    $e->toast('Saved', ToastType::Success);
}
```

The types are `ToastType::Info`, `Success`, `Warning` and `Error`. With no type (the default), the toast gets no `data-type`, as in Base UI. A third argument sets the duration of this toast in milliseconds. `0` keeps the toast until the user closes it:

```php
$e->toast('The order has no stock.', ToastType::Error, 0);
```

A component or a service that the handler calls injects `Phem\Toasts`. It has a method for each type:

```php
use Phem\Toasts;

public function __construct(
    private readonly Toasts $toasts,
) {
}

$this->toasts->error('The schedule starts before it ends.');
$this->toasts->success('Saved', 8000);
```

An empty message throws an `InvalidArgumentException`. So does a duration below 0 or above `Toasts::MAX_DURATION` (2147483647 ms, the longest timer of a browser).

A toast shows only with the response of an event. A handler that fails or redirects shows no toast. A toast of a page load doesn't show. The work of an [`#[Async]` handler](resilience.md#run-slow-work-after-the-response) has no response either: a toast there logs a warning, so show the result with `Streams`.

A toast is a `phem:toast` [browser event](events.md#send-events-to-your-javascript) with the detail `{"message": "Saved", "type": "success"}`.

Change the default duration and the texts with the controller values: `data-phem-toast-duration-value={{ 8000 }}`, `data-phem-toast-error-message-value`, `data-phem-toast-rate-limited-message-value` and `data-phem-toast-close-label-value` (the label of the close button, "Close" by default).

The `show` action shows the detail of another browser event as a toast. A [browser event](events.md#send-events-to-your-javascript) bubbles up to `window`, so listen there: `data-action="phem:saved@window->phem-toast#show"`.

Each toast has these parts: the root (`data-phem-toast`), the content (`[data-phem-toast-content]`), the title (`[data-phem-toast-title]`), the description (`[data-phem-toast-description]`, the message) and the close button (`[data-phem-toast-close]`). A toast with no title has no title part. The newest toast is first in the DOM, as in Base UI, so Tab goes to the newest toast first. `phem.css` shows the newest toast at the bottom (`flex-direction: column-reverse`). The root gets `data-type` (the type of the toast) and `--toast-index` (0 for the newest toast). A toast of a `phem:toast` event with no type, or with a type that is not `info`, `success`, `warning` or `error`, has no `data-type`. A toast gets `data-limited` and `inert` when more than the `limit` value (3) of newer toasts are on the screen: `phem.css` hides it (a toast with no `class`). The title, the description and the close button also get `data-type`. The close button gets `aria-hidden="true"` while the stack is not expanded and the button has no focus, as in Base UI. A new toast has the animations of the [conventions](#animations), and a toast that goes stays until the end of its `[data-ending-style]` transition. 

A toast with the same type, title and message as a toast on the screen does not show again: the toast on the screen starts its full time again. A toast with no type and a toast with the `info` type are not the same. This is different from Base UI, which shows a new toast for each `add` call unless the `id` is the same. So two `$e->toast('Saved')` in a short time show one "Saved" toast, not two. A toast with the same message as a toast that goes shows as a new toast.

The element is a landmark and a live region, as the viewport of Base UI: it gets `role="region"`, `aria-label` ("Notifications", or `data-phem-toast-label-value`), `tabindex="-1"`, `aria-live="polite"`, `aria-atomic="false"` and `aria-relevant="additions text"`. An attribute that your markup gives stays. Each toast is a dialog that can have the focus: it gets `role="dialog"`, `tabindex="0"`, `aria-modal="false"`, and `aria-labelledby` and `aria-describedby` for its title and its description:

```html
<div data-controller="phem-toast" role="region" aria-label="Notifications" tabindex="-1" aria-live="polite" aria-atomic="false" aria-relevant="additions text"
     style="--toast-frontmost-height: 42px;">
    <div id="phem-toast-2" data-phem-toast role="dialog" tabindex="0" aria-modal="false" aria-describedby="phem-toast-2-description"
         style="--toast-swipe-movement-x: 0px; --toast-swipe-movement-y: 0px; --toast-index: 0; --toast-height: 42px; --toast-offset-y: 0px;">
        <div data-phem-toast-content>
            <span data-phem-toast-description id="phem-toast-2-description">Copied</span>
        </div>
        <button type="button" data-phem-toast-close aria-label="Close" aria-hidden="true">×</button>
    </div>
    <div id="phem-toast-1" data-phem-toast data-type="success" role="dialog" tabindex="0" aria-modal="false" aria-describedby="phem-toast-1-description"
         style="--toast-swipe-movement-x: 0px; --toast-swipe-movement-y: 0px; --toast-index: 1; --toast-height: 62px; --toast-offset-y: 42px;">
        <div data-phem-toast-content data-behind>
            <span data-phem-toast-description data-type="success" id="phem-toast-1-description">Saved</span>
        </div>
        <button type="button" data-phem-toast-close aria-label="Close" data-type="success" aria-hidden="true">×</button>
    </div>
</div>
```

The first toast above is the newest: a `phem:toast` event of your JavaScript with no type, `window.dispatchEvent(new CustomEvent("phem:toast", { detail: "Copied" }))`.

| Key | Action |
|---|---|
| F6 | While there is a toast, moves the focus to the `phem-toast` element, and expands the stack |
| Tab | Moves the focus to the toasts, from the newest to the oldest, and to their buttons. After the last one, gives the focus back to the element that had it before F6 |
| Shift+Tab | On the `phem-toast` element, gives the focus back to the element that had it before F6 |
| Escape | Closes the toast with the focus. The focus goes to the next (older) toast, or else to the newer toast, or else back to the element that had it before F6. This is also true after a mouse click on the toast. After a mouse click on the close button, the focus does not move |

To use your own markup, put a `<template>` part in the element, with the same parts:

```html
<div data-controller="phem-toast">
    <template data-phem-toast-target="template">
        <div class="toast">
            <div class="toast-content" data-phem-toast-content>
                <strong data-phem-toast-title></strong>
                <p data-phem-toast-description></p>
            </div>
            <button data-phem-toast-close aria-label="Close">×</button>
        </div>
    </template>
</div>
```

The content and the title are optional parts of a template. The controller removes the title part from a toast with no title. A template with no title part does not show the title. The content part gets `data-behind` when a newer toast is in front of its toast, and `data-expanded` with the stack. Use them to hide the text of the toasts behind the last one in a stack: `.toast-content[data-behind]:not([data-expanded]) { opacity: 0; }`.

The default styles in `phem.css` use `:where()`, so a rule of your app replaces them. A part with a `class` attribute gets no default style: its classes give all the look. So Tailwind utilities work on the parts of a template, also in their cascade layer. Then hide the toasts with `data-limited` yourself, for example with `data-limited:hidden`, and move a toast with the swipe variables (see [Close a toast with a swipe](#close-a-toast-with-a-swipe)).

### Add a title to a toast

Give the `title` argument for a short title above the message:

```php
$e->toast('The file is in your downloads.', ToastType::Success, title: 'Export complete');
$this->toasts->error('The card was declined.', title: 'Payment failed');
```

The browser shows the title in the title part of the toast:

```html
<div data-phem-toast-content>
    <strong data-phem-toast-title>Export complete</strong>
    <span data-phem-toast-description>The file is in your downloads.</span>
</div>
```

The detail of the browser event has the title: `{"message": "The file is in your downloads.", "type": "success", "title": "Export complete"}`. An empty title throws an `InvalidArgumentException`. In the detail of an event of your own, a title that is empty or not a text is no title. A toast with the same type, title and message as a toast on the screen does not show again.

### Show the stack on hover

The pointer or the focus of the keyboard on the `phem-toast` element expands the stack (the focus of a click on a toast does not): the element and each toast get `data-expanded`, and the time of all the toasts stops. The time that remains goes on when the stack closes: the time does not start again. The time also stops while the browser window does not have the focus. The stack closes when the pointer and the focus go, and when its last toast goes. The state stays after the morph of an event. Each toast gets `--toast-height` (its height) and `--toast-offset-y` (the heights of the newer toasts), and the element gets `--toast-frontmost-height` (the height of the newest toast). The height of a toast is the height of its content (its height with `height: auto`), so the CSS can give each toast the height of the newest toast: `height: var(--toast-frontmost-height, var(--toast-height))`. With no toast, the element has no `--toast-frontmost-height`. Use them for a stack that shows only the newest toast until the pointer comes:

```css
[data-controller~="phem-toast"] { display: block; height: var(--toast-frontmost-height); }
[data-phem-toast] {
    position: absolute;
    inset: auto 0 0;
    transform: translateY(calc(var(--toast-index) * -10px)) scale(calc(1 - var(--toast-index) * 0.05));
    transition: transform 200ms;
}
[data-phem-toast][data-expanded] {
    transform: translateY(calc(-1 * var(--toast-offset-y) - var(--toast-index) * 8px));
}
```

### Close a toast with a swipe

A swipe of a toast to the right or down closes it. Set `data-phem-toast-swipe-direction-value` to other directions (`up`, `down`, `left` and `right`, with a space between them), or to an empty text to turn off the swipe. During the swipe, the toast gets `data-swiping`, `transition: none` in its `style` (so it follows the pointer with no delay), and the distance in `--toast-swipe-movement-x` and `--toast-swipe-movement-y`. A toast with no swipe has `0px` in the two variables, so a style can use them with no fallback, as the classes of Base UI do. A move in another direction has a resistance. When the value has a horizontal and a vertical direction, the swipe keeps the axis of its first move: a diagonal swipe moves on one axis only. The toast gets `data-swipe-direction` at its first move in a direction of the value. A swipe of 40 px closes the toast, and its `[data-ending-style]` transition can go on in that direction:

```css
[data-phem-toast][data-ending-style][data-swipe-direction="right"] {
    transform: translateX(calc(var(--toast-swipe-movement-x) + 100%));
    opacity: 0;
}
```

During a swipe to the right (a diagonal move of `+60, +50`):

```html
<div id="phem-toast-2" data-phem-toast data-swiping data-swipe-direction="right" …
     style="--toast-swipe-movement-x: 60px; --toast-swipe-movement-y: 0px; …; transition: none;">
```

A shorter swipe removes `data-swipe-direction`, gives the toast its place again, and the time that remained goes on. A press on the close button, a link or a field of the toast does not start a swipe. `phem.css` moves a toast with the swipe variables. A touch that moves in a direction of the swipe does not scroll the page, and a touch that moves in another direction scrolls it.

## Copy text to the clipboard

`phem-clipboard` copies text to the clipboard and shows brief feedback:

```html
<div data-controller="phem-clipboard">
    <code data-phem-clipboard-target="source">{{ token }}</code>
    <button data-action="phem-clipboard#copy">Copy</button>
    <span data-phem-clipboard-target="feedback"></span>
</div>
```

It copies the `text` value, or the text of the `source` target when there’s no value. The `feedback` targets show the `label` value (“Copied” by default) for the `duration` value (2000 ms by default), and the controller element gets `data-copied` for the same time. The controller dispatches `phem-clipboard:copied` (detail `text`), or `phem-clipboard:error` (detail `error`) when the browser does not copy. Browsers allow a page to copy only on HTTPS or `localhost`.

## Count the characters of a field

`phem-count` shows the number of characters of a text field while the user types:

```html
<div data-controller="phem-count">
    <textarea phem:bind={{ bio }} maxlength="160" aria-label="Bio" data-phem-count-target="field"></textarea>
    <span data-phem-count-target="output">{{ bio|length }}/160</span>
</div>
```

After the user types "Hello", the browser shows:

```html
<span data-phem-count-target="output" data-count="5" data-max="160">5/160</span>
```

The maximum is the `maxlength` of the field, or the `max` value (`data-phem-count-max-value={{ 140 }}`). The `max` value is a soft limit: the user can type more, and the controller element and the outputs get `data-over`. Each output shows the `data-phem-count-template` attribute, where `{count}`, `{max}` and `{remaining}` get their numbers. The default is `{count}/{max}`, or `{count}` with no maximum:

```html
<span data-phem-count-target="output" data-phem-count-template="{remaining} characters left"></span>
```

When the `field` targets are checkboxes, the count is the number of checked boxes, for a "3 selected" text. Give the output a server render of the count, for the time before the controller connects. The count belongs to the client, so it stays right after the morph of an event. The output has no `aria-live`: a screen reader that reads each key is noisy. Add `aria-live="polite"` to the output when your users need it.

## Select images with a preview

`phem-image-input` shows the images of a file input before the upload, and makes the large images smaller in the browser:

```html
<div data-controller="phem-image-input"
     data-phem-image-input-max-width-value={{ 1600 }}
     data-phem-image-input-preview-class="h-20 w-20 object-cover">
    <input type="file" name="photos[]" multiple accept="image/*" data-phem-image-input-target="input" />
    <div data-phem-image-input-target="preview"></div>
</div>
```

After the user selects `beach.jpg`, the preview has:

```html
<div data-phem-image-input-target="preview">
    <img src="blob:https://app.example/3f6c…" alt="beach.jpg" class="h-20 w-20 object-cover">
</div>
```

| Part | Attribute | Effect |
|---|---|---|
| Input | `data-phem-image-input-target="input"` | The file input |
| Preview | `data-phem-image-input-target="preview"` | Gets an `<img>` for each image. An `<img>` target shows the first image, and its own `src` with no file |
| Controller | `data-phem-image-input-preview-class` | The classes of each `<img>` of a preview |
| Controller | `data-phem-image-input-max-width-value`, `-max-height-value` | The maximum size in pixels of an image (`0`, the default, is no limit) |
| Controller | `data-phem-image-input-type-value` | The media type of the new images, such as `image/webp` |
| Controller | `data-phem-image-input-quality-value` | The quality of a JPEG or a WebP, from `0` to `1` (`0.85`) |

The controller element has `data-resizing` while the browser resizes, and a submit waits for the end. The previews belong to the client: the morph of an event keeps them, and they go when the handler calls `$e->clearFiles()`. The controller dispatches `phem-image-input:resized` with the new `files`. For the rules of the resize, see [Make the images smaller before the upload](forms.md#make-the-images-smaller-before-the-upload).

## Show the text of a password

`phem-reveal` shows and hides the text of a password field:

```html
<div data-controller="phem-reveal">
    <input type="password" name="password" aria-label="Password" data-phem-reveal-target="field" />
    <button type="button" data-phem-reveal-target="trigger">Show</button>
</div>
```

A click on a trigger shows the text (`type="text"`), and a second click hides it again. The `toggle`, `show` and `hide` actions do the same from another element. The field keeps its value. A click on the trigger gives the focus to the trigger. When the field has the focus (for example, a keyboard shortcut calls `toggle`), it keeps the focus and its selection. After a click, the browser shows:

```html
<div data-controller="phem-reveal" data-phem-reveal-revealed-value="true" data-revealed>
    <input type="text" name="password" ... data-revealed />
    <button type="button" data-phem-reveal-target="trigger" aria-pressed="true" data-revealed>Show</button>
</div>
```

A trigger gets `aria-controls` when the field has an `id`. Use `data-revealed` to change the label or the icon in CSS. The server can hide the text again with `data-phem-reveal-revealed-value={{ false }}`. A shown password stays a shown password after the morph of an event. In a Symfony form with live validation, the morph also keeps the value of a shown password, as it keeps the value of a hidden one.

## Select all the rows

`phem-checkbox-group` gives one state to a group of checkboxes, and adds a parent checkbox that checks and unchecks the items, for a "select all" box of a list or of a table:

```html
<table data-controller="phem-checkbox-group phem-count">
    <tr>
        <th><input type="checkbox" aria-label="All" data-phem-checkbox-group-target="parent" /></th>
        <th><span data-phem-count-target="output" data-phem-count-template="{count} selected"></span></th>
    </tr>
    {% for row in rows %}
        <tr key={{ row.id }}>
            <td><input type="checkbox" phem:bind={{ selected }} value={{ row.id }} aria-label={{ row.name }}
                       data-phem-checkbox-group-target="item" data-phem-count-target="field" /></td>
            <td>{{ row.name }}</td>
        </tr>
    {% endfor %}
</table>
```

An item and the parent are native checkboxes, or [`phem-toggle`](#toggle-a-button-a-switch-or-a-checkbox) checkboxes:

```html
<div data-controller="phem-checkbox-group" data-phem-checkbox-group-value-value='["pear"]'>
    <label>
        <span role="checkbox" data-controller="phem-toggle" data-phem-checkbox-group-target="parent"></span>
        All
    </label>
    <label>
        <span role="checkbox" data-controller="phem-toggle" data-value="apple" data-phem-checkbox-group-target="item">
            <input type="checkbox" name="fruits[]" value="apple" hidden data-phem-toggle-target="input">
        </span>
        Apple
    </label>
    ...
</div>
```

The value of an item is its `data-value` attribute, its `value` (the `value` attribute of a native checkbox, the `value` value of a `phem-toggle` element), its key (`key={{ ... }}`), or its position.

| Value | Default | Effect |
|---|---|---|
| `value` | | The values of the checked items. The controller writes it after each change. When the server render gives it, the group owns the state of the items: the value sets them on load and after the morph of an event, and a server render with a new value sets it |
| `all-values` | all the items | The values of the items that the parent checks and unchecks |

With no `value` in the server render, the state of the items belongs to the server render and to the user, for example with `phem:bind`.

A click on the parent checks all its items, or unchecks all of them when all are checked. When some items are checked, the parent remembers them: the next clicks go to all the items, to no item, and back to the items that were checked. A change of an item by the user is the new mixed state. A disabled item, and a read-only `phem-toggle` item, keeps its state. Each item that changes dispatches `input` and `change` (on a `phem-toggle` item, on its input), so `phem:bind`, `phem:live` and `phem-count` see the change. With `phem:live`, the changes of the fields of one `phem:bind` property go in one event.

The parent shows the state of its items: it is checked when all the items are checked, and indeterminate when some of them are checked. It gets `data-checked`, `data-unchecked` or `data-indeterminate`, and `aria-controls` with the items that have an `id`. A `phem-toggle` parent also gets `aria-checked="mixed"`. The parent shows the state again after the morph of an event.

A group can be an item of the group around it, for a tree of permissions. Give the element of the inner group a `data-value`:

```html
<div data-controller="phem-checkbox-group">
    <label><input type="checkbox" data-phem-checkbox-group-target="parent"> User permissions</label>
    <label><input type="checkbox" value="view-dashboard" data-phem-checkbox-group-target="item"> View dashboard</label>
    <div data-controller="phem-checkbox-group" data-value="manage-users">
        <label><input type="checkbox" data-phem-checkbox-group-target="parent"> Manage users</label>
        <label><input type="checkbox" value="create-user" data-phem-checkbox-group-target="item"> Create user</label>
        <label><input type="checkbox" value="edit-user" data-phem-checkbox-group-target="item"> Edit user</label>
    </div>
</div>
```

The inner group is checked when all its items are checked: its `data-value` is then in the `value` of the outer group. When some of its items are checked, the outer parent is indeterminate. The outer parent checks and unchecks all the items of the inner group.

Each change by the user dispatches `phem-checkbox-group:change` with `{ value }`. After a click on the native row 1 of three, the parent is:

```html
<input type="checkbox" aria-label="All" data-phem-checkbox-group-target="parent" data-indeterminate />
```

After a click on **Apple** in the group of `phem-toggle` checkboxes, the browser shows:

```html
<div data-controller="phem-checkbox-group" data-phem-checkbox-group-value-value="[&quot;apple&quot;,&quot;pear&quot;]">
    <label>
        <span role="checkbox" data-controller="phem-toggle" data-phem-checkbox-group-target="parent" data-phem-toggle-checked-value="false" data-phem-toggle-indeterminate-value="true" tabindex="0" aria-checked="mixed" data-indeterminate=""></span>
        All
    </label>
    <label>
        <span role="checkbox" data-controller="phem-toggle" data-value="apple" data-phem-checkbox-group-target="item" data-phem-toggle-checked-value="true" tabindex="0" aria-checked="true" data-phem-toggle-indeterminate-value="false" data-checked="">
            <input type="checkbox" name="fruits[]" value="apple" hidden="" data-phem-toggle-target="input" tabindex="-1" aria-hidden="true">
        </span>
        Apple
    </label>
    ...
    <label>
        <span role="checkbox" data-controller="phem-toggle" data-value="plum" data-phem-checkbox-group-target="item" data-phem-toggle-checked-value="false" tabindex="0" aria-checked="false" data-unchecked="">
            <input type="checkbox" name="fruits[]" value="plum" hidden="" data-phem-toggle-target="input" tabindex="-1" aria-hidden="true">
        </span>
        Plum
    </label>
</div>
```

## Show a time in the time zone of the user

The server has no session, so it does not know the time zone of the user. `phem-time` shows the `datetime` of a `<time>` element in the time zone and the language of the browser:

```html
<time datetime={{ comment.createdAt|date('c') }} data-controller="phem-time">{{ comment.createdAt|date('Y-m-d H:i') }} UTC</time>
```

The browser shows a relative time, and the full date in the `title`:

```html
<time datetime="2026-11-11T13:57:00+00:00" data-controller="phem-time" data-past title="November 11, 2026 at 2:57 PM">3 minutes ago</time>
```

| Value | Default | Effect |
|---|---|---|
| `format` | `relative` | `relative` ("3 minutes ago", "in 30 seconds"), `date`, `time` or `datetime` |
| `date-style`, `time-style` | | The style of `date`, `time` and `datetime`: `short`, `medium`, `long` or `full` |

The language is the `lang` attribute of the element or of the page. A Symfony locale such as `fr_FR` works as the tag `fr-FR`, and a language that the browser does not know gives the language of the browser. A relative time updates while the page shows. The element gets `data-past` or `data-future`, and a `title` when the server render gives none. When a future time comes, the element gets `data-past` and dispatches `phem-time:reached`, which bubbles to the controllers around it. Use `data-future` for a countdown:

```html
<p class="resend">
    <span>You can ask for a new code <time datetime={{ resendAt|date('c') }} data-controller="phem-time">soon</time>.</span>
    <button onClick={{ resend }}>Send a new code</button>
</p>
```

```css
.resend:has(time[data-future]) button,
.resend:not(:has(time[data-future])) span { display: none; }
```

The text before the controller connects is the server render, so give it a text that is correct in all time zones, such as the UTC time. The text belongs to the client: after the morph of an event, the controller shows it again, also for a new `datetime`.

## Focus a field after an event

`phem-autofocus` focuses its element when the element connects. The native `autofocus` attribute works only on page load, but this controller also works when an event adds the element:

```twig
{% if editing %}
    <input name="title" data-controller="phem-autofocus" />
{% endif %}
```

To focus a child, put the controller on a parent and add `data-phem-autofocus-target="focus"` to the child.

The element gets the focus on page load and in the morph of an event that the user started. A push or a refresh of the server doesn’t move the focus. It doesn’t steal the focus from a field the user is editing.

To scroll to a new element with no focus, see [Scroll to a new item after an event](events.md#scroll-to-a-new-item-after-an-event).

### Open the iOS keyboard after a round trip

iOS opens the keyboard only for a focus inside a tap. After a server round trip, such as a Turbo visit or the morph of an event, the field gets the focus but no keyboard opens. Add `data-phem-autofocus-keyboard` to the link or to the event element that shows the field:

```html
<a href="/search" data-phem-autofocus-keyboard>Search</a>

<button onClick={{ edit }} data-phem-autofocus-keyboard>Edit</button>
```

During the tap, a hidden field takes the focus and the keyboard opens. The keyboard stays open when the `phem-autofocus` field takes the focus, also inside an open `<dialog>`. These edge cases apply:

- **No field takes the focus**: the keyboard closes, and the element that had the focus before the tap gets it back
- **The visit is cancelled or fails**: when a listener cancels the visit or its fetch fails, the keyboard closes right away; a visit that takes more than 30s closes it too
- **Slow network**: the keyboard opens before the new page shows
- **The user is editing a field**: the attribute does nothing
- **Turbo Frames**: the link must start a Turbo Drive visit; a link that a `<turbo-frame>` loads doesn’t open the keyboard

## Ask for confirmation before an event

`phem-confirm` asks the user to confirm before an event goes to the server:

```html
<button onClick={{ delete }} data-controller="phem-confirm"
        data-phem-confirm-message-value="Delete this item?">Delete</button>
```

With no `message` value, the question is “Are you sure?”. When the user cancels, the browser sends no event.

### Ask in a dialog of the page

By default, `window.confirm()` asks the question. To ask it in a `<dialog>` of your page, give the selector of the dialog in the `dialog` value:

```html
<button onClick={{ clear }} data-controller="phem-confirm"
        data-phem-confirm-message-value="Empty the bag?"
        data-phem-confirm-accept-value="Empty bag"
        data-phem-confirm-dialog-value="#confirm">Empty bag</button>

<dialog id="confirm" aria-describedby="confirm-message">
    <form method="dialog">
        <p id="confirm-message" data-phem-confirm-message></p>
        <button value="cancel">Back</button>
        <button value="confirm" data-phem-confirm-accept>Yes</button>
    </form>
</dialog>
```

When the user clicks **Empty bag**, the controller does these steps:

1. It holds the event: no event goes to the server yet.
2. It puts the `message` value in the `[data-phem-confirm-message]` elements of the dialog, and the `accept` value in its `[data-phem-confirm-accept]` elements. With no `accept` value, the accept button keeps the label of the page.
3. It opens the dialog with `showModal()`.
4. When the dialog closes with the return value `confirm`, the held event goes, with the values of the click. Any other close, such as **Back**, Escape or a tap on the backdrop, sends no event.

A tap on the backdrop has the same rule as in [`phem-dialog`](#open-a-modal-dialog).

When the question ends, the dialog gets the texts of the page again. So one dialog can serve all the `phem-confirm` elements of the page, and the `data-phem-guard` elements too (see [Unsaved changes](forms.md#warn-about-unsaved-changes)): put it once in the layout. Give the dialog an accessible description, such as `aria-describedby` on the message, so a screen reader reads the question.

These edge cases apply:

- **A morph while the dialog asks**: the morph does not change the dialog, its texts or its `open` attribute
- **A morph or a removal of the element of the event**: the event drops and the dialog closes. After the morph, the handler can belong to another component, so the event could run on the wrong item. The user clicks again
- **A new question while the dialog asks**: the new question replaces the open question
- **A Turbo visit while the dialog asks**: the event drops when Turbo caches the page (`turbo:before-cache`). The dialog closes and gets the texts of the page again, so a visit back shows no old question
- **The dialog cannot ask**: when the selector is not valid, finds no `<dialog>`, or finds a dialog that is open with no question, the browser logs an error and `window.confirm()` asks

A field with a `phem-confirm` gets the value of the server render while the dialog is open. When the user confirms, the event sends the value that the user chose, and the server render shows it.
