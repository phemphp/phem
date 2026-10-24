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
release.sh           releases a version: runs and watches the release workflows, publishes the release
install.sh           downloads the phemc binary of the latest release (install.ps1 on Windows)
```

## Release

A release has the `phem/phem` Composer package (on Packagist, from the tag) and the `phemc`
binaries (on the GitHub release). `vendor/bin/phemc` downloads the binary of the installed
`phem/phem` version, so the compiler and the runtime always have the same version. The editor
support (the VS Code extension and the PhpStorm plugin) has its own version and is not part of a
release: build it with `make vscode` and `make phpstorm`.

### Before you start

- Install `git`, `gh` and `curl`.
- Log in with `gh auth login`. The token must have the `repo` and `workflow` scopes
  (`gh auth status` shows them).
- Check out `main`. It must be clean and the same as `origin/main`. The script checks this first.

### Run the release

Run `./release.sh`. A menu shows the latest release and the next versions:

```
Latest release: v0.1.0
? Which release?
  patch  v0.1.1
> minor  v0.2.0
  major  v1.0.0
  other version
```

Select with the arrow keys (or `j` and `k`) and press Enter. Select `other version` to type a
version, for example a pre-release `0.2.0-beta.1`. A pre-release does not become the latest
release. The script then does all the steps and shows the progress of each workflow:

1. It asks you to confirm the version.
2. It dispatches the `Release` workflow and watches it. This workflow sets the version of the
   compiler, commits it to `main`, makes the tag, drafts the GitHub release, and starts
   `phemc.yaml`.
3. It watches `phemc.yaml`. That workflow builds the `phemc` binaries (Linux x86_64 and aarch64,
   macOS arm64 and x86_64, Windows x86_64), attests them, and uploads them with
   `phemc-checksums.txt` to the release.
4. It checks that the release has all the binaries and `phemc-checksums.txt`.
5. It asks you to publish the draft release. `vendor/bin/phemc` downloads only from a published
   release. If you answer `No`, the script shows the command to publish it later.
6. It waits until Packagist has the version.

After the release, run `git pull`: the `Release` workflow pushed a commit to `main`.

### Options

| Command                     | Result                                                |
|-----------------------------|-------------------------------------------------------|
| `./release.sh`              | Select the version in a menu.                         |
| `./release.sh patch`        | Bump the latest stable tag. Also `minor` and `major`. |
| `./release.sh 0.2.0-beta.1` | Release this version (no `v` prefix).                 |
| `./release.sh --yes minor`  | Do not ask: confirm the version and publish.          |

Without a terminal (for example in a pipe), give the version. The prompts then read `y` or `n`.

### If a step fails

The script stops and shows the URL of the run and the command for its logs. Fix the cause and run
the script again with the version, for example `./release.sh 0.2.0`. The `Release` workflow
resumes from the tag. Do not use `patch`, `minor` or `major` for this: the new tag exists, so they
give the version after it.

If Packagist does not have the version after 5 minutes, check the GitHub hook of the package on
https://packagist.org/packages/phem/phem.
