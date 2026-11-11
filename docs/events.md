# Handle events

This guide shows you how to respond when the user clicks, types or changes a field. It covers drops and pastes, loads on view and polls, handler arguments, services in handlers, loading states, key filters and event modifiers, the wait before an event, redirects, browser events for your JavaScript, and code that runs around every handler. For forms, see [Handle forms](forms.md). For the syntax of the `on*` attributes and the `phem:*` directives, see [The `.phem` syntax](syntax.md).

## Respond to a click, a key or a change

Give a handler to an `on*` attribute, and the browser sends an event to the server when the user acts. With `onClick={{ save }}`, the server runs the `save` handler, renders the components that can change and returns only the HTML that changed. The browser then morphs that HTML into the page.

A handler can take a `Phem\Event` argument with these properties:

| Property | Value |
|---|---|
| `$e->type` | The type of the DOM event, such as `click`, `change` or `submit`, or `visible`, `poll` or `call` for the events that the browser makes itself |
| `$e->value` | The value of the element |
| `$e->checked` | Whether a checkbox is checked |
| `$e->key` | The key of a key event |
| `$e->form` | The form fields, by name; a submit, a change or a click sends them (see [Map a form to an object](forms.md#map-a-form-to-an-object)) |
| `$e->files` | The files of the form, or the list of the files of a drop or a paste (see [Accept file uploads](forms.md#accept-file-uploads)) |
| `$e->text` | The text of a drop or a paste, or `null` for other events |

By default, only the component of the handler, the components whose state changed and their children with new props render again. Call `$e->refresh()` in a handler to render the full page after it, for example when a form adds a row to a list in another component.

`onChange` on a text field fires as the user types. The browser waits until the user stops typing for 150 ms before it sends the event (see [Control when typing sends an event](#control-when-typing-sends-an-event)).

## Respond to a drop, a paste or a right-click

These events also go to the server: `onDragStart`, `onDragOver`, `onDrop`, `onPaste`, `onContextMenu`, `onPointerDown`, `onPointerUp` and `onScroll`. For the data of a JavaScript library, see [Call a handler from your JavaScript](#call-a-handler-from-your-javascript). For the full list, see [The `.phem` syntax](syntax.md#event-handlers).

A drop and a paste send their files. The handler gets them in `$e->files`, as a list of Symfony `UploadedFile` objects:

```php
#[Component]
final class PhotoDrop
{
    #[State] public array $names = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/photos')] private readonly string $photoDir,
    ) {
    }

    public function add(Event $e): void
    {
        foreach ($e->files as $file) {
            $file->move($this->photoDir, $file->getClientOriginalName());
            $this->names[] = $file->getClientOriginalName();
        }
    }

    public function render(): Node
    {
        return (
            <div>
                <div class="dropzone" onDrop={{ add }}>Drop the photos here</div>
                <textarea onPaste={{ add }}></textarea>
                <p>{{ names|join(', ') }}</p>
            </div>
        );
    }
}
```

The browser sends the drop as `multipart/form-data`, as a submit with files. The same limits apply (see [Limit the upload size](forms.md#limit-the-upload-size)). A drop or a paste with no files, such as a paste of text, sends an event with an empty `$e->files`. The handler gets the text in `$e->text`. A paste fires before the browser puts the text in the field, so `$e->value` is the old value of the field.

The browser cancels the default action of some events for you:

| Event | Default action |
|---|---|
| `onDrop` | Cancelled: the browser does not open the dropped file. The user can drop in the element and in its children. A drop of text with no files in a text field or an editable element keeps its default action: the text goes in the field. |
| `onContextMenu` | Cancelled: the browser does not show its own menu, so the server can render a menu. |
| `onPaste` | Kept: the pasted text goes in the field. |

`onDragOver` and `onScroll` fire many times each second. Only the element that scrolls gets its `onScroll`: a scroll does not bubble. While an event of the same handler waits in the queue, a new event takes its place, so the server gets the last event only. Add `phem:throttle` to send at most one event in a period (see [Control when typing sends an event](#control-when-typing-sends-an-event)):

```html
<div onScroll={{ track }} phem:throttle={{ 200 }}>…</div>
```

## Pass arguments to a handler

Call the handler in the `on*` attribute to give it values of the render. The call doesn’t run during the render: Phem keeps the handler and its arguments, and runs them when the user clicks:

```php
enum CancelReason: string
{
    case OutOfStock = 'out_of_stock';
    case CustomerAsked = 'customer_asked';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::OutOfStock => 'Out of stock',
            self::CustomerAsked => 'The customer asked',
            self::Duplicate => 'Duplicate order',
        };
    }
}

#[Component]
final class CancelOrder
{
    #[State] public ?CancelReason $reason = null;

    public function pickReason(?CancelReason $reason): void
    {
        $this->reason = $reason;
    }

    public function render(): Node
    {
        $reasons = CancelReason::cases();

        return (
            <div>
                {% for r in reasons %}
                    <button key={{ r.value }} aria-pressed={{ r == reason ? 'true' : 'false' }} onClick={{ pickReason(r) }}>{{ r.label }}</button>
                {% endfor %}
                <button onClick={{ pickReason(null) }}>Clear</button>
            </div>
        );
    }
}
```

A click on “Out of stock” runs `pickReason(CancelReason::OutOfStock)`, and a click on “Clear” runs `pickReason(null)`. The handler gets the enum case, not the string `'out_of_stock'`.

The arguments go to the first parameters of the handler, in order. The other parameters get the event, the services and the user, as before: `pickReason(?CancelReason $reason, Event $e)` also gets the event. An argument keeps its PHP value: an enum case stays an enum case, and an entity stays an entity.

The browser can’t change an argument. The browser sends only the handler id, and the id contains a fingerprint of the arguments. The server renders the component again and finds the handler with the same id. A handler that read `$e->value` from a `value` attribute had to check the value again. A handler with arguments doesn’t.

A call also works for a closure prop. The child adds its arguments after the arguments of the parent. Here, a shirt has a print on the front and on the back, and each print gets its own size picker:

```php
enum Size: string
{
    case Small = 's';
    case Large = 'l';
}

#[Component]
function SizePicker(Closure $onPick): Node
{
    $sizes = Size::cases();

    return <div>{% for size in sizes %}<button key={{ size.value }} onClick={{ onPick(size) }}>{{ size.name }}</button>{% endfor %}</div>;
}

#[Component]
final class ShirtOrder
{
    /** @var array<string, string> the size of each print */
    #[State] public array $sizes = [];

    public function pickSize(string $print, Size $size): void
    {
        $this->sizes[$print] = $size->value;
    }

    public function render(): Node
    {
        return (
            <div>
                <SizePicker onPick={{ pickSize('front') }} />
                <SizePicker onPick={{ pickSize('back') }} />
            </div>
        );
    }
}
```

A click on “Large” in the second picker runs `pickSize('back', Size::Large)`. The state keeps the value of the case (`'l'`): a property typed as one enum gets its case back after an event, but the items of an array stay strings.

`phemc` checks a call of a method of the class component (error E047): the number of arguments, an argument for an `Event` parameter, a named argument, and a literal of another type (`pickReason('out_of_stock')` gives a string to a `?CancelReason` parameter).

## Get services and the user in a handler

A handler receives its arguments the way a Symfony controller action does. The event is one of them, at any position, and you can leave it out. A class method can also take the `Request`, services, `#[CurrentUser]` and any other value resolver:

```php
public function save(Event $e, NoteRepository $notes, #[CurrentUser] User $user): void
{
    $notes->add($user, $e->value);
}
```

A closure in `render()` receives the same arguments, except the services: the event, the `Request`, `#[CurrentUser]`, `#[MapForm]` and the other value resolvers. Type its parameter, as in `fn (Event $e) => ...`, and inject services through the constructor.

## Show a loading state

While an event runs, the element that sent it and its component get `aria-busy="true"` and the `phem-loading` class after a 200 ms delay. Style them with CSS.

To show an element only while a handler runs, pass the handler to `phem:loading`:

```html
<button onClick={{ save }}>Save</button>
<span phem:loading={{ save }} className="spinner">Saving…</span>
```

The server renders the element with `hidden`, and the browser shows it while a `save` event of this component runs. With a call, the element shows only for the handler with these arguments: `<span phem:loading={{ remove(row.id) }}>` shows in the row that the user clicked. To show a component, wrap it in an element: `<span phem:loading={{ save }}><Spinner /></span>`.

Set the `data-phem-loading-delay-value` attribute on the `<Root>` element to change the delay.

## Filter keys and stop events

`onKeyDown` and `onKeyUp` send each key to the server. Add `phem:key` to send only some keys. The other keys send no event, and they keep their default action, so the user can still type in the field:

```php
#[Component]
final class Composer
{
    #[State] public array $sent = [];

    public function send(Event $e): void
    {
        $this->sent[] = $e->key;
    }

    public function render(): Node
    {
        return (
            <form>
                <textarea name="body" onKeyDown={{ send }} phem:key="ctrl+Enter meta+Enter"></textarea>
                <input onKeyDown={{ send }} phem:key="Escape" />
            </form>
        );
    }
}
```

The server renders the keys in a data attribute:

```html
<textarea name="body" data-phem-on-keydown="…" data-phem-key-filter="ctrl+Enter meta+Enter"></textarea>
<input data-phem-on-keydown="…" data-phem-key-filter="Escape">
```

Separate the keys with spaces. A key is the `key` of the browser event: one character such as `s` or `?`, or a name such as `Enter`, `Escape`, `Tab`, `ArrowUp` or `Space`. Put the modifiers before it: `ctrl+`, `shift+`, `alt+`, `meta+` (the Command key on a Mac) and `mod+` (the Command key on a Mac, an iPhone and an iPad, and Ctrl on other systems). `mod+` doesn’t go with `ctrl+` or `meta+` in one key. The names have no case. The modifiers must match: `Enter` doesn’t match Shift+Enter, so a user can still add a new line in the textarea. Shift is free for a character that isn’t a letter, such as `?`, which needs Shift on some keyboards. A value from an expression works too: `phem:key={{ shortcut }}`.

A click goes to the closest element with an `onClick`, and to no other one. A click on an element with no handler inside a row with `onClick` runs the handler of the row. Add `phem:stop` to an element to keep its events, and the events of its content, from the handlers around it:

```html
<tr onClick={{ open(order) }}>
    <td>{{ order.number }}</td>
    <td><button onClick={{ star(order) }} phem:stop>★</button></td>
    <td phem:stop><a href="{{ order.invoiceUrl }}">Invoice</a></td>
</tr>
```

A click on the star runs `star`, and a click on the invoice link opens the link: neither runs `open`. A click on the number runs `open`.

Add `phem:prevent` to cancel the default action of the event of a handler. The link runs `add` and doesn’t open its page, but it still works with no JavaScript:

```html
<a href="/cart/add/{{ product.id }}" onClick={{ add(product) }} phem:prevent>Add to cart</a>
```

A submit and a drop always cancel their default action. `phem:stop` and `phem:prevent` take a bool too: `phem:stop={{ inRow }}`. `phemc` checks the keys of `phem:key` (E032), and warns about `phem:key` with no `onKeyDown` or `onKeyUp`, and `phem:prevent` with no handler (W006).

## Add keyboard shortcuts

A command palette opens with `Cmd+K`, and `j` and `k` move in a list, where the focus is. Add `phem:shortcut` with the keys to the element that runs the action. A shortcut on the page clicks the element, as a user does: the click runs its `onClick` handler, opens its link, or opens the popup of a trigger. A field gets the focus:

```php
#[Component]
final class Board
{
    #[State] public int $selected = 0;

    public function next(): void
    {
        ++$this->selected;
    }

    public function previous(): void
    {
        $this->selected = max(0, $this->selected - 1);
    }

    public function render(): Node
    {
        return (
            <div>
                <input type="search" name="q" placeholder="Search (/)" phem:shortcut="/" />
                <button onClick={{ previous }} phem:shortcut="k ArrowUp">Previous</button>
                <button onClick={{ next }} phem:shortcut="j ArrowDown">Next</button>
                <div data-controller="phem-dialog">
                    <button data-phem-dialog-target="trigger" phem:shortcut="mod+k" aria-keyshortcuts="Meta+K Control+K">Commands</button>
                    <dialog data-phem-dialog-target="popup">…</dialog>
                </div>
            </div>
        );
    }
}
```

The server renders the keys in a data attribute:

```html
<input type="search" name="q" placeholder="Search (/)" data-phem-shortcut="/">
<button data-phem-on-click="…" data-phem-shortcut="k ArrowUp">Previous</button>
<button data-phem-on-click="…" data-phem-shortcut="j ArrowDown">Next</button>
<div data-controller="phem-dialog">
    <button data-phem-dialog-target="trigger" data-phem-shortcut="mod+k" aria-keyshortcuts="Meta+K Control+K">Commands</button>
    <dialog data-phem-dialog-target="popup">…</dialog>
</div>
```

`/` focuses the search field, `j` sends the `next` event, and `Cmd+K` (`Ctrl+K` on Windows and Linux) opens the dialog in the browser, with no round trip. The keys have the syntax of [`phem:key`](#filter-keys-and-stop-events). The key of a shortcut doesn’t do its default action: `Ctrl+K` doesn’t go to the search bar of the browser, and `/` doesn’t go in the field.

These rules keep a shortcut from running:

- A key with no `ctrl+`, `alt+`, `meta+` or `mod+` doesn’t run while the focus is on a control that uses the key: `j` is text in a field, `ArrowDown` changes a `<select>`, a radio button or a range, and `Enter` and `Space` activate a focused button or link. `Escape` runs.
- An element or a listener of the page that gets the key first, and cancels it, keeps it: an open menu or popover closes with `Escape`, and a shortcut on `Escape` doesn’t run.
- A held key runs the shortcut once, not again for each repeat.
- A disabled, hidden or inert element doesn’t run. While a modal dialog is open, only the shortcuts in the dialog that opened last run.

When two elements have the same shortcut, the first one that can run gets the key. Each element of a list can have the same shortcut: only the one that shows runs. A value from an expression works too: `phem:shortcut={{ keys }}`. `phemc` checks the keys (E032).

Phem doesn’t show the shortcut to the user. Add a `<kbd>` to the label, and `aria-keyshortcuts` for screen readers: `Meta+K` and `Control+K` for `mod+k`.

## Control when typing sends an event

The browser waits until the user stops typing for 150 ms before it sends the `onChange` and `onInput` events of a text field. Other events don’t wait. Change the wait for one element with `phem:debounce` or `phem:throttle`:

```html
<input onChange={{ search }} phem:debounce={{ 300 }} />
<input onChange={{ validate }} phem:debounce={{ 'blur' }} />
<input type="range" onInput={{ preview }} phem:throttle={{ 500 }} />
```

Each directive sends the event at a different moment:

| Attribute | The browser sends the event |
|---|---|
| `phem:debounce={{ 300 }}` | When no new event comes for 300 ms; `0` turns the wait off |
| `phem:debounce={{ 'blur' }}` | When the field loses focus |
| `phem:throttle={{ 500 }}` | Right away, then at most once every 500 ms; the last event always goes |

`onSubmit` and `onBlur` never wait. Any other event, such as a click or a submit, first sends every event that’s waiting, so the server always gets the input before the click. Set the `data-phem-debounce-value` attribute on the `<Root>` element to change the default wait.

## Redirect after a handler

Return a `Phem\RedirectTo` from a handler to send the browser to another page. The current page doesn’t render again, and the browser visits the new page with Turbo:

```php
use Phem\RedirectTo;

public function delete(): RedirectTo
{
    $this->scientists->remove($this->scientist);

    return new RedirectTo(ScientistsPage::class, ['page' => 2]);
}
```

Name a class page by its class, and a function page by its callable, such as `ScientistPage(...)` with `use function`. The second argument holds the route values, and any other value goes into the query string. The `headers` argument adds headers to the response. For a URL that isn’t a page, return a Symfony `RedirectResponse`.

In an event, the headers and the cookies of the redirect go with the answer of the event. The browser then visits the new page with a new request.

## Send events to your JavaScript

A handler can dispatch events to the JavaScript on the page:

```php
public function save(Event $e): void
{
    $this->scientists->save($this->scientist);
    $e->dispatcher->dispatch('saved', ['id' => $this->scientist->id]);
}
```

After the morph, the browser dispatches a `phem:saved` event. A Stimulus controller can listen for it:

```html
<div data-controller="chart" data-action="phem:saved@window->chart#reload"></div>
```

The event starts on the component’s element and bubbles up to `window`. A service can dispatch events too: inject `Phem\BrowserEventDispatcher`. A handler that fails or redirects dispatches no events.

The name has only letters, digits and `_ - . :`. The browser uses these names itself, so you can’t use them: `call`, `before-queue`, `queue`, `send`, `receive`, `done`, `error`, `rate-limited`, `upload-progress` and `clear-files`. The detail must be a value that JSON can encode. `dispatch()` throws an `InvalidArgumentException` for a name or a detail that breaks these rules.

To show a message to the user, call `$e->toast('Saved')`. See [Show toast messages](controllers.md#show-toast-messages). To tell only a screen reader, call `$e->announce()`. See [Announce a change to screen readers](#announce-a-change-to-screen-readers).

## Scroll to a new item after an event

Add `phem:scroll-into-view` to an element that an event adds, such as a new comment. After the morph of the event, the browser scrolls to the element:

```php
#[State] public ?int $added = null;

public function add(): void
{
    $this->added = $this->repository->add($this->draft)->id;
}
```

```php
<ul>
    {% for comment in comments %}
        <li key={{ comment.id }} phem:scroll-into-view={{ comment.id is same as(added) }}>{{ comment.text }}</li>
    {% endfor %}
</ul>
```

After `add`, the new item has the attribute:

```html
<li data-phem-key="7" data-phem-scroll-into-view>Hello</li>
```

The browser scrolls to the first element in the page that has the attribute now and did not have it before the morph. An element that keeps the attribute in the next events does not scroll again. Give a `key` to the items of a list: with no key, the morph can give the element of one item to another item, and the browser can scroll to an item again. The browser scrolls only as much as necessary (`block: "nearest"`), and the CSS `scroll-behavior` of the page sets the animation.

Only an event of the user scrolls. The browser does not scroll on page load, for a `<Suspense>` boundary that loads, for `onPoll` and `onVisible`, in a push, or in the refresh after a push: a change that the user did not start does not move the view. The focus does not move. To move the focus, see [Focus a field after an event](controllers.md#focus-a-field-after-an-event).

`phem:scroll-into-view` with no value always adds the attribute. `phem:scroll-into-view={{ expr }}` adds it only when the value is `true`.

## Announce a change to screen readers

A screen reader does not see all the changes of a morph, for example the new number of results of a search. Call `$e->announce()` to tell the user with no toast on the screen:

```php
public function search(Event $e): void
{
    $this->results = $this->products->search($this->query);
    $e->announce(\sprintf('%d results', \count($this->results)));
}
```

After the morph, the browser puts the message in a hidden live region. The region is in the `<body>` from the page load, out of the morph, because many screen readers do not read a live region that a page just added:

```html
<div data-phem-announcer role="status" aria-live="polite" style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); …">
    <p>3 results</p>
</div>
```

The browser removes the old text first, so a screen reader also reads the same message again. The messages of one event show together. The region is polite: the screen reader waits until the user stops.

A component or a service that the handler calls injects `Phem\Announcements`:

```php
public function __construct(private readonly Announcements $announcements) {}

$this->announcements->announce('3 results');
```

`announce()` sends the `announce` browser event with `{"message": "3 results"}`. An empty message throws an `InvalidArgumentException`. A handler that fails or redirects announces nothing. In a test, check the message with `assertAnnounced('3 results')`.

## Call a handler from your JavaScript

A JavaScript library can call a handler with its own data, for example a sortable list ("card 12 moved to column 3 at position 0"), a rich text editor or a map. Put an `onCall` handler on an element, and dispatch a `phem:call` event on the element or on an element in it. The handler gets the detail of the event in `$e->detail`:

```php
#[Component]
final class Board
{
    #[State] public array $cards = ['a', 'b', 'c'];

    public function move(Event $e): void
    {
        // The browser makes the detail: check it.
        $card = $e->detail['card'] ?? null;
        $index = $e->detail['index'] ?? null;
        if (!\is_string($card) || !\is_int($index) || !\in_array($card, $this->cards, true)) {
            return;
        }

        $cards = array_values(array_diff($this->cards, [$card]));
        array_splice($cards, max(0, min($index, \count($cards))), 0, [$card]);
        $this->cards = $cards;
    }

    public function render(): Node
    {
        return (
            <ul data-controller="sortable" onCall={{ move }}>
                {% for card in cards %}
                    <li key={{ card }}>{{ card }}</li>
                {% endfor %}
            </ul>
        );
    }
}
```

The Stimulus controller of the library dispatches the event when the user drops a card:

```js
this.element.dispatchEvent(new CustomEvent("phem:call", { detail: { card: "c", index: 0 } }))
```

The list renders with a `data-phem-on-call` attribute. The request of the event has the detail as JSON:

```html
<ul data-controller="sortable" data-phem-on-call="…">…</ul>
```

```json
{"type": "call", "value": null, "checked": null, "key": null, "form": null, "detail": {"card": "c", "index": 0}}
```

The event needs no `bubbles: true`: the page root listens to `phem:call` in the capture phase. The closest element with an `onCall` handler gets it, as for the other events, and `phem:stop` keeps it from the handlers around an element. The detail must be a value that JSON can encode. The browser takes a copy of it at the time of the call, so a later change of the object does not change the event. A detail that JSON can’t encode, such as an object with a cycle, sends no event, and the browser shows an error in the console. With no detail, `$e->detail` is `null`. A `phem:call` with no `onCall` handler on the element or around it sends no event, and the browser shows a warning in the console. The warning also tells when a `phem:stop` element keeps the event from the handler, or when the page has no state yet.

For a sortable list, the built-in `phem-sortable` controller dispatches this event on a drop (see [Sort a list with drag and drop](controllers.md#sort-a-list-with-drag-and-drop)).

The browser makes the detail, so a user can change it. Check it in the handler, as you check `$e->value`. The event goes in the queue as the other events: `phem:debounce`, `phem:throttle`, `phem:loading` and `phem:before-queue` work the same. Its type is `call`.

A handler can’t dispatch a `phem:call` browser event: `$e->dispatcher->dispatch('call')` throws an `InvalidArgumentException`. In a test, `call()` sends the event also to a disabled element, as the browser does.

## Load more when an element comes into view

Put an `onVisible` handler on an element, and the browser sends the event when the element comes into view. Use it as the trigger of an infinite scroll: put the element after the last item, and remove it when the server has no more items.

```php
#[Component]
final class Timeline
{
    /** @var array<int, string> the titles of the posts, by id */
    #[State] public array $posts = [];
    #[State] public bool $hasMore = true;

    public function __construct(private readonly PostRepository $repository)
    {
    }

    public function more(): void
    {
        $next = $this->repository->titles(offset: \count($this->posts), limit: 20);
        $this->posts += $next;
        $this->hasMore = 20 === \count($next);
    }

    public function render(): Node
    {
        return (
            <ul>
                {% for id, title in posts %}
                    <li key={{ id }}>{{ title }}</li>
                {% endfor %}
                {% if hasMore %}<li onVisible={{ more }}>Loading…</li>{% endif %}
            </ul>
        );
    }
}
```

The trigger renders with a `data-phem-on-visible` attribute, and the event has the type `visible`:

```html
<li data-phem-on-visible="…">Loading…</li>
```

```json
{"type": "visible", "value": null, "checked": null, "key": null, "form": null}
```

The browser sends the event each time the element comes into view, and one time only while it stays in view. After the update of the event, an element that is still in view sends the event again, but only when the update moved it, for example with new items before it. So the list loads until the items fill the view, and an update with no new items stops the loads. A failed event does not load again. An update that adds items after the element, such as older messages below a trigger at the top of a chat, does not move it: the element sends the event again only when it leaves the view and comes back. For a long list whose items stay in the browser, use a feed: see [Load older items](feeds.md#load-older-items).

The browser sends the event of the element itself: an element in it does not send it, and `phem:stop` does not change it. A morph or a push can show a new `onVisible` element, and the browser watches it too.

## Poll the server

Put an `onPoll` handler and a `phem:poll` interval in milliseconds on an element, and the browser sends the event at this interval. Use it for a status or a "last seen" value when the application has no Mercure hub (see [Real-time updates](real-time.md)):

```php
#[Component]
final class ExportStatus
{
    #[Prop] public int $export;
    #[State] public string $status = 'running';

    public function __construct(private readonly ExportRepository $exports)
    {
    }

    public function refresh(): void
    {
        $this->status = $this->exports->status($this->export);
    }

    public function render(): Node
    {
        $interval = 'done' === $this->status ? null : 2000;

        return (
            <p onPoll={{ refresh }} phem:poll={{ interval }}>Export: {{ status }}</p>
        );
    }
}
```

```html
<p data-phem-on-poll="…" data-phem-poll="2000">Export: running</p>
```

The interval starts at the end of the last poll, so the polls of a slow server do not wait in the queue. The polls stop while the tab is hidden (`visibilitychange`) and while the browser is offline. When the tab is visible again, a poll whose interval passed goes at once. With `null` or `false`, the element renders no interval and does not poll: above, the polls stop when the export is done. A morph that changes the interval starts the poll again with the new interval.

The interval is a whole number of milliseconds, 1 or more. `phemc` checks a literal value (E032), and the runtime throws an `InvalidArgumentException` for another value. `phemc` warns about `phem:poll` with no `onPoll`, and `onPoll` with no `phem:poll` (W006).

`onVisible` and `onPoll` events go in the queue as the other events: `phem:debounce`, `phem:throttle`, `phem:loading` and `phem:before-queue` work the same. `phem:debounce={{ 'blur' }}` does not apply: the event goes at once. An event of the same element that waits in the queue does not go two times. A listener of `phem:before-queue` that cancels one of these events drops it: `detail.resume()` does not send it, and the next view or the next poll sends a new event. In a test, `visible()` and `poll()` send the events (see [Simulate user actions](testing.md#simulate-user-actions)).

## Run code around every handler

A handler middleware runs code around each handler, such as a security check, a log entry or a metric. Create a service that implements `Phem\Handler\HandlerMiddleware`:

```php
final class LoginRequired implements HandlerMiddleware
{
    public function __construct(
        private readonly Security $security,
    ) {
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

The `RedirectTo` middleware (priority 10) changes a returned `RedirectTo` into the redirect response. A middleware with a priority above 10 runs outside it, so its `RedirectTo` does nothing. In such a middleware, return a Symfony `RedirectResponse`.

### Order your middleware with priorities

The tag priority sets the order, and a higher priority runs first, around the others. The built-in middleware uses these priorities:

| Priority | Built-in middleware |
|---|---|
| 100 | `#[RateLimit]` |
| 50 | The Symfony profiler (debug only) |
| 10 | `RedirectTo` |
| 0 | The pushes of `Streams` |
| -50 | `#[Validated]` |
| -60 | `#[MapForm]` |
| -100 | `#[Transactional]` |
| -200 | `#[Async]` |
| -1000 | `#[Broadcast]` |

The default priority is 0, outside the transaction. To run inside the transaction, set a priority between -100 and -200 with `#[AsTaggedItem(priority: -150)]`.
