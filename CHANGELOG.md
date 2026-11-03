# Changelog

The changes of the `phem/phem` package, its client (`assets/`) and the `phemc` compiler. The
editor extensions have their own changelogs (for example `editors/vscode/CHANGELOG.md`).

The project has no stable release yet: a minor version can break the public API. The entries
that break the code of an application start with **Breaking**.

## Unreleased

- Fix: the Tailwind merger removed the custom values of the theme. For example, with
  `--text-caption` in `@theme`, a class list with `text-caption` and then `text-ink-muted` gave
  `text-ink-muted`: tailwind-merge put the size with the text colors. The new
  `phem.class_merger.tailwind.css` option reads the custom names of the `@theme` blocks of the
  Tailwind entry CSS, and the new `phem.class_merger.tailwind.theme` option gives them in the
  configuration (`theme: { text: [caption, price-lg] }`).
- Live validation of a Symfony form. A handler of `onChange` on the `<form>` calls
  `handleRequest()`, and the view of the form shows the errors of the fields that the user
  touched only. The form keeps all its errors, so `isValid()` checks all the fields. The server
  marks the touched fields with `data-phem-touched`, and a submit touches all of them.
  `TouchedFields::touchAll($request)` does the same for a handler of another event (a save with
  a click). Phem decorates the request handler of the forms when `symfony/form` is installed.
- **Breaking:** `onChange` on a `<form>` gets each key of its text fields, as in React. Before, it
  got the native `change` event only (when the user left the field). Add
  `phem:debounce={{ 'blur' }}` to keep the old behavior. A `phem:live` field still sends its own
  events.
- **Breaking:** a submit, a change or a click of an element in a form sends the fields of the
  form (`$e->form` and the POST data of the `Request`), also for a control with the `form`
  attribute. The fields go with their values when the event leaves. Only the submit sends the
  files: before, a change of a form sent them too.
- The morph keeps the values of the form fields with no `phem:bind` that the user changes while
  an event runs. For a field that the server marks as touched, it also keeps the value of the
  focused text field and of a password field.
- Fix: a change that `phem:before-queue` cancels (for example with `phem-confirm`) gives the old
  value back to the field that changed, also when the handler is on its form.
- `phem:debounce={{ 'blur' }}` on a `<form>` sends the event when the user leaves one of its
  fields. Before, the event waited for another event.
- Fix: a morph after an event or a push no longer replaces a bound field that the user edits.
  Before, when a component before the field went away (for example the `<FieldError>` of a
  field that became valid), the next component of the same class got its fiber id. The morph then
  matched the parent of the field with another element, and built the field again: the typed
  value and the focus were lost. While a morph runs, each bound field now has an id from its
  `phem:bind` key (and from its value, for a checkbox or a radio button), so the morph matches the
  field and its parents.

## 0.2.5 (2026-11-02)

- Live validation of the `#[State]` properties. A `#[State]` property of the type
  `Phem\Validation` turns it on for a class component. The bound values of an event touch their
  fields, and the errors of the touched fields go into the `Validation`.
- `#[Validated]` on a handler method or a closure touches all the fields and validates before
  the transaction. When there are errors, the handler does not run. It takes validation groups.

## 0.2.4 (2026-11-02)

- Install Phem in 3 commands: `symfony new --webapp`, `composer require phem/phem` and
  `symfony serve`. The page routes go into the `routing.controllers` resource (Symfony 7.4+),
  so an application needs no routes file. The `type: phem` import still works.
- `config/preload.php` needs no change: the container preload file includes
  `phem-preload.php` from the build directory. `symfony/stimulus-bundle` is now required.
- **Breaking:** `phemc` does not write a `preload.php` file any more.
- Compile the `.phpm` components of the Composer packages with the application, and show them
  in the editor.
- `#[Component(replaces: ...)]`: a component of the application replaces a component of a
  package in all the tags of the project. `phemc` reports E016 for a replacement that does not
  keep the props and the slots of the original, and E017 for a replacement that it cannot use.
- Theme packages: a base package has abstract classes (the props, the state, the handlers and
  `#[Provide]`), and each theme extends them. `#[Provide]` on a parent class applies to its
  children. `#[Component]` or `#[Page]` on an abstract class is E045.
- `<Dynamic>` renders an element with a tag name from a value. It refuses the unsafe elements.
- Merge the classes of a component with the classes of its caller: `classes()` gives a
  `Phem\ClassList`. Set `phem.class_merger.tailwind` to remove the conflicting Tailwind classes.
- Convert a string or an int prop to a case of its backed enum.
- Publish to a standalone Mercure hub (for example with PHP-FPM): set
  `phem.mercure.publisher_key` and `phem.mercure.publish_url`. A page with `#[Broadcast]` and no
  hub logs a warning.
- The client stops after the hub refuses 3 connections in a row, with a warning in the console.
  Before, a page with no hub sent a refresh every 5 seconds.

## 0.2.3 (2026-10-28)

- New Stimulus controllers: `phem-optimistic` (optimistic changes, matched to their events by
  DOM event id), `phem-toggle`, `phem-dialog`, `phem-tabs`, `phem-toast`, `phem-clipboard`,
  `phem-autofocus` and `phem-confirm`.
- Stream the `<Suspense>` boundaries in the response of the page.
- The fiber id goes on the root element of a component. A component that renders a text, more
  than one element or another component still gets a `<phem-c>`.
- Send one placeholder for a run of kept components, and a patch of the render shape.
- Send a patch of each state entry that changed only a bit.
- Check that the browser can send the state of a page back in an event. In debug mode, a state
  larger than `phem.max_event_size` throws. In production, the server logs an error.
- **Breaking:** `phemc` writes its output to `var/phem`, not to `var/cache/phem`, so
  `rm -rf var/cache` keeps it.
- Fix: the markup values render one time in each render pass. Before, a consumer in a slot of
  a component that rendered alone could keep the old value of its context.
- Performance: the fibers of a request are freed with no run of the cycle collector, and the
  loaded templates of the markup literals stay for the request.

## 0.2.2 (2026-10-26)

- After a handler of a component with `#[Broadcast]`, the other browsers render the component
  again. `#[Broadcast]` on some handler methods limits this to these handlers.
- `#[BroadcastKey]` marks the props that identify an instance: the refresh goes only to the
  instances with the same values. `phemc` reports E057 for a `#[BroadcastKey]` that is not on a
  prop of a component that broadcasts.
- **Breaking:** `Streams::toComponent()` takes the values of the key props, and `refresh()`
  takes no target.
- The browser that sent the event does not render the component of the handler again.

## 0.2.1 (2026-10-25)

- **Breaking:** rename the `#[Subscribe]` attribute to `#[Broadcast]`.
- Renew the hub topic of a broadcast component only when it renders in the event. A viewer who
  loses access to a component stops to get its pushes when the topic expires.

## 0.2.0 (2026-10-24)

- `#[Subscribe]` can mark a component.
- **Breaking:** `Streams::to()` is now two methods: `toPage()` and `toComponent()`.

## 0.1.3 (2026-10-24)

- `#[Subscribe]` can mark a function page. `Streams::to()` takes the first-class callable of a
  function page (`Guestbook(...)`).
- The language server finds the references of a component, a function or a method, and shows
  a usage count above each one.

## 0.1.2 (2026-10-24)

- The `<Suspense>` boundaries that start to load together send one load event. After a failed
  load, each boundary loads alone.
- A state smaller than `phem.state_cache_min_size` (4096 bytes) goes with each event and is not
  cached.

## 0.1.1 (2026-10-24)

- No change in the package. The repository has no quickstart app any more, and the dev Docker
  image moved to `docker/`.

## 0.1.0 (2026-10-24)

The first release.

- `.phpm` components: class and function components with JSX-like markup and Twig, compiled by
  `phemc` to PHP and Twig. Props (`#[Prop]`, rest props), slots, `#[State]` and `Phem\Node`.
- Pages: `#[Page]` on a class or a function, the route values as `#[Path]` props (also
  entities), and `#[Url]` properties in the query string.
- Event handlers with the arguments of a Symfony controller, `RedirectTo` and a
  `RedirectResponse`. The client morphs the page with Turbo Streams.
- Form fields bound to `#[State]` properties with `phem:bind` and `phem:live`, also in an array
  path and from a child component. File uploads, with `phem:progress`.
- Loading states (`phem:loading`, `aria-busy`), form locks, `phem:debounce`, `phem:throttle`,
  retries, an offline class and view transitions.
- Render only the components that can change after an event. The signed state goes into a
  cache, and an event sends only its signature.
- `<Suspense>`, `<ErrorBoundary>`, contexts (`#[Provide]` and `#[Context]`) and Stimulus client
  code, with list items morphed by `key`.
- Real-time pushes with `#[Subscribe]` and the Mercure hub of FrankenPHP.
- `#[Transactional]`, `#[RateLimit]` and `#[Async]` handlers, and server-to-browser events.
- Test helpers for components and pages, and a Phem panel in the Symfony profiler.
- The `phemc lsp` language server, used by the VS Code and PhpStorm plugins and by the Claude
  Code plugin.
