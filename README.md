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

- **Portal and Positioner**: they have no tag. A popup goes in the top layer (`popover="manual"`, or a
  native `<dialog>`), and the Positioner props (`side`, `align`, `sideOffset`…) are props of the root.
- **Backdrop**: the `::backdrop` of the native `<dialog>`, styled by the popup classes.
- **Ids**: parts take their ids from a `value` (or the `id` of the root), because the morph needs ids
  from the server render.

## Status

All 37 Base UI components are here. The parts follow the Base UI anatomy, with these exceptions:

- **Switch**: the root is `SwitchRoot`, because `switch` is a reserved word in PHP.
- **Menu checkbox and radio items**: the server gives their state (`checked`), with an `onClick` handler.
- **Toast**: one message, with no title.

The phem gaps found so far, and which of them phem fixed, are in [notes/phem-gaps.md](notes/phem-gaps.md).

## Develop

The commands run in the dev image of phem (it builds phemc). `phem/phem` is a symlink to `../../phem`.

```sh
make install   # composer install
make test      # PHPUnit render tests
make check     # phemc check src
make demo      # the gallery on http://localhost:8100
```
