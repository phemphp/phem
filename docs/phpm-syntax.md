# The `.phpm` syntax

A `.phpm` file is a PHP file with **markup literals** in it, as a `.jsx` file is JavaScript with
JSX in it. The `phemc` compiler turns each markup literal into a Twig template, and keeps the PHP
code as it is. All the parsing happens at build time.

This page shows how to write a `.phpm` file. To learn what the features do at runtime, read the
topic pages: [Components and state](components.md), [Events and forms](events-and-forms.md),
[Client-side behavior](client-side.md), [Server features](server-features.md) and
[Errors and limits](errors-and-limits.md).

## The file

Open PHP with `<?phpm`. A `<?php` tag is an error (E006). The rest of the file is normal PHP:
a namespace, `use` imports, functions and classes.

```php
<?phpm
namespace App\Component;

use Phem\Attribute\Component;
use Phem\Node;

#[Component]
function MyButton(): Node
{
    return <button>I'm a button</button>;
}
```

Phem needs PHP 8.4 or later. The syntax of PHP 8.5 (for example the `|>` operator) works only
when the app runs on PHP 8.5.

## Components

A component is a function or a class with the `#[Component]` attribute. A function component
returns markup, with the return type `Phem\Node`. A class component returns its markup from a
`render()` method:

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

Use a component with a tag: `<MyButton />` or `<Counter label="Clicks" />`. The compiler finds
the component of a tag with the PHP rules: a `use function` import, then a `use` import, then the
current namespace.

A function with no `#[Component]` is a plain PHP function. You can call it in PHP, but a tag
cannot use it (E010).

In the markup of a class, a name is a local variable, then a property, then a method. The
properties and the methods of a parent class and of its traits work too. To learn when to use a
class, see [Components and state](components.md).

## Markup

### Markup rules

Markup follows the rules of JSX. Close all the tags: `<br />`, `<img ... />`. Return one root. To
return more than one element, put them in a fragment: `<>...</>`. A lowercase tag is an HTML
element, and a capitalized tag is a component. You can write `className` for `class`, and
`htmlFor` for `for`.

A markup literal starts where a PHP expression starts: after `return`, `=`, `(`, `,`, `=>`, `?`,
`:`, `??`, `[` or `echo`. Put parentheses around markup on more than one line.

Phem marks the root element of each component with the id of the component (`data-phem-c`). When
the root is not one element (a fragment, a text, another component), Phem puts a `<phem-c>`
element around it. A `<phem-c>` is not valid in a `<table>`, a `<tr>` or a `<select>`, so a row
or an option component must return one element.

### Values and attributes

In markup, the text and the attribute values are Twig. The Twig variables are the PHP variables
of the scope: `$user` is `user`. The `app` global of Symfony is there too
(`{{ app.user.userIdentifier }}`).

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

| You write | You get |
|---|---|
| `{{ expr }}` in text | The value, escaped. Markup prints as HTML. |
| `attr="text {{ expr }}"` | A string with the value in it. |
| `attr={{ expr }}` | The value. `null` and `false` remove the attribute. `true` gives a boolean attribute. |
| `style={{ {...} }}` | A style from a hash. camelCase keys become kebab-case. Numbers get `px`, except for unitless properties (`opacity`, `zIndex`, ...). |
| `{{ ...attrs }}` in a tag | All the attributes of a hash. As in JSX, a later attribute replaces an earlier one. |

As in React, `data-*`, `aria-*` and `value` print a boolean as text: `data-open={{ false }}`
gives `data-open="false"`. Use `null` to remove them.

All the Twig filters, tests and tags work. Do not write PHP code in markup: compute the values in
PHP before the `return`.

Phem does not render a `javascript:` URL that comes from an expression in a URL attribute (`href`,
`src`, `action`). A spread never renders an inline script, such as `onclick` with a string.

### Conditions

A markup literal is a value, so a PHP `if` works:

```php
if ($isLoggedIn) {
    $content = <AdminPanel />;
} else {
    $content = <LoginForm />;
}

return <div>{{ content }}</div>;
```

You can also write the condition in Twig:

```twig
<div>{{ isLoggedIn ? <AdminPanel /> : <LoginForm /> }}</div>
<div>{{ isLoggedIn and <AdminPanel /> }}</div>   {# like && in JSX #}
<div>{% if isLoggedIn %}<AdminPanel />{% else %}<LoginForm />{% endif %}</div>
```

### Lists

Use a Twig loop, and give each item a `key`:

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

An array of markup also prints as a list, as `map()` in React:

```php
$items = array_map(fn ($product) => <li key={{ product.id }}>{{ product.title }}</li>, $products);

return <ul>{{ items }}</ul>;
```

After an event, the browser matches the items by their key. Thus an item keeps its element (its
focus, its client changes) when an item before it goes away.

## Props

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

```php
<Card title="Hello">
    <p>The content of the card.</p>
    <Card:footer>Updated today</Card:footer>
</Card>
```

A function component does not get services. Each parameter is a prop, also a parameter with a
service type, so the caller must give it. Get the data in a function page or in the `mount()` of a
class component, and give it as a prop. A class component gets its services in the constructor
(see [Services and `mount()`](components.md#services-and-mount)).

The props of a class component are its public properties with `#[Prop]`. A prop with no default
value is required.

`prop="text"` gives a string, `prop={{ expr }}` gives any value, and a bare `prop` gives `true`.
The content of the tag goes to the `$children` prop. A named slot (`<Card:footer>`) goes to the
prop with its name. A slot with the type `Html`, with no `?` and no default, is required: the
compiler reports E011 when the caller does not give it. An unknown prop is an error, unless the
component has rest props.

A normal Twig template of the app can also render a component, with no state:
`{% component 'App\\Component\\Profile' with {person: person} %}`.

### Rest props

A variadic parameter gets the props that no other parameter takes, as a hash. Spread it on an
element to forward the attributes and the handlers:

```php
#[Component]
function CountButton(int $count, mixed ...$rest): Node
{
    return <button type="button" {{ ...rest }}>Clicked {{ count }} times</button>;
}

<CountButton count={{ count }} onClick={{ handleClick }} className="primary" aria-label="Count" />
```

The order counts. The caller can replace `type`, because it comes before the spread. Put an
attribute after the spread to make it fixed. Use `mixed`, because the values can be strings,
booleans or handlers.

In a class, the rest prop is a property with `#[Prop(rest: true)]` and the type `array`:

```php
#[Prop(rest: true)] public array $attrs = [];
```

### Enum props

A prop with a backed enum type takes the value of a case. The caller writes the value, and Phem
converts it with `::from()`:

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

A value that is not a case is an error, with the list of the values. phemc checks a literal value
at build time (E018). For the classes of each case, see
[Variants of a component](packages.md#variants-of-a-component).

### Merge of classes

With a spread, a `className` of the caller replaces the classes of the component. To keep the
default classes, mark them with `classes()`. The classes of the caller then merge into them:

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

`classes()` before the spread gives defaults that the caller can change. `classes()` after the
spread gives classes that the caller cannot remove. `classes()` takes the same inputs as clsx:
strings, lists, and maps of conditions (`{'active': isActive}`). A `class` attribute takes a list
too: `className={{ ['btn', {'active': isActive}] }}`. In PHP code, use `Phem\classes()`.

`style` does not merge: the last value wins. The default merger removes only the duplicates. To
merge the Tailwind classes of the same group (`px-2` and then `px-4` gives `px-4`), see
[The class merger](packages.md#the-class-merger).

## Pages and routes

### Pages

A page is a component that has a URL. Add `#[Page]` to a class or to a function. A page needs no
`#[Component]`.

```php
use Phem\Attribute\Page;
use Phem\Node;

use function App\Component\Document;

#[Page('/', name: 'home')]
final class Home
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
function About(): Node
{
    return <Document><h1>About us</h1></Document>;
}
```

The page renders its content in the document of the app (see
[compiling.md](compiling.md#the-document-of-the-pages)), and gives its title with the `title`
slot.

`#[Page]` extends the `#[Route]` attribute of Symfony, so it has the same options: `name`,
`methods`, `requirements`, `defaults`, `host`, and the others. Use it more than one time to give
a page more than one URL. With no `name`, the route name comes from the class:
`App\Page\Example\Counter` gives `app_page_example_counter`. Use `#[Page]` on a function, not
`#[Route]` (E052).

The parameters of a function page get the same arguments as a Symfony controller: the route
values, the `Request`, the services, `#[CurrentUser]`, and the other value resolvers.

### Route values

A class page gets a route value in a public property with `#[Path]`. The name of the property is
the name of the route value. A function page gets it in the parameter with its name:

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
function Tag(string $name): Node
{
    return <Document><h1>{{ name }}</h1></Document>;
}
```

Each route value needs a `#[Path]` property or a parameter. Phem converts the value to the type:
`/items/3` gives `$id = 3`. A value that does not fit the type gives a 404 response.

A Doctrine entity type gets the entity. `{scientist}` finds it by its identifier, and
`{id:scientist}` maps the route value `id` to the property `scientist`:

```php
#[Page('/scientists/{id:scientist}')]
final class ScientistPage
{
    #[Path] public Scientist $scientist;
}
```

For other types (a date, a value object), see
[Other types of route values](components.md#other-types-of-route-values).

## Events and directives

### Event handlers

Give a handler to an `on*` attribute. Pass the function, do not call it:

```php
#[Component]
function MyButton(): Node
{
    $handleClick = fn () => error_log('You clicked me!');

    return <button onClick={{ handleClick }}>Click me</button>;
}
```

In a class, a method name is a handler: `onClick={{ increment }}`. A member path calls the method
of an object: `onClick={{ tabs.select }}` runs `$tabs->select()`. A handler can also be a prop
of the type `Closure`.

The supported events are `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`,
`onKeyDown`, `onKeyUp`, `onFocus` and `onBlur`. phemc warns about other events (W003). To learn
what a handler gets and what it can return, see [Events and forms](events-and-forms.md).

### Directives

A `phem:*` attribute gives an element a behavior of Phem:

```php
<input phem:bind={{ query }} phem:live phem:debounce={{ 300 }} />
<span phem:loading={{ save }}>Saving…</span>
```

| Directive | Effect |
|---|---|
| `phem:bind={{ prop }}` | Links a form field to a `#[State]` property. See [Bound fields](events-and-forms.md#bound-fields). |
| `phem:live` | Sends each change of a bound field at once. |
| `phem:loading={{ handler }}` | Shows the element only while the handler runs. See [Loading states](events-and-forms.md#loading-states). |
| `phem:debounce={{ ms }}` | Waits until the user stops for `ms` milliseconds. `'blur'` waits until the field loses the focus. See [Debounce and throttle](events-and-forms.md#debounce-and-throttle). |
| `phem:throttle={{ ms }}` | Sends at most one event in each `ms` milliseconds. |
| `phem:progress={{ handler }}` | Shows the progress of a file upload. See [File uploads](events-and-forms.md#file-uploads). |

The value of `phem:bind` is the name of a property, or a path in an array property:
`phem:bind={{ filters.status }}`, `phem:bind={{ rows[i].name }}`. Write it with no spaces and
with no filter. phemc checks the values of the directives (E032, E033).

### Data attributes

Some `data-phem-*` attributes change how the browser treats an element:

| Attribute | Effect |
|---|---|
| `data-phem-client="hidden"` | The morph does not change these attributes. See [Client code with Stimulus](client-side.md#client-code-with-stimulus). |
| `data-phem-ignore` | The morph does not change the element or its content. |
| `data-phem-guard` | Asks the user before they leave the page with unsaved changes. See [Unsaved changes](events-and-forms.md#unsaved-changes). |
| `data-phem-transition` | Animates the changes with the View Transitions API. See [Transitions](client-side.md#transitions). |

## Built-in components

### Dynamic element

A tag name is static. To choose the tag at runtime, use the built-in `<Dynamic>`
(`use Phem\Dynamic;`):

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

The other props are the attributes and the handlers of the element. `<Dynamic>` renders an HTML
element, not a component. The tag can come from data, so the runtime rejects the elements that can
run a script or load a resource (`script`, `style`, `iframe`, `object`, `base`, ...). Write them as
static markup.

### Other built-in components

| Component | Use |
|---|---|
| `<Root>` (`Phem\Root`) | The root element of a page, in the document of the app. See [compiling.md](compiling.md#the-document-of-the-pages). |
| `<Suspense>` (`Phem\Suspense`) | Loads its content after the page. Slots: `fallback`, `error`. See [Content that loads after the page](server-features.md#content-that-loads-after-the-page). |
| `<ErrorBoundary>` (`Phem\ErrorBoundary`) | Shows its `fallback` slot when its content throws. See [Errors in a part of the page](server-features.md#errors-in-a-part-of-the-page). |

## Markup values

A markup literal is a value, as a React element. phemc compiles `<p>{{ x }}</p>` to a
`Phem\Element` object, and Phem renders it when markup prints it. Markup that does not print (the
other branch of a condition) renders no component. Do not cast markup to a string in PHP.

`Phem\Node` is the type of markup, as `ReactNode`. A markup literal is a `Phem\Element`, and a
prop with markup (`$children`) is a `Phem\Html`. Both are a `Node`. Use `?Node` for a component
that can render nothing, and `Node|string` for a component that can return text.

The type of a prop decides what an array of markup becomes. A prop with the type `array` gets a
PHP array, so you can read or reorder the items. A prop with another type gets a `Phem\HtmlList`,
which prints as one block.

## Template components

A component whose body is only `return <markup>;` compiles to a plain Twig template. It renders
with a Twig `include` or `embed`, with no PHP call, so it is faster. You write it as any other
component.

## Attributes

The PHP attributes of Phem are in the `Phem\Attribute` namespace:

| Attribute | On | Effect |
|---|---|---|
| `#[Component]` | a function or a class | Makes a component. `replaces:` replaces a component of a package (see [packages.md](packages.md#replace-a-component-of-a-package)). |
| `#[Page]` | a function or a class | Gives a component a URL. |
| `#[Prop]` | a property | Makes a prop. `rest: true` makes the rest prop. |
| `#[State]` | a property | Makes state. See [State](components.md#state). |
| `#[Path]` | a property | Gets a route value. |
| `#[Url]` | a `#[State]` property | Keeps the value in the query string. See [State in the URL](components.md#state-in-the-url). |
| `#[Provide]`, `#[Context]` | a class, a property or a parameter | Gives a value to the components below. See [Context](components.md#context). |
| `#[Validated]` | a handler | Validates the state before the handler. See [Live validation](events-and-forms.md#live-validation). |
| `#[Transactional]` | a class or a handler | Runs the handler in a transaction. See [Transactions](server-features.md#transactions). |
| `#[Broadcast]`, `#[BroadcastKey]` | a page, a component, a handler or a prop | Sends real-time updates. See [Real-time updates](server-features.md#real-time-updates). |
| `#[Async]` | a handler | Runs slow work after the response. See [Slow work after the response](server-features.md#slow-work-after-the-response). |
