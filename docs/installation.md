# Install and configure Phem

`composer require phem/phem` installs the bundle, the client assets and the `vendor/bin/phemc` launcher, and the Symfony Flex recipe configures the application so that no Phem config file is needed. This page lists what the install sets up, the recipe options, the install without the `--webapp` skeleton, the `phemc` binary and the client assets.

## What Flex and `PhemBundle` configure

Flex and the bundle set up these parts of the application:

- **Bundle**: Flex enables `PhemBundle` in `config/bundles.php`
- **Client**: Flex adds the `phem` Stimulus controller and Turbo to the assets. The controller is the client of Phem: it sends the events to the server and updates the page (see [Client assets](#client-assets))
- **Starter files**: the recipe copies `src/Ui/Common/Document.phem` (the HTML document of the pages), `src/Ui/HomePage.phem` (the page at `/`) and `.symfony.local.yaml` (the `phemc watch src` worker of `symfony serve`)
- **Routes**: the `routing.controllers` resource of `config/routes.yaml` loads the routes of the `#[Page]` attributes, as it does for controllers, so the pages need no routes file (see [Route loading](pages.md#load-the-routes-of-the-pages))
- **Preload**: in production, `config/preload.php` also preloads the compiled components without a change (see [OPcache preload](deployment.md#turn-on-the-opcache-preload))
- **Compiler**: `vendor/bin/phemc` downloads the `phemc` binary of the same version on its first run (see [The `phemc` binary](#the-phemc-binary))

The bundle defaults match this layout: `phemc` reads `src/` and writes to `var/phem/`, which is the `build_dir` default. You don’t need a `config/packages/phem.yaml` file. For all the options and their defaults, see [Configuration](configuration.md).

## Flex recipe options

The recipe of `phem/phem` is in the contrib repository of Symfony Flex, so Flex asks before it runs it. When Flex skips the recipe (for example with `composer require --no-interaction`), allow the contrib recipes and install the recipe again:

```sh
composer config extra.symfony.allow-contrib true
composer recipes:install phem/phem --force
```

Flex records a skipped recipe as installed, so `--force` is required. `allow-contrib` applies to all contrib recipes, and Flex doesn’t ask again. Set it before `composer require phem/phem` to install with no prompt, for example in CI.

## Install without the `--webapp` skeleton

The `symfony new --webapp` skeleton already has AssetMapper. Without it, install AssetMapper together with `phem/phem`. `phem/phem` requires StimulusBundle, and Flex adds the `phem` controller to `assets/controllers.json`:

```sh
symfony new my-app
cd my-app
composer require symfony/asset-mapper phem/phem
```

## The `phemc` binary

`phemc` ships as a prebuilt binary for each release, and you get it through Composer or a standalone installer:

- **Composer**: `composer require phem/phem` installs `vendor/bin/phemc`. On its first run, this PHP script downloads the `phemc` binary of the installed `phem/phem` version from the GitHub release, checks its SHA-256 against `phemc-checksums.txt`, and stores it next to the script. `composer update` removes the old binary with the old version, so the compiler and the runtime always share a version
- **Standalone on Linux or macOS**: `install.sh` downloads the binary of the latest release to `$BIN_DIR` (the current directory by default)
- **Standalone on Windows**: `install.ps1` downloads the binary of the latest release to `$env:PHEMC_INSTALL` (`~\.phem` by default)

The prebuilt binaries cover Linux (`x86_64`, `aarch64`), macOS (`arm64`, `x86_64`) and Windows (`x86_64`). On another platform, or for a development version of `phem/phem` that has no release, build the binary from the sources:

```sh
cargo build --release --manifest-path compiler/Cargo.toml -p phemc-cli
```

`vendor/bin/phemc` reads the `PHEMC_DOWNLOAD_URL` environment variable as the base URL of the release assets (`https://github.com/phemphp/phem/releases/download` by default). The application also needs PHP 8.4 or later.

## Client assets

The client of Phem is the `phem` Stimulus controller and the `phem.css` file. The bundle ships them as a Symfony UX package (`assets/package.json`), and AssetMapper exposes them in the `phemjs` namespace. The controller imports `@hotwired/stimulus` and `@hotwired/turbo` from the importmap of the application, so the page loads one Turbo and one Stimulus.

The CSS file has these rules:

- `phem-c{display:contents}`: the `<phem-c>` element around a component that doesn’t render one element doesn’t change the layout
- `phem-suspense{display:block}`: the fallback of a `<Suspense>` is a box, so a lazy boundary can come into view
- `.phem-error`: the default message of a failed event (see [Failed events and limits](failed-events.md))
- `[data-phem-loading][hidden]` and `[data-phem-progress][hidden]`: a `phem:loading` or `phem:progress` element stays hidden while no handler runs, also with a `display` of its own. The rule is `!important`, so you can’t override it
- `[data-phem-toast]`, `[data-type]`, `[data-limited]`, `[data-phem-toast-description]`, `[data-phem-toast-close]` and the `phem-toast` container: the default look of the toasts
- `touch-action: none` on the handles of `phem-sortable` (or on its items with no handle), and no text selection during a drag (`[data-phem-sorting]`)

The toast rules are in `:where()`, so any rule of the application overrides them. To change the `phem-c`, `phem-suspense` and `.phem-error` rules, write a rule with a higher specificity, or load your CSS after `phem.css`.

`phem/phem` requires StimulusBundle. It doesn’t require AssetMapper: install it yourself to load the client assets with an importmap (see [Install without the `--webapp` skeleton](#install-without-the---webapp-skeleton)). Symfony Flex adds `@hotwired/stimulus` and `@hotwired/turbo` to `importmap.php`, and enables the controller in `assets/controllers.json`:

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

The package also has optional controllers (`phem-optimistic`, `phem-collapsible`, `phem-accordion`, `phem-depends`, `phem-dialog`, `phem-popover`, `phem-tooltip`, `phem-menu`, `phem-context-menu`, `phem-combobox`, `phem-tabs`, `phem-toast`, `phem-clipboard`, `phem-count`, `phem-reveal`, `phem-checkbox-group`, `phem-time`, `phem-scrollspy`, `phem-autofocus`, `phem-confirm` and `phem-sortable`), which are off by default. Enable one with its key in the `@phem/phem` entry, for example `"popover": { "enabled": true, "fetch": "lazy" }`. See [Built-in controllers](controllers.md#enable-the-controllers).
