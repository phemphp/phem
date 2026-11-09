# Handle events

This guide shows you how to respond when the user clicks, types or changes a field. It covers handler arguments, services in handlers, loading states, the wait before an event, redirects, browser events for your JavaScript, and code that runs around every handler. For forms, see [Handle forms](forms.md). For the syntax of the `on*` attributes and the `phem:*` directives, see [The `.phem` syntax](phem-syntax.md).

## Respond to a click, a key or a change

Give a handler to an `on*` attribute, and the browser sends an event to the server when the user acts. With `onClick={{ save }}`, the server runs the `save` handler, renders the components that can change and returns only the HTML that changed. The browser then morphs that HTML into the page.

A handler can take a `Phem\Event` argument with these properties:

| Property | Value |
|---|---|
| `$e->value` | The value of the element |
| `$e->checked` | Whether a checkbox is checked |
| `$e->key` | The key of a key event |
| `$e->form` | The form fields, by name; a submit, a change or a click sends them (see [Map a form to an object](forms.md#map-a-form-to-an-object)) |
| `$e->files` | The files of the form (see [Accept file uploads](forms.md#accept-file-uploads)) |

`onChange` on a text field fires as the user types. The browser waits until the user stops typing for 150 ms before it sends the event (see [Control when typing sends an event](#control-when-typing-sends-an-event)).

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

A closure in `render()` receives only the event and the `Request`. Type its parameter, as in `fn (Event $e) => ...`, and inject services through the constructor.

## Show a loading state

While an event runs, the element that sent it and its component get `aria-busy="true"` and the `phem-loading` class after a 200 ms delay. Style them with CSS.

To show an element only while a handler runs, pass the handler to `phem:loading`:

```html
<button onClick={{ save }}>Save</button>
<span phem:loading={{ save }} className="spinner">Saving…</span>
```

The server renders the element with `hidden`, and the browser shows it while a `save` event of this component runs. With a call, the element shows only for the handler with these arguments: `<span phem:loading={{ remove(row.id) }}>` shows in the row that the user clicked. To show a component, wrap it in an element: `<span phem:loading={{ save }}><Spinner /></span>`.

Set the `data-phem-loading-delay-value` attribute on the `<Root>` element to change the delay.

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

To show a message to the user, call `$e->toast('Saved')`. See [Show toast messages](client-side.md#show-toast-messages).

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
