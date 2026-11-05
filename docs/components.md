# Components and state

This page explains the parts of a component, how it keeps state, how it gets its data, and how
it shares values with the components below it. For the syntax of the attributes, see [The `.phem` syntax](phem-syntax.md).

## Anatomy of a component

A class component has up to six parts. Write them in this order:

```php
use Phem\Attribute\{Component, Context, Prop, State};
use Phem\Node;

#[Component]
final class TodoList
{
    // 1. Inputs: the parent sets them.
    #[Prop] public int $limit = 10;
    #[Context] public Theme $theme = Theme::Light;

    // 2. State: the browser keeps it between the events.
    #[State] public array $todos = [];

    // 3. Services: the container gives them. They are never props.
    public function __construct(private readonly TodoRepository $repository)
    {
    }

    // 4. mount(): optional. It runs only when there is no state yet. It reads the data.
    public function mount(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

    // 5. Handlers: public methods. They change the state.
    public function refresh(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

    // 6. render(): required. It reads only the props, the state and the context.
    public function render(): Node
    {
        return (
            <section className="theme-{{ theme.value }}">
                <ul>{% for todo in todos %}<li key={{ todo.id }}>{{ todo.title }}</li>{% endfor %}</ul>
                <button onClick={{ refresh }}>Refresh</button>
            </section>
        );
    }
}
```

Only `render()` is required. A component with no state, no services and no handlers does not need
a class: write a function. Start with the smallest form, and move to a class when you need state
or services (see [Use the smallest form of a component](philosophy.md#2-use-the-smallest-form-of-a-component)).

Phem makes a new object for each component in each request. The steps are not the same on the
first render and in an event:

| Step | First render | Event |
|---|---|---|
| The constructor | runs | runs |
| The props and the contexts | are set | are set |
| The state | `mount()` sets it | Phem restores it from the browser |
| The handler | does not run | runs |
| `render()` | runs | runs, when the component can change |

Thus `mount()` reads the data one time, and the state keeps it for the next events. A handler
reads new data when it needs it, as `refresh()` does. A component with no `#[State]` has no state
to restore, so its `mount()` runs each time that it renders.

## State

A `#[Prop]` property is a prop. A prop with no default value is required. A `#[State]` property
is state: a handler changes it, and Phem calls `render()` again. Each instance of the component
has its own state. A page class is a component too, so it can have state.

The state lives in the browser, and the server signs it. Each event sends it back, so the state
must be JSON values (scalars and arrays) or backed enums. The state is UI state, not trusted data:
the browser can send back an older state that the server signed. Keep limits and security
decisions (tries, votes, roles) in the database or the session. `phem.state_max_age` (one day by
default) limits the age of a state, and `phem.state_bind_session: true` binds it to the session.

Phem has no hooks. A call to `Phem\useState` (or `useReducer`, `useRef`, `useMemo`,
`useCallback`, `useId`) is the error E020. For `useContext`, see [Context](#context).

## Lift state up

When two components show the same data, keep the state in their common parent, and pass it down.
A function component can get a handler as a prop:

```php
#[Component]
function MyButton(int $count, Closure $onClick): Node
{
    return <button onClick={{ onClick }}>Clicked {{ count }} times</button>;
}

#[Page('/counters')]
final class CountersPage
{
    #[State] public int $count = 0;

    public function handleClick(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return (
            <Document>
                <MyButton count={{ count }} onClick={{ handleClick }} />
                <MyButton count={{ count }} onClick={{ handleClick }} />
            </Document>
        );
    }
}
```

The two buttons show the same count. A click on one of them changes both.

## Services and `mount()`

A class component is a Symfony service. Its constructor gets the services with autowiring, as in
the [anatomy](#anatomy-of-a-component) example. The container makes a new object for each
component instance.

Use the services in `mount()` and in the handlers, not in `render()` (see
[Keep `render()` pure](#keep-render-pure)). `mount()` sees the props, so it can use them to read
the data: `$this->repository->latest($this->limit)`.

To configure a component service (for example to bind a scalar argument), define it in
`config/services.yaml` with its class name as the id. Phem keeps your definition, and only makes
it not shared.

## Keep `render()` pure

As in React, the output of `render()` depends only on the props, the state, the contexts, and the
properties that `mount()` or a handler set in the same request. `render()` does not call services,
and it does not read the time, the session or a static property. Read the data in `mount()` or in
a handler:

```php
// Not correct: the output changes when the database changes.
public function render(): Node
{
    $unread = $this->messages->countUnread();

    return <span>{{ unread }}</span>;
}

// Correct: mount() reads the service, and render() reads the state.
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

Phem uses this rule to render only the components that can change. An event renders the component
of the handler and the components whose state changed. A child that gets the same props as before
does not render again: the browser keeps its HTML.

When a handler changes data that other components read, call `$e->refresh()`. The full page then
renders again, as `router.refresh()` does in Next.js.

phemc reports W008 for a `render()` that reads a service, a static property, a superglobal, the
clock or a random value. In debug mode, Phem also renders the full page after each event, and
logs a warning for each component whose HTML changed with no render.

## Context

A context gives a value to all the components below, with no props in between. It is
`createContext` and `useContext` of React. Use it for a value that depends on the position in the
tree: the theme of a section, or the `<Tabs>` around a `<Tab>`. A value that is the same for all
the request (the current user, a repository) is a service.

A class with `#[Provide]` gives itself as the context. A component below reads it with
`#[Context]`:

```php
use Phem\Attribute\{Component, Context, Prop, Provide, State};
use Phem\{Event, Html, Node};

#[Component, Provide]
final class Tabs
{
    #[State] public string $active = 'general';
    #[Prop] public ?Html $children = null;

    public function select(Event $e): void
    {
        $this->active = $e->value;
    }

    public function render(): Node
    {
        return <div role="tablist">{{ children }}</div>;
    }
}

#[Component]
function Tab(string $name, string $label, #[Context] Tabs $tabs): Node
{
    return (
        <button role="tab" aria-selected={{ tabs.active == name }} onClick={{ tabs.select }} value={{ name }}>
            {{ label }}
        </button>
    );
}

<Tabs>
    <Tab name="general" label="General" />
    <Tab name="security" label="Security" />
</Tabs>
```

`#[Provide]` on a property gives the value of the property:
`#[State, Provide] public Theme $theme = Theme::Light;`.

The type is the key: Phem takes the nearest component above that provides a value of this type.
The type is a class, an interface or an enum (E044 for a scalar or an array). A context with no
default and no `null` is required: the render fails when no component above provides it (E015 when
the compiler can see it).

The value is live. When the provider changes, its consumers render again. A consumer can call a
handler of the provider, as `tabs.select` above.

## State in the URL

A `#[Url]` property is in the query string. Filters, search terms and page numbers then stay after
a reload, in a shared link, and with the back button:

```php
use Phem\Attribute\{Component, State, Url};

#[Component]
final class Catalog
{
    #[State, Url(as: 'q')] public string $query = '';    // ?q=php
    #[State, Url(history: true)] public int $page = 1;    // ?page=2, with a history entry
    #[State, Url] public array $tags = [];                // ?tags[]=a&tags[]=b
}
```

The first render reads the query string, after `mount()`. After each event, the browser writes the
values into the URL. A value equal to the default removes its key. `as` gives another key, and
`history: true` adds a history entry for each change. The query string comes from the user, so do
not trust its values.

## Other types of route values

A converter gives a route value another type: a date, an enum, a value object. Make a service that
implements `Phem\Path\PathValueConverter`:

```php
use Phem\Path\{PathProp, PathValueConverter};

final class DateConverter implements PathValueConverter
{
    public function supports(PathProp $prop): bool
    {
        return \DateTimeImmutable::class === $prop->type->getName();
    }

    public function convert(PathProp $prop, mixed $value): ?\DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value)
            ?: throw new \UnexpectedValueException(\sprintf('"%s" is not a date.', $value));
    }
}
```

```php
#[Page('/agenda/{day}')]
final class AgendaPage
{
    #[Path] public \DateTimeImmutable $day;
}
```

Throw an `\UnexpectedValueException` when the value does not fit: the page gives a 404. The
converters of the app run before the built-in converters.
