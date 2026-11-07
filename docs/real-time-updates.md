# Push updates to other browsers

This guide shows you how to show one user’s change to every other user who has the page open, without waiting for their next event. You’ll push changes from a handler, refresh a component after its own handlers, target one instance with a key and set up the Mercure hub that delivers the updates.

## Push changes from a handler

Add `#[Broadcast]` to a page so it receives updates from other requests. A handler pushes the updates with `Phem\Stream\Streams`, and the Mercure hub delivers them to the open pages:

```php
use Phem\Attribute\{Broadcast, Component, Page, State, Transactional};
use Phem\Stream\Streams;

#[Page('/guestbook'), Broadcast]
final class GuestbookPage
{
    // ... renders <SignForm />, <GuestbookStats id="guestbook-stats" />
    // and <ul id="entries">
}
```

The form component signs the guestbook and pushes two changes to every open guestbook page:

```php
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

### Choose which pages get the update

`Streams` has one method for each audience:

- **`toPage(GuestbookPage::class)`**: every URL of a page
- **`toPage(GuestbookPage::class, ['id' => 42])`**: one URL of a page
- **`toComponent(Inbox::class)`**: every page that renders a component with `#[Broadcast]`
- **`everywhere()`**: every page with `#[Broadcast]`

### Change the page with stream actions

The actions are the Turbo Stream actions: `append`, `prepend`, `before`, `after`, `replace`, `update` and `remove`. Each one takes the `id` of the target element.

The markup goes to every viewer, so don’t push data meant for one user only. Use `refresh($target)` for that instead: each browser renders the component around the target again, with its own access rules.

### Keep pushed content in the server render

Pushed content must also be in the server render, so load it in `mount()`, or the next event removes it. Give each pushed item an `id`, so the user who made the change sees it only once. The streams go out after the transaction commits, and Phem drops them when the handler throws.

## Skip the refresh in the browser that sent the event

The browser that sent an event doesn’t render again what its event already rendered. In an event request, each refresh has the event’s token, even a refresh from a service such as a Doctrine listener. The event response gives that browser the token and the list of components the event rendered.

For each refresh with that token, the browser skips a component that the event rendered with all its children, and renders the other targets:

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

The other viewers of the order render the page again. The browser that clicked “Ship” skips the refresh when the event rendered the page with all its components. A component that the event didn’t render, such as a component it kept or a count in the layout, still renders again in that browser.

## Handle a refresh for a page the user can’t see anymore

A refresh can reach a page that the user isn’t allowed to see anymore. When the render of a refresh throws a `401`, `403`, `404` or `410` HTTP exception, the server returns `409` and the browser reloads the page. The user then sees the server’s error page instead of the old page with an error banner. A `#[Path]` entity that no longer exists has the same effect.

A page with no `#[State]` can throw in `mount()` when its entity is hidden:

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

When a staff member hides the product, every open page of that product loads the app’s 404 page. The server decides from the exception of the render, so a 403 from the firewall or from a proxy shows the error banner, as it does after a handler.

A refresh comes from a push, not from the user. When a `data-phem-guard` element has unsaved changes, the browser doesn’t reload the page. It shows the error banner instead, so the user can copy the changes first.

## Refresh a component after its own handlers

A class component with `#[Broadcast]` doesn’t need a stream for its own changes. After each of its handlers, the other browsers that show it render it again:

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

    // State of this browser only: no refresh.
    public function toggle(): void
    {
        $this->open = !$this->open;
    }
}
```

Put `#[Broadcast]` on some handler methods to refresh the component only after those handlers, or on the class to refresh it after all of them.

### Target one instance with a broadcast key

The props with `#[BroadcastKey]` identify an instance, so in the example above the refresh goes only to the instances that show the same post.

A key doesn’t have to come from the parent. A class component can mark any property as a key, including a private one that `mount()` sets, for example from the current user. Phem reads the keys after the render:

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

A Doctrine listener then refreshes only the lists of one customer:

```php
$this->streams->toComponent(OrderList::class, ['customer' => $order->customer->id])->refresh();
```

The key must have a value after every render. A component with no `#[State]` runs `mount()` on every render, so any property works. A component with `#[State]` runs `mount()` only on the first render, so put the key on a `#[Prop]` or a `#[State]` property.

### Receive updates without refreshing after handlers

Add `#[Broadcast(handlers: false)]` to the class to receive streams from other requests while no handler triggers a refresh. Use it for a component that shows one user’s data, which other requests change:

```php
use Phem\Attribute\{Broadcast, BroadcastKey, Component, Prop};

#[Component, Broadcast(handlers: false)]
final class BagContents
{
    #[Prop, BroadcastKey] public int $bag;

    public function __construct(private readonly BagRepository $bags)
    {
    }

    // A change for this user only: other browsers keep their bag.
    public function empty(): void
    {
        $this->bags->empty($this->bag);
    }
}
```

A staff handler can refresh every open bag, or only the instances of one bag:

```php
$this->streams->toComponent(BagContents::class)->refresh();

$this->streams->toComponent(BagContents::class, ['bag' => $bag->id])->refresh();
```

The `handlers` argument works on a class component only. On a page, a function, a handler method or a closure, Phem throws a `LogicException`. A component with `handlers: false` can’t also have `#[Broadcast]` on a handler method or a closure. In that case, put `#[Broadcast]` on those handlers only, and leave it off the class.

## Set up the Mercure hub in FrankenPHP

Phem publishes updates with the FrankenPHP `mercure_publish()` function. Add the hub to the site in the Caddyfile:

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

Then give Phem the subscriber key:

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        subscriber_key: '%env(MERCURE_SUBSCRIBER_JWT_KEY)%'
```

With the subscriber key, the updates are private: each page gets a cookie that grants access to its own topics only. Without a key, the updates are public, and the hub needs the `anonymous` directive. The cookie is `Secure`, so it works on HTTPS and on `localhost`. On another plain HTTP host, set `cookie_name` to a name without the `__Secure-` prefix.

### Name the hub

Always give the hub a `name`. Caddy creates one server for each port of the site, and each server gets a hub. With two ports (`SERVER_NAME=":80, https://localhost"`), two unnamed hubs stop Caddy from starting. The `local` transport is shared by hub name, so an update from a request on one port reaches a page on the other port.

## Use a standalone Mercure hub

With another server, such as PHP-FPM, or when the hub runs apart from the app, Phem publishes through the hub’s HTTP API. Add a publisher key to the hub:

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

Then give Phem the publisher key and the URL the server uses to reach the hub:

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        subscriber_key: '%env(MERCURE_SUBSCRIBER_JWT_KEY)%'
        publisher_key: '%env(MERCURE_PUBLISHER_JWT_KEY)%'
        publish_url: 'http://mercure/.well-known/mercure'
```

For private updates, serve the hub on the app’s host (`/.well-known/mercure`) and turn off proxy buffering:

```nginx
location /.well-known/mercure {
    proxy_pass http://mercure;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_buffering off;
    proxy_read_timeout 24h;
}
```
