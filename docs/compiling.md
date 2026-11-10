# Compiler reference: `phemc` commands, options and output

`phemc` is the Rust compiler that turns `.phem` files into PHP and Twig. This page lists the `phemc` commands (`build`, `check`, `watch`, `lsp`) with their options and defaults, the files the build writes, the diagnostic codes, static analysis of the compiled code, and the related Symfony console commands. To install Phem, see [Install and configure Phem](installation.md). For the production build, see [Deploy a Phem app](deployment.md#build-for-production).

## Build pipeline

A Phem application is a Symfony application with `PhemBundle`, and it needs two build steps before it can serve a page:

1. `phemc build` compiles the `.phem` files to PHP and Twig, in `var/phem/`.
2. `bin/console phem:warmup` compiles the generated Twig templates into the Twig cache.

`phemc build` runs step 2 for you. At runtime, nothing parses a `.phem` or a `.twig` source.

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

Both formats leave out the warnings of a Composer package, because the application can’t fix them. The errors of a package stay in the list. Both formats also leave out the hints (see [Diagnostic codes](#diagnostic-codes)).

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

`phemc lsp` runs the language server on stdin and stdout. It takes no command-line option. The editor can give the `sourceDir` initialization option (default `src`): the server checks only the `.phem` files in it. The server reports the same diagnostics as `phemc check` while you type, and it adds completion, go to definition, find references, a code lens with the usage count of each function, class and method, quick fixes (for example for H001), hover and the outline of `.phem` files.

The VS Code and PhpStorm plugins bundle the same server compiled to WebAssembly, so they don’t need a `phemc` binary (see `editors/vscode/README.md` and `editors/phpstorm/README.md`). The Claude Code plugin runs the `vendor/bin/phemc lsp` of the application (see [Use Claude Code](#use-claude-code)).

The server also checks the `.phem` files of the Composer packages together with the application:

- **Package components**: completion, definition and hover work for package components in the files of the application
- **Package errors**: the server shows an error of a package file in that file, also at its real path for a package of a Composer path repository. Only the application that installs a package checks its files, so a linked package in the same workspace doesn’t check itself
- **Reloads**: the server reads the package roots and files once, then again after a Composer change, after a package file changes on disk, and when a source directory or a package root appears or goes away
- **Wrong package root**: when it can’t read a package root (a bad `extra.phem.source`), it shows the error once (and again after a Composer change), and checks only the files of the application

### Use Claude Code

The Phem plugin for [Claude Code](https://claude.com/claude-code) runs the language server of the application (`vendor/bin/phemc lsp`). After each edit of a `.phem` file, Claude Code gets the `phemc` errors, warnings and hints and can correct them right away. Its `LSP` tool also gets go to definition, find references, hover and the outline. Install the plugin:

```sh
claude plugin marketplace add phemphp/phem
claude plugin install phem@phem
```

In a running session, run `/reload-plugins` after the install. The plugin runs the `vendor/bin/phemc` of each project that has one, so use it only in projects that you trust.

The server starts the first time Claude Code edits a `.phem` file. After each edit, the conversation shows a summary such as `Found 1 new diagnostic issues in 1 files`, and **Ctrl+O** shows the diagnostics. If the server doesn’t start, open `/plugin` and read the **Errors** tab. For more, see `editors/claude-code/README.md`.

## Diagnostic codes

Errors start with `E` and stop the build; warnings start with `W` and don’t. Hints start with `H`: only the editors show them, often with a quick fix, and `phemc build` and `phemc check` leave them out. `phemc` reports these codes:

| Code | Meaning |
|---|---|
| E001–E006 | Syntax: an unclosed or mismatched tag, a void element that isn’t self-closed, more than one root, or an open tag other than `<?phem` (`<?php`, the old `<?phpm`, `<?phemx`) |
| E010–E015 | An unknown component (also a function without `#[Component]`, or the target of a `replaces`), an unknown or missing prop, children or a slot that the component doesn’t accept, a duplicate component (also a component named after a built-in component such as `Phem\Root`, or an enum that two files declare), or a required context with no provider above the component |
| E016, E017 | A replacement (`#[Component(replaces: ...)]`) that doesn’t accept a prop or slot of the original, requires one that the original doesn’t require, or declares a `Bound` prop of the original with another type. Also a replacement in a package, a second replacement of a component, a component that replaces itself, the replacement of a replacement, a page or a built-in component, a page that replaces a component, or a `replaces` value that isn’t `Name::class` or a string (`self::class` included) |
| E018 | A literal value of a backed enum prop that isn’t the value of a case (`variant="big"`, `level="03"`, an empty value or a bare attribute), when the enum is in a `.phem` file |
| E020 | A call to a hook function (`Phem\useState`, …). Phem keeps state in `#[Component]` classes |
| E030–E033 | An undefined variable in markup, markup in an unsupported expression, an invalid attribute, or an invalid `phem:bind` (not a `#[State]` property, not a form field, a type that can’t take the value of the field, or an `aria-invalid` on the field) |
| E040–E047 | A class component without `render()`, a readonly `#[State]` property, a `#[Prop]` property that isn’t public or is static or `#[State]`, a second `#[Prop(rest: true)]` property or one whose type doesn’t accept an array, a `#[Context]` or `#[Provide]` type that isn’t one class, interface or enum (or a `#[Context]` property that is static, readonly, a prop or state), `#[Component]` on an abstract class, `mount` or `render` passed as a handler (`onClick={{ mount }}`), or a handler call whose arguments don’t fit the method (too many, one for an `Event` parameter, a named one, or a literal of another type) |
| E050, E052, E053 | `export default` (not PHP; use `#[Page]`), the Symfony `#[Route]` on a function (use `#[Page]`) or on a class that isn’t a component, `#[Broadcast]` on something that isn’t a page or a component, on an error page or on a method of a page, or `#[Broadcast(handlers: false)]` on a page, a function component, a method, or a class with `#[Broadcast]` on a handler |
| E054, E055 | `#[Async]` on a function, or an `#[Async]` method that doesn’t declare `Promise` as its return type |
| E056 | A page (class or function) or an error page used as a tag |
| E057 | `#[BroadcastKey]` on a static property, on a constructor parameter, on a property that isn’t a `#[Prop]` or a `#[State]` in a component with `#[State]`, on a page, or in a component with no `#[Broadcast]` on it or on a handler |
| E058 | `#[Validated]` on a function, or on a handler of a component with no non-static `#[State]` property of type `Phem\Validation` (a property of a trait or a parent class counts), or a component with more than one `Validation` property |
| E059, E060 | `#[ErrorPage]` on a class (an error page is a function), or in a package (only the application has error pages). See [Error pages](error-pages.md) |
| E061 | An invalid `phem:error`: not a `#[State]` property or a `Bound` prop, a component with no `Validation` property, an element with an `id`, content or `phem:bind`, a void element, or a component tag whose component has no `id` prop or no `children` |
| E062 | A class name in markup with a case or a constant that the enum doesn’t have (`OrderStatus.Canceled`), or `cases()` of a component, when the enum is in a `.phem` file. See [Classes in markup](phem-syntax.md#classes-in-markup) |
| E063 | `path()` or `url()` of a class or a function that isn’t a page with a route (`path(Card)`, or an error page) |
| E064 | A class name in markup in another place than `Class.NAME`, `Class.cases()` or `path(Page)`, for example `{{ OrderStatus }}` or `OrderStatus.from('paid')` |
| E065 | An invalid `phem:feed`: not a `Feed` property of the class component (a function component, a local variable that hides the property, a property of another type, or an expression), a `Feed` property that isn’t public or is static, readonly, promoted or nullable, or has `#[State]`, `#[Prop]`, `#[Path]` or `#[Context]`, on the tag of a component or a `<Dynamic>`, on an element with an `id`, a spread, a `phem:bind` or a `phem:error`, or a feed element that renders more than one time (in a `{% for %}` loop, in an item of a feed, or a second element for the same feed) |
| E066 | Invalid content in a `phem:feed` element: content other than one `{% for item in feed %}` loop on the feed, a loop on another value, a loop with `{% else %}` or with a key (`{% for key, item in feed %}`), an item that isn’t one element (text, a Twig output, a Twig tag, or two elements), an item element with an `id`, or an item that reads `loop` or renders a class or a function component tag |
| W002–W003 | A list item without `key`, or an event that the browser doesn’t send (`onMouseEnter`) |
| W004 | `#[Page]`, `#[ErrorPage]`, `#[Route]`, `#[Component]`, `#[State]`, `#[Prop]`, `#[Path]`, `#[Context]`, `#[Provide]`, `#[Broadcast]`, `#[BroadcastKey]`, `#[Async]` or `#[Validated]` without its `use` import (`Phem\Attribute\...`, `Symfony\Component\Routing\Attribute\Route`) |
| W005 | A `<title>` outside `<head>` and `<svg>`. The document component prints the title, and a page sets it with the `title` prop of the document (`<Document title="Home">`) |
| W006 | `phem:debounce`, `phem:throttle` or `phem:prevent` on an element with no handler, `phem:key` on an element with no `onKeyDown` or `onKeyUp`, `phem:poll` on an element with no `onPoll`, or `onPoll` on an element with no `phem:poll` |
| W007 | `phem:live` on an element with no `phem:bind` |
| W008 | A `render()` or a function component that isn’t pure: it reads a constructor service, a static property, a superglobal, the clock or a random value (see [Keep `render()` pure](components.md#keep-render-pure)) |
| H001 | A variable that only copies a property for the markup (`$rows = $this->lines;`). The markup reads the property. The quick fix removes the copy and uses the name of the property in the markup. A copy that keeps an older value of the property isn't a hint (see [Read the properties in the markup](components.md#read-the-properties-in-the-markup)) |

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
