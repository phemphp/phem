# Add client-side behavior

This guide shows you how to handle changes that don’t need the server, such as opening a menu, toggling a class or moving the focus. It covers Stimulus controllers that keep their changes through the morph, HTML and CSS that need no controller, the event lifecycle in the browser, optimistic updates and view transitions. For the built-in controllers, see [Build UI with the built-in controllers](controllers.md).

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
| `phem:before-queue` | Before the event joins the queue; call `preventDefault()` to cancel it, and `detail.resume()` to send the cancelled event later (see [Hold an event](#hold-an-event-and-send-it-later)) |
| `phem:queue` | When the event joins the queue |
| `phem:send` | When the request starts |
| `phem:receive` | When the server accepted the event, before the morph |
| `phem:done` | When the event ends; `detail.ok` is `false` if it failed, and for a redirect, `detail.redirect` is the URL and the event comes before the visit |

Each `phem:send` gets exactly one `phem:done`, even when the event fails, redirects or reloads the page. Use the pair to know when an event runs. The loading state shows only after the loading delay, so a fast event never shows it.

After the morph, a bound field that still has a value that the server did not take gets the server value again. Each such field dispatches `phem:reset`, which bubbles. A controller that shows the value of a field (a count, a panel) listens to it and renders again; `ClientState` does it for the built-in controllers.

A push (see [Push updates to other browsers](real-time.md)) has no sending element, so it dispatches none of these events. Instead, the root element has the `data-phem-push-pending` attribute until the page finishes its pushes. A browser test waits for it (see [Test with a real browser](testing.md#test-with-a-real-browser)).

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
| `addClass`, `removeClass`, `toggleClass` | A class | `class`, or the `class` value of the controller (`data-phem-optimistic-class-value`) |
| `setAttribute` | An attribute | `attribute`, `value` |
| `removeAttribute`, `toggleAttribute` | An attribute | `attribute` |
| `setText` | The text | `text` |
| `increment`, `decrement` | The first integer in the text | `by` |
| `insert` | A clone of a `<template>` | `target` (required) |

A param is an attribute on the element of the action, such as `data-phem-optimistic-class-param="liked"`. With a `target` param, the action changes the targets with that name instead of the controller element:

```html
<button onClick={{ like }} data-controller="phem-optimistic"
        data-action="phem-optimistic#toggleClass phem-optimistic#increment"
        data-phem-optimistic-class-param="liked" data-phem-optimistic-increment-target-param="count">
    ♥ <span data-phem-optimistic-target="count">{{ likes }} likes</span>
</button>
```

`insert` adds a copy of a `<template>` target, directly before the template. It needs a `target` param that names the template. In the copy, an element with `data-phem-optimistic-field="body"` shows the value of the form’s `body` field, or its values joined with ", ".

## Use the built-in controllers

Phem ships Stimulus controllers for common UI patterns: a section that opens, an accordion, a section that shows from the value of a field, a popover, a tooltip, a menu, a combobox, a sortable list, a modal dialog, tabs, toasts, a copy button, a count of characters, a password that shows, a "select all" box, a local time, the focus after an event and a confirmation. Their state survives the morph after an event. See [Build UI with the built-in controllers](controllers.md).

## Use HTML and CSS before a controller

Some changes need no JavaScript. CSS reads the state of the browser (the text, the focus, the checked boxes), so the morph does not change it:

| Need | Use |
|---|---|
| A textarea that grows with its text | `textarea { field-sizing: content; }`. Firefox does not support it yet: the textarea keeps its size there |
| The style of a field with an error, after the user leaves it | `input:user-invalid { border-color: red; }`, with `required`, `pattern`, `min` or `maxlength` on the field |
| A disabled look on the submit button of a form with an invalid field | `form:has(:invalid) button[type=submit] { opacity: .5; }`. The server must still validate the form |
| A section that opens, with no state on the server | `<details data-phem-client="open">` and `<summary>`: with no `data-phem-client`, the morph closes it. Use `phem-collapsible` for an animation of its height |
| A style that depends on a checked box | `.row:has(input:checked) { background: #eef; }` |

## Hold an event and send it later

To build your own confirmation, listen to `phem:before-queue`, call `preventDefault()`, and call `event.detail.resume()` after the user agrees:

```js
element.addEventListener("phem:before-queue", (event) => {
    event.preventDefault()
    askTheUser().then((yes) => yes && event.detail.resume())
    event.detail.signal.addEventListener("abort", closeTheQuestion)
})
```

`resume()` sends the held event one time, with the values of the DOM event and with no new `phem:before-queue`. Phem reads the values after all the listeners of `phem:before-queue`, so a listener can change a field before the event goes.

A morph or a removal of the element drops the held event, and `event.detail.signal` aborts. A Turbo visit drops all the held events when Turbo caches the page (`turbo:before-cache`), before Turbo copies the page. Close your question on `abort`, so the cached page does not show it. Then `resume()` does nothing. It also does nothing when the event was not cancelled or when the page has no events. A call of `resume()` in the listener itself undoes the cancel: the event goes at once, as if no listener cancelled it.

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
