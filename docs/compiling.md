# Compiling `.phpm` files

A `.phpm` project is a Symfony application with `MamuBundle`. It needs two build steps before
it can run:

1. `mamuc build` (Rust) compiles the `.phpm` files to PHP and Twig.
2. `bin/console mamu:warmup` (PHP) compiles the Twig templates into the Twig cache.

`mamuc build` runs step 2 for you. At runtime, nothing parses `.phpm` or `.twig` sources.

## Project layout

```
my-app/
├── composer.json          requires mamuphp/runtime, symfony/asset-mapper, symfony/stimulus-bundle (and symfony/runtime, symfony/yaml, symfony/dotenv)
├── .env                   APP_ENV, APP_SECRET (it signs the component state in the browser)
├── Caddyfile              FrankenPHP: public/index.php in worker mode, and the static files
├── bin/console
├── config/
│   ├── bundles.php        FrameworkBundle, TwigBundle, StimulusBundle, MamuBundle (and DoctrineBundle for #[Transactional] and #[Path] entities)
│   ├── packages/mamu.yaml   optional: source_dir, build_dir, secret
│   ├── packages/asset_mapper.yaml   the assets/ directory
│   ├── routes/mamu.yaml     { resource: ., type: mamu }: the #[Page] attributes of the pages
│   └── services.yaml      your services (for example repositories)
├── src/                   the .phpm sources (the "source root"), and your PHP classes
│   ├── Kernel.php
│   ├── Model/             optional: Doctrine entities
│   ├── Repository/        normal PHP services, injected into components
│   ├── DataFixtures/      optional: Doctrine fixtures, the first rows of a new database
│   ├── Components/        components that are not pages
│   └── Pages/             the pages: classes with a #[Page] attribute
│       ├── Document.phpm   the HTML document: each page extends it (Mamu\Document)
│       ├── index.phpm      #[Page('/')]
│       └── Examples/
│           └── Counter.phpm #[Page('/examples/Counter')]
├── assets/                the JavaScript and CSS of the pages (AssetMapper)
│   ├── app.js             the `app` entry of the importmap: bootstrap.js and styles/app.css
│   ├── bootstrap.js       starts Stimulus (StimulusBundle)
│   └── controllers.json   enables the `mamu` controller of mamuphp/runtime
├── importmap.php          app, @hotwired/stimulus, @hotwired/turbo, @symfony/stimulus-bundle
├── public/                static files, index.php (Symfony Runtime: FrankenPHP worker mode, PHP-FPM)
└── var/cache/             build output (do not commit)
```

The directories are only a convention: a page is a class with the `#[Page]` attribute that
extends the document, in any file.

### The document of the pages

The application owns the HTML document: a class in the .phpm sources that implements
`Mamu\Document`. Its `document()` method gets the rendered page (`Mamu\Http\PageView`), and it
returns the `<html>` element. Mamu adds the `<!DOCTYPE html>`.

```php
<?phpm

namespace App\Pages;

use Mamu\Http\PageView;

class Document implements \Mamu\Document
{
    protected string $title = 'My app';

    public function title(): string
    {
        return $this->title;
    }

    public function document(PageView $page)
    {
        $title = $this->title();

        return (
            <html lang="en">
                <head>
                    <title>{{ title }}</title>
                    {{ importmap('app') }}
                </head>
                <body>{{ mamu_root(page) }}</body>
            </html>
        );
    }
}
```

Each page extends it. A page changes the title with the `$title` property, or with a `title()`
method for a dynamic title:

```php
#[Page('/scientists/{id:scientist}')]
final class ScientistPage extends Document
{
    #[Path] public Scientist $scientist;

    public function title(): string
    {
        return $this->scientist->name;
    }

    public function render() { ... }
}
```

For another layout (an admin area, for example), write a subclass of the document that
changes `document()`, and make the pages of that area extend it. A page that does not
implement `Mamu\Document` is an error when Symfony loads the routes.

The document renders once, after the page, with the state of the page. The events do not
render it again. A `<title>` in the markup of a page is not the title of the page: mamuc
reports it (W005). A `<title>` in `<head>` or in an `<svg>` is correct.

The document prints the page with these functions:

| In the document | Gives |
|---|---|
| `{{ mamu_root(page) }}` | the root element: `<div id="mamu-root" data-controller="mamu" …>` with the page |
| `{{ mamu_root(page, {class: 'app'}, 'main') }}` | the same, with more attributes and another tag |

The root element has the attributes of the `mamu` Stimulus controller: they give the component
state to the controller. Your `data-controller` and `data-action` values come after the values
of Mamu. You cannot change the state attributes.

A controller of the application can render a page in a Twig template too, with the
`Mamu\Http\PageRenderer` service:
`$this->render('page.html.twig', ['page' => $pages->render(Counter::class, $request)])`. In
Twig, `<body {{ mamu_root_attributes(page) }}>{{ page.body }}</body>` gives only the
attributes, for your own root element. The browser sends the events of a page to the URL of
the page, and only a page route runs them. Thus, render a page with event handlers on its page
route.

### The client assets

The client of Mamu is the `mamu` Stimulus controller, and the `mamu-c{display:contents}` CSS. The
bundle gives them as a Symfony UX package (`runtime/assets/package.json`). With AssetMapper,
they are in the `mamujs` namespace. The controller imports `@hotwired/stimulus` and
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
        "@mamuphp/runtime": {
            "mamu": { "enabled": true, "fetch": "eager", "autoimport": { "@mamuphp/runtime/dist/mamu.css": true } }
        }
    },
    "entrypoints": []
}
```

Without StimulusBundle, add `'mamujs' => ['path' => 'mamujs/mamu_controller.js']`
to `importmap.php`, register the controller (`application.register('mamu', MamuController)`),
and import `mamujs/mamu.css`.

## 1. Get the tools

With Docker (recommended), the `dev` image has FrankenPHP (PHP 8.5), Composer, and the Rust
toolchain:

```sh
docker compose build dev
docker compose run --rm dev sh      # a shell; `mamuc` is on the PATH
```

Without Docker, you need PHP 8.5, [FrankenPHP](https://frankenphp.dev), and Rust:

```sh
cargo build --release --manifest-path compiler/Cargo.toml -p mamuc-cli
export PATH="$PWD/compiler/target/release:$PATH"
cd examples/quickstart && composer install   # also runs importmap:install (assets/vendor/)
```

## 2. Build

Run the commands in the project directory (the directory that contains `bin/console`).

```sh
mamuc build src
```

Output:

```
mamuc: compiled 15 file(s), 24 template(s), 10 page(s); 43 file(s) changed, 0 warning(s) in 51 ms
mamu: compiled 24 template(s) into the Twig cache in 250 ms
```

The build is incremental: it writes only the files that changed, so the Twig cache and
OPcache stay valid. If there is an error, it writes nothing and exits with code 1.

| Option | Default | Meaning |
|---|---|---|
| `--out <dir>` | `var/cache/mamu` | The output directory (the same as the `mamu.build_dir` option) |
| `--php <bin>` | `php` | The PHP binary for the Twig warmup |
| `--no-warmup` | off | Do not run `bin/console mamu:warmup` |

The warmup uses the environment of `APP_ENV`. In debug mode (`dev`), Symfony builds the
container and the routes again when the manifest or a compiled page changes: a new component,
page, or route needs no `cache:clear`.

### What the build writes

```
var/cache/mamu/
├── php/Pages/index.php          the PHP code; each markup literal becomes new \Mamu\Element('Pages/index.0.twig', [...])
├── templates/Pages/index.0.twig one Twig template for each markup literal (Twig name: @phpm/Pages/index.0.twig)
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
mamuc check src
```

It reports all diagnostics, writes nothing, and exits with code 1 if there is an error.
Each diagnostic shows the `.phpm` line and column, a label, and a hint:

```
E011

  × `<Profile>` has no prop `persn`
    ╭─[src/Pages/App.phpm:16:22]
 15 │         <div>
 16 │             <Profile persn={{ user }} />
    ·                      ─────
 17 │         </div>
    ╰────
  help: its props are: person
```

The README has the list of codes.

For editors and other tools, `--format json` prints the diagnostics to stdout as JSON. The exit
code does not change:

```sh
mamuc check src --format json
```

```json
{"files": [{"file": "Components/ScientistCard.phpm", "diagnostics": [{"severity": "error",
  "code": "E010", "message": "unknown component `<Avatr>` (resolved to `App\\Components\\Avatr`)",
  "label": null, "help": "declare `function Avatr()` in a .phpm file, or check the `use` imports",
  "start": {"line": 30, "character": 13}, "end": {"line": 30, "character": 18}}]}]}
```

`file` is relative to the source directory. `line` starts at 0, and `character` counts UTF-16
code units, as the Language Server Protocol does. The VS Code extension uses this output (see
`editors/vscode/README.md`).

## 4. Run

With FrankenPHP in worker mode, in the project directory:

```sh
frankenphp run --config Caddyfile
```

- `public/index.php` uses Symfony Runtime. In a FrankenPHP worker, Symfony Runtime boots the
  kernel once and gives it many requests. The kernel resets the services (`kernel.reset`)
  between requests.
- Caddy serves the static files of `public/` at `/`. A directory serves its `index.html`.
  In development, Symfony serves the AssetMapper assets at `/assets/`. The quickstart also
  serves `assets/vendor/` at `/vendor/`, for the Stimulus controllers of its static pages.
- A `handle_path` block serves one more static directory. The quickstart uses it to show the
  example sources in the sandboxes (`/examples-src/`, `/components-src/`, `/repository-src/`).
- `FRANKENPHP_WORKER_CONFIG` adds options to the worker. For example, `watch ./src/**/*.php`
  restarts the workers when a PHP class changes.
- `APP_ENV=dev` (the default in `.env`) shows the Symfony error pages, with the `.phpm` location.
- The application assumes that the database exists. Make it before the first run, for example
  `bin/console doctrine:schema:create` and `bin/console doctrine:fixtures:load`. In Docker, the
  quickstart entrypoints run `docker/init-db.sh` for this. The script changes nothing when the
  database already exists.

With PHP-FPM, use `public/index.php` as the front controller, as for any Symfony application.

Other commands:

```sh
php bin/console mamu:warmup                          # compile the templates into the Twig cache again
php bin/console mamu:render /examples/CounterTogether   # print the HTML of a page (no server)
php bin/console debug:router                          # the routes of the pages and their events (mamu_event.*)
```

## 5. Watch mode (development)

```sh
mamuc watch src --exec "frankenphp run --config Caddyfile"
```

When a `.phpm` file changes, `mamuc` rebuilds. If the build passes, it restarts FrankenPHP, so
the workers load the new components. If the build fails, it prints the diagnostics and the
previous server keeps running. `make up` runs this in Docker for the quickstart, with
`FRANKENPHP_WORKER_CONFIG="watch ./src/**/*.php"`.

## 6. Production

```sh
export APP_ENV=prod APP_DEBUG=0
composer install --no-dev --optimize-autoloader
mamuc build src --no-warmup
rm -rf var/cache/prod && php bin/console cache:warmup
php bin/console asset-map:compile
```

In production, Symfony does not check the cache for changes. Build the cache again after each
`mamuc build`: `cache:warmup` builds the container (with the component services), the routes,
and the Twig cache of the mamuc templates. `asset-map:compile` writes the assets to
`public/assets/`. Set a random `APP_SECRET` (for example in `.env.local`).

Then enable the preload (see `docker/php-prod.ini`). `config/preload.php` loads the Symfony
container and the compiled components:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```

`make prod` builds and runs the production image on http://localhost:8081. The image is based
on the FrankenPHP image. It builds `mamuc` in a Rust stage, compiles the project and the caches
at image build time, preloads the compiled components and templates, and runs
`frankenphp run --config Caddyfile` (worker mode). On each start, its entrypoint runs
`docker/init-db.sh`: a new volume gets the schema and the fixtures.
