# Compiling `.rhp` files

A `.rhp` project is a Symfony application with `RephpBundle`. It needs two build steps before
it can run:

1. `rhpc build` (Rust) compiles the `.rhp` files to PHP and Twig.
2. `bin/console rephp:warmup` (PHP) compiles the Twig templates into the Twig cache.

`rhpc build` runs step 2 for you. At runtime, nothing parses `.rhp` or `.twig` sources.

## Project layout

```
my-app/
├── composer.json          requires rephp/runtime (and symfony/yaml, symfony/dotenv)
├── .env                   APP_ENV, APP_SECRET (it signs the component state in the browser)
├── bin/console
├── config/
│   ├── bundles.php        FrameworkBundle, TwigBundle, RephpBundle
│   ├── packages/rephp.yaml   optional: source_dir, build_dir, secret
│   ├── routes/rephp.yaml     { resource: ., type: rephp }: a route for each #[Page]
│   └── services.yaml      your services (for example repositories)
├── src/                   the .rhp sources (the "source root"), and your PHP classes
│   ├── Kernel.php
│   ├── Repository/        normal PHP services, injected into components
│   ├── components/        components that are not pages
│   └── pages/             the #[Page] component of each file is a route
│       ├── index.rhp      → /
│       └── examples/
│           └── Counter.rhp → /examples/Counter
├── templates/bundles/RephpBundle/page.html.twig   optional: override the page document
├── public/                static files, index.php (PHP-FPM, FrankenPHP)
└── var/cache/             build output (do not commit)
```

The page document is `@Rephp/page.html.twig`. To add a stylesheet, override it:

```twig
{% extends '@!Rephp/page.html.twig' %}

{% block stylesheets %}<link rel="stylesheet" href="/app.css">{% endblock %}
```

## 1. Get the tools

With Docker (recommended), the `dev` image has PHP 8.6, Composer, and the Rust toolchain:

```sh
docker compose build dev
docker compose run --rm dev sh      # a shell; `rhpc` is on the PATH
```

Without Docker, you need PHP 8.6 and Rust:

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
rhpc: compiled 15 file(s), 24 template(s), 10 route(s); 43 file(s) changed, 0 warning(s) in 51 ms
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
container and the routes again when the manifest changes: a new component or page needs no
`cache:clear`.

### What the build writes

```
var/cache/rhp/
├── php/pages/index.php          the PHP code; each markup literal becomes new \Rephp\Element('pages/index.0.twig', [...])
├── templates/pages/index.0.twig one Twig template for each markup literal (Twig name: @rhp/pages/index.0.twig)
├── manifest.php / manifest.json components (kind, props, slots), routes, template → .rhp line map
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
    ╭─[src/pages/App.rhp:16:22]
 15 │         <div>
 16 │             <Profile persn={{ user }} />
    ·                      ─────
 17 │         </div>
    ╰────
  help: its props are: person
```

The README has the list of codes.

## 4. Run

On the Io\Poll server (one long-running process; the kernel resets the services between
requests):

```sh
php bin/console rephp:serve --host=0.0.0.0 --port=8080 --mount=/examples-src/=src/pages/examples
```

- `public/` is served at `/` (PHP files in it are not served). The runtime assets are in
  `public/bundles/rephp/` (`bin/console assets:install public`).
- `--mount=/url/=dir` serves one more static directory (repeat the option for more).
  The quickstart uses it to show the example sources in the sandboxes.
- `APP_ENV=dev` (the default in `.env`) shows the Symfony error pages, with the `.rhp` location.

With PHP-FPM or FrankenPHP, use `public/index.php` as the front controller, as for any Symfony
application.

Other commands:

```sh
php bin/console rephp:warmup                          # compile the templates into the Twig cache again
php bin/console rephp:render /examples/CounterTogether   # print the HTML of a page (no server)
php bin/console debug:router                          # the rephp_page_* routes and rephp_event
```

## 5. Watch mode (development)

```sh
rhpc watch src --exec "php bin/console rephp:serve --port=8080"
```

When a `.rhp` file changes, `rhpc` rebuilds. If the build passes, it restarts the server.
If the build fails, it prints the diagnostics and the previous server keeps running.
`make up` runs this in Docker for the quickstart.

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
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```

`make prod` builds and runs the production image on http://localhost:8081. The image builds
`rhpc` in a Rust stage, compiles the project and the caches at image build time, and
preloads the compiled components and templates.
