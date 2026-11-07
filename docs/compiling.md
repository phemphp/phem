# Compiler reference: `phemc` commands, options and output

`phemc` is the Rust compiler that turns `.phem` files into PHP and Twig. This page lists what installing Phem sets up, the `phemc` commands (`build`, `check`, `watch`, `lsp`) with their options and defaults, the files the build writes, the diagnostic codes, the related Symfony console commands, the project layout that the compiler expects, and the production build sequence.

## Build pipeline

A Phem application is a Symfony application with `PhemBundle`, and it needs two build steps before it can serve a page:

1. `phemc build` compiles the `.phem` files to PHP and Twig, in `var/phem/`.
2. `bin/console phem:warmup` compiles the generated Twig templates into the Twig cache.

`phemc build` runs step 2 for you. At runtime, nothing parses a `.phem` or a `.twig` source.

## Installation and setup

`composer require phem/phem` installs the bundle, the client assets and the `vendor/bin/phemc` launcher, and the Symfony Flex recipe configures the application so that no Phem config file is needed.

### What Flex and `PhemBundle` configure

Flex and the bundle set up these parts of the application:

- **Bundle**: Flex enables `PhemBundle` in `config/bundles.php`
- **Client**: Flex adds the `phem` Stimulus controller and Turbo to the assets. The controller is the client of Phem: it sends the events to the server and updates the page (see [Client assets](#client-assets))
- **Starter files**: the recipe copies `src/Ui/Common/Document.phem` (the HTML document of the pages), `src/Ui/HomePage.phem` (the page at `/`) and `.symfony.local.yaml` (the `phemc watch src` worker of `symfony serve`)
- **Routes**: the `routing.controllers` resource of `config/routes.yaml` loads the routes of the `#[Page]` attributes, as it does for controllers, so the pages need no routes file (see [Route loading](phem-syntax.md#route-loading))
- **Preload**: in production, `config/preload.php` also preloads the compiled components without a change (see [OPcache preload](#opcache-preload))
- **Compiler**: `vendor/bin/phemc` downloads the `phemc` binary of the same version on its first run (see [The `phemc` binary](#the-phemc-binary))

The bundle defaults match this layout: `phemc` reads `src/` and writes to `var/phem/`, which is the `build_dir` default. You don’t need a `config/packages/phem.yaml` file.

### Flex recipe options

The recipe of `phem/phem` is in the contrib repository of Symfony Flex, so Flex asks before it runs it. When Flex skips the recipe (for example with `composer require --no-interaction`), allow the contrib recipes and install the recipe again:

```sh
composer config extra.symfony.allow-contrib true
composer recipes:install phem/phem --force
```

Flex records a skipped recipe as installed, so `--force` is required. `allow-contrib` applies to all contrib recipes, and Flex doesn’t ask again. Set it before `composer require phem/phem` to install with no prompt, for example in CI.

### Install without the `--webapp` skeleton

The `symfony new --webapp` skeleton already has AssetMapper. Without it, install AssetMapper together with `phem/phem`. `phem/phem` requires StimulusBundle, and Flex adds the `phem` controller to `assets/controllers.json`:

```sh
symfony new my-app
cd my-app
composer require symfony/asset-mapper phem/phem
```

### The `phemc` binary

`phemc` ships as a prebuilt binary for each release, and you get it through Composer or a standalone installer:

- **Composer**: `composer require phem/phem` installs `vendor/bin/phemc`. On its first run, this PHP script downloads the `phemc` binary of the installed `phem/phem` version from the GitHub release, checks its SHA-256 against `phemc-checksums.txt`, and stores it next to the script. `composer update` removes the old binary with the old version, so the compiler and the runtime always share a version
- **Standalone on Linux or macOS**: `install.sh` downloads the binary of the latest release to `$BIN_DIR` (the current directory by default)
- **Standalone on Windows**: `install.ps1` downloads the binary of the latest release to `$env:PHEMC_INSTALL` (`~\.phem` by default)

The prebuilt binaries cover Linux (`x86_64`, `aarch64`), macOS (`arm64`, `x86_64`) and Windows (`x86_64`). On another platform, or for a development version of `phem/phem` that has no release, build the binary from the sources:

```sh
cargo build --release --manifest-path compiler/Cargo.toml -p phemc-cli
```

`vendor/bin/phemc` reads the `PHEMC_DOWNLOAD_URL` environment variable as the base URL of the release assets (`https://github.com/phemphp/phem/releases/download` by default). The application also needs PHP 8.4 or later.

## `phemc` commands

Each command runs from the project root, the directory that contains `bin/console`. Paths in arguments and options are relative to it:

| Command | Purpose |
|---|---|
| `phemc build <src>` | Compiles the sources, writes the output directory and warms up the Twig cache |
| `phemc check <src>` | Reports the diagnostics and writes nothing |
| `phemc watch <src>` | Builds, then rebuilds on each change, and restarts an optional command |
| `phemc lsp` | Runs the language server on stdin and stdout |

`phemc --help` lists the commands, `phemc <command> --help` lists the options of one command, and `phemc --version` prints the version.

### `phemc build`

`phemc build` compiles the `.phem` files of the source directory and of the Composer packages, writes the output directory, and then runs the Twig warmup:

```sh
phemc build src
```

It prints a summary on stderr:

```text
phemc: compiled 15 file(s), 24 template(s), 10 page(s); 43 file(s) changed, 0 warning(s) in 51 ms
phem: compiled 24 template(s) into the Twig cache in 250 ms
```

The `<src>` argument is the source root of the application, for example `src`. The command takes these options:

| Option | Default | Effect |
|---|---|---|
| `--out <dir>` | `var/phem` | The output directory. Set the `phem.build_dir` option to the same path |
| `--php <bin>` | `php` | The PHP binary that runs the Twig warmup |
| `--no-warmup` | off | Skips `bin/console phem:warmup` after the build |

The build has these properties:

- **Incremental output**: it writes a file only when its content changed, so the Twig cache and OPcache stay valid. It deletes the output of a removed source file or a removed package
- **All or nothing**: when a file has an error, it prints the diagnostics and `phemc: build failed: 1 error(s), 0 warning(s)`, writes nothing, and exits with code 1
- **Composer packages**: it also compiles the `.phem` files of each installed package that sets `extra.phem.source` in its `composer.json` (see [Component packages](packages.md)). The summary then counts them: `compiled 42 file(s) (2 package(s)), …`
- **Warmup environment**: the warmup runs in the environment of `APP_ENV`. When the project has no `bin/console` or no `vendor/autoload.php`, the build skips the warmup and says so. A failed warmup exits with code 1

In debug mode (`APP_ENV=dev`), Symfony rebuilds the container and the routes when the manifest or a compiled page changes, so a new component, page or route doesn’t need `cache:clear`.

### Build output directory

The build writes the compiled application to `var/phem/` (or the `--out` directory):

```text
var/phem/
├── php/Ui/HomePage.php            the PHP code of HomePage.phem
├── php/vendor/acme/ui/…           the PHP code of a Composer package
├── templates/Ui/HomePage.0.twig   one Twig template for each markup literal
├── templates/vendor/acme/ui/…     the templates of a Composer package
├── manifest.php, manifest.json    components, pages and template line map
└── autoload.php                   requires all the compiled PHP files
var/cache/<env>/twig/              the Twig cache, filled by the warmup
```

Each markup literal becomes `new \Phem\Element('Ui/HomePage.0.twig', [...])` in the PHP code, and its template has the Twig name `@phemc/Ui/HomePage.0.twig`. The manifest lists each component (its kind, source, props, slots and `replaces` target), each page, and the `.phem` file and line of each template.

`var/phem/` isn’t a cache. It holds the compiled code of the application, it’s the same for all environments, and only `phemc build` writes it. `cache:clear` and `rm -rf var/cache` leave it in place. Don’t commit it.

The compiled PHP keeps the line numbers of the `.phem` file, and its first line names the source (`<?php /* compiled by phemc from src/Ui/Report/Tally.phem; do not edit */`). Because the manifest maps each template back to its `.phem` file and line, the Symfony error page and the console show `src/<file>.phem` (or `vendor/<package>/…` for a package) for an error in markup.

### `phemc check`

`phemc check` reports all the diagnostics of the application and its packages, writes nothing, and exits with code 1 when there is at least one error. Use it in continuous integration (CI):

```sh
phemc check src
```

| Option | Default | Effect |
|---|---|---|
| `--format <format>` | `text` | `text` prints the diagnostics on stderr; `json` prints them on stdout, for editors and other tools |

In the `text` format, each diagnostic shows its code, the `.phem` line and column, a label and a hint, and the command ends with `phemc: checked 15 file(s): 1 error(s), 0 warning(s)`:

```text
E011

  × `<Profile>` has no prop `persn`
    ╭─[src/Ui/Scientist/ScientistPage.phem:16:22]
 15 │         <div>
 16 │             <Profile persn={{ user }} />
    ·                      ─────
 17 │         </div>
    ╰────
  help: its props are: person
```

The `json` format keeps the same exit code:

```sh
phemc check src --format json
```

```json
{"files": [{"file": "src/Ui/Scientist/ScientistCard.phem", "diagnostics": [{"severity": "error",
  "code": "E010", "message": "unknown component `<Avatr>` (resolved to `App\\Ui\\Scientist\\Avatr`)",
  "label": null, "help": "declare `#[Component] function Avatr()` in a .phem file, or check the `use` imports",
  "start": {"line": 30, "character": 13}, "end": {"line": 30, "character": 18}}]}]}
```

The JSON fields follow the Language Server Protocol (LSP) conventions:

- **`file`**: the path from the project root. A file with no diagnostic isn’t in the list
- **`severity`**: `error` or `warning`
- **`label`**, **`help`**: the label of the span and the hint, or `null`
- **`start`**, **`end`**: `line` starts at 0, and `character` counts UTF-16 code units

Both formats leave out the warnings of a Composer package, because the application can’t fix them. The errors of a package stay in the list.

### `phemc watch`

`phemc watch` runs a build, then rebuilds each time a `.phem` file changes. With `--exec`, it also starts a command after each successful build and restarts it after the next one:

```sh
phemc watch src --exec "frankenphp run --config Caddyfile"
```

It takes the options of `phemc build` (`--out`, `--php`, `--no-warmup`) and one more:

| Option | Default | Effect |
|---|---|---|
| `--exec <cmd>` | none | The command to start after a successful build, and to restart after each later successful build. It runs with `sh -c` |

The watcher behaves as follows:

- **Watched files**: the `.phem` files of the source root and of each package root, and `vendor/composer/installed.json`. A change of `installed.json` (`composer require`, `composer update`) reads the package roots again and rebuilds
- **Debounce**: after a change, it waits until no change arrives for 150 ms, then builds once
- **Failed build**: it prints the diagnostics and `phemc: the previous build keeps running`. It doesn’t restart the command, so the previous server keeps serving the last good build. When the first build fails, the command doesn’t start until a build passes
- **Missing source root**: the command exits with an error. A wrong package root (a bad `extra.phem.source`) prints an error, and the watcher keeps watching the application and `installed.json` until you fix it

With PHP-FPM or the Symfony local web server, you don’t need `--exec`: the next request uses the new build. With FrankenPHP in worker mode, the workers keep the old components in memory, so pass the server command to `--exec`. Set `FRANKENPHP_WORKER_CONFIG="watch ./src/**/*.php"` to restart the workers when a PHP class changes too.

The Flex recipe of `phem/phem` adds `phemc watch` to `.symfony.local.yaml`, so `symfony serve` runs it as a worker. Run `symfony server:log` to see its diagnostics:

```yaml
workers:
    phemc:
        cmd: ['vendor/bin/phemc', 'watch', 'src']
```

### `phemc lsp`

`phemc lsp` runs the language server on stdin and stdout. It takes no option. The server reports the same diagnostics as `phemc check` while you type, and it adds completion, go to definition, hover and the outline of `.phem` files.

The VS Code and PhpStorm plugins bundle the same server compiled to WebAssembly, so they don’t need a `phemc` binary (see `editors/vscode/README.md` and `editors/phpstorm/README.md`). The Claude Code plugin runs the `vendor/bin/phemc lsp` of the application (see [Use Claude Code](#use-claude-code)).

The server also checks the `.phem` files of the Composer packages together with the application:

- **Package components**: completion, definition and hover work for package components in the files of the application
- **Package errors**: the server shows an error of a package file in that file, also at its real path for a package of a Composer path repository. Only the application that installs a package checks its files, so a linked package in the same workspace doesn’t check itself
- **Reloads**: the server reads the package roots and files once, then again after a Composer change, after a package file changes on disk, and when a source directory or a package root appears or goes away
- **Wrong package root**: when it can’t read a package root (a bad `extra.phem.source`), it shows the error once (and again after a Composer change), and checks only the files of the application

### Use Claude Code

The Phem plugin for [Claude Code](https://claude.com/claude-code) runs the language server of the application (`vendor/bin/phemc lsp`). After each edit of a `.phem` file, Claude Code gets the `phemc` errors and warnings and can correct them right away. Its `LSP` tool also gets go to definition, find references, hover and the outline. Install the plugin:

```sh
claude plugin marketplace add phemphp/phem
claude plugin install phem@phem
```

In a running session, run `/reload-plugins` after the install. The plugin runs the `vendor/bin/phemc` of each project that has one, so use it only in projects that you trust.

The server starts the first time Claude Code edits a `.phem` file. After each edit, the conversation shows a summary such as `Found 1 new diagnostic issues in 1 files`, and **Ctrl+O** shows the diagnostics. If the server doesn’t start, open `/plugin` and read the **Errors** tab. For more, see `editors/claude-code/README.md`.

## Diagnostic codes

Errors start with `E` and stop the build; warnings start with `W` and don’t. `phemc` reports these codes:

| Code | Meaning |
|---|---|
| E001–E006 | Syntax: an unclosed or mismatched tag, a void element that isn’t self-closed, more than one root, or an open tag other than `<?phem` (`<?php`, the old `<?phpm`, `<?phemx`) |
| E010–E015 | An unknown component (also a function without `#[Component]`, or the target of a `replaces`), an unknown or missing prop, children or a slot that the component doesn’t accept, a duplicate component (also a component named after a built-in component such as `Phem\Root`, or an enum that two files declare), or a required context with no provider above the component |
| E016, E017 | A replacement (`#[Component(replaces: ...)]`) that doesn’t accept a prop or slot of the original, requires one that the original doesn’t require, or declares a `Bound` prop of the original with another type. Also a replacement in a package, a second replacement of a component, a component that replaces itself, the replacement of a replacement, a page or a built-in component, a page that replaces a component, or a `replaces` value that isn’t `Name::class` or a string (`self::class` included) |
| E018 | A literal value of a backed enum prop that isn’t the value of a case (`variant="big"`, `level="03"`, an empty value or a bare attribute), when the enum is in a `.phem` file |
| E020 | A call to a hook function (`Phem\useState`, …). Phem keeps state in `#[Component]` classes |
| E030–E033 | An undefined variable in markup, markup in an unsupported expression, an invalid attribute, or an invalid `phem:bind` (not a `#[State]` property, not a form field, or a type that can’t take the value of the field) |
| E040–E046 | A class component without `render()`, a readonly `#[State]` property, a `#[Prop]` property that isn’t public or is static or `#[State]`, a second `#[Prop(rest: true)]` property or one whose type doesn’t accept an array, a `#[Context]` or `#[Provide]` type that isn’t one class, interface or enum (or a `#[Context]` property that is static, readonly, a prop or state), `#[Component]` on an abstract class, or `mount` or `render` passed as a handler (`onClick={{ mount }}`) |
| E050, E052, E053 | `export default` (not PHP; use `#[Page]`), the Symfony `#[Route]` on a function (use `#[Page]`) or on a class that isn’t a component, `#[Broadcast]` on something that isn’t a page or a component, on an error page or on a method of a page, or `#[Broadcast(handlers: false)]` on a page, a function component, a method, or a class with `#[Broadcast]` on a handler |
| E054, E055 | `#[Async]` on a function, or an `#[Async]` method that doesn’t declare `Promise` as its return type |
| E056 | A page (class or function) or an error page used as a tag |
| E057 | `#[BroadcastKey]` on a static property, on a constructor parameter, on a property that isn’t a `#[Prop]` or a `#[State]` in a component with `#[State]`, on a page, or in a component with no `#[Broadcast]` on it or on a handler |
| E058 | `#[Validated]` on a function, or on a handler of a component with no non-static `#[State]` property of type `Phem\Validation` (a property of a trait or a parent class counts), or a component with more than one `Validation` property |
| E059, E060 | `#[ErrorPage]` on a class (an error page is a function), or in a package (only the application has error pages). See [Error pages](error-pages.md) |
| W001–W003 | A handler that is called instead of passed, a list item without `key`, or an event that the browser doesn’t send (`onMouseEnter`) |
| W004 | `#[Page]`, `#[ErrorPage]`, `#[Route]`, `#[Component]`, `#[State]`, `#[Prop]`, `#[Path]`, `#[Context]`, `#[Provide]`, `#[Broadcast]`, `#[BroadcastKey]`, `#[Async]` or `#[Validated]` without its `use` import (`Phem\Attribute\...`, `Symfony\Component\Routing\Attribute\Route`) |
| W005 | A `<title>` outside `<head>` and `<svg>`. The document component prints the title, and a page sets it with `<Document:title>` |
| W006 | `phem:debounce` or `phem:throttle` on an element with no handler |
| W007 | `phem:live` on an element with no `phem:bind` |
| W008 | A `render()` or a function component that isn’t pure: it reads a constructor service, a static property, a superglobal, the clock or a random value (see [Keep `render()` pure](components.md#keep-render-pure)) |

## Static analysis (PHPStan)

PHPStan can’t read a `.phem` file, because markup isn’t PHP. Run it on the compiled PHP in `var/phem/php/` instead: that code is plain PHP with the same line numbers as the `.phem` file. Add the output directory to the PHPStan paths, and exclude the compiled packages, which your application can’t change:

```yaml
# phpstan.dist.neon
parameters:
    level: 8
    paths:
        - src
        - var/phem/php
    excludePaths:
        - var/phem/php/vendor
```

Run `phemc build src` first (`phemc build src --no-warmup` in CI). PHPStan reads only `.php` files, so it skips the `.phem` files in `src/`. For this `.phem` file:

```php
#[Component]
function Tally(array $items): Node {
    $n = count($items);
    return <p id="count">{{ n }}</p>;
}
```

`phemc` writes this PHP, on the same lines:

```php
#[Component]
function Tally(array $items): Node {
    $n = count($items);
    return new \Phem\Element('Ui/Report/Tally.0.twig', ['n' => $n]);
}
```

PHPStan reports the line of the `.phem` file in the path of the compiled file (here with `--error-format=raw`). Fix the error in the `.phem` file that the first line of the compiled file names, at the same line:

```text
var/phem/php/Ui/Report/Tally.php:12:Function App\Ui\Report\Tally() has parameter $items with no value type specified in iterable type array.
```

PHPStan doesn’t see two things:

- **Expressions in markup** (`{{ name == current }}`): they’re Twig. `phemc check` checks the variables, components and props in markup
- **Variables that can be undefined at the markup**: `phemc` writes them as `$name ?? null`, and the template gets `null`

`phemc` writes `$name` when the variable is always defined at the markup: a parameter, a loop variable inside its loop, or an assignment in a statement before the markup (also in each branch of an `if` with an `else`, or of a `try` and its `catch`). In other code, for example an alternative syntax block (`if (...): ... endif;`) or after a `goto` label, it writes `$name ?? null`. If PHPStan then reports `nullCoalesce.variable`, assign the variable in a statement before the markup.

php-cs-fixer, PHP_CodeSniffer and Rector don’t work on `.phem` files, and a change in the compiled PHP doesn’t go back to the source.

## Console commands

`PhemBundle` adds two Symfony console commands that work on the compiled output:

| Command | Effect |
|---|---|
| `bin/console phem:warmup` | Compiles the `phemc` templates into the Twig cache. `phemc build` runs it, unless you pass `--no-warmup` |
| `bin/console phem:render [path]` | Prints the HTML of the page at `path` (`/` by default) without a server. It exits with code 1 when the response isn’t successful, and doesn’t catch errors, so the console shows them with the `.phem` location |

`bin/console debug:router` lists the routes of the pages and of their events (`phem_event.*`).

## Build directory configuration

The compiler reads no configuration file: its options are the command-line options above. The bundle reads the output with the `build_dir` option, which must match `--out`:

```yaml
# config/packages/phem.yaml
phem:
    build_dir: '%kernel.project_dir%/var/phem'
```

`build_dir` defaults to `%kernel.project_dir%/var/phem`, the same directory as the `--out` default. Set both when you move the output.

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

These directories are a convention. A page is any class with the `#[Page]` attribute and a `render()` method, or any function with the `#[Page]` attribute, in any `.phem` file under the source root. For the document component and its `<Root>`, see [The document of the pages](phem-syntax.md#the-document-of-the-pages). For the routes file, see [Route loading](phem-syntax.md#route-loading).

### Organize the UI

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
            <Document>
                <Document:title>{{ scientist.name }}</Document:title>
                <ScientistCard scientist={{ scientist }} />
            </Document>
        );
    }
}
```

When a second context starts to use a component of a page, move the component to `src/Ui/Common/`.

### Client assets

The client of Phem is the `phem` Stimulus controller and the `phem-c{display:contents}` CSS rule, which styles the `<phem-c>` element around a component that doesn’t render one element. The bundle ships them as a Symfony UX package (`assets/package.json`), and AssetMapper exposes them in the `phemjs` namespace. The controller imports `@hotwired/stimulus` and `@hotwired/turbo` from the importmap of the application, so the page loads one Turbo and one Stimulus.

`phem/phem` requires StimulusBundle and AssetMapper (see [Install without the `--webapp` skeleton](#install-without-the---webapp-skeleton)). Symfony Flex adds `@hotwired/stimulus` and `@hotwired/turbo` to `importmap.php`, and enables the controller in `assets/controllers.json`:

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

The package also has optional controllers (`phem-optimistic`, `phem-toggle`, `phem-dialog`, `phem-tabs`, `phem-toast`, `phem-clipboard`, `phem-autofocus` and `phem-confirm`), which are off by default. Enable one with its key in the `@phem/phem` entry, for example `"toggle": { "enabled": true, "fetch": "lazy" }`. See [Built-in controllers](client-side.md#enable-the-built-in-controllers).

## Serve the compiled application

The compiled application runs as any Symfony application, with FrankenPHP in worker mode or with PHP-FPM. With FrankenPHP, run this in the project directory:

```sh
frankenphp run --config Caddyfile
```

The server setup has these parts:

- **Front controller**: `public/index.php` uses Symfony Runtime. In a FrankenPHP worker, Symfony Runtime boots the kernel once and passes it many requests, and the kernel resets its services (`kernel.reset`) between requests. With PHP-FPM, use `public/index.php` as the front controller
- **Static files**: Caddy serves the files of `public/` at `/`, and a directory serves its `index.html`. In development, Symfony serves the AssetMapper assets at `/assets/`
- **Worker options**: `FRANKENPHP_WORKER_CONFIG` adds options to the worker. For example, `watch ./src/**/*.php` restarts the workers when a PHP class changes
- **Environment**: `APP_ENV=dev`, the default in `.env`, shows the Symfony error pages with the `.phem` location
- **Database**: the application expects the database to exist. Create it before the first run, for example with `bin/console doctrine:schema:create` and `bin/console doctrine:fixtures:load`. In Docker, run these commands in the entrypoint, only when the database doesn’t exist yet

For a watch loop that rebuilds and restarts the server, see [`phemc watch`](#phemc-watch).

### Symfony profiler panel

In debug mode, Phem adds a panel to the Symfony profiler. Install the profiler in development:

```sh
composer require --dev symfony/web-profiler-bundle symfony/stopwatch
```

The Phem panel shows each page request and each event:

- **Request**: the page, the handler and its source, and the outcome (rendered, redirect, reload or rate limit)
- **Component tree**: the state of each component, its render time without its children, and the contexts that it provides and reads, with the provider
- **Event passes**: the components that rendered before the handler, the ones that rendered after it, the ones that rendered only because a context above them changed (propagated), and the ones that changed, which the browser morphs
- **State**: the size of the signed state, and whether it came from the state cache
- **Inputs and outputs**: the bound values, the browser events of the handler, and the warnings of the dev-mode check
- **Transaction**: the Doctrine transaction of the handler, its commit or rollback, and its time
- **Real-time updates**: the Turbo Streams that the request pushed to the Mercure hub with their topics, and the stale components that an event rendered again (see [Real-time updates](real-time-updates.md))

The Performance panel shows the render passes, the handler and the transaction in the `phem` category. With the profiler on, the browser console logs each event with a link to its Phem panel (`console.debug`). Without debug mode, Phem doesn’t measure the renders.

## Production build

In production, Symfony doesn’t check its cache for changes, so rebuild the cache after each `phemc build`. Run this sequence:

```sh
export APP_ENV=prod APP_DEBUG=0
composer install --no-dev --optimize-autoloader
phemc build src --no-warmup
rm -rf var/cache/prod && php bin/console cache:warmup
php bin/console asset-map:compile
```

Each step has one job:

- **`phemc build src --no-warmup`**: compiles the sources. `cache:warmup` runs the Twig warmup later, so the build skips it
- **`cache:warmup`**: builds the container (with the component services), the routes, the Twig cache of the `phemc` templates, and the OPcache preload file of the components
- **`asset-map:compile`**: writes the assets to `public/assets/`

Set a random `APP_SECRET`, for example in `.env.local`. It signs the component state in the browser (the `phem.secret` option defaults to `%kernel.secret%`).

### OPcache preload

`config/preload.php` loads the preload file of the Symfony container. `cache:warmup` writes `phem-preload.php` in the kernel build directory (`var/cache/prod/` by default), and Symfony adds it to that file, so `config/preload.php` needs no change. Enable the preload in `php.ini`:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```
