# phem/ui-base

Phem components that copy the behavior and the look of [Base UI](https://base-ui.com/react/components).
The `.phem` files hold the markup and the Tailwind classes. The browser behavior comes from the
headless Stimulus controllers of `phem/phem` (`phem-dialog`, `phem-menu`, `phem-combobox`…).
This package ships no JavaScript. A missing feature goes into phem, not into this package.

## Use it in an app

```sh
composer require phem/ui-base
```

Import the theme in the Tailwind build, and enable the phem controllers in `assets/controllers.json`
(see `phem/docs/controllers.md`). The theme file also adds the sources of the components to the
build, so you need no `@source` for them:

```css
@import "tailwindcss";
@import "../../vendor/phem/ui-base/assets/theme.css";
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

## Customize

The components take their colors, radius and popup shadow from CSS variables, with the names of
[shadcn/ui](https://ui.shadcn.com/docs/theming): `bg-background`, `text-foreground`,
`border-input`, `bg-primary`… The default values give the look of the Tailwind demos of Base UI.
The components have no `dark:` classes: the theme sets the dark values.

### Change the theme

Set the variables in a rule after the import:

```css
/* assets/styles/app.css */
@import "tailwindcss";
@import "../../vendor/phem/ui-base/assets/theme.css";

:root {
    --primary: oklch(0.55 0.2 260);
    --primary-foreground: oklch(0.98 0 0);
    --ring: oklch(0.55 0.2 260);
    --radius: 0.625rem;
}
```

Each `<Checkbox>`, `<Switch>`, `<RadioGroup>` and pressed `<Toggle>` is now blue, and each control
and popup is round.

| Variable | Used for |
|---|---|
| `--background`, `--foreground` | the box and the text of a control |
| `--popover`, `--popover-foreground` | the box and the text of a popup: popover, menu, select, dialog, drawer, toast |
| `--primary`, `--primary-foreground` | a checked, pressed or filled part: checkbox, switch, radio, toggle, slider, progress, meter |
| `--accent`, `--accent-foreground` | the hover and the open state |
| `--muted`, `--muted-foreground` | a track (avatar, meter, progress, scroll area); a description, a placeholder, a disabled text |
| `--border` | the border of a popup and of a frame (accordion, fieldset), and the separator in a popup |
| `--input` | the border of a control: button, input, select, checkbox, switch |
| `--ring` | the focus outline |
| `--destructive` | the error of a field |
| `--radius` | the radius: `rounded-popup` is `--radius`, `rounded-control` is `--radius * 0.8` |

phem/ui-base adds these variables. In the Base UI look, one shadcn color has two roles here:

| Variable | Default | Used for |
|---|---|---|
| `--highlight`, `--highlight-foreground` | `--primary`, `--primary-foreground` | the highlighted item of a menu, a select or a combobox |
| `--separator` | `neutral-300` | the `<Separator>` component |
| `--accent-active` | `--accent` with 10% of `--foreground` | a control while the pointer presses it (`active:`) |
| `--overlay` | `black / 20%` | the backdrop of a dialog |
| `--popup-shadow` | an offset shadow, none in dark mode | the shadow of a popup (`shadow-popup`) |

### Use a shadcn theme

Paste a theme of shadcn (or of a theme generator for shadcn) after the import. It sets the shadcn
variables. Add these lines for the shadcn look of the highlight, the separator and the popups:

```css
:root {
    --highlight: var(--accent);
    --highlight-foreground: var(--accent-foreground);
    --separator: var(--border);
    --popup-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); /* shadow-md */
}
```

### Dark mode

The theme sets the dark values with `@variant dark`, so it follows the dark variant of your build.
Declare a custom variant before the import. The variant must match the `<html>` element itself,
because the theme sets the variables on `:root`:

```css
@import "tailwindcss";
@custom-variant dark (&:where(.dark, .dark *));
@import "../../vendor/phem/ui-base/assets/theme.css";
```

The default variant of Tailwind (the `prefers-color-scheme` media query) also works. The variant of
shadcn, `&:is(.dark *)`, does not match `<html>`: a shadcn theme sets its own dark values in
`.dark { … }`, but the dark values of the variables of phem/ui-base do not apply.

### Add classes

Each part merges the `className` of the caller into its classes. Turn on the Tailwind merger of
phem, so that a class of the caller replaces a class of the same group (see
`phem/docs/styling.md`):

```php
<Button className="h-9 px-4">Save</Button>
```

```yaml
# config/packages/phem.yaml
phem:
    class_merger:
        tailwind:
            css: '%kernel.project_dir%/assets/styles/app.css'
```

Set the `css` option to the entry that imports the theme. The merger then reads the `@theme`
blocks of the theme file too, so it knows `rounded-control`, `rounded-popup` and `shadow-popup`.
Without the `css` option, these names are in no group: `<Button className="rounded-md">` keeps
`rounded-control` too, and the CSS order picks the radius.

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

## Use it with a coding agent

The package ships its examples (`examples/`, the `extra.phem.examples` key of `composer.json`) and
this guide (`extra.phem.guide`) for the coding agents. In an app, `phemc` gives them to the agent
(see `phem/docs/ai-agents.md`):

```sh
vendor/bin/phemc components                  # the families, one line each
vendor/bin/phemc components Dialog           # the props, the context and the anatomy of a component
vendor/bin/phemc examples Dialog             # the examples that use Dialog
vendor/bin/phemc search "dialog with a form" # the best components and examples for a task
```

Follow these rules when you write code with the components:

- **Families**: a family is one file of `src/` (`Dialog.phem`): its root and its parts. Import the
  root and the class parts with `use`, and the function parts with `use function`, as `phemc
  components <name>` shows.
- **Anatomy**: a part goes inside the component of its context, as the anatomy of the root shows.
  A popup part goes inside its root, and the root has the Positioner props.
- **Start from an example**: each example is one use case of base-ui.com, with the text of its
  page. Copy the closest one, and keep its classes, its `handle` and its data attributes.
- **Check**: run `vendor/bin/phemc check <file>` after each change.

## Status

All 37 Base UI components are here. The parts follow the Base UI anatomy, with this exception:

- **Switch**: the root is `SwitchRoot`, because `switch` is a reserved word in PHP.

## Develop

The bundle lives in `ui/base/` of the phem repository until its components are stable. It requires `phem/phem` from the root of the repository (a Composer path repository), so a change in phem and a change in a component go in the same commit. The commands run in the images of `docker/compose.yaml` (the `ui` and `ui-e2e` services), with the `phemc` that the images build from `compiler/` (`PHEMC_BIN`). Run them here, or from the root of the repository with `make ui-test`, `make ui-check`, `make ui-demo` and `make ui-test-e2e`.

The components use only the public API of phem: the components, the controllers and their documented attributes. A package of an app can do the same.

The demos of the gallery are the examples of the package (`examples/`, namespace `Phem\Ui\Base\Examples`). A demo page (`demo/src/Ui/Demos/`) shows them, and the demo compiles them with `phemc build src --examples-of phem/ui-base`. Give each example a docblock: its title, then the text of its section on base-ui.com. `make check` checks the examples and runs the retrieval eval of the search (`tests/retrieval.yaml`).

```sh
make install     # composer install
make test        # PHPUnit render tests
make check       # phemc check of the components and the examples, and the retrieval eval
make demo        # the gallery on http://localhost:8100 (FrankenPHP, production image)
make demo-watch  # the same gallery in the dev environment, restarted after each change
make test-e2e    # the browser tests of the demo (Playwright PHP)
```

The demo runs in its own production image (`demo/etc/Dockerfile`, with its `Caddyfile` and PHP settings): FrankenPHP in worker mode, `APP_ENV=prod`. The build context is the root of the repository, because the image installs phem/phem and phem/ui-base from it and builds `phemc` from `compiler/`. Rebuild the image after each change (`make demo` does it). The image copies `demo/.env.local` (not in git) with the `APP_SECRET` of the demo. `make demo` writes it with a random secret when it is missing. To build and run the image without make:

```sh
docker build -f ui/base/demo/etc/Dockerfile -t phem-ui-base-demo .   # from the root of the repository
docker run --rm -p 8080:8080 phem-ui-base-demo
```

While you work on a component or a controller, run `make demo-watch` (or `make ui-demo-watch` from the root of the repository). It serves the same gallery on http://localhost:8100 with the `watch` stage of `demo/etc/Dockerfile`: FrankenPHP in worker mode, `APP_ENV=dev`, on a bind mount of the repository. The image is built only once, and `demo/etc/watch` reloads the demo after each change:

- **`.phem` files** (the demo, the components and phem): `phemc watch` rebuilds them and restarts FrankenPHP
- **PHP classes and config files** (the demo, the components and phem): the `watch` options of the worker restart the workers
- **Controllers and CSS** (`assets/`): AssetMapper serves the new file on the next request, and `tailwind:build --watch` rebuilds the CSS

Stop `make demo` first: both use port 8100.

The browser tests (`demo/tests/`) run in the e2e image of phem, with Chromium. They need no web server: the Playwright Symfony bundle sends the requests of the browser to the kernel of the demo, in the PHPUnit process. A failed test saves a screenshot and the HTML of the page in `demo/var/failures/`.
