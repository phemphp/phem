# Make a component package

A Composer package can ship `.phem` components, for example a UI kit with a theme. This guide shows how to declare the components, write them so apps can customize them, ship pages, CSS, and Stimulus controllers, share behavior between themes, and test the package. To install and customize a package in your app, see [Use and customize component packages](using-packages.md).

## Declare the components in composer.json

Point `extra.phem.source` in the package’s `composer.json` to the directory of its `.phem` files, and require `phem/phem`:

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

A package with this setup has the following layout:

```text
acme-ui/
├── composer.json
├── components/            the .phem files of the package (extra.phem.source)
│   ├── Badge.phem
│   └── GalleryPage.phem
├── src/                   the PHP classes of the package (enums, services), with PSR-4
└── tests/
    ├── Kernel.php         the test kernel (see "Test a package")
    └── app/src/           the test app: pages and components that use the package
```

The `.phem` files don’t need a PSR-4 autoload entry, because the app’s `phemc build` compiles them. The package’s other PHP classes, such as an enum of variants or a service, use the normal Composer autoload.

Once an app runs `composer require acme/ui` and `phemc build`, its pages use the components as their own:

```php
use function Acme\Ui\Badge;

#[Page('/')]
function HomePage(): Node
{
    return <Document title="Home"><Badge label="New" /></Document>;
}
```

## Write components that apps can customize

Apps customize a package component in three ways, and each needs a choice in the component:

- **Extra classes**: Mark the default classes with `classes()`, so `<Button className="w-full" />` keeps the classes of `Button` and adds the caller’s. See [Merge of classes](phem-syntax.md#merge-of-classes).
- **Another element**: Take the tag in a prop and render `<Dynamic tag={{ tag }}>`, so a `Button` can render as a link. See [Dynamic element](phem-syntax.md#dynamic-element).
- **Replacement**: The app can replace any component in every tag, including tags inside your other components. Don’t make classes `final`, so an app can extend one and replace the original. See [Replace a component of a package](using-packages.md#replace-a-component-of-a-package).

## Add variants to a component

A variant such as `primary` or `outline` needs no special feature. Use a backed enum prop (see [Enum props](phem-syntax.md#enum-props)) and give each case its classes in an enum method:

```php
// vendor/acme/ui/components/Button.phem
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
```

The component passes the variant’s classes to `classes()`, which merges them with the base classes and the caller’s classes:

```php
#[Component]
function Button(string $label, Variant $variant = Variant::Primary, mixed ...$rest): Node
{
    return <button className={{ classes('px-4 py-2', variant.classes()) }} {{ ...rest }}>{{ label }}</button>;
}
```

An app then picks a variant and adds its own class:

```php
<Button label="Save" variant="outline" className="w-full" />
```

```html
<button class="px-4 py-2 border border-blue-500 text-blue-500 w-full">Save</button>
```

Keep these points in mind for variants:

- **Full class names**: Write full class names in the source (`'bg-blue-500'`, not `'bg-'.$color`). Tailwind scans the source files, so it can’t find a class that the code builds at runtime.
- **Checked values**: `phemc` checks a literal prop value, so `variant="big"` is E018.
- **Conflicting classes**: With the [Tailwind class merger](styling.md#merge-tailwind-classes), a caller’s class replaces a variant class of the same group. `<Button variant="outline" className="border-red-500" />` gives `px-4 py-2 border text-blue-500 border-red-500`.

## Support controlled and uncontrolled state

A package component often keeps a value in its `#[State]`, for example the active tab of `Tabs`. Some apps only set the first value, while others keep the value in their own state. Support both with two props:

- **`defaultValue`** (uncontrolled): the component copies it to its `#[State]` in `mount()` and then manages the value itself
- **`value` with an `onChange` closure** (controlled): the parent keeps the value, and the component’s handler calls `onChange` without changing its own state

Declare both props next to the state, and seed the state in `mount()`:

```php
// vendor/acme/ui/components/Tabs.phem
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
```

The handler changes `active` only when `value` is `null`, and it always calls `onChange`. `render()` shows `value ?? active`:

```php
    public function select(string $name): void
    {
        if (null === $this->value) {
            $this->active = $name;
        }

        if (null !== $this->onChange) {
            ($this->onChange)($name);
        }
    }

    public function render(): Node
    {
        $current = $this->value ?? $this->active;

        return (
            <div role="tablist">
                {% for name in names %}
                    <button key={{ name }} role="tab" aria-selected={{ name == current }} onClick={{ select(name) }}>{{ name }}</button>
                {% endfor %}
            </div>
        );
    }
}
```

An app can now use `Tabs` both ways on the same page:

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
            <Document title="Settings">
                <Tabs names={{ ['general', 'security'] }} defaultValue="security" />
                <Tabs names={{ ['general', 'security'] }} value={{ tab }} onChange={{ show }} />
                <p>{{ tab }}</p>
            </Document>
        );
    }
}
```

A click on “security” in the second `Tabs` runs `select('security')`, which calls `show('security')`. The page renders again with its new state, and the second `Tabs` gets the new `value`. The result, without the `data-phem-*` attributes:

```html
<div role="tablist">
    <button role="tab" aria-selected="false">general</button>
    <button role="tab" aria-selected="true">security</button>
</div>
<p>security</p>
```

This pattern needs no core feature. After an event, each component whose state changed renders again, and the parent sits on the path to the handler, so it renders again when `onChange` changes its state. A controlled component without `onChange` never changes: its value is read-only.

## Ship pages in a package

A package can declare `#[Page]` pages, but they only become routes when an app imports them (see [Import the pages of a package](using-packages.md#import-the-pages-of-a-package)). A package page can’t import the app’s `Document`, so give it a layout component of the package with a default document and a `<Root>`:

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

The app can replace `Layout` with a component that renders its own `Document`, so your pages get the app’s document.

Don’t declare `#[ErrorPage]` in a package, because only the app has error pages (E060). Ship the error content as a component instead, and let the app render it in its own error page.

## Share behavior between theme packages

Two theme packages, for example `acme/ui-tailwind` and `acme/ui-shadcn`, can share the props, state, handlers, and `#[Provide]` of their components. Put abstract classes with no `#[Component]` and no `render()` in a base package:

```php
// vendor/acme/ui-base/components/AbstractTabs.phem
namespace Acme\UiBase;

#[Provide]
abstract class AbstractTabs
{
    #[State] public string $active = '';
    #[Prop] public ?Html $children = null;

    public function select(string $name): void
    {
        $this->active = $name;
    }
}
```

Each theme extends the base classes, adds `#[Component]`, and implements `render()`. Consumers of the context declare the base type:

```php
// vendor/acme/ui-tailwind/components/Tabs.phem
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
        <button role="tab" aria-selected={{ tabs.active == name }} onClick={{ tabs.select(name) }}>
            {{ children }}
        </button>
    );
}
```

These rules apply to base classes:

- **Inherited `#[Provide]`**: `#[Provide]` on a parent class applies to its children. PHP doesn’t inherit class attributes, but Phem checks the parents, both in `phemc` (E015) and at runtime. `#[Component]` isn’t inherited, so the base isn’t a component.
- **Base type in consumers**: Declare the base type (`#[Context] AbstractTabs $tabs`). The lookup takes the nearest provider whose value is an instance of the type, so consumers work with every theme.
- **No abstract components**: `#[Component]` on an abstract class is E045. The base stays a plain class, and a tag of it is E010.
- **No `final`**: Leave the classes open for extension, so an app can extend a theme class (to change a handler, for example) and replace the original with `#[Component(replaces: ...)]`.

### Let apps switch themes

Give the components of each theme the same names (`Acme\Ui\Tabs`), so the app’s imports don’t change. Each theme declares a Composer `conflict` with the other themes, and it can `provide` a virtual package for packages that need a theme:

```json
{
    "name": "acme/ui-tailwind",
    "require": { "acme/ui-base": "^1.0" },
    "conflict": { "acme/ui-shadcn": "*" },
    "provide": { "acme/ui-theme": "1.0" },
    "extra": { "phem": { "source": "components" } }
}
```

`composer require acme/ui-shadcn` then removes `acme/ui-tailwind`. If two themes are installed anyway, `phemc` reports the duplicate components (E014).

## Ship CSS and Stimulus controllers

Phem has no CSS feature for packages. Your package relies on the app’s Tailwind build for styles and on the Symfony UX format for Stimulus controllers.

For styles, keep the classes in the `.phem` sources and put theme tokens (CSS variables and an `@theme` block, as shadcn does) in a CSS file of the package, such as `assets/theme.css`. The app adds both to its Tailwind build, as shown in [Add a package to your Tailwind build](using-packages.md#add-a-package-to-your-tailwind-build). Don’t ship Tailwind CSS through the Symfony UX `autoimport` (the way `@phem/phem` ships `phem.css`), because that file skips the Tailwind build. Use `autoimport` only for plain CSS.

For Stimulus controllers, use the Symfony UX format that `phem/phem` uses: add the `symfony-ux` keyword to `composer.json`, and an `assets/package.json` with the `symfony.controllers` key. Flex then adds the controllers to the app’s `assets/controllers.json`:

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

A package has no app, so its tests use a small test app in `tests/app/src` with the pages and components that the tests need (for example a `<Tabs>` around the `<Tab>` items). Extend `Phem\Test\TestKernel` and point PHPUnit to it:

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

The test kernel works like this:

- **Project dir**: the root of the package, the directory of its `composer.json`
- **Compilation**: when the kernel boots, it compiles the test app with `phemc` once per test run. It finds `phemc` in `PHEMC_BIN`, in `vendor/bin/phemc`, or in the `PATH`. Without `phemc`, the test is skipped.
- **Package as an app sees it**: `phemc` also reads `extra.phem.source` from the root `composer.json` and compiles the package as a package, so its pages need a route import
- **Bundles and routes**: FrameworkBundle, TwigBundle, StimulusBundle, and PhemBundle, with `framework.test` and the `routing.controllers` routes. Override `registerBundles()`, `configureContainer()`, or `configureRoutes()` to add more, and call the parent method.
- **Directories**: override `sourceDir()` for another test app directory, and `buildDir()` for another `phemc` output directory. The default is `var/phem`, which is also the `phem.build_dir` option.

The tests use `InteractsWithPhem`, as in an app (see [Test components and pages](testing.md)):

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

### Check the package in CI

In continuous integration (CI), run `vendor/bin/phemc check tests/app/src` to check the package too. A package without a test app can run the same command: when the source dir doesn’t exist in a package (its root `composer.json` sets `extra.phem.source`), `phemc` compiles only the package root. In an app, a missing source dir is an error.
