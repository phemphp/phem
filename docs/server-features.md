# Server features

This page covers the features that run on the server around the handlers: database
transactions, content that loads after the page, real-time updates, slow work, rate limits and
middleware.

## Transactions

Add `#[Transactional]` to a handler method to run it in a Doctrine transaction. On the class, it
applies to all the handlers. Phem flushes and commits when the handler returns, and rolls back
when it throws. Thus the handler does not call `flush()`:

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

The transaction contains only the handler: `render()` and `mount()` run outside of it. A handler
that a parent gives as a prop uses the attribute of the parent.

## Content that loads after the page

A slow component makes all the page slow. Put it in a `<Suspense>` boundary. The page sends the
`fallback` slot first, and the slow content comes after:

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

With no `lazy`, the same response streams the content: the server sends the page with the
fallbacks, and then a chunk for each boundary. With `lazy`, the browser loads the content with an
event when the fallback comes into view. When the content throws, the `error` slot shows.

A Turbo visit cannot read a stream, so it loads the boundaries with an event, as `lazy` does. A
streamed page has no HTTP cache and no web debug toolbar. Turn off the buffers between PHP and the
browser (for example the compression of a proxy). `phem.suspense_stream: false` loads all the
boundaries with an event.

## Errors in a part of the page

An exception in the render of one component makes all the event fail. When a part of the page can
fail, put it in an `<ErrorBoundary>`. When the content throws, the `fallback` slot shows in its
place:

```php
use Phem\ErrorBoundary;

<ErrorBoundary>
    <ErrorBoundary:fallback><p>The stock quote is not available now.</p></ErrorBoundary:fallback>
    <StockQuote />
</ErrorBoundary>
```

The logger gets the exception. Each render tries the content again. As in React, the boundary
catches the errors of the render and of `mount()`, but not of a handler.

## Real-time updates

A change by one user does not show for another user until that user sends an event. A page with
`#[Broadcast]` gets updates from other requests. A handler pushes them with `Phem\Stream\Streams`,
and the Mercure hub sends them to the open pages:

```php
use Phem\Attribute\{Broadcast, Component, Page, State, Transactional};
use Phem\Stream\Streams;

#[Page('/guestbook'), Broadcast]
final class GuestbookPage
{
    // ... renders <SignForm />, <GuestbookStats id="guestbook-stats" /> and <ul id="entries">
}

#[Component]
final class SignForm
{
    #[State] public string $message = '';

    public function __construct(
        private readonly GuestbookRepository $repository,
        private readonly Streams $streams,
    ) {
    }

    #[Transactional]
    public function sign(): void
    {
        $entry = new Entry($this->message);
        $this->repository->add($entry);
        $this->message = '';

        $this->streams->toPage(GuestbookPage::class)
            ->prepend('entries', <GuestbookEntry entry={{ entry }} />)
            ->refresh('guestbook-stats');
    }
}
```

`toPage(GuestbookPage::class)` sends to all the URLs of a page, and
`toPage(GuestbookPage::class, ['id' => 42])` to one URL. `toComponent(Inbox::class)` sends to all the
pages that render a component with `#[Broadcast]`. `everywhere()` sends to all the pages with
`#[Broadcast]`.

The actions are the Turbo Stream actions: `append`, `prepend`, `before`, `after`, `replace`,
`update` and `remove`. Each one takes the `id` of the target element. The markup goes to all the
viewers, so do not push data for one user only. Use `refresh($target)` for that: each browser
renders the component around the target again, with its own access rules.

The browser that sent the event does not render again what its event rendered. In an event
request, each refresh has the token of the event, also a refresh of a service such as a Doctrine
listener. The answer of the event gives that browser the token and the components that the event
rendered. For each refresh with the token, the browser skips a component that the event rendered
with all the components in it, and renders the other targets:

```php
#[Page('/orders/{id:order}'), Broadcast]
final class OrderPage
{
    #[Path] public Order $order;

    // The handler renders the page.
    public function ship(): void
    {
        $this->order->ship();
    }
}

// A Doctrine postFlush listener, in the same request.
$this->streams->toPage(OrderPage::class, ['order' => $order])->refresh();
```

The other viewers of the order render the page again. The browser that clicked "Ship" sends no
refresh when the event rendered the page with all its components. A component that the event
did not render (a component that it kept, or a count in the layout) still renders again in that
browser.

A refresh can find a page that this user cannot see now. When the render of a refresh throws
a `401`, `403`, `404` or `410` HTTP exception, the server answers `409`, and the browser loads
the page again: it shows the error page of the server, not the old page with an error banner.
A `#[Path]` entity that is not found any more does the same. A page with no `#[State]` can throw
in `mount()` when its entity is hidden:

```php
#[Page('/products/{id:product}'), Broadcast]
final class ProductPage
{
    #[Path] public Product $product;

    public function mount(): void
    {
        if ($this->product->hidden) {
            throw new NotFoundHttpException();
        }
    }
}
```

The staff hides the product, and the open pages of the product load the 404 page of the app.
The server decides from the exception of the render: a 403 of the firewall or of a proxy shows
the error banner, as after a handler. A refresh comes from a push, not from the user: when a
`data-phem-guard` has changes that the user did not save, the browser does not load the page
again. It shows the error banner, so the user can copy the changes first.

The content that you push must also be in the server render (read it in `mount()`), or the next
event removes it. Give each pushed item an `id`, so the user who made the change sees it one time.
The streams go out after the transaction. When the handler throws, they are dropped.

### The refresh of a component after its handlers

A class component with `#[Broadcast]` needs no stream for its own changes. After each of its
handlers, the other browsers that show it render it again:

```php
use Phem\Attribute\{Broadcast, BroadcastKey, Component, Prop, State, Transactional};

#[Component]
final class LikeButton
{
    #[Prop, BroadcastKey] public Post $post;
    #[State] public bool $open = false;

    #[Transactional, Broadcast]
    public function like(): void
    {
        ++$this->post->likes;
    }

    // A state of this browser only: no refresh.
    public function toggle(): void
    {
        $this->open = !$this->open;
    }
}
```

With `#[Broadcast]` on some handler methods, only these handlers refresh the component. With
`#[Broadcast]` on the class, all of them do. The props with `#[BroadcastKey]` identify an
instance: the refresh goes only to the instances of the same post.

A key does not have to come from the parent. A class component can mark any property, also a
private one that `mount()` sets, for example from the current user. Phem reads the keys after
the render:

```php
#[Component, Broadcast]
final class OrderList
{
    #[BroadcastKey] private int $customer;
    public array $orders = [];

    public function __construct(
        private readonly CurrentCustomer $customers,
        private readonly OrderRepository $repository,
    ) {
    }

    public function mount(): void
    {
        $this->customer = $this->customers->get()->id;
        $this->orders = $this->repository->findByCustomer($this->customer);
    }
}
```

```php
// In a Doctrine listener: only the lists of this customer render again.
$this->streams->toComponent(OrderList::class, ['customer' => $order->customer->id])->refresh();
```

The key must have a value after each render. A component with no `#[State]` runs `mount()` in
each render, so any property works. In a component with `#[State]`, `mount()` runs only at the
first render: put the key on a `#[Prop]` or a `#[State]`.

With `#[Broadcast(handlers: false)]` on the class, the component gets the streams of other
requests, and no handler refreshes it. Use it for a component that shows data of one user, and
that other requests change:

```php
use Phem\Attribute\{Broadcast, BroadcastKey, Component, Prop};

#[Component, Broadcast(handlers: false)]
final class BagContents
{
    #[Prop, BroadcastKey] public int $bag;

    public function __construct(private readonly BagRepository $bags)
    {
    }

    // A change for this user only: the other browsers do not render their bag again.
    public function empty(): void
    {
        $this->bags->empty($this->bag);
    }
}
```

```php
// In a handler of the staff: each open bag renders again.
$this->streams->toComponent(BagContents::class)->refresh();

// Only the instances of one bag.
$this->streams->toComponent(BagContents::class, ['bag' => $bag->id])->refresh();
```

The `handlers` argument is for a class component only. On a page, a function, a handler method
or a closure, Phem throws a `LogicException`. A component with `handlers: false` cannot have
`#[Broadcast]` on a handler method or on a closure too: use `#[Broadcast]` on these handlers
only, and no `#[Broadcast]` on the class.

### Set up the hub

Phem publishes with `mercure_publish()` of FrankenPHP. Add the hub to the site in the Caddyfile,
and give Phem the subscriber key:

```caddyfile
mercure {
    name phem
    transport local
    issuer phem {
        subscriber {
            jwt {$MERCURE_SUBSCRIBER_JWT_KEY}
        }
    }
}
```

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        subscriber_key: '%env(MERCURE_SUBSCRIBER_JWT_KEY)%'
```

With the subscriber key, the updates are private: each page gets a cookie with access to its own
topics only. With no key, the updates are public, and the hub needs the `anonymous` directive.
The cookie is `Secure`, so it works on HTTPS and on `localhost`. On another plain HTTP host, set
`cookie_name` to a name with no `__Secure-` prefix.

Give the hub a `name`. Caddy makes one server for each port of the site, and each server gets a
hub. With two ports (`SERVER_NAME=":80, https://localhost"`), two unnamed hubs stop Caddy from
starting. The `local` transport is shared by the hub name, so an update from a request on one port
gets to a page on the other port.

### A standalone hub

With another server (PHP-FPM), or when the hub runs apart from the app, Phem publishes with the
HTTP API of the hub. Add a publisher key to the hub and to Phem:

```caddyfile
mercure {
    name phem
    transport local
    issuer phem {
        subscriber {
            jwt {$MERCURE_SUBSCRIBER_JWT_KEY}
        }
        publisher {
            jwt {$MERCURE_PUBLISHER_JWT_KEY}
        }
    }
}
```

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        subscriber_key: '%env(MERCURE_SUBSCRIBER_JWT_KEY)%'
        publisher_key: '%env(MERCURE_PUBLISHER_JWT_KEY)%'
        publish_url: 'http://mercure/.well-known/mercure'   # the URL of the hub for the server
```

For private updates, serve the hub on the host of the app (`/.well-known/mercure`), and turn off
the buffer of the proxy:

```nginx
location /.well-known/mercure {
    proxy_pass http://mercure;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_buffering off;
    proxy_read_timeout 24h;
}
```

## Slow work after the response

A slow handler (an export, a payment, a call to an API) blocks the event. Put `#[Async]` on the
handler, and return the slow work in a `Phem\Async\Promise`. The event answers at once, and a
Symfony Messenger worker does the work after the response:

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

In the request, the method runs as a normal handler, and the event renders. Then the worker builds
the page again from the state of the event, calls the method again, and runs the closure of the
`Promise`. Thus the method body runs **two times**: keep it fast, with no side effects. Pass the
values that the work needs to the closure (`use ($ticket)`).

The worker has no response, so the closure shows its result with `Streams`. The worker has the
user of the request, but no session, no cookies and no files. Messenger can retry the closure, so
make it safe to run again.

Install Messenger, route the message to a transport, and run a worker:

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

The worker cannot use `mercure_publish()`. Give Phem the publisher key, as for
[a standalone hub](#a-standalone-hub). Tests run the work with `settle()` (see
[Testing](testing.md#actions)). phemc reports E054 for `#[Async]` on a function, and E055 for an
`#[Async]` method that does not return `Promise`.

## Rate limits

The `#[RateLimit]` attribute of Symfony limits the events of a page. Install
`symfony/rate-limiter`, configure a limiter, and add the attribute to the page or to one handler:

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        phem_events:
            policy: sliding_window
            limit: 30
            interval: '1 minute'
```

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

An event is a `POST` request, so `methods: 'POST'` counts the events and not the page views. On a
method, the limit applies to that handler only. Over the limit, the server answers `429`, and the
browser dispatches `phem:rate-limited`, with `retryAfter` in its detail.

## Code around the handlers

A handler middleware runs code around each handler: a security check, a log, a metric. Make a
service that implements `Phem\Handler\HandlerMiddleware`:

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

`$next()` runs the next middleware and then the handler. Return without a call to `$next()` to
stop the event. The middleware runs for all the handlers: check `$handler` or `$event` to apply it
to some of them only.

The tag priority sets the order. A higher priority runs first, around the others:

| Priority | Built-in middleware |
|---|---|
| 100 | `#[RateLimit]` |
| 50 | the Symfony profiler (debug only) |
| 10 | `RedirectTo` |
| 0 | the pushes of `Streams` |
| -50 | `#[Validated]` |
| -100 | `#[Transactional]` |
| -200 | `#[Async]` |

The default priority is 0, outside the transaction. To run in the transaction, set a priority
between -100 and -200 with `#[AsTaggedItem(priority: -150)]`.
