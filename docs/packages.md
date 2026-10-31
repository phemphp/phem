# Component packages

A Composer package can ship `.phpm` components, for example a UI kit with a theme. The app
installs the package, and its pages use the components as their own:

```sh
composer require acme/ui
```

```php
use function Acme\Ui\Badge;

#[Page('/')]
function Home(): Node
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
│   └── Page/Gallery.phpm
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
function Gallery(): Node
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
// src/Component/Alert.phpm
namespace App\Component;

use Acme\Ui\Alert as UiAlert;

#[Component(replaces: UiAlert::class)]
function Alert(string $type = 'info', ?Html $children = null): Node
{
    $label = 'warning' === $type ? 'Careful' : 'Note';

    return <UiAlert type={{ type }}><strong>{{ label }}</strong> {{ children }}</UiAlert>;
}
```

```php
// src/Page/Replaced.phpm
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
<section class="notice">{% component embed 'App\\Component\\Alert' with {'type': 'warning'} only %}...{% endcomponent %}</section>

{# component/Alert.0.twig (the replacement) #}
{% component embed 'Acme\\Ui\\Alert' with {'type': (type)} only %}...{% endcomponent %}
```

The value of `replaces` is `Name::class` or a string with the class name. A function component
also uses `Name::class`, with a `use` import (not `use function`): PHP does not check that the
class exists.

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
  definition of a tag of the original goes to the replacement, and the hover shows both. The
  editor uses the rules of `phemc`: with no valid replacement, the tag goes to the original.

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
