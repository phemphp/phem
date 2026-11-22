# Use and customize component packages

Install a Composer package of `.phem` components and use its components like your own. This guide also shows how to install the Base UI components of `phem/ui-base`, import the pages of a package, replace one of its components, merge Tailwind classes, and add its styles to your Tailwind build. To write a package, see [Make a component package](package-authoring.md).

## Install a package

Require the package. `phemc watch` (the worker of `symfony serve`) sees the change of `vendor/composer/installed.json` and compiles the package’s `.phem` files with your app:

```sh
composer require acme/ui
```

Import a component of the package and use it as a tag:

```php
use function Acme\Ui\Badge;

#[Page('/')]
function HomePage(): Node
{
    return <Document title="Home"><Badge label="New" /></Document>;
}
```

Add classes where you use a component, for example `<Button className="w-full" />`. The package’s classes stay, and `classes()` merges yours in (see [Merge of classes](syntax.md#merge-of-classes)).

## Install the Base UI components

[`phem/ui-base`](https://github.com/phemphp/ui-base) has the components of [Base UI](https://base-ui.com/react/components) (dialogs, menus, selects, comboboxes, tabs, toasts, fields and more), styled with Tailwind CSS v4. The package has no JavaScript: the controllers of phem give the behavior.

1. Require the package:

   ```sh
   composer require phem/ui-base
   ```

   Flex adds `Phem\Ui\Base\PhemUiBaseBundle` to `config/bundles.php`. Without Flex, add it yourself. The bundle maps the `assets/` folder of the package to the `@phem/ui-base` path of AssetMapper.

2. Import the theme in your input CSS. The theme sets the colors, the radius and the shadows, and adds the sources of the components to the build, so you need no `@source` for them:

   ```css
   /* assets/styles/app.css */
   @import "tailwindcss";
   @import "../../vendor/phem/ui-base/assets/theme.css";
   ```

3. Enable the controllers of phem that the components use, in `assets/controllers.json` (see [Enable the controllers](controllers.md#enable-the-controllers)). Enable only the controllers of the components that you use, or all of them:

   ```json
   "@phem/phem": {
       "accordion": { "enabled": true, "fetch": "lazy" },
       "avatar": { "enabled": true, "fetch": "lazy" },
       "button": { "enabled": true, "fetch": "lazy" },
       "checkbox-group": { "enabled": true, "fetch": "lazy" },
       "collapsible": { "enabled": true, "fetch": "lazy" },
       "combobox": { "enabled": true, "fetch": "lazy" },
       "context-menu": { "enabled": true, "fetch": "lazy" },
       "dialog": { "enabled": true, "fetch": "lazy" },
       "drawer-provider": { "enabled": true, "fetch": "lazy" },
       "field": { "enabled": true, "fetch": "lazy" },
       "form": { "enabled": true, "fetch": "lazy" },
       "menu": { "enabled": true, "fetch": "lazy" },
       "menubar": { "enabled": true, "fetch": "lazy" },
       "navigation-menu": { "enabled": true, "fetch": "lazy" },
       "number-field": { "enabled": true, "fetch": "lazy" },
       "otp-field": { "enabled": true, "fetch": "lazy" },
       "popover": { "enabled": true, "fetch": "lazy" },
       "radio-group": { "enabled": true, "fetch": "lazy" },
       "scroll-area": { "enabled": true, "fetch": "lazy" },
       "select": { "enabled": true, "fetch": "lazy" },
       "slider": { "enabled": true, "fetch": "lazy" },
       "tabs": { "enabled": true, "fetch": "lazy" },
       "toast": { "enabled": true, "fetch": "lazy" },
       "toggle": { "enabled": true, "fetch": "lazy" },
       "toggle-group": { "enabled": true, "fetch": "lazy" },
       "toolbar": { "enabled": true, "fetch": "lazy" },
       "tooltip": { "enabled": true, "fetch": "lazy" }
   }
   ```

4. Optional: give your input CSS to the Tailwind class merger. The bundle of the package turns on the merger, so that a class that you give in `className` replaces the class of the same group of the component (`<Button className="rounded-md">` removes `rounded-control`). The merger reads the theme of the package, so it knows `rounded-control`, `rounded-popup` and `shadow-popup`. When your input CSS has its own `@theme` names, set `css` to it. It imports the theme, so the merger knows the names of the two files. See [Merge Tailwind classes](styling.md#merge-tailwind-classes):

   ```yaml
   # config/packages/phem.yaml
   phem:
       class_merger:
           tailwind:
               css: '%kernel.project_dir%/assets/styles/app.css'
   ```

5. Use the components. Each Base UI part is a tag: the `X.Root` of Base UI is `X`, and each other part is `X<Part>`:

   ```php
   use Phem\Ui\Base\{Accordion, AccordionItem};
   use function Phem\Ui\Base\{AccordionHeader, AccordionPanel, AccordionTrigger};

   #[Page('/faq')]
   function FaqPage(): Node
   {
       return <Document title="FAQ">
           <Accordion>
               <AccordionItem value="what">
                   <AccordionHeader><AccordionTrigger>What is Base UI?</AccordionTrigger></AccordionHeader>
                   <AccordionPanel>A library of unstyled components.</AccordionPanel>
               </AccordionItem>
           </Accordion>
       </Document>;
   }
   ```

`vendor/bin/phemc components` lists the components of the package. `vendor/bin/phemc components Dialog` shows the imports, the props and the parts of one component, and `vendor/bin/phemc examples Dialog` shows its examples. The guide of the package (`vendor/phem/ui-base/README.md`) shows how to change the theme and the dark mode.

## Import the pages of a package

A package’s `#[Page]` pages aren’t routes of your app until you import them. The `routing.controllers` resource (and the `phem` resource `.`) only loads your app’s pages. Import a package’s pages by its name, with a prefix if you like:

```yaml
# config/routes/acme_ui.yaml
acme_ui:
    resource: acme/ui
    type: phem
    prefix: /ui
```

The routes come from the build. After `composer require`, `phemc watch` builds again and rebuilds your app’s routes. Without the watcher, the import has no routes until the next `phemc build`. Naming a package that isn’t installed is an error, and the message lists the packages that have pages.

### Render package pages in your document

A package page can’t import your `Document`, so it uses a layout component of the package. Replace that layout with a component that renders your `Document`, and the package pages get your document:

```php
use Acme\Ui\Layout;

#[Component(replaces: Layout::class)]
function PackageLayout(?Html $children = null): Node
{
    return <Document title="Acme">{{ children }}</Document>;
}
```

## Replace a component of a package

Put `#[Component(replaces: ...)]` on a component of your app. Every tag of the original then renders your replacement: in your app, and in the components and pages of all packages. Inside the replacement, a tag of the original stays the original, so the replacement can wrap it.

Take this package, where `Notice` uses `Alert`:

```php
// vendor/acme/ui/src/Ui/Alert.phem
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

The app replaces `Alert` with a version that adds a label and wraps the original:

```php
// src/Ui/Common/Alert.phem
namespace App\Ui\Common;

use Acme\Ui\Alert as UiAlert;

#[Component(replaces: UiAlert::class)]
function Alert(string $type = 'info', ?Html $children = null): Node
{
    $label = 'warning' === $type ? 'Careful' : 'Note';

    return <UiAlert type={{ type }}><strong>{{ label }}</strong> {{ children }}</UiAlert>;
}
```

A page that uses both package components:

```php
// src/Ui/ReplacedPage.phem
namespace App\Ui;

use Acme\Ui\Alert;
use function Acme\Ui\Notice;

#[Page('/replaced')]
function ReplacedPage(): Node
{
    return (
        <Document title="Replaced">
            <Alert>Saved</Alert>
            <Notice>Read this</Notice>
        </Document>
    );
}
```

Both alerts render the replacement, including the one inside `Notice`:

```html
<div class="alert alert-info" role="alert" data-phem-c="phemf06a118edb20"><strong>Note</strong> Saved</div>
<section class="notice"><div class="alert alert-warning" role="alert" data-phem-c="phem57a1d908ae81"><strong>Careful</strong> Read this</div></section>
```

`phemc` rewrites the tags at compile time. The template of `Notice` renders the replacement, and the template of the replacement renders the original:

```twig
{# vendor/acme/ui/Alert.1.twig (Notice) #}
<section class="notice">{% component embed 'App\\Ui\\Common\\Alert' with {'type': 'warning'} only %}...{% endcomponent %}</section>

{# Ui/Common/Alert.0.twig (the replacement) #}
{% component embed 'Acme\\Ui\\Alert' with {'type': (type)} only %}...{% endcomponent %}
```

Set `replaces` to `Name::class` or a string with the class name (`self::class` is E017). For a function component, also use `Name::class` with a `use` import, not `use function`. PHP doesn’t check that the class exists.

### Rules for replacements

`phemc` checks each replacement against the original:

- **App only**: Only the app replaces a component. `replaces` in a package is E017. A theme package extends the classes of a base package instead (see [Share behavior between theme packages](package-authoring.md#share-behavior-between-theme-packages)).
- **One replacement per component**: A second replacement is E017. So is a replacement of a replacement, of a page, or of a built-in component (`<Root>`, `<Suspense>`), and a page that replaces a component, because a page isn’t a tag. A component can’t replace itself, but a tag of the replacement inside itself is fine. `replaces: null` means no replacement.
- **Compatible props and slots**: The replacement accepts every prop and slot of the original, or has a rest prop (`mixed ...$rest`). It requires no prop or slot that the original doesn’t require. A `Bound` prop of the original stays `Bound`, so `phem:bind` on a tag of the original passes a `Bound`. Otherwise `phemc` reports E016. Other types aren’t compared, because PHP checks them.
- **Class replacements**: In a class replacement, a tag of the original stays the original in all its methods, including methods of its parent classes and traits in the sources (up to the original).
- **Original checks**: Tags of the original keep the original’s checks. An unknown prop is E011, even when the replacement has a rest prop.
- **Contexts**: A consumer finds its provider by type. A replacement of a provider extends the original’s class or wraps the original, so the package’s consumers still find it. A required context of the replacement with no provider above a tag of the original is E015 at the tag, and the message names the replacement.
- **Streams**: A replacement gets the streams of the original with `#[Broadcast]`. `$streams->toComponent(Counter::class)` also refreshes the replacements of `Counter`, with the `#[BroadcastKey]` props of `Counter`.
- **Plain Twig templates**: `{% component 'Acme\\Ui\\Alert' %}` in a plain Twig template of your app also renders the replacement, because the manifest keeps the replacements. A plain Twig template isn’t inside the replacement, so it can’t render the original. Wrap the original in the replacement’s `.phem` markup instead.
- **Tooling**: The profiler shows which component each replacement replaces. In the editor, go to definition on a tag of the original opens the replacement, and hover shows both. A prop or slot of the tag goes to the replacement’s prop or slot, or to the original when the replacement takes it in its rest prop. The editor follows the `phemc` rules: with no valid replacement, the tag goes to the original.

## Add a package to your Tailwind build

Tailwind CSS v4 skips the files in `.gitignore`, and `vendor/` is in it. Add each package’s `.phem` root to your input CSS with `@source`. The `.phem` sources contain the classes, so Tailwind doesn’t need the compiled templates in `var/phem`:

```css
/* assets/styles/app.css */
@import "tailwindcss";

@source "../../vendor/acme/ui/src/Ui";
```

When a theme package (shadcn, for example) ships its CSS variables and `@theme` block in a CSS file, import that file too, so your Tailwind build reads it:

```css
/* assets/styles/app.css */
@import "tailwindcss";
@import "../../vendor/acme/ui/assets/theme.css";

@source "../../vendor/acme/ui/src/Ui";
```

With the Tailwind merger, pass your input CSS to the `css` option (see [Turn on the Tailwind class merger](styling.md#merge-tailwind-classes)). Phem reads the `@theme` blocks of relative imports too, so it also reads the package’s theme.

Packages ship their Stimulus controllers in the Symfony UX format, so Flex adds them to `assets/controllers.json` when you install the package. You don’t need to register them by hand.

## How phemc builds your packages

`phemc build`, `check`, and `watch` find packages in `installed.json` in the Composer dir of your vendor dir (`vendor/composer/`, or the `config.vendor-dir` of `composer.json`, or `COMPOSER_VENDOR_DIR`). They compile the `.phem` files of each package with `extra.phem.source` together with your app, in one build. This affects your build in these ways:

- **One manifest**: The manifest has the components of your app and all packages, so a package can use another package’s components (a theme can extend a base package).
- **Output paths**: A package compiles to `var/phem/php/vendor/<package>/` and `var/phem/templates/vendor/<package>/`. An app file with the same output path (in `src/vendor/<package>/`) is an error, so rename or move one of the two files.
- **Skipped directories**: A root doesn’t read the directory of another root (a test app in the root `.` of a package), a Composer vendor dir, `node_modules`, or `.git`.
- **Matching versions**: `phemc` compiles a package from its source. The package requires `phem/phem`, which ships `phemc`, so Composer installs a compatible version.
- **Errors and warnings**: An error in a package stops the build, and `phemc` shows it with its path in `vendor/`. Warnings of a package don’t show, because your app can’t fix them. Your app’s diagnostics about package components (an unknown prop, a missing slot) show as usual.
- **Duplicate components**: Two packages that declare the same component are E014, and the message gives the path of the other file.
- **Editor support**: The language server checks packages with your app and gives completion, go to definition, and hover for their components (see [The language server](compiler.md#phemc-lsp)).
- **Watch mode**: `phemc watch` also watches `vendor/composer/installed.json`, so it rebuilds with the new packages after `composer require` or `composer update`. It watches each package directory too, for a package in development (a Composer path repository). When a package root is wrong, it shows the error and keeps watching your app and `installed.json`, so fixing the package triggers a new build.
- **Template errors**: An error in a package template points to the package’s `.phem` file in `vendor/`.
