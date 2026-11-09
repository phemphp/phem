# Understand Phem in five ideas

Phem is a component layer for Symfony web apps. You write a component’s logic and markup together in one `.phem` file, and the server renders the HTML, runs the event handlers, and updates only the parts of the page that change. You don’t write JavaScript, an API, or a client store. This page explains the five ideas behind Phem with one small example each, and shows where Phem stops. When you’re ready to build, follow [Getting started](getting-started.md) and then the [tutorial](tutorial.md).

> [!NOTE]
> Phem takes inspiration from [React Server Components](https://react.dev/reference/rsc/server-components).

## A `.phem` file is PHP with markup in it

A `.phem` file is ordinary PHP code that can also contain markup. The markup is Twig, so `{{ name }}` prints a value.

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

This file defines a `Greeting` component that takes a `name` prop and renders a paragraph. The `phemc` compiler reads your `.phem` files at build time and writes plain PHP and Twig templates. When it finds an error, it reports the line in your `.phem` file. Nothing parses `.phem` files at runtime. To learn more, read [Compiling](compiling.md).

## A page is a component with a URL

Add `#[Page]` to a component, and it becomes a Symfony route. You don’t write a controller or a routes file.

```php
#[Page('/hello')]
function HelloPage(): Node
{
    return (
        <Document title="Hello">
            <Greeting name="Ada" />
        </Document>
    );
}
```

Visiting `/hello` renders the `Greeting` component inside `Document`. `Document` is a component that your app defines once: it holds the HTML document (`<html>`, `<head>`, `<body>`) that wraps every page, and its `title` prop (`<Document title="Hello">`) sets the title of the page. By convention, a page’s name ends with `Page`, and your `.phem` files live in `src/Ui/` (see [Organize the UI](project-structure.md#organize-the-ui)).

## A component is a function or a class

Phem gives you two forms of component, and you pick the smallest one that does the job:

| You need | Use |
|---|---|
| markup and props | a `#[Component]` function |
| state, event handlers, or services | a `#[Component]` class |
| a URL | `#[Page]` on a function or a class |

A class component keeps its state in `#[State]` properties, and its `render()` method returns the markup. This counter stores a number and increments it on each click:

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
        return (
            <button onClick={{ increment }}>
                Clicked {{ count }} times
            </button>
        );
    }
}
```

### Methods that Phem calls for you

A class component can have three methods that Phem calls itself, in this order:

- **`__construct()`**: gets the services. A component is a Symfony service, so Phem autowires the constructor.
- **`mount()`** (optional): sets the first values from the props and the services. With `#[State]` properties, it runs when there’s no state yet: before the first render, and again when a parent changes a readonly prop. Without them, it runs before each render.
- **`render()`** (required): returns the markup. It only reads the properties, so keep services and the database out of it.

The names `mount` and `render` are reserved: `onClick={{ mount }}` is the compiler error E046. Give a handler any other name, such as `increment()` above.

## Event handlers run on the server

The `increment()` method above is PHP, so it can’t run in the browser. Instead, each click is a round trip to the server:

1. The browser sends the event, the component state, and the state’s signature to the server.
2. The server rebuilds the component from that state and runs `increment()`.
3. The server renders the components that can change and sends back only the HTML that changed.
4. The browser morphs the page in place, so focus and scroll position stay where they were.

Think of the state as a sealed envelope that the browser carries between requests. The server seals it with a signature, so the browser can’t change what’s inside, and the server doesn’t need a session to remember anything between two events.

> [!NOTE]
> State is for the UI. Keep limits and security decisions in the database or the session, not in `#[State]`. Read [Treat state as UI state, not trusted data](philosophy.md#treat-state-as-ui-state-not-trusted-data) to see why.

## Symfony does everything else

Phem adds components to Symfony and leaves the rest of the framework as it is. A component is a Symfony service, so you inject repositories and services through its constructor. You use Symfony security (`#[IsGranted]`), forms, validation, and Doctrine the same way you would in a controller.

```php
#[IsGranted('ROLE_ADMIN')]
#[Page('/admin/users')]
final class UsersPage
{
    /** @var list<User> */
    private array $users = [];

    public function __construct(
        private readonly UserRepository $repository,
    ) {
    }

    public function mount(): void
    {
        $this->users = $this->repository->findAll();
    }

    public function render(): Node
    {
        return (
            <Document>
                <ul>
                    {% for user in users %}
                        <li key={{ user.id }}>{{ user.email }}</li>
                    {% endfor %}
                </ul>
            </Document>
        );
    }
}
```

This page lists every user to administrators only. The constructor gets the repository, `mount()` loads the users, and `render()` only reads them. Keeping data access out of `render()` matters because Phem relies on it to skip work (see [Keep `render()` pure](components.md#keep-render-pure)).

## What Phem doesn’t do

Phem is built for server-rendered Symfony apps, and that choice sets its boundaries:

- **Browser-only behavior**: each event is a round trip to the server, so code that must run in the browser without waiting (an animation, a drag) belongs in a Stimulus controller
- **Rich objects in state**: state values are JSON values, backed enums and plain objects that the Serializer encodes, such as a draft of a form or a date, so a service, an entity or a closure can’t be state
- **Single-page apps**: Phem renders pages on the server and isn’t a single-page app framework

## Install Phem and build your first app

Two pages take you from here to a working app:

- [Create your first Phem app](getting-started.md): install Phem in a new Symfony app
- [Build a task list](tutorial.md): build a small app, one idea at a time
