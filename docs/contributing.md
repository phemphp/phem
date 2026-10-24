# Contributing

This document is for work on Phem itself: the runtime, the compiler and the editor support. To
use Phem in an application, start with the [README](../README.md).

## Run the tests

You need only Docker. Run the commands from the root of the repository.

```sh
make test      # the JS tests, cargo test and PHPUnit
make shell     # a shell in the dev image: phemc (built from compiler/), cargo, composer, PHPUnit
make vscode    # the VS Code extension for .phpm files (editors/vscode/phem-<version>.vsix)
make phpstorm  # the PhpStorm plugin for .phpm files (needs Java 21 and Gradle)
```

The dev image is in `docker/`: FrankenPHP (PHP 8.5), Composer and the Rust toolchain. Its
entrypoint builds `phemc` from the mounted `compiler/` before each command. For a fast PHPUnit
run, use `docker compose -f docker/compose.yaml run --rm dev vendor/bin/phpunit`.

To build and install the editor support, run `make vscode-install` or `make phpstorm-install`.
To give Claude Code the compiler diagnostics after each edit, run `make lsp` and start
`claude --plugin-dir editors/claude-code`. All three use the Phem language server
(`phemc lsp`), compiled to WebAssembly: diagnostics as you type, completion, go to definition,
hover, and outline. In an application, the Claude Code plugin runs `vendor/bin/phemc lsp`. See
[editors/vscode/README.md](../editors/vscode/README.md),
[editors/phpstorm/README.md](../editors/phpstorm/README.md) and
[editors/claude-code/README.md](../editors/claude-code/README.md).

To try a change in a browser, make an application as in [Getting started](getting-started.md),
and require this checkout with a Composer path repository.

## Repository layout

The repository root is the `phem/phem` Composer package. The compiler, the editor support and the
docs are in the same repository, and `.gitattributes` keeps them out of the
Composer dist.

```
src/ config/ templates/  phem/phem (Composer): PhemBundle, Renderer, Twig extension, controllers,
                     route loader, Doctrine transaction runner, console commands
assets/              the phem Stimulus controller (a Symfony UX package for AssetMapper)
bin/phemc            the phemc launcher: downloads the binary of the release on the first run
tests/               PHPUnit tests of the package (assets/tests/: the Vitest tests of the controller)
compiler/            Rust workspace: phemc-syntax, phemc-twig-scan, phemc-analyze, phemc-codegen, phemc-lsp, phemc-cli
docker/              the dev image (Dockerfile, compose.yaml, entrypoint.sh): `make test`, `make shell`
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
