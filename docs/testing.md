# Testing components and pages

Phem has test helpers for PHPUnit. They test a component or a page without a browser. A test
clicks, types and submits forms, and then it checks the HTML and the state.

```php
use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CounterTest extends KernelTestCase
{
    use InteractsWithPhem;

    public function testIncrement(): void
    {
        $this->mount(Counter::class, ['label' => 'Clicks'])
            ->click('button')
            ->assertSee('Clicks 1 times')
            ->assertState('count', 1);
    }

    public function testCountersPage(): void
    {
        $this->visit('/counters')
            ->click('button')
            ->assertSee('Clicked 1 times');
    }
}
```

## Setup

1. Install PHPUnit: `composer require --dev phpunit/phpunit`.
2. Set `framework.test: true` for the `test` environment (the Symfony recipe does this).
3. Run `phemc build` before the tests. The tests use the compiled `.phpm` files.
4. Use the `InteractsWithPhem` trait in a `KernelTestCase` (or a `WebTestCase`).

## Two ways to test

| | `mount(Component::class, $props)` | `visit('/url')` |
|---|---|---|
| What it renders | one component, with its props | a page, with its document |
| How events run | in the test process, as the `EventController` runs them | HTTP requests to the URL of the page, through the kernel |
| Access rules of the page | do not apply | apply (a firewall, `access_control`, `#[IsGranted]`, `#[RateLimit]`) |
| Signed state | signed and checked, as JSON | signed and checked, as in the browser |
| Session | a new session for the test | the session cookie goes with each request |
| Redirect of a handler | `assertRedirect()` | `assertRedirect()`, then `followRedirect()` |

Use `mount()` for a unit test of a component. Use `visit()` for a test of a page and of its
security.

`mount()` takes the props of the component. Give a slot, such as `children`, as a `Phem\Html`
value:

```php
$this->mount(Card::class, ['title' => 'Hello', 'children' => new Html('<p>Body</p>')]);
```

A function component works too: give the name of the function (`'App\Component\Badge'`).

## Actions

An action finds an element with a CSS selector. Then it sends the event as the `phem` Stimulus
controller does: the handler of the element, or of its closest ancestor, with the value of the
element. The answer of the server morphs the components that changed.

| Action | Event | Example |
|---|---|---|
| `click($selector)` | `onClick`. A submit button also submits its form (`onSubmit`). | `->click('button.add')` |
| `doubleClick($selector)` | `onDoubleClick` | `->doubleClick('li')` |
| `change($selector, $value)` | `onChange`, `onInput`, or `phem:live` | `->change('[name=q]', 'Curie')` |
| `fill($selector, $value)` | none: the next event sends a bound field (`phem:bind`) | `->fill('[name=q]', 'Curie')->click('button')` |
| `submit($selector, $fields)` | `onSubmit` | `->submit('form', ['name' => 'Ada'])` |
| `upload($selector, $paths)` | none: the next submit of its form sends the files | `->upload('[name=photo]', __DIR__.'/ada.jpg')->submit()` |
| `keyDown($selector, $key)`, `keyUp($selector, $key)` | `onKeyDown`, `onKeyUp` | `->keyDown('input', 'Enter')` |
| `focus($selector)`, `blur($selector)` | `onFocus`, `onBlur` | `->focus('input')` |
| `fire($selector, $type, $event)` | any DOM event | `->fire('div', 'click', ['value' => 'x'])` |
| `load($selector)` | the load of a `<Suspense>` boundary | `->load()`, `->load('#report')` |
| `receive()` | the pushes of other requests (a page with `#[Subscribe]`) | `$other->receive()->assertSee('Hi')` |
| `settle()` | none: runs the work of the `#[Async]` handlers, as a worker does | `->settle()->receive()->assertSee('Ready')` |

The value of `change()` and `fill()`:

- a text field (`<input>`, `<textarea>`) takes a string;
- a checkbox takes `true` or `false`;
- a radio button takes `true`, or the value of a button of its group;
- a `<select>` takes the value of an option, or a list of values for `<select multiple>`.

`submit()` takes a form, or the button that submits it (the button is a field of the form). The
fields get the values first, as `change()` does. A group of checkboxes (`tags[]`) takes a list
of the values to check.

`upload()` takes a path, or a list of paths for `<input type="file" multiple>`. An empty list
removes the files. The handler gets a copy of each file in the temporary directory, so it can
move the file. As in a browser, the files stay in the input after an event, also when the morph
renders the input again.

The first render of a page with a `<Suspense>` boundary shows its fallback, as in a browser.
`load()` sends the load event of each boundary that shows its fallback, in their order. A
selector loads only the boundary of the element: the `<phem-suspense>` element, or an element in
its fallback. The loaded children can have boundaries too: call `load()` again.

```php
$this->visit('/dashboard')
    ->assertSee('Loading the sales report')
    ->load()
    ->assertSee('North: 1200 €');
```

A page with `#[Subscribe]` gets the pushes of `Phem\Stream\Streams` with `receive()`: the updates of
its topics since its render, or since the last `receive()`. In the tests (`framework.test: true`),
the streams go to memory, not to a Mercure hub. A stream marks the component around its target as
stale, and the next event sends it, as in a browser. A `refresh()` sends one event with no handler.
As in a browser, an element with `data-phem-ignore` and an `id` (a target that only the pushes fill)
keeps its content when an event renders it again. Open the page two times, as two tabs:

```php
$other = $this->visit('/guestbook');
$this->visit('/guestbook')->fill('[name=message]', 'Hi')->click('button');

$other->receive()->assertSee('Hi');
```

An `#[Async]` handler does not run its work in the event. In a page of `visit()`, its message
goes to memory, not to a Messenger transport. Other tests (a `WebTestCase`, Panther) use the
Messenger configuration of the application. `settle()` runs the waiting work of all the pages of the
test, in their order, as a worker does. The work pushes its result: get it with `receive()`. Test
an `#[Async]` handler with `visit()`: a mounted component has no page for the worker.

```php
$this->visit('/reports')
    ->click('button')
    ->receive()->assertSee('Building a report')
    ->settle()
    ->receive()->assertSee('4 scientists');
```

An action fails when it cannot work in a browser:

- no element matches the selector;
- the element is disabled;
- no handler gets the event;
- the form has no field with the name, or the select has no option with the value;
- `load()` finds no boundary that shows its fallback;
- `receive()` gets a page with no `#[Subscribe]`;
- `settle()` finds no waiting work;
- `upload()` gets an element that is not a file input, more than one path for an input with no
  `multiple` attribute, or a file that does not exist.

## Assertions

Each assertion returns the tester, so you can chain the actions and the assertions.

| Assertion | Checks |
|---|---|
| `assertSee($text)`, `assertDontSee($text)` | the text of the body (white space is one space) |
| `assertSeeHtml($html)`, `assertDontSeeHtml($html)` | the HTML |
| `assertSelectorExists($selector)`, `assertSelectorNotExists($selector)` | an element |
| `assertSelectorCount($count, $selector)` | the number of elements |
| `assertSelectorTextContains($selector, $text)` | the text of the first element |
| `assertState($name, $value)` | a `#[State]` property of the page, or of the mounted component |
| `assertState($name, $value, in: $selector)` | a `#[State]` property of a child component (see below) |
| `assertStatus($status)` | the HTTP status of the last response |
| `assertRedirect($url)`, `assertNoRedirect()` | a handler that returns a `RedirectTo` or a `RedirectResponse`, or a page that redirects |
| `assertReload()` | the last event answered 409: the browser loads the page again |
| `assertQuery($key, $value)` | a key of the query string of the URL (`#[Url]`), after the last action: a string, a list, or null for no key |
| `assertDispatched($name)`, `assertDispatched($name, $detail)`, `assertNotDispatched($name)` | a browser event of the last action (`$e->dispatcher->dispatch()`), and its detail as JSON gives it |

To read the values, use `html()`, `text()`, `state()`, and `document()` (a `Dom\HTMLDocument`).

### The state of a child component

Give a CSS selector in `in`. The tester finds the element, and then the closest component with
`#[State]` properties around it, as in the DOM of the browser. A component with no `#[State]`
property (a function component, the `<Root>`) does not match. With no match, the assertion
reads the page, or the mounted component.

```php
$this->visit('/')
    ->click('#toggle')
    ->assertState('on', true, in: '#toggle')   // the Toggle component around the button
    ->assertState('count', 0);                 // the page
```

`state(in: '#toggle')` gives all the properties of that component.

## Services

The components get their services from the container of the test. Change a service in the
test, and the next event uses it:

```php
$counter = $this->mount(Counter::class);
self::getContainer()->get(Limits::class)->max = 1;
$counter->click('button')->click('button')->assertState('count', 1);
```

Before each event, the services are reset (`kernel.reset`), as between two requests.

## Limits

- The tester runs no JavaScript. A Stimulus controller of the application does not run.
- A server error in `visit()`, or in one of its events, throws its exception in the test.
- `assertState(in: ...)` uses the DOM to find a child component. Slot content is in the DOM
  of the component that renders the slot. A selector in a slot finds that component (when it
  has state), not the component that gives the slot.
