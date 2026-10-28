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
- [ ] **Live validation** (section 2). The touched fields and the errors go in the signed
      state. Does validation run in the Doctrine transaction? How does a submit handler stop when
      the state has errors?

## 1. Rendering and performance

### [ ] An event with no render of the path to its handler — P3

The first pass of an event renders the ancestors of the handler component, to get the handler
closure and the props of the component. This is a documented limit (docs/how-it-works.md, Limits).
A stress run on 2026-10-28 (the server time, with no network):

- A leaf with no `#[Prop]`: about 0.3 ms under 10 levels, 15 ms under 400 levels, 45 ms under 800.
  Real pages have fewer than 50 levels.
- A click on one row of a list: about 9 ms for 1000 rows and 46 ms for 5000 rows, with a full
  render of the page in 24 ms and 130 ms. About half of the event is the first pass: the
  `render()` of the list, and Twig for each row. The rest is the state (decode, sign, shape, the
  cache write).

- **Proposal (only when the benchmark shows a need):** a method handler with no captured values,
  of a component with no `#[Prop]`, no `#[Context]` and no slot, runs on the instance from the
  state, with no render of the path. It helps a deep leaf, not a row of a list: a row has props.
- **Open question for lists:** the JSON props of each fiber in the signed state, so a row renders
  with no render of its parent. The state gets larger, and a prop that is not a JSON value (a
  closure, markup, an entity) still needs the render of the path.

### [ ] Streams for long lists — P3

- **Best peer:** LV streams: insert, update and delete items in a list without the list in
  server memory or in the state.
- **Proposal:** a `Stream` value. A handler calls `$this->messages->insert($m)` or
  `->delete($m)`, and the response has a Turbo Stream `append` / `remove` for the item. The
  list is not part of `#[State]`. Good for chats, feeds and infinite scroll.

## 2. Forms and validation

### [ ] Validation of `#[State]` properties, with touched fields — P2

Live validation works today, by hand: `#[Assert]` on the `#[State]` properties, a `touched` and
an `errors` property, and a handler that calls the validator (see
[Live validation](docs/phpm-syntax.md#live-validation)). Each form repeats this code. It is not a
blocker, so build it only when a real form shows that the manual code is too much.

- **Best peer:** LC `ValidatableComponentTrait`: it validates on each change but shows the errors
  only for the fields that the user touched. LW `#[Validate]` on a property, with real-time
  validation. LV changesets with `used_input?`.
- **Proposal:**
  - Validate the `#[Assert\...]` constraints of the `#[State]` properties after a `phem:bind`
    change, with no handler.
  - Track the touched fields in the state. Show the errors only for them, and for all the fields
    after a submit.
  - Give the errors to `render()` (for example `errors.email`), and let a submit handler stop
    when there are errors.
- **Not in scope:** live validation of a Symfony form, as LC `ComponentWithFormTrait` does (it
  keeps the submitted values and calls `submit()` with `clearMissing: false` on each change).
  It does not fit Phem:
  - The fields of a Symfony form are not bound fields, so their values are not in the state.
  - The submitted values of all the form would go into the signed state.
  - Each change would submit all the form again.

  LC needs it because LC is built around Symfony forms. In Phem, use bound properties for the
  fields that need live validation, and a Symfony form for the submit.
