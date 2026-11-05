# How it works

This document shows the path of a `.phem` file from the compiler to the browser. For a map of the
ideas of React to Phem, see [What is Phem](what-is-phem.md#from-react-to-phem).

## The pipeline

```
 .phem ──► phemc (Rust) ──► php/*.php        markup replaced by new \Phem\Element('x.0.twig', [...])
                     ├──► templates/*.twig one template for each markup literal (@phemc/x.0.twig)
                     └──► manifest.php, autoload.php
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

1. **Compile time.** `phemc` parses each `.phem` file. It finds the markup literals in the PHP
   code, checks them (closed tags, one root, known components and props), and writes PHP +
   Twig. A markup literal becomes a value, `new \Phem\Element(template, vars)`, like a React
   element: the renderer renders it. Then `bin/console phem:warmup` compiles all templates
   into the Twig cache. At runtime, nothing parses `.phem` or `.twig` sources. The production
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
   component. An event sends a small state with the signature. For a large state (4 KiB or more by
   default), it sends only the signature: the server keeps each large state that it signs in a
   cache pool, by its signature. When the pool does not have it (an expired entry, another
   machine), the browser sends the event again with the full state, compressed. The cache is only
   an optimization: the browser keeps the state (see
   [State cache](errors-and-limits.md#state-cache)).
   The state has the shape of the last render too: the parent and a hash of the HTML of each
   component. The server renders only the components on the path to the handler, restores
   their state, and runs the PHP handler (in a Doctrine transaction, when the handler or its component
   has `#[Transactional]`). Then, as React does, it renders only the components that can change: the component
   of the handler, the components whose state changed, and their children with new props. It
   compares their HTML with the hashes, and answers with a Turbo Stream `replace`
   (`method="morph"`) for each component that changed, plus the entries of the state that
   changed and the new signature: the browser sets them in its copy of the state, so a big page
   does not get all its state again with each event. An entry that changed only a bit gets a
   patch of its JSON text: the shape gets only the hashes that changed, and a large `#[State]`
   list that changed in one item gets only that item. A component that did not render again is a `<template data-phem-keep>`
   placeholder: the browser keeps it. A run of kept components that are next to each other has
   one placeholder (`first..last`), and the browser finds the components of the run in the shape
   that it has. This needs a pure `render()`
   (see [Keep `render()` pure](components.md#keep-render-pure)). Only the content of the
   `<Root>` changes: the document around it (for example the title) stays the same.
   Events wait in a queue, so each event uses the newest state.
   While an event runs, the element that sent it and its component get `aria-busy="true"` and
   the `phem-loading` class, after a delay of 200 ms (`data-phem-loading-delay-value` on the
   `<Root>` element changes it). Use CSS to style them, for example
   `.phem-loading { opacity: .5 }`. `phem:loading={{ save }}` on an element shows it only
   while a `save` event runs (see [Loading states](events-and-forms.md#loading-states)).
   While a submit event runs, the form is locked: its buttons and controls are disabled, its
   text fields are read-only, and a second submit is ignored. Add `data-phem-disable={{ false }}`
   to the form to turn this off.
   While the browser is offline, the `<Root>` element has the `phem-offline` class.
   When an event fails (for example a 500), the browser shows a default error message. A
   `phem:error` listener can show its own message (see [Failed events](errors-and-limits.md#failed-events)).
   After a network error, a 502 or a 503, the browser sends the event again with a longer wait
   each time (see [Retries](errors-and-limits.md#retries)).
   Add `data-phem-transition` to an element to run the morph of its components in a view
   transition (see [Transitions](client-side.md#transitions)).
5. **Real-time updates.** A page with `#[Broadcast]`, or with a component with `#[Broadcast]`,
   opens one connection to the Mercure hub (the hub of FrankenPHP, or a standalone hub). A
   handler pushes Turbo Streams to the open pages with `Phem\Stream\Streams`: plain markup
   (`prepend`, `update`, `remove`, ...), or a `refresh()` that renders a component again in each
   browser with a normal event. A component with `#[Broadcast]` (and no `handlers: false`)
   refreshes itself in the other browsers after its handlers. The next event of a page renders
   again the components that a push changed (see
   [Real-time updates](server-features.md#real-time-updates)).
   An `#[Async]` handler returns its slow work in a `Promise`: the event answers at once, and a
   Messenger worker runs the work and pushes its result (see
   [Slow work after the response](server-features.md#slow-work-after-the-response)).
6. **Server.** [FrankenPHP](https://frankenphp.dev) runs `public/index.php` in worker mode
   (see the Caddyfile in [Deployment](deployment.md)). Each worker boots the Symfony kernel once and keeps
   it in memory for many requests. Symfony Runtime gives each request to the kernel, which
   resets the services (`kernel.reset`) between requests. Caddy serves the static files of
   `public/`. The same `public/index.php` also runs on PHP-FPM.

## Limits

For the limits of an event and the cost of a render, see
[Errors and limits](errors-and-limits.md).
