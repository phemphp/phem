# TODO

The work that is still open in `phem/ui-base`. The bundle has no workarounds. A missing feature goes into phem core first, then the bundle uses it.

## Waiting for a phem release

- **Release phem with commit `f29d41a`.** This commit adds the Base UI controllers and closes the gaps of the existing ones. It is on the local phem `main` only. It is not pushed and it is not in a release.
- **Require a tagged phem version.** `composer.json` and `demo/composer.json` require `phem/phem: dev-main` from the path repository `../../phem`, with `minimum-stability: dev`. After the phem release, require the release version (for example `^0.x`), remove the path repository from the bundle `composer.json`, and set `minimum-stability` back to `stable`.

## Waiting for a phem fix

See [notes/phem-gaps.md](notes/phem-gaps.md) for the full list.

- **Menu checkbox and radio items in the browser (gap 5).** Base UI toggles `Menu.CheckboxItem` and `Menu.RadioItem` in the browser (`defaultChecked`, `defaultValue`). `phem-menu` has no client state for them. Now the server gives `checked`, with an `onClick` handler. After the fix, add `defaultChecked` to `MenuCheckboxItem`, `defaultValue` to `MenuRadioGroup`, and the `data-checked`/`data-unchecked` attributes from the controller.
- **Toast title and `data-behind` (gap 6).** `phem-toast` has one message part only. Base UI has `Toast.Title` and `Toast.Description`, and `data-behind` on the content of a stacked toast. After the fix, add `ToastTitle` to the template of `ToastViewport`, and the `data-behind` classes.

## To build here

- **Playwright tests for the demo.** The plan has one browser test for the main interaction of each component (`demo/tests/`, on the phem e2e image). The demo was checked by hand in the browser pane only.
- **Dark mode check.** Compare each demo page with base-ui.com in dark mode. The light mode check matched.
- **README status.** After gaps 5 and 6 are fixed, remove the menu and toast exceptions from the README status.
