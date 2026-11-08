# Changelog

The changes of the `phem/phem` package, its client (`assets/`) and the `phemc` compiler. The
editor extensions have their own changelogs (for example `editors/vscode/CHANGELOG.md`).

The project has no stable release yet: a minor version can break the public API. The entries
that break the code of an application start with **Breaking**.

## Unreleased

- phemc: `mount` or `render` passed as a handler (`onClick={{ mount }}`, `phem:loading={{ render }}`)
  in a class component or a page is the error E046. Phem calls these methods itself: they are
  not handlers. A PHP or Twig variable, a prop or a property of the same name is still a value.

## 0.3.11 (2026-11-08)

- Security: an event has the route name of its page (`_route`), not `phem_event.<name>`. An
  `access_control` rule on a route name (`route: ^admin_`) now applies to the events of the page
  and to a `POST` to its URL. Before, a client that the rule denied could send them.
- Security: `$e->form` takes linear time for many fields of the same name. Before, one small
  gzip event with many fields of one name used seconds of CPU.
- `data-phem-autofocus-keyboard` on a link or on the element of an event opens the keyboard of
  iOS for the field that `phem-autofocus` focuses after the visit or the morph. Before, the field
  got the focus, and no keyboard opened. It works in an open `<dialog>`, and gives the focus back
  to the element of the tap when no field gets the focus.

## 0.3.10 (2026-11-07)

- Error pages: `#[ErrorPage]` on a function of a `.phem` file renders the page of the HTTP
  errors (a 404, a 500), in the document of the application. A status (`#[ErrorPage(404)]`,
  `#[ErrorPage([401, 403])]`) and a path (`#[ErrorPage(path: '/admin')]`) choose the page: the
  longest path wins, then the status. The page gets `int $status`, the
  `FlattenException $exception` of Symfony (or the exception itself for `\Throwable $exception`)
  and the arguments of a function page. It has no state and no events, and it can be a plain
  HTML document with no `<Root>`. The status and the headers of the error stay. The preview at
  `/_error/{code}` shows it (`?path=/admin` for a section), and `error:dump` writes it to static
  files at the host of the router. The container build checks the attributes.
- The option `phem.show_exceptions` (the debug mode of the kernel by default) shows the page of
  the exception in place of the error pages. Set `false` in the e2e tests.
- `errorPage()` in the tests renders the error page of an error at a URL, with a session.
- `visit()` in the tests runs Symfony in the web mode, as `WebTestCase`: an HTTP error renders
  the error page of the application, not the output of the CLI error renderer.
- phemc reports E059 for `#[ErrorPage]` on a class, E060 for `#[ErrorPage]` in a package, and
  E053 for `#[Broadcast]` on an error page. An error page used as a tag is E056. The hover of
  the editors shows the statuses and the paths of an error page.

## 0.3.9 (2026-11-07)

- Fix: phemc did not pass a local to the template when only a tag (`{% if missing %}`) used it
  and its name was `missing`, `ignore`, `only`, `key` or `sandboxed`. The page failed at runtime
  with `Variable "missing" does not exist`, and `phemc check` gave no error. The words
  `ignore missing`, `with` and `only` are now keywords only after the expression in
  `{% include %}`, `{% embed %}` and `{% with %}`, as in Twig: `{% with a ~ only %}` reads `only`.
- Fix: phemc did not pass a local named `with`, `if`, `starts`, `ends`, `has`, `some` or `every`.
  These words are keywords only in the operators `starts with`, `ends with`, `has some` and
  `has every`: `{{ with }}` reads `$with`.
- Fix: in `{% if flags b-and 4 %}`, phemc read `b` as a variable and gave an error. It now reads
  `b-and`, `b-or` and `b-xor` as operators.

## 0.3.8 (2026-11-07)

- Fix: after an event, a kept component with slots showed old HTML when its owner rendered again
  and the slots read an object that the handler changed in place (an entity). The slots now
  belong to the component that gives them, as in React: a component with slots renders again each
  time that its owner renders. A component with props only is still kept when it gets the same
  objects. Before, only a function component with a body of one `return <markup>;` (a template
  component) rendered its slots again: a statement in the body, for example `$x = ...;`, made the
  page show old HTML.

## 0.3.7 (2026-11-05)

- Fix: in the tests, the browser kept a cookie with an empty `Domain` (for example the session
  cookie of PHP, with the default `session.cookie_domain`) on no host, so it did not send the
  cookie. As a browser, it now ignores an empty `Domain`: the cookie goes to the host of the page.

## 0.3.6 (2026-11-05)

- The `visit()` of the tests takes cookies for the browser (`cookies: ['currency' => 'EUR']`),
  on `InteractsWithPhem` and on a page. `cookies()` gives the cookies that the browser of a page
  sends to its URL, sorted by name.
- Fix: in the tests, `visit()` and `followRedirect()` of a page opened a relative URL on
  `localhost`. They now open it from the URL of the page, as a link does.

## 0.3.5 (2026-11-05)

- **Breaking** The file extension of the components is now `.phem` (before: `.phpm`). The open
  tag is now `<?phem` (before: `<?phpm`), and the Twig namespace of the compiled templates is now
  `@phemc` (before: `@phpm`). Rename your files, and change each `<?phpm` tag to `<?phem`: an
  old `<?phpm` tag is now an error (E006).

## 0.3.1 (2026-11-03)

- Phem needs PHP 8.4 or later (before: 8.5). A `.phpm` file can use the syntax of PHP 8.5 (the
  `|>` operator, a closure in an attribute such as the key of `#[RateLimit]`) only when the
  application runs on PHP 8.5.

## 0.3.0 (2026-11-03)

- No change in the code. The Flex recipe of `phem/phem` starts at this version
  (`phem/phem/0.3/` in symfony/recipes-contrib): it enables `PhemBundle`, adds the document of
  the pages, the first page at `/`, and the `phemc` worker of `symfony serve`.

## 0.2.7 (2026-11-03)

- Fix: the morph kept the files of a file input, so the next submit sent them again. A handler
  calls `$e->clearFiles()` when it takes the files, and the controller empties the file inputs
  that sent them. A handler that shows a validation error does not call it, so the user does not
  select the files again. A file input with files makes its form dirty (`phem-dirty`, and
  `data-phem-guard`).

## 0.2.6 (2026-11-03)

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
