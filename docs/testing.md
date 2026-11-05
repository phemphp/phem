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

A Composer package with `.phpm` components has no app: test it with `Phem\Test\TestKernel` and
a small test app (see [Test a package](packages.md#test-a-package)).

## Two ways to test

| | `mount(Component::class, $props)` | `visit('/url')` |
|---|---|---|
| What it renders | one component, with its props | a page, with its document |
| How events run | in the test process, as the `EventController` runs them | HTTP requests to the URL of the page, through the kernel |
| Access rules of the page | do not apply | apply (a firewall, `access_control`, `#[IsGranted]`, `#[RateLimit]`) |
| Signed state | signed and checked, as JSON | signed and checked, as in the browser |
| Session | a new session for the test | the cookies of the browser (the session) go with each request, and to the pages of `->visit()` |
| Redirect of a handler | `assertRedirect()` | `assertRedirect()`, then `followRedirect()` |

Use `mount()` for a unit test of a component. Use `visit()` for a test of a page and of its
security.

`mount()` takes the props of the component. Give a slot, such as `children`, as a `Phem\Html`
value:

```php
$this->mount(Card::class, ['title' => 'Hello', 'children' => new Html('<p>Body</p>')]);
```

A function component works too: give the name of the function (`'App\Ui\Common\Badge'`).

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
| `visit($uri)` | none: opens a page in the same browser, with its cookies | `$customer->visit('/bag')` |
| `receive()` | the pushes of other requests (a page with `#[Broadcast]`) | `$other->receive()->assertSee('Hi')` |
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
renders the input again. They go away when the handler calls `$e->clearFiles()`, or when the
page gets a new element for the input: a morph that gives the input another `id`, or a push that
is not a morph.

The first render of a page shows its `<Suspense>` boundaries as the browser has them at the end
of the stream of the page: a boundary with no `lazy` shows its children. A `lazy` boundary shows
its fallback, and so does a streamed boundary whose children threw with no `error` slot.
`load()` sends the load event of each boundary that shows its fallback, in their order. A
selector loads only the boundary of the element: the `<phem-suspense>` element, or an element in
its fallback. The loaded children can have boundaries too: call `load()` again.

```php
$this->visit('/dashboard')
    ->assertSee('Loading the chart')
    ->load()
    ->assertSee('North: 1200 €');
```

A page with `#[Broadcast]` (or with a component with `#[Broadcast]`) gets the pushes of
`Phem\Stream\Streams` with `receive()`: the updates of its topics since its render, or since the
last `receive()`. In the tests (`framework.test: true`), the streams go to memory, not to a
Mercure hub. A stream marks the component around its target as stale, and the next event sends
it, as in a browser. A `refresh()` sends one event with no handler. As in a browser, after a handler of
a component with `#[Broadcast]`, the `receive()` of the same page does not render that component
again: the event rendered it.
As in a browser, an element with `data-phem-ignore` and an `id` (a target that only the pushes fill)
keeps its content when an event renders it again. Open the page two times, as two tabs:

```php
$other = $this->visit('/guestbook');
$this->visit('/guestbook')->fill('[name=message]', 'Hi')->click('button');

$other->receive()->assertSee('Hi');
```

Each `$this->visit()` is a new browser, with no cookies. To open a page in the same browser,
with the session of a page (for example a user who logged in), call `visit()` on that page. As
the tabs of a browser, the pages of one browser share the cookies and the server values (the
`$server` of the first `visit()`), and each page gets its pushes apart. A cookie goes only to
its host (or its `Domain`) and its `Path`, and a `Secure` cookie only on HTTPS and on
`localhost`.

Here the login page is a Phem page: its `onSubmit` handler logs the user in
(`Security::login()`) and returns a redirect. A Symfony `form_login` is a normal form with no
Phem handler, so `submit()` cannot send it.

```php
$customer = $this->visit('/login')->submit('form', ['email' => 'ada@example.com'])->followRedirect();
$staff = $this->visit('/login')->submit('form', ['email' => 'staff@example.com'])->followRedirect();

$bag = $customer->visit('/bag');
$staff->visit('/admin/products/5')->click('#hide');

$bag->receive()->assertSee('The bag changed');
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
- `receive()` gets a page with no `#[Broadcast]` and no component with `#[Broadcast]`;
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

To read the values, use `html()`, `text()`, `state()`, `url()`, and `document()` (a
`Dom\HTMLDocument`). `url()` gives the URL that the browser shows: the URL of the page (`/` for a
component), with the query string of the `#[Url]` properties after the last action.

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

## Tests with a real browser

A test with a real browser (Playwright) runs the JavaScript of the page. When the kernel of the
test process handles the requests of the browser (as playwright-symfony does), with
`framework.test: true`, Phem publishes the streams to memory, and the browser has no Mercure hub.
`Phem\Test\BrowserHub` is the hub for these tests. A browser with its own server process
(Panther) does not work: the streams are in the memory of that process.

1. Add `BrowserHub::initScript()` to each page, before its scripts (Playwright
   `addInitScript()`). It replaces `EventSource` with a fake that connects at once. A page with
   no init script tries to connect to a hub that is not there.
2. Get the hub with `$this->browserHub()` of `InteractsWithPhem` (or `new BrowserHub($publisher)`
   with the `phem.test.stream_publisher` service). It takes the updates from now.
3. After each step that can publish, evaluate `$hub->deliverScript()` in each page. The same
   script goes to each page: each connection gets each update one time, for its topics, and
   only the updates published after it opened, as the hub does. With no update, the script does
   nothing.
4. Wait until the root element has no `data-phem-push-pending` attribute: the page rendered the
   pushes and their refreshes. A refresh waits 50 ms before it goes, so do not sleep: wait for
   the attribute.

With a Playwright client for PHP (the method names depend on the client):

```php
$hub = $this->browserHub();
$customer->addInitScript(BrowserHub::initScript());
$staff->addInitScript(BrowserHub::initScript());
$customer->goto('/bag');
$staff->goto('/admin/products/5');

// The staff hides a product: a listener of the application pushes a refresh of the bags.
$staff->click('#hide');

foreach ([$customer, $staff] as $page) {
    $page->evaluate($hub->deliverScript());
    $page->waitForSelector('[data-controller~="phem"]:not([data-phem-push-pending])');
}

$customer->assertSee('The bag changed');
```

A step that publishes out of a request (a command, a data fixture) calls `Streams::flush()`
itself: the streams of a request go out at its end. The fake hub has two limits:

- It does not check the subscriber key: a page gets the private updates of its topics also when
  the real hub would not authorize it, as `receive()` does. Test the access to the topics with
  a real hub.
- It uses the time of each update to know if a connection was open: the browser and the test
  must run on the same machine.

The init script is for tests only: a page with it gets no pushes from a real hub.

## Limits

- The tester runs no JavaScript. A Stimulus controller of the application does not run.
- A server error in `visit()`, or in one of its events, throws its exception in the test.
- `assertState(in: ...)` uses the DOM to find a child component. Slot content is in the DOM
  of the component that renders the slot. A selector in a slot finds that component (when it
  has state), not the component that gives the slot.
