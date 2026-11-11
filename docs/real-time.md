# Push updates to other browsers

This guide shows you how to show one user’s change to every other user who has the page open, without waiting for their next event. You’ll push changes from a handler, refresh the pages that show a changed entity, refresh a component after its own handlers, target one instance with a key and set up the Mercure hub that delivers the updates.

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
- **`toPage(OrderPage::class, ['order' => $order])`**: the URL of a page with a `#[Path]` entity
- **`toComponent(Inbox::class)`**: every page that renders a component with `#[Broadcast]`
- **`everywhere()`**: every page with `#[Broadcast]`

A function page or a function component is its first-class callable: `toPage(GuestbookPage(...))`, `toComponent(Inbox(...))`.

To select one URL, give a value for each `#[Path]` property. Give a `#[Path]` entity as the entity, not as its id. All the routes of the page then get the update: `/orders/{order}`, `/orders/{id:order}` and `/o/{slug:order}` select the same order. When you have only the id, give a Doctrine reference:

```php
$this->streams->toPage(OrderPage::class, ['order' => $entityManager->getReference(Order::class, 42)])->refresh();
```

### Change the page with stream actions

The actions are the Turbo Stream actions: `append`, `prepend`, `before`, `after`, `replace`, `update` and `remove`. Each one takes the `id` of the target element. `replace()` and `update()` morph the target by default, so the focus and a field that the user changes stay. Give `false` as the third argument to replace the target with no morph: `->replace('cart', <Cart />, false)`.

The markup goes to every viewer, so don’t push data meant for one user only. Use `refresh($target)` for that instead: each browser renders the component around the target again, with its own access rules.

### Keep pushed content in the server render

Pushed content must also be in the server render, so load it in `mount()`, or the next event removes it. For a long list, use a feed instead: the browser keeps its items, and `->feed('messages')->append($entry)` pushes a new item that each browser renders itself, with its handlers (see [Show long lists with feeds](feeds.md#push-changes-to-other-browsers)). Give each pushed item an `id`, so the user who made the change sees it only once. The streams go out after the transaction commits, and Phem drops them when the handler throws.

## Refresh the pages that show an entity

Add `on:` to `#[Broadcast]` to name the Doctrine entities that a page or a component shows. It needs DoctrineBundle: without it, `on:` does nothing. After each flush that inserts, updates or deletes one of these entities, Phem refreshes the page or the component in every browser. You don’t need a Doctrine listener or a stream:

```php
use Phem\Attribute\{Broadcast, BroadcastKey, Component, Page, Path, Prop};

// The URL of the order, also when one of its lines changes.
#[Page('/staff/orders/{id:order}'), Broadcast(on: [Order::class, OrderLine::class])]
final class StaffOrderPage
{
    #[Path] public Order $order;
}

// Every instance, for a new or a deleted Order, a change of its status, or any change of an OrderLine.
#[Component, Broadcast(on: [Order::class => ['status'], OrderLine::class])]
final class StaffAlerts
{
}

// Only the instances that show the changed line.
#[Component, Broadcast(on: OrderLine::class)]
final class StaffOrderLine
{
    #[Prop, BroadcastKey] public OrderLine $line;
}
```

A handler of `StaffOrderPage` ships the order:

```php
#[Transactional]
public function ship(): void
{
    $this->order->status = OrderStatus::Shipped;
}
```

After the commit, the hub gets one refresh for each target. In the event request, each refresh has the token of the event (see [Skip the refresh in the browser that sent the event](#skip-the-refresh-in-the-browser-that-sent-the-event)):

```html
<!-- to the URL /staff/orders/42 -->
<turbo-stream action="refresh" data-phem-sender="…"></turbo-stream>
<!-- to the instances of <StaffAlerts> -->
<turbo-stream action="refresh" targets="[data-phem-broadcast~=&quot;…&quot;]" data-phem-sender="…"></turbo-stream>
```

### Choose the URLs and the instances

The entity selects what refreshes:

- **A page** refreshes the URLs where a `#[Path]` property has the entity. A change of `Order` 42 refreshes `/staff/orders/42` only.
- **A component** refreshes the instances where a `#[BroadcastKey]` prop has the entity.
- **A related entity** follows its to-one association. `OrderLine` has a `ManyToOne` to `Order`, the type of the `order` property, so a change of a line refreshes the page of its order. When a line moves to another order, the pages of the two orders refresh.
- **With no such property**, every URL of the page or every instance of the component refreshes.

A page with more than one `#[Path]` property, such as `/shops/{shop}/orders/{order}`, also refreshes by the entity alone: a change of the order refreshes its URL in every shop.

### Refresh only for some fields

Give a list of fields to refresh only after an update of these fields. A field can also be an association or a collection of the owning side. An insert and a delete always refresh:

```php
#[Component, Broadcast(on: [Order::class => ['status', 'customer']])]
```

A flush doesn’t write the inverse side of an association (`mappedBy`), such as `Order::$lines`. To refresh when the lines change, add `OrderLine::class` to `on:`.

Phem checks the classes and the fields at the first flush. A class that isn’t a Doctrine entity, a field that the entity doesn’t have, or the inverse side of an association throws a `LogicException`.

### Know the limits

- Only a flush of the Doctrine ORM refreshes. A DQL `UPDATE` or `DELETE`, or plain SQL, doesn’t go through the unit of work. Push the refresh with `Streams` for them.
- A flush in a command or in a Messenger handler publishes its refreshes at the end of the command or of the message. These processes have no FrankenPHP request, so they publish only with the `publisher_key` and `publish_url` options (see [Use a standalone Mercure hub](#use-a-standalone-mercure-hub)). Without them, Phem logs an error and the refreshes are lost. In another long process, call `Streams::flush()`.
- A flush in the render of a push refresh doesn’t refresh other browsers. For example, a `mount()` that saves a “last seen” date would make two browsers refresh each other with no end. Save such data in a handler, or leave its entity out of `on:`.
- A class component with `on:` still refreshes after its own handlers (see [Refresh a component after its own handlers](#refresh-a-component-after-its-own-handlers)). When a handler and a flush refresh the same instances, the browser renders them once. Use `handlers: false` when only the entity changes need a refresh.
- `on:` goes on a page or a component, not on a handler method or a closure.

## Skip the refresh in the browser that sent the event

The browser that sent an event doesn’t render again what its event already rendered. In an event request, each refresh has the event’s token, also a refresh of an entity change or of a service such as a Doctrine listener. The event response gives that browser the token and the list of components the event rendered.

For each refresh with that token, the browser skips a component that the event rendered with all its children, and renders the other targets:

```php
#[Page('/orders/{id:order}'), Broadcast(on: Order::class)]
final class OrderPage
{
    #[Path] public Order $order;

    // The handler renders the page, and its flush refreshes the page in every browser.
    #[Transactional]
    public function ship(): void
    {
        $this->order->ship();
    }
}
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

    public function __construct(
        private readonly BagRepository $bags,
    ) {
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

The `handlers` argument works on a class component only. On a page, a function or a handler method, `phemc` reports E053. On these and on a closure, Phem throws a `LogicException`. A component with `handlers: false` can’t also have `#[Broadcast]` on a handler method or a closure. In that case, put `#[Broadcast]` on those handlers only, and leave it off the class.

## Sync the tabs of one browser without a hub

Each tab keeps its own signed state. When a user opens the same page in two tabs and changes data in one tab, the other tab shows old data until its next event. To refresh the other tabs, set `data-phem-sync-tabs-value` on the `<Root>` element. This needs no `#[Broadcast]` and no Mercure hub:

```php
#[Page('/orders/{id:order}')]
final class OrderPage
{
    #[Path] public Order $order;

    public function render(): Node
    {
        return (
            <html lang="en">
                <head>{{ importmap('app') }}</head>
                <body>
                    <Root data-phem-sync-tabs-value="true">
                        <OrderLines order={{ order }} />
                    </Root>
                </body>
            </html>
        );
    }
}
```

The `<Root>` element renders the value for the `phem` controller:

```html
<div id="phem-root" data-controller="phem" ... data-phem-sync-tabs-value="true">
```

After an event of the user, the tab sends a message on a `BroadcastChannel`. Each other tab of the same path refreshes the content of its `<Root>`: it sends a refresh with no handler, as a `refresh()` push does. The refresh keeps the bound fields that the user changed in that tab.

- **Which tabs**: the tabs of the same origin with the same path, so the tabs of one browser, with the cookies of the same user. A tab with another query string or fragment refreshes too. Another path, for example the list of the orders, doesn’t refresh.
- **Which events**: an event with a handler that the server answered with success, also an event that redirects. A failed event (also a redirect that the browser blocks), a refresh, a `phem:live` update, a `<Suspense>` load, an `onPoll` event and an `onVisible` event send no message. So two tabs don’t refresh each other again and again.
- **Hidden tabs**: a hidden tab refreshes when it becomes visible again, one time for all the messages since then.
- **Pages that come back**: Turbo can show a page from its cache when the user goes back. The page refreshes when a message came for it while it was away. A page that the browser restores from its back/forward cache got no messages, so it always refreshes.
- **Other browsers**: the message doesn’t leave the browser. To update the pages of other users, use `#[Broadcast]` and the Mercure hub. An event that pushes a `refresh()` to a page with an open connection to the hub sends no message: the hub refreshes the other tabs, and a message would refresh them a second time.

The option is off by default, because each event of the user makes one more request in each other tab, and that request renders all the content of the `<Root>`. The tab doesn’t know which data the handler changed, so it renders all of it, also after a handler that changes no data.

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

The `issuer` name must be the `phem.mercure.issuer` option (`phem` by default), or the hub rejects the tokens of Phem. Then give Phem the subscriber key:

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        subscriber_key: '%env(MERCURE_SUBSCRIBER_JWT_KEY)%'
```

With the subscriber key, the updates are private: each page gets a cookie that grants access to its own topics only. Without a key, the updates are public, and the hub needs the `anonymous` directive. The cookie is `Secure`, so it works on HTTPS and on `localhost`. On another plain HTTP host, set `phem.mercure.cookie_name` and the `cookie_name` of the hub to the same name without the `__Secure-` prefix.

The browser connects to the hub at `phem.mercure.hub_url` (`/.well-known/mercure` by default). For a hub on another host or port, give its absolute URL:

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        hub_url: 'https://mercure.example.com/.well-known/mercure'
```

### Name the hub

Always give the hub a `name`. Caddy creates one server for each port of the site, and each server gets a hub. With two ports (`SERVER_NAME=":80, https://localhost"`), two unnamed hubs stop Caddy from starting. The `local` transport is shared by hub name, so an update from a request on one port reaches a page on the other port.

## Use a standalone Mercure hub

With another server, such as PHP-FPM or the `symfony serve` server of development, or when the hub runs apart from the app, Phem publishes through the hub’s HTTP API. Commands and Messenger workers, with the workers of `#[Async]` handlers, always publish this way, also when the app runs in FrankenPHP. Without the publisher key, they don’t publish, and a page on another server gets no updates.

Install the Symfony HttpClient:

```sh
composer require symfony/http-client
```

Add a publisher key to the hub:

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
