# TODO

Features that Phem does not have yet. Each item takes the best design from the peers:
Symfony UX Live Components (LC), Livewire 4 (LW), Phoenix LiveView (LV), Blazor Server, and
React. The syntax in each item is a sketch, not a decision.

Priority: **P1** blocks real applications. **P2** is expected by users of the peers.
**P3** is a good addition.

## Design discussions

These items need a Phem-specific design. We cannot copy a peer as it is, because of one of
these properties of Phem:

- the state lives in the browser, signed, with one value for all the components of the page;
- the server keeps no session and renders the full tree two times for each event;
- the `.phpm` markup is compiled and checked by `phemc`, and it follows React, not Blade or Twig;
- a handler runs in a Doctrine transaction.

Talk about each item before its implementation. The open questions are a start, not a full list.

- [ ] **Streams** (section 1). The items of a stream are not in the state. How does a later
      event render the list, and how does the "before/after" comparison see it?
- [ ] **Live validation** (section 3). The touched fields go in the signed state. How does
      this work with a Symfony form, and with `#[Assert]` on `#[State]` properties? Does
      validation run in the Doctrine transaction?
- [ ] **Colocated client code** (section 4). Is the extracted script a Stimulus controller (as
      the `data-phem-client` controllers), or a smaller hook API? How does `phemc` give it to
      AssetMapper in development (`phemc watch`) and in production?
- [ ] **Async handlers** (section 2). A worker has no request, no browser state and no tree.
      What does the method get (its props, its context, the `Event`), and how does the result
      go back to the browser of the user who clicked?

## 1. Rendering and performance

### [x] Lazy and deferred components (Suspense) — P2

A slow component (a report, an API call) blocks the full page render.

Done: a built-in `<Suspense>` component, with the `fallback` and `error` slots. The page renders
the fallback, and the children do not render (so their `mount()` does not run). The browser loads
the children with a normal event to the built-in load handler of the boundary: when the page
starts, or with `lazy` when the fallback comes into view (IntersectionObserver). The state keeps
the status of the boundary (pending, loaded, failed). When the render of the children throws, the
boundary drops their fibers and renders the error slot (`Renderer::catching()`, the base of
`<ErrorBoundary>`). Without an error slot, the event fails and the fallback stays. Each boundary
loads with its own event. The test helper has `->load()`. The Dashboard example uses it. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#content-that-loads-after-the-page).

- **Best peer:** React `<Suspense fallback>`. LV `assign_async` + `<.async_result>` (loading,
  ok and failed states in one place). LC `loading="defer"` / `loading="lazy"` (lazy waits
  until the element is visible). LW `#[Lazy]` with a `placeholder()` method.
- **Later:** stream the deferred parts in the first response (HTTP streaming), with no second
  request. A retry of a failed boundary (a built-in handler for the error slot).

### [ ] Streams for long lists — P3

- **Best peer:** LV streams: insert, update and delete items in a list without the list in
  server memory or in the state.
- **Proposal:** a `Stream` value. A handler calls `$this->messages->insert($m)` or
  `->delete($m)`, and the response has a Turbo Stream `append` / `remove` for the item. The
  list is not part of `#[State]`. Good for chats, feeds and infinite scroll.

## 2. State and communication

### [x] Context (provide and read) — P2

Props pass data one level at a time. Deep trees need "prop drilling".

Done: a class component provides a context with `#[Provide]`: on a property (its value), or on
the class (the component itself, for compound components such as `<Tabs>` and `<Tab>`). A
component below reads it with `#[Context]`, on a property or on a parameter of a function
component. The declared type is the key (one class, interface or enum), and the lookup takes the
nearest provider above, through the fibers (`ContextResolver`). Context is for a value that
depends on the position in the tree; a value that is the same for the full request stays a
service. A required context with no provider throws, with the path of the components. `phemc`
reports E015 when a chain of placed uses from a page has no provider (it never reports a false
error: a tag in the slot of another component stops the check), and E044 for a type that is not
a key. The context is set after the props and before the state or `mount()`, it is not in the
state, and it counts as a prop for the purity rule. After an event, the consumers render when
their provider changes, also under a kept component: the tree shape marks the consumers, and
`UpdateScope` renders the paths to them, as React does. An enum or a readonly object changes only
when its value changes; the component itself or a mutable object changes each time that the
provider renders. A member path in a handler (`onClick={{ tabs.select }}`) is the method of the
object (`phem_method()`). The profiler shows the contexts of each component, and the components
that rendered because of a context. The Settings example uses it. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#context).

- **Best peer:** React `createContext` + `useContext`. LC 3.1 `provide()` / `inject()` for Twig
  components.
- **Later:** the compiler follows `{{ children }}` and the named slots into the component that
  prints them (`<Layout><Tab /></Layout>`, where `Layout` returns `<Tabs>{{ children }}</Tabs>`),
  so it finds more missing providers. The tree shape keeps the type of each consumer, so a change
  renders only the consumers of that type. The profiler dumps the context values (the same "safe
  dump" problem as the props).

### [x] Real-time push — P2

A change by one user does not show for another user until that user sends an event.

Done: a page with `#[Subscribe]` opens one EventSource to the Mercure hub of FrankenPHP, for its
topics: the page, the page with its `#[Path]` route values, and `phem:*` (`everywhere()`). A
page topic is an HMAC of its name, so the browser sees no class name and no route value. A
handler or a service pushes Turbo Streams with `Phem\Stream\Streams`:
`$streams->to(Page::class, $values)->prepend('entries', <Entry entry={{ entry }} />)->refresh('stats')`.

- The markup is plain markup: it renders at once outside the page (`Renderer::detached()`), with
  no `<phem-c>`. A handler, a `phem:bind`, a `phem:loading` or a `<Suspense>` in it throws.
- `refresh()` is the action of Phem (`phem-refresh`): each browser renders the component around
  the target again with a normal event, with its state and its access rules, as after
  `$e->refresh()`: a component with no `#[State]` reads its data again in `mount()`. The browser
  sends one event for the refreshes that come together.
- In the root element, the browser marks the component around each target as stale. The next
  event sends the stale ids, and the server renders them with their full subtree and always
  morphs them (`Tree::markStale()`). A stale component that is not in the page makes its parent
  stale. So the shape and the state of the page know the change.
- `update` and `replace` morph by default. A pushed morph keeps a bound field that the user
  changed. Before an `append` or a `prepend`, the browser removes an element of the target with
  the id of a new element (no duplicate for the user who made the change). While an event runs,
  the pushes wait for its morph.
- The streams go to the hub at the end of the request (`kernel.response`), after the transaction
  of the event, with `mercure_publish()` (no HTTP request, no publisher key). `StreamsMiddleware`
  drops the streams of a handler that throws.
- With `phem.mercure.subscriber_key`, the updates are private: the response sets the cookie of
  the hub with a token (`at+jwt`, modern Mercure) for the topics of the page and of the other
  tabs, and each event renews it. With no key, the updates are public.
- After a lost connection, the browser refreshes the content of the root element (the `local`
  transport has no history).
- The tests get the pushes with `$page->receive()` (a MemoryPublisher with `framework.test`).
  The profiler shows the pushed streams and the stale components. `phemc` reports E053 for
  `#[Subscribe]` on a class that is not a page. The Guestbook example uses it. See
  [docs/phpm-syntax.md](docs/phpm-syntax.md#real-time-updates).

- **Best peer:** LV PubSub: each LiveView subscribes to topics, and a broadcast re-renders all
  subscribers. LC uses Mercure and Turbo Streams. LW uses Laravel Echo with `#[On('echo:...')]`.
  Turbo 8 broadcasts page refreshes.
- **Later:**
  - Interactive pushed markup: a component with handlers in a push. Its fiber ids and handler
    ids depend on the page path, which a publisher does not know. A relative id that the server
    resolves through the stale component could work, with a `key` on the pushed component.
  - A component that handles a Messenger message (`#[AsMessageHandler]` on a component method)
    and returns its streams, so the markup stays next to the markup that it changes.
  - Publish from a CLI process (a Messenger worker): a publisher for the HTTP API of the hub,
    with a publisher key. `mercure_publish()` works only in a request of FrankenPHP.
  - A replay of the lost updates (`lastEventID`, with a transport that keeps a history), in
    place of the refresh after a lost connection.
- **Out of scope:** polling (LW `wire:poll`, LC `data-poll`). Each tick is a full event (the
  state, two render passes, a transaction) also when nothing changed. A push sends data only
  when it changes.

### [ ] Async handlers (fire and forget) — P3

A slow handler (a payment, an export, a call to an API) blocks the event and its morph.

- **Proposal:** `#[Async]` on a handler method. The browser sends the event as usual. The server
  does not run the method. It dispatches a generic Messenger message with the handler reference
  (`FQCN::method`) and the data of the event, and it answers at once. A generic message handler
  in the worker makes the component, and calls the method. The result goes to the open pages as
  a push (`Streams`), also to the browser of the user who clicked: its response is gone.

  ```php
  #[Async]
  public function ship(Event $e): void
  {
      $order = $this->orders->find($this->id);
      $order->ship();   // slow
      // A push: the worker has no response.
      $this->streams->to(OrderPage::class, ['id' => $this->id])
          ->update('order-status', <OrderStatus order={{ order }} />);
  }
  ```

- **Best peer:** LV `start_async` / `assign_async` (a task in the LiveView process, the result
  comes back as a message). LW `#[Async]` on an action (it runs in parallel, but in a request).
  Symfony Messenger with `#[AsMessage(transport: 'async')]`.
- **Open questions:**
  - The reference. `FQCN::method` from the browser must not call any method: sign it (as the
    handler ids now), and accept only a method with `#[Async]`. Is the compiler the place to
    check this (a new E code)?
  - The component in the worker. It has no tree, no fiber, no parent: what gives its props, its
    context (`#[Context]`) and its `#[State]`? Options: the signed state of the event, a
    serialized copy of the props (only scalars and entities by id), or a rule that an async
    method reads only its arguments and its services.
  - The `Event`. Serialize its data (the form fields, the route values of the page), but not the
    uploaded files and not the request. Is it a smaller `AsyncEvent` with no `refresh()`, no
    redirect and no response?
  - The user. The worker has no session and no token. Keep the user identifier in the message,
    and check the access rules (`#[IsGranted]`) at dispatch and again in the worker?
  - The sync part. Does the event still render (for example a "pending" state that the handler
    sets before the dispatch), or does the browser get only an empty answer?
  - The push from the worker. `mercure_publish()` works only in a request of FrankenPHP, so this
    needs the publisher for the HTTP API of the hub (see Real-time push, Later).
  - The transaction, the retries and the failures. The worker runs the method in a transaction
    (`doctrine_transaction` middleware). A retry runs it again: the method must be idempotent.
    How does the user see a failure (a push of an error)?
  - Is it worth it? A sync handler can already dispatch its own message
    (`$bus->dispatch(new ShipOrder($id))`). `#[Async]` saves the message class and its handler,
    but it hides a queue behind a method call.

## 3. Forms and validation

### [ ] Live validation of touched fields — P2

Symfony forms work in a submit handler. There is no validation while the user types.

- **Best peer:** LC `ValidatableComponentTrait` + `ComponentWithFormTrait`: it validates on each
  change but shows the errors only for the fields that the user touched. LW `#[Validate]` on a
  property, with real-time validation. LV changesets with `used_input?`.
- **Proposal:**
  - `#[Assert\...]` constraints on `#[State]` properties, validated after `bind` changes.
  - Track touched fields in the state. Show errors only for them, and for all fields after a
    submit.
  - For a Symfony form: keep the submitted values in the state, and call `submit()` with
    `clearMissing: false` on each change (as LC does).

## 4. Client escape hatch

### [ ] Colocated client code and refs — P2

Some behavior must run in the browser: a chart, a map, a rich text editor, a focus.

- **Best peer:** LV 1.1 colocated hooks: a `<script :type={ColocatedHook}>` next to the markup,
  extracted at compile time, with `mounted`, `updated` and `destroyed` callbacks. React refs and
  `useEffect`. LW Alpine.
- **Proposal:**
  - `phemc` extracts a `<script type="phem/controller">` from a `.phpm` file into a Stimulus
    controller and registers it with AssetMapper. The component root gets its
    `data-controller` automatically.
  - The controller gets the server events of the item "Server-to-browser events".

## 5. Errors and resilience

### [x] Error boundaries — P2

An exception in one component breaks the full event.

Done: a built-in `<ErrorBoundary>` component, with a required `fallback` slot. When the render of
the children throws (also in `mount()`), the boundary drops their fibers (`Renderer::catching()`)
and renders the fallback, only for this subtree. The logger gets the exception. The boundary has
no state: each render tries the children again, so they show again when they do not throw, and
they mount again. The boundary catches render errors only, as in React. An exception in a handler
fails the event: the transaction rolls back, and the response does not change the state of the
page. The Dashboard example uses it. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#errors-in-a-part-of-the-page).

- **Best peer:** React error boundaries. LV crash recovery (the process restarts and the client
  recovers the form values).
- **Later:** in debug mode, show the Symfony exception page in an overlay (as LW does in
  development).

## 6. Developer experience

### [x] Symfony profiler panel — P2

Done: in debug mode, a Phem panel in the Symfony profiler, for each page request and each event:
the handler and its outcome, the component tree with the state and the own render time of each
component in each pass, the components that changed, the state size and the state cache, the
bound values, the browser events, the warnings of the dev-mode check, and the Doctrine
transaction. The Performance panel shows the passes, the handler and the transaction. The browser
console logs each event with a link to its panel. See
[docs/compiling.md](docs/compiling.md#the-symfony-profiler).

- **Best peer:** React DevTools (the component tree, props and state). The Symfony profiler
  panels of LC.
- **Later:** the props of each component (they can be entities and closures, so they need a
  safe dump).

### [x] Language server and PhpStorm support — P2

Done: `phemc lsp` runs a language server (the `phemc-lsp` crate). It gives the diagnostics of the
compiler as the user types (with the unsaved text of the open documents), and the completion
(components, props, slots, HTML, Twig, PHP members, variables, and `use` imports), go to
definition, hover, and the outline. The server replaces the JavaScript engine of the VS Code
extension. The editors bundle the server compiled to WebAssembly (`make lsp`) and run it with
Node, so they need no `phemc` binary. The VS Code extension is a thin client (`make vscode`).
The PhpStorm plugin uses the LSP API of the IntelliJ Platform and the same TextMate grammar
(`make phpstorm`). See [editors/vscode/README.md](editors/vscode/README.md) and
[editors/phpstorm/README.md](editors/phpstorm/README.md).

- **Best peer:** the Laravel Idea plugin for Livewire. The Elixir language server for HEEx.
- **Later:** find references and rename of components and props, code actions (for example,
  add a missing import), and stubs for the PHP built-in classes. A small native PhpStorm
  plugin that injects PHP into `.phpm` files, so the PHP features of PhpStorm (refactorings,
  find usages) work there too.
