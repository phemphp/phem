# The `.rhp` file format

A `.rhp` file is a PHP 8.5 file that can contain **markup literals**, the same way a `.jsx` file is a
JavaScript file that contains JSX. The `rhpc` compiler turns each markup literal into a Twig
template and turns the PHP code into plain PHP. All parsing happens at build time.

## 1. Components

A component is a PHP function whose name starts with a capital letter. It returns markup.
A component with state is a `#[Component]` class (see section 7).

```php
<?php
namespace App\Components;

function MyButton() {
    return (
        <button>I'm a button</button>
    );
}
```

Use a component with a tag: `<MyButton />`. The compiler resolves the tag with the PHP rules:
a `use function` import, then a `use` (class) import, then the current namespace.

A page is a component with the Symfony `#[Route]` attribute. React uses `export default` for
the page component of a file. In rephp, the route attribute exposes a component at a URL, as it
does for a Symfony controller. The file stays valid PHP.

```php
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', name: 'home')]
function MyApp() {
    return (
        <div>
            <h1>Welcome to my app</h1>
            <MyButton />
        </div>
    );
}
```

The attribute has the usual options: `name`, `methods`, `requirements`, `defaults`, `host`,
`schemes`, `condition`, `priority`, `env`, `alias`, and localized paths. Use it more than once to
give a page more than one URL. Without a `name`, the route name comes from the component:
`App\Pages\Examples\Counter` → `app_pages_examples_counter`. A file can have any number of
pages, in any directory.

### Route values

A route value goes to the page as a prop. Mark the prop with `#[Path]`: a public property of a
class page, or a parameter of a function page. The name of the prop is the name of the route
value.

```php
use App\Repository\ScientistRepository;
use Rephp\Attribute\{Component, Path};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/scientists/{id}', requirements: ['id' => '\d+'])]
#[Component]
final class ScientistPage
{
    #[Path] public int $id;

    public function __construct(private readonly ScientistRepository $scientists)
    {
    }

    public function render()
    {
        $scientist = $this->scientists->find($this->id)
            ?? throw new NotFoundHttpException('No scientist with this id.');

        return <h1>{{ scientist.name }}</h1>;
    }
}

#[Route('/hello/{name}', defaults: ['name' => 'World'])]
function Hello(#[Path] string $name) {
    return <p>Hello {{ name }}</p>;
}
```

- The route values are the placeholders (`{id}`) and the `defaults` keys. The keys that start
  with `_` (`_locale`, `_format`, ...) are not route values.
- Each route value needs a `#[Path]` prop. A route value without one is an error when Symfony
  loads the routes.
- A prop without `#[Path]` gets no route value.
- A string goes to an `int`, `float` or `bool` prop as a value of that type: `/scientists/3`
  gives `$id = 3`. A value that does not match the type gives a 404 response. Use
  `requirements` to reject it before.
- The events of the page get the same route values. They go into the signed state of the page,
  so a client cannot change them.
- A `#[Path]` property cannot be `#[State]`, and a `#[Path]` parameter cannot be variadic.

## 2. Markup rules (the same as JSX)

- Close all tags: `<br />`, `<img ... />`.
- Return one root. To return more than one element, use a fragment: `<>...</>`.
- Lowercase tags are HTML elements. Capitalized tags are components.
- `className` is an alias for `class`, and `htmlFor` is an alias for `for`.
- A markup literal starts where a PHP expression starts: after `return`, `(`, `=`, `,`, `=>`, `?`, `:`, `??`, `[`, `echo`.

## 3. Twig in markup

Inside markup, the text and the attribute values are Twig. The Twig variables are the PHP
variables of the scope that contains the markup (`$user` is `user` in Twig).

```php
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
- `style={{ {...} }}` takes a hash. camelCase keys become kebab-case. Numbers get `px`
  (except unitless properties such as `opacity`, `zIndex`, `fontWeight`, `lineHeight`, `flex`).
- `{{ ...props }}` in a tag spreads a hash of attributes (or props, on a component). As in JSX,
  the order counts: a later value replaces an earlier one. `<button type="button" {{ ...rest }}>`
  gives a default that `rest` can replace. `on*` keys (`onClick`, ...) bind handlers.
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
function MyButton() {
    $handleClick = fn() => error_log('You clicked me!');

    return <button onClick={{ handleClick }}>Click me</button>;
}
```

Supported events: `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`, `onKeyDown`, `onKeyUp`,
`onFocus`, `onBlur`. The handler runs on the server. It receives a `Rephp\Event` object with
`$e->value`, `$e->checked`, `$e->key`, `$e->form` (an array, for `onSubmit`), and `$e->request`.
`onChange` on a text input fires on each key (as in React).

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
browser goes to its URL (a Turbo visit). For example, after a delete:

```php
use Symfony\Component\HttpFoundation\RedirectResponse;

public function delete(): RedirectResponse
{
    $this->scientists->remove($this->scientist);

    return new RedirectResponse('/scientists');
}
```

## 7. State: class components

A component with state is a class with the `#[Component]` attribute and a `render()` method.

```php
use Rephp\Attribute\{Component, State};

#[Component]
final class Counter
{
    public string $label = 'Clicked';   // prop
    #[State] public int $count = 0;     // state

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

- A public property is a prop. A property without a default value (and not nullable) is a
  required prop.
- A `#[State]` property is state. A method changes it, and rephp calls `render()` again.
- In the markup of a class, a name is a local variable, then a property (`$this->name`),
  then a method (`$this->name(...)`, so a method passes as a handler).
- Each component instance has its own state.
- The state lives in the browser. The `rhp` Stimulus controller keeps it, and the server signs it.
  An event sends the state to the server. The server makes the component again, restores the
  state, runs the handler, renders again, and sends back Turbo Streams that morph only the
  components that changed.
- State values must be JSON values (scalars and arrays).

rephp has no Hooks. A call to `Rephp\useState` (or `useReducer`, `useRef`, `useMemo`,
`useCallback`, `useId`, `useContext`) is the compiler error E020.

## 8. Services and `mount()`

A class component is a Symfony service. The container makes a new object for each component
instance, and the constructor gets its dependencies (autowiring). Constructor parameters are
never props.

```php
use App\Repository\TodoRepository;
use Rephp\Attribute\{Component, State};

#[Component]
final class TodoList
{
    public int $limit = 10;                // prop
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
2. rephp writes the props into the public properties.
3. If the browser sent state for this component, rephp writes it into the `#[State]`
   properties. If not, rephp calls `mount()` (when the class has it).
4. rephp calls the event handler (for an event), then `render()`.

A component without `#[State]` properties has no state in the browser, so `mount()` runs on each
request. To configure a component service (for example to bind a scalar argument), define it in
`config/services.yaml` with its class name as the id. rephp keeps your definition and only makes
it not shared.

### Transactions

Add `#[Transactional]` to a class component to run each of its event handlers in a Doctrine ORM
transaction (this needs DoctrineBundle). When the handler returns, rephp flushes and commits.
When the handler throws an exception, rephp rolls back all of its changes. Thus the handler
does not call `flush()`:

- To add an entity, call `persist()` (for example through a repository method).
- To delete an entity, call `remove()`.
- To change an entity that Doctrine loaded, set its properties. The flush finds the changes.

```php
use Rephp\Attribute\{Component, Transactional};

#[Component]
#[Transactional]
final class TodoItem
{
    public Todo $todo;                     // prop: a managed entity

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

The transaction contains only the handler. `render()` and `mount()` run outside of it. A handler
that a parent passes as a prop belongs to the parent: the parent's attribute applies.

## 9. Props and lifting state up

The props of a function component are its named parameters. A function component has no state.
It can receive a handler as a prop.

```php
function MyButton(int $count, Closure $onClick) {
    return <button onClick={{ onClick }}>Clicked {{ count }} times</button>;
}

#[Component]
#[Route('/counters')]
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
            <div>
                <h1>Counters that update together</h1>
                <MyButton count={{ count }} onClick={{ handleClick }} />
                <MyButton count={{ count }} onClick={{ handleClick }} />
            </div>
        );
    }
}
```

- `prop="text"` passes a string, `prop={{ expr }}` passes any value, and a bare `prop` passes `true`.
- Children go to the `?Html $children` parameter (or public property): `<Card>...</Card>`.
- Named slots: `<Card:footer>...</Card:footer>` goes to the `?Html $footer` parameter.
- A default for children or a slot: `{{ footer ?? 'No footer' }}` shows when the caller gives none.
- An unknown prop is an error, unless the function has a variadic `...$rest` parameter.

### Rest props

A variadic parameter collects the props that no other parameter takes, as a hash. This is
`function CountButton({ count, ...rest })` in React. Spread it on an element to forward the
attributes and the handlers:

```php
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

## 10. Markup values

A markup literal is a value, like a React element: rhpc compiles `<p>{{ x }}</p>` to
`new \Rephp\Element('file.0.twig', ['x' => $x])`. rephp renders it when the component returns
it, or when markup prints it (`{{ content }}`). An array of markup prints as a list. Do not cast
a markup literal to a string in PHP.

Components also work in the normal Twig templates of the application, without state:
`{% component 'App\\Components\\Profile' with {person: person} %}`.

## 11. Template components (fast path)

A component whose body is only `return <markup>;` (no PHP statements) compiles to a
plain Twig template. It renders through Twig `include` (no children) or `embed` (children and
slots, as Twig blocks), with no PHP call. You write it in the same way as other components.
