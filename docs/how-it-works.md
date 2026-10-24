# How it works

This document shows the path of a `.phpm` file from the compiler to the browser, and how the
ideas of React map to Phem.

## The pipeline

```
 .phpm ──► phemc (Rust) ──► php/*.php        markup replaced by new \Phem\Element('x.0.twig', [...])
                     ├──► templates/*.twig one template for each markup literal (@phpm/x.0.twig)
                     └──► manifest.php, autoload.php, preload.php
                              │
     bin/console phem:warmup ──► the Twig cache (compiled at build time, not at runtime)
                              │
 PhemBundle ── class components → services (autowired, not shared)
             ── #[Page] classes and functions → routes ── PageController / EventController
                              │
 Browser ◄── HTML ─────── HttpKernel (FrankenPHP in worker mode, or PHP-FPM)
   phem Stimulus controller ── POST <page URL> {signature, handler, event}
   ◄── Turbo Streams (morph of the changed components) + the changed state entries
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
   `#[Page]` attributes (a Symfony `#[Route]`) of the pages, classes and functions, as the
   attribute loader does for controllers. A page renders its document: a component of the app with a `<Root>`
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
   [State cache](phpm-syntax.md#state-cache)).
   The state has the shape of the last render too: the parent and a hash of the HTML of each
   component. The server renders only the components on the path to the handler, restores
   their state, and runs the PHP handler (in a Doctrine transaction, when the handler or its component
   has `#[Transactional]`). Then, as React does, it renders only the components that can change: the component
   of the handler, the components whose state changed, and their children with new props. It
   compares their HTML with the hashes, and answers with a Turbo Stream `update`
   (`method="morph"`) for each component that changed, plus the entries of the state that
   changed and the new signature: the browser sets them in its copy of the state, so a big page
   does not get all its state again with each event. A component that did not render again is
   a `<phem-c data-phem-keep>` placeholder: the browser keeps it. This needs a pure `render()`
   (see [Keep `render()` pure](phpm-syntax.md#keep-render-pure)). Only the content of the
   `<Root>` changes: the document around it (for example the title) stays the same.
   Events wait in a queue, so each event uses the newest state.
   While an event runs, the element that sent it and its component get `aria-busy="true"` and
   the `phem-loading` class, after a delay of 200 ms (`data-phem-loading-delay-value` on the
   `<Root>` element changes it). Use CSS to style them, for example
   `.phem-loading { opacity: .5 }`. `phem:loading={{ save }}` on an element shows it only
   while a `save` event runs (see [Loading states](phpm-syntax.md#loading-states)).
   While a submit event runs, the form is locked: its buttons and controls are disabled, its
   text fields are read-only, and a second submit is ignored. Add `data-phem-disable={{ false }}`
   to the form to turn this off.
   While the browser is offline, the `<Root>` element has the `phem-offline` class.
   When an event fails (for example a 500), the browser shows a default error message. A
   `phem:error` listener can show its own message (see [Failed events](phpm-syntax.md#failed-events)).
   After a network error, a 502 or a 503, the browser sends the event again with a longer wait
   each time (see [Retries](phpm-syntax.md#retries)).
   Add `data-phem-transition` to an element to run the morph of its components in a view
   transition (see [Transitions](phpm-syntax.md#transitions)).
5. **Real-time updates.** A page with `#[Subscribe]` opens one connection to the Mercure hub of
   FrankenPHP. A handler pushes Turbo Streams to the open pages with `Phem\Stream\Streams`:
   plain markup (`prepend`, `update`, `remove`, ...), or a `refresh()` that renders a component
   again in each browser with a normal event. The next event of a page renders again the
   components that a push changed (see [Real-time updates](phpm-syntax.md#real-time-updates)).
   An `#[Async]` handler returns its slow work in a `Promise`: the event answers at once, and a
   Messenger worker runs the work and pushes its result (see
   [Slow work after the response](phpm-syntax.md#slow-work-after-the-response)).
6. **Server.** [FrankenPHP](https://frankenphp.dev) runs `public/index.php` in worker mode
   (see the Caddyfile in [Getting started](getting-started.md#step-3-add-the-docker-files)). Each worker boots the Symfony kernel once and keeps
   it in memory for many requests. Symfony Runtime gives each request to the kernel, which
   resets the services (`kernel.reset`) between requests. Caddy serves the static files of
   `public/`. The same `public/index.php` also runs on PHP-FPM.

## From React to Phem

| React (react.dev/learn) | Phem |
|---|---|
| `function MyButton() { return <button/> }` | `#[Component] function MyButton()`: the same, in PHP (a function without `#[Component]` is a plain PHP function) |
| `export default function MyApp()` | `#[Page('/')] function MyApp()`: a Symfony route (a class page for its own state or route values) |
| the root layout (`<html>`, metadata) | a `Document` component of the app, with a `<Root>`: each page renders in it, and gives the title with `<Document:title>` |
| `{user.name}`, `src={user.imageUrl}` | `{{ user.name }}`, `src={{ user.imageUrl }}` |
| `className`, `style={{ width: 90 }}` | `className`, `style={{ {width: 90} }}` |
| `cond ? <A/> : <B/>`, `cond && <A/>` | `{{ cond ? <A /> : <B /> }}`, `{{ cond and <A /> }}`, `{% if %}` |
| `items.map(i => <li key={i.id}>…)` | `{% for i in items %}<li key={{ i.id }}>…{% endfor %}` or `array_map(fn($i) => <li …>, $items)` |
| `onClick={handleClick}` | `onClick={{ handleClick }}` (the handler runs on the server) |
| `useState` | a `#[State]` property of a `#[Component]` class; a method changes it |
| initial state from props or data | `mount()`, called when the component has no state yet |
| a form `onSubmit` handler | a Symfony form: `$form->handleRequest($request)` in the handler (`Request $request`) |
| `createContext` + `useContext` | `#[Provide]` on a class component (or a property), and `#[Context]` on a property or a parameter |
| a WebSocket or a subscription | `#[Subscribe]` on a page, and `Phem\Stream\Streams` in a handler: Turbo Streams through the Mercure hub of FrankenPHP |
| imported modules | services, injected in the constructor |
| props, `children`, lifting state up | parameters or `#[Prop]` properties, `?Html $children`, the same pattern |

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
