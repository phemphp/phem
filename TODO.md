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
- [ ] **Objects in `#[State]`** (section 3). How does the signed state keep an object, and which
      classes can it hold? How does `phem:bind={{ draft.name }}` set a property of the object?
- [ ] **Refresh after a handler** (section 3). An explicit `#[Refresh]`, or an automatic refresh
      from the Doctrine unit of work? How does Phem find the components that show a changed
      entity?
- [ ] **Entity broadcasts** (section 3). Which pages and components get a refresh for a changed
      entity, and how does a `#[Path]` entity select one URL of a page?

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

### [ ] Objects in `#[State]` — P2

The state holds only JSON values and backed enums. A form for an entity needs one flat
property for each field: the product form of the app has 11. `mount()` copies 9 entity fields one
by one, and `save()` gives them back in a call with 9 arguments:

```php
#[State] public string $name = '';
#[State] public ?int $categoryId = null;
// … 7 more

public function mount(): void
{
    $this->name = $p->name;
    $this->categoryId = $p->category->id;
    // … 7 more
}
```

- **Best peer:** LW form objects, Blazor `EditForm Model`.
- **Proposal:** a `#[State]` property can hold an object of JSON values and backed enums (a
  draft). The Serializer encodes it in the state. `phem:bind` and `Validation` already use paths:

  ```php
  #[State, Assert\Valid] public ProductDraft $draft;

  public function mount(): void
  {
      $this->draft = ProductDraft::of($this->product);
  }

  #[Validated(groups: ['basics', 'details'])]
  public function save(): void
  {
      $this->product->apply($this->draft);
  }
  ```

  ```php
  <input phem:bind={{ draft.name }} />
  ```

- **Open questions:** see "Objects in `#[State]`" in the design discussions.

### [ ] Toast messages from a handler — P3

13 handlers of the app repeat
`$e->dispatcher->dispatch('toast', ['message' => …, 'type' => 'error'])`. A wrong `type` fails
with no error.

- **Best peer:** LV `put_flash`, the Flux toast of LW.
- **Proposal:** a method on the event, and a service for code outside a handler:

  ```php
  $e->toast('Guardado');
  $e->toast(NO_STOCK, ToastType::Error);

  public function __construct(private readonly Toasts $toasts) {}
  $this->toasts->error('El horario empieza antes de terminar.');
  ```

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

### [ ] Entity broadcasts — P2

The app has a Doctrine listener of 220 lines (`App\Shop\LiveUpdates`). It maps each entity
change to the `toPage()` and `toComponent()` calls by hand, and a new live component must go in
it too. It also has this form:

```php
$this->streams->toPage(StaffOrderPage::class, ['order' => ['id' => $order->id]])->refresh();
```

- **Best peer:** Symfony UX Turbo `#[Broadcast]` on a Doctrine entity.
- **Proposal:** a component tells which entities it shows, and a Phem Doctrine listener sends
  the refresh after the commit:

  ```php
  // A refresh for the Order of the #[Path] only.
  #[Page('/gestion/pedidos/{id:order}'), Broadcast(on: Order::class)]
  final class StaffOrderPage

  // A refresh for a new Order, a change of its status, or any change of an OrderLine.
  #[Component, Broadcast(on: [Order::class => ['status'], OrderLine::class => '*'])]
  final class StaffAlerts
  ```

- **First step:** `toPage()` accepts the entity of a mapped route value:
  `toPage(StaffOrderPage::class, ['order' => $order])`.
- **Open questions:** see "Entity broadcasts" in the design discussions.

### [ ] Enum cases and page links in markup — P3

The markup of the app compares enums as strings (`{% if status.value == 'cancelled' %}`), and it
links with 64 `path('route_name')` strings. `phemc` checks none of them.

- **Best peer:** the Twig `enum()` function, typed routes of Blazor.
- **Proposal:** the markup can use the classes that the file imports with `use`, and `phemc`
  checks the names:

  ```php
  {% if status == OrderStatus.Cancelled %}
  <a href={{ path(StaffOrdersPage) }}>Volver a pedidos</a>
  <a href={{ path(ProductPage, {product: product}) }}>Ver en la tienda</a>
  ```

  Most `name:` arguments of `#[Page]` are then not necessary.

### [ ] A lint for copied properties, and docs for UI-only state — P3

- **Copied properties:** the app has 33 lines such as `$lines = $this->lines;` in `render()`.
  The markup already finds the properties, also the private ones. Add a `phemc` hint with a
  quick fix: "This variable copies `$this->lines`: use `lines` in the markup".
- **UI-only state:** a page of the app keeps `askingCancel` in `#[State]`, with an
  `openCancel()` and a `closeCancel()` handler: two requests to open a panel. The `phem-dialog`
  controller does it in the browser. Add a section "Keep UI-only state in the browser" to
  `docs/components.md`.
- **Variants:** the app keeps its button classes in constants (`PRIMARY`, `DANGER`). The
  [variants pattern](docs/packages.md#add-variants-to-a-component) covers it, but the docs show it
  only for packages. Link to it from `docs/components.md`.
  