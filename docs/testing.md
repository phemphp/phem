# Test components and pages

Phem ships PHPUnit helpers that test a component or a page without a browser. Your test clicks, types, and submits forms, then checks the HTML and the state. This guide covers setup, actions, assertions, real-time updates, async work, error pages, and tests with a real browser.

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

## Set up the test helpers

Prepare your app once before you write tests:

1. Install PHPUnit: `composer require --dev phpunit/phpunit`.
2. Set `framework.test: true` for the `test` environment. The Symfony recipe does this for you.
3. Run `phemc build` before the tests, because they use the compiled `.phem` files.
4. Use the `InteractsWithPhem` trait in a `KernelTestCase` or a `WebTestCase`.

A Composer package with `.phem` components has no app. Test it with `Phem\Test\TestKernel` and a small test app, as shown in [Test a package](packages.md#test-a-package).

## Choose between mount() and visit()

Use `mount()` for a unit test of one component. Use `visit()` to test a page and its security. They differ in these ways:

| | `mount(Component::class, $props)` | `visit('/url')` |
|---|---|---|
| What it renders | One component, with its props | A page, with its document |
| How events run | In the test process, the way the `EventController` runs them | As HTTP requests to the page URL, through the kernel |
| Page access rules | Don’t apply | Apply (a firewall, `access_control`, `#[IsGranted]`, `#[RateLimit]`) |
| Signed state | Signed and checked, as JSON | Signed and checked, as in the browser |
| Session | A new session for the test | The browser cookies (the session) go with each request and to the pages of `->visit()` |
| Redirect from a handler | `assertRedirect()` | `assertRedirect()`, then `followRedirect()` |

### Pass props and slots to mount()

`mount()` takes the component’s props. Pass a slot such as `children` as a `Phem\Html` value:

```php
$this->mount(Card::class, ['title' => 'Hello', 'children' => new Html('<p>Body</p>')]);
```

For a function component, pass the function name, for example `'App\Ui\Common\Badge'`.

## Simulate user actions

Each action finds an element with a CSS selector and sends the event the way the `phem` Stimulus controller does. The handler of the element (or of its closest ancestor) gets the event with the element’s value. The server’s answer then morphs the components that changed.

| Action | Event | Example |
|---|---|---|
| `click($selector)` | `onClick`. A submit button also submits its form (`onSubmit`). | `->click('button.add')` |
| `doubleClick($selector)` | `onDoubleClick` | `->doubleClick('li')` |
| `change($selector, $value)` | `onChange`, `onInput`, or `phem:live` | `->change('[name=q]', 'Curie')` |
| `fill($selector, $value)` | None: the next event sends the bound field (`phem:bind`) | `->fill('[name=q]', 'Curie')->click('button')` |
| `submit($selector, $fields)` | `onSubmit` | `->submit('form', ['name' => 'Ada'])` |
| `upload($selector, $paths)` | None: the next submit of its form sends the files | `->upload('[name=photo]', __DIR__.'/ada.jpg')->submit()` |
| `keyDown($selector, $key)`, `keyUp($selector, $key)` | `onKeyDown`, `onKeyUp` | `->keyDown('input', 'Enter')` |
| `focus($selector)`, `blur($selector)` | `onFocus`, `onBlur` | `->focus('input')` |
| `fire($selector, $type, $event)` | Any DOM event | `->fire('div', 'click', ['value' => 'x'])` |
| `load($selector)` | The load of a `<Suspense>` boundary | `->load()`, `->load('#report')` |
| `visit($uri, $cookies)` | None: opens a page in the same browser, with its cookies plus the ones you pass | `$customer->visit('/bag', ['currency' => 'EUR'])` |
| `receive()` | Pushes from other requests (a page with `#[Broadcast]`) | `$other->receive()->assertSee('Hi')` |
| `settle()` | None: runs the work of `#[Async]` handlers, as a worker does | `->settle()->receive()->assertSee('Ready')` |

### Fill fields and submit forms

`change()` and `fill()` take a value that matches the field type:

- **Text field** (`<input>`, `<textarea>`): a string
- **Checkbox**: `true` or `false`
- **Radio button**: `true`, or the value of a button in its group
- **`<select>`**: the value of an option, or a list of values for `<select multiple>`

`submit()` takes a form or the button that submits it, since the button is a field of the form. It sets the field values first, the way `change()` does. For a group of checkboxes (`tags[]`), pass a list of the values to check.

### Upload files

`upload()` takes a path, or a list of paths for `<input type="file" multiple>`. An empty list removes the files. The handler gets a copy of each file in the temporary directory, so it can move the file.

As in a browser, the files stay in the input after an event, even when the morph renders the input again. They’re cleared when the handler calls `$e->clearFiles()`, or when the page gets a new element for the input: a morph that gives the input another `id`, or a push that isn’t a morph.

### Load Suspense boundaries

The first render of a page shows its `<Suspense>` boundaries the way the browser has them at the end of the page stream. A boundary without `lazy` shows its children. A `lazy` boundary shows its fallback, and so does a streamed boundary whose children threw with no `error` slot.

`load()` sends the load event of each boundary that shows its fallback, in order. With a selector, it loads only that element’s boundary: the `<phem-suspense>` element, or an element in its fallback. Loaded children can contain boundaries too, so call `load()` again for them:

```php
$this->visit('/dashboard')
    ->assertSee('Loading the chart')
    ->load()
    ->assertSee('North: 1200 €');
```

## Test real-time updates

A page with `#[Broadcast]`, or with a component that has it, gets the pushes of `Phem\Stream\Streams` through `receive()`. Each call returns the updates of its topics since the render or since the last `receive()`. With `framework.test: true`, streams go to memory instead of a Mercure hub.

The tester follows browser behavior for pushes:

- A stream marks the component around its target as stale, and the next event sends it.
- A `refresh()` sends one event with no handler.
- After a handler of a component with `#[Broadcast]`, `receive()` on the same page doesn’t render that component again, because the event already rendered it.
- An element with `data-phem-ignore` and an `id` (a target that only pushes fill) keeps its content when an event renders it again.
- A feed element keeps its items in a morph, and the streams of the feeds apply after the morph (see [Show long lists with feeds](long-lists.md#test-a-feed)). A push of a feed removes a deleted item, or sends the new item with the refresh.

To see a push, open the page twice, like two tabs:

```php
$other = $this->visit('/guestbook');
$this->visit('/guestbook')->fill('[name=message]', 'Hi')->click('button');

$other->receive()->assertSee('Hi');
```

## Open pages in the same browser

Each `$this->visit()` starts a new browser with only the cookies you pass. To open a page in the same browser, with that page’s session (a signed-in user, for example), call `visit()` on the page instead. Like browser tabs, pages of one browser share cookies and server values (the `$server` of the first `visit()`), and each page gets its own pushes.

Cookies follow browser rules. A cookie goes only to its host (or its `Domain`) and its `Path`, and a `Secure` cookie only over HTTPS and to `localhost`. An empty `Domain` counts as no `Domain`, so the PHP session cookie (with the default `session.cookie_domain`) goes to the page’s host.

A relative URL resolves against the page URL, like a link. `$page->visit('/bag')` on `http://shop.example/login` opens `http://shop.example/bag`, and the relative redirect of `followRedirect()` works the same way.

### Sign in users and watch their pages

In this example the login page is a Phem page: its `onSubmit` handler signs the user in with `Security::login()` and returns a redirect. A Symfony `form_login` is a plain form with no Phem handler, so `submit()` can’t send it:

```php
$customer = $this->visit('/login')->submit('form', ['email' => 'ada@example.com'])->followRedirect();
$staff = $this->visit('/login')->submit('form', ['email' => 'staff@example.com'])->followRedirect();

$bag = $customer->visit('/bag');
$staff->visit('/admin/products/5')->click('#hide');

$bag->receive()->assertSee('The bag changed');
```

### Send cookies

Pass cookies to the browser with the `cookies` argument of `visit()`, as name-to-value strings. The two `visit()` methods take different arguments:

- **`$this->visit($uri, $server, $cookies)`**: the second argument is the server values and the headers of each request, for example `['HTTP_ACCEPT_LANGUAGE' => 'fr']`. Give the cookies with the named argument: `$this->visit('/shop', cookies: [...])`
- **`$page->visit($uri, $cookies)`**: the second argument is the cookies. The page uses the server values of its browser

Each cookie goes to the URL’s host for all paths (`Path=/`), as a session cookie. A `__Secure-` or `__Host-` cookie is `Secure`, the way a browser stores it.

A cookie you pass replaces a response cookie with the same name, host, and path `/`, and the next response that sets that cookie replaces yours. A response cookie with a longer `Path` stays, and the browser sends it on its path.

`cookies()` returns the cookies that the page’s browser sends to the page URL now, sorted by name. They include cookies that other pages of the same browser received:

```php
$page = $this->visit('/shop', cookies: ['currency' => 'EUR'])->assertSee('0,00 €');

$page->visit('/shop?set-currency=USD');
self::assertSame(['currency' => 'USD'], $page->cookies());

$page->visit('/bag', ['coupon' => 'SPRING'])->assertSee('Coupon SPRING');
self::assertSame(['coupon' => 'SPRING', 'currency' => 'USD'], $page->cookies());
```

A mounted component has no browser, so `cookies()` and `visit()` fail on it. A cookie value that isn’t a string, or a cookie with no name, throws an `InvalidArgumentException`.

## Test async handlers

An `#[Async]` handler doesn’t run its work during the event. In a page from `visit()`, its message goes to memory instead of a Messenger transport. Other tests (a `WebTestCase`, Panther) use the app’s Messenger configuration.

`settle()` runs the waiting work of every page in the test, in order, as a worker does. The work pushes its result, so read it with `receive()`. Test `#[Async]` handlers with `visit()`, because a mounted component has no page for the worker:

```php
$this->visit('/reports')
    ->click('button')
    ->receive()->assertSee('Building a report')
    ->settle()
    ->receive()->assertSee('4 scientists');
```

## Actions that fail the test

An action fails when it couldn’t work in a browser either. That happens when:

- No element matches the selector
- The element is disabled
- No handler gets the event
- The form has no field with that name, or the select has no option with that value
- `load()` finds no boundary that shows its fallback
- `receive()` runs on a page with no `#[Broadcast]` and no component with `#[Broadcast]`
- `settle()` finds no waiting work
- `upload()` gets an element that isn’t a file input, more than one path for an input without `multiple`, or a file that doesn’t exist

## Check the HTML and state

Each assertion returns the tester, so you can chain actions and assertions:

| Assertion | Checks |
|---|---|
| `assertSee($text)`, `assertDontSee($text)` | The text of the body (white space collapses to one space) |
| `assertSeeHtml($html)`, `assertDontSeeHtml($html)` | The HTML |
| `assertSelectorExists($selector)`, `assertSelectorNotExists($selector)` | An element |
| `assertSelectorCount($count, $selector)` | The number of elements |
| `assertSelectorTextContains($selector, $text)` | The text of the first element |
| `assertState($name, $value)` | A `#[State]` property of the page or of the mounted component |
| `assertState($name, $value, in: $selector)` | A `#[State]` property of a child component |
| `assertStatus($status)` | The HTTP status of the last response |
| `assertRedirect($url)`, `assertNoRedirect()` | A handler that returns a `RedirectTo` or a `RedirectResponse`, or a page that redirects (for example a `mount()` that returns a `RedirectTo`) |
| `assertReload()` | The last event answered 409, so the browser loads the page again |
| `assertQuery($key, $value)` | A query string key of the URL (`#[Url]`) after the last action: a string, a list, or null for no key |
| `assertDispatched($name)`, `assertDispatched($name, $detail)`, `assertNotDispatched($name)` | A browser event of the last action (`$e->dispatcher->dispatch()`), with its detail as JSON gives it |
| `assertToast($message)`, `assertToast($message, ToastType::Error)` | A toast of the last action (`$e->toast()` or `Phem\Toasts`), with its type, as the `phem-toast` controller shows it |

To read values directly, use `html()`, `text()`, `state()`, `url()`, `cookies()` (pages only), and `document()` (a `Dom\HTMLDocument`). `url()` returns the URL that the browser shows: the page URL (`/` for a component), with the query string of the `#[Url]` properties after the last action.

### Check the state of a child component

Pass a CSS selector in `in`. The tester finds the element, then the closest component around it that has `#[State]` properties, as in the browser DOM. A component without `#[State]` properties (a function component, the `<Root>`) doesn’t match. With no match, the assertion reads the page or the mounted component:

```php
$this->visit('/')
    ->click('#toggle')
    ->assertState('on', true, in: '#toggle')   // the Toggle component around the button
    ->assertState('count', 0);                 // the page
```

`state(in: '#toggle')` returns all the properties of that component.

## Test an error page

In a `KernelTestCase`, `errorPage()` renders the [error page](error-pages.md) for an error at a URL. It works like the preview at `/_error/{code}`, so the test’s debug mode doesn’t show the exception. An `int` stands for an `HttpException` with that status:

```php
use Phem\Test\InteractsWithPhem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ErrorPageTest extends KernelTestCase
{
    use InteractsWithPhem;

    public function testAPageOfTheAdminThatIsNotThereLinksToTheDashboard(): void
    {
        $this->errorPage(404, '/admin/missing')
            ->assertStatus(404)
            ->assertSee('Back to the dashboard');
    }
```

Pass an exception to test other statuses. The page must not show the exception message:

```php
    public function testAServerErrorOffersToTryAgain(): void
    {
        $this->errorPage(new \RuntimeException('Boom.'), '/cart?page=2')
            ->assertStatus(500)
            ->assertSelectorExists('a[href="/cart?page=2"]')
            ->assertDontSee('Boom.');
    }

    public function testATooManyRequestsErrorShowsTheWait(): void
    {
        $this->errorPage(new TooManyRequestsHttpException(60), '/verify')
            ->assertStatus(429)
            ->assertSee('Try again in 60 seconds.');
    }
}
```

When no error page matches the status and URL, `errorPage()` returns the Symfony page. Its request has a session, like a browser request. An error page has no events, so tester actions such as `click()` and `fill()` don’t work.

### Test error pages through visit() and end-to-end tests

Set `show_exceptions: false` in the `test` environment (see [Preview an error page in debug mode](error-pages.md#preview-an-error-page-in-debug-mode)). Then `visit()` of a failing URL shows the error page, and so do end-to-end tests through a web server or a real browser:

```php
$this->visit('/admin/missing')->assertStatus(404)->assertSee('Back to the dashboard');
```

`visit()` runs Symfony in web mode, like `WebTestCase`. In CLI mode, Symfony would render an HTTP error with its CLI renderer.

## Replace a service in a test

Components get their services from the test container. Change a service in the test, and the next event uses it. Here, the `Counter` gets a `Limits` service of the app, and its handler stops at the maximum of the service:

```php
final class Limits
{
    public int $max = 10;
}

// In Counter:
public function __construct(
    private readonly Limits $limits,
) {
}

public function increment(): void
{
    $this->count = min($this->count + 1, $this->limits->max);
}
```

The test sets the maximum to 1, so the second click doesn’t change the count:

```php
$counter = $this->mount(Counter::class);
self::getContainer()->get(Limits::class)->max = 1;
$counter->click('button')->click('button')->assertState('count', 1);
```

Before each event, the services reset (`kernel.reset`), as they do between two requests.

## Test with a real browser

A test with a real browser such as Playwright runs the page’s JavaScript. When the test process kernel handles the browser’s requests (as playwright-symfony does) with `framework.test: true`, Phem publishes streams to memory, and the browser has no Mercure hub. `Phem\Test\BrowserHub` acts as the hub for these tests. A browser with its own server process (Panther) doesn’t work, because the streams live in that process’s memory.

Wire the hub into your test with these steps:

1. Add `BrowserHub::initScript()` to each page before its scripts (Playwright `addInitScript()`). It replaces `EventSource` with a fake that connects at once. Without it, the page tries to connect to a hub that isn’t there.
2. Get the hub with `$this->browserHub()` from `InteractsWithPhem`, or with `new BrowserHub($publisher)` and the `phem.test.stream_publisher` service. It collects updates from that point on.
3. After each step that can publish, evaluate `$hub->deliverScript()` in each page. Every page gets the same script. Each connection gets each update once, for its topics, and only the updates published after it opened, as the real hub does. With no update, the script does nothing.
4. Wait until the root element has no `data-phem-push-pending` attribute, which means the page rendered the pushes and their refreshes. A push stays pending while it waits for the running event, while Turbo renders it, or while its refresh waits 50 ms (to group the refreshes that arrive together) or runs. Wait for the attribute instead of sleeping.

With a Playwright client for PHP (method names depend on the client):

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

A step that publishes outside a request, such as a command or a data fixture, must call `Streams::flush()` itself, because a request’s streams only go out at its end.

### Limits of the fake hub

The fake hub differs from a real Mercure hub in two ways:

- **No subscriber check**: It doesn’t check the subscriber key. A page gets the private updates of its topics even when the real hub wouldn’t authorize it, the same as `receive()`. Test topic access with a real hub.
- **Same machine**: It uses the time of each update to know whether a connection was open, so the browser and the test must run on the same machine.

The init script is for tests only. A page that loads it gets no pushes from a real hub.

## What the tester can’t do

The tester simulates a browser in PHP, so keep its limits in mind:

- It runs no JavaScript, so your app’s Stimulus controllers don’t run.
- A server error in `visit()`, or in one of its events, throws its exception in the test.
- `assertState(in: ...)` uses the DOM to find a child component. Slot content sits in the DOM of the component that renders the slot, so a selector inside a slot finds that component (when it has state), not the component that passes the slot.
