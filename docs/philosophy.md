# Design components that fit how Phem works

Phem rests on a few ideas: the server owns the UI, state is disposable UI state, and Symfony does everything that isn’t a component. Each rule on this page follows from one of those ideas and comes with an example, so you can apply it when you write a component or design a page. The page ends with a checklist to review a component before you finish it. For the syntax itself, read [The `.phem` syntax](phem-syntax.md).

## Let the server own the UI

In Phem, the server decides what the page shows, and the browser only displays it. Event handlers run on the server; the browser sends events and morphs the HTML that comes back. The server keeps no session between events, because the browser carries the signed state.

```php
#[Component]
final class LikeButton
{
    #[State]
    public bool $liked = false;

    public function toggle(): void
    {
        $this->liked = !$this->liked;
    }

    public function render(): Node
    {
        return (
            <button onClick={{ toggle }}>
                {{ liked ? '♥' : '♡' }}
            </button>
        );
    }
}
```

When you click the button, `toggle()` runs on the server, Phem renders the component again, and the browser morphs the new button into the page. You don’t write a client store, an API layer, or a JSON endpoint. The trade-off is that every event is a round trip, so plan for it (see [Design for the round trip](#design-for-the-round-trip)).

## Use the smallest form of a component

Each component form costs a bit more than the one before it, so start small and move up only when you need to:

| You need | Use |
|---|---|
| markup only | a function whose body is only `return <markup>;` (a template component: plain Twig, no PHP call) |
| props and some PHP | a `#[Component]` function |
| state, handlers, or services | a `#[Component]` class |
| a URL | `#[Page]` on a function or a class |

A template component is the fastest form, because Phem renders it as plain Twig without calling PHP. A function page has no state of its own, but the components inside it can:

```php
#[Component]
function Badge(string $label): Node
{
    return <span className="badge">{{ label }}</span>;
}

#[Page('/about')]
function AboutPage(): Node
{
    return <Document title="About us"><h1>About us</h1><LikeButton /></Document>;
}
```

A function without `#[Component]` is a plain PHP function. Use it for helpers.

## Keep `render()` pure

A pure `render()` gives the same markup for the same inputs, and Phem depends on that to render only the components that can change. The output of `render()` must depend only on the props, the `#[State]` properties, the `#[Context]` properties, and the properties that `mount()` or a handler set in the same request. Load data in `mount()` or in a handler, never in `render()`. The compiler reports warning W008 when `render()` isn’t pure. For the details, read [Keep `render()` pure](components.md#keep-render-pure).

## Treat state as UI state, not trusted data

State lives in the browser, so treat it like a note the user carries for you. The server signs it, which stops the user from editing it, but the browser can still send back an older state that the server signed earlier, until that signature expires. State values are JSON values (scalars, arrays), backed enums, or plain objects that the Serializer encodes as JSON.

Here’s a poll where a vote limit in state would be a bug, because a user could replay an older state and vote again:

```php
// Wrong: replaying an older state restores the votes.
#[State] public int $votesLeft = 3;
```

Keep the limit in the database instead, and keep in state only what the UI shows. Here `Poll` and `PollOption` are Doctrine entities, and `Poll::vote()` counts the votes of the user in the database before it adds one:

```php
#[Component]
final class PollVote
{
    #[Prop] public Poll $poll;
    #[State] public bool $voted = false;

    #[Transactional]
    public function vote(PollOption $option, #[CurrentUser] User $user): void
    {
        $this->poll->vote($option, $user);
        $this->voted = true;
    }

    public function render(): Node
    {
        return (
            <div>
                {% for option in poll.options %}
                    <button key={{ option.id }} onClick={{ vote(option) }}>{{ option.label }}</button>
                {% endfor %}
                {% if voted %}<p>Thank you for your vote.</p>{% endif %}
            </div>
        );
    }
}
```

A replay of an older state only shows the buttons again with `voted` false. The next vote still goes through `Poll::vote()`, which stops it.

As a rule, put in `#[State]` only values you can lose or undo: an open panel, a tab, a search term, a draft. Put filters, tabs, and page numbers in the URL with `#[Url]`, so they survive a reload and a shared link.

## Pass data down and events up

Data flows from parent to child through props, and changes flow back up through handlers. When two components show the same data, lift that data to their nearest common parent. In this example, the parent owns one `count` and passes it, with one handler, to two buttons, so both buttons update together:

```php
#[Component]
function MyButton(int $count, Closure $onClick): Node
{
    return (
        <button onClick={{ onClick }}>
            Clicked {{ count }} times
        </button>
    );
}

// In the parent:
<MyButton count={{ count }} onClick={{ handleClick }} />
<MyButton count={{ count }} onClick={{ handleClick }} />
```

Props aren’t the only way to get a value into a component. Pick the channel that matches where the value comes from:

| The value | Channel |
|---|---|
| comes from the parent | a prop |
| depends on the position in the tree (a theme, the nearest `<Tabs>`) | `#[Provide]` and `#[Context]` |
| is the same for the whole request (the current user, a repository) | a service in the constructor |

A repository is the same everywhere in the tree, so it’s a service, not a context:

```php
public function __construct(
    private readonly TodoRepository $repository,
) {
}
```

## Compute in PHP, show in Twig

Markup is Twig, and Twig is good at showing values but awkward at computing them. Prepare values in PHP before the `return`, and keep the markup to output:

```php
// Wrong: business logic in the markup.
return <p>{{ (order.items|reduce((s, i) => s + i.price * i.qty, 0)) * 1.21 }}</p>;

// Right: PHP computes the value, and the markup shows it.
$total = $this->order->totalWithTax();

return <p>{{ total }}</p>;
```

A markup literal is a value, a `Phem\Element`. You can store it in a variable, put it in an array, or return it, but don’t cast it to a string in PHP:

```php
$content = $isLoggedIn ? <AdminPanel /> : <LoginForm />;

return <div>{{ content }}</div>;
```

## Pass handlers instead of calling them

`onClick={{ save }}` passes the `save` method; it doesn’t call it. To give the handler a value of the render, write a call: `onClick={{ remove(s) }}` doesn’t run `remove()` during the render either. Phem keeps the handler with its arguments, and runs it when the user clicks. The object that owns the handler renders again after the event:

```php
public function render(): Node
{
    return (
        <ul>
            {% for s in scientists %}
                <li key={{ s.id }}>
                    <button onClick={{ remove(s) }}>Remove</button>
                </li>
            {% endfor %}
        </ul>
    );
}
```

Each row gets its own handler with its own scientist, so a click removes the right row even after the list changes. A closure that captures the value in PHP does the same: `$remove = fn () => $this->remove($s)`. Give a `key` to every list item, too: when an item before it goes away, the browser keeps the right element, with its focus and its Stimulus controller.

## Use Symfony for everything else

Phem adds a component model on top of Symfony and doesn’t replace any of it. For each job, use the Symfony tool you already know:

- **Routes**: `#[Page]` extends `#[Route]` and takes the same options
- **Security**: an event is a `POST` to the page’s URL, so `access_control`, the firewall, and `#[IsGranted]` protect events too
- **Services**: a class component is an autowired service, and each component instance gets a new object
- **Forms**: use a Symfony form and call `$form->handleRequest($request)` in the handler
- **Doctrine**: add `#[Transactional]`, and Phem flushes and commits after the handler, so don’t call `flush()` yourself

This page combines several of them. Symfony security restricts it to administrators, Doctrine loads the `User` from the `{id:user}` route value, and the rename is saved when Phem commits the transaction:

```php
#[IsGranted('ROLE_ADMIN')]
#[Page('/admin/users/{id:user}')]
final class EditUserPage
{
    #[Path] public User $user;

    #[Transactional]
    public function rename(Event $e): void
    {
        $this->user->name = $e->form['name'];
    }

    // ...
}
```

## Keep browser-only work in the browser

Some changes don’t need the server at all: opening a menu, toggling a class, focusing a field. Write those as Stimulus controllers, and mark the attributes the client owns with `data-phem-client` so the morph leaves them alone:

```html
<span data-controller="dropdown">
    <button
        data-action="dropdown#toggle"
        data-phem-client="aria-expanded"
        aria-expanded="false"
    >⋯</button>
    <span data-dropdown-target="menu" data-phem-client="hidden" hidden>
        <button onClick={{ star }}>Star</button>
    </span>
</span>
```

The dropdown opens and closes in the browser, while the **Star** button inside it still sends an event to the server. For an element that a JavaScript library controls (a chart, a map, an editor), add `data-phem-ignore` so the morph skips it. Before you write a new controller, check the [built-in ones](ui-controllers.md), such as `phem-popover`, `phem-menu`, `phem-optimistic`, and `phem-confirm`.

## Design for the round trip

Every event goes to the server, and its cost grows with the size of the component tree on the path to the handler. These techniques keep pages fast:

- **Pagination**: show long lists in pages, because a click on one row runs the `render()` of the whole list
- **Suspense**: put a slow part in a `<Suspense>` boundary, so the page doesn’t wait for it
- **Error boundaries**: put a part that can fail in an `<ErrorBoundary>`, so one broken widget doesn’t break the page
- **Async handlers**: use `#[Async]` to run slow work after the response

This chart loads after the page and shows a fallback message if it fails:

```php
<Suspense lazy>
    <Suspense:fallback><p>Loading the chart…</p></Suspense:fallback>
    <ErrorBoundary>
        <ErrorBoundary:fallback>
            <p>The chart is not available now.</p>
        </ErrorBoundary:fallback>
        <ActivityChart />
    </ErrorBoundary>
</Suspense>
```

## Let the compiler find errors

`phemc` checks your markup at build time, so mistakes show up before a user sees them. It checks closed tags, a single root element, known components, props and slots, and impure renders. Treat every warning as a defect, and run the check in continuous integration (CI):

```sh
vendor/bin/phemc check src
```

Give each prop a precise type and each component the return type `Node`. The compiler, PHP, and PHPStan then check every call for you. To run PHPStan on the compiled PHP, read [Static analysis](compiling.md#static-analysis-phpstan).

## Test without a browser

Phem’s test helpers render components on the server, so your tests don’t need a browser. Test a component with `mount()`, and test a page, including its security rules, with `visit()`. A test can click, type, and check both the HTML and the state:

```php
$this->mount(Counter::class, ['label' => 'Clicks'])
    ->click('button')
    ->assertSee('Clicks 1 times')
    ->assertState('count', 1);
```

For the full API, read [Testing components and pages](testing.md).

## Review a component before you finish it

Each item below checks one rule from this page. Before you call a component done, confirm that:

- It uses the smallest form that does the job
- `render()` reads only props, state, and context
- A user can lose or replay every `#[State]` value without risk
- Filters, tabs, and page numbers survive a reload, through `#[Url]`
- Each value comes through the right channel: a prop, a context, or a service
- Every list item has a `key`
- The database, not the state, holds every limit and security decision
- `phemc check` reports no diagnostics
