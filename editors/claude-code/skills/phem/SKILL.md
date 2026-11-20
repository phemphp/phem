---
name: phem
description: "Write and change Phem code in a Symfony app - .phem files (PHP with markup literals), components, pages, state, events, forms, real-time updates. Use it before you write or edit a .phem file, or when the task names Phem, phemc or a Phem attribute (#[Component], #[Page], #[State], #[Prop])."
allowed-tools: Bash(vendor/bin/phemc:*)
---

# Phem

Phem is a Symfony component framework: a `.phem` file is PHP with markup literals, and `phemc`
compiles it to PHP and Twig. The texts below come from the installed version of Phem.

## Workflow

1. **Look up before you write.** Call `phem_search` (or run `vendor/bin/phemc search "<task>"`)
   with the task in a few words. Read the 1 to 3 best keys with `phem_doc`, `phem_component` or
   `phem_examples` (`vendor/bin/phemc docs <key>`, `components <name>`, `examples <key>`).
2. **Follow the cheatsheet.** It has the syntax and the rules that are easy to break.
3. **Check after you write.** The plugin runs `phemc check` after each edit of a `.phem` file and
   gives you the errors. Correct all of them. Call `phem_check` (or run
   `vendor/bin/phemc check <file>`) to check a file again.

Do not guess a prop, a part or an attribute: read it with `phem_component`. Do not write PHP in
the markup: compute the values before the `return`.

## Cheatsheet

!`vendor/bin/phemc docs cheatsheet`

## The docs

Read a doc or a section with `phem_doc` and its key, for example `events#poll-the-server`. Call
`phem_doc` with no key (or run `vendor/bin/phemc docs --toc`) for the key of each section.

!`vendor/bin/phemc docs`
