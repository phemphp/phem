# Client-side behavior

Some changes need no server. This page shows how Stimulus controllers work with the morph of
an event, and the controllers that Phem gives you.

## Client code with Stimulus

Some changes need no server: open a menu, toggle a class, move the focus. Write them as
[Stimulus](https://stimulus.hotwired.dev/) controllers, with `data-controller` and `data-action`.

The morph of an event sets the attributes of the server render again. `data-phem-client` gives a
list of attributes to the client, and the morph does not change them:

```html
<button data-action="dropdown#toggle" data-phem-client="aria-expanded" aria-expanded="false">⋯</button>
```

`data-phem-ignore` keeps all the element: its attributes and its content. Use it for the element
of a JS library (a chart, a map, an editor):

```html
<div data-controller="chart" data-phem-ignore></div>
```

The element that sent an event gets these events, with the `handler` id in their detail:

| Event | When |
|---|---|
| `phem:before-queue` | Before the event goes in the queue. Call `preventDefault()` to stop it. |
| `phem:queue` | When the event goes in the queue. |
| `phem:send` | When the request starts. |
| `phem:receive` | When the server took the event, before the morph. |
| `phem:done` | When the event ends. `detail.ok` is `false` when it failed. For a redirect, `detail.redirect` is the URL, and the event comes before the visit. |

Each `phem:send` gets exactly one `phem:done`, also when the event fails, redirects, or loads the
page again. Use the two events to know when an event runs: the loading state shows only after the
loading delay, so a fast event does not show it.

## Optimistic UI

The `phem-optimistic` controller changes the page at once, before the server answers. When the
answer comes, the page shows the server render: the morph confirms the change, and a failed event
reverts it.

```html
<li key={{ id }} data-controller="phem-optimistic">
    {{ subject }}
    <button onClick={{ delete }} data-action="phem-optimistic#hide">Delete</button>
</li>
```

Put the action on the element that has the event handler. The actions are:

| Action | Change | Params |
|---|---|---|
| `hide`, `show` | the `hidden` attribute | |
| `addClass`, `removeClass`, `toggleClass` | a class | `class` |
| `setAttribute` | an attribute | `attribute`, `value` |
| `removeAttribute`, `toggleAttribute` | an attribute | `attribute` |
| `setText` | the text | `text` |
| `increment`, `decrement` | the first integer in the text | `by` |
| `insert` | a clone of a `<template>` | |

A param is an attribute of the element of the action:
`data-phem-optimistic-class-param="liked"`. With a `target` param, the action changes the
targets with that name in place of the controller element:

```html
<button onClick={{ like }} data-controller="phem-optimistic"
        data-action="phem-optimistic#toggleClass phem-optimistic#increment"
        data-phem-optimistic-class-param="liked" data-phem-optimistic-increment-target-param="count">
    ♥ <span data-phem-optimistic-target="count">{{ likes }} likes</span>
</button>
```

`insert` adds a copy of a `<template>` target. An element of the copy with
`data-phem-optimistic-field="body"` shows the value of the `body` field of the form.

## Built-in controllers

Phem has Stimulus controllers for common jobs. Their state belongs to the client, so it stays
after the morph of an event. They are not enabled by default. Enable each one that you use in
`assets/controllers.json`:

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

### Show and hide

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

The actions are `toggle`, `open` and `close`. Escape and a click outside close it. Set
`data-phem-toggle-open-value="true"` to open it at the start, and
`data-phem-toggle-dismiss-value="false"` to keep it open on Escape.

### Dialog

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

The actions are `open` and `close`. Escape and a click on the backdrop close it. The server can
open it with `data-phem-dialog-open-value={{ editing }}`.

### Tabs

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

The tab at a position shows the panel at the same position. The arrow keys, Home and End move
between the tabs. The controller sets the ARIA roles and attributes. Give an `id` to each tab and
panel, so it can link them. `data-phem-tabs-index-value="1"` selects a tab at the start.

### Toasts

`phem-toast` shows short messages that go away after some seconds. Put one element in the layout:

```html
<div data-controller="phem-toast"></div>
```

It shows the `phem:toast` events of the handlers, the failed events (`phem:error`), and the rate
limits (`phem:rate-limited`):

```php
$e->dispatcher->dispatch('toast', ['message' => 'Saved', 'type' => 'success']);
```

The types are `info`, `success`, `warning` and `error`. Change the duration and the texts with the
values of the controller: `data-phem-toast-duration-value={{ 8000 }}`,
`data-phem-toast-error-message-value`, `data-phem-toast-rate-limited-message-value`.

### Clipboard

`phem-clipboard` copies a text to the clipboard and shows a short feedback:

```html
<div data-controller="phem-clipboard" data-phem-clipboard-copied-class="copied">
    <code data-phem-clipboard-target="source">{{ token }}</code>
    <button data-action="phem-clipboard#copy">Copy</button>
    <span data-phem-clipboard-target="feedback"></span>
</div>
```

It copies the `text` value, or else the text of the `source` target. The `feedback` targets show
"Copied" for two seconds. The browser lets a page copy only on HTTPS or `localhost`.

### Autofocus

`phem-autofocus` gives the focus to its element when it connects. The native `autofocus` works
only when the page loads. This controller also works when an event adds the element:

```html
{% if editing %}
    <input name="title" data-controller="phem-autofocus" />
{% endif %}
```

It does not take the focus from a field that the user edits.

### Confirm

`phem-confirm` asks the user before an event goes to the server:

```html
<button onClick={{ delete }} data-controller="phem-confirm"
        data-phem-confirm-message-value="Delete this item?">Delete</button>
```

When the user cancels, no event goes.

## Transitions

Add `data-phem-transition` to an element to animate the changes of its components with the
[View Transitions API](https://developer.mozilla.org/docs/Web/API/View_Transition_API). On the
`<Root>` element, all the events of the page have a transition. The default is a cross-fade.

The value is the type of the transition. Use it in CSS:

```html
<ul data-phem-transition={{ 'slide' }}>
```

```css
html:active-view-transition-type(slide)::view-transition-old(root) { animation: slide-out .2s ease-in; }
html:active-view-transition-type(slide)::view-transition-new(root) { animation: slide-in .2s ease-out; }
```

A browser with no support, or with `prefers-reduced-motion: reduce`, shows no transition.
