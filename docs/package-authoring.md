# Make a component package

A Composer package can ship `.phem` components, for example a UI kit with a theme. This guide shows how to declare the components, write them so apps can customize them, ship pages, CSS, and Stimulus controllers, share behavior between themes, and test the package. To install and customize a package in your app, see [Use and customize component packages](packages.md).

## Declare the components in composer.json

Put the `.phem` files in `src/Ui`, next to the other PHP classes of the package. Point `extra.phem.source` in the package’s `composer.json` to this directory, require `phem/phem`, and autoload `src/` with PSR-4:

```json
{
    "name": "acme/ui",
    "type": "library",
    "require": {
        "phem/phem": "^0.3"
    },
    "autoload": {
        "psr-4": {
            "Acme\\": "src/"
        }
    },
    "extra": {
        "phem": {
            "source": "src/Ui"
        }
    }
}
```

A package with this setup has the following layout:

```text
acme-ui/
├── assets/                the CSS and the Stimulus controllers of the package (optional)
├── src/
│   ├── Ui/                the components of the package (extra.phem.source)
│   │   ├── Badge.phem     namespace Acme\Ui
│   │   ├── Button.phem
│   │   └── Variant.php    a PHP class next to the components (an enum of variants)
│   └── AcmeUiBundle.php   a Symfony bundle, only when the package changes the app config
├── tests/
│   ├── app/src/           the test app: pages and components that use the package
│   └── Kernel.php         the test kernel (see "Test a package")
└── composer.json
```

The namespace of each file follows its directory, as PSR-4 does. A component in `src/Ui/Badge.phem` is in `Acme\Ui`, and the enum in `src/Ui/Variant.php` is `Acme\Ui\Variant`. The `.phem` files don’t need the autoload, because the app’s `phemc build` compiles them. The autoload loads the other PHP classes of the package, such as an enum of variants, a service, or a bundle. `phemc` reads only the `.phem` files of `src/Ui`, so a `.php` file in the same directory is correct.

The package doesn’t need to be a Symfony bundle. `phemc` finds the components through `extra.phem.source`, and `PhemBundle` in the app renders them. Keep `"type": "library"` when the package has only components and PHP classes. A package that must change the app’s configuration, for example to add its styles to AssetMapper, must be a bundle (see [Add a bundle to a package](#add-a-bundle-to-a-package)).

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

- **Extra classes**: Mark the default classes with `classes()`, so `<Button className="w-full" />` keeps the classes of `Button` and adds the caller’s. See [Merge of classes](syntax.md#merge-of-classes).
- **Another element**: Take the tag in a prop and render `<Dynamic tag={{ tag }}>`, so a `Button` can render as a link. See [Dynamic element](syntax.md#dynamic-element).
- **Replacement**: The app can replace any component in every tag, including tags inside your other components. Don’t make classes `final`, so an app can extend one and replace the original. See [Replace a component of a package](packages.md#replace-a-component-of-a-package).

## Add variants to a component

A variant such as `primary` or `outline` needs no special feature. Use a backed enum prop (see [Enum props](syntax.md#enum-props)) and give each case its classes in an enum method:

```php
// vendor/acme/ui/src/Ui/Button.phem
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

### Combine the variants of two axes

When a component has two or more enum props, some classes apply only to a combination of values, for example a bold `danger` badge only in the `lg` size. A `match` on one enum can't express this. Use `Phem\variants()`, which works like cva: the base classes, the classes of each value of each axis, and the classes of each compound variant.

Put the recipe in a method of the main enum, and give the other props as arguments. The markup then calls the method, as with one axis:

```php
// vendor/acme/ui/components/Badge.phem
namespace Acme\Ui;

use Phem\ClassList;

use function Phem\variants;

enum Tone: string
{
    case Info = 'info';
    case Danger = 'danger';

    public function classes(Size $size): ClassList
    {
        static $recipe = variants(
            base: 'rounded',
            variants: [
                'tone' => ['info' => 'bg-blue-100 text-blue-800', 'danger' => 'bg-red-100 text-red-800'],
                'size' => ['sm' => 'px-2 text-xs', 'lg' => 'px-3 text-sm'],
            ],
            compound: [
                [['tone' => Tone::Danger, 'size' => Size::Lg], 'font-bold'],
            ],
        );

        return $recipe->apply(tone: $this, size: $size);
    }
}

enum Size: string
{
    case Sm = 'sm';
    case Lg = 'lg';
}

#[Component]
function Badge(string $label, Tone $tone = Tone::Info, Size $size = Size::Sm, mixed ...$rest): Node
{
    return <span className={{ tone.classes(size) }} {{ ...rest }}>{{ label }}</span>;
}
```

```php
<Badge label="Failed" tone="danger" size="lg" className="ml-2" />
<Badge label="New" />
```

```html
<span class="rounded bg-red-100 text-red-800 px-3 text-sm font-bold ml-2">Failed</span>
<span class="rounded bg-blue-100 text-blue-800 px-2 text-xs">New</span>
```

The `static` variable builds the recipe at the first call only, not one time for each badge. `Badge` has only a `return`, so it renders inline, as `Button` does. Code before the `return` makes a function component render as its own component, with a `data-phem-c` marker on its element.

The enums stay the props, so `phemc` still checks the values (E018), and the PHP defaults are the default variants. `variants()` doesn't need `defaultVariants`. `apply()` works in this way:

- **Order**: The base classes come first, then the classes of each axis in the order of the recipe, and then the classes of each compound variant that matches.
- **Keys**: A backed enum gives its value, a pure enum gives its name, and a bool gives `'true'` or `'false'`. An axis without a value, or with `null`, adds no class.
- **Compound variants**: Each one is a pair of conditions and classes. A compound variant matches when all its conditions match. A condition is one value or a list of values: `['tone' => [Tone::Info, Tone::Danger]]`.
- **Result**: `apply()` gives a `ClassList`, so the caller's `className` merges into it, as with `classes()`. The classes of a recipe take the inputs of `classes()` too, for example a condition map.
- **Errors**: The recipe is strict, as a `match` is. An axis or a value that isn't in the recipe is an error, in `apply()` and in a compound variant. So when you add a case to an enum and forget the recipe, the first render fails, and no class is silently lost. Give a value that has no classes an empty string: `'md' => ''`. A compound variant that isn't a pair (for example the cva shape `['tone' => 'danger', 'class' => '...']`) is an error too.

Keep the enum method for a component with one axis. It is simpler.

## Support controlled and uncontrolled state

A package component often keeps a value in its `#[State]`, for example the active tab of `Tabs`. Some apps only set the first value, while others keep the value in their own state. Support both with two props:

- **`defaultValue`** (uncontrolled): the component copies it to its `#[State]` in `mount()` and then manages the value itself
- **`value` with an `onChange` closure** (controlled): the parent keeps the value, and the component’s handler calls `onChange` without changing its own state

Declare both props next to the state, and seed the state in `mount()`:

```php
// vendor/acme/ui/src/Ui/Tabs.phem
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

A package can declare `#[Page]` pages, but they only become routes when an app imports them (see [Import the pages of a package](packages.md#import-the-pages-of-a-package)). A package page can’t import the app’s `Document`, so give it a layout component of the package with a default document and a `<Root>`:

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
// vendor/acme/ui-base/src/UiBase/AbstractTabs.phem
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
// vendor/acme/ui-tailwind/src/Ui/Tabs.phem
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
    "autoload": { "psr-4": { "Acme\\": "src/" } },
    "extra": { "phem": { "source": "src/Ui" } }
}
```

`composer require acme/ui-shadcn` then removes `acme/ui-tailwind`. If two themes are installed anyway, `phemc` reports the duplicate components (E014).

## Ship CSS and Stimulus controllers

Phem has no CSS feature for packages. Your package relies on the app’s Tailwind build for styles and on the Symfony UX format for Stimulus controllers.

For styles, keep the classes in the `.phem` sources and put theme tokens (CSS variables and an `@theme` block, as shadcn does) in a CSS file of the package, such as `assets/theme.css`. The app adds both to its Tailwind build, as shown in [Add a package to your Tailwind build](packages.md#add-a-package-to-your-tailwind-build). Don’t ship Tailwind CSS through the Symfony UX `autoimport` (the way `@phem/phem` ships `phem.css`), because that file skips the Tailwind build. Use `autoimport` only for plain CSS.

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

## Add a bundle to a package

A package of components and PHP classes doesn’t need a bundle. A package that must change the configuration of the app needs one, because only a bundle can prepend configuration or register services. For example, a package with styles in `assets/` needs a bundle to add this directory to AssetMapper. The components work the same with or without a bundle.

Set `"type": "symfony-bundle"` in `composer.json`, so Flex adds the bundle to the app’s `config/bundles.php`. Put the bundle class in `src/`, out of `src/Ui`:

```php
// src/AcmeUiBundle.php
namespace Acme;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class AcmeUiBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!interface_exists(AssetMapperInterface::class)) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [$this->getPath().'/assets' => '@acme/ui'],
            ],
        ]);
    }
}
```

The class is in `src/`, so `getPath()` of the `AbstractBundle` is the package root. The app then loads the styles with the `@acme/ui/ui.css` logical path.

The bundle needs these packages in `require`: `symfony/config`, `symfony/dependency-injection`, and `symfony/http-kernel`. Put `symfony/asset-mapper` in `suggest`, because the bundle only uses it when the app has it.

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
