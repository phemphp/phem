# Organize your project

A Phem project is a Symfony application with `.phem` files in its source root. This page shows the files of a project and the convention for the pages and components in `src/Ui/`.

## Project layout

A Phem project is a Symfony application. The compiler only needs a source root (`src/` here), and the bundle only needs the output directory and the routes:

```text
my-app/
├── composer.json        phem/phem, symfony/asset-mapper, symfony/runtime, …
├── .symfony.local.yaml  the phemc worker of `symfony serve`
├── .env                 APP_ENV, APP_SECRET (signs the component state)
├── Caddyfile            FrankenPHP: public/index.php in worker mode
├── bin/console
├── config/
│   ├── bundles.php      FrameworkBundle, TwigBundle, StimulusBundle, PhemBundle
│   ├── packages/        phem.yaml (optional), asset_mapper.yaml
│   ├── routes.yaml      { resource: routing.controllers }
│   └── services.yaml
├── src/                 the source root: .phem files and PHP classes
├── assets/              app.js, bootstrap.js, controllers.json
├── importmap.php        app, @hotwired/stimulus, @hotwired/turbo
├── public/              index.php (Symfony Runtime) and static files
└── var/
    ├── phem/            the phemc output (don't commit)
    └── cache/           the Symfony cache (don't commit)
```

Add DoctrineBundle to `config/bundles.php` for transactions and `#[Path]` entities. The source root holds your PHP classes next to the `.phem` files:

```text
src/
├── Kernel.php
├── Model/               optional: Doctrine entities
├── Repository/          services that components get injected
├── DataFixtures/        optional: Doctrine fixtures
└── Ui/                  the pages and their components, by context
    ├── Common/
    │   └── Document.phem      the HTML document, with a <Root>
    ├── HomePage.phem          #[Page('/')]
    └── Example/
        ├── CounterPage.phem   #[Page('/examples/counter')]
        └── Counter.phem       a component of the page
```

These directories are a convention. A page is any class with the `#[Page]` attribute and a `render()` method, or any function with the `#[Page]` attribute, in any `.phem` file under the source root. For the document component and its `<Root>`, see [The document of the pages](pages.md#wrap-the-pages-in-a-document). For the routes file, see [Route loading](pages.md#load-the-routes-of-the-pages).

## Organize the UI

Put the `.phem` files in `src/Ui/`, and split them by context, not into `Component/` and `Page/` directories. The namespace follows the directory: `src/Ui/` is `App\Ui`, and `src/Ui/Scientist/` is `App\Ui\Scientist`. The convention has four rules:

- **Page**: the name ends with `Page` (`HomePage`, `ScientistPage`). It goes in the directory of its context (`src/Ui/Scientist/`). A page with no context, such as the home page, stays in `src/Ui/`
- **Component of a page**: it goes in the directory of the page that uses it, in the same namespace. Its name has no suffix (`ScientistCard`, not `ScientistCardComponent`)
- **Common component**: a component that more than one context uses goes in `src/Ui/Common/` (the document, a button, a badge)
- **Subcontext**: when a context grows, add a directory inside it (`src/Ui/Admin/User/`)

```text
src/Ui/                      App\Ui
├── Common/                  App\Ui\Common
│   ├── Document.phem
│   └── Badge.phem
├── HomePage.phem            #[Page('/')]
├── Scientist/               App\Ui\Scientist
│   ├── ScientistsPage.phem  the list
│   ├── ScientistPage.phem   one scientist
│   ├── ScientistCard.phem   used by both pages
│   └── ScientistFilters.phem
└── Admin/
    └── User/                App\Ui\Admin\User
        ├── EditUserPage.phem
        └── UserForm.phem
```

Because a page and its components share a namespace, the page uses them with no `use` import. It imports only the common components. Here `ScientistCard` resolves to `App\Ui\Scientist\ScientistCard`, and `Document` comes from `App\Ui\Common`:

```php
<?phem

namespace App\Ui\Scientist;

use App\Model\Scientist;
use Phem\Attribute\{Page, Path};
use Phem\Node;

use function App\Ui\Common\Document;

#[Page('/scientists/{id:scientist}')]
final class ScientistPage
{
    #[Path] public Scientist $scientist;

    public function render(): Node
    {
        return (
            <Document title={{ scientist.name }}>
                <ScientistCard scientist={{ scientist }} />
            </Document>
        );
    }
}
```

When a second context starts to use a component of a page, move the component to `src/Ui/Common/`.
