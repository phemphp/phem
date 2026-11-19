# phem/ui-base

Phem components that copy the behavior and the look of [Base UI](https://base-ui.com/react/components).
The `.phem` files hold the markup and the Tailwind classes. The browser behavior comes from the
headless Stimulus controllers of `phem/phem` (`phem-dialog`, `phem-menu`, `phem-combobox`…).
This package ships no JavaScript. A missing feature goes into phem, not into this package.

## Use it in an app

```sh
composer require phem/ui-base
```

Add the sources to the Tailwind build, and enable the phem controllers in `assets/controllers.json`
(see `phem/docs/controllers.md`):

```css
@import "tailwindcss";
@source "../../vendor/phem/ui-base/src";
```

```php
use Phem\Ui\Base\{Accordion, AccordionItem};
use function Phem\Ui\Base\{AccordionHeader, AccordionPanel, AccordionTrigger};

<Accordion>
    <AccordionItem value="what">
        <AccordionHeader><AccordionTrigger>What is Base UI?</AccordionTrigger></AccordionHeader>
        <AccordionPanel>A library of unstyled components.</AccordionPanel>
    </AccordionItem>
</Accordion>
```

## Names

The Base UI `X.Root` is the `X` tag, and each other part is `X<Part>`: `Dialog`, `DialogTrigger`,
`DialogPopup`, `DialogTitle`… A root that shares its props with its parts is a class with
`#[Provide]` (import it with `use`). The parts are function components (import them with `use function`).
A part that shares its props with the parts in it is also a class: `AccordionItem` and `MenuRadioGroup`.

- **Portal and Positioner**: they have no tag. A popup goes in the top layer (`popover="manual"`, or a
  native `<dialog>`), and the Positioner props (`side`, `align`, `sideOffset`…) are props of the root.
- **Backdrop**: the `::backdrop` of the native `<dialog>`, styled by the popup classes.
- **Ids**: parts take their ids from a `value` (or the `id` of the root), because the morph needs ids
  from the server render.

## Status

All 37 Base UI components are here. The parts follow the Base UI anatomy, with this exception:

- **Switch**: the root is `SwitchRoot`, because `switch` is a reserved word in PHP.

## Develop

The bundle lives in `ui/base/` of the phem repository until its components are stable. It requires `phem/phem` from the root of the repository (a Composer path repository), so a change in phem and a change in a component go in the same commit. The commands run in the images of `docker/compose.yaml` (the `ui` and `ui-e2e` services), with the `phemc` that the images build from `compiler/` (`PHEMC_BIN`). Run them here, or from the root of the repository with `make ui-test`, `make ui-check`, `make ui-demo` and `make ui-test-e2e`.

The components use only the public API of phem: the components, the controllers and their documented attributes. A package of an app can do the same.

```sh
make install   # composer install
make test      # PHPUnit render tests
make check     # phemc check src
make demo      # the gallery on http://localhost:8100 (FrankenPHP, production image)
make test-e2e  # the browser tests of the demo (Playwright PHP)
```

The demo runs in its own production image (`demo/etc/Dockerfile`, with its `Caddyfile` and PHP settings): FrankenPHP in worker mode, `APP_ENV=prod`. The build context is the root of the repository, because the image installs phem/phem and phem/ui-base from it and builds `phemc` from `compiler/`. Rebuild the image after each change (`make demo` does it). The image copies `demo/.env.local` (not in git) with the `APP_SECRET` of the demo. `make demo` writes it with a random secret when it is missing. To build and run the image without make:

```sh
docker build -f ui/base/demo/etc/Dockerfile -t phem-ui-base-demo .   # from the root of the repository
docker run --rm -p 8080:8080 phem-ui-base-demo
```

The browser tests (`demo/tests/`) run in the e2e image of phem, with Chromium. They need no web server: the Playwright Symfony bundle sends the requests of the browser to the kernel of the demo, in the PHPUnit process. A failed test saves a screenshot and the HTML of the page in `demo/var/failures/`.
