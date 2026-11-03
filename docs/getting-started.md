# Getting started: a new app from scratch

This guide adds Phem to a new Symfony application. At the end, you have a dev server that builds
the `.phpm` files when you save them, and a page with an interactive component.

You need the [Symfony CLI](https://symfony.com/download) (with PHP 8.4 or later, and Composer).

## Step 1: Make the application and install Phem

```sh
symfony new my-app --webapp
cd my-app
composer require phem/phem
```

Run all the commands of this guide in `my-app/`.

The recipe of `phem/phem` is in the "contrib" repository of Symfony Flex. Flex asks before it runs
the recipe: answer `y`.

Symfony Flex and PhemBundle configure the application:

- Flex enables `PhemBundle`, and adds the `phem` Stimulus controller and Turbo to the assets.
  This controller is the client of Phem: it sends the events to the server and updates the page.
- The recipe adds the document of the pages, the first page, and the `phemc` worker of the dev
  server:

  ```
  my-app/
  ├── src/
  │   ├── Component/
  │   │   └── Document.phpm   the HTML document of the pages
  │   └── Page/
  │       └── Home.phpm       the page at /
  └── .symfony.local.yaml     `symfony serve` also runs `phemc watch src`
  ```

- The `routing.controllers` resource of `config/routes.yaml` loads the routes of the pages (the
  `#[Page]` attributes), as it does for the controllers. You do not add a routes file.
- In production, `config/preload.php` also preloads the compiled components. You do not change it.
- `vendor/bin/phemc` downloads the `phemc` compiler of the same version on the first run.

The defaults of PhemBundle fit this layout: it reads `src/` and writes the build output to
`var/phem/`. You do not need a `config/packages/phem.yaml` file.

If Flex does not run the recipe (for example, with `composer require --no-interaction`), allow
the contrib recipes and install the recipe again:

```sh
composer config extra.symfony.allow-contrib true
composer recipes:install phem/phem --force
```

Flex keeps a skipped recipe as installed, so `--force` is necessary. `allow-contrib` applies to
all the contrib recipes, and Flex does not ask again. Set it before `composer require phem/phem`
to install with no question (for example, in CI).

Without `--webapp`, install AssetMapper with `phem/phem`. `phem/phem` requires StimulusBundle, and
Flex adds the `phem` controller to `assets/controllers.json`:

```sh
symfony new my-app
cd my-app
composer require symfony/asset-mapper phem/phem
```

## Step 2: Start the dev server

```sh
symfony serve
```

The server also starts the `phemc` worker of `.symfony.local.yaml`. On the first start, the worker
downloads `phemc`, then builds the `.phpm` files:

```
[phemc] Downloading phemc v0.3.1 (phemc-mac-arm64)...
[phemc] phemc: compiled 2 file(s), 2 template(s), 1 page(s); 7 file(s) changed, 0 warning(s) in 7 ms
[phemc] phem: compiled 2 template(s) into the Twig cache in 32 ms
[phemc] phemc: watching 1 source dir(s) for changes
```

Open the URL that `symfony serve` shows (by default https://127.0.0.1:8000). The page shows
"Hello, Phem!". Keep the server on.

The application owns the HTML document. It is a component, and each page renders its content in
it. `<Root>` is the root element of the page: the `phem` controller gets the events of the
elements in it. `{{ importmap('app') }}` loads `assets/app.js`, which starts Stimulus with the
`phem` controller.

`src/Component/Document.phpm`:

```php
<?phpm

namespace App\Component;

use Phem\Attribute\Component;
use Phem\{Html, Node, Root};

// The HTML document of the pages. A page gives its title with <Document:title>.
#[Component]
function Document(Html $title, Html $children): Node
{
    return (
        <html lang="en">
            <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>{{ title }}</title>
                <link rel="icon" href="data:," />
                {{ importmap('app') }}
            </head>
            <body>
                <Root>{{ children }}</Root>
            </body>
        </html>
    );
}
```

A page is a component with the `#[Page]` attribute: a Symfony route. This page has no state, so
it is a function that returns the markup.

`src/Page/Home.phpm`:

```php
<?phpm

namespace App\Page;

use Phem\Attribute\Page;
use Phem\Node;

use function App\Component\Document;

#[Page('/')]
function Home(): Node
{
    return (
        <Document>
            <Document:title>My app</Document:title>
            <h1>Hello, Phem!</h1>
        </Document>
    );
}
```

## Step 3: Add an interactive component

A class component keeps its state in `#[State]` properties. The state stays in the browser
between the events. An event handler, such as `increment()`, runs on the server.

`src/Component/Counter.phpm`:

```php
<?phpm

namespace App\Component;

use Phem\Attribute\{Component, State};
use Phem\Node;

#[Component]
final class Counter
{
    #[State]
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return (
            <button type="button" onClick={{ increment }}>
                Clicked {{ count }} times
            </button>
        );
    }
}
```

Import it in `src/Page/Home.phpm`, and use it two times. Each `<Counter />` has its own state. The
page has no state of its own, so it stays a function:

```php
use App\Component\Counter;

#[Page('/')]
function Home(): Node
{
    return (
        <Document>
            <Document:title>My app</Document:title>
            <h1>Hello, Phem!</h1>
            <Counter />
            <Counter />
        </Document>
    );
}
```

Save the files. The `phemc` worker builds them again:

```
[phemc] phemc: change detected, rebuilding
[phemc] phemc: compiled 3 file(s), 3 template(s), 1 page(s); 7 file(s) changed, 0 warning(s) in 8 ms
[phemc] phem: compiled 3 template(s) into the Twig cache in 15 ms
```

Reload the page, and click the buttons. Each button counts its own clicks.

If a file has an error, the worker shows the diagnostic with the `.phpm` line, and the previous
build stays. Run `symfony server:log` to see the log of the worker in another terminal. Other
tools:

```sh
vendor/bin/phemc check src          # all the diagnostics
bin/console debug:router            # the routes of the pages and their events
bin/console phem:render /           # the HTML of a page, with no server
```

## Use Claude Code

The Phem plugin for [Claude Code](https://claude.com/claude-code) runs the language server of
the application (`vendor/bin/phemc lsp`). After each edit of a `.phpm` file, Claude Code gets the
errors and warnings of `phemc`, so it can correct them at once. It also gets go to definition,
find references, hover, and outline through its `LSP` tool.

Install the plugin:

```sh
claude plugin marketplace add phemphp/phem
claude plugin install phem@phem
```

In a running session, run `/reload-plugins` after the install.

The plugin runs the `vendor/bin/phemc` of each project that has one, so use it only in projects
that you trust.

The server starts the first time Claude Code edits a `.phpm` file. After each edit, the
conversation shows a summary, for example `Found 1 new diagnostic issues in 1 files`. Press
`Ctrl+O` to read the diagnostics. If the server does not start, open `/plugin` and read the
**Errors** tab.

## Next steps

- [Tutorial: a task list](tutorial.md): build a small app, one idea at a time
- [Deployment](deployment.md): the production image with FrankenPHP
- [Philosophy and conventions](philosophy.md): the rules to keep in mind when you design with Phem
- [The `.phpm` syntax](phpm-syntax.md): props, slots, lists, forms, and events
- [Compiling and running `.phpm` files](compiling.md): the build output and the diagnostics
- [Testing components and pages](testing.md)
- [How it works](how-it-works.md), and the limits
