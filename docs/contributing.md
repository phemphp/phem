# Contributing

This document is for work on Phem itself: the runtime, the compiler, the editor support and the
quickstart. To use Phem in an application, start with the [README](../README.md).

## Run the quickstart

You need only Docker. Run the commands from the root of the repository.

```sh
make up      # http://localhost:8080/learn — phemc watch + FrankenPHP (worker mode)
make test    # the JS tests, cargo test and PHPUnit
make check   # phemc diagnostics for the quickstart sources
make prod    # the production image on http://localhost:8081
make vscode    # the VS Code extension for .phpm files (editors/vscode/phem-<version>.vsix)
make phpstorm  # the PhpStorm plugin for .phpm files (needs Java 21 and Gradle)
make claude    # the Claude Code plugin for .phpm files (editors/claude-code/)
```

`make up` and `make prod` also make the SQLite database of the quickstart on the first start:
`examples/quickstart/docker/init-db.sh` makes the schema from the Doctrine mapping and loads the Doctrine fixtures.
They also run the Messenger worker of the `#[Async]` handlers (`messenger:consume async`), next to
FrankenPHP.

To build and install the editor support, run `make vscode-install` or `make phpstorm-install`.
To give Claude Code the compiler diagnostics after each edit, run `make claude` and start
`claude --plugin-dir editors/claude-code`. All three use the Phem language server
(`phemc lsp`), compiled to WebAssembly: diagnostics as you type, completion, go to definition,
hover, and outline. See
[editors/vscode/README.md](../editors/vscode/README.md),
[editors/phpstorm/README.md](../editors/phpstorm/README.md) and
[editors/claude-code/README.md](../editors/claude-code/README.md).

- `/learn` is the Quick Start page (static HTML + CSS, like react.dev/learn).
- Each sandbox in it runs a real `.phpm` file from `examples/quickstart/src/Page/Example/`.
  Its `#[Page]` attribute serves it at `/examples/<Name>`.
- `/learn/describing-the-ui.html` has a multi-file example: the page `Gallery.phpm` uses
  components from `examples/quickstart/src/Component/`. The page `DurableGallery.phpm` reads and
  writes its data in SQLite through a Doctrine ORM entity and its repository. Its handlers run in
  a Doctrine transaction (`#[Transactional]`), and a Symfony form adds a scientist. The data stays after a reload.

## Repository layout

The repository root is the `phem/phem` Composer package. The compiler, the editor support, the
docs and the quickstart are in the same repository, and `.gitattributes` keeps them out of the
Composer dist.

```
src/ config/ templates/  phem/phem (Composer): PhemBundle, Renderer, Twig extension, controllers,
                     route loader, Doctrine transaction runner, console commands
assets/              the phem Stimulus controller (a Symfony UX package for AssetMapper)
bin/phemc            the phemc launcher: downloads the binary of the release on the first run
tests/               PHPUnit tests of the package (assets/tests/: the Vitest tests of the controller)
compiler/            Rust workspace: phemc-syntax, phemc-twig-scan, phemc-analyze, phemc-codegen, phemc-lsp, phemc-cli
examples/quickstart/ a Symfony application: the react.dev/learn-style site (public/), its
                     runnable .phpm examples (src/), its FrankenPHP Caddyfile, compose.yaml, and
                     docker/ (Dockerfile: dev, prod; the entrypoints; init-db.sh)
editors/server/      the Node launcher of the language server (phem-lsp.wasm: `make lsp`)
editors/vscode/      VS Code extension: .phpm syntax highlighting (TextMate grammar) and the language server
editors/phpstorm/    PhpStorm plugin: the same grammar and language server
editors/claude-code/ Claude Code plugin: the language server, for diagnostics after each edit
docs/phpm-syntax.md  the .phpm syntax
docs/compiling.md    how to build, check, watch, and run .phpm files
docs/testing.md      how to test components and pages (Phem\Test\InteractsWithPhem)
docs/how-it-works.md the path from the compiler to the browser, and React to Phem
docs/contributing.md this document
release.sh           dispatches the release workflow (.github/workflows/release.yaml)
install.sh           downloads the phemc binary of the latest release (install.ps1 on Windows)
```

## Release

Run `./release.sh 0.1.0` on an up-to-date `main`. The `Release` workflow sets the version of the
compiler, tags `v0.1.0`, drafts the GitHub release, and starts `phemc.yaml`. That workflow builds
the `phemc` binaries (Linux x86_64 and aarch64, macOS arm64 and x86_64, Windows x86_64), attests
them, and uploads them with `phemc-checksums.txt` to the release. Then publish the draft release:
`vendor/bin/phemc` downloads only from a published release.
