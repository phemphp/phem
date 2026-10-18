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

- [ ] **Bound state (`bind`)** (section 1). React has no two-way binding: is `bind` a new
      concept, or a shorthand for `value` + `onChange`? Which property types can the compiler
      accept? How does a bound value join the signed state before the handler runs?
- [ ] **Debounce and throttle syntax** (section 1). An attribute next to the handler, a
      modifier in the attribute name (`onChange:debounce`), or a wrapper value
      (`debounce(search, 300)`)? Which default applies to text inputs?
- [ ] **File uploads** (section 1). A temporary file must be in the signed state as a
      reference. How long is the reference valid, and how does it relate to
      `phem.state_max_age` and `phem.state_bind_session`? What happens to the file when the
      transaction of the handler rolls back?
- [ ] **Optimistic UI and client commands** (section 2). How does a PHP `Js` builder look in
      Twig markup? How does the Turbo morph keep the client changes until the server answers?
- [ ] **Render only the components that can change** (section 3). A component that reads a
      service can change with no change in its props or state. How does a component declare its
      inputs? Can the "before" render come from hashes in the signed payload?
- [ ] **State payload and a server-side store** (section 3). Is a server store still
      "stateless"? How does it work with FrankenPHP workers on many machines? Which mode is
      the default?
- [ ] **`<Suspense>`** (section 3). A deferred child needs its own event with the state of the
      page. Is that a normal event to a built-in handler? How does an error in the deferred
      render show?
- [ ] **Streams** (section 3). The items of a stream are not in the state. How does a later
      event render the list, and how does the "before/after" comparison see it?
- [ ] **Context** (section 4). React uses context for UI values and Phem uses services for the
      rest. Where is the line? Can `phemc` check that a provider is above each consumer, also
      across `.phpm` files?
- [ ] **Real-time push** (section 4). The server cannot re-render a page alone. Is a Mercure
      signal + a normal event from the browser enough? How do many signals in a short time
      coalesce? Who can subscribe to a topic (private topics, JWT)?
- [ ] **Live validation** (section 5). The touched fields go in the signed state. How does
      this work with a Symfony form, and with `#[Assert]` on `#[State]` properties? Does
      validation run in the Doctrine transaction?
- [ ] **Colocated client code** (section 6). Is the extracted script a Stimulus controller, or a
      smaller hook API? How does `phemc` give it to AssetMapper in development (`phemc watch`)
      and in production?
- [ ] **`<ErrorBoundary>`** (section 7). An exception in a render and an exception in a
      handler are different: the handler transaction rolls back, and the state may not change.
      Which state does the response send? Is the boundary only for rendering, as in React?

## 1. Input and requests

### [ ] Debounce, throttle and event coalescing — P1

Today `onChange` on a text input sends one request for each key. The queue sends all of them,
one after the other, so a fast typist waits for many old renders.

- **Best peer:** LV `phx-debounce` / `phx-throttle`. It has a `"blur"` value (send on blur only),
  and it sends a pending event at once on submit. LW `wire:model.live.debounce.300ms`.
- **Proposal:**
  - A `debounce` and `throttle` option for each handler, in milliseconds or `'blur'`.
    Sketch: `<input onChange={{ search }} debounce={{ 300 }} />`.
  - A default debounce for text inputs (for example 150 ms), configurable in `phem.yaml`.
  - Coalescing: when the queue has an event for the same handler and element that did not
    start yet, replace it with the new one.
  - A submit or a click sends the pending debounced events first, so no input is lost.

### [ ] Bound state without a request on each key (deferred binding) — P1

A controlled input needs `value` + `onChange` + a handler that only copies the value.
This sends a request for each change.

- **Best peer:** LW `wire:model` (deferred by default since v3: the value goes with the next
  action), with `.live` and `.blur` modifiers. LC `data-model` with `on(change)`.
- **Proposal:** a `bind` attribute that links an input to a `#[State]` property. The browser
  keeps the new value and sends it with the next event. The server sets the property before
  it runs the handler. Options: `live` (send on each change, with debounce) and `blur`.
  Sketch: `<input bind={{ query }} />`, `<input bind:live={{ query }} debounce={{ 300 }} />`.
- **Notes:** the compiler checks that the property is `#[State]` and that its type accepts the
  input value (string, bool for a checkbox, list for `<select multiple>`). Nested paths
  (`bind={{ filters.status }}`) are a later step.

### [ ] File uploads — P1

The browser does not send the files of a form today (`formFields()` drops them).

- **Best peer:** LV `allow_upload` / `live_file_input`: upload starts on select, progress for
  each file, size and type limits checked before the upload, chunked upload, direct upload to
  cloud storage (presigned URL), and cancel. LW `WithFileUploads`: temporary uploads with a
  signed URL, and previews.
- **Proposal:**
  - Upload each file to a temporary endpoint as soon as the user selects it. The state keeps
    a signed reference, not the bytes.
  - `$e->files` (or the Symfony `Request` files) in the handler gives `UploadedFile` objects,
    so Symfony forms with `FileType` work.
  - Limits (`maxSize`, `accept`, `maxFiles`) on the component, checked in the browser and on
    the server.
  - Progress for each file, available in markup (`upload.progress`).
  - An adapter for direct upload to S3-compatible storage (Flysystem).
  - Temporary files expire. A cleanup command removes them.

## 2. Feedback while a request runs

### [ ] Loading states — P1

The user does not see that an event runs.

- **Best peer:** LV adds `phx-click-loading`, `phx-submit-loading` and `phx-change-loading`
  classes to the element and its form automatically, with no code. LC 3.1 uses the standard
  `aria-busy` attribute. LW `wire:loading` with `wire:target` and a delay.

Done: while an event runs, the element that sent it and its component root (the closest
`<phem-c>`, or the `<Root>` element) get `aria-busy="true"` and the `phem-loading` class. They
show after a delay of 200 ms (`data-phem-loading-delay-value`), to stop flicker.

Not done yet:

- [x] Disable a submit button and its form inputs during a submit (as LV does), with an opt-out:
      `data-phem-disable={{ false }}` on the form.
- [ ] Custom markup: `<Spinner phem:loading={{ save }} />` shows only while `save` runs.

### [ ] Optimistic UI and client commands — P2

Some changes do not need the server first: open a menu, hide a row, toggle a class.

- **Best peer:** LV `Phoenix.LiveView.JS` commands (`show`, `hide`, `toggle_class`,
  `transition`, `push`). They run in the browser, compose with a server event, and survive
  a DOM patch. React `useOptimistic`.
- **Proposal:** a `Js` builder in PHP that compiles to a data attribute that the Stimulus
  controller runs. A command list can end with a server handler.
  Sketch: `<button onClick={{ js.hide('#row-' ~ id).then(remove) }}>`.
  The morph must keep the client changes until the server answers (as LV does).

### [ ] Dirty state and "leave page" guard — P3

- **Best peer:** LW `wire:dirty` (style for unsaved bound values).
- **Proposal:** a `phem-dirty` class on inputs with a `bind` value that the server did not
  get yet. An optional `beforeunload` guard for a component with dirty values.

### [ ] Transitions — P3

- **Best peer:** LV `JS.transition`. LW `wire:transition`, which uses the View Transitions API.
- **Proposal:** use `document.startViewTransition()` around the morph when the browser has it.
  Opt in for each component or element (`transition={{ 'fade' }}`).

## 3. Rendering and performance

### [ ] Render only the components that can change — P1

Today an event renders the full page tree two times (before and after the handler), then it
compares the two renders. The cost grows with the page size, not with the change size.

- **Best peer:** LV change tracking: the compiler splits static and dynamic parts, and only the
  dynamic parts that use a changed assign render again. React `memo`. LW islands
  (`@island`): an action re-renders only its island.
- **Proposal:**
  - Keep the render of the page from before the handler in the signed payload as hashes, one
    for each component, so the "before" render is not necessary.
  - Skip a component when its props and its state did not change (automatic `memo`).
    A component that reads services (a repository) can change without a prop change, so it
    needs an opt-in (`#[Component(memo: true)]`) or a way to declare its inputs.
  - Later: the `phemc` compiler knows the static parts of each template, so it can send only
    the dynamic values (as LV does).

### [ ] Smaller state payload, or state on the server — P2

The browser sends all the state of the page on each event. A big page sends a big payload.

- **Best peer:** Blazor and LV keep state on the server (no payload, but memory for each user).
  LW and LC send one small snapshot for each component, because each request is for one
  component.
- **Proposal:**
  - Compress the state (it is JSON, and it repeats keys).
  - An opt-in server-side store (`phem.state_store: cache|session`): the browser keeps only an
    id and a version. The current signed-state mode stays the default.
  - Send only the state of the components that changed in the response.

### [ ] Lazy and deferred components (Suspense) — P2

A slow component (a report, an API call) blocks the full page render.

- **Best peer:** React `<Suspense fallback>`. LV `assign_async` + `<.async_result>` (loading,
  ok and failed states in one place). LC `loading="defer"` / `loading="lazy"` (lazy waits
  until the element is visible). LW `#[Lazy]` with a `placeholder()` method.
- **Proposal:** a built-in `<Suspense>` component, as in React, with a `fallback` slot. The page
  renders the fallback, then the browser loads the children with an event (`defer`), or when the
  children become visible (`lazy`, IntersectionObserver). A failure renders an error slot.
  Sketch: `<Suspense lazy><Suspense:fallback><Spinner /></Suspense:fallback><Report /></Suspense>`.
- **Later:** stream the deferred parts in the first response (HTTP streaming), with no second
  request.

### [ ] Streams for long lists — P3

- **Best peer:** LV streams: insert, update and delete items in a list without the list in
  server memory or in the state.
- **Proposal:** a `Stream` value. A handler calls `$this->messages->insert($m)` or
  `->delete($m)`, and the response has a Turbo Stream `append` / `remove` for the item. The
  list is not part of `#[State]`. Good for chats, feeds and infinite scroll.

## 4. State and communication

### [ ] State in the URL — P2

Filters, search terms and tabs must survive a reload, a shared link, and the back button.

- **Best peer:** LW `#[Url]` with `as` (another query key), `history` (push or replace) and
  `except` (no key for the default value). LC `#[LiveProp(url: true)]`. LV `push_patch` +
  `handle_params`.
- **Proposal:** `#[State(url: true)]` or `#[Url(as: 'q', history: false)]`. The server reads the
  query string on the first render, and the browser updates the URL after each event
  (`history.replaceState` by default). Back and forward restore the state.

### [ ] Context (provide and inject) — P2

Props pass data one level at a time. Deep trees need "prop drilling".

- **Best peer:** React `createContext` + `useContext`. LC 3.1 `provide()` / `inject()` for Twig
  components.
- **Proposal:** services already replace module imports. For UI values (the theme, the current
  form, the locale of a section), add a provider component and an `#[Inject]` (or
  `#[Context]`) property. The compiler checks that a provider is above the consumer.

### [ ] Server-to-browser events — P2

A handler cannot ask the browser to do something today (a toast, a focus, a scroll, a
third-party JS library), except with a redirect.

- **Best peer:** LV `push_event` + `handleEvent` in a hook. LW `$this->dispatch()` (browser
  event) and `$this->js()`.

Done: `$e->dispatcher->dispatch('toast', ['message' => 'Saved'])` (a `BrowserEventDispatcher`) adds a browser event to the response.
After the morph, the browser dispatches a `CustomEvent` named `phem:toast` on the root of the
component of the handler, with the detail. It bubbles up to `window`. A handler that redirects sends no events. Test: `->assertDispatched('toast')`.

Not done yet:

- [x] An injected service to dispatch from a handler with no `Event` parameter, or from a
      service that the handler calls: autowire `Phem\BrowserEventDispatcher`.
- [x] Dispatch to an element (the component root) instead of `window`, so the event bubbles.

### [ ] Real-time push — P2

A change by one user does not show for another user until that user sends an event.

- **Best peer:** LV PubSub: each LiveView subscribes to topics, and a broadcast re-renders all
  subscribers. LC uses Mercure and Turbo Streams. LW uses Laravel Echo with `#[On('echo:...')]`.
- **Proposal:** Phem keeps state in the browser, so the server cannot re-render a page alone.
  Use Mercure (SSE, and FrankenPHP has a built-in Mercure hub):
  - `#[Subscribe('scientists')]` on a component method, or on a component that must refresh.
  - The server publishes a small signal to the topic (`$hub->publish('scientists')`).
  - The browser receives the signal and sends a normal event with its current state. The
    server renders, and the normal morph runs. Access rules and the signature stay the same.
  - Optional: a payload in the signal, passed to the handler as `$e->value`.

### [ ] Polling — P3

- **Best peer:** LW `wire:poll` (it slows down when the tab is in the background, and
  `wire:poll.visible` stops when the element is not visible). LC `data-poll`.
- **Proposal:** `#[Poll(seconds: 5)]` on a method, or a `<Poll every={{ 5 }} onTick={{ refresh }} />`
  built-in component. Pause it when the tab is hidden.

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
  - An `ignore` attribute (as LV `phx-update="ignore"` and LW `wire:ignore`) so the morph does not
    change the DOM that a JS library owns.
  - The controller gets the server events of the item "Server-to-browser events".

## 7. Errors and resilience

### [ ] Error boundaries — P2

An exception in one component breaks the full event.

- **Best peer:** React error boundaries. LV crash recovery (the process restarts and the client
  recovers the form values).
- **Proposal:** a built-in `<ErrorBoundary>` with a `fallback` slot. An exception in a child
  render or handler renders the fallback for that subtree only. In debug mode, show the
  Symfony exception page in an overlay (as LW does in development).

### [ ] Network errors, retry and offline state — P3

The controller logs a failed event to the console only.

- **Best peer:** LV reconnects automatically and recovers form values. LW `wire:offline`.
- **Proposal:** retry an idempotent failure (network error, 502, 503) with backoff. Add a
  `phem-offline` class on the root when the browser is offline. Show a default error message
  for a 500, with a hook to change it.

## 8. Developer experience

### [x] Test helpers — P1

Done: `Phem\Test\InteractsWithPhem` (see [docs/testing.md](docs/testing.md)).
`$this->mount(Counter::class, ['label' => 'Clicks'])->click('button')->assertSee('Clicks 1 times')`
renders a component in the test process. `$this->visit('/x')` loads a page and sends its events
over HTTP, so the access rules and the signature are tested too. Also `->change()`, `->submit()`,
`->assertState()` (with `in:` for a child component), `->assertRedirect()` and `->followRedirect()`.

Not done yet:

- [x] `->assertDispatched('toast')`, with the detail, and `->assertNotDispatched()`.
- [x] `->assertState()` for a child component: `->assertState('on', true, in: '#toggle')`.

### [ ] Symfony profiler panel — P2

- **Best peer:** React DevTools (the component tree, props and state). The Symfony profiler
  panels of LC.
- **Proposal:** a profiler panel for each event: the handler, the component tree, the state size,
  the render time for each component, the components that changed, and the Doctrine
  transaction. A browser log of the events in debug mode.

### [ ] Language server and PhpStorm support — P2

- **Best peer:** the Laravel Idea plugin for Livewire. The Elixir language server for HEEx.
- **Proposal:** `phemc lsp`: diagnostics, completion of components and props, go to definition,
  and hover, from the analyzer that already exists in Rust. The VS Code extension and a
  PhpStorm plugin use it.

## 9. Security

### [ ] Audit against the Live Components advisories of 2026 — P1

LC had these issues in 2026. Phem has a similar design, so check each one and add tests:

- [ ] CSRF bypass with the `Accept` header. Phem checks `Sec-Fetch-Site` / `Origin` and requires
      JSON: confirm that no other content type or header combination skips the check.
- [ ] Denial of service with unbounded batch requests. Phem has one event for each request, but
      limit the body size, the state size, and the depth of the JSON.
- [ ] XSS with child component tags. Check the escaping of props and slots that go into
      `{% component %}` tags and into data attributes.

### [ ] Rate limit for events — P3

- **Proposal:** an optional Symfony RateLimiter for each page or handler
  (`#[Page(..., eventRateLimit: 'phem_events')]`).
