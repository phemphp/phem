# Handle slow and failing work on the server

This guide shows you how to control what happens on the server around your handlers and your slow components. You’ll save handler changes in a database transaction, stream slow content after the page, show a fallback when part of the page fails, and move slow work after the response. To push changes to other browsers, see [Push updates to other browsers](real-time.md).

## Save handler changes in a transaction

Add `#[Transactional]` to a handler method to run it in a Doctrine transaction, or add it to the class to cover every handler. Phem flushes and commits when the handler returns, and rolls back when it throws, so the handler doesn’t call `flush()`:

```php
use Doctrine\ORM\EntityManagerInterface;
use Phem\Attribute\{Component, Prop, Transactional};
use Phem\{Event, Node};

#[Component, Transactional]
final class TodoItem
{
    #[Prop] public Todo $todo;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function toggle(): void
    {
        $this->todo->done = !$this->todo->done;   // saved at the commit
    }

    public function delete(Event $e): void
    {
        $this->em->remove($this->todo);           // deleted at the commit
        $e->refresh();                            // the list renders again without the row
    }

    public function render(): Node
    {
        return (
            <li>
                <input type="checkbox" checked={{ todo.done }} onChange={{ toggle }} />
                {{ todo.title }}
                <button onClick={{ delete }}>Delete</button>
            </li>
        );
    }
}
```

The transaction wraps only the handler: `render()` and `mount()` run outside it. A handler that a parent passes as a prop follows the parent’s attribute.

## Load slow content after the page

A slow component delays the whole page. Wrap it in a `<Suspense>` boundary, and the page sends the `fallback` slot first and the slow content after:

```php
use Phem\Suspense;

<Document title="Sales">
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

When the content throws, the `error` slot shows. With no `error` slot, the fallback stays: a streamed boundary loads again with an event, and the event fails.

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
use Phem\Stream\Streams;

#[Component]
final class BuildReport
{
    // The id of the next report.
    #[State] public string $ticket = '';

    public function __construct(
        private readonly Streams $streams,
        private readonly ReportBuilder $reports,
    ) {
    }

    public function mount(): void
    {
        $this->ticket = bin2hex(random_bytes(4));
    }

    #[Async, Transactional]
    public function build(): Promise
    {
        // This report takes the ticket, and the next click gets a new one.
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

`ReportsPage` is a page with `#[Broadcast]` that lists the reports in a `<ul id="reports">`, and `ReportRow` renders one finished report. `ReportBuilder` is a service of your app.

In the request, the method runs as a regular handler and the event renders. The worker then rebuilds the page from the event’s state, calls the method again and runs the closure of the `Promise`. **The method body runs twice**, so keep it fast. The worker drops the pushes of the method body, because they went out with the request, but another side effect, such as a database write or an email, happens twice. Put it in the closure. Pass the values the work needs to the closure, as `use ($ticket)` does: the worker gets the ticket of the event, not the new one.

The worker has no response, so the closure shows its result with `Streams` (see [Push updates to other browsers](real-time.md)). A toast or another browser event of the closure doesn't reach the browser, and the worker logs a warning. The worker has the user of the request, but no session, no cookies and no files. An `#[Async]` handler in an event with files throws a `LogicException`, so move the files in a regular handler. Messenger can retry the closure, so make it safe to run again.

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

The worker can’t use `mercure_publish()`, so give Phem the publisher key, as for [a standalone hub](real-time.md#use-a-standalone-mercure-hub). Tests run the work with `settle()` (see [Testing](testing.md#simulate-user-actions)). phemc reports E054 for `#[Async]` on a function, and E055 for an `#[Async]` method that doesn’t return `Promise`.
