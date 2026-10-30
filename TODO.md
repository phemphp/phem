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
- [ ] **Live validation** (section 2). The touched fields and the errors go in the signed
      state. Does validation run in the Doctrine transaction? How does a submit handler stop when
      the state has errors?
- [ ] **Component libraries** (section 4). Each P1 and P2 item has its decisions, with no open
      question. The packages compile with the app, in one build, the classes merge through a
      `ClassList` value, and the app replaces a package component with
      `#[Component(replaces: ...)]`.

## 1. Rendering and performance

### [ ] An event with no render of the path to its handler — P3

The first pass of an event renders the ancestors of the handler component, to get the handler
closure and the props of the component. This is a documented limit (docs/how-it-works.md, Limits).
A stress run on 2026-10-28 (the server time, with no network):

- A leaf with no `#[Prop]`: about 0.3 ms under 10 levels, 15 ms under 400 levels, 45 ms under 800.
  Real pages have fewer than 50 levels.
- A click on one row of a list: about 9 ms for 1000 rows and 46 ms for 5000 rows, with a full
  render of the page in 24 ms and 130 ms. About half of the event is the first pass: the
  `render()` of the list, and Twig for each row. The rest is the state (decode, sign, shape, the
  cache write).

- **Proposal (only when the benchmark shows a need):** a method handler with no captured values,
  of a component with no `#[Prop]`, no `#[Context]` and no slot, runs on the instance from the
  state, with no render of the path. It helps a deep leaf, not a row of a list: a row has props.
- **Open question for lists:** the JSON props of each fiber in the signed state, so a row renders
  with no render of its parent. The state gets larger, and a prop that is not a JSON value (a
  closure, markup, an entity) still needs the render of the path.

### [ ] Streams for long lists — P3

- **Best peer:** LV streams: insert, update and delete items in a list without the list in
  server memory or in the state.
- **Proposal:** a `Stream` value. A handler calls `$this->messages->insert($m)` or
  `->delete($m)`, and the response has a Turbo Stream `append` / `remove` for the item. The
  list is not part of `#[State]`. Good for chats, feeds and infinite scroll.

## 2. Forms and validation

### [ ] Validation of `#[State]` properties, with touched fields — P2

Live validation works today, by hand: `#[Assert]` on the `#[State]` properties, a `touched` and
an `errors` property, and a handler that calls the validator (see
[Live validation](docs/phpm-syntax.md#live-validation)). Each form repeats this code. It is not a
blocker, so build it only when a real form shows that the manual code is too much.

- **Best peer:** LC `ValidatableComponentTrait`: it validates on each change but shows the errors
  only for the fields that the user touched. LW `#[Validate]` on a property, with real-time
  validation. LV changesets with `used_input?`.
- **Proposal:**
  - Validate the `#[Assert\...]` constraints of the `#[State]` properties after a `phem:bind`
    change, with no handler.
  - Track the touched fields in the state. Show the errors only for them, and for all the fields
    after a submit.
  - Give the errors to `render()` (for example `errors.email`), and let a submit handler stop
    when there are errors.
- **Not in scope:** live validation of a Symfony form, as LC `ComponentWithFormTrait` does (it
  keeps the submitted values and calls `submit()` with `clearMissing: false` on each change).
  It does not fit Phem:
  - The fields of a Symfony form are not bound fields, so their values are not in the state.
  - The submitted values of all the form would go into the signed state.
  - Each change would submit all the form again.

  LC needs it because LC is built around Symfony forms. In Phem, use bound properties for the
  fields that need live validation, and a Symfony form for the submit.

## 3. Installation

### [ ] The Flex recipe in symfony/recipes-contrib — P1

The getting-started guide and the README show 3 commands: `symfony new my-app --webapp`,
`composer require phem/phem`, and `symfony serve`. This flow needs the recipe of `phem/phem` in
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib). Until the recipe is there,
Flex does not enable the bundle and does not add the first page or the `phemc` worker.

- **Plan:**
  - Release Phem 0.3.0. The recipe goes in `phem/phem/0.3/`. A 0.2 recipe would also apply to
    0.2.3, which has no automatic routes of the pages.
  - Copy `recipe/` to `phem/phem/0.3/` in symfony/recipes-contrib, and open the pull request.
  - After the merge, test the 3 commands in a new application, with no local Flex endpoint.

## 4. Component libraries

The goal: a Composer package of components for each theme (for example `acme/ui-tailwind` and
`acme/ui-shadcn`). Each component has a default style and a default behavior. The app installs
the package, and it customizes a component where it uses it. Rest props, `Closure` handler
props, `#[Context]`, the inheritance of class components, and the Symfony UX format of the
assets work today. The items below are missing.

### [x] Components in Composer packages — P1

Done: see [Component packages](docs/packages.md). The text below keeps the design decisions.

`phemc build`, `check` and `watch` compile one source directory. A tag of a component in
`vendor/` is E010. The runtime reads one manifest and one templates directory. The LSP
diagnostics also compile only the source directory of the app.

- **Best peer:** the Symfony UX bundles and LW Flux: `composer require` makes the components
  available, with no other step.
- **Decisions:**
  - **Discovery is automatic.** A package gives its `.phpm` root in its `composer.json`:
    `"extra": {"phem": {"source": "components"}}`. `phemc` reads
    `vendor/composer/installed.json` and takes each package with this key. The root is relative
    to the `install-path` of the package, so a path repository also works. `phemc` already runs
    from the project root (the warmup runs `bin/console`), so it needs no `--root` option. A component that the app does not
    import costs only build time.
  - **One build.** `phemc build`, `check` and `watch` compile the roots of the packages with
    the app, into the build directory of the app: one registry, one manifest and one templates
    directory. A package can use the components of another package (a theme extends a base
    package). Two packages with the same FQCN are E014, as today.
  - **From source.** `phemc` compiles the package from its source, not from a precompiled
    output. The output depends on the version of `phemc`, and `phemc` ships in `phem/phem`, which
    the package requires: Composer makes sure that the versions are compatible.
  - **The output paths.** The `rel` of a package file is `vendor/<package>/<path in the root>`,
    so its PHP file and its templates do not collide with the files of the app
    (`php/vendor/acme/ui/Button.php`, `@phpm/vendor/acme/ui/Button.0.twig`). The app files keep
    their `rel`.
  - **The source path.** Each template in the manifest keeps the path of its `.phpm` file from
    the project root (`src/Page/index.phpm`, `vendor/acme/ui/components/Button.phpm`).
    `SourceMap` uses `kernel.project_dir`. Remove the `phem.source_dir` option, the
    `Manifest::sourceDir()` method and the hardcoded `'src/'` in `SourceMap`.
  - **Diagnostics.** An error in a package file stops the build, so `phemc` shows it, with the
    path in `vendor/`. `phemc` hides the warnings of the package files: the app cannot fix them.
    The diagnostics of the app files about package components (E011, E012, E015) show as usual.
  - **Watch.** `watch` also watches `vendor/composer/installed.json` (a `composer require` or a
    `composer update` while it runs) and the package roots (a package in development, through a
    path repository).
  - **The LSP.** The diagnostics use the same roots. The LSP already watches
    `installed.json`, but its check compiles only the source directory of the app.
  - **Pages in a package.** A package root can have `#[Page]` pages, but their routes come only
    when the app imports them. The manifest keeps the package of each page. The `.` resource
    (and `routing.controllers`) loads only the pages of the app, and the name of a package loads
    its pages:

    ```yaml
    # config/routes/acme_admin.yaml
    acme_admin:
        resource: acme/admin
        type: phem
        prefix: /admin
    ```

  - **The document of a package page.** A package page cannot import the `Document` of the
    app. It renders in a layout component of the package, and the app replaces it (see the
    override of a package component, below).
  - **The tests of a package: the package is a package.** A package has no app, so its tests
    use a small test app with the fixture components (`tests/app/src`, for example a `<Tabs>`
    around the `<Tab>` items that `mount()` cannot give). `phemc` also takes the
    `extra.phem.source` of the root `composer.json` as a package root, with the `name` of the
    root package. So the tests compile the package as an app sees it: a `replaces` in the
    package is an error, and its pages need a route import. In an app, the root `composer.json`
    has no `extra.phem.source`, and nothing changes.
  - **`Phem\Test\TestKernel`.** `phem/phem` ships a base kernel, from the `TestKernel` of the
    Phem tests (`tests/Fixtures/TestKernel.php`): FrameworkBundle, TwigBundle,
    StimulusBundle and PhemBundle, `framework.test`, the `routing.controllers` routes, and the
    build of the test app with `phemc` once per test run (`PHEMC_BIN`, `vendor/bin/phemc`, or
    the `PATH`). The project dir is the root of the package, so `phemc` reads the
    `extra.phem.source` of its `composer.json`. The test app is in `tests/app/src`
    (`sourceDir()`), and the output in `var/phem` (`buildDir()`). A package adds its bundles,
    its config and its routes. The tests of Phem use it too. Peer: `nyholm/symfony-bundle-test`.

    ```php
    // tests/Kernel.php
    final class Kernel extends \Phem\Test\TestKernel
    {
    }
    ```

    With `KERNEL_CLASS` in `phpunit.xml.dist`, the tests use `InteractsWithPhem` as in an app.
    In the CI, `vendor/bin/phemc check tests/app/src` also checks the package.

### [x] Merge of classes — P1

Done: see [Merge of classes](docs/phpm-syntax.md#merge-of-classes). The text below keeps the
design decisions. Two details of the implementation: a plain `class` array also goes through
the merger (a plain string does not), and the quoted `className` of a component tag stays a
string, because the prop can have the type `string`.

A spread uses "last value wins", as in React: `className="btn" {{ ...rest }}` with a
`className` from the caller prints only the class of the caller. A class array ignores its keys,
so `{'active': isActive}` does not work. A package component cannot keep its default classes
and add the classes of the caller.

- **Best peer:** the `tailwind_classes` filter of `tales-from-a-dev/twig-tailwind-extra`: a
  mergeable class list that `html_attr()` merges with a later `class` value, in one merge when
  the attribute renders. Also React `cn()` (clsx and tailwind-merge), TwigComponent
  `attributes.defaults()` and Blade `$attributes->merge()`.
- **Decisions:**
  - **A mergeable value.** `classes(...)` gives a `Phem\ClassList`. The author marks the default
    classes with it, and the classes of the caller merge into them:

    ```php
    #[Component]
    function Button(mixed ...$rest): Node
    {
        return <button className={{ classes('px-4 py-2 bg-blue-500') }} {{ ...rest }} />;
    }

    <Button className="bg-red-500" />   // class="px-4 py-2 bg-red-500" with the Tailwind merger
    ```

  - **The rule.** When one of two values of `class` (or `className`) is a `ClassList`, the
    result is a `ClassList` with the earlier classes and then the later classes. The later
    class wins a conflict. So a `classes(...)` after the spread gives classes that the caller
    cannot replace. Two plain values keep "last value wins", as in React, and the other
    attributes do not change.
  - **The inputs, as clsx.** `classes()` takes strings (split on white space), lists, nested
    lists, maps of conditions (`{'active': isActive}` keeps `active` when the value is true),
    other `ClassList` values, and `null` or `false` (no class). A plain `class` array takes the
    same inputs, with no merge with other values.
  - **A late merge.** A `ClassList` keeps its classes, and it merges them only when the
    attribute renders. PHP code gets a `ClassList` with `Phem\classes(...)`, also in a function
    component with no services (for example the classes of a variant). Only the renderer needs
    the merger.
  - **The merger.** A `ClassMerger` interface merges the classes of a `ClassList`. The default
    implementation removes the duplicates. The Tailwind implementation uses
    `tales-from-a-dev/tailwind-merge-php` (a suggested dependency, Tailwind v4): `px-2` and
    then `px-4` gives `px-4`. It keeps its in-memory cache between the requests of a worker,
    and it can use a PSR-16 cache. The app selects it in the config, or it gives a service id:

    ```yaml
    # config/packages/phem.yaml
    phem:
        class_merger:
            tailwind: { prefix: tw }   # or: service: App\Ui\MyClassMerger
    ```

  - **Component tags use the same rule.** With a spread, phemc emits a runtime call for the
    props of a component tag (and of a template component), in place of the Twig hash spread
    `{..., ...(rest)}`. So a wrapper keeps its classes:
    `<Inner className={{ classes('x') }} {{ ...rest }} />`.
  - **No core feature to replace all the default classes.** The Tailwind merger removes the
    defaults that conflict with the classes of the caller. A package that needs it gives a prop,
    for example `unstyled`.
  - **`phem_class` for an element with no spread.** The `class` of `<div className={{ x }}>`
    can be a `ClassList` too, and it needs the merger. phemc emits `phem_class(x)` for a
    dynamic `class` or `className`, as it emits `phem_style(x)` for `style` today. A quoted value
    with an expression (`className="btn {{ extra }}"`) also goes through it, as a list
    (`phem_class(['btn', extra])`), because Twig cannot print a `ClassList` with no merger.
    One service, `ClassAttribute` (with the `ClassMerger`), renders a class value for
    `phem_class`, `phem_attrs` and `<Root>` (`RootElement`). `phem_attr` stays static for the
    other attributes. A `class` with no expression stays plain HTML, with no call.
- **Not in scope:** the merge of `style`. It keeps "last value wins": no package needs more
  yet, because a theme uses classes and the CSS variables of its CSS. When a package shows the
  need, add a mergeable `Phem\Style` (a merge by CSS property, as Twig `html_attr()` does).

### [ ] Polymorphic element — P2

A tag name is static. A package `Button` cannot render as an `<a>` for a link. Only `<Root>`
takes a `tag` prop.

- **Best peer:** Solid `<Dynamic>`, LV `dynamic_tag`, Svelte `<svelte:element this>`.
- **Decisions:**
  - **The built-in `<Dynamic>`** (`use Phem\Dynamic;`, as `<Root>`). Its `tag` prop is the name
    of the element, the same prop name as `<Root tag>`. `tag` is required (E011). The other
    props are the attributes and the handlers of the element, and the children are its content:

    ```php
    #[Component]
    function Button(string $tag = 'button', ?Html $children = null, mixed ...$rest): Node
    {
        return (
            <Dynamic tag={{ tag }} className={{ classes('px-4 py-2') }} {{ ...rest }}>
                {{ children }}
            </Dynamic>
        );
    }

    <Button tag="a" href="/docs">Docs</Button>   // <a class="px-4 py-2" href="/docs">Docs</a>
    ```

  - **An element, not a component.** phemc compiles `<Dynamic>` to an element in the template
    of its parent: `<{{ phem_tag(tag) }} {{ phem_attrs(...) }}>...</{{ phem_tag(tag) }}>`. It
    has no fiber. So when it is the root of a component, the `data-phem-c` id goes on it, as on
    a static element. A component with its own fiber would make the parent render "another
    component", and Phem would put a `<phem-c>` around it (not valid in a `<ul>` or a `<table>`).
  - **The attributes** go through `phem_attrs`, as an element with a spread: the handlers, the
    `ClassList` merge, `style` and `key` work. `phem:bind` is a compiler error on `<Dynamic>`,
    because its code depends on the tag name (`input`, `textarea` or `select`).
  - **Elements only.** The tag is an HTML element name, not a component, so phemc keeps its
    checks. A package that needs a component (for example a router link) uses a slot or a
    condition.
  - **The runtime checks the tag.** `phem_tag` refuses a name that is not `[a-z][a-z0-9-]*`
    (the check of `RootElement`), and the raw text elements `script` and `style`: the tag can
    come from data, and escaped children do not make a script safe. Write them as static markup.
    A void element (`img`, `input`, the list of `RootTag`) renders with no end tag, and with
    children it is an error.
- **Not in scope:** `asChild` (the props merged into the child element), because the child is
  rendered `Html`.

### [ ] Override of a package component in the app — P2

A tag resolves to a fixed FQCN at compile time. The app cannot replace a package `Button` that
a package `Dialog` renders.

- **Best peer:** the template override of a Symfony bundle (`templates/bundles/`), Laravel
  `resources/views/vendor/`, and the Symfony DI decorators (`#[AsDecorator]`: the decorator can
  use the inner service).
- **Decisions:**
  - **`#[Component(replaces: ...)]`** on a component of the app:

    ```php
    use Acme\Ui\Button as UiButton;

    #[Component(replaces: UiButton::class)]
    function Button(?Html $children = null, mixed ...$rest): Node
    {
        return (
            <UiButton className={{ classes('rounded-full') }} {{ ...rest }}>
                {{ children }}
            </UiButton>
        );
    }
    ```

  - **Each tag, at compile time.** phemc resolves each tag of the replaced FQCN to the
    replacement, in the app and in all the packages (`Dialog` renders the `Button` of the app).
    Inside the replacement, the tag stays the original, so the replacement can wrap it (as a
    Symfony decorator) or render something else.
  - **Also at runtime.** The manifest keeps the replacements, so
    `{% component 'Acme\\Ui\\Button' %}` in a Twig template of the app also renders the
    replacement.
  - **The app only.** Only a component in the source dir of the app can replace a component. A
    package that replaced a component of another package would change the app with no sign
    when the app installs it. A theme package extends the base classes instead (see the next
    item). A `replaces` in a package is an error.
  - **One replacement for each FQCN.** Two replacements of the same FQCN are an error (E017).
    The replacement of a replacement is also an error. A tag of the replacement itself is
    correct.
  - **The check (E016).** The replacement accepts each prop and each slot of the original, or it
    has a rest prop. It requires no prop and no slot that the original does not require. phemc
    does not compare the types: PHP checks them at runtime.
  - **The contexts.** A consumer finds its provider by type. A replacement of a provider
    (`#[Provide]` on the class) extends the original class or wraps the original, so the
    consumers of the package still find it. Else the consumers get E015, or the runtime error
    of a required context.
  - **The document of a package page.** A package page renders in a layout component of the
    package (for example `Acme\Admin\Layout`, with a default document). The app replaces it with
    a component that renders the `Document` of the app. This answers the open question of
    the first item.
  - **The tools.** The profiler shows the replaced component of each replacement. The LSP goes
    to the replacement from a tag of the original, and its hover shows both.

### [ ] Shared behavior between theme packages — P2

Two theme packages have the same behavior: the state, the handlers and the ARIA attributes.
Most of the inheritance works today: a class gets the props, the state and the methods of a
parent with no `#[Component]`, and the component checks (for example E040) run only on the
component classes. A context lookup uses `instanceof`. Two gaps: `phemc` ignores `abstract`,
so an abstract `#[Component]` class is a tag (and its instance fails at runtime), and
`#[Provide]` on a class does not inherit (PHP class attributes do not inherit).

- **Decisions:**
  - **The pattern.** A base package has abstract classes with no `#[Component]` and no
    `render()`: the props, the state, the handlers and `#[Provide]`. Each theme package extends
    them, adds `#[Component]` and gives `render()`. The consumers of a context declare the base
    type, so they work with each theme:

    ```php
    // acme/ui-base
    #[Provide]
    abstract class AbstractTabs
    {
        #[State] public string $active = '';
        #[Prop] public ?Html $children = null;

        public function select(Event $e): void
        {
            $this->active = $e->value;
        }
    }

    // acme/ui-tailwind
    #[Component]
    class Tabs extends AbstractTabs
    {
        public function render(): Node
        {
            return <div role="tablist" className={{ classes('flex gap-2') }}>{{ children }}</div>;
        }
    }

    #[Component]
    function Tab(string $name, #[Context] AbstractTabs $tabs, ?Html $children = null): Node
    {
        // ...
    }
    ```

  - **`#[Component]` on an abstract class is an error** (E045), with the help "remove
    `#[Component]`: the children declare it". The base stays a plain class, and a tag of it is
    E010, because it is not in the registry.
  - **`#[Provide]` on a class inherits.** The compiler (for E015) and the runtime
    (`ContextMetadata`) also look at the parents. Being a provider is behavior, as the props,
    the state and the methods that inherit already. `#[Component]` does not inherit, because
    the base is not a component.
  - **The theme switch: the same FQCNs.** The theme packages use the same namespace
    (`Acme\Ui\Button`), so the app imports do not change. Each theme package has a Composer
    `conflict` with the other themes, and it can `provide` a virtual package (`acme/ui-theme`)
    for the packages that need a theme. `composer require` of another theme switches it. This
    needs no core change: if two themes are installed, E014 reports the duplicate FQCNs.
  - **Not `final`.** The package classes that are made for extension are not `final`.
    The app can then extend a theme class (for example to override a handler), and replace the
    original with `#[Component(replaces: ...)]`.

### [ ] Backed enum props — P2

`PropBinder` gives a prop its value as it is. With a `Variant $variant` prop,
`<Button variant="outline">` throws a `TypeError`, and the caller must write
`variant={{ enum('Acme\\Ui\\Variant').Outline }}`. The state and the bound fields already
convert a string to a backed enum with `::from()` (`StateAccessor`).

- **Best peer:** Blazor (an enum parameter from a string attribute), LC (a prop hydrates to an
  enum).
- **Decisions:**
  - When the type of a prop (a parameter of a function, or a `#[Prop]` property) is a backed
    enum, a string or an int value converts with `::from()`. An enum value stays as it is. A
    nullable prop takes `null`.
  - A wrong value is a `PropError` with the cases of the enum:
    `<Button> has no variant "big". Its values are: primary, outline, ghost.`
  - phemc checks a literal value (`variant="big"`) against the cases when the enum is in the
    sources (the app or a package root). An enum in another place is checked only at runtime.
  - The same for a template component, in `PropBinder::templateContext()`.

### [ ] Variants of a component — P3

- **Best peer:** cva, Symfony UX Toolkit `html_cva`.
- **Decisions:** no core feature. Document the pattern, on top of the backed enum props and
  `classes()`:

  ```php
  enum Variant: string
  {
      case Primary = 'primary';
      case Outline = 'outline';

      public function classes(): string
      {
          return match ($this) {
              self::Primary => 'bg-blue-500 text-white',
              self::Outline => 'border border-blue-500 text-blue-500',
          };
      }
  }

  #[Component]
  function Button(Variant $variant = Variant::Primary, ?Html $children = null, mixed ...$rest): Node
  {
      return (
          <button className={{ classes('px-4 py-2', variant.classes()) }} {{ ...rest }}>
              {{ children }}
          </button>
      );
  }

  <Button variant="outline" className="w-full">Save</Button>
  ```

  - The classes are full names in the source (`'bg-blue-500'`, not `'bg-'.$color`), so Tailwind
    finds them.
  - Look at a `cva()` helper again when the packages show repeated code (the compound
    variants, for example).

### [ ] Controlled and uncontrolled state — P3

A package `Tabs` keeps the active tab in its `#[State]`. Some apps give only the first tab, and
other apps keep the active tab in their own state.

- **Best peer:** React `value` and `defaultValue`.
- **Decisions:** no core change. It works today: after an event, Phem renders again each fiber
  whose state changed (`EventRenderer::dirty()`), and the parent is on the path to the handler.
  So a handler of the child can call the `onChange` closure of the parent, and the parent
  renders again with its new state. Document the pattern:
  - `#[Prop] ?string $value`, with an `onChange` `Closure` prop: the parent keeps the value
    (controlled). The handler of `Tabs` calls `($this->onChange)($name)`.
  - `#[Prop] ?string $defaultValue`, copied to the `#[State]` in `mount()`: the component keeps
    the value (uncontrolled).
  - `render()` uses `value ?? active`, and the handler changes `active` only when `value` is
    `null`.

### [ ] The CSS of a package — P3

- **Decisions:** no core change. Document:
  - **The classes.** Tailwind v4 does not scan the files in `.gitignore`, and `vendor/` is in
    it. The input CSS of the app adds the root of each package:
    `@source "../../vendor/acme/ui/components";`. The compiled templates in `var/phem` are not
    necessary: the `.phpm` sources have the same classes.
  - **The theme tokens.** A shadcn theme gives its CSS variables and its `@theme` block in a
    CSS file of the package. The input CSS of the app imports it
    (`@import "../../vendor/acme/ui/assets/theme.css";`), so the Tailwind build reads it. The
    Symfony UX autoimport (the format of `@phem/phem`) does not send a file through the Tailwind
    build: use it only for plain CSS.
  - **The prefix.** A Tailwind `prefix(tw)` needs the same `prefix` in
    `phem.class_merger.tailwind`.
  - **The Stimulus controllers** of a package use the Symfony UX format, as `@phem/phem`: Flex
    adds them to `assets/controllers.json`.
