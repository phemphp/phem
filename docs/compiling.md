# Compiling `.phpm` files

A `.phpm` project is a Symfony application with `PhemBundle`. It needs two build steps before
it can run:

1. `phemc build` (Rust) compiles the `.phpm` files to PHP and Twig.
2. `bin/console phem:warmup` (PHP) compiles the Twig templates into the Twig cache.

`phemc build` runs step 2 for you. At runtime, nothing parses `.phpm` or `.twig` sources.

## Project layout

```
my-app/
├── composer.json          requires phem/phem, symfony/asset-mapper, symfony/stimulus-bundle (and symfony/runtime, symfony/yaml, symfony/dotenv)
├── .env                   APP_ENV, APP_SECRET (it signs the component state in the browser)
├── Caddyfile              FrankenPHP: public/index.php in worker mode, and the static files
├── bin/console
├── config/
│   ├── bundles.php        FrameworkBundle, TwigBundle, StimulusBundle, PhemBundle (and DoctrineBundle for transactions and #[Path] entities)
│   ├── packages/phem.yaml   optional: source_dir, build_dir, secret
│   ├── packages/asset_mapper.yaml   the assets/ directory
│   ├── routes/phem.yaml     { resource: ., type: phem }: the #[Page] attributes of the pages
│   └── services.yaml      your services (for example repositories)
├── src/                   the .phpm sources (the "source root"), and your PHP classes
│   ├── Kernel.php
│   ├── Model/             optional: Doctrine entities
│   ├── Repository/        normal PHP services, injected into components
│   ├── DataFixtures/      optional: Doctrine fixtures, the first rows of a new database
│   ├── Component/        components that are not pages
│   │   └── Document.phpm   the HTML document: each page renders in it, with a <Root>
│   └── Page/             the pages: classes and functions with a #[Page] attribute
│       ├── index.phpm      #[Page('/')]
│       └── Example/
│           └── Counter.phpm #[Page('/examples/Counter')]
├── assets/                the JavaScript and CSS of the pages (AssetMapper)
│   ├── app.js             the `app` entry of the importmap: bootstrap.js and styles/app.css
│   ├── bootstrap.js       starts Stimulus (StimulusBundle)
│   └── controllers.json   enables the `phem` controller of phem/phem
├── importmap.php          app, @hotwired/stimulus, @hotwired/turbo, @symfony/stimulus-bundle
├── public/                static files, index.php (Symfony Runtime: FrankenPHP worker mode, PHP-FPM)
└── var/cache/             build output (do not commit)
```

The directories are only a convention: a page is a class with the `#[Page]` attribute and a
`render()` method, or a function with the `#[Page]` attribute, in any file.

### The document of the pages

The application owns the HTML document: a component in the .phpm sources, as the others. It
returns the `<html>` element, and it prints the content of the page in a `<Root>`. Phem adds
the `<!DOCTYPE html>`.

```php
<?phpm

namespace App\Component;

use Phem\Attribute\Component;
use Phem\{Html, Node, Root};

#[Component]
function Document(?Html $title = null, ?Html $children = null): Node
{
    return (
        <html lang="en">
            <head>
                <title>{{ title ?? 'My app' }}</title>
                {{ importmap('app') }}
            </head>
            <body><Root>{{ children }}</Root></body>
        </html>
    );
}
```

Each page renders its content in it. A page gives the title with the `title` slot:

```php
use Phem\Node;

use function App\Component\Document;

#[Page('/scientists/{id:scientist}')]
final class ScientistPage
{
    #[Path] public Scientist $scientist;

    public function render(): Node
    {
        return (
            <Document>
                <Document:title>{{ scientist.name }}</Document:title>
                <h1>{{ scientist.name }}</h1>
            </Document>
        );
    }
}
```

The document can have more slots, for example a `?Html $head` slot for the `<meta>` tags of a
page. For another layout (an admin area, for example), write another document component, and
use it in the pages of that area.

`<Root>` (`Phem\Root`) is a built-in component: import it with `use Phem\Root;`. It is the
root element of the page:

| In the document | Gives |
|---|---|
| `<Root>{{ children }}</Root>` | `<div id="phem-root" data-controller="phem" …>` with the page |
| `<Root tag="main" className="app">{{ children }}</Root>` | the same, with more attributes and another tag |

The root element has the attributes of the `phem` Stimulus controller: they give the component
state to the controller. `tag` is the tag name (`div` by default). The other props are
attributes, as on an element (`className`, a class list, `style`, `data-*`). The `id` is
`phem-root` (`id={{ null }}` removes it). Your `data-controller` and `data-action` values come
after the values of Phem. You cannot change the state attributes, and the root element cannot
have an event handler.

A page renders one `<Root>`. A page with no `<Root>` or with two is an error, and a `<Root>` in
a Twig template outside a page is an error too.

The events render only the components that can change (see
[Keep `render()` pure](phpm-syntax.md#keep-render-pure)), and they update only the content of
the `<Root>` in the browser. The document around it (the title, a footer) does not change. A component after
the root element renders, but the `phem` controller does not get its DOM events. A `<title>`
in the markup of a page is not the title of the page: phemc reports it (W005). A `<title>` in
`<head>` or in an `<svg>` is correct.

The browser sends the events of a page to the URL of the page, and only a page route runs
them.

### The client assets

The client of Phem is the `phem` Stimulus controller, and the `phem-c{display:contents}` CSS. The
bundle gives them as a Symfony UX package (`assets/package.json`). With AssetMapper,
they are in the `phemjs` namespace. The controller imports `@hotwired/stimulus` and
`@hotwired/turbo` from the importmap of the application, so the page has one Turbo and one
Stimulus. To set up an application:

```sh
composer require symfony/asset-mapper symfony/stimulus-bundle
php bin/console importmap:require @hotwired/stimulus @hotwired/turbo
```

Then enable the controller in `assets/controllers.json` (Symfony Flex does it when it installs
the bundle):

```json
{
    "controllers": {
        "@phem/phem": {
            "phem": { "enabled": true, "fetch": "eager", "autoimport": { "@phem/phem/dist/phem.css": true } }
        }
    },
    "entrypoints": []
}
```

Without StimulusBundle, add `'phemjs' => ['path' => 'phemjs/phem_controller.js']`
to `importmap.php`, register the controller (`application.register('phem', PhemController)`),
and import `phemjs/phem.css`.

The package also has optional controllers, which are not enabled by default (see the `.phpm`
syntax guide):

- `phem-optimistic` (`phemjs/phem_optimistic_controller.js`) changes the page before the server
  answers an event.
- `phem-toggle` (`phemjs/phem_toggle_controller.js`) shows and hides a dropdown, a menu or a
  section, and keeps it open after the morph of an event.
- `phem-dialog` (`phemjs/phem_dialog_controller.js`) opens a native `<dialog>` as a modal, and
  keeps it open after the morph of an event.
- `phem-tabs` (`phemjs/phem_tabs_controller.js`) shows the panel of the selected tab, with the
  ARIA roles and the keyboard of a tab list, and keeps the tab after the morph of an event.
- `phem-toast` (`phemjs/phem_toast_controller.js`) shows the `phem:toast`, `phem:error` and
  `phem:rate-limited` browser events as toasts.
- `phem-clipboard` (`phemjs/phem_clipboard_controller.js`) copies a text to the clipboard, and
  keeps its "copied" feedback after the morph of an event.
- `phem-autofocus` (`phemjs/phem_autofocus_controller.js`) gives the focus to an element when it
  connects, also when the morph of an event adds it.
- `phem-confirm` (`phemjs/phem_confirm_controller.js`) asks the user to confirm before an event
  of a handler goes to the server.

Enable a controller in `assets/controllers.json` with its key in the `@phem/phem` entry, for
example `"toggle": { "enabled": true, "fetch": "lazy" }`.

## 1. Get the tools

In an application, `composer require phem/phem` gives `vendor/bin/phemc`. On the first run, it
downloads the `phemc` binary of the installed version from the GitHub release. You also need
PHP 8.5 and, for the worker mode, [FrankenPHP](https://frankenphp.dev).

To work on this repository, use Docker (recommended). The `dev` image has FrankenPHP (PHP 8.5), Composer, and the Rust
toolchain:

```sh
make shell      # a shell in the dev image; `phemc` (built from compiler/) is on the PATH
```

Without Docker, you need PHP 8.5, [FrankenPHP](https://frankenphp.dev), and Rust:

```sh
cargo build --release --manifest-path compiler/Cargo.toml -p phemc-cli
export PATH="$PWD/compiler/target/release:$PATH"
composer install
```

## 2. Build

Run the commands in the project directory (the directory that contains `bin/console`).

```sh
phemc build src
```

Output:

```
phemc: compiled 15 file(s), 24 template(s), 10 page(s); 43 file(s) changed, 0 warning(s) in 51 ms
phem: compiled 24 template(s) into the Twig cache in 250 ms
```

The build is incremental: it writes only the files that changed, so the Twig cache and
OPcache stay valid. If there is an error, it writes nothing and exits with code 1.

| Option | Default | Meaning |
|---|---|---|
| `--out <dir>` | `var/cache/phem` | The output directory (the same as the `phem.build_dir` option) |
| `--php <bin>` | `php` | The PHP binary for the Twig warmup |
| `--no-warmup` | off | Do not run `bin/console phem:warmup` |

The warmup uses the environment of `APP_ENV`. In debug mode (`dev`), Symfony builds the
container and the routes again when the manifest or a compiled page changes: a new component,
page, or route needs no `cache:clear`.

### What the build writes

```
var/cache/phem/
├── php/Page/index.php          the PHP code; each markup literal becomes new \Phem\Element('Page/index.0.twig', [...])
├── templates/Page/index.0.twig one Twig template for each markup literal (Twig name: @phpm/Page/index.0.twig)
├── manifest.php / manifest.json components (kind, props, slots), pages, template → .phpm line map
├── autoload.php                 requires all compiled PHP files
└── preload.php                  for opcache.preload (production)
var/cache/<env>/twig/            the Twig cache (filled by the warmup)
```

The compiled PHP keeps the line numbers of the `.phpm` file. The manifest maps each template
back to its `.phpm` line, so the Symfony error page and the console show `src/<file>.phpm` and
its line for an error in markup.

## 3. Check only (CI)

```sh
phemc check src
```

It reports all diagnostics, writes nothing, and exits with code 1 if there is an error.
Each diagnostic shows the `.phpm` line and column, a label, and a hint:

```
E011

  × `<Profile>` has no prop `persn`
    ╭─[src/Page/App.phpm:16:22]
 15 │         <div>
 16 │             <Profile persn={{ user }} />
    ·                      ─────
 17 │         </div>
    ╰────
  help: its props are: person
```

### Diagnostics

`phemc` does not write output when it finds an error. The codes:

| Code | Meaning |
|---|---|
| E001–E006 | Syntax: unclosed or mismatched tags, a void element that is not self-closed, more than one root, a `<?php` open tag instead of `<?phpm` |
| E010–E015 | Unknown component (also a function without `#[Component]`), unknown or missing prop, children or slot not accepted, duplicate component (also a component with the name of a built-in component, `Phem\Root`), a required context with no provider above the component |
| E020 | A call to a React Hook (`Phem\useState`, ...): Phem keeps state in `#[Component]` classes |
| E030–E033 | Undefined variable in markup, markup in an unsupported expression, invalid attribute, invalid `phem:bind` (not a `#[State]` property, not a form field, or a type that does not take the value of the field) |
| E040–E044 | Class component without `render()`, readonly `#[State]` property, `#[Prop]` property that is not public, static, or `#[State]`, a second `#[Prop(rest: true)]` property or one whose type does not accept an array, a `#[Context]` or `#[Provide]` type that is not one class, interface or enum (or a `#[Context]` property that is static, readonly, a prop or state) |
| E050, E052, E053 | `export default` (not PHP; use `#[Page]`), the Symfony `#[Route]` on a function (use `#[Page]`) or on a class that is not a component, `#[Broadcast]` on a class or a function that is not a page or a component, or on a method of a page |
| E054, E055 | `#[Async]` on a function; an `#[Async]` method that does not declare `Promise` as its return type |
| E056 | a page (a class or a function) used as a tag |
| E057 | `#[BroadcastKey]` on a property that is not a prop, on a page, or in a component with no `#[Broadcast]` on it or on a handler |
| W001–W003 | A handler is called instead of passed; a list item without `key`; an event that the browser does not send (`onMouseEnter`) |
| W004 | `#[Page]`, `#[Route]`, `#[Component]`, `#[State]`, `#[Prop]`, `#[Path]`, `#[Context]`, `#[Provide]`, `#[Broadcast]`, `#[BroadcastKey]` or `#[Async]` without its `use` import (`Phem\Attribute\...`, `Symfony\Component\Routing\Attribute\Route`) |
| W005 | a `<title>` outside `<head>` and `<svg>`: the document component prints the title, and a page gives it with `<Document:title>` |
| W006 | `phem:debounce` or `phem:throttle` on an element with no handler |
| W007 | `phem:live` on an element with no `phem:bind` |
| W008 | a `render()` (or a function component) that is not pure: it reads a service of the constructor, a static property, a superglobal, the clock, or a random value (see [Keep `render()` pure](phpm-syntax.md#keep-render-pure)) |

For editors and other tools, `--format json` prints the diagnostics to stdout as JSON. The exit
code does not change:

```sh
phemc check src --format json
```

```json
{"files": [{"file": "Component/ScientistCard.phpm", "diagnostics": [{"severity": "error",
  "code": "E010", "message": "unknown component `<Avatr>` (resolved to `App\\Component\\Avatr`)",
  "label": null, "help": "declare `#[Component] function Avatr()` in a .phpm file, or check the `use` imports",
  "start": {"line": 30, "character": 13}, "end": {"line": 30, "character": 18}}]}]}
```

`file` is relative to the source directory. `line` starts at 0, and `character` counts UTF-16
code units, as the Language Server Protocol does.

### The language server

`phemc lsp` runs the language server on stdin and stdout. It shows the same diagnostics as
`phemc check` as you type, and it gives the completion, the definition, the hover, and the
outline of `.phpm` files. The editors bundle the same server, compiled to WebAssembly
(`make lsp`), so they do not need a `phemc` binary. See `editors/vscode/README.md` and
`editors/phpstorm/README.md`. The Claude Code plugin runs `vendor/bin/phemc lsp` of the
application: see [Getting started](getting-started.md#use-claude-code) and
`editors/claude-code/README.md`.

## 4. Run

With FrankenPHP in worker mode, in the project directory:

```sh
frankenphp run --config Caddyfile
```

- `public/index.php` uses Symfony Runtime. In a FrankenPHP worker, Symfony Runtime boots the
  kernel once and gives it many requests. The kernel resets the services (`kernel.reset`)
  between requests.
- Caddy serves the static files of `public/` at `/`. A directory serves its `index.html`.
  In development, Symfony serves the AssetMapper assets at `/assets/`.
- `FRANKENPHP_WORKER_CONFIG` adds options to the worker. For example, `watch ./src/**/*.php`
  restarts the workers when a PHP class changes.
- `APP_ENV=dev` (the default in `.env`) shows the Symfony error pages, with the `.phpm` location.
- The application assumes that the database exists. Make it before the first run, for example
  `bin/console doctrine:schema:create` and `bin/console doctrine:fixtures:load`. In Docker, run
  these commands in the entrypoint, only when the database does not exist yet.

With PHP-FPM, use `public/index.php` as the front controller, as for any Symfony application.

### The Symfony profiler

In debug mode, Phem adds a panel to the Symfony profiler. Install the profiler in development:

```sh
composer require --dev symfony/web-profiler-bundle symfony/stopwatch
```

The Phem panel shows each page request and
each event:

- the page, the handler and its source, and the outcome (rendered, redirect, reload, or a
  rate limit);
- the component tree, with the state of each component, its render time without its
  children, and the contexts that it provides and reads (with the provider);
- the passes of an event: the components that rendered before the handler, the components that
  rendered after it, the components that rendered only because a context above them changed
  (propagated), and the components that changed (the browser morphs them);
- the size of the signed state, and whether the state came from the state cache;
- the bound values, the browser events of the handler, and the warnings of the dev-mode check;
- the Doctrine transaction of the handler: commit or rollback, and its time;
- the Turbo Streams that the request pushed to the Mercure hub, with their topics, and the
  stale components that an event rendered again (see
  [Real-time updates](phpm-syntax.md#real-time-updates)).

The Performance panel shows the render passes, the handler and the transaction (`phem`
category). With the profiler on, the browser console shows each event with a link to its Phem
panel (`console.debug`). Without debug mode, Phem does not measure the renders.

Other commands:

```sh
php bin/console phem:warmup                          # compile the templates into the Twig cache again
php bin/console phem:render /examples/Counter        # print the HTML of a page (no server)
php bin/console debug:router                          # the routes of the pages and their events (phem_event.*)
```

## 5. Watch mode (development)

```sh
phemc watch src --exec "frankenphp run --config Caddyfile"
```

When a `.phpm` file changes, `phemc` rebuilds. If the build passes, it restarts FrankenPHP, so
the workers load the new components. If the build fails, it prints the diagnostics and the
previous server keeps running. Set `FRANKENPHP_WORKER_CONFIG="watch ./src/**/*.php"` to restart
the workers also when a PHP class changes.

## 6. Production

```sh
export APP_ENV=prod APP_DEBUG=0
composer install --no-dev --optimize-autoloader
phemc build src --no-warmup
rm -rf var/cache/prod && php bin/console cache:warmup
php bin/console asset-map:compile
```

In production, Symfony does not check the cache for changes. Build the cache again after each
`phemc build`: `cache:warmup` builds the container (with the component services), the routes,
and the Twig cache of the phemc templates. `asset-map:compile` writes the assets to
`public/assets/`. Set a random `APP_SECRET` (for example in `.env.local`).

Then enable the preload. `config/preload.php` loads the Symfony container and the compiled
components:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```
