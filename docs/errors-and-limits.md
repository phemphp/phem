# Errors and limits

This page explains what the browser does when an event fails, and the limits of an event and of
a page.

## Failed events

When the server answers an event with an error (a 500, a 403), the page does not change. The
browser dispatches `phem:error` on the component of the handler, with the `status` in its detail.
With no listener, the browser shows a default message at the end of the page. Change its text on
the `<Root>` element:

```html
<Root data-phem-error-message-value={{ 'The change was not saved.' }}>
```

To show your own message, call `event.preventDefault()` in a listener of `phem:error`, or use the
[toasts](client-side.md#toasts).

## Retries

A network error, a `502` or a `503` can be temporary. The browser sends the event again, up to 3
times, with a wait of 500 ms, 1 s and 2 s. When the last try fails, it dispatches `phem:error`.

A network error can come after the handler committed, so the handler can run two times. Make a
handler safe to run again, or turn off the retries:

```html
<Root data-phem-retries-value={{ 0 }} data-phem-retry-delay-value={{ 1000 }}>
```

## Event size

An event body has a maximum size, with the state of the page: `phem.max_event_size` (1 MiB by
default, `0` for no limit). Over the limit, the server answers `413`. The browser compresses a
large body with gzip.

The state of a page must fit in an event body. A state that is too large throws in debug mode,
with its largest entry. Keep less in `#[State]` (an id, not the rows), or raise the limit:

```yaml
# config/packages/phem.yaml
phem:
    max_event_size: 2097152
```

## State cache

For a large state, an event sends only its signature. The server keeps the state in the
`cache.phem.state` pool. A state is large from `phem.state_cache_min_size` bytes (4096 by
default). When the pool lost the state, the browser sends the event again with the full state, so
a miss costs only one more request.

Use a pool that removes old entries, such as Redis or APCu. With more than one server, a shared
pool gives more hits:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.phem.state:
                adapter: cache.adapter.redis
                default_lifetime: 1800
```

`phem.state_cache: false` turns the cache off.

## Performance limits

Each event is a round trip to the server: about 1 ms on the server, plus the network time. Logic
that must run only in the browser goes in a Stimulus controller (see
[Client-side behavior](client-side.md)). There is no `useEffect`, because the server keeps no
session between events.

An event runs the `render()` of the components on the path to its handler again, because they
give the handler and its props. A click on one row of a list thus runs the `render()` of the
list, and Twig goes through all its rows. The browser keeps the HTML of the other rows. On a list
of 5000 rows, a click on one row takes about 46 ms on the server, and the full page about 130 ms.
Show a long list in pages.

The HTML of a component contains the HTML of all the components under it, and each level reads it
again. So the render time grows faster than the depth of the tree: 200 nested components take
about 5 ms, but 800 take about 45 ms. A real page has fewer than 50 levels, and there the cost is
less than 1 ms.
