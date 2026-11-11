# Troubleshoot failed events and limits

This page helps you fix an event that fails, reloads the page, or runs slowly. Each section starts with the symptom you see, explains the cause, and shows the fix. The last section lists the default limits and the options that change them. For the page the browser shows when a request fails with a 404 or a 500, see [Error pages](error-pages.md).

## An event fails and the page doesn’t change

When the server answers an event with an error status, such as a `500` or a `403`, the browser keeps the page as it was. It dispatches a `phem:error` event on the component of the handler, with `handler` and `status` in its detail. After a network error, `status` is `null`. If no listener cancels the event, the browser shows a default message at the bottom of the page: “Something went wrong. Please try again.”

The server log and the browser console show why the handler failed. The console logs each failure as `[phem] event failed`, with the status and the response body.

### Change the default error message

The default message is a `div` with the `phem-error` class and `role="alert"`. A click on it closes it. To change its text, set `data-phem-error-message-value` on the `<Root>` element of your document:

```html
<Root data-phem-error-message-value={{ 'The change was not saved.' }}>
```

### Show your own error message

To replace the default message, listen to `phem:error` and call `event.preventDefault()`. The event bubbles, so one listener on the document handles the errors of all components:

```js
document.addEventListener('phem:error', (event) => {
    if (event.detail.status === 403) {
        event.preventDefault()
        alert('You can’t change this item.')
    }
})
```

The built-in [toasts](controllers.md#show-toast-messages) also listen to `phem:error`, and show the error as a toast in place of the default message.

## An event fails on an unstable network

A network error, a `502` or a `503` can be temporary, so the browser sends the event again before it reports a failure. It tries up to 3 more times and doubles the wait each time: 500 ms, 1s, then 2s. When the response has a `Retry-After` header, the browser waits at least that long. While the browser is offline, it waits until the connection comes back. When the last try fails, the browser dispatches `phem:error`.

To change the number of tries or the first wait, set these values on the `<Root>` element:

```html
<Root data-phem-retries-value={{ 5 }} data-phem-retry-delay-value={{ 1000 }}>
```

With these values, the browser tries 5 more times and waits 1s, 2s, 4s, 8s, then 16s.

### A handler runs two times

A network error can come after the handler committed its changes, for example when the connection drops during the response. The browser can’t tell this apart from a request that never arrived, so it sends the event again and the handler runs a second time. Make the handler safe to run again: check whether the change is already done before you make it. If you can’t, turn off the retries for the page:

```html
<Root data-phem-retries-value={{ 0 }}>
```

## The page reloads after an event

When the server can’t use the state that the browser sent, it answers `409` and the browser loads the page again. The browser doesn’t dispatch `phem:error` for a `409`, because the reload is the fix. The server answers `409` in these cases:

- **New build or new secret**: you deployed a new build, or changed `phem.secret`, so the signature of the state isn’t valid any more
- **State too old**: the state is older than `phem.state_max_age` (`86400` seconds, one day, by default)
- **Other session**: with `phem.state_bind_session: true`, the session changed since the page loaded, for example after a login
- **Other URL**: the state belongs to another URL of the same page, such as `/items/3` and not `/items/4`
- **Missing entity**: an entity of the page’s route was deleted, so the reload shows the 404 page
- **Changed page**: the page’s component tree changed since the browser rendered it, for example after a deploy
- **Missing handler**: the handler isn’t in the page any more, for example a row that another user removed, or a feed item that is gone or has a signature that isn’t valid

A reload drops the values that the user typed and didn’t save. If users leave pages open for longer than a day, raise `phem.state_max_age`, or set it to `0` for no limit:

```yaml
# config/packages/phem.yaml
phem:
    state_max_age: 604800
```

### A real-time refresh shows an error, not a reload

A refresh comes from a push, not from a click, so the browser protects the user’s unsaved work. When the render of a refresh finds a page that this user can’t see now (a `401`, `403`, `404` or `410`), the server answers `409`. If a `data-phem-guard` form has changes that the user didn’t save, the browser dispatches `phem:error` with the status `409` and doesn’t reload. The user can copy the changes before they reload. See [Real-time updates](real-time.md).

## An event fails with a 413

The server answers `413 Payload Too Large` when the event body is larger than `phem.max_event_size`. The default is 1 MB (`1048576` bytes), and `0` turns the limit off. The body is JSON that includes the state of the page, so a large state is the usual cause. The browser compresses a body of 1 KB or more with gzip, but the server checks the size after it decompresses the body.

### The state of the page is too large

Every event must be able to send the full state of the page, because the [state cache](#an-event-makes-two-requests) can lose it. When the state can’t fit in an event body, the page throws a `LogicException` in debug mode, and logs an error in production. The message names the page, the size of the state, and its largest entry.

To fix it, keep less in `#[State]`: store an id, and load the rows in `render()`. If the state must stay large, raise the limit:

```yaml
# config/packages/phem.yaml
phem:
    max_event_size: 2097152 # 2 MB
```

### The files of an event are too large

The files of one event have their own limit: `phem.max_upload_size`, 8 MB (`8388608` bytes) by default. The `post_max_size` and `upload_max_filesize` options of PHP also apply. See [Limit the upload size](forms.md#limit-the-upload-size).

## An event makes two requests

You see a `409` response and then the same event again in the network tab. This is a miss of the state cache, and it’s expected. For a state of `phem.state_cache_min_size` bytes or more (`4096`, 4 KB, by default), the server keeps the state in the `cache.phem.state` pool, and an event sends only the signature. When the pool doesn’t have the state any more, the server answers `409` with `{"state": "required"}` before the handler runs, and the browser sends the event again with the full state. A miss costs one more request, and the handler runs one time.

The pool loses a state when it removes old entries, when the server restarts, or when the event reaches another server. To get more hits, use a pool that removes old entries, such as Redis or APCu. With more than one server, use a pool that all servers share:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.phem.state:
                adapter: cache.adapter.redis
                default_lifetime: 1800
```

To turn the cache off and send the full state with each event, set `phem.state_cache: false`. An event with files always sends the full state.

## An event is dropped with a 429

A `#[RateLimit]` on the page or on the handler method dropped the event: the handler didn’t run and the page doesn’t change. The response has a `Retry-After` header. The browser doesn’t retry, and dispatches `phem:rate-limited` with `retryAfter` (in seconds) in its detail, not `phem:error`. See [Rate limits](security.md#rate-limit-events) to change the limit.

## An event fails with a 400, 403, 405 or 415

The browser client of Phem doesn’t send these requests. Look for a proxy, a script or a test that sends the event with another body or other headers:

| Status | Cause |
| --- | --- |
| `400` | The body isn’t valid: it isn’t JSON, is deeper than 8 levels, has no handler, is a gzip body that isn’t valid, or is an event with files and no state. It also has more than 100 stale components, 100 boundaries in one load or 100 feed items, or a feed item longer than 1024 bytes |
| `403` | The event comes from another origin |
| `405` | A `POST` request of the page has no `X-Phem-Event` header, and the route of the page doesn’t accept `POST` |
| `415` | The `Content-Type` isn’t JSON or `multipart/form-data`, or the body has a `Content-Encoding` other than `gzip`. A body with files can’t use `gzip` |

## Events feel slow

Each event is a round trip to the server: about 1 ms of server time, plus the network time. When an interaction must respond with no wait, such as a menu that opens or a counter in a text field, put the logic in a Stimulus controller (see [Client-side behavior](client-side.md)). Phem has no `useEffect`, because the server keeps no session between events.

### A click in a long list is slow

An event runs `render()` again for each component on the path to its handler, because these components create the handler and its props. A click on one row of a list runs the `render()` of the list, and Twig goes through all its rows. The browser keeps the HTML of the rows that didn’t change. On a list of 5000 rows, a click on one row takes about 46 ms on the server, and a load of the full page about 130 ms. Show a long list in pages.

### A deep component tree is slow

The HTML of a component includes the HTML of all the components under it, and each level reads that HTML again. So the render time grows faster than the depth of the tree: 200 nested components take about 5 ms, but 800 take about 45 ms. A real page has fewer than 50 levels, where the cost is less than 1 ms.

## Default limits and where to change them

These are the defaults for events and state. Set the `phem.*` options in `config/packages/phem.yaml`, and the `data-phem-*` values on the `<Root>` element:

| Limit | Default | Change it with |
| --- | --- | --- |
| Event body size, with the state | 1 MB (`1048576` bytes) | `phem.max_event_size` (`0` for no limit) |
| Files of one event, together | 8 MB (`8388608` bytes) | `phem.max_upload_size` (`0` for no limit) |
| Smallest state kept in the state cache | 4 KB (`4096` bytes) | `phem.state_cache_min_size` |
| State cache | On, in the `cache.phem.state` pool | `phem.state_cache` |
| Age of a state | `86400` seconds (one day) | `phem.state_max_age` (`0` for no limit) |
| Smallest event body that the browser compresses | 1 KB | Not configurable |
| Retries after a network error, `502` or `503` | 3 | `data-phem-retries-value` |
| First wait before a retry, doubled each time | 500 ms | `data-phem-retry-delay-value` |
| Default error message | “Something went wrong. Please try again.” | `data-phem-error-message-value` |
