# Handle slow and failing work on the server

This guide shows you how to control what happens on the server around your handlers and your slow components. You’ll save handler changes in a database transaction, stream slow content after the page, and show a fallback when part of the page fails. To push changes to other browsers, see [Push updates to other browsers](real-time.md).

## Save handler changes in a transaction

Add `#[Transactional]` to a handler method to run it in a Doctrine transaction, or add it to the class to cover every handler. Phem flushes and commits when the handler returns, and rolls back when it throws, so the handler doesn’t call `flush()`:

```php
use Doctrine\ORM\EntityManagerInterface;
use Phem\Attribute\{Component, Prop, Transactional};
use Phem\{Node, Reply};

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

    public function delete(Reply $reply): void
    {
        $this->em->remove($this->todo);           // deleted at the commit
        $reply->refresh();                        // the list renders again without the row
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
        <Suspense.fallback><p>Loading the sales report…</p></Suspense.fallback>
        <SalesReport />
    </Suspense>
    <Suspense lazy>
        <Suspense.fallback><p>Loading the chart…</p></Suspense.fallback>
        <Suspense.error><p>The chart is not available now.</p></Suspense.error>
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
    <ErrorBoundary.fallback><p>The stock quote is not available now.</p></ErrorBoundary.fallback>
    <StockQuote />
</ErrorBoundary>
```

Phem logs the exception, and each render tries the content again. The boundary catches errors in the render and in `mount()`, but not in a handler. When the whole request fails with a 404 or a 500, the browser gets the app’s [error page](error-pages.md).
