# Contribute to Phem

This guide is for work on Phem itself: the runtime, the compiler, and the editor support. It shows how to run the tests and the benchmark, build the editor plugins, find your way around the repository, and release a version. To use Phem in an app, start with the [README](../README.md).

## Run the tests

You only need Docker. Run these commands from the root of the repository:

```sh
make test      # the JS tests, cargo test and PHPUnit
make bench     # the benchmark of the client/server flow (bench/run.php)
make shell     # a shell in the dev image: phemc (built from compiler/), cargo, composer, PHPUnit
make vscode    # the VS Code extension for .phem files (editors/vscode/phem-<version>.vsix)
make phpstorm  # the PhpStorm plugin for .phem files (needs Java 21 and Gradle)
```

The dev image lives in `docker/` and contains FrankenPHP (PHP 8.5), Composer, and the Rust toolchain. Its entrypoint builds `phemc` from the mounted `compiler/` before each command. For a faster PHPUnit run while you work, use `docker compose -f docker/compose.yaml run --rm dev vendor/bin/phpunit`.

## Measure a change to the client/server flow

`make bench` runs the pages of `tests/Fixtures/app` (and its `bench.phem`) the way a browser sends their events. It prints the median server time, the bytes of each request and response, the requests per step, and the writes to the state cache.

The time includes no network, so one extra request means one extra round trip in a real browser. Run the benchmark before and after a change to the client/server flow, and put both tables in the pull request.

## Try a change in a browser

Create an app as in [Getting started](getting-started.md), then require your checkout of this repository with a Composer path repository.

## Build and install the editor support

Run `make vscode-install` or `make phpstorm-install` to build and install the editor plugins. After a change in `compiler/` or `editors/`, run both again, then reload the VS Code window and restart PhpStorm.

To give Claude Code the compiler diagnostics after each edit, run `make lsp` and start `claude --plugin-dir editors/claude-code`. In an app, the Claude Code plugin runs `vendor/bin/phemc lsp` instead.

All three plugins use the Phem language server (`phemc lsp`), compiled to WebAssembly. It gives diagnostics as you type, completion, go to definition, find references, hover, and outline. For details, see these READMEs:

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

## Release a version

A release publishes the `phem/phem` Composer package (on Packagist, from the tag) and the `phemc` binaries (on the GitHub release). `vendor/bin/phemc` downloads the binary that matches the installed `phem/phem` version, so the compiler and the runtime always share a version. The VS Code extension and the PhpStorm plugin have their own versions and aren’t part of a release. Build them with `make vscode` and `make phpstorm`.

### Check the release prerequisites

Prepare your machine before you start:

- Install `git`, `gh`, and `curl`.
- Sign in with `gh auth login`. The token needs the `repo` and `workflow` scopes, which `gh auth status` shows.
- Check out `main`. It must be clean and match `origin/main`. The script checks this first.

### Run the release script

Run `./release.sh`. A menu shows the latest release and the next versions:

```text
Latest release: v0.1.0
? Which release?
  patch  v0.1.1
> minor  v0.2.0
  major  v1.0.0
  other version
```

Select a version with the arrow keys (or `j` and `k`) and press **Enter**. Select `other version` to type a version, for example the pre-release `0.2.0-beta.1`. A pre-release doesn’t become the latest release.

The script then runs every step and shows the progress of each workflow:

1. It asks you to confirm the version.
2. It dispatches the `Release` workflow and watches it. This workflow sets the compiler version, commits it to `main`, creates the tag, drafts the GitHub release, and starts `phemc.yaml`.
3. It watches `phemc.yaml`. That workflow builds the `phemc` binaries (Linux x86_64 and aarch64, macOS arm64 and x86_64, Windows x86_64), attests them, and uploads them with `phemc-checksums.txt` to the release.
4. It checks that the release has all the binaries and `phemc-checksums.txt`.
5. It asks you to publish the draft release. `vendor/bin/phemc` only downloads from a published release. If you answer `No`, the script shows the command to publish it later.
6. It waits until Packagist has the version.

After the release, run `git pull`, because the `Release` workflow pushed a commit to `main`.

### Pass the version on the command line

Skip the menu or the prompts with these arguments:

| Command | Result |
|---|---|
| `./release.sh` | Select the version in a menu |
| `./release.sh patch` | Bump the latest stable tag. Also `minor` and `major`. |
| `./release.sh 0.2.0-beta.1` | Release this version (no `v` prefix) |
| `./release.sh --yes minor` | Don’t ask: confirm the version and publish |

Without a terminal (in a pipe, for example), pass the version. The prompts then read `y` or `n`.

### Resume a failed release

When a step fails, the script stops and shows the run URL and the command to read its logs. Fix the cause and run the script again with the exact version, for example `./release.sh 0.2.0`. The `Release` workflow resumes from the tag. Don’t pass `patch`, `minor`, or `major` here: the new tag already exists, so they’d pick the version after it.

If Packagist doesn’t have the version after 5 minutes, check the GitHub hook of the package on [the phem/phem Packagist page](https://packagist.org/packages/phem/phem).

### Update the Flex recipe

`recipe/` is a copy of the `phem/phem` recipe in [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib), under `phem/phem/<version>/`. Flex uses the recipe with the highest version that isn’t higher than the installed `major.minor` version. When a release needs different recipe files, change `recipe/` and send a pull request to `symfony/recipes-contrib` with a new `<major>.<minor>` directory.
