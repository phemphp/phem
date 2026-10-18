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
| Access rules of the page | do not apply | apply (a firewall, `access_control`, `#[IsGranted]`) |
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

A function component works too: give the name of the function (`'App\Components\Badge'`).

## Actions

An action finds an element with a CSS selector. Then it sends the event as the `phem` Stimulus
controller does: the handler of the element, or of its closest ancestor, with the value of the
element. The answer of the server morphs the components that changed.

| Action | Event | Example |
|---|---|---|
| `click($selector)` | `onClick`. A submit button also submits its form (`onSubmit`). | `->click('button.add')` |
| `doubleClick($selector)` | `onDoubleClick` | `->doubleClick('li')` |
| `change($selector, $value)` | `onChange`, `onInput` | `->change('[name=q]', 'Curie')` |
| `submit($selector, $fields)` | `onSubmit` | `->submit('form', ['name' => 'Ada'])` |
| `keyDown($selector, $key)`, `keyUp($selector, $key)` | `onKeyDown`, `onKeyUp` | `->keyDown('input', 'Enter')` |
| `focus($selector)`, `blur($selector)` | `onFocus`, `onBlur` | `->focus('input')` |
| `fire($selector, $type, $event)` | any DOM event | `->fire('div', 'click', ['value' => 'x'])` |

The value of `change()`:

- a text field (`<input>`, `<textarea>`) takes a string;
- a checkbox takes `true` or `false`;
- a radio button takes `true`, or the value of a button of its group;
- a `<select>` takes the value of an option, or a list of values for `<select multiple>`.

`submit()` takes a form, or the button that submits it (the button is a field of the form). The
fields get the values first, as `change()` does. A group of checkboxes (`tags[]`) takes a list
of the values to check.

An action fails when it cannot work in a browser:

- no element matches the selector;
- the element is disabled;
- no handler gets the event;
- the form has no field with the name, or the select has no option with the value.

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
| `assertStatus($status)` | the HTTP status of the last response |
| `assertRedirect($url)`, `assertNoRedirect()` | a handler that returns a `RedirectResponse`, or a page that redirects |
| `assertReload()` | the last event answered 409: the browser loads the page again |

To read the values, use `html()`, `text()`, `state()`, and `document()` (a `Dom\HTMLDocument`).

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
- `assertState()` reads the state of the page or of the mounted component, not of a child
  component. Check the HTML of a child component.
