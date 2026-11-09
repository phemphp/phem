# Show long lists with feeds

This guide shows you how to show a long list whose items stay in the browser: a chat, a feed, a log or a list that loads more items on demand. You’ll declare a feed, change it in a handler, handle events in its items, load older items and push changes to other browsers.

## Know when to use a feed

A `#[State]` list goes to the browser and back with each event, and a component with no `#[State]` loads its list again in each render. Both are good for a short list. For a long list, use a `Phem\Feed`:

- The items aren’t in the state. The state stays small, also with thousands of items.
- An event doesn’t load the list again. The server renders only the items that the event adds or changes.
- The browser keeps the other items, with their focus and their client changes.

## Show a feed

Add a public `Feed` property to a class component, with no attribute. Phem sets a new feed on each new instance. Add the first items in `mount()`, and show the feed with `phem:feed`:

```php
use Phem\Attribute\{Component, Prop, State};
use Phem\Feed;
use Phem\Node;

#[Component]
final class ChatRoom
{
    #[Prop] public Room $room;
    #[State] public string $draft = '';
    public Feed $messages;

    public function __construct(private readonly MessageRepository $repository)
    {
    }

    public function mount(): void
    {
        $this->messages->append(...$this->repository->latest($this->room, 50));
    }

    public function render(): Node
    {
        return (
            <section>
                <ul phem:feed={{ messages }}>
                    {% for message in messages %}
                        <li><span>{{ message.text }}</span> <button onClick={{ remove(message) }}>Delete</button></li>
                    {% endfor %}
                </ul>
                <form onSubmit={{ send }}><input name="draft" phem:bind={{ draft }} /></form>
            </section>
        );
    }
}
```

The feed element holds one `{% for %}` loop on the feed, and the loop renders one element for each item. `phemc` checks this (E065, E066). Phem gives each element an id and the signed item:

```html
<ul id="phem12c0dd1e70d1-messages" data-phem-feed>
    <li id="phem12c0dd1e70d1-messages-3" data-phem-item="WyJBcHBcXEVudGl0eVxcTWVzc2FnZSIseyJpZCI6M31d.k9Xq…">
        <span>Three</span> <button data-phem-on-click="phem12c0dd1e70d1.1a46ffd3e34e">Delete</button>
    </li>
    …
</ul>
```

The id of the feed element is the fiber id of the component and the name of the property. The id of an item adds the identifier of the item.

A component with a feed runs `mount()` on its first render only, also when it has no `#[State]`: the browser keeps the items.

## Change the feed in a handler

A handler changes the feed with five methods:

| Method | Effect in the browser |
|---|---|
| `append($a, $b)` | Adds the items at the end of the list, in their order |
| `prepend($a, $b)` | Adds the items at the start of the list, in their order |
| `update($item)` | Renders the item again, in its place |
| `delete($item)` | Removes the item |
| `reset($a, $b)` | Replaces all the items of the list |

An item that is in the list already moves when you append it or prepend it again.

```php
public function send(): void
{
    $message = $this->repository->add($this->room, $this->draft);
    $this->messages->append($message);
    $this->draft = '';
}
```

The response has one Turbo Stream for each change. Here the HTML of the component is the same after the handler, because the draft is empty again, so the response has only the new item:

```html
<turbo-stream action="append" target="phem12c0dd1e70d1-messages">
    <template><li id="phem12c0dd1e70d1-messages-6" data-phem-item="…"><span>Six</span> …</li></template>
</turbo-stream>
```

When the component changes too, the morph of the component comes first. In the morph, the feed element has no items, and the browser keeps its children. Then the streams of the feed change the items.

## Handle events in an item

A handler in an item works as in any list: `onClick={{ remove(message) }}`. The browser sends the signed item of the element with the event. The server checks the signature, loads the item again with its identifier, and renders it in the feed, so the handler is found:

```php
public function remove(Message $message): void
{
    $this->repository->remove($message);
    $this->messages->delete($message);
}
```

After the handler, the item of the event renders again and goes to the browser as a `replace`, also when the handler doesn’t call `update()`.

The signature covers the class of the component, the name of the feed and the item. The browser can’t ask for an item that it didn’t get, or for an item of another feed. When the item isn’t in the database any more, or the signature isn’t valid, the handler doesn’t run and the page loads again (409), as for a row that another user removed.

The token doesn’t limit the item to one user. A user can send back an item that they saw before, so check access in the handler, as for any value of the browser (see [Keep security decisions out of the state](security.md#keep-security-decisions-out-of-the-state)).

## Load older items

Keep the position of the list in a `#[State]` property, and prepend the next items:

```php
#[State] public ?int $oldest = null;

public function mount(): void
{
    $latest = $this->repository->latest($this->room, 50);
    $this->messages->append(...$latest);
    $this->oldest = $latest[0]->id ?? null;
}

public function older(): void
{
    $older = $this->repository->before($this->room, $this->oldest, 50);
    $this->messages->prepend(...$older);
    $this->oldest = $older[0]->id ?? null;
}
```

```php
{% if oldest %}<button onClick={{ older }}>Older</button>{% endif %}
```

`prepend()` gives one stream for all its items, so they keep their order.

## Push changes to other browsers

Add `#[Broadcast]` and a `#[BroadcastKey]` to the component, and push the changes with `feed()` on `Streams::toComponent()`:

```php
#[Component, Broadcast(handlers: false)]
final class ChatRoom
{
    #[Prop, BroadcastKey] public Room $room;
    public Feed $messages;

    public function send(): void
    {
        $message = $this->repository->add($this->room, $this->draft);
        $this->messages->append($message);
        $this->draft = '';

        $this->streams->toComponent(self::class, ['room' => $this->room])
            ->feed('messages')
            ->append($message);
    }
}
```

`feed()` has `append()`, `prepend()`, `update()` and `delete()`. The push has no markup, only the signed item:

```html
<turbo-stream action="phem-feed" targets="[data-phem-broadcast~=&quot;…&quot;]" data-phem-feed="messages" data-phem-feed-op="append" data-phem-feed-key="6" data-phem-item="…" data-phem-sender="…"></turbo-stream>
```

Each browser that shows the feed sends the item back with a refresh, and its server renders the item in the feed. Thus the pushed item has its handlers. Phem doesn’t check if each user may see the item: push an item only to the instances whose users may see it (the `#[BroadcastKey]`), or check access in the markup of the item. A `delete()` removes the element of the item in the browser, with no request. The browser that sent the event skips the push, because its handler changed its feed already.

`feed()` works only on `Streams::toComponent()`: `toPage()` and `everywhere()` throw a `LogicException`.

## Start a new feed

The feed belongs to the instance of the component. To show another list, give the component a `key`. A new key makes a new instance, so `mount()` adds the items of the new list:

```php
<ChatRoom key={{ room.id }} room={{ room }} />
```

To replace the items of the same instance, call `reset()`.

## Know the limits

- An item is an entity: Phem identifies it with its class and its identifier, and loads it again for an event (see `Phem\Handler\ObjectIdentifier` and `Phem\Path\EntityFinder`). An object that Phem can’t identify throws an exception when it renders.
- An item contains plain markup, the handlers of the component and template components (a function that only returns markup). A function or a class component with its own render throws a `LogicException`: it would need its state and its props in the page. `phemc` reports the tag of such a component in an item (E066).
- The loop has one name, the item: `{% for message in messages %}`. An event renders only the new and the changed items, so a key (`{% for key, message in messages %}`) and `loop` (`loop.index`, `loop.last`) don’t give the place of the item in the list. `phemc` reports them (E066). Read the value from the item, or use CSS (`:nth-child()`, `counter()`).
- A component renders one element for each feed: not in a `{% for %}` loop, not in an item of another feed, and not two elements for the same feed (E065).
- The feed element renders in each render of its component, also when the list is empty. The browser keeps the items, and `mount()` adds the first items one time only, so a feed element that shows later would have no items. A component that renders no element for one of its feeds throws a `LogicException`. Hide the list with CSS or the `hidden` attribute, not with `{% if %}`.
- `phem:feed` can’t come from a spread, and the feed element can’t have an `id`, a `phem:bind` or a `phem:error`. The element of an item can’t have an `id` either: Phem gives it the id of the item.
- The identifier of an item is made of scalar values or Stringable objects (a UUID). An identifier with an association throws a `LogicException`.
- A feed property is public, not static, not readonly, not promoted and not nullable, and has no `#[State]`, `#[Prop]`, `#[Path]` or `#[Context]`. `phemc` reports another property (E065), and the runtime throws a `LogicException` for it.
- One event sends at most 100 pushed items. The next refresh sends the others.

## Test a feed

`ComponentTester` applies the streams of the feeds as the browser does, and keeps the items in a morph:

```php
$page = $this->visit('/chat/lobby');
$page->fill('input[name="draft"]', 'Six')->submit('form');

$page->assertSelectorTextContains('[data-phem-feed] > li:last-child', 'Six');
```

`receive()` handles the pushes of a feed: it removes a deleted item, and sends the new items with the refresh (see [Test real-time updates](testing.md#test-real-time-updates)).
