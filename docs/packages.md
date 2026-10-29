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
- The language server checks the packages with the app too (see
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
  another directory of the test app.

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
