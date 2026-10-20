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

- [ ] **Streams** (section 3). The items of a stream are not in the state. How does a later
      event render the list, and how does the "before/after" comparison see it?
- [x] **Real-time push** (section 4). Decided: a component handles a Messenger message and
      returns a Turbo Stream, published with Mercure. See the decisions in section 4.
- [ ] **Live validation** (section 5). The touched fields go in the signed state. How does
      this work with a Symfony form, and with `#[Assert]` on `#[State]` properties? Does
      validation run in the Doctrine transaction?
- [ ] **Colocated client code** (section 6). Is the extracted script a Stimulus controller (as
      the controllers of section 2), or a smaller hook API? How does `phemc` give it to
      AssetMapper in development (`phemc watch`) and in production?

## 1. Input and requests

### [x] File uploads — P1

The browser does not send the files of a form today (`formFields()` drops them).

Done: a submit event with files goes as `multipart/form-data`, with the JSON of the event in the
`_phem` part. The handler gets `UploadedFile` objects in `$e->files` and in `$e->request->files`,
so a Symfony form with `FileType` works with `handleRequest($e->request)`. PHP removes a file that
the handler does not move at the end of the request. The files of an event have a maximum size
together (`phem.max_upload_size`, 8 MiB by default, 413 over it), and the JSON part keeps
`phem.max_event_size`. A multipart event has the same CSRF checks (`X-Phem-Event`, same origin).
A file over `upload_max_filesize` of PHP gets to the handler with its upload error, so the
form shows it. The test helper has `->upload()`. The PhotoUpload example uses it. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#file-uploads).

Done: progress for each file, available in markup. An event with files goes with XHR.
`<progress phem:progress={{ save }}>` shows the progress of the upload of a `save` event (its
`value`, and the `--phem-progress` CSS property of any element). The form dispatches
`phem:upload-progress`, with the progress of each file. The server does not know the files before
the event, so the markup shows the progress of all the files together.

- **Best peer:** LV `allow_upload` / `live_file_input`: upload starts on select, progress for
  each file, size and type limits checked before the upload, chunked upload, direct upload to
  cloud storage (presigned URL), and cancel. LW `WithFileUploads`: temporary uploads with a
  signed URL, and previews.
- **Notes:** Phem is a UI component model. It brings the files to the handler, and the
  application decides what to do with them: where to keep them, how to check them
  (`#[Assert\File]`), and what to do when the transaction rolls back.
- **Out of scope:** upload on select (temporary uploads) and direct upload to cloud storage
  (presigned URL). They need storage, an expiry, and cleanup, and the server of Phem keeps no
  session. An application does them with its own endpoint or a JS uploader, and puts the result
  (an id or a path) in a hidden field of the form. The submit event sends it as a normal field.

## 2. Feedback while a request runs

### [x] Client code with Stimulus and optimistic UI — P2

Some changes do not need the server first: open a menu, hide a row, toggle a class.

Done: these changes are Stimulus controllers, as in any Symfony page, and not a new syntax.
`data-phem-client="hidden aria-expanded"` gives the attributes in the list to the client, so the
morph does not undo the changes of a controller. `data-phem-ignore` keeps an element and its
content for a JS library. The element that sent an event gets `phem:send` when the request starts,
and `phem:done` when the event ends (`ok`, and the `status` of a failure). A Stimulus action on the
element runs before the event, so it can change the page at once and undo it on `phem:done`. The
Inbox example uses them. The morph matches the items of a list by their `key`, so the client
changes stay on the correct item. The tests of the controller run with Vitest (`make test-js`).
See [docs/phpm-syntax.md](docs/phpm-syntax.md#client-code-with-stimulus).

- **Best peer:** LV `Phoenix.LiveView.JS` commands. React `useOptimistic`. LC and Turbo use
  Stimulus.
- **Notes:** LV needs `JS` commands because it has no client framework for them. Phem runs on
  Stimulus already, so a second syntax adds API but no capability.

### [x] Dirty state and "leave page" guard — P3

Done: a bound field that the server did not get yet has the `phem-dirty` class, and so do its
component, its form, its `data-phem-guard` element, and the root element. `data-phem-guard` asks
the user before they leave the page with dirty fields (`beforeunload`, and a `confirm()` for a Turbo
visit). The PackingList example uses it. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#unsaved-changes).

- **Best peer:** LW `wire:dirty` (style for unsaved bound values).

## 3. Rendering and performance

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

## 4. State and communication

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

### [ ] Real-time push — P2

A change by one user does not show for another user until that user sends an event.

- **Best peer:** LV PubSub: each LiveView subscribes to topics, and a broadcast re-renders all
  subscribers. LC uses Mercure and Turbo Streams. LW uses Laravel Echo with `#[On('echo:...')]`.
- **Proposal:** a component handles a Messenger message with the native `#[AsMessageHandler]`,
  and returns a `Phem\Stream`. Phem publishes it to Mercure as a Turbo Stream, and Turbo
  applies it in each browser. The plain PHP code (a controller, a worker, a Doctrine
  listener) only dispatches the message, so it needs no markup. The markup stays in the
  `.phpm` file, next to the markup that it changes. No browser sends a request.

  ```php
  // plain PHP, anywhere
  $this->bus->dispatch(new OrderPlaced($order->getId()));
  ```

  ```php
  #[Component]
  final class OrderFeed
  {
      #[Prop, Topic] public Customer $customer;
      public array $orders = [];

      public function __construct(private readonly Orders $repo) {}

      public function mount(): void { $this->orders = $this->repo->latestOf($this->customer); }

      #[AsMessageHandler]
      public function placed(OrderPlaced $m): Stream
      {
          $order = $this->repo->find($m->orderId);

          return Stream::prepend('orders', <OrderRow order={{ order }} />)->to($order->customer);
      }

      #[AsMessageHandler]
      public function cancelled(OrderCancelled $m): Stream
      {
          return Stream::remove('order-'.$m->orderId)->to($m->customerId);
      }

      public function render(): Node
      {
          return (
              <ul id="orders">
                  {% for o in orders %}<OrderRow key={{ o.id }} order={{ o }} />{% endfor %}
              </ul>
          );
      }
  }
  ```

- **Decisions:**
  - **Registration.** `ComponentPass` registers each class component as an autoconfigured
    service, before the autoconfiguration passes of Symfony. So Messenger finds
    `#[AsMessageHandler]` on a component method. A Phem compiler pass wraps each component
    handler: the wrapper calls the method and publishes the returned `Stream`. It works on
    each bus, sync or async, with no middleware configuration. `symfony/mercure-bundle` and
    `symfony/messenger` are "suggest" dependencies.
  - **The handler method runs outside a page.** It has no props, no state, no context, no
    request and no user. It reads only the constructor services and the message. `phemc`
    reports an error when the method reads a `#[Prop]`, `#[State]` or `#[Context]` property.
    Phem renders the returned markup alone, with no page.
  - **No `#[State]` in a component with message handlers** (a `phemc` error). The component
    reads its data in `mount()`, so the next event renders the same data that the push
    showed. A handler id comes from the handler and its captured values, so a click on a
    pushed row finds its handler.
  - **Topics.** The topic of a component is its class name. A component with a `#[Topic]`
    prop also has a scoped topic: the class name and the value of the prop
    (`App\OrderFeed/42`). The value is a scalar, a backed enum, or a Doctrine entity (its
    identifier). A component has one `#[Topic]` prop at most (a `phemc` error). A stream
    without `->to()` goes to the class topic, so it reaches all instances. A stream with
    `->to($value)` goes only to the scoped topic of that value.
  - **Subscription.** When a page renders a component with message handlers, Phem adds one
    `<turbo-stream-source>` for the topics of the page. When conditional rendering removes
    the component, its topics leave the subscription.
  - **All topics are private.** Phem publishes each update as private. When a page renders,
    Phem sets the `mercureAuthorization` cookie with a subscriber JWT for the topics of the
    page. The page passed its access rules (firewall, `access_control`, `#[IsGranted]`), so
    the viewer can see what the server rendered for them. The JWT also keeps the topics of a
    valid cookie of the same user, so two open tabs keep their subscriptions. A user cannot
    subscribe to the scoped topic of another customer by a guess.
  - **One render for all subscribers.** A pushed stream cannot show data for one user only.
    Put user data in a scoped topic for that user.
  - **The page after a push.** Pushed markup has no fiber ids. The browser keeps the ids of the
    components that got a stream, and sends them with the next event. The server renders these
    components as dirty, so the morph replaces the pushed markup with markup that has ids.
  - **Duplicates.** Pushed items have an `id`. Turbo removes an element with the same id before
    an `append` or a `prepend`, so the user who made the change does not see the item two times.
  - **Transactions.** A component handler that runs in an event (a sync transport, in the
    Doctrine transaction) does not publish at once. Phem keeps the streams until the commit,
    and drops them on a rollback.
  - **`Stream` actions.** The Turbo actions: `append`, `prepend`, `replace`, `update`,
    `remove`, `before`, `after`, and the `morph` method for `replace` and `update`.
  - **Tests.** `$this->handle(new OrderPlaced(1))` in `InteractsWithPhem` runs the component
    handlers of the test page for the message, and applies the streams to the page.
- **Out of scope:** polling (LW `wire:poll`, LC `data-poll`). Each tick is a full event (the
  state, two render passes, a transaction) also when nothing changed. A push sends data only
  when it changes.

## 5. Forms and validation

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

## 6. Client escape hatch

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
  - Done: `data-phem-ignore` (as LV `phx-update="ignore"` and LW `wire:ignore`), see section 2.

## 7. Errors and resilience

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

## 8. Developer experience

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
