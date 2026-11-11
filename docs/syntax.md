# `.phem` syntax reference

A `.phem` file is a PHP file with markup literals in it. The `phemc` compiler turns each markup literal into a Twig template and keeps the PHP code as it is, so all parsing happens at build time. This page lists each syntax element: the file, components, markup, props, pages and routes, events and directives, built-in components, markup values and the PHP attributes.

For what the features do at runtime, read the topic pages: [Components and state](components.md), [Pages and routes](pages.md), [Events](events.md), [Forms](forms.md), [Client-side behavior](client-side.md), [Styling](styling.md), [Server features](resilience.md) and [Errors and limits](failed-events.md).

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

A tag renders a component: `<MyButton />` or `<Counter label="Clicks" />`. The compiler resolves the tag name with the PHP rules, in this order: a `use function` import, a `use` import, then the current namespace. A qualified name is a component too: `<Common\Card />` resolves its first segment with a `use` import or the current namespace, and `<\App\Ui\Card />` is fully qualified.

A function without `#[Component]` is a plain PHP function. PHP code can call it, but a tag can’t use it (E010).

In the markup of a class, a name resolves to a local variable, then a property, then a method. Properties and methods of a parent class and of its traits resolve too. For when to use a class, see [Components and state](components.md).

## Markup literals

Markup literals follow the rules below, and their text and attribute values are Twig expressions.

### Markup rules

Markup follows these rules:

- **Closed tags**: close every tag, including void elements (`<br />`, `<img ... />`)
- **One root**: return one root. To return several elements, wrap them in a fragment: `<>...</>`
- **Tag case**: a lowercase tag is an HTML element, and a capitalized or qualified tag (`<Common\Card />`) is a component
- **Attribute names**: you can write `className` for `class` and `htmlFor` for `for`
- **Start position**: a markup literal starts where a PHP expression starts: after `return`, `yield`, `echo`, `print`, `=`, `??=`, `(`, `,`, `=>`, `?`, `:`, `??`, `[`, `.`, `!`, `&&`, `||`, `and`, `or` or `xor`
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

`data-*`, `aria-*`, `value`, `contenteditable`, `draggable`, `spellcheck`, `focusable`, `autoreverse`, `externalResourcesRequired` and `preserveAlpha` print a boolean as text: `data-open={{ false }}` renders `data-open="false"`. Use `null` to remove them.

All Twig filters, tests and tags work. Don’t write PHP in markup: compute values in PHP before the `return`.

Phem doesn’t render a `javascript:` URL that comes from an expression in a URL attribute (`href`, `src`, `action`). A spread never renders an inline script, such as `onclick` with a string.

### Classes in markup

Markup can read the cases and the constants of a class, and link to a page, with the class name. Import the class with `use`, as in PHP:

```php
use App\Entity\Order;
use App\Entity\OrderStatus;
use App\Ui\Shop\ProductPage;
use Phem\Attribute\{Page, Path};
use Phem\Node;

use function App\Ui\Common\Document;

#[Page('/staff/orders/{id:order}')]
final class StaffOrderPage
{
    #[Path] public Order $order;

    public function render(): Node
    {
        return (
            <Document title="Order {{ order.id }}">
                {% if order.status == OrderStatus.Cancelled %}<p>Cancelled</p>{% endif %}
                <select name="status">
                    {% for s in OrderStatus.cases() %}<option key={{ s.value }} value={{ s.value }}>{{ s.name }}</option>{% endfor %}
                </select>
                <a href={{ path(StaffOrdersPage) }}>Back to the orders</a>
                <a href={{ path(ProductPage, {product: order.product}) }}>View in the shop</a>
            </Document>
        );
    }
}
```

`StaffOrdersPage` is a page in the same namespace, so it needs no import. The links render with the URLs of the routes:

```html
<a href="/staff/orders">Back to the orders</a>
<a href="/products/42">View in the shop</a>
```

A name that starts with an uppercase letter is a class when the file imports it with `use` (or `use function` for a function page), or when a component or an enum of the `.phem` files has this name in the namespace of the file. It isn’t a template variable. Each form compiles this way:

| You write | You get |
|---|---|
| `OrderStatus.Cancelled` | The case or the constant (`OrderStatus::Cancelled`) |
| `OrderStatus.cases()` | The list of the cases of the enum |
| `path(ProductPage)`, `path(ProductPage, {...})` | The path of the page (`/products/42`) |
| `url(ProductPage, {...})` | The absolute URL of the page (`https://example.com/products/42`) |

The values of `path()` and `url()` are by prop or by route value. For the route `/products/{id:product}`, `{product: product}` gives the route value `id` from the identifier of the entity, and `{id: 42}` works too. A backed enum gives its value, and the other values go to the query string. A page needs a `name:` in `#[Page]` only when you link to it with a string: `path('home')`.

`phemc` checks the names (E062, E063, E064). It knows the enums of the `.phem` files only. For a class in a `.php` file, Twig checks the case or the constant when it compiles the template: `phemc build` compiles the templates (`phem:warmup`), so it reports the error too. A static method call (`OrderStatus.from('paid')`) isn’t allowed: call it in PHP before the `return`.

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

The props of a class component are its public properties with `#[Prop]`. A prop with no default value is required, except a nullable prop: it gets `null`.

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

A value that isn’t a case is an error that lists the valid values. `phemc` checks a literal value at build time (E018). For classes per case, see [Variants of a component](package-authoring.md#add-variants-to-a-component).

### Merge of classes

`classes()` marks the default classes of a component, so a caller’s `className` merges into them and doesn’t replace them. It takes the same inputs as clsx: strings, lists and condition maps. See [Merge the classes of the caller](styling.md#merge-the-classes-of-the-caller).

## Pages

Add `#[Page]` to a class or a function to give it a URL. A page needs no `#[Component]`:

```php
#[Page('/about')]
function AboutPage(): Node
{
    return <Document title="About us"><h1>About us</h1></Document>;
}
```

`#[Page]` extends the Symfony `#[Route]` attribute and takes the same options. A class page receives a route value in a `#[Path]` property, and a function page in the parameter with that name. Markup links to a page with its name: `<a href={{ path(AboutPage) }}>About</a>` (see [Classes in markup](#classes-in-markup)). For the document, the `<Root>` element, the routes and the route values, see [Build pages and routes](pages.md).

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
- **Call with arguments**: `onClick={{ remove(item) }}` runs `remove($item)` when the user clicks, not during the render. See [Pass arguments to a handler](events.md#pass-arguments-to-a-handler)

The supported events are `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`, `onKeyDown`, `onKeyUp`, `onFocus`, `onBlur`, `onFocusIn`, `onFocusOut`, `onDragStart`, `onDragOver`, `onDrop`, `onPaste`, `onContextMenu`, `onPointerDown`, `onPointerUp`, `onScroll`, `onCall`, `onVisible` and `onPoll`. `phemc` warns about other events (W003). `onCall` runs when JavaScript code dispatches a `phem:call` event (see [Call a handler from your JavaScript](events.md#call-a-handler-from-your-javascript)). The browser sends `onVisible` when the element comes into view (see [Load more when an element comes into view](events.md#load-more-when-an-element-comes-into-view)), and `onPoll` at the interval of `phem:poll` (see [Poll the server](events.md#poll-the-server)). For what a handler receives and returns, see [Handle events](events.md).

### Directives (`phem:*`)

A `phem:*` attribute adds a Phem behavior to an element:

```php
<input phem:bind={{ query }} phem:live phem:debounce={{ 300 }} />
<span phem:loading={{ save }}>Saving…</span>
```

| Directive | Effect |
|---|---|
| `phem:bind={{ prop }}` | Links a form field to a `#[State]` property or to a `Bound` prop. On a component tag, it gives the property to the `Bound` prop of the child. See [Bound fields](forms.md#bind-a-field-to-state-without-a-handler) and [Bind a parent property from a child component](forms.md#bind-a-parent-property-from-a-child-component) |
| `phem:live` | Sends each change of a bound field right away. `phem:live={{ expr }}` turns it on only when the value is `true` |
| `phem:error={{ prop }}` | Renders the element only when the bound property has a validation error, with the id of the error and the message. See [Show the error of a bound field](forms.md#show-the-error-of-a-bound-field). It is not the `phem:error` event of the browser |
| `phem:loading={{ handler }}` | Shows the element only while the handler runs. See [Loading states](events.md#show-a-loading-state) |
| `phem:debounce={{ ms }}` | Waits until the user pauses for `ms` milliseconds. `'blur'` waits until the field loses focus. See [Debounce and throttle](events.md#control-when-typing-sends-an-event) |
| `phem:throttle={{ ms }}` | Sends at most one event every `ms` milliseconds |
| `phem:key="keys"` | Sends the `onKeyDown` or `onKeyUp` event only for these keys, such as `"Enter"` or `"ctrl+s Escape"`. See [Filter keys and stop events](events.md#filter-keys-and-stop-events) |
| `phem:shortcut="keys"` | Clicks the element, or focuses a field, when the user presses these keys on the page, such as `"mod+k"` or `"j ArrowDown"`. See [Add keyboard shortcuts](events.md#add-keyboard-shortcuts) |
| `phem:stop` | Keeps the events of the element and its content from the handlers around it |
| `phem:prevent` | Cancels the default action of the event of a handler of the element, such as the visit of a link |
| `phem:poll={{ ms }}` | Sends the `onPoll` event of the element every `ms` milliseconds while the tab is visible. `null` stops the poll. See [Poll the server](events.md#poll-the-server) |
| `phem:scroll-into-view` | After the morph of an event of the user, the browser scrolls to the element when the element is new. `phem:scroll-into-view={{ expr }}` turns it on only when the value is `true`. See [Scroll to a new item](events.md#scroll-to-a-new-item-after-an-event) |
| `phem:keep-scroll` | On an element that scrolls: the element in view stays at its place in each render. `phem:keep-scroll="end"` also keeps the element at its end when it is at its end (a chat). See [Keep the scroll position](feeds.md#keep-the-scroll-position-of-a-list) |
| `phem:progress={{ handler }}` | Shows the progress of a file upload. See [File uploads](forms.md#accept-file-uploads) |
| `phem:feed={{ prop }}` | Shows a `Feed` property: the element holds one `{% for %}` loop on the feed, with one element for each item. The browser keeps the items. See [Feeds](feeds.md) |

The value of `phem:bind` and `phem:error` is a property name, or a path in an array property or in a draft object: `phem:bind={{ filters.status }}`, `phem:bind={{ rows[i].name }}`, `phem:bind={{ draft.address.city }}`. Write it with no spaces and no filter. `phemc` checks directive values (E032, E033, E061, E065, E066).

### Data attributes (`data-phem-*`)

These `data-phem-*` attributes change how the browser treats an element:

| Attribute | Effect |
|---|---|
| `data-phem-client="hidden"` | The morph leaves the listed attributes unchanged. See [Client code with Stimulus](client-side.md#keep-stimulus-changes-through-the-morph) |
| `data-phem-ignore` | The morph leaves the element and its content unchanged |
| `data-phem-guard` | Asks the user to confirm before they leave the page with unsaved changes. See [Unsaved changes](forms.md#warn-about-unsaved-changes) |
| `data-phem-guard-dialog` | The selector of a `<dialog>` that asks the question of `data-phem-guard` in place of `window.confirm()`. See [Unsaved changes](forms.md#warn-about-unsaved-changes) |
| `data-phem-guard-accept` | The label of the accept button of the `data-phem-guard-dialog` dialog |
| `data-phem-guard-message-value` | On the `<Root>` element: the question of a `data-phem-guard` with no value. The default is "You have unsaved changes. Leave the page?" |
| `data-phem-disable={{ false }}` | On a form: does not lock the controls of the form while its submit event runs. See [The browser shows feedback while an event runs](architecture.md#the-browser-shows-feedback-while-an-event-runs) |
| `data-phem-autofocus-keyboard` | On a link or an event element: opens the iOS keyboard for the `phem-autofocus` field that shows after the round trip. See [Open the iOS keyboard](controllers.md#open-the-ios-keyboard-after-a-round-trip) |
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
| `<Root>` (`Phem\Root`) | The root element of a page, in the document of the application. See [The `<Root>` element](pages.md#render-the-root-element) |
| `<Suspense>` (`Phem\Suspense`) | Loads its content after the page. Slots: `fallback`, `error`. See [Content that loads after the page](resilience.md#load-slow-content-after-the-page) |
| `<ErrorBoundary>` (`Phem\ErrorBoundary`) | Shows its `fallback` slot when its content throws. See [Errors in a part of the page](resilience.md#show-a-fallback-when-part-of-the-page-fails) |

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
| `#[Component]` | a function or a class | Makes a component. `replaces:` replaces a component of a package (see [Replace a component of a package](packages.md#replace-a-component-of-a-package)) |
| `#[Page]` | a function or a class | Gives a component a URL |
| `#[ErrorPage]` | a function | Renders the page of an HTTP error. See [Error pages](error-pages.md) |
| `#[Prop]` | a property | Makes a prop. `rest: true` makes the rest prop |
| `#[State]` | a property | Makes state. See [State](components.md#keep-state-between-events) |
| `#[Path]` | a property | Receives a route value |
| `#[Url]` | a `#[State]` property | Keeps the value in the query string. See [State in the URL](components.md#keep-state-in-the-url) |
| `#[Provide]`, `#[Context]` | a class, a property or a parameter | Passes a value to the components below. See [Context](components.md#pass-a-value-down-the-tree-with-context) |
| `#[Validated]` | a handler | Validates the state before the handler runs. See [Live validation](forms.md#validate-a-form-while-the-user-types) |
| `#[MapForm]` | a handler parameter | Gives the handler the submitted form as an object. See [Map a form to an object](forms.md#map-a-form-to-an-object) |
| `#[Transactional]` | a class or a handler | Runs the handler in a transaction. See [Transactions](resilience.md#save-handler-changes-in-a-transaction) |
| `#[Broadcast]`, `#[BroadcastKey]` | a page, a component, a handler, a prop or a property | Sends real-time updates. `on:` refreshes after a change of an entity. See [Real-time updates](real-time.md) |
| `#[Async]` | a handler | Runs slow work after the response. See [Slow work after the response](resilience.md#run-slow-work-after-the-response) |
