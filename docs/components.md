# Build components and manage their state

This guide shows you how to write a class component, keep its state between events, load its data from services, and share values with the components below it. It also covers state in the URL. To keep security decisions out of the state, see [Secure events and state](security.md). For the attribute syntax, see [The `.phem` syntax](phem-syntax.md).

## Write a class component

A class component has up to six parts, and you write them in this order:

- **Inputs**: `#[Prop]` properties that the parent sets, and `#[Context]` properties that a component above provides
- **State**: `#[State]` properties that the browser keeps between events
- **Services**: constructor arguments that the container autowires; they’re never props
- **`mount()`**: optional; it loads the data when there’s no state yet, or before each render when the component has no `#[State]`. The `mount()` of a page can return a `RedirectTo` (see [Redirect before a page renders](pages.md#redirect-before-a-page-renders))
- **Handlers**: public methods that change the state; a handler can’t be named `mount` or `render` (error E046)
- **`render()`**: required; it reads only the props, the state and the context

The inputs, the state and the services come first. `Theme` is a backed enum that a component above provides (see [Pass a value down the tree with context](#pass-a-value-down-the-tree-with-context)):

```php
use Phem\Attribute\{Component, Context, Prop, State};
use Phem\Node;

enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
}

#[Component]
final class TodoList
{
    #[Prop] public int $limit = 10;
    #[Context] public Theme $theme = Theme::Light;

    #[State] public array $todos = [];

    public function __construct(
        private readonly TodoRepository $repository,
    ) {
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

### Read the properties in the markup

The markup of a method reads the properties of its class, also the private ones. So don't copy a property into a variable for the markup:

```php
#[Component]
final class Invoice
{
    #[State] private array $lines = [];

    public function render(): Node
    {
        $rows = $this->lines; // hint H001

        return <ul>{% for line in rows %}<li key={{ line.id }}>{{ line.name }}</li>{% endfor %}</ul>;
    }
}
```

Use the name of the property in the markup:

```php
    public function render(): Node
    {
        return <ul>{% for line in lines %}<li key={{ line.id }}>{{ line.name }}</li>{% endfor %}</ul>;
    }
```

The compiled template gets the same value, `'lines' => $this->lines`, so the HTML doesn't change. The editors show the hint H001 on the copy. Its quick fix removes the copy, and changes `rows` to `lines` in the markup. The editor shows no quick fix when `lines` reads another value in the markup, for example a `{% for lines in ... %}` variable.

A copy that keeps an older value isn't a hint. The method reads or changes the property after the copy, or calls a method of the class. For example, a handler keeps the old ticket, gives the property a new one, and streams the old one:

```php
$ticket = $this->ticket;
$this->ticket = bin2hex(random_bytes(4));
// <li id="report-{{ ticket }}"> shows the old ticket
```

A copy that the PHP code also uses, such as `count($rows)`, isn't a hint either.

### Give a component variants

Don't keep the classes of a style in class constants (`PRIMARY`, `DANGER`). Use a backed enum prop, and give each case its classes in an enum method:

```php
enum Variant: string
{
    case Primary = 'primary';
    case Danger = 'danger';

    public function classes(): string
    {
        return match ($this) {
            self::Primary => 'bg-blue-500 text-white',
            self::Danger => 'bg-red-600 text-white',
        };
    }
}

#[Component]
function Button(string $label, Variant $variant = Variant::Primary): Node
{
    return <button className={{ classes('px-4 py-2', variant.classes()) }}>{{ label }}</button>;
}
```

A caller then writes `<Button label="Delete" variant="danger" />`, and `phemc` checks the value (`variant="big"` is E018). For the full pattern, see [Add variants to a component](packages.md#add-variants-to-a-component).

### Know what runs on the first render and on an event

Phem creates a new object for each component in each request, but the steps differ between the first render and an event:

| Step | First render | Event |
|---|---|---|
| Constructor | Runs | Runs |
| Props and contexts | Set | Set |
| State | Set by `mount()` | Restored from the browser |
| Handler | Doesn’t run | Runs |
| `render()` | Runs | Runs when the component can change |

So `mount()` loads the data once, and the state keeps it for the next events. A handler loads fresh data when it needs it, as `refresh()` does. A component with no `#[State]` has nothing to restore, so its `mount()` runs every time it renders. A component with a `Feed` property is the exception: its `mount()` runs on the first render only, because the browser keeps its items (see [Show long lists with feeds](long-lists.md)).

## Keep state between events

Mark a property with `#[State]` to keep it between events. When a handler changes it, Phem calls `render()` again. Each component instance has its own state, and a page class is a component too, so a page can have state.

A `#[Prop]` property is different: the parent sets it on every render. A prop without a default value is required, except a nullable prop: it gets `null`.

A prop can be `readonly`. When the parent gives it another value, Phem makes a new instance with the new props and the state of the old instance. As in the first render, `mount()` runs when the browser sent no state.

### Store only JSON values in the state

The browser keeps the state, and the server signs it. Every event sends the state back to the server, so it must hold JSON values (scalars and arrays), backed enums, objects that the Serializer encodes as JSON (see [Keep a draft object in the state](#keep-a-draft-object-in-the-state)), or value objects that implement `Phem\State\StateValue`, as `Validation` does.

A `StateValue` gives its JSON value with `toState()`, and `static fromState(mixed $state)` makes the object again. `fromState(null)` gives the initial object, so such a property is never `null`. Make the object immutable: a change gives a new object, and a child that gets it as a prop renders again only when it is another object.

The browser can send back an older state that the server signed, so keep limits and security decisions out of the state (see [Keep security decisions out of the state](security.md#keep-security-decisions-out-of-the-state)).

### Keep a draft object in the state

A form for an entity needs a value for each field. Put the fields in a plain class (a draft), and keep one object of it in a `#[State]` property:

```php
final class ProductDraft
{
    #[Assert\NotBlank]
    public string $name = '';

    public ?int $categoryId = null;

    public ?\DateTimeImmutable $availableFrom = null;

    public static function of(Product $product): self
    {
        $draft = new self();
        $draft->name = $product->name;
        $draft->categoryId = $product->category->id;
        $draft->availableFrom = $product->availableFrom;

        return $draft;
    }
}

#[Component]
final class ProductForm
{
    #[Prop] public Product $product;

    #[State, Assert\Valid] public ProductDraft $draft;

    #[State] public Validation $validation;

    public function mount(): void
    {
        $this->draft = ProductDraft::of($this->product);
    }

    public function clearName(): void
    {
        $this->draft->name = '';
    }

    #[Validated, Transactional]
    public function save(): void
    {
        $this->product->apply($this->draft);
    }

    public function render(): Node
    {
        return (
            <form onSubmit={{ save }}>
                <input phem:bind={{ draft.name }} phem:live />
                <p phem:error={{ draft.name }} className="error" />
                <input type="date" phem:bind={{ draft.availableFrom }} />
                <button type="submit">Save</button>
            </form>
        );
    }
}
```

The state keeps the JSON value of the object. The Symfony Serializer makes it, and makes the object again on the next event:

```json
{"draft":{"name":"Desk","categoryId":3,"availableFrom":"2026-11-08T00:00:00+00:00"},"validation":[]}
```

The rules:

- Install `symfony/serializer`. A `#[State]` property whose type is a class (not a backed enum, and not a `Phem\State\StateValue`) keeps its object with the Serializer. A date (`\DateTimeImmutable`) is an object too.
- Use public properties with types. The state keeps only the public properties, also in the nested objects: the values of the getters (`getLabel()`, `isAdult()`) are not in it. A `phem:bind` path sets a public property that is not `readonly` (see [Bind a property of a draft object](forms.md#bind-a-property-of-a-draft-object)).
- Don't keep a Doctrine entity in the state, also not in a property of a draft: Phem throws a `LogicException`. The state keeps a copy of the fields, so it gets old, and the browser can read all of them. Keep a draft and the id of the entity (a prop, or a `#[Path]` of the page).
- Give the property a class type. In a property with an `array`, a union or an `object` type, an object is kept as `json_encode()` writes it, and it comes back as an array.
- A handler can change the object in place (`$this->draft->name = ''`), also a handler of a child that gets the draft as a prop. After the handlers, a draft that changed becomes a new object, so a child that gets it renders again. A draft that didn't change stays the same object, and the child is kept.

### Keep UI-only state in the browser

Don't keep a value in `#[State]` when only the browser needs it, such as an open panel. This page opens a dialog with two handlers, so each open and each close is a request:

```php
#[State] public bool $askingCancel = false;

public function openCancel(): void
{
    $this->askingCancel = true;
}

public function closeCancel(): void
{
    $this->askingCancel = false;
}
```

The `phem-dialog` controller opens and closes the dialog in the browser, with no request. Only the action that changes data goes to the server:

```html
<div data-controller="phem-dialog">
    <button data-action="phem-dialog#open">Cancel the order</button>
    <dialog data-phem-dialog-target="dialog" aria-labelledby="cancel-title">
        <h2 id="cancel-title">Cancel the order?</h2>
        <button onClick={{ cancel }} data-action="phem-dialog#close">Yes, cancel it</button>
        <button data-action="phem-dialog#close">No</button>
    </dialog>
</div>
```

Keep the value in the state only when the server decides it. For example, a handler opens the dialog after it finds a problem. Then set `data-phem-dialog-open-value={{ askingCancel }}`. See [Open a modal dialog](client-side.md#open-a-modal-dialog). For the same reason, use [`phem-tabs`](client-side.md#switch-between-tabs) for tabs and [`phem-confirm`](client-side.md#ask-for-confirmation-before-an-event) for a confirmation.

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
            <Document title="Counters">
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

An object that a handler changes in place, such as a Doctrine entity, counts as the same prop. Pass the changed value to the child (`<LeadTime days={{ settings.leadDays }} />`), not the object (`settings={{ settings }}`), so Phem sees the change.

Slots work differently. They belong to the component that passes them (the owner). When the owner renders again, a child with slots renders again with its slots, even if its props didn’t change. Here, `Panel` gets the same `title` after the event, but it renders again because `LeadTimeSettings` owns its children:

```php
#[Component]
function Panel(string $title, ?Html $children = null): Node
{
    return <fieldset><legend>{{ title }}</legend>{{ children }}</fieldset>;
}

#[Component]
final class LeadTimeSettings
{
    private Settings $settings; // a Doctrine entity

    public function __construct(
        private readonly SettingsRepository $repository,
    ) {
    }

    public function mount(): void
    {
        $this->settings = $this->repository->current();
    }

    public function setLead(int $days): void
    {
        // The same entity object: only its leadDays changed.
        $this->settings->setLeadDays($days);
    }

    public function render(): Node
    {
        // The component renders after setLead(), so <Panel> renders with its children.
        return (
            <Panel title="Lead time">
                {% for days in 0..2 %}
                    <button key={{ days }} role="radio" aria-checked={{ days == settings.leadDays ? 'true' : 'false' }} onClick={{ setLead(days) }}>{{ days }}</button>
                {% endfor %}
            </Panel>
        );
    }
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
use Phem\{Html, Node};

#[Component, Provide]
final class Tabs
{
    #[State] public string $active = 'general';
    #[Prop] public ?Html $children = null;

    public function select(string $name): void
    {
        $this->active = $name;
    }

    public function render(): Node
    {
        return <div role="tablist">{{ children }}</div>;
    }
}
```

Each `Tab` reads the nearest `Tabs` and calls its `select` handler with its name:

```php
#[Component]
function Tab(string $name, string $label, #[Context] Tabs $tabs): Node
{
    return (
        <button role="tab" aria-selected={{ tabs.active == name }} onClick={{ tabs.select(name) }}>
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

The first render reads the query string after `mount()`. After each event, the browser writes the values into the URL, and a value equal to the default removes its key. The attribute takes three arguments:

- **`as`**: the query string key, when it differs from the property name. It can have only letters, digits, `_` and `-`
- **`history`**: set it to `true` to add a browser history entry for each change
- **`except`**: the value that removes the key, when it differs from the default value of the property. Use it for a property that `mount()` sets, as in `#[State, Url(except: 'name')] public string $sort;`

The type of the property is a string, an int, a float, a bool, a backed enum, or an array of them. A bool is `1` or `0` (`true` and `false` too). An array with keys goes into the URL as `filters[status]=open`, with only the keys that differ from the default.

The query string comes from the user, so don’t trust its values. A value that doesn’t fit the type is ignored, and the property keeps its value. Phem throws a `LogicException` when a `#[Url]` property isn’t a `#[State]` property, when its key or its type isn’t valid, when the `except` value doesn’t fit the type, or when two properties of the page have the same key.
