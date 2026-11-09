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
- the `.phem` markup is compiled and checked by `phemc`, and it follows React, not Blade or Twig;
- a handler runs in a Doctrine transaction.

Talk about each item before its implementation. The open questions are a start, not a full list.

- [ ] **Streams** (section 1). The items of a stream are not in the state. How does a later
      event render the list, and how does the "before/after" comparison see it?
- [ ] **Refresh after a handler** (section 3). An explicit `#[Refresh]`, or an automatic refresh
      from the Doctrine unit of work? How does Phem find the components that show a changed
      entity?

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

## 2. Installation

### [ ] The Flex recipe in symfony/recipes-contrib — P3

Low priority for now. Until the recipe is in recipes-contrib, the app enables the bundle and
copies the files of `recipe/` by hand.

The getting-started guide and the README show 3 commands: `symfony new my-app --webapp`,
`composer require phem/phem`, and `symfony serve`. This flow needs the recipe of `phem/phem` in
[symfony/recipes-contrib](https://github.com/symfony/recipes-contrib). Until the recipe is there,
Flex does not enable the bundle and does not add the first page or the `phemc` worker.

- **Plan:**
  - Release Phem 0.3.0. The recipe goes in `phem/phem/0.3/`. A 0.2 recipe would also apply to
    0.2.3, which has no automatic routes of the pages.
  - Copy `recipe/` to `phem/phem/0.3/` in symfony/recipes-contrib, and open the pull request.
  - After the merge, test the 3 commands in a new application, with no local Flex endpoint.

## 3. Developer experience

These items come from a review of a real app (a shop with a customer side and a staff side, about
5,700 lines of `.phem`) on 2026-11-08. Each item removes code that the app repeats.

### [ ] Refresh after a handler without `$e->refresh()` — P2

15 handlers of the app start with `$e->refresh()`. A comment tells why: "each
StaffOrderLineItem reads the order, and its props do not change". When a handler forgets the
call, the page shows old data, with no error.

- **Best peer:** Blazor `StateHasChanged`, LC re-render of the component.
- **Proposal A (explicit):** a `#[Refresh]` attribute on a class or on a handler:

  ```php
  #[Page('/gestion/pedidos/{id:order}', name: 'staff_order'), Broadcast, Transactional, Refresh]
  final class StaffOrderPage
  ```

- **Proposal B (automatic):** with `#[Transactional]`, Phem reads the Doctrine unit of work at
  the flush, and renders again each component with a changed entity in a prop or a `#[Path]`.
- **Open questions:** see "Refresh after a handler" in the design discussions.
