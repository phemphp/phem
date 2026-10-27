# TODO

Features that Phem does not have yet. Each item takes the best design from the peers:
Symfony UX Live Components (LC), Livewire 4 (LW), Phoenix LiveView (LV), Blazor Server, and
React. The syntax in each item is a sketch, not a decision.

Priority: **P1** blocks real applications. **P2** is expected by users of the peers.
**P3** is a good addition.

## Design discussions

These items need a Phem-specific design. We cannot copy a peer as it is, because of one of
these properties of Phem:

- the state lives in the browser, signed, with one value for all the components of the page;
- the server keeps no session and renders the full tree two times for each event;
- the `.phpm` markup is compiled and checked by `phemc`, and it follows React, not Blade or Twig;
- a handler runs in a Doctrine transaction.

Talk about each item before its implementation. The open questions are a start, not a full list.

- [ ] **Streams** (section 1). The items of a stream are not in the state. How does a later
      event render the list, and how does the "before/after" comparison see it?
- [ ] **Live validation** (section 2). The touched fields go in the signed state. How does
      this work with a Symfony form, and with `#[Assert]` on `#[State]` properties? Does
      validation run in the Doctrine transaction?

## 1. Rendering and performance

### [ ] A fiber id on the root element, not a `<phem-c>` — P2

The `<phem-c>` wrapper is not valid in `<table>`, `<tr>` and `<select>`, and each component has
one more DOM node. `phemc` checks that a markup literal has one root element.

- **Best peer:** React and Blazor have no wrapper; LV puts the id on the root element.
- **Proposal:** `data-phem-c="<id>"` on the root element of the component. Keep `<phem-c>` only
  for a root that is a text, a fragment or another component. The morph targets and the
  `data-phem-keep` placeholders change with it.

### [ ] Smaller keep placeholders and shape changes — P2

`make bench`: a click on a page with 200 row components that changes only the page sends about
15 KB. About 10 KB are the `<phem-c … data-phem-keep>` placeholders of the rows, and about 3.5 KB
are the new `_tree` value (all its entries, also when only one hash changed).

- **Proposal:** a range placeholder for a run of kept siblings, and a diff of the `_tree` entries
  (the fibers that rendered), as for the state entries.

### [ ] Streaming SSR of the `<Suspense>` boundaries — P3

A boundary loads with a second request after the page (now one load event for all of them).

- **Best peer:** React 18 streaming SSR: the shell first, then each boundary as a `<template>` and
  a small script in the same response.
- **Open question:** the state is signed at the end of the render. The last chunk must give the
  final state and signature, and an event before it must wait.

### [ ] An event with no render of the path to its handler — P3

The first pass of an event renders the ancestors of the handler component, to get the handler
closure. `make bench`: about 0.24 ms for a leaf under 11 levels, so it is not a problem now.

- **Proposal (only when the benchmark shows a need):** a method handler with no captured values,
  of a component with no `#[Prop]`, runs on the instance from the state, with no render of the
  path.

### [ ] Streams for long lists — P3

- **Best peer:** LV streams: insert, update and delete items in a list without the list in
  server memory or in the state.
- **Proposal:** a `Stream` value. A handler calls `$this->messages->insert($m)` or
  `->delete($m)`, and the response has a Turbo Stream `append` / `remove` for the item. The
  list is not part of `#[State]`. Good for chats, feeds and infinite scroll.

## 2. Forms and validation

### [ ] Live validation of touched fields — P2

Symfony forms work in a submit handler. There is no validation while the user types.

- **Best peer:** LC `ValidatableComponentTrait` + `ComponentWithFormTrait`: it validates on each
  change but shows the errors only for the fields that the user touched. LW `#[Validate]` on a
  property, with real-time validation. LV changesets with `used_input?`.
- **Proposal:**
  - `#[Assert\...]` constraints on `#[State]` properties, validated after `bind` changes.
  - Track touched fields in the state. Show errors only for them, and for all fields after a
    submit.
  - For a Symfony form: keep the submitted values in the state, and call `submit()` with
    `clearMissing: false` on each change (as LC does).

## 3. Client escape hatch

Decision: the client code is a Stimulus controller, as in any Symfony page. `phemc` does not
extract colocated client code (`<script>` in a `.phpm` file).

### [ ] Built-in Stimulus controllers — P2

Each app writes the same controllers again: a dropdown, a dialog, tabs. A generic library (for
example stimulus-components) does not know the morph, so the user must add `data-phem-client`
and `data-phem-ignore` by hand.

- **Best peer:** LV `core_components` + `JS` commands, stimulus-components, Symfony UX packages.
- **Proposal:** a small set of Phem controllers. Each controller keeps its DOM changes after the
  morph of an event: with its own `data-phem-client` attributes, or by applying the changes again
  on `turbo:morph-element` (as `phem-optimistic` does). `phem-optimistic`, `phem-toggle`,
  `phem-dialog`, `phem-tabs` and `phem-toast` are done.
  - `phem-clipboard`, `phem-autofocus` (also after a morph), `phem-confirm` before a server
    event.
- **Rules:**
  - The `phem-` prefix, so the names do not clash with the controllers of the app.
  - Each controller is an entry of `symfony.controllers` in `assets/package.json` with
    `"fetch": "lazy"`. The app enables it in `controllers.json`.
  - Accessible, with no dependency. Vitest + jsdom tests, as for `phem_controller.js`.
  - Add a controller only when a Phem detail gives it value, or when almost all apps need it.
