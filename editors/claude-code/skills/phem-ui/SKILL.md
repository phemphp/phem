---
name: phem-ui
description: Build UI with the Phem components of the installed packages (for example phem/ui-base) - dialogs, menus, forms and fields, selects, comboboxes, tabs, tooltips, toasts, drawers. Use it when a task in a Phem app needs an interactive UI part, before you write the markup.
allowed-tools: Bash(vendor/bin/phemc:*)
---

# Phem UI components

The components of the app and of its Composer packages are below, one family each. They come
from the installed sources, so they match the installed versions. Use the `phem` skill for the
syntax and the rules of `.phem` files.

## Workflow

1. **Find the use case.** Call `phem_search` (or run `vendor/bin/phemc search "<task>"`), for
   example "dialog with a form". The best results are often examples.
2. **Start from the closest example.** Call `phem_examples` with its key (or run
   `vendor/bin/phemc examples <key>`): it gives a complete file, with the imports, the constants,
   the example and its helpers. Copy it, rename it, and change only what the task needs. Keep its
   classes, its `handle` values and its data attributes.
3. **Read each component that you use.** Call `phem_component` (or run
   `vendor/bin/phemc components <name>`): the props, their types and defaults, the components
   that it goes inside, and the anatomy of its family. Use only these props and parts, and put
   each part where the anatomy shows it.
4. **Check.** The plugin checks each `.phem` file that you write. Correct all the errors.

## The components

!`vendor/bin/phemc components`
