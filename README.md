# rephp

rephp brings the React model to PHP 8.6 + Twig + Stimulus. A `.rhp` file is a PHP file that
contains markup literals, the same way a `.jsx` file contains JSX:

```php
<?php
namespace App\Pages\Examples;

use Closure;
use Rephp\Attribute\Page;
use function Rephp\useState;

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

- The full syntax is in [docs/rhp-syntax.md](docs/rhp-syntax.md).
- How to compile, check, watch, and run `.rhp` files: [docs/compiling.md](docs/compiling.md).

## Quick start

You need only Docker.

```sh
make up      # http://localhost:8080/learn — rhpc watch + the Io\Poll server
make test    # cargo test + PHPUnit
make check   # rhpc diagnostics for the quickstart sources
make prod    # the production image on http://localhost:8081
make vscode  # the VS Code extension for .rhp syntax highlighting (editors/vscode/rhp-0.1.0.vsix)
```

To install the VS Code extension, run `code --install-extension editors/vscode/rhp-0.1.0.vsix`.
See [editors/vscode/README.md](editors/vscode/README.md).

- `/learn` is the Quick Start page (static HTML + CSS, like react.dev/learn).
- Each sandbox in it runs a real `.rhp` file from `examples/quickstart/src/pages/examples/`,
  served at `/examples/<Name>`.
- `/learn/describing-the-ui.html` has a multi-file example: the page `Gallery.rhp` uses
  components from `examples/quickstart/src/components/`.

## How it works

```
 .rhp ──► rhpc (Rust) ──► php/*.php        markup replaced by \Rephp\Rt::render('x.0.twig', [...])
                     ├──► templates/*.twig one template for each markup literal
                     └──► manifest.php, autoload.php, preload.php
                              │
             rephp warmup ──► var/cache/twig  (Twig compiles at build time, not at runtime)
                              │
 Browser ◄── HTML ─────── rephp serve (PHP 8.6, Io\Poll event loop)
   rhp Stimulus controller ── POST /_rhp/event {route, state, signature, handler, event}
   ◄── Turbo Streams (morph of the changed components) + the new signed state
```

1. **Compile time.** `rhpc` parses each `.rhp` file. It finds the markup literals in the PHP
   code, checks them (closed tags, one root, known components and props, Rules of Hooks), and
   writes PHP + Twig. Then `rephp warmup` compiles all templates into the Twig cache. At
   runtime, nothing parses `.rhp` or `.twig` sources. The production image also uses OPcache
   preload.
2. **Components.** A component tag becomes the `{% component %}` Twig tag. It has two forms:
   - The include form is for a self-closing tag.
   - The embed form is for a tag with children. The children and the named slots
     (`<Card:footer>`) are Twig blocks.

   A component whose body is only `return <markup>;` is a *template component*: it renders
   as plain Twig, with no PHP call. Other function components and `#[Component]` classes go
   through the renderer.
3. **State in the browser.** The server keeps no session. The `rhp` Stimulus controller on the
   page root keeps the state of all components in a signed JSON value. The signature is an
   HMAC (`RHP_SECRET`). An event sends the state back. The server renders with it, runs the
   PHP handler, renders again, and compares the two renders. It answers with a Turbo Stream
   `update` (`method="morph"`) for each component that changed, plus the new state. Events
   wait in a queue, so each event uses the newest state.
4. **Server.** `rephp serve` is an HTTP/1.1 server on the native PHP 8.6 `Io\Poll` API (epoll
   on Linux, kqueue on macOS). It supports keep-alive, pipelining, static files with ETags,
   and timers.

| React (react.dev/learn) | rephp |
|---|---|
| `function MyButton() { return <button/> }` | the same, in PHP |
| `export default function MyApp()` | `#[Page] function MyApp()`; files in `src/pages/` are routes |
| `{user.name}`, `src={user.imageUrl}` | `{{ user.name }}`, `src={{ user.imageUrl }}` |
| `className`, `style={{ width: 90 }}` | `className`, `style={{ {width: 90} }}` |
| `cond ? <A/> : <B/>`, `cond && <A/>` | `{{ cond ? <A /> : <B /> }}`, `{{ cond and <A /> }}`, `{% if %}` |
| `items.map(i => <li key={i.id}>…)` | `{% for i in items %}<li key={{ i.id }}>…{% endfor %}` or `array_map(fn($i) => <li …>, $items)` |
| `onClick={handleClick}` | `onClick={{ handleClick }}` (the handler runs on the server) |
| `useState`, `useReducer`, `useRef`, `useMemo`, `useCallback`, `useId`, `useContext` | the same names, in `Rephp\` |
| props, `children`, lifting state up | named parameters, `?Html $children`, the same pattern |
| class components | `#[Component]` classes with `#[State]` properties |

## Repository layout

```
compiler/            Rust workspace: rhpc-syntax, rhpc-twig-scan, rhpc-analyze, rhpc-codegen, rhpc-cli
runtime/             rephp/runtime (Composer): Twig extension, renderer + hooks, App, Io\Poll server, rhp.js
examples/quickstart/ the react.dev/learn-style site (public/) and its runnable .rhp examples (src/)
docker/              Dockerfile (dev, prod), dev entrypoint
editors/vscode/      VS Code extension: .rhp syntax highlighting (TextMate grammar)
docs/rhp-syntax.md   the .rhp syntax
```

## Diagnostics

`rhpc` reports errors with the `.rhp` line and column, and it does not write output when it
finds an error:

| Code | Meaning |
|---|---|
| E001–E005 | Syntax: unclosed or mismatched tags, a void element that is not self-closed, more than one root |
| E010–E014 | Unknown component, unknown or missing prop, children or slot not accepted, duplicate component |
| E020–E023 | Rules of Hooks: a hook in a condition or loop, in a closure, after an early return, or outside a component |
| E030–E032 | Undefined variable in markup, markup in an unsupported expression, invalid attribute |
| E040–E041 | Class component without `render()`, readonly `#[State]` property |
| E050–E052 | `export default` (not PHP; use `#[Page]`), more than one `#[Page]` in a file, `#[Page]` on a function that is not a component |
| W001, W002 | A handler is called instead of passed; a list item without `key` |
| W003, W004 | `#[Page]` outside `src/pages/` (no route); `#[Page]`, `#[Component]` or `#[State]` without its `use Rephp\Attribute\...` import |

## Limits

- Each event is a round trip to the server (about 1 ms on the server, plus network time).
  Logic that must run only in the browser goes into a normal Stimulus controller.
- State values must be JSON values. Closures and objects are not state.
- `useEffect` is not available, because the server keeps no session between events.
- A component that is not a template component is wrapped in an `<rh-c>` element (CSS `display: contents`).
  This wrapper is not valid inside `<table>`, `<tr>`, or `<select>`.
