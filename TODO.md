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
- [ ] **Colocated client code** (section 3). Is the extracted script a Stimulus controller (as
      the `data-phem-client` controllers), or a smaller hook API? How does `phemc` give it to
      AssetMapper in development (`phemc watch`) and in production?

## 1. Rendering and performance

### [ ] Client commands — P1

Each UI change is a round trip: a menu toggle, a tab, a modal. Without a server event, the app
needs its own Stimulus controller.

- **Best peer:** LV `Phoenix.LiveView.JS`: `JS.toggle`, `JS.show`, `JS.add_class`,
  `JS.dispatch`, chained with `JS.push` (a server event).
- **Proposal:** `onClick={{ js.toggle('#menu') }}`, `js.show()`, `js.hide()`, `js.addClass()`,
  `js.setAttr()`, `js.focus()`, `js.dispatch()`, and `.then(handler)` for a server event after
  them. `phemc` checks them and writes data attributes. The morph keeps their DOM changes, as it
  keeps the `data-phem-client` attributes.

### [ ] Optimistic updates — P2

- **Best peer:** React `useOptimistic`, LV `phx-click` + `JS` commands.
- **Proposal:** with the client commands: `phem:optimistic={{ js.addClass('liked') }}` runs at
  once, and the morph of the event confirms it or reverts it.

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

### [ ] Colocated client code and refs — P2

Some behavior must run in the browser: a chart, a map, a rich text editor, a focus.

- **Best peer:** LV 1.1 colocated hooks: a `<script :type={ColocatedHook}>` next to the markup,
  extracted at compile time, with `mounted`, `updated` and `destroyed` callbacks. React refs and
  `useEffect`. LW Alpine.
- **Proposal:**
  - `phemc` extracts a `<script type="phem/controller">` from a `.phpm` file into a Stimulus
    controller and registers it with AssetMapper. The component root gets its
    `data-controller` automatically.
  - The controller gets the server events of the item "Server-to-browser events".
