# Contribute to Phem

This guide is for work on Phem itself: the runtime, the compiler, and the editor support. It shows how to run the tests and the benchmark, build the editor plugins, and find your way around the repository. To publish a version, see [Release a version](releasing.md). To use Phem in an app, start with the [README](../README.md).

## Run the tests

You only need Docker. Run these commands from the root of the repository:

```sh
make test      # the JS tests, the e2e tests, cargo test and PHPUnit
make test-e2e  # only the e2e tests: Chromium and the e2e app (e2e/)
make bench     # the benchmark of the client/server flow (bench/run.php)
make shell     # a shell in the dev image: phemc (built from compiler/), cargo, composer, PHPUnit
make vscode    # the VS Code extension for .phem files (editors/vscode/phem-<version>.vsix)
make phpstorm  # the PhpStorm plugin for .phem files (needs Java 21 and Gradle)
```

The dev image lives in `docker/` and contains FrankenPHP (PHP 8.5), Composer, and the Rust toolchain. Its entrypoint builds `phemc` from the mounted `compiler/` before each command. For a faster PHPUnit run while you work, use `docker compose -f docker/compose.yaml run --rm dev vendor/bin/phpunit`.

## Test in a real browser

The e2e tests check the client and the server together in Chromium: the events, the morph, and the pushes of the Mercure hub. They use [Playwright PHP for Symfony](https://github.com/playwright-php/playwright-symfony), so the specs are PHPUnit tests. Playwright sends the requests of the browser to the kernel of the e2e app, in the process of PHPUnit, so the app needs no web server. Only the Mercure hub runs as a server, because an `EventSource` needs a long response.

Run them with Docker, from the root of the repository:

```sh
make test-e2e
make test-e2e ARGS="--filter Smoke"
```

The `e2e` service of `docker/compose.yaml` builds `e2e/Dockerfile`: the dev image with Node.js and the Chromium of Playwright. `e2e/bin/test` installs the Composer packages of `e2e/` and the importmap, removes the data and the failures of the last run, starts the hub of `e2e/Caddyfile` on `localhost:3000`, and runs PHPUnit. The app compiles its `.phem` sources with the `phemc` of `compiler/` (`PHEMC_BIN` of the image).

The e2e app is a Symfony project in `e2e/`, with its own `composer.json`. It requires this checkout of `phem/phem` with a Composer path repository:

```text
e2e/src/Kernel.php       the kernel: a Phem\Test\TestKernel, with the services of the pages
e2e/src/Ui/              the .phem pages: /counter, /guestbook/{room}, /chat/{room}, /chat-switch/{prefix}
e2e/config/              phem.yaml (the hub), playwright.yaml (the hosts of the kernel), framework.yaml
e2e/assets/              app.js, controllers.json (the importmap of importmap.php)
e2e/tests/               the specs: SmokeTest.php, FeedTest.php, and E2eTestCase.php (the base class)
e2e/var/                 the phemc output, the SQLite file of the pages, the hub log, the failures of the last run
```

A spec extends `E2eTestCase`. `visit()` opens a page in the first browser, and `visitInNewBrowser()` opens a page as another user, with its own cookies. `waitForStream()` waits until an `EventSource` of the page is open, so the page gets the pushes of the hub:

```php
namespace App\Tests;

use function Playwright\Testing\expect;

final class GuestbookTest extends E2eTestCase
{
    public function testSignShowsUpInTheOtherBrowser(): void
    {
        $alice = $this->visit('/guestbook/lobby');
        $bob = $this->visitInNewBrowser('/guestbook/lobby');
        $this->waitForStream($bob);

        $alice->getByLabel('Message')->fill('Hello');
        $alice->locator('#sign')->click();

        expect($bob->locator('#entries li'))->toHaveText('Hello');
    }
}
```

To add a page, add a `.phem` file in `e2e/src/Ui/`, and register its services in `Kernel::configureContainer()`. Keep the data of a page in the SQLite file of `App\Database`, as `App\Guestbook` does, and give each test its own room or key: the tests of a run share the file. When a test fails, `e2e/var/failures/` gets a screenshot and the HTML of each page.

Run the suite in the `e2e` service only. It has the Mercure hub, its keys, `PHEMC_BIN` and the Chromium of Playwright, and the e2e app needs all of them. For a shell with these tools, for example to run `bin/console debug:router` in `e2e/`, use the `e2e` service and not `make shell`:

```sh
docker compose -f docker/compose.yaml run --rm e2e sh
```

## Measure a change to the client/server flow

`make bench` runs the pages of `tests/Fixtures/app` (and its bench pages in `src/Ui/Bench/`) the way a browser sends their events. It prints the median server time, the bytes of each request and response, the requests per step, and the writes to the state cache.

The time includes no network, so one extra request means one extra round trip in a real browser. Run the benchmark before and after a change to the client/server flow, and put both tables in the pull request.

## Try a change in a browser

Create an app as in [Getting started](quickstart.md), then require your checkout of this repository with a Composer path repository.

## Build and install the editor support

Run `make vscode-install` or `make phpstorm-install` to build and install the editor plugins. After a change in `compiler/` or `editors/`, run both again, then reload the VS Code window and restart PhpStorm.

To give Claude Code the compiler diagnostics after each edit, run `make lsp` and start `claude --plugin-dir editors/claude-code`. In an app, the Claude Code plugin runs `vendor/bin/phemc lsp` instead.

All three plugins use the Phem language server (`phemc lsp`). The VS Code and PhpStorm plugins bundle it compiled to WebAssembly. The Claude Code plugin runs the `vendor/bin/phemc lsp` of the application first. It gives diagnostics as you type, completion, go to definition, find references, usage code lenses, quick fixes, hover, and outline. For details, see these READMEs:

- [editors/vscode/README.md](../editors/vscode/README.md), and [editors/vscode/DEVELOPMENT.md](../editors/vscode/DEVELOPMENT.md) to build it
- [editors/phpstorm/README.md](../editors/phpstorm/README.md)
- [editors/claude-code/README.md](../editors/claude-code/README.md)

## Find your way around the repository

The repository root is the `phem/phem` Composer package. The compiler, editor support, docs, and docs website live in the same repository, and `.gitattributes` keeps them out of the Composer dist:

```text
src/ config/ templates/  phem/phem (Composer): PhemBundle, Renderer, Twig extension, controllers,
                     route loader, Doctrine transaction runner, console commands
assets/              the phem Stimulus controller (a Symfony UX package for AssetMapper)
bin/phemc            the phemc launcher: downloads the binary of the release on the first run
tests/               PHPUnit tests of the package (assets/tests/: the Vitest tests of the controller)
e2e/                 the e2e tests in Chromium (Playwright PHP) and their Symfony app: `make test-e2e`
bench/               the benchmark of the client/server flow: `make bench`
compiler/            Rust workspace: phemc-syntax, phemc-twig-scan, phemc-analyze, phemc-codegen, phemc-lsp, phemc-cli
docker/              the dev image (Dockerfile, compose.yaml, entrypoint.sh): `make test`, `make shell`
editors/server/      the Node launcher of the language server (phem-lsp.wasm: `make lsp`)
editors/vscode/      VS Code extension: .phem syntax highlighting (TextMate grammar) and the language server
editors/phpstorm/    PhpStorm plugin: the same grammar and language server
editors/claude-code/ Claude Code plugin: the language server, for diagnostics after each edit
docs/                the docs, as plain Markdown (GitHub, the website and LLMs read the same files)
website/             the docs website (Astro): `make docs`, `make docs-build`
recipe/              the Symfony Flex recipe of phem/phem (a copy of the recipe in symfony/recipes-contrib)
release.sh           releases a version: runs and watches the release workflows, publishes the release
install.sh           downloads the phemc binary of the latest release (install.ps1 on Windows)
```
