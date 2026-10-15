# Compiling `.rhp` files

A `.rhp` project needs two build steps before it can run:

1. `rhpc build` (Rust) compiles the `.rhp` files to PHP and Twig.
2. `rephp warmup` (PHP) compiles the Twig templates into the Twig cache.

`rhpc build` runs step 2 for you. At runtime, nothing parses `.rhp` or `.twig` sources.

## Project layout

```
my-app/
├── composer.json          requires rephp/runtime
├── src/                   the .rhp sources (the "source root")
│   └── pages/             the #[Page] component of each file is a route
│       ├── index.rhp      → /
│       └── examples/
│           └── Counter.rhp → /examples/Counter
├── public/                static files (app.css is linked automatically)
└── var/cache/             build output (do not commit)
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
cd examples/quickstart && composer install
```

## 2. Build

Run the commands in the project directory (the directory that contains `vendor/`).

```sh
rhpc build src
```

Output:

```
rhpc: compiled 8 file(s), 15 template(s), 8 route(s); 27 file(s) changed, 0 warning(s) in 24 ms
rephp: compiled 15 template(s) into the Twig cache in 55 ms
```

The build is incremental: it writes only the files that changed, so the Twig cache and
OPcache stay valid. If there is an error, it writes nothing and exits with code 1.

| Option | Default | Meaning |
|---|---|---|
| `--out <dir>` | `var/cache/rhp` | The output directory |
| `--prod` | off | A production build: Twig does not check templates for changes |
| `--php <bin>` | `php` | The PHP binary for the Twig warmup |
| `--no-warmup` | off | Do not run `vendor/bin/rephp warmup` |

### What the build writes

```
var/cache/rhp/
├── php/pages/index.php          the PHP code; each markup literal becomes \Rephp\Rt::render('pages/index.0.twig', [...])
├── templates/pages/index.0.twig one Twig template for each markup literal
├── manifest.php / manifest.json components (kind, props, slots), routes, template → .rhp line map
├── autoload.php                 requires all compiled PHP files
└── preload.php                  for opcache.preload (production)
var/cache/twig/                  the Twig cache (filled by the warmup)
var/cache/secret                 the key that signs the browser state (or set RHP_SECRET)
```

The compiled PHP keeps the line numbers of the `.rhp` file. The manifest maps each template
back to its `.rhp` line, so runtime errors point to `src/<file>.rhp:<line>`.

## 3. Check only (CI)

```sh
rhpc check src
```

It reports all diagnostics, writes nothing, and exits with code 1 if there is an error.
Each diagnostic shows the `.rhp` line and column, a label, and a hint:

```
E020

  × hook `useState` is called conditionally
   ╭─[src/pages/App.rhp:4:23]
 4 │     if ($ok) { [$a] = useState(0); }
   ·                       ────┬───
   ·                           ╰── inside a condition, a loop, or a try block
   ╰────
  help: hooks must run in the same order on each render; move the call to the
        top level, or extract a component
```

The README has the list of codes.

## 4. Run

```sh
php vendor/bin/rephp serve --host=0.0.0.0 --port=8080 --mount=/examples-src/=src/pages/examples --mount=/components-src/=src/components --mount=/data-src/=src/data
```

- `--mount=/url/=dir` serves one more static directory (repeat the option for more).
  The quickstart uses it to show the example sources in the sandboxes.
- `public/` is served at `/`, and the runtime assets are served at `/_rhp/assets/`.
- Set `RHP_DEBUG=1` for error pages with the `.rhp` location.

Other runtime commands:

```sh
php vendor/bin/rephp warmup          # compile the templates into the Twig cache again
php vendor/bin/rephp render /examples/CounterTogether   # print the HTML of a page (no server)
```

## 5. Watch mode (development)

```sh
rhpc watch src --exec "php vendor/bin/rephp serve --port=8080"
```

When a `.rhp` file changes, `rhpc` rebuilds. If the build passes, it restarts the server.
If the build fails, it prints the diagnostics and the previous server keeps running.
`make up` runs this in Docker for the quickstart.

## 6. Production

```sh
rhpc build src --prod
```

Then enable the preload (see `docker/php-prod.ini`):

```ini
opcache.enable_cli=1
opcache.validate_timestamps=0
opcache.preload=/app/var/cache/rhp/preload.php
opcache.preload_user=root
```

`make prod` builds and runs the production image on http://localhost:8081. The image builds
`rhpc` in a Rust stage, compiles the project and the Twig cache at image build time, and
preloads the compiled components and templates.
