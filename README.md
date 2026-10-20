# Phem

Phem brings the React component model to PHP 8.5, Symfony, Twig and Stimulus. A `.phpm` file
is a PHP file that contains markup literals, the same way a `.jsx` file contains JSX:

```php
<?phpm
namespace App\Pages\Examples;

use Closure;
use Phem\Attribute\{Component, Page, State};
use Phem\Node;

use function App\Components\Document;

#[Component]
function MyButton(int $count, Closure $onClick): Node {
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

    public function render(): Node
    {
        return (
            <Document>
                <Document:title>Counters</Document:title>
                <h1>Counters that update together</h1>
                <MyButton count={{ count }} onClick={{ handleClick }} />
                <MyButton count={{ count }} onClick={{ handleClick }} />
            </Document>
        );
    }
}
```

A page is a class with `#[Page]` (a Symfony route). It extends the document of the application:
a class that renders the `<html>` element and the title of the page. A component with state is
an object: a `#[Component]` (or `#[Page]`) class is a Symfony service, so its
constructor gets the services that it needs (autowiring). With Doctrine ORM, each event
handler runs in a transaction: Phem flushes and commits when the handler returns.

```php
#[Component]
final class AddScientist
{
    public function __construct(private readonly ScientistRepository $scientists)
    {
    }

    public function add(Event $e): void   // an event handler
    {
        $this->scientists->add(new Scientist($e->form['name'], $e->form['profession']));
    }

    public function render(): Node
    {
        return (
            <form onSubmit={{ add }}>
                <input name="name" />
                <input name="profession" />
                <button type="submit">Add</button>
            </form>
        );
    }
}
```

- The full syntax is in [docs/phpm-syntax.md](docs/phpm-syntax.md).
- How to compile, check, watch, and run `.phpm` files: [docs/compiling.md](docs/compiling.md).
- How to test components and pages with PHPUnit: [docs/testing.md](docs/testing.md).

## Quick start

You need only Docker.

```sh
make up      # http://localhost:8080/learn — phemc watch + FrankenPHP (worker mode)
make test    # cargo test + PHPUnit
make check   # phemc diagnostics for the quickstart sources
make prod    # the production image on http://localhost:8081
make vscode    # the VS Code extension for .phpm files (editors/vscode/phem-<version>.vsix)
make phpstorm  # the PhpStorm plugin for .phpm files (needs Java 21 and Gradle)
```

`make up` and `make prod` also make the SQLite database of the quickstart on the first start:
`docker/init-db.sh` makes the schema from the Doctrine mapping and loads the Doctrine fixtures.

To build and install the editor support, run `make vscode-install` or `make phpstorm-install`.
Both use the Phem language server (`phemc lsp`), compiled to WebAssembly: diagnostics as you
type, completion, go to definition, hover, and outline. See
[editors/vscode/README.md](editors/vscode/README.md) and
[editors/phpstorm/README.md](editors/phpstorm/README.md).

- `/learn` is the Quick Start page (static HTML + CSS, like react.dev/learn).
- Each sandbox in it runs a real `.phpm` file from `examples/quickstart/src/Pages/Examples/`.
  Its `#[Page]` attribute serves it at `/examples/<Name>`.
- `/learn/describing-the-ui.html` has a multi-file example: the page `Gallery.phpm` uses
  components from `examples/quickstart/src/Components/`. The page `DurableGallery.phpm` reads and
  writes its data in SQLite through a Doctrine ORM entity and its repository. Its handlers run in
  a Doctrine transaction, and a Symfony form adds a scientist. The data stays after a reload.

## How it works

```
 .phpm ──► phemc (Rust) ──► php/*.php        markup replaced by new \Phem\Element('x.0.twig', [...])
                     ├──► templates/*.twig one template for each markup literal (@phpm/x.0.twig)
                     └──► manifest.php, autoload.php, preload.php
                              │
     bin/console phem:warmup ──► the Twig cache (compiled at build time, not at runtime)
                              │
 PhemBundle ── class components → services (autowired, not shared)
             ── #[Page] classes → routes ── PageController / EventController
                              │
 Browser ◄── HTML ─────── HttpKernel (FrankenPHP in worker mode, or PHP-FPM)
   phem Stimulus controller ── POST <page URL> {signature, handler, event}
   ◄── Turbo Streams (morph of the changed components) + the new signed state
```

1. **Compile time.** `phemc` parses each `.phpm` file. It finds the markup literals in the PHP
   code, checks them (closed tags, one root, known components and props), and writes PHP +
   Twig. A markup literal becomes a value, `new \Phem\Element(template, vars)`, like a React
   element: the renderer renders it. Then `bin/console phem:warmup` compiles all templates
   into the Twig cache. At runtime, nothing parses `.phpm` or `.twig` sources. The production
   image also uses OPcache preload.
2. **Symfony.** `PhemBundle` reads the phemc manifest. Its compiler pass registers each
   `#[Component]` and `#[Page]` class as an autowired service that is not shared, so each
   component on a page is a new object with its dependencies. Its route loader reads the
   `#[Page]` attributes (a Symfony `#[Route]`) of the pages, as the attribute loader does for
   controllers. A page renders its document: a component of the app with a `<Root>`
   (`Phem\Root`), the root element of the page.
   The compiled templates use the application Twig environment, so Twig extensions such as
   `path()` work in markup. In debug mode, the container and the routes are built again after
   each `phemc build`.
3. **Components.** A component tag becomes the `{% component %}` Twig tag. It has two forms:
   - The include form is for a self-closing tag.
   - The embed form is for a tag with children. The children and the named slots
     (`<Card:footer>`) are Twig blocks.

   A component whose body is only `return <markup>;` is a *template component*: it renders
   as plain Twig, with no PHP call. Other function components and `#[Component]` classes go
   through the `Renderer` service.
4. **State in the browser.** The server keeps no session. The `phem` Stimulus controller on the
   `<Root>` element keeps the `#[State]` properties of all components in a signed JSON value. The
   signature is an HMAC (`kernel.secret`, from `APP_SECRET`) of the state and its page
   component. An event sends only the signature: the server keeps each state that it signs in a
   cache pool, by its signature. When the pool does not have it (an expired entry, another
   machine), the browser sends the event again with the full state, compressed. The cache is only
   an optimization: the browser keeps the state (see
   [State cache](docs/phpm-syntax.md#state-cache)).
   The state has the shape of the last render too: the parent and a hash of the HTML of each
   component. The server renders only the components on the path to the handler, restores
   their state, and runs the PHP handler (in a Doctrine transaction, when Doctrine ORM is
   enabled). Then, as React does, it renders only the components that can change: the component
   of the handler, the components whose state changed, and their children with new props. It
   compares their HTML with the hashes, and answers with a Turbo Stream `update`
   (`method="morph"`) for each component that changed, plus the new state. A component that did
   not render again is a `<phem-c data-phem-keep>` placeholder: the browser keeps it. This needs
   a pure `render()` (see [Keep `render()` pure](docs/phpm-syntax.md#keep-render-pure)). Only the content
   of the `<Root>` changes: the document around it (for example the title) stays the same.
   Events wait in a queue, so each event uses the newest state.
   While an event runs, the element that sent it and its component get `aria-busy="true"` and
   the `phem-loading` class, after a delay of 200 ms (`data-phem-loading-delay-value` on the
   `<Root>` element changes it). Use CSS to style them, for example
   `.phem-loading { opacity: .5 }`. `phem:loading={{ save }}` on an element shows it only
   while a `save` event runs (see [Loading states](docs/phpm-syntax.md#loading-states)).
   While a submit event runs, the form is locked: its buttons and controls are disabled, its
   text fields are read-only, and a second submit is ignored. Add `data-phem-disable={{ false }}`
   to the form to turn this off.
   While the browser is offline, the `<Root>` element has the `phem-offline` class.
   When an event fails (for example a 500), the browser shows a default error message. A
   `phem:error` listener can show its own message (see [Failed events](docs/phpm-syntax.md#failed-events)).
   After a network error, a 502 or a 503, the browser sends the event again with a longer wait
   each time (see [Retries](docs/phpm-syntax.md#retries)).
   Add `data-phem-transition` to an element to run the morph of its components in a view
   transition (see [Transitions](docs/phpm-syntax.md#transitions)).
5. **Server.** [FrankenPHP](https://frankenphp.dev) runs `public/index.php` in worker mode
   (see `examples/quickstart/Caddyfile`). Each worker boots the Symfony kernel once and keeps
   it in memory for many requests. Symfony Runtime gives each request to the kernel, which
   resets the services (`kernel.reset`) between requests. Caddy serves the static files of
   `public/`. The same `public/index.php` also runs on PHP-FPM.

| React (react.dev/learn) | Phem |
|---|---|
| `function MyButton() { return <button/> }` | `#[Component] function MyButton()`: the same, in PHP (a function without `#[Component]` is a plain PHP function) |
| `export default function MyApp()` | `#[Page('/')] final class MyApp`: a Symfony route |
| the root layout (`<html>`, metadata) | a `Document` component of the app, with a `<Root>`: each page renders in it, and gives the title with `<Document:title>` |
| `{user.name}`, `src={user.imageUrl}` | `{{ user.name }}`, `src={{ user.imageUrl }}` |
| `className`, `style={{ width: 90 }}` | `className`, `style={{ {width: 90} }}` |
| `cond ? <A/> : <B/>`, `cond && <A/>` | `{{ cond ? <A /> : <B /> }}`, `{{ cond and <A /> }}`, `{% if %}` |
| `items.map(i => <li key={i.id}>…)` | `{% for i in items %}<li key={{ i.id }}>…{% endfor %}` or `array_map(fn($i) => <li …>, $items)` |
| `onClick={handleClick}` | `onClick={{ handleClick }}` (the handler runs on the server) |
| `useState` | a `#[State]` property of a `#[Component]` class; a method changes it |
| initial state from props or data | `mount()`, called when the component has no state yet |
| a form `onSubmit` handler | a Symfony form: `$form->handleRequest($e->request)` in the handler |
| `createContext` + `useContext` | `#[Provide]` on a class component (or a property), and `#[Context]` on a property or a parameter |
| imported modules | services, injected in the constructor |
| props, `children`, lifting state up | parameters or `#[Prop]` properties, `?Html $children`, the same pattern |

## Repository layout

```
compiler/            Rust workspace: phemc-syntax, phemc-twig-scan, phemc-analyze, phemc-codegen, phemc-lsp, phemc-cli
phem/                phem/phem (Composer): PhemBundle, Renderer, Twig extension, controllers,
                     route loader, Doctrine transaction runner, console commands, and the phem
                     Stimulus controller (assets/: a Symfony UX package for AssetMapper)
examples/quickstart/ a Symfony application: the react.dev/learn-style site (public/), its
                     runnable .phpm examples (src/), and its FrankenPHP Caddyfile
docker/              Dockerfile (FrankenPHP: dev, prod), dev and prod entrypoints, init-db.sh
editors/server/      the Node launcher of the language server (phem-lsp.wasm: `make lsp`)
editors/vscode/      VS Code extension: .phpm syntax highlighting (TextMate grammar) and the language server
editors/phpstorm/    PhpStorm plugin: the same grammar and language server
docs/phpm-syntax.md   the .phpm syntax
docs/compiling.md    how to build, check, watch, and run .phpm files
docs/testing.md      how to test components and pages (Phem\Test\InteractsWithPhem)
```

## Diagnostics

`phemc` reports errors with the `.phpm` line and column, and it does not write output when it
finds an error:

| Code | Meaning |
|---|---|
| E001–E006 | Syntax: unclosed or mismatched tags, a void element that is not self-closed, more than one root, a `<?php` open tag instead of `<?phpm` |
| E010–E015 | Unknown component (also a function without `#[Component]`), unknown or missing prop, children or slot not accepted, duplicate component (also a component with the name of a built-in component, `Phem\Root`), a required context with no provider above the component |
| E020 | A call to a React Hook (`Phem\useState`, ...): Phem keeps state in `#[Component]` classes |
| E030–E033 | Undefined variable in markup, markup in an unsupported expression, invalid attribute, invalid `phem:bind` (not a `#[State]` property, not a form field, or a type that does not take the value of the field) |
| E040–E044 | Class component without `render()`, readonly `#[State]` property, `#[Prop]` property that is not public, static, or `#[State]`, a second `#[Prop(rest: true)]` property or one whose type does not accept an array, a `#[Context]` or `#[Provide]` type that is not one class, interface or enum (or a `#[Context]` property that is static, readonly, a prop or state) |
| E050, E052 | `export default` (not PHP; use `#[Page]`), `#[Page]` or `#[Route]` on a function, or on a class that is not a component |
| W001–W003 | A handler is called instead of passed; a list item without `key`; an event that the browser does not send (`onMouseEnter`) |
| W004 | `#[Page]`, `#[Route]`, `#[Component]`, `#[State]`, `#[Prop]`, `#[Path]`, `#[Context]` or `#[Provide]` without its `use` import (`Phem\Attribute\...`, `Symfony\Component\Routing\Attribute\Route`) |
| W005 | a `<title>` outside `<head>` and `<svg>`: the document component prints the title, and a page gives it with `<Document:title>` |
| W006 | `phem:debounce` or `phem:throttle` on an element with no handler |
| W007 | `phem:live` on an element with no `phem:bind` |

## Limits

- Each event is a round trip to the server (about 1 ms on the server, plus network time).
  Logic that must run only in the browser goes into a normal Stimulus controller.
- State values must be JSON values. Closures, objects and services are not state.
- There is no `useEffect`, because the server keeps no session between events.
- A page gets no props. A route placeholder such as `{id}` is not a prop of the page.
- A markup literal is rendered when a component returns it or when markup prints it. Do not
  cast it to a string in PHP.
- A component that is not a template component is wrapped in an `<phem-c>` element (CSS `display: contents`).
  This wrapper is not valid inside `<table>`, `<tr>`, or `<select>`.
