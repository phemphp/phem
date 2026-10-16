# Compiling `.rhp` files

A `.rhp` project is a Symfony application with `RephpBundle`. It needs two build steps before
it can run:

1. `rhpc build` (Rust) compiles the `.rhp` files to PHP and Twig.
2. `bin/console rephp:warmup` (PHP) compiles the Twig templates into the Twig cache.

`rhpc build` runs step 2 for you. At runtime, nothing parses `.rhp` or `.twig` sources.

## Project layout

```
my-app/
├── composer.json          requires rephp/runtime (and symfony/runtime, symfony/yaml, symfony/dotenv)
├── .env                   APP_ENV, APP_SECRET (it signs the component state in the browser)
├── Caddyfile              FrankenPHP: public/index.php in worker mode, and the static files
├── bin/console
├── config/
│   ├── bundles.php        FrameworkBundle, TwigBundle, RephpBundle (and DoctrineBundle for #[Transactional] and #[Path] entities)
│   ├── packages/rephp.yaml   optional: source_dir, build_dir, secret
│   ├── routes/rephp.yaml     { resource: ., type: rephp }: the #[Route] attributes of the pages
│   └── services.yaml      your services (for example repositories)
├── src/                   the .rhp sources (the "source root"), and your PHP classes
│   ├── Kernel.php
│   ├── Model/             optional: Doctrine entities
│   ├── Repository/        normal PHP services, injected into components
│   ├── DataFixtures/      optional: Doctrine fixtures, the first rows of a new database
│   ├── Components/        components that are not pages
│   └── Pages/             the components with a #[Route] attribute
│       ├── index.rhp      #[Route('/')]
│       └── Examples/
│           └── Counter.rhp #[Route('/examples/Counter')]
├── templates/bundles/RephpBundle/page.html.twig   optional: override the page document
├── public/                static files, index.php (Symfony Runtime: FrankenPHP worker mode, PHP-FPM)
└── var/cache/             build output (do not commit)
```

The directories are only a convention: a page is a component with the Symfony `#[Route]`
attribute, in any file. The page document is `@Rephp/page.html.twig`. To add a stylesheet, override it:

```twig
{% extends '@!Rephp/page.html.twig' %}

{% block stylesheets %}<link rel="stylesheet" href="/app.css">{% endblock %}
```

## 1. Get the tools

With Docker (recommended), the `dev` image has FrankenPHP (PHP 8.5), Composer, and the Rust
toolchain:

```sh
docker compose build dev
docker compose run --rm dev sh      # a shell; `rhpc` is on the PATH
```

Without Docker, you need PHP 8.5, [FrankenPHP](https://frankenphp.dev), and Rust:

```sh
cargo build --release --manifest-path compiler/Cargo.toml -p rhpc-cli
export PATH="$PWD/compiler/target/release:$PATH"
cd examples/quickstart && composer install   # also runs assets:install (public/bundles/rephp/)
```

## 2. Build

Run the commands in the project directory (the directory that contains `bin/console`).

```sh
rhpc build src
```

Output:

```
rhpc: compiled 15 file(s), 24 template(s), 10 page(s); 43 file(s) changed, 0 warning(s) in 51 ms
rephp: compiled 24 template(s) into the Twig cache in 250 ms
```

The build is incremental: it writes only the files that changed, so the Twig cache and
OPcache stay valid. If there is an error, it writes nothing and exits with code 1.

| Option | Default | Meaning |
|---|---|---|
| `--out <dir>` | `var/cache/rhp` | The output directory (the same as the `rephp.build_dir` option) |
| `--php <bin>` | `php` | The PHP binary for the Twig warmup |
| `--no-warmup` | off | Do not run `bin/console rephp:warmup` |

The warmup uses the environment of `APP_ENV`. In debug mode (`dev`), Symfony builds the
container and the routes again when the manifest or a compiled page changes: a new component,
page, or route needs no `cache:clear`.

### What the build writes

```
var/cache/rhp/
├── php/Pages/index.php          the PHP code; each markup literal becomes new \Rephp\Element('Pages/index.0.twig', [...])
├── templates/Pages/index.0.twig one Twig template for each markup literal (Twig name: @rhp/Pages/index.0.twig)
├── manifest.php / manifest.json components (kind, props, slots), pages, template → .rhp line map
├── autoload.php                 requires all compiled PHP files
└── preload.php                  for opcache.preload (production)
var/cache/<env>/twig/            the Twig cache (filled by the warmup)
```

The compiled PHP keeps the line numbers of the `.rhp` file. The manifest maps each template
back to its `.rhp` line, so the Symfony error page and the console show `src/<file>.rhp` and
its line for an error in markup.

## 3. Check only (CI)

```sh
rhpc check src
```

It reports all diagnostics, writes nothing, and exits with code 1 if there is an error.
Each diagnostic shows the `.rhp` line and column, a label, and a hint:

```
E011

  × `<Profile>` has no prop `persn`
    ╭─[src/Pages/App.rhp:16:22]
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
rhpc check src --format json
```

```json
{"files": [{"file": "Components/ScientistCard.rhp", "diagnostics": [{"severity": "error",
  "code": "E010", "message": "unknown component `<Avatr>` (resolved to `App\\Components\\Avatr`)",
  "label": null, "help": "declare `function Avatr()` in a .rhp file, or check the `use` imports",
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
  The runtime assets are in `public/bundles/rephp/` (`bin/console assets:install public`).
- A `handle_path` block serves one more static directory. The quickstart uses it to show the
  example sources in the sandboxes (`/examples-src/`, `/components-src/`, `/repository-src/`).
- `FRANKENPHP_WORKER_CONFIG` adds options to the worker. For example, `watch ./src/**/*.php`
  restarts the workers when a PHP class changes.
- `APP_ENV=dev` (the default in `.env`) shows the Symfony error pages, with the `.rhp` location.
- The application assumes that the database exists. Make it before the first run, for example
  `bin/console doctrine:schema:create` and `bin/console doctrine:fixtures:load`. In Docker, the
  quickstart entrypoints run `docker/init-db.sh` for this. The script changes nothing when the
  database already exists.

With PHP-FPM, use `public/index.php` as the front controller, as for any Symfony application.

Other commands:

```sh
php bin/console rephp:warmup                          # compile the templates into the Twig cache again
php bin/console rephp:render /examples/CounterTogether   # print the HTML of a page (no server)
php bin/console debug:router                          # the routes of the pages and rephp_event
```

## 5. Watch mode (development)

```sh
rhpc watch src --exec "frankenphp run --config Caddyfile"
```

When a `.rhp` file changes, `rhpc` rebuilds. If the build passes, it restarts FrankenPHP, so
the workers load the new components. If the build fails, it prints the diagnostics and the
previous server keeps running. `make up` runs this in Docker for the quickstart, with
`FRANKENPHP_WORKER_CONFIG="watch ./src/**/*.php"`.

## 6. Production

```sh
export APP_ENV=prod APP_DEBUG=0
composer install --no-dev --optimize-autoloader
rhpc build src --no-warmup
rm -rf var/cache/prod && php bin/console cache:warmup
```

In production, Symfony does not check the cache for changes. Build the cache again after each
`rhpc build`: `cache:warmup` builds the container (with the component services), the routes,
and the Twig cache of the rhpc templates. Set a random `APP_SECRET` (for example in
`.env.local`).

Then enable the preload (see `docker/php-prod.ini`). `config/preload.php` loads the Symfony
container and the compiled components:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```

`make prod` builds and runs the production image on http://localhost:8081. The image is based
on the FrankenPHP image. It builds `rhpc` in a Rust stage, compiles the project and the caches
at image build time, preloads the compiled components and templates, and runs
`frankenphp run --config Caddyfile` (worker mode). On each start, its entrypoint runs
`docker/init-db.sh`: a new volume gets the schema and the fixtures.
