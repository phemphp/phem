# `.phem` syntax reference

A `.phem` file is a PHP file with markup literals in it. The `phemc` compiler turns each markup literal into a Twig template and keeps the PHP code as it is, so all parsing happens at build time. This page lists each syntax element: the file, components, markup, props, pages and routes, events and directives, built-in components, markup values and the PHP attributes.

For what the features do at runtime, read the topic pages: [Components and state](components.md), [Events and forms](events-and-forms.md), [Client-side behavior](client-side.md), [Server features](server-features.md) and [Errors and limits](errors-and-limits.md).

## File structure and open tag

A `.phem` file opens with `<?phem`, and the rest is normal PHP: a namespace, `use` imports, functions and classes. Any other open tag (`<?php`, the old `<?phpm`) is an error (E006):

```php
<?phem
namespace App\Ui\Common;

use Phem\Attribute\Component;
use Phem\Node;

#[Component]
function MyButton(): Node
{
    return <button>I'm a button</button>;
}
```

Phem needs PHP 8.4 or later. PHP 8.5 syntax, for example the `|>` operator, works only when the application runs on PHP 8.5.

## Component declarations

A component is a function or a class with the `#[Component]` attribute. A function component returns markup with the return type `Phem\Node`, and a class component returns its markup from a `render()` method:

```php
#[Component]
final class Counter
{
    #[Prop] public string $label = 'Clicked';
    #[State] public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return <button onClick={{ increment }}>{{ label }} {{ count }} times</button>;
    }
}
```

A tag renders a component: `<MyButton />` or `<Counter label="Clicks" />`. The compiler resolves the tag name with the PHP rules, in this order: a `use function` import, a `use` import, then the current namespace.

A function without `#[Component]` is a plain PHP function. PHP code can call it, but a tag can’t use it (E010).

In the markup of a class, a name resolves to a local variable, then a property, then a method. Properties and methods of a parent class and of its traits resolve too. For when to use a class, see [Components and state](components.md).

## Markup literals

Markup literals follow the rules below, and their text and attribute values are Twig expressions.

### Markup rules

Markup follows these rules:

- **Closed tags**: close every tag, including void elements (`<br />`, `<img ... />`)
- **One root**: return one root. To return several elements, wrap them in a fragment: `<>...</>`
- **Tag case**: a lowercase tag is an HTML element, and a capitalized tag is a component
- **Attribute names**: you can write `className` for `class` and `htmlFor` for `for`
- **Start position**: a markup literal starts where a PHP expression starts: after `return`, `=`, `(`, `,`, `=>`, `?`, `:`, `??`, `[` or `echo`
- **Multi-line markup**: put parentheses around markup that spans more than one line

Phem marks the root element of each component with the component ID (`data-phem-c`). When the root isn’t one element (a fragment, a text or another component), Phem wraps it in a `<phem-c>` element. A `<phem-c>` isn’t valid inside a `<table>`, a `<tr>` or a `<select>`, so a row or an option component must return one element.

### Expressions and attribute values

In markup, text and attribute values are Twig. The Twig variables are the PHP variables of the scope (`$user` is `user`), and the Symfony `app` global is available too (`{{ app.user.userIdentifier }}`):

```php
#[Component]
function Profile(): Node
{
    $user = ['name' => 'Hedy Lamarr', 'imageUrl' => '/img/hedy.jpg', 'imageSize' => 90];

    return (
        <>
            <h1>{{ user.name }}</h1>
            <img
                className="avatar"
                src={{ user.imageUrl }}
                alt={{ 'Photo of ' ~ user.name }}
                style={{ {width: user.imageSize, height: user.imageSize} }}
            />
        </>
    );
}
```

Each form of value renders this way:

| You write | You get |
|---|---|
| `{{ expr }}` in text | The value, escaped. Markup prints as HTML |
| `attr="text {{ expr }}"` | A string with the value in it |
| `attr={{ expr }}` | The value. `null` and `false` remove the attribute, and `true` renders a boolean attribute |
| `style={{ {...} }}` | A style from a hash. camelCase keys become kebab-case. Numbers get `px`, except for unitless properties (`opacity`, `zIndex`, …) |
| `{{ ...attrs }}` in a tag | All the attributes of a hash. A later attribute replaces an earlier one |

`data-*`, `aria-*` and `value` print a boolean as text: `data-open={{ false }}` renders `data-open="false"`. Use `null` to remove them.

All Twig filters, tests and tags work. Don’t write PHP in markup: compute values in PHP before the `return`.

Phem doesn’t render a `javascript:` URL that comes from an expression in a URL attribute (`href`, `src`, `action`). A spread never renders an inline script, such as `onclick` with a string.

### Conditional markup

A markup literal is a value, so a PHP `if` can choose it:

```php
if ($isLoggedIn) {
    $content = <AdminPanel />;
} else {
    $content = <LoginForm />;
}

return <div>{{ content }}</div>;
```

You can also write the condition in Twig. The `and` form renders the markup only when the condition is true:

```twig
<div>{{ isLoggedIn ? <AdminPanel /> : <LoginForm /> }}</div>
<div>{{ isLoggedIn and <AdminPanel /> }}</div>
<div>{% if isLoggedIn %}<AdminPanel />{% else %}<LoginForm />{% endif %}</div>
```

### Lists and keys

A Twig loop renders a list. Give each item a `key`:

```php
#[Component]
function ShoppingList(array $products): Node
{
    return (
        <ul>
            {% for product in products %}
                <li key={{ product.id }}>{{ product.title }}</li>
            {% endfor %}
        </ul>
    );
}
```

An array of markup also prints as a list:

```php
$items = array_map(fn ($product) => <li key={{ product.id }}>{{ product.title }}</li>, $products);

return <ul>{{ items }}</ul>;
```

After an event, the browser matches the items by their key. An item keeps its element, with its focus and client changes, when an item before it goes away.

## Component props

Props are the inputs of a component: the parameters of a function component, or the `#[Prop]` properties of a class component.

### Props, children and slots

The props of a function component are its parameters:

```php
use Phem\{Html, Node};

#[Component]
function Card(string $title, ?Html $children = null, ?Html $footer = null): Node
{
    return (
        <section className="card">
            <h2>{{ title }}</h2>
            {{ children }}
            <footer>{{ footer ?? 'No footer' }}</footer>
        </section>
    );
}
```

The caller passes `title` as an attribute, the tag content as `children`, and the `footer` slot with a `<Card:footer>` tag:

```php
<Card title="Hello">
    <p>The content of the card.</p>
    <Card:footer>Updated today</Card:footer>
</Card>
```

A function component doesn’t get services. Each parameter is a prop, even one with a service type, so the caller must pass it. Fetch the data in a function page or in the `mount()` of a class component, and pass it as a prop. A class component gets its services in the constructor (see [Services and `mount()`](components.md#use-services-in-a-component)).

The props of a class component are its public properties with `#[Prop]`. A prop with no default value is required.

Props take values in these forms:

- **String**: `prop="text"`
- **Any value**: `prop={{ expr }}`
- **`true`**: a bare `prop`
- **Children**: the tag content goes to the `$children` prop
- **Named slot**: `<Card:footer>` goes to the prop with that name. A slot of type `Html` with no `?` and no default is required, and the compiler reports E011 when the caller leaves it out

An unknown prop is an error, unless the component has rest props.

A slot that the caller leaves out is `null`. A slot that the caller passes is a `Phem\Html` object, and an object is always true in a Twig condition, also when its markup is empty. To show a wrapper only when the slot has markup, use the `empty` test:

```php
{% if footer is not empty %}<footer>{{ footer }}</footer>{% endif %}
```

`<Card title="Hello" />` and `<Card title="Hello"><Card:footer></Card:footer></Card>` render no `<footer>`. Twig prints a `Phem\Html` value without escaping. A filter that returns a string, such as `|trim`, gives text, and Twig escapes it. The `in` operator does not read the markup of a slot: `'x' in footer` is always false. Use `'x' in (footer ~ '')` to search the markup.

A regular Twig template of the application can also render a component, with no state: `{% component 'App\\Ui\\Common\\Profile' with {person: person} %}`.

### Rest props

A variadic parameter receives, as a hash, the props that no other parameter takes. Spread it on an element to forward the attributes and handlers:

```php
#[Component]
function CountButton(int $count, mixed ...$rest): Node
{
    return <button type="button" {{ ...rest }}>Clicked {{ count }} times</button>;
}

<CountButton count={{ count }} onClick={{ handleClick }} className="primary" aria-label="Count" />
```

Order matters. The caller can replace `type` because it comes before the spread, and an attribute after the spread is fixed. Use `mixed`, because the values can be strings, booleans or handlers.

In a class, the rest prop is an `array` property with `#[Prop(rest: true)]`:

```php
#[Prop(rest: true)] public array $attrs = [];
```

### Enum props

A prop with a backed enum type takes the value of a case. The caller writes the value, and Phem converts it with `::from()`:

```php
enum Variant: string
{
    case Primary = 'primary';
    case Outline = 'outline';
}

#[Component]
function Button(Variant $variant = Variant::Primary, ?Html $children = null): Node
{
    return <button className="btn btn-{{ variant.value }}">{{ children }}</button>;
}

<Button variant="outline">Save</Button>   // $variant is Variant::Outline
```

A value that isn’t a case is an error that lists the valid values. `phemc` checks a literal value at build time (E018). For classes per case, see [Variants of a component](packages.md#add-variants-to-a-component).

### Merge of classes

With a spread, a caller’s `className` replaces the classes of the component. Mark the default classes with `classes()` to merge the caller’s classes into them instead:

```php
#[Component]
function Button(?Html $children = null, mixed ...$rest): Node
{
    return (
        <button className={{ classes('px-4 py-2 bg-blue-500') }} {{ ...rest }}>
            {{ children }}
        </button>
    );
}

<Button className="bg-red-500">Delete</Button>
// class="px-4 py-2 bg-red-500" with the Tailwind merger
```

Position decides who wins. `classes()` before the spread sets defaults that the caller can change, and `classes()` after the spread sets classes that the caller can’t remove. `classes()` takes the same inputs as clsx: strings, lists and condition maps (`{'active': isActive}`). A `class` attribute takes a list too: `className={{ ['btn', {'active': isActive}] }}`. In PHP code, call `Phem\classes()`.

`style` doesn’t merge: the last value wins. The default merger removes only duplicates. To merge Tailwind classes of the same group (`px-2` then `px-4` gives `px-4`), see [The class merger](using-packages.md#turn-on-the-tailwind-class-merger).

## Pages and routes

A page is a component with a URL. It renders inside the document component of the application, and the bundle loads its routes from the Symfony routing config.

### Page declarations

Add `#[Page]` to a class or a function to make it a page. A page needs no `#[Component]`, and by convention its name ends with `Page` (see [Organize the UI](compiling.md#organize-the-ui)):

```php
use Phem\Attribute\Page;
use Phem\Node;

use function App\Ui\Common\Document;

#[Page('/', name: 'home')]
final class HomePage
{
    public function render(): Node
    {
        return (
            <Document>
                <Document:title>Home</Document:title>
                <h1>Welcome to my app</h1>
            </Document>
        );
    }
}

#[Page('/about')]
function AboutPage(): Node
{
    return <Document><h1>About us</h1></Document>;
}
```

`#[Page]` extends the Symfony `#[Route]` attribute, so it takes the same options: `name`, `methods`, `requirements`, `defaults`, `host` and the others. Repeat it to give a page more than one URL. With no `name`, the route name comes from the class: `App\Ui\Example\CounterPage` becomes `app_ui_example_counterpage`. On a function, use `#[Page]`, not `#[Route]` (E052).

The parameters of a function page get the same arguments as a Symfony controller: route values, the `Request`, services, `#[CurrentUser]` and the other value resolvers.

### The document of the pages

The application owns the HTML document. It’s a component in the `.phem` sources like any other: it returns the `<html>` element and prints the page content in a `<Root>`. Phem adds the `<!DOCTYPE html>`:

```php
<?phem

namespace App\Ui\Common;

use Phem\Attribute\Component;
use Phem\{Html, Node, Root};

#[Component]
function Document(?Html $title = null, ?Html $children = null): Node
{
    return (
        <html lang="en">
            <head>
                <title>{{ title ?? 'My app' }}</title>
                {{ importmap('app') }}
            </head>
            <body><Root>{{ children }}</Root></body>
        </html>
    );
}
```

Each page renders its content inside the document and sets its title with the `title` slot (`<Document:title>`), as `HomePage` does above. The document can have more slots, for example a `?Html $head` slot for the `<meta>` tags of a page. For another layout, such as an admin area, write another document component and use it in the pages of that area.

A `<title>` in the markup of a page isn’t the page title, and `phemc` reports it (W005). A `<title>` inside `<head>` or an `<svg>` is valid.

### The `<Root>` element

`<Root>` (`Phem\Root`) is the built-in component that renders the root element of a page. Import it with `use Phem\Root;`:

| In the document | Renders |
|---|---|
| `<Root>{{ children }}</Root>` | `<div id="phem-root" data-controller="phem" …>` with the page |
| `<Root tag="main" className="app">{{ children }}</Root>` | The same, with another tag and more attributes |

The root element carries the attributes of the `phem` Stimulus controller, which pass the component state to the controller. Its props work this way:

- **`tag`**: the tag name, `div` by default
- **Other props**: attributes, as on an element (`className`, a class list, `style`, `data-*`)
- **`id`**: `phem-root` by default. `id={{ null }}` removes it
- **`data-controller`, `data-action`**: your values come after the Phem values
- **State attributes**: you can’t change them, and the root element can’t have an event handler

A page renders exactly one `<Root>`. A page with no `<Root>` or with two is an error, and so is a `<Root>` in a Twig template outside a page.

Events render only the components that can change (see [Keep `render()` pure](components.md#keep-render-pure)), and the browser updates only the content of the `<Root>`. The document around it, such as the title or a footer, doesn’t change. A component after the root element renders, but the `phem` controller doesn’t receive its DOM events. The browser sends the events of a page to the page URL, and only a page route runs them.

### Route loading

A Symfony application (7.4 and later) imports the `routing.controllers` resource in `config/routes.yaml`, which holds the routes of the `#[Route]` controllers. `PhemBundle` adds the routes of the `#[Page]` pages to that resource, so the pages need no routes file:

```yaml
controllers:
    resource: routing.controllers
```

An application without this resource, created before Symfony 7.4, imports the pages with the `phem` type:

```yaml
# config/routes/phem.yaml
phem:
    resource: .
    type: phem
```

Use only one of the two. With both, each page gets its routes twice, and the last import replaces the routes of the first.

### Route values

A class page receives a route value in a public `#[Path]` property whose name is the name of the route value. A function page receives it in the parameter with that name:

```php
use Phem\Attribute\{Page, Path};

#[Page('/items/{id}', requirements: ['id' => '\d+'])]
final class ItemPage
{
    #[Path]
    public int $id;

    public function render(): Node
    {
        return <Document><h1>Item {{ id }}</h1></Document>;
    }
}

#[Page('/tags/{name}')]
function TagPage(string $name): Node
{
    return <Document><h1>{{ name }}</h1></Document>;
}
```

Each route value needs a `#[Path]` property or a parameter. Phem converts the value to the declared type, so `/items/3` sets `$id = 3`. A value that doesn’t fit the type returns a 404 response.

A Doctrine entity type receives the entity. `{scientist}` finds it by its identifier, and `{id:scientist}` maps the route value `id` to the property `scientist`:

```php
#[Page('/scientists/{id:scientist}')]
final class ScientistPage
{
    #[Path] public Scientist $scientist;
}
```

For other types, such as a date or a value object, see [Other types of route values](components.md#convert-route-values-to-your-own-types).

## Events and directives

Event attributes (`on*`), directives (`phem:*`) and `data-phem-*` attributes connect elements to server handlers and to client behavior.

### Event handlers

Pass a handler to an `on*` attribute:

```php
#[Component]
function MyButton(): Node
{
    $handleClick = fn () => error_log('You clicked me!');

    return <button onClick={{ handleClick }}>Click me</button>;
}
```

A handler can take these forms:

- **Closure variable**: `onClick={{ handleClick }}`, as above
- **Method name** in a class: `onClick={{ increment }}`
- **Member path**: `onClick={{ tabs.select }}` runs `$tabs->select()`
- **Prop of type `Closure`**
- **Call with arguments**: `onClick={{ remove(item) }}` runs `remove($item)` when the user clicks, not during the render. See [Pass arguments to a handler](events-and-forms.md#pass-arguments-to-a-handler)

The supported events are `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`, `onKeyDown`, `onKeyUp`, `onFocus` and `onBlur`. `phemc` warns about other events (W003). For what a handler receives and returns, see [Events and forms](events-and-forms.md).

### Directives (`phem:*`)

A `phem:*` attribute adds a Phem behavior to an element:

```php
<input phem:bind={{ query }} phem:live phem:debounce={{ 300 }} />
<span phem:loading={{ save }}>Saving…</span>
```

| Directive | Effect |
|---|---|
| `phem:bind={{ prop }}` | Links a form field to a `#[State]` property. See [Bound fields](events-and-forms.md#bind-a-field-to-state-without-a-handler) |
| `phem:live` | Sends each change of a bound field right away |
| `phem:loading={{ handler }}` | Shows the element only while the handler runs. See [Loading states](events-and-forms.md#show-a-loading-state) |
| `phem:debounce={{ ms }}` | Waits until the user pauses for `ms` milliseconds. `'blur'` waits until the field loses focus. See [Debounce and throttle](events-and-forms.md#control-when-typing-sends-an-event) |
| `phem:throttle={{ ms }}` | Sends at most one event every `ms` milliseconds |
| `phem:progress={{ handler }}` | Shows the progress of a file upload. See [File uploads](events-and-forms.md#accept-file-uploads) |

The value of `phem:bind` is a property name or a path in an array property: `phem:bind={{ filters.status }}`, `phem:bind={{ rows[i].name }}`. Write it with no spaces and no filter. `phemc` checks directive values (E032, E033).

### Data attributes (`data-phem-*`)

These `data-phem-*` attributes change how the browser treats an element:

| Attribute | Effect |
|---|---|
| `data-phem-client="hidden"` | The morph leaves the listed attributes unchanged. See [Client code with Stimulus](client-side.md#keep-stimulus-changes-through-the-morph) |
| `data-phem-ignore` | The morph leaves the element and its content unchanged |
| `data-phem-guard` | Asks the user to confirm before they leave the page with unsaved changes. See [Unsaved changes](events-and-forms.md#warn-about-unsaved-changes) |
| `data-phem-transition` | Animates the changes with the View Transitions API. See [Transitions](client-side.md#animate-changes-with-view-transitions) |

## Built-in components

Phem ships four built-in components in the `Phem` namespace: `<Dynamic>`, `<Root>`, `<Suspense>` and `<ErrorBoundary>`.

### Dynamic element

A tag name is static. To choose the tag at runtime, use the built-in `<Dynamic>` (`use Phem\Dynamic;`):

```php
#[Component]
function Button(string $tag = 'button', ?Html $children = null, mixed ...$rest): Node
{
    return (
        <Dynamic tag={{ tag }} className={{ classes('px-4 py-2') }} {{ ...rest }}>
            {{ children }}
        </Dynamic>
    );
}

<Button type="submit">Save</Button>          // <button class="px-4 py-2" type="submit">Save</button>
<Button tag="a" href="/docs">Docs</Button>   // <a class="px-4 py-2" href="/docs">Docs</a>
```

The other props become the attributes and handlers of the element. `<Dynamic>` renders an HTML element, not a component. Because the tag can come from data, the runtime rejects elements that can run a script or load a resource (`script`, `style`, `iframe`, `object`, `base`, …). Write those as static markup.

### Other built-in components

The other three built-in components handle the page root, deferred content and errors:

| Component | Use |
|---|---|
| `<Root>` (`Phem\Root`) | The root element of a page, in the document of the application. See [The `<Root>` element](#the-root-element) |
| `<Suspense>` (`Phem\Suspense`) | Loads its content after the page. Slots: `fallback`, `error`. See [Content that loads after the page](server-features.md#load-slow-content-after-the-page) |
| `<ErrorBoundary>` (`Phem\ErrorBoundary`) | Shows its `fallback` slot when its content throws. See [Errors in a part of the page](server-features.md#show-a-fallback-when-part-of-the-page-fails) |

## Markup as a PHP value

A markup literal is a value. `phemc` compiles `<p>{{ x }}</p>` to a `Phem\Element` object, and Phem renders it when markup prints it. Markup that doesn’t print, such as the other branch of a condition, renders no component. Don’t cast markup to a string in PHP.

`Phem\Node` is the type of markup. A markup literal is a `Phem\Element`, and a prop with markup (`$children`) is a `Phem\Html`; both are a `Node`. Use `?Node` for a component that can render nothing, and `Node|string` for a component that can return text.

The type of a prop decides what an array of markup becomes. A prop of type `array` gets a PHP array, so you can read or reorder the items. A prop of any other type gets a `Phem\HtmlList`, which prints as one block.

## Template components

A component whose body is only `return <markup>;` compiles to a plain Twig template. It renders with a Twig `include` or `embed` and no PHP call, so it’s faster. You write it like any other component.

## PHP attributes

The Phem PHP attributes live in the `Phem\Attribute` namespace:

| Attribute | On | Effect |
|---|---|---|
| `#[Component]` | a function or a class | Makes a component. `replaces:` replaces a component of a package (see [Replace a component of a package](using-packages.md#replace-a-component-of-a-package)) |
| `#[Page]` | a function or a class | Gives a component a URL |
| `#[ErrorPage]` | a function | Renders the page of an HTTP error. See [Error pages](error-pages.md) |
| `#[Prop]` | a property | Makes a prop. `rest: true` makes the rest prop |
| `#[State]` | a property | Makes state. See [State](components.md#keep-state-between-events) |
| `#[Path]` | a property | Receives a route value |
| `#[Url]` | a `#[State]` property | Keeps the value in the query string. See [State in the URL](components.md#keep-state-in-the-url) |
| `#[Provide]`, `#[Context]` | a class, a property or a parameter | Passes a value to the components below. See [Context](components.md#pass-a-value-down-the-tree-with-context) |
| `#[Validated]` | a handler | Validates the state before the handler runs. See [Live validation](events-and-forms.md#validate-a-form-while-the-user-types) |
| `#[Transactional]` | a class or a handler | Runs the handler in a transaction. See [Transactions](server-features.md#save-handler-changes-in-a-transaction) |
| `#[Broadcast]`, `#[BroadcastKey]` | a page, a component, a handler, a prop or a property | Sends real-time updates. See [Real-time updates](real-time-updates.md) |
| `#[Async]` | a handler | Runs slow work after the response. See [Slow work after the response](server-features.md#run-slow-work-after-the-response) |
