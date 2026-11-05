# Component packages

A Composer package can ship `.phpm` components, for example a UI kit with a theme. The app
installs the package, and its pages use the components as their own:

```sh
composer require acme/ui
```

```php
use function Acme\Ui\Badge;

#[Page('/')]
function HomePage(): Node
{
    return <Document><Badge label="New" /></Document>;
}
```

## Make a package

Give the directory of the `.phpm` files in the `composer.json` of the package:

```json
{
    "name": "acme/ui",
    "require": {
        "phem/phem": "^0.3"
    },
    "extra": {
        "phem": {
            "source": "components"
        }
    }
}
```

```
acme-ui/
├── composer.json
├── components/            the .phpm files of the package (extra.phem.source)
│   ├── Badge.phpm
│   └── GalleryPage.phpm
├── src/                   the PHP classes of the package (enums, services), with PSR-4
└── tests/
    ├── Kernel.php         the test kernel (see "Test a package")
    └── app/src/           the test app: pages and components that use the package
```

The `.phpm` files have no PSR-4 autoload: `phemc` compiles them. The other PHP classes of the
package (an enum of variants, a service) use the normal Composer autoload.

The components of a package mark their default classes with `classes()`, so the app can add
classes where it uses a component: `<Button className="w-full" />` keeps the classes of
`Button`. See [Merge of classes](phpm-syntax.md#merge-of-classes).

A component that can render as another element (a `Button` as a link) takes its tag in a prop,
and renders `<Dynamic tag={{ tag }}>`. See [Dynamic element](phpm-syntax.md#dynamic-element).

The app can replace a component of a package in all its tags, also in the other components of
the package. See [Replace a component of a package](#replace-a-component-of-a-package).

For the variants, the controlled state and the CSS of a package, see
[Variants of a component](#variants-of-a-component),
[Controlled and uncontrolled state](#controlled-and-uncontrolled-state) and
[The CSS of a package](#the-css-of-a-package).

## How the app compiles a package

`phemc build`, `check` and `watch` read `installed.json` in the Composer dir of the vendor dir
(`vendor/composer/`, or the `config.vendor-dir` of `composer.json`, or `COMPOSER_VENDOR_DIR`).
They compile the `.phpm` files of each package with `extra.phem.source` together with the app,
in one build:

- One manifest has the components of the app and of all the packages. A package can use the
  components of another package (a theme can extend a base package).
- The output of a package is in `var/phem/php/vendor/<package>/` and
  `var/phem/templates/vendor/<package>/`. An app file with the same output path (in
  `src/vendor/<package>/`) is an error: rename or move one of the two files.
- A root does not read the directory of another root (a test app in the root `.` of a package),
  a Composer vendor dir, `node_modules` or `.git`.
- `phemc` compiles a package from its source. The package requires `phem/phem`, and `phemc`
  ships in `phem/phem`, so Composer installs a compatible version.
- An error in a package stops the build, and `phemc` shows it with its path in `vendor/`. The
  warnings of a package do not show: the app cannot fix them. The diagnostics of the app about
  the components of a package (an unknown prop, a missing slot) show as usual.
- Two packages that declare the same component are E014. The message gives the path of the
  other file.
- The language server checks the packages with the app too, and it gives the completion, the
  definition and the hover of their components (see
  [The language server](compiling.md#the-language-server)).
- `phemc watch` also watches `vendor/composer/installed.json`: after `composer require` or
  `composer update`, it builds again with the new packages. It also watches the directory of
  each package, for a package in development (a Composer path repository). When a package
  root is wrong, it shows the error, and it keeps the watch of the app and of `installed.json`:
  the fix of the package builds again.
- An error of a template of a package shows the `.phpm` file of the package in `vendor/`.

## Pages in a package

A package can have `#[Page]` pages, but they are not routes of the app until the app imports
them. The `routing.controllers` resource (and the `phem` resource `.`) has only the pages of
the app. Import the pages of a package with its name, for example with a prefix:

```yaml
# config/routes/acme_ui.yaml
acme_ui:
    resource: acme/ui
    type: phem
    prefix: /ui
```

The routes of an installed package come with the build: after `composer require`, the import
has no routes until the next `phemc build` (the routes of the app are built again then). The name
of a package that is not installed is an error, with the list of the packages that have pages.

A page of a package cannot import the `Document` of the app. Give it a layout component of the
package, with a default document and a `<Root>`:

```php
#[Component]
function Layout(?Html $children = null): Node
{
    return (
        <html lang="en">
            <head><title>Acme UI</title></head>
            <body><Root>{{ children }}</Root></body>
        </html>
    );
}

#[Page('/gallery')]
function GalleryPage(): Node
{
    return <Layout><Badge label="New" /></Layout>;
}
```

The app replaces the layout with a component that renders its own `Document`, so the pages of
the package get the document of the app:

```php
use Acme\Ui\Layout;

#[Component(replaces: Layout::class)]
function PackageLayout(?Html $children = null): Node
{
    return <Document>{{ children }}</Document>;
}
```

## Replace a component of a package

Put `#[Component(replaces: ...)]` on a component of the app. Each tag of the original renders
the replacement: in the app, and in the components and the pages of all the packages. Inside
the replacement, a tag of the original stays the original, so the replacement can wrap it:

```php
// vendor/acme/ui/components/Alert.phpm
namespace Acme\Ui;

#[Component]
function Alert(string $type = 'info', ?Html $children = null): Node
{
    return <div className="alert alert-{{ type }}" role="alert">{{ children }}</div>;
}

// The Notice of the package renders the replacement of the app.
#[Component]
function Notice(?Html $children = null): Node
{
    return <section className="notice"><Alert type="warning">{{ children }}</Alert></section>;
}
```

```php
// src/Ui/Common/Alert.phpm
namespace App\Ui\Common;

use Acme\Ui\Alert as UiAlert;

#[Component(replaces: UiAlert::class)]
function Alert(string $type = 'info', ?Html $children = null): Node
{
    $label = 'warning' === $type ? 'Careful' : 'Note';

    return <UiAlert type={{ type }}><strong>{{ label }}</strong> {{ children }}</UiAlert>;
}
```

```php
// src/Ui/ReplacedPage.phpm
namespace App\Ui;

use Acme\Ui\Alert;
use function Acme\Ui\Notice;

#[Page('/replaced')]
function ReplacedPage(): Node
{
    return (
        <Document>
            <Alert>Saved</Alert>
            <Notice>Read this</Notice>
        </Document>
    );
}
```

The result:

```html
<div class="alert alert-info" role="alert" data-phem-c="phemf06a118edb20"><strong>Note</strong> Saved</div>
<section class="notice"><div class="alert alert-warning" role="alert" data-phem-c="phem57a1d908ae81"><strong>Careful</strong> Read this</div></section>
```

`phemc` changes the tags when it compiles. The template of `Notice` renders the replacement,
and the template of the replacement renders the original:

```twig
{# vendor/acme/ui/Alert.1.twig (Notice) #}
<section class="notice">{% component embed 'App\\Ui\\Common\\Alert' with {'type': 'warning'} only %}...{% endcomponent %}</section>

{# Ui/Common/Alert.0.twig (the replacement) #}
{% component embed 'Acme\\Ui\\Alert' with {'type': (type)} only %}...{% endcomponent %}
```

The value of `replaces` is `Name::class` or a string with the class name (`self::class` is
E017). A function component also uses `Name::class`, with a `use` import (not `use function`):
PHP does not check that the class exists.

The rules:

- Only the app replaces a component. A `replaces` in a package is an error (E017): a theme
  package extends the classes of a base package.
- One replacement for each component (E017). The replacement of a replacement, of a page or of
  a built-in component (`<Root>`, `<Suspense>`) is also E017, and so is a page that replaces a
  component: a page is not a tag. A component cannot replace itself. A tag of the replacement
  itself is correct. `replaces: null` is no replacement.
- The replacement accepts each prop and each slot of the original, or it has a rest prop
  (`mixed ...$rest`). It requires no prop and no slot that the original does not require. A
  `Bound` prop of the original stays a `Bound` prop: `phem:bind` on a tag of the original gives
  it a `Bound`. Else `phemc` reports E016. It does not compare the other types: PHP checks them.
- In a class replacement, a tag of the original stays the original in all its methods, also in
  the methods of its parent classes and its traits in the sources (up to the original).
- The tags of the original keep the checks of the original: an unknown prop is E011, also when
  the replacement has a rest prop.
- A consumer of a context finds its provider by type. A replacement of a provider extends the
  class of the original or wraps the original, so the consumers of the package still find it.
- A replacement gets the streams of the original with `#[Broadcast]`:
  `$streams->toComponent(Counter::class)` also refreshes the replacements of `Counter`, with the
  `#[BroadcastKey]` props of `Counter`.
- `{% component 'Acme\\Ui\\Alert' %}` in a plain Twig template of the app also renders the
  replacement: the manifest keeps the replacements. A plain Twig template is not inside the
  replacement, so it cannot render the original: wrap the original in the `.phpm` markup of the
  replacement.
- The profiler shows the component that each replacement replaces. In the editor, the
  definition of a tag of the original goes to the replacement, and the hover shows both. A prop
  or a slot of the tag goes to the prop or the slot of the replacement, or to the original when
  the replacement takes it in its rest prop. The editor uses the rules of `phemc`: with no valid
  replacement, the tag goes to the original.
- A required context of the replacement with no provider above a tag of the original is E015,
  at the tag: the message names the replacement.

## Share the behavior between themes

Two theme packages (for example `acme/ui-tailwind` and `acme/ui-shadcn`) can share the props,
the state, the handlers and `#[Provide]` of their components. A base package has abstract
classes with no `#[Component]` and no `render()`. Each theme extends them, adds `#[Component]`
and gives `render()`:

```php
// vendor/acme/ui-base/components/AbstractTabs.phpm
namespace Acme\UiBase;

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
```

```php
// vendor/acme/ui-tailwind/components/Tabs.phpm
namespace Acme\Ui;

use Acme\UiBase\AbstractTabs;

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
    return (
        <button role="tab" aria-selected={{ tabs.active == name }} onClick={{ tabs.select }} value={{ name }}>
            {{ children }}
        </button>
    );
}
```

The rules:

- `#[Provide]` on a parent class applies to its children. PHP does not inherit the attributes
  of a class, but Phem looks at the parents, in `phemc` (E015) and at runtime. `#[Component]`
  does not inherit: the base is not a component.
- The consumers declare the base type (`#[Context] AbstractTabs $tabs`). The lookup takes the
  nearest provider whose value is an instance of the type, so they work with each theme.
- `#[Component]` on an abstract class is E045. The base stays a plain class, and a tag of it is
  E010.
- Do not make the classes for extension `final`. The app can then extend a theme class (for
  example to change a handler), and replace the original with `#[Component(replaces: ...)]`.

To switch the theme, give the components of each theme the same names (`Acme\Ui\Tabs`), so
the imports of the app do not change. Each theme has a Composer `conflict` with the other
themes, and it can `provide` a virtual package for the packages that need a theme:

```json
{
    "name": "acme/ui-tailwind",
    "require": { "acme/ui-base": "^1.0" },
    "conflict": { "acme/ui-shadcn": "*" },
    "provide": { "acme/ui-theme": "1.0" },
    "extra": { "phem": { "source": "components" } }
}
```

`composer require acme/ui-shadcn` then removes `acme/ui-tailwind`. When two themes are
installed, `phemc` reports the duplicate components (E014).

## Variants of a component

A variant (`primary`, `outline`) needs no special feature. Use a backed enum prop (see
[Enum props](phpm-syntax.md#enum-props)), and give each case its classes in a method of the
enum. `classes()` merges them with the base classes and with the classes of the caller:

```php
// vendor/acme/ui/components/Button.phpm
namespace Acme\Ui;

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
function Button(string $label, Variant $variant = Variant::Primary, mixed ...$rest): Node
{
    return <button className={{ classes('px-4 py-2', variant.classes()) }} {{ ...rest }}>{{ label }}</button>;
}
```

```php
<Button label="Save" variant="outline" className="w-full" />
```

The result:

```html
<button class="px-4 py-2 border border-blue-500 text-blue-500 w-full">Save</button>
```

- Write the full class names in the source (`'bg-blue-500'`, not `'bg-'.$color`). Tailwind
  reads the source files, and it does not find a class that the code makes at runtime.
- `phemc` checks a literal value of the prop: `variant="big"` is E018.
- With the Tailwind merger, a class of the caller replaces a class of the variant in the same
  group: `<Button variant="outline" className="border-red-500" />` gives
  `px-4 py-2 border text-blue-500 border-red-500`.

## Controlled and uncontrolled state

A component of a package often keeps a value in its `#[State]`, for example the active tab of
`Tabs`. Some apps give only the first value. Other apps keep the value in their own state. As
in React, give the component two props:

- `defaultValue`: the component copies it to its `#[State]` in `mount()`, and it keeps the
  value (uncontrolled).
- `value` with an `onChange` closure: the parent keeps the value (controlled). The handler of
  the component calls `onChange`, and it does not change its state.

```php
// vendor/acme/ui/components/Tabs.phpm
namespace Acme\Ui;

#[Component]
class Tabs
{
    /** @var list<string> */
    #[Prop] public array $names = [];
    #[Prop] public ?string $value = null;
    #[Prop] public ?string $defaultValue = null;
    #[Prop] public ?Closure $onChange = null;
    #[State] public string $active = '';

    public function mount(): void
    {
        $this->active = $this->defaultValue ?? $this->names[0] ?? '';
    }

    public function select(Event $e): void
    {
        if (null === $this->value) {
            $this->active = $e->value;
        }

        if (null !== $this->onChange) {
            ($this->onChange)($e->value);
        }
    }

    public function render(): Node
    {
        $current = $this->value ?? $this->active;

        return (
            <div role="tablist">
                {% for name in names %}
                    <button role="tab" aria-selected={{ name == current }} onClick={{ select }} value={{ name }}>{{ name }}</button>
                {% endfor %}
            </div>
        );
    }
}
```

```php
#[Page('/settings')]
final class SettingsPage
{
    #[State] public string $tab = 'general';

    public function show(string $name): void
    {
        $this->tab = $name;
    }

    public function render(): Node
    {
        return (
            <Document>
                <Tabs names={{ ['general', 'security'] }} defaultValue="security" />
                <Tabs names={{ ['general', 'security'] }} value={{ tab }} onChange={{ show }} />
                <p>{{ tab }}</p>
            </Document>
        );
    }
}
```

A click on "security" in the second `Tabs` runs `select()`, which calls `show()`. The page
renders again with its new state, and the second `Tabs` gets the new `value` (the result
has no `data-phem-*` attributes here):

```html
<div role="tablist">
    <button role="tab" aria-selected="false" value="general">general</button>
    <button role="tab" aria-selected="true" value="security">security</button>
</div>
<p>security</p>
```

- This needs no core feature. After an event, each component whose state changed renders
  again, and the parent is on the path to the handler. So the parent renders again when
  `onChange` changes its state.
- `render()` shows `value ?? active`. The handler changes `active` only when `value` is `null`.
- A controlled component with no `onChange` does not change: as in React, the value is read-only.

## The class merger

`classes()` merges the classes of a component with the classes of the caller (see
[Merge of classes](phpm-syntax.md#merge-of-classes)). The default merger removes only the
duplicates. The Tailwind merger (Tailwind CSS v4) also removes the earlier classes of the same
group: `px-2` and then `px-4` gives `px-4`. Install it, and turn it on:

```sh
composer require tales-from-a-dev/tailwind-merge-php
```

```yaml
# config/packages/phem.yaml
phem:
    class_merger:
        tailwind:
            css: '%kernel.project_dir%/assets/styles/app.css'
            # prefix: tw
            # cache: app.psr16_cache
            # theme: { text: [caption, price-lg] }
        # Or the service of a Phem\Dom\ClassMerger of the app:
        # service: App\Ui\MyClassMerger
```

The merger knows only the default values of the Tailwind theme. A custom value of your `@theme`
is not in its group: for example, it puts `text-caption` with the text colors, so a later color
removes the size. The `css` option fixes this. Phem reads the `@theme` blocks of the file and of
its relative imports when the container builds. The `theme` option gives the names by hand, by
theme key. In production, clear the cache after a change of the theme.

Give the `prefix` of your Tailwind build (`@import "tailwindcss" prefix(tw);`) with no colon.
`cache` is the id of a PSR-16 cache service for the merged classes.

## The CSS of a package

Phem has no CSS feature for the packages. Use the Tailwind build of the app, and the Symfony UX
format for the Stimulus controllers.

**The classes.** Tailwind CSS v4 does not read the files in `.gitignore`, and `vendor/` is in
it. Add the `.phpm` root of each package to the input CSS of the app. The `.phpm` sources have
the classes, so Tailwind does not need the compiled templates in `var/phem`:

```css
/* assets/styles/app.css */
@import "tailwindcss";

@source "../../vendor/acme/ui/components";
```

**The theme tokens.** A theme (for example shadcn) gives its CSS variables and its `@theme`
block in a CSS file of the package. The input CSS of the app imports it, so the Tailwind build
reads it:

```css
/* assets/styles/app.css */
@import "tailwindcss";
@import "../../vendor/acme/ui/assets/theme.css";

@source "../../vendor/acme/ui/components";
```

The Symfony UX `autoimport` of a CSS file (as `@phem/phem` does for `phem.css`) does not send
the file through the Tailwind build. Use it only for plain CSS.

With the Tailwind merger, give the input CSS of the app to the `css` option (see
[The class merger](#the-class-merger)). Phem reads the `@theme` blocks of the file and of its
relative imports, so it also reads the theme of the package.

**The Stimulus controllers.** A package gives its controllers in the Symfony UX format, as
`phem/phem`: the `symfony-ux` keyword in its `composer.json`, and an `assets/package.json` with
the `symfony.controllers` key. Flex then adds them to `assets/controllers.json`:

```json
{
    "name": "@acme/ui",
    "symfony": {
        "controllers": {
            "combobox": {
                "main": "dist/combobox_controller.js",
                "fetch": "lazy",
                "enabled": true
            }
        }
    }
}
```

## Test a package

A package has no app. Its tests use a small test app in `tests/app/src`, with the pages and the
components that the tests need (for example a `<Tabs>` around the `<Tab>` items).
`Phem\Test\TestKernel` is a kernel for it:

```php
// tests/Kernel.php
namespace Acme\Ui\Tests;

final class Kernel extends \Phem\Test\TestKernel
{
}
```

```xml
<!-- phpunit.xml.dist -->
<php>
    <server name="KERNEL_CLASS" value="Acme\Ui\Tests\Kernel"/>
</php>
```

- The project dir of the kernel is the root of the package (the directory of its
  `composer.json`).
- When the kernel boots, it compiles the test app with `phemc`, one time in each test run. It
  finds `phemc` in `PHEMC_BIN`, in `vendor/bin/phemc`, or in the `PATH`. Without `phemc`, the
  test is skipped.
- `phemc` also takes the `extra.phem.source` of the root `composer.json`. It compiles the
  package as a package, as an app sees it: its pages need a route import.
- The kernel has FrameworkBundle, TwigBundle, StimulusBundle and PhemBundle, `framework.test`,
  and the `routing.controllers` routes. Override `registerBundles()`, `configureContainer()` or
  `configureRoutes()` to add more, and call the parent method. Override `sourceDir()` for
  another directory of the test app, and `buildDir()` for another directory of the `phemc`
  output (the default is `var/phem`, and it is also the `phem.build_dir` option).

The tests use `InteractsWithPhem`, as in an app (see [Testing](testing.md)):

```php
final class BadgeTest extends KernelTestCase
{
    use InteractsWithPhem;

    public function testTheBadgeShowsItsLabel(): void
    {
        $this->mount('Acme\Ui\Badge', ['label' => 'New'])->assertSee('New');
    }
}
```

In the CI, `vendor/bin/phemc check tests/app/src` also checks the package. A package with no
test app can run the same command: when the source dir does not exist in a package (its root
`composer.json` gives `extra.phem.source`), `phemc` compiles only the root of the package. In an
app, a source dir that does not exist is an error.
