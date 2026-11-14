# Understand how Phem runs your components

A `.phem` file goes through four stages before a user can click on it: the `phemc` compiler turns it into PHP and Twig, Symfony registers its components and routes, the server renders it to HTML, and the browser sends events back and morphs in the HTML that changed. This page follows that path from start to finish, so you can explain why Phem needs no session, why `render()` must stay pure, and what the browser does while it waits.

## The path from a `.phem` file to the browser

The whole pipeline fits in one diagram. The top half runs once, at build time and at boot; the bottom half runs on every request and every event:

![The Phem pipeline: phemc compiles a .phem file to PHP, Twig templates and a manifest. phem:warmup fills the Twig cache. PhemBundle registers the components as services and the #[Page] classes as routes. HttpKernel renders the HTML for the browser, and the browser sends events back as a POST and gets Turbo Streams with the morph and the state.](images/architecture.webp)

Each section below explains one part of the diagram, in the same order.

## The compiler turns `.phem` files into PHP and Twig

All the parsing happens at build time, so production never reads a `.phem` file. `phemc`, a compiler written in Rust, parses each `.phem` file and finds the markup literals inside the PHP code. It checks them for closed tags, a single root, and known components and props, and then writes two outputs: PHP files and Twig templates.

Each markup literal becomes a value in the PHP output, `new \Phem\Element(template, vars)`, which points to its own compiled template (for example `@phemc/x.0.twig`). The literal describes what to render, and the renderer renders it later.

After the build, `bin/console phem:warmup` compiles every template into the Twig cache. At runtime, nothing parses `.phem` or `.twig` sources, and the production image also preloads the PHP code with OPcache.

## Symfony registers your components and routes

`PhemBundle` connects the compiled code to Symfony by reading the manifest that `phemc` writes. Its compiler pass registers each `#[Component]` and `#[Page]` class as an autowired service that isn’t shared, so every component on a page is a new object with its own dependencies.

The bundle’s route loader reads the `#[Page]` attribute of each page class and function. `#[Page]` is a Symfony `#[Route]`, so the loader works the same way the attribute loader works for controllers. A page renders inside its document: a component of your app that contains a `<Root>` (`Phem\Root`), the element around the part of the page that events update.

The compiled templates use your app’s Twig environment, so Twig extensions such as `path()` work in markup. A class name in markup (`OrderStatus.Cancelled`, `path(ProductPage)`) compiles to the class name as a string, in a call to `phem_constant()`, `enum_cases()`, `phem_path()` or `phem_url()`. In debug mode, Symfony rebuilds the container and the routes after each `phemc build`.

## Component tags become Twig tags

When you write a component tag in markup, the compiler turns it into a `{% component %}` Twig tag. The tag has two forms:

- **Include form**: for a self-closing tag such as `<Badge label="New" />`
- **Embed form**: for a tag with children, where the children and the named slots (`<Card.footer>`) become Twig blocks

How Phem renders the component depends on its form. A component whose body is only `return <markup>;` is a template component: it renders as plain Twig, with no PHP call. Every other function component and every `#[Component]` class goes through the `Renderer` service.

## The browser keeps the state

Phem keeps no session on the server, so the browser has to remember each component’s state between events. The `phem` Stimulus controller on the `<Root>` element stores the `#[State]` properties of all components on the page in one signed JSON value, and sends it back with each event.

### The server signs the state

The signature works like a wax seal: anyone can read the state, but nobody can change it without breaking the seal. Phem computes a hash-based message authentication code (HMAC) of the state, its page component, and the time it signed them. The key is the `phem.secret` option, which defaults to `kernel.secret` (from `APP_SECRET`). When an event arrives, the server rejects a state whose signature doesn’t match, or whose signature is older than the `phem.state_max_age` option (one day by default).

### Large state goes through a cache

Sending a big state with every event wastes bandwidth, so Phem switches strategy above a size threshold. A state under 4 KB (4,096 bytes, by default) goes with each event, together with its signature. For a larger state, the server stores the state it signs in a cache pool, keyed by its signature, and the browser sends only the signature.

When the pool doesn’t have the entry, for example because it expired or the request reached another machine, the browser sends the event again with the full state, compressed. The cache is only an optimization: the browser always keeps the state (see [State cache](failed-events.md#an-event-makes-two-requests)).

### The server renders only what changed

The state also records the shape of the last render: each component’s parent and a hash of its HTML. When an event arrives, the server renders the components on the path from the page to the handler, restores their state, and runs the PHP handler. If the handler or its component has `#[Transactional]`, the handler runs in a Doctrine transaction.

Next, the server renders again only the components that can change:

- The component that owns the handler
- The components whose state changed
- Their children that get new props or slots

The server compares the new HTML of each of these components with the hash it had before. For each component that changed, it answers with a Turbo Stream `replace` (`method="morph"`). The response also contains the state entries that changed and the new signature, and the browser writes them into its copy of the state, so a big page doesn’t download all its state again on every event.

The response stays small in four more ways:

- **JSON patches**: an entry that changed only a bit gets a patch of its JSON text, the shape gets only the hashes that changed, and a large `#[State]` list where one item changed gets only that item
- **Kept components**: a component that didn’t render again comes back as a `<template data-phem-keep>` placeholder, and the browser keeps its current HTML
- **Kept runs**: adjacent kept components share one placeholder (`first..last`), and the browser finds the components of the run in the shape it already has
- **Feeds**: the items of a `Phem\Feed` aren’t in the state or in the hash of their component. An event renders only the items that it changes, in their own streams after the morph, and the browser keeps the other items (see [Feeds](feeds.md))

All of this depends on a pure `render()`, because Phem assumes that a component with the same inputs gives the same HTML (see [Keep `render()` pure](components.md#keep-render-pure)). Only the content of the `<Root>` changes; the document around it, for example the title, stays the same. Events wait in a queue, so each event starts from the newest state.

### The browser shows feedback while an event runs

The round trip takes time, so the `phem` controller tells the user what’s going on. While an event runs, it does the following:

- **Loading state**: after a delay of 200 ms, the element that sent the event and its component get `aria-busy="true"` and the `phem-loading` class, which you can style with CSS such as `.phem-loading { opacity: .5 }`; set `data-phem-loading-delay-value` on the `<Root>` element to change the delay
- **Targeted indicators**: an element with `phem:loading={{ save }}` shows only while a `save` event runs (see [Loading states](events.md#show-a-loading-state))
- **Form lock**: during a submit event, the form’s buttons and controls are disabled, its text fields are read-only, and a second submit is ignored; add `data-phem-disable={{ false }}` to the form to turn this off
- **Offline state**: while the browser is offline, the `<Root>` element has the `phem-offline` class, and the events wait for the connection; with `data-phem-offline-queue-value`, they stay after a reload (see [Offline queue](failed-events.md#keep-the-events-of-an-offline-page-after-a-reload))

When something goes wrong, the controller handles it too. If an event fails, for example with a 500 error, the browser shows a default error message, and a `phem:error` listener can replace it with your own (see [Failed events](failed-events.md#an-event-fails-and-the-page-doesnt-change)). After a network error, a 502, or a 503, the browser sends the event again and waits longer before each new try (see [Retries](failed-events.md#an-event-fails-on-an-unstable-network)).

To animate an update, add `data-phem-transition` to an element, and the morph of its components runs in a view transition (see [Transitions](client-side.md#animate-changes-with-view-transitions)).

## Real-time updates go through Mercure

Events update the browser that sent them; real-time updates reach every other open page. A page with `#[Broadcast]`, or with a component that has `#[Broadcast]`, opens one connection to the Mercure hub (the hub built into FrankenPHP, or a standalone hub).

A handler pushes Turbo Streams to the open pages with `Phem\Stream\Streams`. A push is either plain markup (`prepend`, `update`, `remove`, and so on) or a `refresh()`, which makes each browser render a component again through a normal event. A component with `#[Broadcast]` refreshes itself in the other browsers after its handlers run, unless you set `handlers: false`. The next event on a page renders again the components that a push changed (see [Real-time updates](real-time.md)). With no hub, `data-phem-sync-tabs-value` on the `<Root>` element refreshes the other tabs of the same page in one browser after an event (see [Sync the tabs of one browser](real-time.md#sync-the-tabs-of-one-browser-without-a-hub)).

The same channel delivers slow work. An `#[Async]` handler returns its slow work in a `Promise`, so the event answers at once, and a Messenger worker runs the work later and pushes the result (see [Slow work after the response](resilience.md#run-slow-work-after-the-response)).

## FrankenPHP keeps the kernel in memory

In production, [FrankenPHP](https://frankenphp.dev) runs `public/index.php` in worker mode (see the Caddyfile in [Deployment](deployment.md)). Each worker boots the Symfony kernel once and keeps it in memory across requests, which saves the boot cost on every event.

Symfony Runtime passes each request to the kernel, and the kernel resets its services (`kernel.reset`) between requests, so each request starts with clean services. Caddy serves the static files in `public/`. The same `public/index.php` also runs on PHP-FPM if you don’t use FrankenPHP.

## Where to find event size and render cost limits

This page explains the mechanism; the numbers live elsewhere. For the maximum size of an event, the state cache settings, and the cost of a render, read [Errors and limits](failed-events.md).
