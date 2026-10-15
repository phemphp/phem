# The `.rhp` file format

A `.rhp` file is a PHP 8.6 file that can contain **markup literals**, the same way a `.jsx` file is a
JavaScript file that contains JSX. The `rhpc` compiler turns each markup literal into a Twig
template and turns the PHP code into plain PHP. All parsing happens at build time.

## 1. Components

A component is a PHP function whose name starts with a capital letter. It returns markup.

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

The `#[Page]` attribute marks the page component of a file in `src/pages/`. The file path is
the route: `src/pages/index.rhp` → `/`, `src/pages/learn/index.rhp` → `/learn`,
`src/pages/learn/thinking.rhp` → `/learn/thinking`. A file has at most one `#[Page]`.
React uses `export default` for this. `#[Page]` is the PHP way, so the file stays valid PHP.

```php
use Rephp\Attribute\Page;

#[Page]
function MyApp() {
    return (
        <div>
            <h1>Welcome to my app</h1>
            <MyButton />
        </div>
    );
}
```

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
- `{{ ...props }}` in a tag spreads a hash of attributes (or props, on a component).
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
`$e->value`, `$e->checked`, `$e->key`, and `$e->form` (an array, for `onSubmit`).
`onChange` on a text input fires on each key (as in React).

## 7. State: `useState`

```php
use function Rephp\useState;

function MyButton() {
    [$count, $setCount] = useState(0);

    $handleClick = fn() => $setCount($count + 1);

    return <button onClick={{ handleClick }}>Clicked {{ count }} times</button>;
}
```

- The setter takes a value or an updater: `$setCount(fn($c) => $c + 1)`.
- Each component instance has its own state.
- The state lives in the browser. The `rhp` Stimulus controller keeps it, and the server signs it.
  An event sends the state to the server. The server runs the handler, renders again, and sends
  back Turbo Streams that morph only the components that changed.
- State values must be JSON values (scalars and arrays).
- Other hooks: `useReducer($reducer, $initial)`, `useMemo($fn, $deps)`, `useCallback($fn, $deps)`,
  `useRef($initial)`, `useId()`, `useContext($context)`.

### Rules of Hooks

Call hooks only at the top level of a component (or of a custom hook, a function whose name
starts with `use`). Do not call hooks in `if`, loops, `match`, closures, or after an early
`return`. The compiler reports these errors.

## 8. Props and lifting state up

Props are named parameters.

```php
function MyButton(int $count, Closure $onClick) {
    return <button onClick={{ onClick }}>Clicked {{ count }} times</button>;
}

#[Page]
function MyApp() {
    [$count, $setCount] = useState(0);
    $handleClick = fn() => $setCount($count + 1);

    return (
        <div>
            <h1>Counters that update together</h1>
            <MyButton count={{ count }} onClick={{ handleClick }} />
            <MyButton count={{ count }} onClick={{ handleClick }} />
        </div>
    );
}
```

- `prop="text"` passes a string, `prop={{ expr }}` passes any value, and a bare `prop` passes `true`.
- Children go to the `?Html $children` parameter: `<Card>...</Card>`.
- Named slots: `<Card:footer>...</Card:footer>` goes to the `?Html $footer` parameter.
- An unknown prop is an error, unless the function has a variadic `...$rest` parameter.

## 9. Class components

```php
use Rephp\Attribute\{Component, State};

#[Component]
final class Counter
{
    public string $label = 'Clicked';   // prop
    #[State] public int $count = 0;     // state

    public function increment(): void
    {
        $this->count = clamp($this->count + 1, 0, 10);
    }

    public function render()
    {
        return <button onClick={{ increment }}>{{ label }} {{ count }} times</button>;
    }
}
```

In the markup of a class, a name is a local variable, then a property (`$this->name`),
then a method (`$this->name(...)`, so a method passes as a handler).

## 10. Context

```php
use Rephp\Attribute\Page;
use Rephp\Context;
use Rephp\Provider;
use function Rephp\useContext;

const ThemeContext = new Context('light');

function Toolbar() {
    $theme = useContext(ThemeContext);
    return <p className="theme-{{ theme }}">The theme is {{ theme }}</p>;
}

#[Page]
function App() {
    return (
        <Provider context="App\ThemeContext" value="dark">
            <Toolbar />
        </Provider>
    );
}
```

## 11. Template components (fast path)

A component whose body is only `return <markup>;` (no hooks, no PHP statements) compiles to a
plain Twig template. It renders through Twig `include` (no children) or `embed` (children and
slots, as Twig blocks), with no PHP call. You write it in the same way as other components.
