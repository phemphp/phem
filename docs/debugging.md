# Debug a Phem app

This page shows you how to see what Phem does for a page request or an event: the Symfony profiler panel and the console commands. For an event that fails, see [Troubleshoot failed events and limits](failed-events.md).

## Inspect requests in the Symfony profiler

In debug mode, Phem adds a panel to the Symfony profiler. Install the profiler in development:

```sh
composer require --dev symfony/web-profiler-bundle symfony/stopwatch
```

The Phem panel shows each page request and each event:

- **Request**: the page, the handler and its source, and the outcome (rendered, redirect, reload or rate limit)
- **Component tree**: the state of each component, its render time without its children, and the contexts that it provides and reads, with the provider
- **Event passes**: the components that rendered before the handler, the ones that rendered after it, the ones that rendered only because a context above them changed (propagated), and the ones that changed, which the browser morphs
- **State**: the size of the signed state, and whether it came from the state cache
- **Inputs and outputs**: the bound values, the browser events of the handler, and the warnings of the dev-mode check
- **Transaction**: the Doctrine transaction of the handler, its commit or rollback, and its time
- **Real-time updates**: the Turbo Streams that the request pushed to the Mercure hub with their topics, and the stale components that an event rendered again (see [Real-time updates](real-time.md))

The Performance panel shows the render passes, the handler and the transaction in the `phem` category. With the profiler on, the browser console logs each event with a link to its Phem panel (`console.debug`). Without debug mode, Phem doesn’t measure the renders.

## Render a page from the console

`bin/console phem:render [path]` prints the HTML of the page at `path` (`/` by default) without a server. It exits with code 1 when the response isn’t successful. It doesn’t catch errors, so the console shows them with the `.phem` location.

`bin/console debug:router` lists the routes of the pages and of their events (`phem_event.*`).
