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
- [ ] **Context** (section 4). React uses context for UI values and Phem uses services for the
      rest. Where is the line? Can `phemc` check that a provider is above each consumer, also
      across `.phpm` files?
- [ ] **Real-time push** (section 4). The server cannot re-render a page alone. Is a Mercure
      signal + a normal event from the browser enough? How do many signals in a short time
      coalesce? Who can subscribe to a topic (private topics, JWT)?
- [ ] **Live validation** (section 5). The touched fields go in the signed state. How does
      this work with a Symfony form, and with `#[Assert]` on `#[State]` properties? Does
      validation run in the Doctrine transaction?
- [ ] **Colocated client code** (section 6). Is the extracted script a Stimulus controller (as
      the controllers of section 2), or a smaller hook API? How does `phemc` give it to
      AssetMapper in development (`phemc watch`) and in production?
- [ ] **`<ErrorBoundary>`** (section 7). An exception in a render and an exception in a
      handler are different: the handler transaction rolls back, and the state may not change.
      Which state does the response send? Is the boundary only for rendering, as in React?
      `Renderer::catching()` of `<Suspense>` already drops the fibers of a render that throws.

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

### [ ] Context (provide and inject) — P2

Props pass data one level at a time. Deep trees need "prop drilling".

- **Best peer:** React `createContext` + `useContext`. LC 3.1 `provide()` / `inject()` for Twig
  components.
- **Proposal:** services already replace module imports. For UI values (the theme, the current
  form, the locale of a section), add a provider component and an `#[Inject]` (or
  `#[Context]`) property. The compiler checks that a provider is above the consumer.

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
  - The controller gets the server events of the item "Server-to-browser events".
  - Done: `data-phem-ignore` (as LV `phx-update="ignore"` and LW `wire:ignore`), see section 2.

## 7. Errors and resilience

### [ ] Error boundaries — P2

An exception in one component breaks the full event.

- **Best peer:** React error boundaries. LV crash recovery (the process restarts and the client
  recovers the form values).
- **Proposal:** a built-in `<ErrorBoundary>` with a `fallback` slot. An exception in a child
  render or handler renders the fallback for that subtree only. In debug mode, show the
  Symfony exception page in an overlay (as LW does in development).

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
