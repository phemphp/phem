# The `.phpm` file format

A `.phpm` file is a PHP 8.5 file that can contain **markup literals**, the same way a `.jsx` file is a
JavaScript file that contains JSX. The `phemc` compiler turns each markup literal into a Twig
template and turns the PHP code into plain PHP. All parsing happens at build time.

A `.phpm` file opens PHP with `<?phpm`. A plain `<?php` tag is an error (E006). The compiler
writes `<?php` in the PHP that it generates.

## 1. Components

A component is a PHP function with the `#[Component]` attribute. It returns markup.
As in React, a string that it returns is text: Phem escapes it.
A component with state is a `#[Component]` class (see section 7).

```php
<?phpm
namespace App\Components;

use Phem\Attribute\Component;

#[Component]
function MyButton() {
    return (
        <button>I'm a button</button>
    );
}

// A plain PHP function: it is not a component.
function initials(string $name): string {
    return strtoupper($name[0]);
}
```

A function without `#[Component]` is a plain PHP function, as in a `.php` file. Its name can
start with a capital letter or not. A tag cannot use it (E010), and the manifest does not
list it. Without `use Phem\Attribute\Component;`, `#[Component]` is another attribute (W004).

Use a component with a tag: `<MyButton />`. The compiler resolves the tag with the PHP rules:
a `use function` import, then a `use` (class) import, then the current namespace.

A page is a class with the `#[Page]` attribute. React uses `export default` for the page
component of a file. In Phem, `#[Page]` exposes a class at a URL, as `#[Route]` does for a
Symfony controller. The file stays valid PHP. A page class is a component: it needs no
`#[Component]`. It renders its content in the document of the application, a component that
renders the `<html>` element and the `<Root>` of the page (see
[compiling.md](compiling.md#the-document-of-the-pages)). The page gives the title with the
`title` slot of the document. A function cannot be a page.

```php
use Phem\Attribute\Page;

use function App\Components\Document;

#[Page('/', name: 'home')]
final class MyApp
{
    public function render()
    {
        return (
            <Document>
                <Document:title>Home</Document:title>
                <h1>Welcome to my app</h1>
                <MyButton />
            </Document>
        );
    }
}
```

`#[Page]` extends the Symfony `#[Route]` attribute, and it has the same options: `name`, `methods`, `requirements`, `defaults`, `host`,
`schemes`, `condition`, `priority`, `env`, `alias`, and localized paths. Use it more than once to
give a page more than one URL. Without a `name`, the route name comes from the component:
`App\Pages\Examples\Counter` → `app_pages_examples_counter`. A file can have any number of
pages, in any directory. The Symfony `#[Route]` attribute works too, on a `#[Component]` class.

### Route values

A route value goes to the page as a prop. Mark the public property with `#[Path]` (it needs no
`#[Prop]`). The name of the property is the name of the route value.

```php
use Phem\Attribute\{Page, Path};

use function App\Components\Document;

#[Page('/hello/{name}', defaults: ['name' => 'World'])]
final class Hello
{
    #[Path] public string $name;

    public function render()
    {
        return <Document><p>Hello {{ name }}</p></Document>;
    }
}

#[Page('/items/{id}', requirements: ['id' => '\d+'])]
final class ItemPage
{
    #[Path] public int $id;

    public function render()
    {
        return <Document><h1>Item {{ id }}</h1></Document>;
    }
}
```

- The route values are the placeholders (`{id}`) and the `defaults` keys. The keys that start
  with `_` (`_locale`, `_format`, ...) are not route values.
- Each route value needs a `#[Path]` prop. A route value without one is an error when Symfony
  loads the routes.
- A prop without `#[Path]` gets no route value.
- A string goes to an `int`, `float` or `bool` prop as a value of that type: `/items/3`
  gives `$id = 3`. A value that does not match the type gives a 404 response. Use
  `requirements` to reject it before.
- The events of the page get the same route values. They go into the signed state of the page,
  so a client cannot change them.
- A `#[Path]` property cannot be `#[State]`.

### Entities from route values

A `#[Path]` prop with a Doctrine entity type gets the entity. The rules are the same as for the
entity arguments of a Symfony controller. Install `doctrine/orm` and `doctrine/doctrine-bundle`.

```php
use App\Model\Scientist;
use Phem\Attribute\{Page, Path};

use function App\Components\Document;

#[Page('/scientists/{id:scientist}', requirements: ['id' => '\d+'])]
final class ScientistPage
{
    #[Path] public Scientist $scientist;

    public function render()
    {
        return (
            <Document>
                <Document:title>{{ scientist.name }}</Document:title>
                <h1>{{ scientist.name }}</h1>
            </Document>
        );
    }
}
```

- `{scientist}` (the name of the prop) finds the entity by its identifier: `find($value)`.
- `{id:scientist}` maps the route value `id` to the prop `scientist`, and finds the entity by
  the field `id`: `findOneBy(['id' => $value])`. `{slug:scientist}` finds it by the field
  `slug`.
- `{slug:post.title}` finds the `post` entity by the field `title`. Use more than one mapping
  for more than one field: `/{year:post.year}/{slug:post.slug}`.
- When no entity matches, the response is a 404. A nullable prop (`?Scientist`) gets `null`.
- The signed state keeps the route values, not the entity. Each event finds the entity again.
  When the entity is not there any more, the browser loads the page again.
- A mapped route value needs a `#[Path]` prop with an entity type. Other class types are an
  error.

## 2. Markup rules (the same as JSX)

- Close all tags: `<br />`, `<img ... />`.
- Return one root. To return more than one element, use a fragment: `<>...</>`.
- Lowercase tags are HTML elements. Capitalized tags are components.
- `className` is an alias for `class`, and `htmlFor` is an alias for `for`.
- A markup literal starts where a PHP expression starts: after `return`, `(`, `=`, `,`, `=>`, `?`, `:`, `??`, `[`, `echo`.

## 3. Twig in markup

Inside markup, the text and the attribute values are Twig. The Twig variables are the PHP
variables of the scope that contains the markup (`$user` is `user` in Twig). The Twig global
`app` of Symfony is also available (`{{ app.user.userIdentifier }}`).

```php
#[Component]
function Profile() {
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

- `{{ expr }}` in text prints the value. Twig escapes it, except `Html` values (markup).
- `attr="text {{ expr }}"` is a string with interpolation.
- `attr={{ expr }}` is a dynamic value. `null` and `false` remove the attribute. `true` makes a
  boolean attribute. For `class`, a list is joined with spaces (empty items are dropped).
  As in React, `data-*`, `aria-*`, `contenteditable`, `draggable`, `spellcheck` and `value`
  take the text of a boolean: `data-open={{ false }}` gives `data-open="false"`. A CSS selector
  `[data-open]` and `hasAttribute()` find it; use `null` to remove the attribute.
- `style={{ {...} }}` takes a hash. camelCase keys become kebab-case. Numbers get `px`
  (except unitless properties such as `opacity`, `zIndex`, `fontWeight`, `lineHeight`, `flex`).
- `{{ ...props }}` in a tag spreads a hash of attributes (or props, on a component). As in JSX,
  the order counts: a later value replaces an earlier one. `<button type="button" {{ ...rest }}>`
  gives a default that `rest` can replace. `on*` keys (`onClick`, ...) bind handlers.
  A spread can contain data, so it does not render an invalid attribute name or an inline
  script (`onclick`, with a string). A static `onclick="..."` in the markup stays.
- All Twig filters, tests, and tags work: `|upper`, `|length`, `{% set %}`, `{% for %}`, and more.
- Do not put PHP code in markup. Compute values in PHP before the `return`.

## 4. Conditional rendering

```php
// if/else in PHP: a markup literal is a value (an Html object)
if ($isLoggedIn) {
    $content = <AdminPanel />;
} else {
    $content = <LoginForm />;
}
return <div>{{ content }}</div>;
```

```twig
<div>{{ isLoggedIn ? <AdminPanel /> : <LoginForm /> }}</div>   {# ternary with markup #}
<div>{{ isLoggedIn and <AdminPanel /> }}</div>                 {# like JSX && #}
<div>{% if isLoggedIn %}<AdminPanel />{% else %}<LoginForm />{% endif %}</div>
```

## 5. Lists and keys

```php
#[Component]
function ShoppingList() {
    $products = [
        ['title' => 'Cabbage', 'isFruit' => false, 'id' => 1],
        ['title' => 'Garlic', 'isFruit' => false, 'id' => 2],
        ['title' => 'Apple', 'isFruit' => true, 'id' => 3],
    ];

    return (
        <ul>
            {% for product in products %}
                <li key={{ product.id }} style={{ {color: product.isFruit ? 'magenta' : 'darkgreen'} }}>
                    {{ product.title }}
                </li>
            {% endfor %}
        </ul>
    );
}
```

Or in PHP, like `map()`: an array of markup prints as a list.

```php
$listItems = array_map(fn($product) => <li key={{ product.id }}>{{ product.title }}</li>, $products);
return <ul>{{ listItems }}</ul>;
```

## 6. Events

Pass the function. Do not call it.

```php
#[Component]
function MyButton() {
    $handleClick = fn() => error_log('You clicked me!');

    return <button onClick={{ handleClick }}>Click me</button>;
}
```

Supported events: `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`, `onKeyDown`, `onKeyUp`,
`onFocus`, `onBlur`. phemc warns about another event (W003): the browser does not send it.
The handler runs on the server. It receives a `Phem\Event` object with `$e->value`,
`$e->checked`, `$e->key`, `$e->form` (an array, for `onSubmit`), and `$e->request`.
`onChange` on a text input fires on each key (as in React).

`$e->form` has the fields of the form by name. A name with more than one value (a
`<select multiple>`, checkboxes) has the list of its values. The button that submits the form
is a field too. The browser does not send the files of a form.

The id of a handler comes from the handler (its method or closure, and the values that it
captures, such as `fn () => $this->remove($scientist)`), not from its position. When the data
changes before a click (another user removes a row), a click never runs the handler of
another row: the page reloads when its handler is not there any more. A captured Doctrine
entity counts by its identifier.

An event is a `POST` request to the URL of the page, with the `X-Phem-Event` header. Thus the
access rules of the page apply to its events too: `access_control`, the firewall, and the host
and condition of the `#[Page]`. The controller attributes of Symfony (`#[IsGranted]`, `#[Cache]`)
apply to a page class, for the page and for its events. Phem accepts an event only
as JSON from the same origin (`Sec-Fetch-Site`, or `Origin`), so another site cannot send one
(CSRF). A `POST` request without the header renders the page, if its route accepts `POST`.

`$e->request` is the Symfony `Request` of the event, with the form fields as its POST data.
The keys with brackets become nested arrays (`scientist[name]` → `['scientist' => ['name' => ...]]`),
as in a normal POST. A Symfony form can read it directly:

```php
public function add(Event $e): void
{
    $form = $this->forms->create(ScientistType::class);
    $form->handleRequest($e->request);
    if ($form->isSubmitted() && $form->isValid()) {
        // $form->getData()
    }
}
```

To render the form, keep it in a property. A `get` hook (PHP 8.4 property hooks) makes a new
form when no handler made one, so `render()` shows the validation errors after a failed submit
and an empty form in the other renders:

```php
private ?FormInterface $form = null {
    get => $this->form ??= $this->forms->create(ScientistType::class);
}

public function render()
{
    $view = $this->form->createView();

    return (
        <form onSubmit={{ add }}>
            {{ form_widget(view.name) }}
            {{ form_errors(view.name) }}
            <button type="submit">Add</button>
        </form>
    );
}
```

A handler can return a Symfony `RedirectResponse`. Then the page does not render again: the
browser goes to its URL (a Turbo visit). The URL is relative, or an `http:` or `https:` URL:
another scheme (`javascript:`) is an error. For example, after a delete:

```php
use Symfony\Component\HttpFoundation\RedirectResponse;

public function delete(): RedirectResponse
{
    $this->scientists->remove($this->scientist);

    return new RedirectResponse('/scientists');
}
```

A handler can send browser events with the dispatcher of its event:
`$e->dispatcher->dispatch($name, $detail)`. After the morph, the
browser dispatches each one on `window` as a `CustomEvent` named `phem:<name>`. The detail is
any value that JSON can encode. A Stimulus controller or plain JS listens to it:

```php
public function save(Event $e): void
{
    $this->scientists->save($this->scientist);
    $e->dispatcher->dispatch('toast', ['message' => 'Saved']);
}
```

```html
<div data-controller="toast" data-action="phem:toast@window->toast#show"></div>
```

The events go in their order. A handler that fails, or that returns a `RedirectResponse`,
sends no events: the browser leaves the page.

## 7. State: class components

A component with state is a class with the `#[Component]` attribute and a `render()` method.
A page class (`#[Page]`) is a component too, with no `#[Component]`.

```php
use Phem\Attribute\{Component, Prop, State};

#[Component]
final class Counter
{
    #[Prop] public string $label = 'Clicked';   // prop
    #[State] public int $count = 0;             // state

    public function increment(): void
    {
        $this->count = min($this->count + 1, 10);
    }

    public function render()
    {
        return <button onClick={{ increment }}>{{ label }} {{ count }} times</button>;
    }
}
```

- A `#[Prop]` property is a prop. It must be public, not static, not promoted, and not
  `#[State]` (compiler error E042). A prop without a default value (and not nullable) is a
  required prop. A public property without `#[Prop]` is not a prop: the markup cannot set it.
- A `#[Prop(rest: true)]` property is the rest prop: it gets the props that no other prop
  takes (see [Rest props](#rest-props)).
- A `#[State]` property is state. A method changes it, and Phem calls `render()` again.
- In the markup of a class, a name is a local variable, then a property (`$this->name`),
  then a method (`$this->name(...)`, so a method passes as a handler).
- The props, the properties and the methods of a parent class (`extends`) and of the traits
  are also available. When the parent class or a trait is not in a `.phpm` file, phemc does not
  see it: it does not check the props and the slots of the component, and it does not report
  the names that it cannot find.
- Each component instance has its own state.
- The state lives in the browser. The `phem` Stimulus controller keeps it, and the server signs it.
  An event sends the state to the server. The server makes the component again, restores the
  state, runs the handler, renders again, and sends back Turbo Streams that morph only the
  components that changed.
- State values must be JSON values (scalars and arrays).
- The state is UI state, not trusted data. The signature stops a forged state, but the browser
  can send back any state that the server signed for the page before: an older state (so a
  user can undo a change), or the state of another visitor. Keep limits (tries, votes) and
  security decisions in the database or the session, not in `#[State]`. Two options make the
  window smaller: a signed state is valid for `phem.state_max_age` seconds (default 86400; 0
  for no limit), and `phem.state_bind_session: true` makes it valid only in its session (a
  login then reloads the open pages on their next event). An invalid state reloads the page.

Phem has no Hooks. A call to `Phem\useState` (or `useReducer`, `useRef`, `useMemo`,
`useCallback`, `useId`, `useContext`) is the compiler error E020.

## 8. Services and `mount()`

A class component is a Symfony service. The container makes a new object for each component
instance, and the constructor gets its dependencies (autowiring). Constructor parameters are
never props.

```php
use App\Repository\TodoRepository;
use Phem\Attribute\{Component, Prop, State};

#[Component]
final class TodoList
{
    #[Prop] public int $limit = 10;        // prop
    #[State] public array $todos = [];     // state

    public function __construct(private readonly TodoRepository $repository)
    {
    }

    // Runs when the component has no state yet: on its first render. The props are set.
    public function mount(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

    public function refresh(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

    public function render()
    {
        return (
            <section>
                <ul>{% for todo in todos %}<li key={{ todo.id }}>{{ todo.title }}</li>{% endfor %}</ul>
                <button onClick={{ refresh }}>Refresh</button>
            </section>
        );
    }
}
```

The order for each component in a request:

1. The container makes the object (constructor injection).
2. Phem writes the props into the `#[Prop]` properties.
3. If the browser sent state for this component, Phem writes it into the `#[State]`
   properties. If not, Phem calls `mount()` (when the class has it).
4. Phem calls the event handler (for an event), then `render()`.

A component without `#[State]` properties has no state in the browser, so `mount()` runs on each
request. To configure a component service (for example to bind a scalar argument), define it in
`config/services.yaml` with its class name as the id. Phem keeps your definition and only makes
it not shared.

### Transactions

When DoctrineBundle is installed, Phem runs each event handler in a Doctrine ORM transaction.
When the handler returns, Phem flushes and commits. When the handler throws an exception, Phem
rolls back all of its changes. Thus the handler does not call `flush()`:

- To add an entity, call `persist()` (for example through a repository method).
- To delete an entity, call `remove()`.
- To change an entity that Doctrine loaded, set its properties. The flush finds the changes.

```php
use Phem\Attribute\{Component, Prop};

#[Component]
final class TodoItem
{
    #[Prop] public Todo $todo;             // prop: a managed entity

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function toggle(): void
    {
        $this->todo->done = !$this->todo->done;   // saved at the commit
    }

    public function delete(): void
    {
        $this->em->remove($this->todo);           // deleted at the commit
    }

    // render() ...
}
```

The transaction contains only the handler. `render()` and `mount()` run outside of it.

The `transactional` option controls this behavior. Its default is `true` when Doctrine ORM is
installed and enabled, and `false` if not. To select the components that use a transaction, set
the option to `false` and add `#[Transactional]` to each of these class components:

```yaml
# config/packages/phem.yaml
phem:
    transactional: false
```

```php
use Phem\Attribute\{Component, Transactional};

#[Component]
#[Transactional]
final class TodoItem
{
    // ...
}
```

A handler that a parent passes as a prop belongs to the parent: the parent's attribute applies.

## 9. Props and lifting state up

The props of a function component (a function with `#[Component]`) are its named parameters.
A function component has no state.
It can receive a handler as a prop.

```php
#[Component]
function MyButton(int $count, Closure $onClick) {
    return <button onClick={{ onClick }}>Clicked {{ count }} times</button>;
}

#[Page('/counters')]
final class MyApp
{
    #[State] public int $count = 0;

    public function handleClick(): void
    {
        ++$this->count;
    }

    public function render()
    {
        return (
            <Document>
                <h1>Counters that update together</h1>
                <MyButton count={{ count }} onClick={{ handleClick }} />
                <MyButton count={{ count }} onClick={{ handleClick }} />
            </Document>
        );
    }
}
```

- `prop="text"` passes a string, `prop={{ expr }}` passes any value, and a bare `prop` passes `true`.
- Children go to the `?Html $children` parameter (or `#[Prop]` property): `<Card>...</Card>`.
- Named slots: `<Card:footer>...</Card:footer>` goes to the `?Html $footer` parameter.
- A default for children or a slot: `{{ footer ?? 'No footer' }}` shows when the caller gives none.
- An unknown prop is an error, unless the function has a variadic `...$rest` parameter (or
  the class a `#[Prop(rest: true)]` property).
- `<Root>` (`use Phem\Root;`) is a built-in component: the root element of a page, in the
  document. It takes `tag` and any attribute (see [compiling.md](compiling.md#the-document-of-the-pages)).

### Rest props

A variadic parameter collects the props that no other parameter takes, as a hash. This is
`function CountButton({ count, ...rest })` in React. Spread it on an element to forward the
attributes and the handlers:

```php
#[Component]
function CountButton(int $count, mixed ...$rest) {
    return <button type="button" {{ ...rest }}>Clicked {{ count }} times</button>;
}

<CountButton count={{ count }} onClick={{ handleClick }} className="primary" aria-label="Count" />
```

The button gets `class`, `aria-label`, and the click handler. The caller can also replace
`type`, because the spread comes after it. Put an attribute after the spread to make it fixed.
Use `mixed` (or no type) for the variadic parameter, because the values can be strings,
booleans, or handlers. To forward the props to another component, spread them on its tag:
`<CountButton count={{ 1 }} {{ ...rest }} />`.

A class component has a rest prop for this: a `#[Prop(rest: true)]` property. It gets the same
hash, and it is empty when the caller gives no other props. You choose its name:

```php
#[Component]
final class CountButton
{
    #[Prop] public int $count = 0;
    #[Prop(rest: true)] public array $attrs = [];

    public function render()
    {
        return <button type="button" {{ ...attrs }}>Clicked {{ count }} times</button>;
    }
}
```

A class has at most one rest prop, and its type must accept an array: `array`, `iterable`,
`mixed`, or no type (compiler error E043). The other rules of a prop also apply to it (E042).
The rest prop is not a named prop: `<CountButton attrs="x" />` puts `attrs` in the hash.

## 10. Markup values

A markup literal is a value, like a React element: phemc compiles `<p>{{ x }}</p>` to
`new \Phem\Element('file.0.twig', ['x' => $x])`. Phem renders it when markup prints it
(`{{ content }}`), or when the component returns it. Markup that does not print (the other
branch of `{{ on ? yes : no }}`) renders no component. An array of markup prints as a list,
and its `null` and `false` items print nothing. Do not cast a markup literal to a string in PHP.

Components also work in the normal Twig templates of the application, without state:
`{% component 'App\\Components\\Profile' with {person: person} %}`.

## 11. Template components (fast path)

A component whose body is only `return <markup>;` (no PHP statements) compiles to a
plain Twig template. It renders through Twig `include` (no children) or `embed` (children and
slots, as Twig blocks), with no PHP call. You write it in the same way as other components.
