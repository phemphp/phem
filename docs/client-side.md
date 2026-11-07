# Add client-side behavior

This guide shows you how to handle changes that don’t need the server, such as opening a menu, toggling a class or moving the focus. It covers Stimulus controllers that keep their changes through the morph, the event lifecycle in the browser, optimistic updates, the built-in controllers and view transitions.

## Keep Stimulus changes through the morph

Write browser-only behavior as [Stimulus](https://stimulus.hotwired.dev/) controllers, with `data-controller` and `data-action`. The morph after an event resets each attribute to the value of the server render, so mark the attributes that your controller owns.

Add `data-phem-client` with a list of attributes that belong to the client. The morph leaves them unchanged:

```html
<button data-action="dropdown#toggle" data-phem-client="aria-expanded" aria-expanded="false">⋯</button>
```

Add `data-phem-ignore` to keep the whole element, with its attributes and its content. Use it for the element of a JavaScript library, such as a chart, a map or an editor:

```html
<div data-controller="chart" data-phem-ignore></div>
```

## Track an event from JavaScript

The element that sent an event receives these browser events, with the `handler` ID in their detail:

| Event | When |
|---|---|
| `phem:before-queue` | Before the event joins the queue; call `preventDefault()` to cancel it |
| `phem:queue` | When the event joins the queue |
| `phem:send` | When the request starts |
| `phem:receive` | When the server accepted the event, before the morph |
| `phem:done` | When the event ends; `detail.ok` is `false` if it failed, and for a redirect, `detail.redirect` is the URL and the event comes before the visit |

Each `phem:send` gets exactly one `phem:done`, even when the event fails, redirects or reloads the page. Use the pair to know when an event runs. The loading state shows only after the loading delay, so a fast event never shows it.

### Wait for pushes in browser tests

A push (see [Push updates to other browsers](real-time-updates.md)) has no sending element, so it dispatches none of these events. Instead, the root element has the `data-phem-push-pending` attribute until the page finishes its pushes. A push stays pending while it waits for the running event, while Turbo renders it, or while its refresh waits 50 ms (to group the refreshes that arrive together) or runs.

A test with a real browser waits until the attribute disappears (see [Tests with a real browser](testing.md#test-with-a-real-browser)).

## Update the page before the server answers

The `phem-optimistic` controller changes the page right away, before the server answers. When the answer arrives, the page shows the server render: the morph confirms the change, and a failed event reverts it.

```html
<li key={{ id }} data-controller="phem-optimistic">
    {{ subject }}
    <button onClick={{ delete }} data-action="phem-optimistic#hide">Delete</button>
</li>
```

Put the action on the element that has the event handler. The controller has these actions:

| Action | Change | Params |
|---|---|---|
| `hide`, `show` | The `hidden` attribute | |
| `addClass`, `removeClass`, `toggleClass` | A class | `class` |
| `setAttribute` | An attribute | `attribute`, `value` |
| `removeAttribute`, `toggleAttribute` | An attribute | `attribute` |
| `setText` | The text | `text` |
| `increment`, `decrement` | The first integer in the text | `by` |
| `insert` | A clone of a `<template>` | |

A param is an attribute on the element of the action, such as `data-phem-optimistic-class-param="liked"`. With a `target` param, the action changes the targets with that name instead of the controller element:

```html
<button onClick={{ like }} data-controller="phem-optimistic"
        data-action="phem-optimistic#toggleClass phem-optimistic#increment"
        data-phem-optimistic-class-param="liked" data-phem-optimistic-increment-target-param="count">
    ♥ <span data-phem-optimistic-target="count">{{ likes }} likes</span>
</button>
```

`insert` adds a copy of a `<template>` target. In the copy, an element with `data-phem-optimistic-field="body"` shows the value of the form’s `body` field.

## Enable the built-in controllers

Phem ships Stimulus controllers for common UI patterns. Their state belongs to the client, so it survives the morph after an event. They’re off by default, so enable each one you use in `assets/controllers.json`:

```json
"@phem/phem": {
    "optimistic": { "enabled": true, "fetch": "lazy" },
    "toggle": { "enabled": true, "fetch": "lazy" },
    "dialog": { "enabled": true, "fetch": "lazy" },
    "tabs": { "enabled": true, "fetch": "lazy" },
    "toast": { "enabled": true, "fetch": "lazy" },
    "clipboard": { "enabled": true, "fetch": "lazy" },
    "autofocus": { "enabled": true, "fetch": "lazy" },
    "confirm": { "enabled": true, "fetch": "lazy" }
}
```

### Show and hide a menu

`phem-toggle` shows and hides a dropdown, a menu or a section:

```html
<span data-controller="phem-toggle">
    <button data-action="phem-toggle#toggle" data-phem-toggle-target="button" aria-controls="actions">⋯</button>
    <span id="actions" data-phem-toggle-target="content" hidden>
        <button onClick={{ star }}>Star</button>
        <button onClick={{ archive }} data-action="phem-toggle#close">Archive</button>
    </span>
</span>
```

The actions are `toggle`, `open` and `close`. Escape and a click outside close the content. Set `data-phem-toggle-open-value="true"` to open it on load, and `data-phem-toggle-dismiss-value="false"` to keep it open on Escape.

### Open a modal dialog

`phem-dialog` opens a native `<dialog>` as a modal:

```html
<div data-controller="phem-dialog">
    <button data-action="phem-dialog#open">Edit</button>
    <dialog data-phem-dialog-target="dialog" aria-labelledby="edit-title">
        <h2 id="edit-title">Edit the title</h2>
        <input phem:bind={{ title }} />
        <button onClick={{ save }} data-action="phem-dialog#close">Save</button>
        <button data-action="phem-dialog#close">Cancel</button>
    </dialog>
</div>
```

The actions are `open` and `close`. Escape and a click on the backdrop close the dialog. The server can open it with `data-phem-dialog-open-value={{ editing }}`.

### Switch between tabs

`phem-tabs` shows the panel of the selected tab:

```html
<div data-controller="phem-tabs">
    <div data-phem-tabs-target="list" aria-label="Settings">
        <button id="tab-profile" data-phem-tabs-target="tab">Profile</button>
        <button id="tab-security" data-phem-tabs-target="tab">Security</button>
    </div>
    <div id="profile" data-phem-tabs-target="panel">...</div>
    <div id="security" data-phem-tabs-target="panel" hidden>...</div>
</div>
```

Each tab shows the panel at the same position. The arrow keys, Home and End move between tabs. The controller sets the Accessible Rich Internet Applications (ARIA) roles and attributes, so give an `id` to each tab and panel to let it link them. Set `data-phem-tabs-index-value="1"` to select a tab on load.

### Show toast messages

`phem-toast` shows short messages that disappear after a few seconds. Put one element in the layout:

```html
<div data-controller="phem-toast"></div>
```

It shows the `phem:toast` events from handlers, failed events (`phem:error`) and rate limits (`phem:rate-limited`). A handler dispatches a toast like this:

```php
$e->dispatcher->dispatch('toast', ['message' => 'Saved', 'type' => 'success']);
```

The types are `info`, `success`, `warning` and `error`. Change the duration and the texts with the controller values: `data-phem-toast-duration-value={{ 8000 }}`, `data-phem-toast-error-message-value` and `data-phem-toast-rate-limited-message-value`.

### Copy text to the clipboard

`phem-clipboard` copies text to the clipboard and shows brief feedback:

```html
<div data-controller="phem-clipboard" data-phem-clipboard-copied-class="copied">
    <code data-phem-clipboard-target="source">{{ token }}</code>
    <button data-action="phem-clipboard#copy">Copy</button>
    <span data-phem-clipboard-target="feedback"></span>
</div>
```

It copies the `text` value, or the text of the `source` target when there’s no value. The `feedback` targets show “Copied” for 2s. Browsers allow a page to copy only on HTTPS or `localhost`.

### Focus a field after an event

`phem-autofocus` focuses its element when the element connects. The native `autofocus` attribute works only on page load, but this controller also works when an event adds the element:

```twig
{% if editing %}
    <input name="title" data-controller="phem-autofocus" />
{% endif %}
```

It doesn’t steal the focus from a field the user is editing.

#### Open the iOS keyboard after a round trip

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

### Ask for confirmation before an event

`phem-confirm` asks the user to confirm before an event goes to the server:

```html
<button onClick={{ delete }} data-controller="phem-confirm"
        data-phem-confirm-message-value="Delete this item?">Delete</button>
```

When the user cancels, the browser sends no event.

## Animate changes with view transitions

Add `data-phem-transition` to an element to animate the changes of its components with the [View Transitions API](https://developer.mozilla.org/docs/Web/API/View_Transition_API). On the `<Root>` element, every event on the page gets a transition. The default is a cross-fade.

The attribute value is the transition type, which you target in CSS:

```html
<ul data-phem-transition={{ 'slide' }}>
```

```css
html:active-view-transition-type(slide)::view-transition-old(root) { animation: slide-out .2s ease-in; }
html:active-view-transition-type(slide)::view-transition-new(root) { animation: slide-in .2s ease-out; }
```

A browser without support, or with `prefers-reduced-motion: reduce`, shows no transition.
