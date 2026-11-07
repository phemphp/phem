# Build components and manage their state

This guide shows you how to write a class component, keep its state between events, load its data from services, and share values with the components below it. It also covers state in the URL and custom types for route values. For the attribute syntax, see [The `.phem` syntax](phem-syntax.md).

## Write a class component

A class component has up to six parts, and you write them in this order:

- **Inputs**: `#[Prop]` and `#[Context]` properties that the parent sets
- **State**: `#[State]` properties that the browser keeps between events
- **Services**: constructor arguments that the container autowires; they’re never props
- **`mount()`**: optional; it runs only when there’s no state yet, and it loads the data
- **Handlers**: public methods that change the state; a handler can’t be named `mount` or `render` (error E046)
- **`render()`**: required; it reads only the props, the state and the context

The inputs, the state and the services come first:

```php
use Phem\Attribute\{Component, Context, Prop, State};
use Phem\Node;

#[Component]
final class TodoList
{
    #[Prop] public int $limit = 10;
    #[Context] public Theme $theme = Theme::Light;

    #[State] public array $todos = [];

    public function __construct(private readonly TodoRepository $repository)
    {
    }
```

The methods follow. `mount()` loads the first list, `refresh()` is a handler, and `render()` returns the markup:

```php
    public function mount(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

    public function refresh(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

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

Only `render()` is required. If a component has no state, no services and no handlers, write a function instead of a class. Start with the smallest form, and move to a class when you need state or services (see [Use the smallest form of a component](philosophy.md#use-the-smallest-form-of-a-component)).

### Know what runs on the first render and on an event

Phem creates a new object for each component in each request, but the steps differ between the first render and an event:

| Step | First render | Event |
|---|---|---|
| Constructor | Runs | Runs |
| Props and contexts | Set | Set |
| State | Set by `mount()` | Restored from the browser |
| Handler | Doesn’t run | Runs |
| `render()` | Runs | Runs when the component can change |

So `mount()` loads the data once, and the state keeps it for the next events. A handler loads fresh data when it needs it, as `refresh()` does. A component with no `#[State]` has nothing to restore, so its `mount()` runs every time it renders.

## Keep state between events

Mark a property with `#[State]` to keep it between events. When a handler changes it, Phem calls `render()` again. Each component instance has its own state, and a page class is a component too, so a page can have state.

A `#[Prop]` property is different: the parent sets it on every render. A prop without a default value is required.

### Store only JSON values in the state

The browser keeps the state, and the server signs it. Every event sends the state back to the server, so it must hold JSON values (scalars and arrays) or backed enums.

### Keep security decisions out of the state

State is UI state, not trusted data: the browser can send back any older state that the server signed. Keep limits and security decisions, such as tries, votes and roles, in the database or the session. Two options limit how a state can be reused:

- **`state_max_age`**: the number of seconds a state stays valid; the default is `86400` (one day), and `0` turns the limit off
- **`state_bind_session`**: set it to `true` to bind each state to the user’s session

```yaml
# config/packages/phem.yaml
phem:
    state_max_age: 3600
    state_bind_session: true
```

### Don’t use hook functions

Phem has no hooks. A call to `Phem\useState`, `useReducer`, `useRef`, `useMemo`, `useCallback` or `useId` is the error E020. Use `#[State]` properties instead. For `useContext`, see [Pass a value down the tree with context](#pass-a-value-down-the-tree-with-context).

## Share state between two components

When two components show the same data, keep the state in their common parent and pass it down. A function component can take a handler as a prop:

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

Both buttons show the same count, and a click on either one updates both.

## Use services in a component

A class component is a Symfony service, so its constructor receives services through autowiring, as `TodoRepository` does in [the class component example](#write-a-class-component). The container creates a new object for each component instance.

Call the services in `mount()` and in the handlers, not in `render()` (see [Keep `render()` pure](#keep-render-pure)). Because `mount()` runs after Phem sets the props, it can use them to load the data: `$this->repository->latest($this->limit)`.

To configure a component service, for example to bind a scalar argument, define it in `config/services.yaml` with its class name as the ID. Phem keeps your definition and only marks it as not shared.

## Keep `render()` pure

The output of `render()` must depend only on the props, the state, the contexts and the properties that `mount()` or a handler set in the same request. Don’t call services in `render()`, and don’t read the time, the session or a static property there. Load the data in `mount()` or in a handler instead:

```php
// Wrong: the output changes when the database changes.
public function render(): Node
{
    $unread = $this->messages->countUnread();

    return <span>{{ unread }}</span>;
}

// Right: mount() calls the service, and render() reads the state.
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

Phem relies on this rule to render only the components that can change. An event renders the component that owns the handler and every component whose state changed. A child that receives the same props as before doesn’t render again, and the browser keeps its HTML.

### Pass the changed value, not the changed object

An object that a handler changes in place, such as a Doctrine entity, counts as the same prop. Pass the changed value to the child (`days={{ s.leadDays }}`), not the object, so Phem sees the change.

Slots work differently. They belong to the component that passes them (the owner). When the owner renders again, a child with slots renders again with its slots, even if its props didn’t change:

```php
public function setLead(Event $e): void
{
    // Same entity object: only its leadDays changed.
    $this->repository->current()->setLeadDays((int) $e->value);
}

public function render(): Node
{
    $s = $this->settings;

    // The page renders after setLead(), so <Panel> renders with its children.
    return (
        <Panel>
            {% for days in 0..2 %}
                <button role="radio" value={{ days }} aria-checked={{ days == s.leadDays ? 'true' : 'false' }} onClick={{ setLead }}>{{ days }}</button>
            {% endfor %}
        </Panel>
    );
}
```

### Render the full page after a shared change

When a handler changes data that other components read, call `$e->refresh()`. Phem then renders the full page again.

### Find impure renders

The compiler and debug mode both catch impure renders:

- **phemc**: reports W008 for a `render()` that reads a service, a static property, a superglobal, the clock or a random value
- **Debug mode**: Phem renders the full page after each event and logs a warning for each component whose HTML changed without a render

## Pass a value down the tree with context

A context passes a value to every component below it, with no props in between. Use it for a value that depends on the position in the tree, such as the theme of a section or the `<Tabs>` around a `<Tab>`. A value that’s the same for the whole request, such as the current user or a repository, belongs in a service.

Add `#[Provide]` to a class to provide the component itself as the context. A component below reads it with `#[Context]`:

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
```

Each `Tab` reads the nearest `Tabs` and calls its `select` handler:

```php
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

To provide the value of a property instead of the whole component, put `#[Provide]` on the property: `#[State, Provide] public Theme $theme = Theme::Light;`.

### Choose the context type

The type is the key: Phem takes the nearest component above that provides a value of that type. The type must be a class, an interface or an enum; a scalar or an array is the error E044. A context with no default and no `null` is required, so the render fails when no component above provides it (E015 when the compiler can see it).

The value is live. When the provider changes, its consumers render again, and a consumer can call a handler of the provider, as `tabs.select` does above.

## Keep state in the URL

Add `#[Url]` to a state property to store it in the query string. Filters, search terms and page numbers then survive a reload, a shared link and the back button:

```php
use Phem\Attribute\{Component, State, Url};

#[Component]
final class Catalog
{
    #[State, Url(as: 'q')] public string $query = '';    // ?q=php
    #[State, Url(history: true)] public int $page = 1;    // ?page=2
    #[State, Url] public array $tags = [];                // ?tags[]=a&tags[]=b
}
```

The first render reads the query string after `mount()`. After each event, the browser writes the values into the URL, and a value equal to the default removes its key. The attribute takes two arguments:

- **`as`**: the query string key, when it differs from the property name
- **`history`**: set it to `true` to add a browser history entry for each change

The query string comes from the user, so don’t trust its values.

## Convert route values to your own types

A converter turns a route value into another type, such as a date, an enum or a value object. Create a service that implements `Phem\Path\PathValueConverter`:

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

A `#[Path]` property of that type now receives the converted value:

```php
#[Page('/agenda/{day}')]
final class AgendaPage
{
    #[Path] public \DateTimeImmutable $day;
}
```

Throw an `\UnexpectedValueException` when the value doesn’t fit, and the page returns a 404. Your app’s converters run before the built-in ones.
