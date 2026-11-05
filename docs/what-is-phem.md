# What is Phem

Phem is a component layer for Symfony web apps. You write the logic and the markup of a
component in one `.phem` file. The server renders the HTML, runs the event handlers, and updates
only the parts of the page that change. You write no JavaScript, no API and no client store.

This page gives the five ideas of Phem, with one small example each. Then do
[Getting started](getting-started.md) and the [tutorial](tutorial.md).

## 1. A `.phem` file is PHP with markup in it

A `.phem` file is a PHP file that can contain markup, as a `.jsx` file is a JavaScript file that
contains JSX. The markup is Twig: `{{ name }}` prints a value.

```php
<?phem

namespace App\Ui\Common;

use Phem\Attribute\Component;
use Phem\Node;

#[Component]
function Greeting(string $name): Node
{
    return <p>Hello, {{ name }}!</p>;
}
```

The `phemc` compiler reads the `.phem` files at build time. It writes plain PHP and Twig
templates, and it shows the errors with the line of the `.phem` file. At runtime, nothing parses
`.phem` files. See [Compiling](compiling.md).

## 2. A page is a component with a URL

`#[Page]` makes a component a Symfony route. You do not write a controller or a routes file.

```php
#[Page('/hello')]
function HelloPage(): Node
{
    return (
        <Document>
            <Document:title>Hello</Document:title>
            <Greeting name="Ada" />
        </Document>
    );
}
```

`Document` is a component of your app: the HTML document (`<html>`, `<head>`, `<body>`) of all
the pages. The name of a page ends with `Page`, and the `.phem` files are in `src/Ui/` (see
[Organize the UI](compiling.md#organize-the-ui)).

## 3. A component is a function or a class

Use the smallest form that does the job:

| You need | Use |
|---|---|
| markup and props | a `#[Component]` function |
| state, event handlers or services | a `#[Component]` class |
| a URL | `#[Page]` on a function or a class |

A class keeps its state in `#[State]` properties. Its `render()` method returns the markup:

```php
#[Component]
final class Counter
{
    #[State]
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return <button onClick={{ increment }}>Clicked {{ count }} times</button>;
    }
}
```

## 4. The handler runs on the server

A click on the button above does these steps:

1. The browser sends the event, the state and its signature to the server.
2. The server makes the component again from the state, and runs `increment()`.
3. The server renders the components that can change, and sends back only the HTML that changed.
4. The browser morphs the page. The focus and the scroll position stay.

The browser keeps the state. The server signs it, so the browser cannot change it. The server
keeps no session between two events.

> [!NOTE]
> The state is UI state. Keep limits and security decisions in the database or the session, not
> in `#[State]`. See [Philosophy](philosophy.md#4-state-is-ui-state-not-trusted-data).

## 5. Symfony does the rest

A component is a Symfony service. Get your repositories and services in the constructor. Use
Symfony security (`#[IsGranted]`), forms, validation and Doctrine as you do in a controller.
Read the data in `mount()`, not in `render()` (see
[Keep `render()` pure](components.md#keep-render-pure)).

```php
#[IsGranted('ROLE_ADMIN')]
#[Page('/admin/users')]
final class UsersPage
{
    /** @var list<User> */
    private array $users = [];

    public function __construct(private readonly UserRepository $repository)
    {
    }

    public function mount(): void
    {
        $this->users = $this->repository->findAll();
    }

    public function render(): Node
    {
        return (
            <Document>
                <ul>{% for user in users %}<li key={{ user.id }}>{{ user.email }}</li>{% endfor %}</ul>
            </Document>
        );
    }
}
```

## From React to Phem

When you know React, this table helps.

| React (react.dev/learn) | Phem |
|---|---|
| `function MyButton() { return <button/> }` | `#[Component] function MyButton()`: the same, in PHP (a function without `#[Component]` is a plain PHP function) |
| `export default function MyApp()` | `#[Page('/')] function HomePage()`: a Symfony route (a class page for its own state or route values) |
| the root layout (`<html>`, metadata) | a `Document` component of the app, with a `<Root>`: each page renders in it, and gives the title with `<Document:title>` |
| `{user.name}`, `src={user.imageUrl}` | `{{ user.name }}`, `src={{ user.imageUrl }}` |
| `className`, `style={{ width: 90 }}` | `className`, `style={{ {width: 90} }}` |
| `cond ? <A/> : <B/>`, `cond && <A/>` | `{{ cond ? <A /> : <B /> }}`, `{{ cond and <A /> }}`, `{% if %}` |
| `items.map(i => <li key={i.id}>…)` | `{% for i in items %}<li key={{ i.id }}>…{% endfor %}` or `array_map(fn($i) => <li …>, $items)` |
| `onClick={handleClick}` | `onClick={{ handleClick }}` (the handler runs on the server) |
| `useState` | a `#[State]` property of a `#[Component]` class; a method changes it |
| initial state from props or data | `mount()`, called when the component has no state yet |
| a form `onSubmit` handler | a Symfony form: `$form->handleRequest($request)` in the handler (`Request $request`), also in `onChange` for live validation |
| `createContext` + `useContext` | `#[Provide]` on a class component (or a property), and `#[Context]` on a property or a parameter |
| a WebSocket or a subscription | `#[Broadcast]` on a page, and `Phem\Stream\Streams` in a handler: Turbo Streams through the Mercure hub |
| imported modules | services, injected in the constructor |
| props, `children`, lifting state up | parameters or `#[Prop]` properties, `?Html $children`, the same pattern |

## What Phem is not

- Each event is a round trip to the server. Code that must run only in the browser (an
  animation, a drag) goes in a Stimulus controller.
- State values are JSON values: scalars, arrays and backed enums. A service or a closure is not
  state.
- Phem is for server-rendered Symfony apps. It is not a single-page app framework.

## Next steps

- [Getting started](getting-started.md): install Phem in a new Symfony app
- [Tutorial: a task list](tutorial.md): build a small app, one idea at a time
