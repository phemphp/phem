# Build UI with the built-in controllers

This guide shows you how to use the Stimulus controllers that Phem ships for common UI patterns: a section that opens, an accordion, a section that shows from the value of a field, a popover, a tooltip, a menu, a context menu, a combobox, a sortable list, a modal dialog, tabs, toasts, a copy button, a count of characters, a password that shows, a "select all" box, a local time, the focus after an event and a confirmation. Their state belongs to the client, so it stays after the morph of an event.

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
    "time": { "enabled": true, "fetch": "lazy" }
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

The controller adds the roles and the ARIA attributes of the pattern, such as `role="dialog"`, `aria-expanded` and `aria-controls`. An attribute that your markup gives stays. A part with no `id` gets one when an ARIA attribute must point to it.

### State in data attributes

The controller shows its state in data attributes. Use them in your CSS, or as Tailwind variants (`data-open:opacity-100`):

| Attribute | Part | When |
|---|---|---|
| `data-open`, `data-closed` | The root, the popup, the panel | The part is open, or closed |
| `data-popup-open` | A trigger | Its popup is open |
| `data-panel-open` | A trigger of `phem-collapsible` | Its panel is open |
| `data-side`, `data-align` | A positioned popup, its arrow | The side and the alignment after a flip: `top`, `bottom`, `left`, `right`, `inline-start`, `inline-end`, and `start`, `center`, `end` |
| `data-highlighted` | An item of a menu or of a combobox | The item has the focus, or the highlight |
| `data-selected` | An item of a combobox with `multiple` | The server chose the item (`aria-selected="true"`) |
| `data-expanded` | The `phem-toast` element, a toast | The pointer or the focus is on the stack |
| `data-swiping`, `data-swipe-direction` | A toast, a drawer | The user swipes the part, and the direction of the swipe that closed it |
| `data-disabled` | An item, a tab | The element is disabled |
| `data-orientation` | The parts of `phem-tabs` | `horizontal` or `vertical` |
| `data-active` | A tab | The tab is selected |
| `data-starting-style`, `data-ending-style` | A part that opens or closes | The first frame of the open, and the time of the close |
| `data-instant` | A popup | The change has no animation |

### Animations

A part that opens gets `data-starting-style` for its first frame. A part that closes gets `data-ending-style`, and gets `hidden` only when its animations end. Use a CSS transition between the two states:

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
```

A transition can go back in the middle: a popup that opens again before the end of its close animation does not jump. A keyframe animation on `[data-closed]` also works. A part with no animation closes at once.

### Position

`phem-popover`, `phem-tooltip`, `phem-menu`, `phem-context-menu` and `phem-combobox` place their popup next to their anchor (the trigger, or the input). The popup gets `position: fixed` and its `top` and `left`, so a `transform` of the popup stays free for an animation. These values set the position:

| Value | Default | Effect |
|---|---|---|
| `side` | `bottom` (`top` for a tooltip) | The side of the anchor: `top`, `bottom`, `left`, `right`, `inline-start` or `inline-end` |
| `align` | `center` (`start` for a menu and a combobox) | The alignment on the other axis: `start`, `center` or `end` |
| `side-offset` | `0` | The distance (px) from the anchor |
| `align-offset` | `0` | The shift (px) on the other axis |
| `collision-padding` | `5` | The space (px) to keep from the edges of the viewport |
| `arrow-padding` | `5` | The space (px) between the arrow and the corners of the popup |

When the popup does not fit on its side, it goes to the other side (a flip). On the other axis, it moves to stay in the viewport (a shift). It follows its anchor on a scroll and on a resize. The popup gets these CSS variables:

- `--anchor-width` and `--anchor-height`: the size of the anchor, for example for a list as wide as its input
- `--available-width` and `--available-height`: the space from the anchor to the edge of the viewport, for a `max-height`
- `--transform-origin`: the point of the anchor, for a scale animation from the anchor

The `arrow` part gets its `left` (on a top or bottom side) or its `top` (on a left or right side), so it points at the center of the anchor. Put it on the edge of the popup with `[data-side]`:

```css
.arrow[data-side="bottom"] { top: -8px; }
.arrow[data-side="top"] { bottom: -8px; rotate: 180deg; }
```

An element around the popup with `overflow: hidden`, a `transform` or a `filter` can cut or move a `position: fixed` popup. Add the `popover="manual"` attribute to the popup: the controller shows it in the top layer of the page, above all the other elements.

### The morph

The state of a controller belongs to the client. The morph after an event does not change the state attributes and the position. The `open` value (or the `index` value of `phem-tabs`) is the state: the server render gives its first value, and a server render with a new value sets the state. For example, a handler opens a dialog with `data-phem-dialog-open-value={{ editing }}`.

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

A click on a trigger opens and closes the panel. The `toggle`, `open` and `close` actions do the same from another element. After a click, the browser shows:

```html
<div data-controller="phem-collapsible" data-phem-collapsible-open-value="true" data-open>
    <button data-phem-collapsible-target="trigger" aria-controls="phem-collapsible-1-0" aria-expanded="true" data-panel-open>Details</button>
    <div data-phem-collapsible-target="panel" id="phem-collapsible-1-0" data-open
         style="--collapsible-panel-height: 48px; --collapsible-panel-width: 320px;">...</div>
</div>
```

The panel gets the size of its content in `--collapsible-panel-height` and `--collapsible-panel-width`, for a transition of its height:

```css
.panel { height: var(--collapsible-panel-height); overflow: hidden; transition: height 200ms; }
.panel[data-starting-style], .panel[data-ending-style] { height: 0; }
```

Set `data-phem-collapsible-open-value="true"` to open it on load. With `data-phem-collapsible-hidden-until-found-value="true"`, a closed panel has `hidden="until-found"`: the search of the browser (Ctrl+F) finds its text and opens it. A collapsible does not close on Escape or on a click outside. For a dropdown, use `phem-popover` or `phem-menu`.

## Open one item of an accordion at a time

`phem-accordion` shows a list of items that open and close. When one item opens, the other items close:

```html
<div data-controller="phem-accordion">
    <div data-value="shipping" data-phem-accordion-target="item">
        <h3><button id="shipping-trigger" data-phem-accordion-target="trigger">Shipping</button></h3>
        <div id="shipping-panel" data-phem-accordion-target="panel" hidden>Two days.</div>
    </div>
    <div data-value="returns" data-phem-accordion-target="item">
        <h3><button id="returns-trigger" data-phem-accordion-target="trigger">Returns</button></h3>
        <div id="returns-panel" data-phem-accordion-target="panel" hidden>Thirty days.</div>
    </div>
</div>
```

Each `item` has one `trigger` and one `panel`. A click on a trigger opens and closes its item. ArrowDown, ArrowUp, Home and End move the focus between the triggers. With `data-phem-accordion-orientation-value="horizontal"`, ArrowRight and ArrowLeft move it. From the last trigger, ArrowDown goes to the first one, unless `data-phem-accordion-loop-focus-value="false"`. A disabled trigger (`disabled` or `aria-disabled="true"`) does not open, and does not get the focus. After a click on **Returns**, the browser shows:

```html
<div data-value="returns" data-phem-accordion-target="item" data-open>
    <h3><button id="returns-trigger" data-phem-accordion-target="trigger" aria-expanded="true"
                aria-controls="returns-panel" data-panel-open>Returns</button></h3>
    <div id="returns-panel" data-phem-accordion-target="panel" role="region"
         aria-labelledby="returns-trigger" data-open
         style="--accordion-panel-height: 20px; --accordion-panel-width: 320px;">Thirty days.</div>
</div>
```

Give each trigger and each panel an `id`, for example `id={{ 'faq-' ~ key ~ '-panel' }}` in a loop. The controller links a trigger and its panel with `aria-controls` and `aria-labelledby` only when both have an id. It gives them no id itself: the morph of an event does not match an element whose id the server render does not have, and replaces it, with the state of its content.

Set `data-phem-accordion-multiple-value="true"` to let more than one item open. The `open` value is the list of the open items. An item is its `data-value` attribute, its `key`, or its index. The server opens an item with `data-phem-accordion-open-value={{ ['returns'] }}`. A panel gets `--accordion-panel-height` and `--accordion-panel-width`, for a transition of its height, as a panel of `phem-collapsible`. With `data-phem-accordion-hidden-until-found-value="true"`, the search of the browser (Ctrl+F) opens the item that has the text.

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
        <button onClick={{ copyLink }}>Copy the link</button>
        <button data-phem-popover-target="close">Close</button>
    </div>
</div>
```

A click on the trigger opens and closes the popup, and a click on a `close` part closes it. The popup is open after the morph of the **Copy the link** event, at its place. After a click, the browser shows:

```html
<button data-phem-popover-target="trigger" aria-haspopup="dialog" aria-expanded="true" data-popup-open>Share</button>
<div data-phem-popover-target="popup" popover="manual" role="dialog" aria-labelledby="share-title" tabindex="-1"
     data-open data-side="bottom" data-align="end"
     style="position: fixed; top: 61px; left: 240px; --anchor-width: 52px; --available-height: 654px; ...">
```

Open moves the focus into the popup: to its `[autofocus]` element, or to the popup itself. Escape closes the popup and gives the focus back to the trigger. A click outside and the focus out of the popover close it too. Set `data-phem-popover-dismiss-value="false"` to keep it open on Escape, on a click outside and on the focus out. Then Escape goes to the popup or the dialog around the popover, which closes.

With `data-phem-popover-open-on-hover-value="true"`, the pointer on the trigger opens the popup after the `delay` value (300 ms), and the pointer out of the trigger and of the popup closes it after the `close-delay` value (at least 100 ms, so the pointer can go to the popup). A popover with `open-on-hover` is good for a preview card, because a touch screen and the keyboard can still open it.

## Show a tooltip

`phem-tooltip` shows a short text next to an element, on hover and on the focus of the keyboard:

```html
<button onClick={{ archive }} data-controller="phem-tooltip" aria-label="Archive">
    <svg aria-hidden="true">...</svg>
    <span data-phem-tooltip-target="popup" hidden>Archive</span>
</button>
```

The trigger is the controller element, or a `trigger` part in it. The pointer on the trigger opens the tooltip after the `delay` value (600 ms), and the pointer out closes it after the `close-delay` value (0 ms). The pointer can go on the tooltip, unless `data-phem-tooltip-hoverable-value="false"`. The focus of the keyboard (`:focus-visible`) opens it at once. Escape, the blur and a click on the trigger close it. Set `data-phem-tooltip-close-on-click-value="false"` to keep it open on a click.

A tooltip that opens less than 400 ms after another tooltip closed opens at once, with `data-instant="delay"`, as in a toolbar. The popup gets `role="tooltip"` and an `id`, and the trigger gets `aria-describedby`:

```html
<button data-controller="phem-tooltip" aria-label="Archive" aria-describedby="phem-tooltip-1" data-popup-open>
    <span data-phem-tooltip-target="popup" role="tooltip" id="phem-tooltip-1" data-open data-side="top" data-align="center" style="...">Archive</span>
</button>
```

A tooltip helps only the users of a pointer and of a keyboard. A touch shows no tooltip. Give the trigger its own label (here `aria-label`) for a touch screen and for a screen reader. Do not put important information only in a tooltip: use a popover with `open-on-hover`.

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

The parts get these roles: the popup `menu`, an item `menuitem`, a group `group` (with `aria-labelledby` to its `groupLabel`), and a separator `separator`. An item can have the role `menuitemcheckbox` or `menuitemradio`, with `aria-checked` from the server: it gets `data-checked` when it is checked.

| Key | On | Action |
|---|---|---|
| Click, Enter, Space | The trigger | Opens or closes the menu. The keyboard puts the focus on the first item |
| ArrowDown, ArrowUp | The trigger | Opens the menu on the first item, or on the last item |
| ArrowDown, ArrowUp | An item | Moves the focus (ArrowRight and ArrowLeft with `data-phem-menu-orientation-value="horizontal"`) |
| Home, End | An item | Moves the focus to the first item, or to the last item |
| A letter | An item | Moves the focus to the next item that starts with the letter |
| Enter, Space | An item | Clicks the item |
| Escape | The menu | Closes the menu, and gives the focus back to the trigger |
| Tab | The menu | Closes the menu |

The keys skip a disabled item (`disabled` or `aria-disabled="true"`). From the last item, ArrowDown goes to the first item, unless `data-phem-menu-loop-focus-value="false"`. The pointer on an item gives it the focus, unless `data-phem-menu-highlight-item-on-hover-value="false"`. The item with the focus gets `data-highlighted`.

A click on an item runs its own handler, and closes the menu. The item with `data-phem-menu-close-on-click="false"` keeps the menu open, as **Pin** above: after its event, the morph shows the new `aria-checked` in the open menu. A click outside and the focus out of the menu close it. With `data-phem-menu-open-on-hover-value="true"`, the pointer on the trigger opens the menu after the `delay` value (100 ms).

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

The arrow keys of the menu go to **Move to** as to the other items. The pointer on it opens the submenu after the `delay` value (100 ms), and the focus stays on it. While the submenu is open, the pointer on another item of the menu gives it the focus after 150 ms, so the pointer can cross an item on its way to the submenu. A trigger with `aria-disabled="true"` does not open the submenu. Set `data-phem-menu-open-on-hover-value="false"` on the submenu to open it only with a click or a key. After ArrowRight on **Move to**, the browser shows:

```html
<button data-phem-menu-target="trigger" role="menuitem" tabindex="-1" aria-haspopup="menu"
        aria-controls="phem-menu-2" aria-expanded="true" data-popup-open>Move to</button>
<div data-phem-menu-target="popup" role="menu" id="phem-menu-2" tabindex="-1"
     data-open data-side="inline-end" data-align="start" style="...">
```

| Key | On | Action |
|---|---|---|
| Click, Enter, Space, ArrowRight | The trigger of the submenu | Opens the submenu. The keyboard puts the focus on its first item |
| ArrowLeft, Escape | The submenu | Closes the submenu, and gives the focus back to its trigger |

In a right-to-left page, ArrowLeft opens the submenu and ArrowRight closes it. The popup of a submenu goes on the `inline-end` side of its trigger. A click on an item of a submenu runs its handler and closes all the menus. When the menu closes, its submenus close too.

### Lock the page while the menu is open

Set `data-phem-menu-modal-value="true"` for a modal menu. While it is open, the page does not scroll, and the elements out of the menu get no hover and no click: the body gets `pointer-events: none`, and the popup `pointer-events: auto`. A click outside closes the menu, and the element under the pointer does not get the click. The width of the scroll bar stays, so the page does not move. A Turbo visit gives the page its styles back before Turbo keeps the page in its cache.

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
<div data-phem-context-menu-target="trigger" class="file" data-popup-open>report.pdf</div>
<div data-phem-context-menu-target="popup" role="menu" id="phem-menu-1" tabindex="-1"
     data-open data-side="bottom" data-align="start" style="position: fixed; top: 212px; left: 348px; ...">
```

The focus goes to the popup, so ArrowDown goes to the first item. When the popup opens under the pointer, the item under the pointer gets the focus. The parts, the keys, the submenus and the values are the ones of `phem-menu`, with `data-phem-context-menu-` in their names. A click on the trigger does not open the menu, and the trigger gets no ARIA attributes, because it is not a button. Escape gives the focus back to the element that had it before the menu opened. A menu that opens with no point, with the `open` action or with `data-phem-context-menu-open-value` from the server, goes below the trigger.

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

The popup opens when the user types, and on ArrowDown or ArrowUp. It shows when it has an item, an `empty` part or a `status` part. The focus stays in the input. When the user types "pa" and presses ArrowDown two times, the browser shows:

```html
<input data-phem-combobox-target="input" role="combobox" aria-autocomplete="list" autocomplete="off"
       aria-controls="phem-combobox-1" aria-expanded="true" aria-activedescendant="city-Parma" data-popup-open value="pa">
<div data-phem-combobox-target="popup" data-open data-side="bottom" data-align="start" style="...">
    <ul data-phem-combobox-target="list" role="listbox" id="phem-combobox-1">
        <li id="city-Paris" role="option" aria-selected="false">Paris</li>
        <li id="city-Parma" role="option" aria-selected="true" data-highlighted>Parma</li>
    </ul>
    <p data-phem-combobox-target="empty" hidden>No city.</p>
</div>
```

| Key | Action |
|---|---|
| ArrowDown, ArrowUp | Opens the popup, and moves the highlight |
| Enter | Chooses the highlighted item. With no highlight, Enter has its default action (the submit of the form) |
| Escape | Closes the popup. A second Escape clears the input |

To choose an item is a click on it: its handler runs (here `choose`), or its link opens. The handler sets the state, and the morph shows the new text in the input. Then the popup closes, and the controller dispatches `phem-combobox:select` with the `item` in the detail. A click on a `clear` part clears the input and sends its `input` event, as when the user deletes the text. A click on a `trigger` part opens or closes the popup. A click outside and the focus out of the combobox close the popup.

The highlight stays on the item with the same `id` through the morph of another event, so give each item a stable `id`. When the user types, the highlight goes. With `data-phem-combobox-auto-highlight-value="true"`, the first item gets the highlight. The popup and the list get `data-empty` when there is no item, and the `empty` parts show only then. A `status` part gets `role="status"`, so a screen reader reads its text, for example "3 cities". Use `var(--anchor-width)` to give the popup the width of the input.

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

The server gives the chosen items `aria-selected="true"`, and the controller does not change it: the highlight is `data-highlighted` and `aria-activedescendant`. A choice keeps the popup open and the highlight, so the user can choose more items. When the user chose **bug** and highlights **docs**, the browser shows:

```html
<span data-phem-combobox-target="chip" tabindex="-1">bug <button data-phem-combobox-target="chipRemove" tabindex="-1" aria-label="Remove bug">×</button></span>
<input data-phem-combobox-target="input" role="combobox" aria-activedescendant="tag-docs" aria-expanded="true" ...>
<ul data-phem-combobox-target="list" role="listbox" aria-multiselectable="true" id="phem-combobox-1">
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

The chips and their `chipRemove` parts get `tabindex="-1"`, so Tab goes from the input out of the combobox. A press on a `chipRemove` keeps the focus in the input.

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

A click on a trigger opens the dialog, and a click on a `close` part closes it. Add `phem:shortcut="mod+k"` to a trigger to open the dialog with a key (see [Add keyboard shortcuts](events.md#add-keyboard-shortcuts)). The `open` and `close` actions do the same from another element. The dialog gets `aria-labelledby` (the `title` part) and `aria-describedby` (a `description` part), and `data-open` or `data-closed`. A close waits for the animations of `[data-ending-style]`, then calls `close()`. The trigger gets `aria-haspopup="dialog"`, `aria-expanded` and `data-popup-open`.

Escape and a tap on the backdrop close the dialog. Set `data-phem-dialog-dismiss-value="false"` to keep it open on Escape and on a tap on the backdrop. The server can open it with `data-phem-dialog-open-value={{ editing }}`. A dialog that closes in another way, such as a `<form method="dialog">`, also sets the state.

A tap on the backdrop is a press and a click on the dialog itself, at a point outside the box of the dialog. A click on the padding of the dialog is inside the box, so the dialog stays open. A text selection that starts in the dialog and ends on the backdrop also keeps the dialog open. Phem does not use `closedby="any"`, because Safari does not support it.

A dialog in an open dialog gets `data-nested`. The dialog around it gets `data-nested-dialog-open` and the `--nested-dialogs` CSS variable (the number of open dialogs in it), for a stack of dialogs:

```css
dialog[data-nested-dialog-open] { scale: calc(1 - 0.05 * var(--nested-dialogs)); }
```

### Close a drawer with a swipe

Set the `swipe-direction` value (`up`, `down`, `left` or `right`) to make the dialog a drawer that closes with a swipe in that direction:

```html
<div data-controller="phem-dialog" data-phem-dialog-swipe-direction-value="down">
    <button data-phem-dialog-target="trigger">Filters</button>
    <dialog data-phem-dialog-target="popup" class="sheet">...</dialog>
</div>
```

A press on the dialog and a move of 5 px in the direction start a swipe. The dialog gets `data-swiping`, the distance in `--dialog-swipe-movement-x` or `--dialog-swipe-movement-y`, and the part of its size in `--dialog-swipe-progress` (from 0 to 1). A move in the other direction has a resistance. A release after half the size of the dialog, or after a fast swipe, closes it. A shorter swipe gives the dialog its place again. Use the variables in your CSS:

```css
.sheet {
    margin: auto 0 0;
    width: 100%;
    transform: translateY(var(--dialog-swipe-movement-y, 0px));
    transition: transform 300ms;
}
.sheet[data-swiping] { transition: none; }
.sheet[data-starting-style], .sheet[data-ending-style] { transform: translateY(100%); }
.sheet::backdrop { opacity: calc(1 - var(--dialog-swipe-progress, 0)); }
```

The dialog that a swipe closed gets `data-swipe-direction` until the end of its `[data-ending-style]` transition, so the transition goes on from its place. A press on a field, a button, a link or a label does not start a swipe. In content that scrolls (`overflow: auto` or `scroll`), the swipe starts only when the content is at its start: the list of a bottom sheet scrolls up first. A touch needs no `touch-action`: a touch that moves in the direction of the swipe swipes the dialog, and a touch that moves the other way scrolls the content of the dialog.

## Switch between tabs

`phem-tabs` shows the panel of the selected tab:

```html
<div data-controller="phem-tabs">
    <div data-phem-tabs-target="list" aria-label="Settings">
        <button id="tab-profile" data-phem-tabs-target="tab">Profile</button>
        <button id="tab-security" data-phem-tabs-target="tab">Security</button>
        <span data-phem-tabs-target="indicator"></span>
    </div>
    <div id="profile" data-phem-tabs-target="panel">...</div>
    <div id="security" data-phem-tabs-target="panel" hidden>...</div>
</div>
```

Each tab shows the panel at the same position. A click selects a tab. The arrow keys move the focus between the tabs, and Enter or Space selects the tab with the focus, also on a tab that is not a `<button>`. With `data-phem-tabs-activate-on-focus-value="true"`, the focus selects the tab at once. Home and End go to the first and to the last tab, and the arrow keys skip a disabled tab. From the last tab, the arrow key goes to the first tab, unless `data-phem-tabs-loop-focus-value="false"`. With `data-phem-tabs-orientation-value="vertical"`, the Up and Down keys move between the tabs in place of Left and Right.

The controller sets the ARIA roles and attributes, so give an `id` to each tab and panel to let it link them. Set `data-phem-tabs-index-value="1"` to select a tab on load. All the parts get `data-orientation` and `data-activation-direction` (the direction of the last change: `left`, `right`, `up`, `down` or `none`). The selected tab gets `data-active`, and a hidden panel gets `data-hidden`. A panel also gets `data-index`, and the animations of the [conventions](#animations).

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

The variables are `--active-tab-left`, `--active-tab-top`, `--active-tab-right`, `--active-tab-bottom`, `--active-tab-width` and `--active-tab-height`.

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

The types are `ToastType::Info` (the default), `Success`, `Warning` and `Error`. A third argument sets the duration of this toast in milliseconds. `0` keeps the toast until the user closes it:

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

Each toast has these parts: the root (`data-phem-toast`), the description (`[data-phem-toast-description]`, the message) and the close button (`[data-phem-toast-close]`). The root gets `data-type` (the type of the toast) and `--toast-index` (0 for the last toast). A toast gets `data-limited` when more than the `limit` value (3) of newer toasts are on the screen: `phem.css` hides it. A new toast has the animations of the [conventions](#animations), and a toast that goes stays until the end of its `[data-ending-style]` transition. A toast with the same message as a toast that goes shows as a new toast:

```html
<div data-controller="phem-toast" aria-live="polite">
    <div id="phem-toast-1" data-phem-toast data-type="success" style="--toast-index: 0;">
        <span data-phem-toast-description>Saved</span>
        <button type="button" data-phem-toast-close aria-label="Close">×</button>
    </div>
</div>
```

To use your own markup, put a `<template>` part in the element, with the same parts:

```html
<div data-controller="phem-toast">
    <template data-phem-toast-target="template">
        <div class="toast">
            <p data-phem-toast-description></p>
            <button data-phem-toast-close aria-label="Close">×</button>
        </div>
    </template>
</div>
```

The default styles in `phem.css` use `:where()`, so a rule of your app replaces them.

### Show the stack on hover

The pointer or the focus on the `phem-toast` element expands the stack: the element and each toast get `data-expanded`, and the time of all the toasts stops. The stack closes when the pointer and the focus go, and when its last toast goes. The state stays after the morph of an event. Each toast gets `--toast-height` (its height) and `--toast-offset-y` (the heights of the newer toasts), and the element gets `--toast-frontmost-height` (the height of the last toast). Use them for a stack that shows only the last toast until the pointer comes:

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

A swipe of a toast to the right or down closes it. Set `data-phem-toast-swipe-direction-value` to other directions (`up`, `down`, `left` and `right`, with a space between them), or to an empty text to turn off the swipe. During the swipe, the toast gets `data-swiping`, and the distance in `--toast-swipe-movement-x` and `--toast-swipe-movement-y`. A move in another direction has a resistance. A swipe of 40 px closes the toast, with `data-swipe-direction`, so its `[data-ending-style]` transition can go on in that direction:

```css
[data-phem-toast][data-ending-style][data-swipe-direction="right"] {
    transform: translateX(calc(var(--toast-swipe-movement-x) + 100%));
    opacity: 0;
}
```

A shorter swipe gives the toast its place again, and its time starts again. A press on the close button, a link or a field of the toast does not start a swipe. `phem.css` moves a toast with the swipe variables. A touch that moves in a direction of the swipe does not scroll the page, and a touch that moves in another direction scrolls it.

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

`phem-checkbox-group` adds a parent checkbox that checks and unchecks all the items, for a "select all" box of a list or of a table:

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

A click on the parent checks all the items, or unchecks all of them when all are checked. A disabled item keeps its state. Each item that changes dispatches `input` and `change`, so `phem:bind`, `phem:live` and `phem-count` see the change. With `phem:live`, the changes of the fields of one `phem:bind` property go in one event.

The parent shows the state of the items: it is checked when all the items are checked, and indeterminate when some of them are checked. It gets `data-checked`, `data-unchecked` or `data-indeterminate`, and `aria-controls` with the items that have an `id`. After a click on one row of three, the browser shows:

```html
<input type="checkbox" aria-label="All" data-phem-checkbox-group-target="parent" data-indeterminate />
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
