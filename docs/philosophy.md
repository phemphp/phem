# Philosophy and conventions

This document gives the ideas behind Phem, and the rules that come from them. Keep them in mind
when you write a component or design a page. Each rule has an example. For the full syntax, see
[The `.phpm` syntax](phpm-syntax.md).

## 1. The server owns the UI

The handlers run on the server. The browser only sends events and morphs the HTML that comes
back. The server keeps no session between events: the browser keeps the signed state.

```php
#[Component]
final class LikeButton
{
    #[State]
    public bool $liked = false;

    // Runs on the server. Phem renders the component again and morphs it in the browser.
    public function toggle(): void
    {
        $this->liked = !$this->liked;
    }

    public function render(): Node
    {
        return <button onClick={{ toggle }}>{{ liked ? '♥ Liked' : '♡ Like' }}</button>;
    }
}
```

Thus there is no client store, no API layer and no JSON endpoint to write. Each event is a round
trip, so design for it (see [rule 10](#10-design-for-the-round-trip)).

## 2. Use the smallest form of a component

Start with the simplest form. Move to the next form only when you need it.

| You need | Use |
|---|---|
| markup only | a function whose body is only `return <markup>;` (a template component: plain Twig, no PHP call) |
| props and some PHP | a `#[Component]` function |
| state, handlers or services | a `#[Component]` class |
| a URL | `#[Page]` on a function or a class |

```php
// A template component: the fastest form.
#[Component]
function Badge(string $label): Node
{
    return <span className="badge">{{ label }}</span>;
}

// A function page: it has no state of its own. Its components can have state.
#[Page('/about')]
function About(): Node
{
    return <Document><h1>About us</h1><LikeButton /></Document>;
}
```

A function without `#[Component]` is a plain PHP function. Use it for helpers.

## 3. Keep `render()` pure

The output of `render()` depends only on the props, the `#[State]` properties, the `#[Context]`
properties, and the properties that `mount()` or a handler set in the same request. Phem uses
this rule to render only the components that can change.

```php
// Not correct: render() calls a service (W008).
public function render(): Node
{
    $unread = $this->messages->countUnread();

    return <span>{{ unread }}</span>;
}

// Correct: mount() reads the service. render() reads the state.
#[State] public int $unread = 0;

public function mount(): void
{
    $this->unread = $this->messages->countUnread();
}

public function render(): Node
{
    return <span>{{ unread }}</span>;
}
```

Do not read the clock, random values, the session, superglobals or static properties in
`render()`. When a handler changes data that other components show, call `$e->refresh()`.

## 4. State is UI state, not trusted data

The state lives in the browser. The server signs it, but the browser can send back an older
state that the server signed. State values are JSON values (scalars, arrays) or backed enums.

```php
// Not correct: a user can send back an older state and vote again.
#[State] public int $votesLeft = 3;

// Correct: the database keeps the limit. The state keeps only what the UI shows.
#[State] public bool $showResults = false;

#[Transactional]
public function vote(Event $e): void
{
    $this->poll->vote($e->value);   // the model checks the limit
}
```

Keep in `#[State]` only what you can lose or undo: an open panel, a tab, a search term, a draft.
Put filters, tabs and page numbers in the URL with `#[Url]`, so a reload and a shared link keep
them.

## 5. Data goes down, events go up

Pass data to a child with props. Pass a handler to let the child change the data of its parent.
When two components show the same data, lift the data up to their common parent.

```php
#[Component]
function MyButton(int $count, Closure $onClick): Node
{
    return <button onClick={{ onClick }}>Clicked {{ count }} times</button>;
}

// In the parent: one state, two buttons that update together.
<MyButton count={{ count }} onClick={{ handleClick }} />
<MyButton count={{ count }} onClick={{ handleClick }} />
```

Choose the correct channel for a value:

| The value | Channel |
|---|---|
| comes from the parent | a prop |
| depends on the position in the tree (a theme, the nearest `<Tabs>`) | `#[Provide]` and `#[Context]` |
| is the same for the full request (the current user, a repository) | a service in the constructor |

```php
// A service, not a context: the repository is the same in all the tree.
public function __construct(private readonly TodoRepository $repository)
{
}
```

## 6. Compute in PHP, show in Twig

The markup is Twig. Prepare the values in PHP before the `return`, and keep the markup simple.

```php
// Not correct: logic in the markup.
return <p>{{ (order.items|reduce((s, i) => s + i.price * i.qty, 0)) * 1.21 }}</p>;

// Correct: PHP computes the value. The markup shows it.
$total = $this->order->totalWithTax();

return <p>{{ total }}</p>;
```

A markup literal is a value (`Phem\Element`). Keep it in a variable, put it in an array, or
return it. Do not cast it to a string in PHP.

```php
$content = $isLoggedIn ? <AdminPanel /> : <LoginForm />;

return <div>{{ content }}</div>;
```

## 7. Pass a handler, do not call it

`onClick={{ save }}` passes the method. The object of the handler is its owner: it renders again
after the event. A closure captures its values, so the id of a handler stays correct when the
data changes.

```php
// Correct: each row gets its own handler, with its own captured value.
$rows = array_map(function (Scientist $s): Node {
    $remove = fn () => $this->remove($s);

    return <li key={{ s.id }}><button onClick={{ remove }}>Remove</button></li>;
}, $this->scientists);

return <ul>{{ rows }}</ul>;
```

Give a `key` to each item of a list. The browser then keeps the correct element (its focus, its
Stimulus controller) when an item before it goes away.

## 8. Symfony does the rest

Phem adds a component model. It does not replace Symfony. Use the Symfony tool for each job:

- **Routes:** `#[Page]` extends `#[Route]`, with the same options.
- **Security:** an event is a `POST` to the URL of the page, so `access_control`, the firewall and
  `#[IsGranted]` apply to the events too.
- **Services:** a class component is an autowired service. A new object is made for each
  component instance.
- **Forms:** use a Symfony form, and call `$form->handleRequest($request)` in the handler.
- **Doctrine:** use `#[Transactional]`. Phem flushes and commits after the handler. Do not call
  `flush()`.

```php
#[IsGranted('ROLE_ADMIN')]
#[Page('/admin/users/{id:user}')]
final class EditUser
{
    #[Path] public User $user;   // Doctrine finds the entity

    #[Transactional]
    public function rename(Event $e): void
    {
        $this->user->name = $e->form['name'];   // saved at the commit
    }

    // ...
}
```

## 9. The browser does only browser work

Some changes need no server: open a menu, toggle a class, give the focus to a field. Write them as
Stimulus controllers. Tell the morph which attributes the client owns.

```html
<span data-controller="dropdown">
    <button data-action="dropdown#toggle" data-phem-client="aria-expanded" aria-expanded="false">⋯</button>
    <span data-dropdown-target="menu" data-phem-client="hidden" hidden>
        <button onClick={{ star }}>Star</button>
    </span>
</span>
```

Use `data-phem-ignore` for the element of a JS library (a chart, a map, an editor). Use the
built-in controllers first (`phem-toggle`, `phem-optimistic`, `phem-confirm`, ...) before you
write a new one.

## 10. Design for the round trip

Each event goes to the server. The cost grows with the size of the tree on the path to the
handler. Keep the page fast:

- Show a long list in pages. A click on one row runs the `render()` of the full list.
- Put a slow part in a `<Suspense>` boundary, so the page does not wait for it.
- Put a part that can fail in an `<ErrorBoundary>`, so one widget does not break the page.
- Use `#[Async]` for slow work after the response.

```php
<Suspense lazy>
    <Suspense:fallback><p>Loading the chart…</p></Suspense:fallback>
    <ErrorBoundary>
        <ErrorBoundary:fallback><p>The chart is not available now.</p></ErrorBoundary:fallback>
        <ActivityChart />
    </ErrorBoundary>
</Suspense>
```

## 11. Let the compiler find the errors

`phemc` checks the markup at build time: closed tags, one root, known components, props and
slots, and impure renders. Treat a warning as a defect. Run the check in CI:

```sh
vendor/bin/phemc check src
```

Give each component a correct type for each prop, and the return type `Node`. The compiler,
PHP and PHPStan then check the calls for you.

## 12. Test without a browser

Test a component with `mount()`, and a page (with its security) with `visit()`. The test clicks,
types and checks the HTML and the state.

```php
$this->mount(Counter::class, ['label' => 'Clicks'])
    ->click('button')
    ->assertSee('Clicks 1 times')
    ->assertState('count', 1);
```

See [Testing components and pages](testing.md).

## Checklist

Before you finish a component, ask these questions:

- Is this the smallest form of the component that does the job?
- Does `render()` only read props, state and context?
- Can a user lose or replay each `#[State]` value with no risk?
- Do the filters, tabs and page numbers survive a reload (`#[Url]`)?
- Is each value in the correct channel: a prop, a context or a service?
- Does each item of a list have a `key`?
- Does the database, not the state, keep each limit and each security decision?
- Does `phemc check` report no diagnostics?
