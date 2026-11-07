# Handle slow and failing work on the server

This guide shows you how to control what happens on the server around your handlers and your slow components. You’ll save handler changes in a database transaction, stream slow content after the page, show a fallback when part of the page fails, move slow work after the response, rate limit events and run your own code around every handler. To push changes to other browsers, see [Push updates to other browsers](real-time-updates.md).

## Save handler changes in a transaction

Add `#[Transactional]` to a handler method to run it in a Doctrine transaction, or add it to the class to cover every handler. Phem flushes and commits when the handler returns, and rolls back when it throws, so the handler doesn’t call `flush()`:

```php
#[Component, Transactional]
final class TodoItem
{
    #[Prop] public Todo $todo;

    public function toggle(): void
    {
        $this->todo->done = !$this->todo->done;   // saved at the commit
    }

    public function delete(): void
    {
        $this->em->remove($this->todo);           // deleted at the commit
    }
}
```

The transaction wraps only the handler: `render()` and `mount()` run outside it. A handler that a parent passes as a prop follows the parent’s attribute.

## Load slow content after the page

A slow component delays the whole page. Wrap it in a `<Suspense>` boundary, and the page sends the `fallback` slot first and the slow content after:

```php
use Phem\Suspense;

<Document>
    <Suspense>
        <Suspense:fallback><p>Loading the sales report…</p></Suspense:fallback>
        <SalesReport />
    </Suspense>
    <Suspense lazy>
        <Suspense:fallback><p>Loading the chart…</p></Suspense:fallback>
        <Suspense:error><p>The chart is not available now.</p></Suspense:error>
        <ActivityChart />
    </Suspense>
</Document>
```

A boundary loads its content in one of two ways:

- **Streamed (default)**: the same response sends the page with the fallbacks, then one chunk for each boundary
- **`lazy`**: the browser loads the content with an event when the fallback scrolls into view

When the content throws, the `error` slot shows.

### Know the limits of streaming

A Turbo visit can’t read a stream, so it loads the boundaries with an event, as `lazy` does. A streamed page has no HTTP cache and no web debug toolbar. Turn off any buffering between PHP and the browser, such as compression in a proxy.

To load every boundary with an event instead of streaming, turn off `suspense_stream`:

```yaml
# config/packages/phem.yaml
phem:
    suspense_stream: false
```

## Show a fallback when part of the page fails

An exception while rendering one component makes the whole event fail. When a part of the page can fail, wrap it in an `<ErrorBoundary>`. If the content throws, the `fallback` slot shows in its place:

```php
use Phem\ErrorBoundary;

<ErrorBoundary>
    <ErrorBoundary:fallback><p>The stock quote is not available now.</p></ErrorBoundary:fallback>
    <StockQuote />
</ErrorBoundary>
```

Phem logs the exception, and each render tries the content again. The boundary catches errors in the render and in `mount()`, but not in a handler. When the whole request fails with a 404 or a 500, the browser gets the app’s [error page](error-pages.md).

## Run slow work after the response

A slow handler, such as an export, a payment or an API call, blocks the event. Add `#[Async]` to the handler and return the slow work in a `Phem\Async\Promise`. The event answers right away, and a Symfony Messenger worker runs the work after the response:

```php
use Phem\Async\Promise;
use Phem\Attribute\{Async, Component, State, Transactional};

#[Component]
final class BuildReport
{
    #[State] public string $ticket = '';

    public function mount(): void
    {
        $this->ticket = bin2hex(random_bytes(4));
    }

    #[Async, Transactional]
    public function build(): Promise
    {
        $ticket = $this->ticket;
        $this->ticket = bin2hex(random_bytes(4));
        $this->streams->toPage(ReportsPage::class)
            ->prepend('reports', <li id="report-{{ ticket }}">Building a report…</li>);

        return new Promise(function () use ($ticket): void {
            $report = $this->reports->build($ticket);   // slow
            $this->streams->toPage(ReportsPage::class)
                ->replace('report-'.$ticket, <ReportRow report={{ report }} />);
        });
    }
}
```

In the request, the method runs as a regular handler and the event renders. The worker then rebuilds the page from the event’s state, calls the method again and runs the closure of the `Promise`. **The method body runs twice**, so keep it fast and free of side effects. Pass the values the work needs to the closure, as `use ($ticket)` does.

The worker has no response, so the closure shows its result with `Streams` (see [Push updates to other browsers](real-time-updates.md)). The worker has the user of the request, but no session, no cookies and no files. Messenger can retry the closure, so make it safe to run again.

### Run the Messenger worker

Install Messenger, route the message to a transport and start a worker:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Phem\Async\AsyncHandlerMessage: async
```

```sh
php bin/console messenger:consume async
```

The worker can’t use `mercure_publish()`, so give Phem the publisher key, as for [a standalone hub](real-time-updates.md#use-a-standalone-mercure-hub). Tests run the work with `settle()` (see [Testing](testing.md#simulate-user-actions)). phemc reports E054 for `#[Async]` on a function, and E055 for an `#[Async]` method that doesn’t return `Promise`.

## Rate limit events

Use the Symfony `#[RateLimit]` attribute to limit the events of a page. Install `symfony/rate-limiter` and configure a limiter:

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        phem_events:
            policy: sliding_window
            limit: 30
            interval: '1 minute'
```

Then add the attribute to the page or to one handler:

```php
use Symfony\Component\HttpKernel\Attribute\RateLimit;

#[RateLimit('phem_events', methods: 'POST')]
#[Page('/search')]
final class SearchPage
{
    #[RateLimit('phem_search')]
    public function search(Event $e): void
    {
        // ...
    }
}
```

An event is a `POST` request, so `methods: 'POST'` counts the events and not the page views. On a method, the limit applies to that handler only. Over the limit, the server returns `429` and the browser dispatches `phem:rate-limited` with `retryAfter` in its detail.

## Run code around every handler

A handler middleware runs code around each handler, such as a security check, a log entry or a metric. Create a service that implements `Phem\Handler\HandlerMiddleware`:

```php
final class LoginRequired implements HandlerMiddleware
{
    public function __construct(private readonly Security $security)
    {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        if (null === $this->security->getUser()) {
            return new RedirectTo(LoginPage::class);
        }

        return $next();
    }
}
```

`$next()` runs the next middleware and then the handler. Return without calling `$next()` to stop the event. The middleware runs for every handler, so check `$handler` or `$event` to apply it to some handlers only.

### Order your middleware with priorities

The tag priority sets the order, and a higher priority runs first, around the others. The built-in middleware uses these priorities:

| Priority | Built-in middleware |
|---|---|
| 100 | `#[RateLimit]` |
| 50 | The Symfony profiler (debug only) |
| 10 | `RedirectTo` |
| 0 | The pushes of `Streams` |
| -50 | `#[Validated]` |
| -100 | `#[Transactional]` |
| -200 | `#[Async]` |

The default priority is 0, outside the transaction. To run inside the transaction, set a priority between -100 and -200 with `#[AsTaggedItem(priority: -150)]`.
