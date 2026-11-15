# Phem gaps found while building phem/ui-base

Each item is a candidate fix in phem core. The bundle does not work around them.

Status (2026-11-15): phem commit `f29d41a` fixes all the items except 5 and 6. Item 10 is the
phemc error E068. Items 5 (menu checkbox and radio items in the browser) and 6 (toast title) are
still open.

1. **phemc check misses a method call in class markup.** In a class component, `aria-valuetext={{ text() }}`
   compiles to the Twig function `text()`, and Twig fails at runtime with `Unknown "text" function`.
   `phemc check` reports no error. `docs/syntax.md` says a name in class markup resolves to a method,
   so a reader expects `text()` to work. Fix: compile the call to the method, or report an error in phemc.
   The bundle computes the values in `render()` before the `return`, as the docs advise.

2. **An optional `Bound` prop fails at runtime.** `ComboboxInput(?Bound $value = null)` with
   `<input phem:bind={{ value }} />` passes phemc. With no `phem:bind` on the tag, the render throws
   `PhemRuntime::bind(): Argument #1 ($property) must be of type Phem\Bound|array|string, null given`.
   Fix: a null Bound renders a plain input. Until then, `PickersTest::testTheComboboxLinksItsLabelAndHasStableItemIds` fails.

3. **AlertDialog: dismiss on Escape but not on a backdrop tap.** Base UI closes an alert dialog on
   Escape only. `phem-dialog` has one `dismiss` value for both. Fix: a `disablePointerDismissal` value.

4. **Tabs select by index, not by value.** Base UI uses `defaultValue` with the tab `value`.
   `phem-tabs` uses `index`. The server render also cannot hide the other panels, because a part
   does not know its index. Fix: select by `data-value`.

5. **Menu checkbox and radio items are server state only.** Base UI toggles them in the browser
   (`defaultChecked`). The bundle takes `checked` from the server, with an `onClick` handler.

6. **Toast has no title part.** Base UI toasts have a title and a description. `phem-toast` has
   only the message (`[data-phem-toast-description]`). Base UI also has `data-behind` on the content.

7. **Drawer variables.** phem uses `--dialog-swipe-*`. Base UI uses `--drawer-swipe-*`, plus
   `--drawer-swipe-strength` and snap points.

8. **Combobox filters on the server only.** Base UI filters a static list in the browser.

9. **phem.css is not in a cascade layer, so Tailwind v4 cannot override it.** `phem.css` (autoimported)
   styles `[data-phem-toast]` with `:where()` (radius, grey background, `display: none` on
   `[data-limited]`). The `:where()` gives zero specificity, but the rules are unlayered. Tailwind v4
   puts its utilities in `@layer utilities`, and unlayered CSS always wins over layered CSS. So the
   Base UI classes of the toast template lose. Seen in the demo: `/components/toast` shows rounded,
   grey toasts. Fix: put the default styles of phem.css in a layer (for example `@layer phem`), except the
   `!important` rules that must win.

10. **phemc accepts a component class with a reserved name.** `#[Component] class Switch` passes
    `phemc check`, but `switch` is a reserved word, so PHP cannot load the class. Fix: phemc reports
    the reserved names (`Switch`, `List`, `Match`, `Default`…). The bundle uses `SwitchRoot`.

11. **`value` and an optional `Bound` cannot be on the same element.** phemc gives an error for
    `<input phem:bind={{ value }} value={{ value ? null : defaultValue }} />`. With an optional
    `?Bound $value = null`, the input is a plain field when the caller gives no `phem:bind`, and then
    it needs its `value` (Base UI `defaultValue`). Fix: allow `value` next to the `phem:bind` of an
    optional Bound prop, and render it only when the Bound is null. Until then, `Input` has no
    `defaultValue`.
    The same rule refuses `checked` next to `phem:bind` (for the hidden checkbox of Checkbox and Switch).

12. **phem-checkbox-group loses its `value` to a phem-toggle item on load.** Demo `/components/checkbox-group`:
    the group has `data-phem-checkbox-group-value-value='["fuji-apple"]'`, and the Fuji item has
    `aria-checked="true"` from the server. After the load, Fuji is unchecked
    (`data-phem-toggle-checked-value="false"`). The group connects first and sets the item, then the
    `phem-toggle` of the item connects and takes its first state from its unchecked input. A click on Gala
    then gives `["gala-apple"]`, not `["fuji-apple","gala-apple"]`. docs/controllers.md says that the
    value of the group sets the items on load. Fix: the group state wins over the first state of an item
    toggle that connects later.

13. **phem-navigation-menu: the first open animates the position from the corner.** The Base UI demo
    gives the positioner `transition-[top,left,right,bottom]`, and `data-instant:transition-none`.
    Base UI sets `data-instant` on the first open, so the position animates only when the open item
    changes. phem never sets `data-instant` on the positioner, so on the first open the popup moves in
    from the top left corner of the page. Fix: set `data-instant` on the positioner (and the popup)
    for the first open, and remove it when the open item changes.
