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

- [x] **Bound state (`bind`)** (section 1). Decided: a new concept in the `phem:` namespace
      (`phem:bind={{ query }}`), not a shorthand. The value is the name of a `#[State]` property
      of the class. The compiler checks the property type against the field: a string, a
      number or a backed enum; a bool or a list for a checkbox; a list for `<select multiple>`.
      The server sets the values after the first render and before the handler, only for the
      properties that the page binds, and only when the value fits the type. `phem:live` sends
      each change.
- [x] **Debounce and throttle syntax** (section 1). Decided: an attribute in the `phem:`
      namespace next to the handler (`phem:debounce={{ 300 }}`), as `phem:loading`. A modifier
      in the name cannot hold milliseconds, and a wrapper value breaks "pass the function, do not
      call it". Text inputs have a debounce of 150 ms by default (a `<Root>` value).
- [x] **File uploads** (section 1). Decided: a form event with files goes as
      `multipart/form-data` (the JSON payload in one part, and the files), not to a temporary
      upload endpoint. PHP keeps each file for that request only, as for a normal form submit,
      so there is no reference, no expiry and no cleanup, and `phem.state_max_age` and
      `phem.state_bind_session` do not change. The handler gets `UploadedFile` objects in
      `$e->files` and `$e->request->files` (so `FileType` works). What the handler does with a
      file, also when its transaction rolls back, is the concern of the application.
- [ ] **Optimistic UI and client commands** (section 2). How does a PHP `Js` builder look in
      Twig markup? How does the Turbo morph keep the client changes until the server answers?
- [x] **Render only the components that can change** (section 3). A component that reads a
      service can change with no change in its props or state. How does a component declare its
      inputs? Can the "before" render come from hashes in the signed payload?
      Decided (as React): `render()` must be pure, so a component declares no inputs. An event
      renders the subtrees of the dirty components (the owner of the handler, and the components
      whose state changed), and skips a child when its props did not change. The "before"
      render comes from one hash for each component in the signed payload. See the
      implementation plan in section 3.
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

### [x] Debounce, throttle and event coalescing — P1

Today `onChange` on a text input sends one request for each key. The queue sends all of them,
one after the other, so a fast typist waits for many old renders.

Done: `phem:debounce={{ 300 }}` (or `{{ 'blur' }}`) and `phem:throttle={{ 300 }}` on the element
of the handler. The `onChange` and `onInput` events of text inputs have a debounce of 150 ms by
default (`data-phem-debounce-value` on the `<Root>`, 0 turns it off). A blur sends the waiting
event of the element, and any other event sends all the waiting events first. An input or change
event in the queue that did not start yet takes the value of a newer event of the same handler and
element. phemc checks the values (E032) and warns about an element with no handler (W006). The
VS Code extension completes the `phem:*` attributes. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#debounce-and-throttle).

- **Best peer:** LV `phx-debounce` / `phx-throttle`. It has a `"blur"` value (send on blur only),
  and it sends a pending event at once on submit. LW `wire:model.live.debounce.300ms`.
- **Proposal:**
  - A `debounce` and `throttle` option for each handler, in milliseconds or `'blur'`.
    Sketch: `<input onChange={{ search }} debounce={{ 300 }} />`.
  - A default debounce for text inputs (for example 150 ms), configurable in `phem.yaml`.
  - Coalescing: when the queue has an event for the same handler and element that did not
    start yet, replace it with the new one.
  - A submit or a click sends the pending debounced events first, so no input is lost.

### [x] Bound state without a request on each key (deferred binding) — P1

A controlled input needs `value` + `onChange` + a handler that only copies the value.
This sends a request for each change.

Done: `<input phem:bind={{ query }} />` shows a `#[State]` property, and the next event sends the
new value (only the fields that changed). The server sets the property before the handler, after
the first render, and only for a property that the page binds. It converts the text of the field
to the type of the property (`int`, `float`, backed enum, `null` for an empty field), and ignores
a value that does not fit: the field shows the server value again. `phem:live` sends each change
with no handler, with the debounce of the field. phemc checks the property and its type (E033) and
warns about `phem:live` with no `phem:bind` (W007). The test helper has `->fill()`. The SearchBox
example uses it. See [docs/phpm-syntax.md](docs/phpm-syntax.md#bound-fields).

Not done yet:

- [x] Nested paths (`phem:bind={{ filters.status }}`, `phem:bind={{ rows[i].name }}`) in an
      `array` property. The server sets only a key that the array has, with the type of its
      current value (no PHPDoc). phemc checks that the property is an array (E033).
- [x] Bind a property of a parent component from a child component: `phem:bind={{ query }}` on
      the tag of the child gives its `Phem\Bound` prop, and the child binds its field to this prop
      (`phem:bind={{ value }}`). The key of the field is the key of the parent property. phemc
      checks that the child has one `Bound` prop (E033).

- **Best peer:** LW `wire:model` (deferred by default since v3: the value goes with the next
  action), with `.live` and `.blur` modifiers. LC `data-model` with `on(change)`.
- **Proposal:** a `bind` attribute that links an input to a `#[State]` property. The browser
  keeps the new value and sends it with the next event. The server sets the property before
  it runs the handler. Options: `live` (send on each change, with debounce) and `blur`.
  Sketch: `<input bind={{ query }} />`, `<input bind:live={{ query }} debounce={{ 300 }} />`.
- **Notes:** the compiler checks that the property is `#[State]` and that its type accepts the
  input value (string, bool for a checkbox, list for `<select multiple>`). Nested paths
  (`bind={{ filters.status }}`) are a later step.

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

Not done yet:

- [x] Progress for each file, available in markup. An event with files goes with XHR.
      `<progress phem:progress={{ save }}>` shows the progress of the upload of a `save` event
      (its `value`, and the `--phem-progress` CSS property of any element). The form dispatches
      `phem:upload-progress`, with the progress of each file. The server does not know the files
      before the event, so the markup shows the progress of all the files together.
- [ ] Upload on select (to a temporary endpoint), for big files or a preview before the submit.
- [ ] Direct upload to S3-compatible storage (presigned URL).

- **Best peer:** LV `allow_upload` / `live_file_input`: upload starts on select, progress for
  each file, size and type limits checked before the upload, chunked upload, direct upload to
  cloud storage (presigned URL), and cancel. LW `WithFileUploads`: temporary uploads with a
  signed URL, and previews.
- **Notes:** Phem is a UI component model. It brings the files to the handler, and the
  application decides what to do with them: where to keep them, how to check them
  (`#[Assert\File]`), and what to do when the transaction rolls back.

## 2. Feedback while a request runs

### [x] Loading states — P1

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
- [x] Custom markup: `<span phem:loading={{ save }}><Spinner /></span>` shows only while `save`
      runs. See [docs/phpm-syntax.md](docs/phpm-syntax.md#loading-states).

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

### [x] Transitions — P3

- **Best peer:** LV `JS.transition`. LW `wire:transition`, which uses the View Transitions API.
- **Proposal:** use `document.startViewTransition()` around the morph when the browser has it.
  Opt in for each component or element (`transition={{ 'fade' }}`).

Done: `data-phem-transition={{ 'fade' }}` on an element runs the morph of the events of its
components in one view transition. The value is the transition type, for CSS
`:active-view-transition-type(fade)`. No transition when the browser does not have the API, or
with `prefers-reduced-motion: reduce`. See [docs/phpm-syntax.md](docs/phpm-syntax.md#transitions).

## 3. Rendering and performance

### [x] Render only the components that can change — P1

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
- **Decided (the React model):**
  - Rule: `render()` is pure. Its output depends only on the props and the `#[State]` of the
    component, and on the plain properties that `mount()` or a handler of the component set in
    the same request (for example a Symfony form with its errors). `render()` does not call
    services, and it does not read the time, the session or other globals. A component reads
    services in `mount()` or in a handler (as `TodoList` does). Two components that need the
    same data lift it up to their common parent, which passes it down as props. A component
    declares no inputs, and there is no `memo` opt-in.
  - An event renders again only the dirty components and their subtrees. Phem skips the rest of
    the page. A dirty component is the owner of the handler (the `$this` of the handler
    closure, or else the component that registered it), or a component whose `#[State]`
    changed (in the handler, or with a bound value). Phem cannot see a change of a plain
    property, so the owner of the handler is always dirty.
  - In a subtree that renders again, Phem skips a child (and its subtree) when it is not dirty
    and its props did not change. As React `Object.is`, the props compare with `===`: a closure
    compares by its fingerprint and its `$this`, and a slot by its template and the context of
    its caller. A handler that changes an object (an entity) in place does not change the props
    of the other components that show it: they do not render again.
  - `mount()` of a component with no `#[State]` runs each time that the component renders, not
    on each request. A skipped component does not run it.
  - Escape hatch, as `router.refresh()` of Next.js: `$e->refresh()` in a handler renders the full
    page again. For example, a form that adds a row to a list that another component shows.
  - Checks, as React `StrictMode` and its lint rules: `phemc` warns when `render()` calls a
    service or reads a global (W008). In dev mode, Phem also renders the full page, and warns
    when the output of a skipped component changed.
  - Cost: correctness is the job of the developer. A `render()` that breaks the rule shows stale
    data in production. The checks above find most of these errors in development.
  - The "before" render comes from one hash for each component in the signed payload. When a
    hash changes, the response has the markup of that component, and the browser morph finds
    the changed nodes. Keep the old markup out of the payload.
- **Done:** steps 1 to 9 below. `TreeShape` (the `_tree` key of the state), `PathScope` and
  `UpdateScope` (the two passes), `EventRenderer` (the event flow of `EventController` and of
  the test driver), `Event::refresh()`, `SkippedRenderCheck` (with `kernel.debug`), the
  `data-phem-keep` placeholder in the `phem` controller, and W008 in `phemc`. See
  [docs/phpm-syntax.md](docs/phpm-syntax.md#keep-render-pure).
- **Implementation plan.** Each step is one change with its tests. The tests of the steps
  before stay green.
  1. **Tree shape and hashes in the payload.** `PageState` keeps, for each fiber of the page,
     its parent id and the xxh3 hash of its own HTML (`Fiber::$ownHtml`), next to its state
     (now one compact string, see "Smaller shape" below).
     `StateAccessor::snapshot()` writes them, and the signature covers them. No change of
     behavior.
  2. **"Before" from the hashes.** `Tree::changedFibers()` compares the own HTML of the second
     pass with the hashes of the payload, not with the first pass. The first pass still renders
     the full page, but only to get the handlers. Test: the same streams as before.
  3. **Kept children in the response.** The HTML of a skipped fiber in its parent is
     `<phem-c id="…" data-phem-keep></phem-c>`. The `phem` controller cancels
     `turbo:before-morph-element` for such an element, so the browser keeps its content.
     `Fiber::output()` and `TurboStreams` know the skipped fibers. Test in the JS tests and in
     a functional test with a skipped child.
  4. **First pass on the path only.** The ids of the target fibers are the fiber of the handler
     id and the fibers of the bound keys (see `BoundValues`). With the parent ids of step 1,
     `Tree` knows the path from the page to each target. The first pass renders only the fibers
     on these paths. It skips the other children, but it keeps their props, slots and caller
     context. The state and the `#[Url]` values of the skipped fibers come from the payload
     (`StateAccessor::snapshot()`, `UrlQuery::of()`). Test: a click in one component of a page
     with 100 components renders only the fibers on its path (count the calls of `render()`).
  5. **Dirty fibers and the second pass.** Before the handler, keep the exported `#[State]` of
     each fiber on the paths. After it, a fiber is dirty when its state changed, or when it owns
     the handler (`ReflectionFunction::getClosureThis()`, through `Tree::fiberOf()`). The
     second pass renders each dirty fiber with no dirty ancestor, from its props, slots and
     caller of the first pass (a new `Renderer::renderSubtree()`; the parent fiber is on the
     stack, so the ids do not change). A child skips when the rules above allow it. A child that
     the first pass did not see (a child of a child that rendered again) always renders.
     A class component with no `#[State]` and a `mount()` always renders.
  6. **`$e->refresh()`.** A flag on `Event`. When it is set, the second pass renders the full
     page (as today, with the hashes of step 2). `AddScientist` calls it after an add, so the
     list of `DurableGallery` shows the new row.
  7. **Dev-mode check.** With `kernel.debug`, after the response is ready, render the full page
     in a new `Tree` with the new state, and compare the hash of each skipped fiber. A
     difference logs a warning with the component and the probable cause ("`render()` reads
     data that is not a prop or state"), and the `phem` controller shows it in the console.
  8. **`phemc` W008.** In `render()` and in the markup of a component: a method call on a
     property that the constructor gets (a service), a static property, a superglobal, or a
     clock or random function (`time()`, `date()`, `microtime()`, `hrtime()`, `rand()`,
     `mt_rand()`, `random_int()`, `uniqid()`, `new DateTime*()` with no argument). Snapshot
     tests in `phemc-codegen`, and a row in the list of warnings.
  9. **Tests helpers and docs.** `ComponentDriver` uses the new passes. Update README section 4,
     the "State" list in `docs/phpm-syntax.md` section 7, `docs/compiling.md` (the events render
     the whole page) and the DurableGallery text in `learn/describing-the-ui.html`: they say
     that an event renders the full page two times.
  10. **Measure.** A page with 500 rows and a counter: the time and the response size of a
      click on the counter, before and after.
  - **Measured** (step 10, a page with 500 function components and a counter, no
    `kernel.debug`): a click on the counter takes 7.4 ms (median) instead of 14.7 ms with two
    full renders, and it sends the same stream. But the request is 32.9 KB instead of 205 B: the
    shape has about 65 bytes for each component (the ids, the hashes, and the JSON escapes).
  - **Smaller shape** (same page, same machine, no `kernel.debug`). The `_tree` key is now one
    string: the fibers from the page down, each with its id (48 bits) and the hash of its own
    HTML (48 bits) in 8 base64url characters each, and its depth (the parent is the last fiber
    before it with one less depth). That is about 18 bytes for each component, with no JSON
    escapes. No change of behavior.

    | 502 fibers                                   | `_tree` in the request | event request | page HTML | event response |
    |----------------------------------------------|-----------------------:|--------------:|----------:|---------------:|
    | Before: `{"<id>": ["<parent id>", "<hash>"]}`  |              32,601 B |      32,815 B |  94,946 B |      109,801 B |
    | Parent index instead of parent id (JSON list) |              23,082 B |             — |         — |              — |
    | One string, hex ids and 64-bit hex hashes     |              15,048 B |             — |         — |              — |
    | Now: one string, base64url, 48-bit hashes     |               9,033 B |       9,247 B |  59,354 B |       74,209 B |

    The time of a click does not change (median 13.5 ms before and after in this measure). A
    48-bit hash misses a change of HTML with a probability of 2^-48 for each changed fiber.
  - Open points: the shape is still about 18 bytes for each component (see "Smaller state
    payload" below: for example the shape on the server). Does W008 report the
    Twig `app` global (`app.request`, `app.session`, `app.user`)? Later: hashes of the props in
    the payload, so a child that the first pass did not see can skip too.

### [ ] Smaller state payload, or state on the server — P2

The browser sends all the state of the page on each event. A big page sends a big payload.

- **Best peer:** Blazor and LV keep state on the server (no payload, but memory for each user).
  LW and LC send one small snapshot for each component, because each request is for one
  component.
- **Proposal:**
  - Compress the state (it is JSON, and it repeats keys). The `_tree` key is already compact
    (about 18 bytes for each component, see "Render only the components that can change").
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

### [x] State in the URL — P2

Filters, search terms and tabs must survive a reload, a shared link, and the back button.

- **Best peer:** LW `#[Url]` with `as` (another query key), `history` (push or replace) and
  `except` (no key for the default value). LC `#[LiveProp(url: true)]`. LV `push_patch` +
  `handle_params`.
- **Proposal:** `#[State(url: true)]` or `#[Url(as: 'q', history: false)]`. The server reads the
  query string on the first render, and the browser updates the URL after each event
  (`history.replaceState` by default). Back and forward restore the state.

Done: `#[State, Url]` on a property of a page or of a component, with `as` (another key) and
`history: true` (a new history entry, not a replace). The first render reads the query string after
`mount()`, and ignores a value that does not fit the type. After each event the browser writes the
values into the URL with the history of Turbo, so back and forward are Turbo restoration visits. A
default value removes its key. Two components with the same key fail. The test helper has
`->assertQuery()` and `->url()`. The SearchBox example uses it. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#state-in-the-url).

Not done yet:

- [x] An `except` option: a value other than the default value of the property that removes the key.
      `#[Url(except: 'name')]` replaces the default value, and must fit the type of the property.
- [x] Nested values (`?filters[status]=open`) for an array shape. An array with keys is nested in
      the URL, with only the keys that changed. The first render sets only the keys that the array
      has, with the type of the current value. An object is not possible: `#[State]` keeps arrays.

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

### [x] Network errors, retry and offline state — P3

The controller logs a failed event to the console only.

- **Best peer:** LV reconnects automatically and recovers form values. LW `wire:offline`.
- **Proposal:** retry an idempotent failure (network error, 502, 503) with backoff. Show a
  default error message for a 500, with a hook to change it.

Done: while the browser is offline (`navigator.onLine` is false), the root element has the
`phem-offline` class.

Not done yet:

- [x] Retry an idempotent failure (network error, 502, 503) with backoff: 3 tries by default
      (`data-phem-retries-value`), 500 ms then double (`data-phem-retry-delay-value`), and
      `Retry-After`. While offline, the next try waits for the `online` event.
- [x] A default error message for a 500, with a hook to change it: a cancelable `phem:error`
      event on the component root, with the `handler` and the `status`.

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

### [x] Audit against the Live Components advisories of 2026 — P1

LC had these issues in 2026. Phem has a similar design, so check each one and add tests:

- [x] CSRF bypass with the `Accept` header. Phem checks `Sec-Fetch-Site` / `Origin` and requires
      JSON from the `Content-Type` header, not from `Accept`. A request without the
      `X-Phem-Event` header is a page request, and the handler does not run. Tests cover the
      other content types, a content type parameter, `Sec-Fetch-Site: none`, and another port.
- [x] Denial of service with unbounded batch requests. Phem has one event for each request.
      The body has a maximum size (`phem.max_event_size`, 1 MiB by default, 413 over it), and
      the state is in the body. The JSON of the body has a maximum depth of 8 (400 over it).
- [x] XSS with child component tags. phemc writes a prop as a Twig expression, never as data
      in the `{% component %}` tag, and a quoted literal is a single-quoted Twig string with no
      interpolation. Props, slots and keys are escaped in text and in attributes, and the key
      of a class component is a hash in the fiber id. Tests cover template and class components.

### [x] Rate limit for events — P3

Done: the `#[RateLimit]` attribute of Symfony 8.1 on a page class limits its events
(`#[RateLimit('phem_events', methods: 'POST')]`), as the other controller attributes do. Over the
limit, the server answers 429 and does not run the handler. The browser dispatches a
`phem:rate-limited` event on the component root, with `retryAfter`. See
[docs/phpm-syntax.md](docs/phpm-syntax.md#rate-limits).

- [x] A limit for one handler. The handler id is in the JSON body, not in the URL, so the
      attribute goes on the handler method. A handler middleware (RateLimitMiddleware) applies
      it, and the default key has the class and the method of the handler.
