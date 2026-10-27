# The `.phpm` file format

A `.phpm` file is a PHP 8.5 file that can contain **markup literals**, the same way a `.jsx` file is a
JavaScript file that contains JSX. The `phemc` compiler turns each markup literal into a Twig
template and turns the PHP code into plain PHP. All parsing happens at build time.

A `.phpm` file opens PHP with `<?phpm`. A plain `<?php` tag is an error (E006). The compiler
writes `<?php` in the PHP that it generates.

## 1. Components

A component is a PHP function with the `#[Component]` attribute. It returns markup: give it the
return type `Phem\Node` (see [Markup values](#10-markup-values)). As in React, a string that it
returns is text: Phem escapes it.
A component with state is a `#[Component]` class (see section 7).

```php
<?phpm
namespace App\Component;

use Phem\Attribute\Component;
use Phem\Node;

#[Component]
function MyButton(): Node {
    return (
        <button>I'm a button</button>
    );
}

// A plain PHP function: it is not a component.
function initials(string $name): string {
    return strtoupper($name[0]);
}
```

A function without `#[Component]` is a plain PHP function, as in a `.php` file. Its name can
start with a capital letter or not. A tag cannot use it (E010), and the manifest does not
list it. Without `use Phem\Attribute\Component;`, `#[Component]` is another attribute (W004).

Use a component with a tag: `<MyButton />`. The compiler resolves the tag with the PHP rules:
a `use function` import, then a `use` (class) import, then the current namespace.

A page is a class or a function with the `#[Page]` attribute. React uses `export default` for
the page component of a file. In Phem, `#[Page]` exposes a component at a URL, as `#[Route]` does
for a Symfony controller. The file stays valid PHP. A page is a component: it needs no
`#[Component]`. It renders its content in the document of the application, a component that
renders the `<html>` element and the `<Root>` of the page (see
[compiling.md](compiling.md#the-document-of-the-pages)). The page gives the title with the
`title` slot of the document.

```php
use Phem\Attribute\Page;
use Phem\Node;

use function App\Component\Document;

#[Page('/', name: 'home')]
final class MyApp
{
    public function render(): Node
    {
        return (
            <Document>
                <Document:title>Home</Document:title>
                <h1>Welcome to my app</h1>
                <MyButton />
            </Document>
        );
    }
}
```

`#[Page]` extends the Symfony `#[Route]` attribute, and it has the same options: `name`, `methods`, `requirements`, `defaults`, `host`,
`schemes`, `condition`, `priority`, `env`, `alias`, and localized paths. Use it more than once to
give a page more than one URL. Without a `name`, the route name comes from the component:
`App\Page\Example\Counter` → `app_page_example_counter`. A file can have any number of
pages, in any directory. The Symfony `#[Route]` attribute works too, on a `#[Component]` class.

### Function pages

A page with no state of its own can be a function:

```php
use Phem\Attribute\Page;
use Phem\Node;

use function App\Component\Document;

#[Page('/about')]
function About(): Node
{
    return (
        <Document>
            <Document:title>About</Document:title>
            <h1>About us</h1>
            <Counter />
        </Document>
    );
}
```

The components of a function page can have state and handlers, as `<Counter />` here: their
events go to the URL of the page.

A page is not a tag (E056), so a function page has no props. Its parameters get the arguments
of a Symfony controller: the route values, the `Request`, the services, `#[CurrentUser]`,
`#[MapQueryParameter]`, `#[Autowire]`, and the other value resolvers of Symfony.

```php
use App\Repository\ItemRepository;
use Phem\Attribute\Page;
use Phem\Node;
use Symfony\Component\HttpFoundation\Request;

#[Page('/items/{id}')]
function Item(int $id, ItemRepository $items, Request $request): Node
{
    $item = $items->find($id);

    return <Document><h1>{{ item.title }}</h1></Document>;
}
```

A route value goes to the parameter with its name, as for a `#[Path]` property: Phem converts
it to the type of the parameter (an `int`, an entity with `{slug:item}`), and a value that does
not match gives a 404 response. The route loader reports a route value with no parameter.

In an event of a component of the page, the page function runs again with the request of the
event: a `POST` request with the same route values. Thus `$request->getMethod()` is `POST`
there. Read the data in the function, not in the request of the first render.

The Symfony `#[Route]` attribute cannot mark a function (E052). Use `#[Page]`.

### Route values

A route value goes to the page as a prop. Mark the public property with `#[Path]` (it needs no
`#[Prop]`). The name of the property is the name of the route value. A function page gets its
route values as parameters (see [Function pages](#function-pages)).

```php
use Phem\Attribute\{Page, Path};
use Phem\Node;

use function App\Component\Document;

#[Page('/hello/{name}', defaults: ['name' => 'World'])]
final class Hello
{
    #[Path] public string $name;

    public function render(): Node
    {
        return <Document><p>Hello {{ name }}</p></Document>;
    }
}

#[Page('/items/{id}', requirements: ['id' => '\d+'])]
final class ItemPage
{
    #[Path] public int $id;

    public function render(): Node
    {
        return <Document><h1>Item {{ id }}</h1></Document>;
    }
}
```

- The route values are the placeholders (`{id}`) and the `defaults` keys. The keys that start
  with `_` (`_locale`, `_format`, ...) are not route values.
- Each route value needs a `#[Path]` prop. A route value without one is an error when Symfony
  loads the routes.
- A prop without `#[Path]` gets no route value.
- A string goes to an `int`, `float` or `bool` prop as a value of that type: `/items/3`
  gives `$id = 3`. A value that does not match the type gives a 404 response. Use
  `requirements` to reject it before.
- The events of the page get the same route values. They go into the signed state of the page,
  so a client cannot change them.
- A `#[Path]` property cannot be `#[State]`.

### Entities from route values

A `#[Path]` prop with a Doctrine entity type gets the entity. The rules are the same as for the
entity arguments of a Symfony controller. Install `doctrine/orm` and `doctrine/doctrine-bundle`.

```php
use App\Model\Scientist;
use Phem\Attribute\{Page, Path};
use Phem\Node;

use function App\Component\Document;

#[Page('/scientists/{id:scientist}', requirements: ['id' => '\d+'])]
final class ScientistPage
{
    #[Path] public Scientist $scientist;

    public function render(): Node
    {
        return (
            <Document>
                <Document:title>{{ scientist.name }}</Document:title>
                <h1>{{ scientist.name }}</h1>
            </Document>
        );
    }
}
```

- `{scientist}` (the name of the prop) finds the entity by its identifier: `find($value)`.
- `{id:scientist}` maps the route value `id` to the prop `scientist`, and finds the entity by
  the field `id`: `findOneBy(['id' => $value])`. `{slug:scientist}` finds it by the field
  `slug`.
- `{slug:post.title}` finds the `post` entity by the field `title`. Use more than one mapping
  for more than one field: `/{year:post.year}/{slug:post.slug}`.
- When no entity matches, the response is a 404. A nullable prop (`?Scientist`) gets `null`.
- The signed state keeps the route values, not the entity. Each event finds the entity again.
  When the entity is not there any more, the browser loads the page again.
- A mapped route value needs a `#[Path]` prop with a class type. A class type that no converter
  supports is an error (see [Other types of route values](#other-types-of-route-values)).

### Other types of route values

A converter gives a route value another type: a date, an enum, a value object. Make a service
that implements `Phem\Path\PathValueConverter`. Phem autoconfigures it with the
`phem.path_converter` tag:

```php
use Phem\Path\PathProp;
use Phem\Path\PathValueConverter;

final class DateConverter implements PathValueConverter
{
    public function supports(PathProp $prop): bool
    {
        return \DateTimeImmutable::class === $prop->type->getName();
    }

    public function convert(PathProp $prop, mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return $prop->type->allowsNull() ? null : throw new \UnexpectedValueException('No date.');
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value) ?: throw new \UnexpectedValueException(\sprintf('"%s" is not a date.', $value));
    }
}
```

```php
#[Page('/agenda/{day}')]
final class AgendaPage
{
    #[Path] public \DateTimeImmutable $day;
    // ...
}
```

- `$prop` has the class of the page (`$prop->page`), the name of the prop (`$prop->name`), and
  its type (`$prop->type`, a `\ReflectionNamedType`). A function page gives its parameters in the
  same way.
- `$value` is the route value: a string, a default of the route, or the criteria of a mapped
  value (`{slug:post}` gives `['slug' => '...']`).
- Throw an `\UnexpectedValueException` when the value does not match: the page gives a 404
  response, and an event loads the page again.
- The first converter that supports the prop converts it. The built-in converters (the scalar
  types and the Doctrine entities) have the priority -100, so a converter of the application
  goes first.
- The signed state keeps the route value, not the converted value. Each event converts it again.

## 2. Markup rules (the same as JSX)

- Close all tags: `<br />`, `<img ... />`.
- Return one root. To return more than one element, use a fragment: `<>...</>`.
- Lowercase tags are HTML elements. Capitalized tags are components.
- `className` is an alias for `class`, and `htmlFor` is an alias for `for`.
- A markup literal starts where a PHP expression starts: after `return`, `(`, `=`, `,`, `=>`, `?`, `:`, `??`, `[`, `echo`.

## 3. Twig in markup

Inside markup, the text and the attribute values are Twig. The Twig variables are the PHP
variables of the scope that contains the markup (`$user` is `user` in Twig). The Twig global
`app` of Symfony is also available (`{{ app.user.userIdentifier }}`).

```php
#[Component]
function Profile(): Node {
    $user = ['name' => 'Hedy Lamarr', 'imageUrl' => '/img/hedy.jpg', 'imageSize' => 90];

    return (
        <>
            <h1>{{ user.name }}</h1>
            <img
                className="avatar"
                src={{ user.imageUrl }}
                alt={{ 'Photo of ' ~ user.name }}
                style={{ {width: user.imageSize, height: user.imageSize} }}
            />
        </>
    );
}
```

- `{{ expr }}` in text prints the value. Twig escapes it, except `Html` values (markup).
- `attr="text {{ expr }}"` is a string with interpolation.
- `attr={{ expr }}` is a dynamic value. `null` and `false` remove the attribute. `true` makes a
  boolean attribute. For `class`, a list is joined with spaces (empty items are dropped).
  As in React, `data-*`, `aria-*`, `contenteditable`, `draggable`, `spellcheck` and `value`
  take the text of a boolean: `data-open={{ false }}` gives `data-open="false"`. A CSS selector
  `[data-open]` and `hasAttribute()` find it; use `null` to remove the attribute.
- `style={{ {...} }}` takes a hash. camelCase keys become kebab-case. Numbers get `px`
  (except unitless properties such as `opacity`, `zIndex`, `fontWeight`, `lineHeight`, `flex`).
- `{{ ...props }}` in a tag spreads a hash of attributes (or props, on a component). As in JSX,
  the order counts: a later value replaces an earlier one. `<button type="button" {{ ...rest }}>`
  gives a default that `rest` can replace. `on*` keys (`onClick`, ...) bind handlers.
  A spread can contain data, so it does not render an invalid attribute name or an inline
  script (`onclick`, with a string). A static `onclick="..."` in the markup stays.
- As in React, a URL attribute (`href`, `src`, `action`, `formAction`, `xlink:href`) with a
  `javascript:` URL from an expression is not rendered: `<a href={{ user.website }}>` with
  `javascript:alert(1)` gives `<a>`. This is also true for a quoted value with an expression
  (`href="{{ url }}"`) and for a spread. A click on the element would run the URL as a script.
- All Twig filters, tests, and tags work: `|upper`, `|length`, `{% set %}`, `{% for %}`, and more.
- Do not put PHP code in markup. Compute values in PHP before the `return`.

## 4. Conditional rendering

```php
// if/else in PHP: a markup literal is a value (an Html object)
if ($isLoggedIn) {
    $content = <AdminPanel />;
} else {
    $content = <LoginForm />;
}
return <div>{{ content }}</div>;
```

```twig
<div>{{ isLoggedIn ? <AdminPanel /> : <LoginForm /> }}</div>   {# ternary with markup #}
<div>{{ isLoggedIn and <AdminPanel /> }}</div>                 {# like JSX && #}
<div>{% if isLoggedIn %}<AdminPanel />{% else %}<LoginForm />{% endif %}</div>
```

## 5. Lists and keys

```php
#[Component]
function ShoppingList(): Node {
    $products = [
        ['title' => 'Cabbage', 'isFruit' => false, 'id' => 1],
        ['title' => 'Garlic', 'isFruit' => false, 'id' => 2],
        ['title' => 'Apple', 'isFruit' => true, 'id' => 3],
    ];

    return (
        <ul>
            {% for product in products %}
                <li key={{ product.id }} style={{ {color: product.isFruit ? 'magenta' : 'darkgreen'} }}>
                    {{ product.title }}
                </li>
            {% endfor %}
        </ul>
    );
}
```

Or in PHP, like `map()`: an array of markup prints as a list.

```php
$listItems = array_map(fn($product) => <li key={{ product.id }}>{{ product.title }}</li>, $products);
return <ul>{{ listItems }}</ul>;
```

The key goes in the `data-phem-key` attribute. After an event, the browser matches the items by
their key, so an item keeps its element (its focus, the changes of a Stimulus controller) when an
item before it goes away.

## 6. Events

Pass the function. Do not call it.

```php
#[Component]
function MyButton(): Node {
    $handleClick = fn() => error_log('You clicked me!');

    return <button onClick={{ handleClick }}>Click me</button>;
}
```

A member path is the method of an object: `onClick={{ tabs.select }}` runs `$tabs->select()`
(in Twig, `tabs.select` alone calls the method). The object is the owner of the handler, so it
renders again after the event. A key of an array keeps its value: `onClick={{ row.onRemove }}`
runs the closure in `$row['onRemove']`.

Supported events: `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`, `onKeyDown`, `onKeyUp`,
`onFocus`, `onBlur`. phemc warns about another event (W003): the browser does not send it.
The handler runs on the server. It can receive a `Phem\Event` object with `$e->value`,
`$e->checked`, `$e->key`, `$e->form` (an array, for `onSubmit`), and `$e->files` (the files of
the form, see [File uploads](#file-uploads)). See [Handler arguments](#handler-arguments).
`onChange` on a text input fires on each key (as in React), but the browser waits until the user
stops typing for 150 ms before it sends the event (see [Debounce and throttle](#debounce-and-throttle)).
A field that only copies its value into the state needs no handler: see [Bound fields](#bound-fields).

`$e->form` has the fields of the form by name. A name with more than one value (a
`<select multiple>`, checkboxes) has the list of its values. The button that submits the form
is a field too. The files of the form are in `$e->files`, not in `$e->form`.

The id of a handler comes from the handler (its method or closure, and the values that it
captures, such as `fn () => $this->remove($scientist)`), not from its position. When the data
changes before a click (another user removes a row), a click never runs the handler of
another row: the page reloads when its handler is not there any more. A captured Doctrine
entity counts by its identifier.

An event is a `POST` request to the URL of the page, with the `X-Phem-Event` header. Thus the
access rules of the page apply to its events too: `access_control`, the firewall, and the host
and condition of the `#[Page]`. The controller attributes of Symfony (`#[IsGranted]`, `#[Cache]`)
apply to a page (a class or a function), for the page and for its events. Phem accepts an event only
from the same origin (`Sec-Fetch-Site`, or `Origin`), as JSON or as `multipart/form-data` with
files, so another site cannot send one (CSRF). A `POST` request without the header renders the page, if its route accepts `POST`.

### Handler arguments

A handler gets its arguments as the action of a Symfony controller. The event is one of them: a
handler declares it at any position, or not at all. A method of a class component can also get
the `Request`, the services, `#[CurrentUser]`, `#[Autowire]`, and the other value resolvers of
Symfony:

```php
use Phem\Event;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

public function save(Request $request, NoteRepository $notes, #[CurrentUser] User $user, Event $e): void
{
    $notes->add($user, $e->value);
}

public function reset(): void
{
    $this->count = 0;
}
```

A closure of `render()` gets the event and the `Request`, but no services: it has no method for
the Symfony locator. Use `$this` and the constructor for them. A parameter with no type gets
nothing: write `fn (Event $e) => ...`. An `#[Async]` handler gets its arguments again in the
worker, with the request of the worker: it has the route values and the user, but no session.

The `Request` of a handler has the form fields as its POST data. The keys with brackets become
nested arrays (`scientist[name]` → `['scientist' => ['name' => ...]]`), as in a normal POST. A
Symfony form can read it directly:

```php
public function add(Request $request): void
{
    $form = $this->forms->create(ScientistType::class);
    $form->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
        // $form->getData()
    }
}
```

To render the form, keep it in a property. A `get` hook (PHP 8.4 property hooks) makes a new
form when no handler made one, so `render()` shows the validation errors after a failed submit
and an empty form in the other renders:

```php
private ?FormInterface $form = null {
    get => $this->form ??= $this->forms->create(ScientistType::class);
}

public function render(): Node
{
    $view = $this->form->createView();

    return (
        <form onSubmit={{ add }}>
            {{ form_widget(view.name) }}
            {{ form_errors(view.name) }}
            <button type="submit">Add</button>
        </form>
    );
}
```

### File uploads

The browser sends the files of a form with its submit event. The handler gets each file as a
Symfony `UploadedFile`, in `$e->files` and in the files of the `Request` argument. Thus a form
with a `FileType` field works with `handleRequest($request)`, as in a normal POST:

```php
public function add(Request $request): void
{
    $form = $this->forms->create(ScientistType::class)->handleRequest($request);
    if ($form->isSubmitted() && $form->isValid()) {
        $photo = $form->get('photo')->getData(); // an UploadedFile, or null
        $photo?->move($this->photoDir, $photo->guessExtension() ?? 'bin');
    }
}
```

`$e->files` has the shape that PHP gives: `photo` is an `UploadedFile`, `photos[]` is a list,
and `scientist[photo]` is an array. A file input with no file sends nothing.

A file is valid only for its request, as in a normal POST: PHP removes it at the end of the
request, if the handler did not move it. Phem does nothing more with the file. The handler
decides where to keep it, and what to do with it when the transaction of the handler rolls back.
Use `#[Assert\File]` (or the `constraints` of a `FileType`) to check its size and type.

An event with files goes as `multipart/form-data`, with the JSON of the event in a `_phem` part.
The other events stay JSON. The files of an event have a maximum size together:
`phem.max_upload_size` bytes (default 8388608, 8 MiB; 0 for no limit). Over the limit, or over
the `post_max_size` option of PHP, the server answers `413 Content Too Large` and does not run
the handler. A file over the `upload_max_filesize` option of PHP gets to the handler with an
upload error and no content (`$file->isValid()` is false), so `#[Assert\File]` shows the error
in the form. The `File` and `Image` constraints need the `symfony/mime` package.

```yaml
# config/packages/phem.yaml
phem:
    max_upload_size: 20971520
```

The browser sends a file again with each new submit, and with each retry of a failed event.
While the files upload, the loading state shows (see [Loading states](#loading-states)).

To show the progress of the upload, give the handler to `phem:progress`:

```html
<form onSubmit={{ save }}>
    <input type="file" name="photos[]" multiple />
    <progress phem:progress={{ save }} max="100"></progress>
    <button type="submit">Upload</button>
</form>
```

The server renders the element with `hidden`, as `phem:loading`. The browser shows it only while
the files of a `save` event upload, and until the response arrives, after the delay of the loading
state. An event with no files does not show it. The `--phem-progress` CSS property of the element
is the progress, from 0 to 1, so any element can show it:
`<div phem:progress={{ save }} className="bar"></div>` with
`.bar { width: calc(var(--phem-progress) * 100%) }`. A `<progress>` element also gets it as its
`value`, times its `max`.

The progress is for the full body of the event. For each file, listen to `phem:upload-progress`.
The form dispatches it on each step of the upload, and it bubbles:

```js
form.addEventListener('phem:upload-progress', (e) => {
    for (const { field, file, loaded, progress } of e.detail.files) {
        console.log(field, file.name, loaded, progress);
    }
});
```

The detail has the `handler`, the `loaded` and `total` bytes of the body, the `progress` (0 to 1),
and the `files`. The body has the JSON of the event too: the browser shares the progress between
the files by their size, in their order. An event with files goes with `XMLHttpRequest`, because
`fetch` cannot give the progress of an upload. A retry starts the progress again from 0.

A handler can return a `Phem\RedirectTo` to a page. Then the page does not render again: the
browser goes to the page (a Turbo visit). The redirect names the page by its class name, or a
function page by its first-class callable (`ScientistPage(...)`, with `use function`). The second
argument has the route values. The values that are not route values go to the query string, and
`_fragment` sets the fragment. For example, after a delete:

```php
use Phem\RedirectTo;

public function delete(): RedirectTo
{
    $this->scientists->remove($this->scientist);

    return new RedirectTo(ScientistList::class, ['page' => 2]);
}
```

```php
use function App\Page\ScientistPage;

return new RedirectTo(ScientistPage(...), ['id' => $scientist->id]);
```

The URL comes from the first route of the page: each page has its class name (or its function
name) as an alias of that route. Thus `path('App\\Page\\ScientistList')` in Twig, and
`$urlGenerator->generate(ScientistList::class)` in PHP, also give its URL. A redirect to a class or
a function that is not a page is an error.

For a URL that is not a page (for example another site), a handler can return a Symfony
`RedirectResponse`. Its URL is relative, or an `http:` or `https:` URL: another scheme
(`javascript:`) is an error.

A handler can send browser events with the dispatcher of its event:
`$e->dispatcher->dispatch($name, $detail)`. After the morph, the
browser dispatches each one as a `CustomEvent` named `phem:<name>`. The detail is any value that
JSON can encode. A Stimulus controller or plain JS listens to it:

```php
public function save(Event $e): void
{
    $this->scientists->save($this->scientist);
    $e->dispatcher->dispatch('toast', ['message' => 'Saved']);
}
```

```html
<div data-controller="toast" data-action="phem:toast@window->toast#show"></div>
```

The event starts on the root of the component of the handler (its `<phem-c>`, or the root
element for the page component), and it bubbles. An ancestor of the component gets it with no
`@window`, and `event.target` tells which component sent it. If the morph removed the component,
the event starts on the root element. It always reaches `window`.

```html
<section data-controller="cart" data-action="phem:added->cart#refresh">
    <ProductList />
</section>
```

The dispatcher is also a service (`Phem\BrowserEventDispatcher`). A handler with no `Event`
parameter, or a service that the handler calls, gets it with autowiring:

```php
public function __construct(private readonly BrowserEventDispatcher $dispatcher)
{
}

public function save(): void
{
    $this->dispatcher->dispatch('toast', ['message' => 'Saved']);
}
```

The events go in their order. A handler that fails, or that returns a `RedirectTo` (or a
`RedirectResponse`), sends no events: the browser leaves the page.

### Loading states

While an event runs, the element that sent it and its component get `aria-busy="true"` and the
`phem-loading` class, after a delay of 200 ms (`data-phem-loading-delay-value` on the `<Root>`
element changes it).

To show markup only while a handler runs, give the handler to `phem:loading`:

```html
<button onClick={{ save }}>Save</button>
<span phem:loading={{ save }} className="spinner">Saving…</span>
```

The server renders the element with `hidden`. The browser removes `hidden` while a `save` event
of this component runs, after the same delay. The events of the same handler in another instance
of the component do not show it. A child component that gets `save` as a prop can send the event,
or show the element: `phem:loading` works in both directions. `phem.css` hides the element also
when its CSS gives it a `display`. `phem:loading` is for HTML elements. To show a component, put
it in an element: `<span phem:loading={{ save }}><Spinner /></span>`.

### Debounce and throttle

The browser sends the `onChange` and `onInput` events of a text input (and of a `<textarea>`) when
the user stops typing for 150 ms, not on each key. `data-phem-debounce-value` on the `<Root>`
element changes this default, and `0` turns it off. The other events do not wait.

`phem:debounce` and `phem:throttle` set the wait of the events of one element:

```html
<input onChange={{ search }} phem:debounce={{ 300 }} />
<input onChange={{ validate }} phem:debounce={{ 'blur' }} />
<input type="range" onInput={{ preview }} phem:throttle={{ 500 }} />
```

- `phem:debounce={{ 300 }}`: send an event when no new event of the same handler comes for
  300 ms. `0` turns off the debounce of the element.
- `phem:debounce={{ 'blur' }}`: send the event when the element loses the focus.
- `phem:throttle={{ 500 }}`: send the first event at once, then at most one event each 500 ms.
  The last event is always sent.

They apply to each handler of the element, but not to `onSubmit` and `onBlur`: these never wait.
An event that waits has the value of the last event, so no request has an old value. A blur sends
the waiting event of the element. Any other event (a click, a submit, a key without a wait) sends
all the waiting events first, so the server gets all the input before the click.

When an `onChange` or `onInput` event waits in the queue (an earlier event still runs), a new
event of the same handler and element replaces it: the server gets one request, with the new value.

phemc checks the value: a number of milliseconds, or `'blur'` for `phem:debounce` (E032). An
element cannot have both. An element with no handler reports W006.

### Bound fields

A field that only copies its value into the state needs no handler. `phem:bind` links a form
field to a `#[State]` property of the class:

```php
#[Component]
final class ScientistSearch
{
    #[State] public string $query = '';
    #[State] public bool $activeOnly = false;

    public function search(): void
    {
        // $this->query and $this->activeOnly have the values of the fields here.
    }

    public function render(): Node
    {
        return (
            <form onSubmit={{ search }}>
                <input phem:bind={{ query }} />
                <input type="checkbox" phem:bind={{ activeOnly }} />
                <button type="submit">Search</button>
            </form>
        );
    }
}
```

The field shows the value of the property. A change sends no request: the browser keeps the new
value, and the next event of the page (the submit, any click) sends it. The server sets the
property before it runs the handler. The value of `phem:bind` is the name of the property, not an
expression.

`phem:live` sends each change at once, with no handler. `render()` shows the new value:

```php
<input phem:bind={{ name }} phem:live />
<p>{{ name ? 'Hello, ' ~ name ~ '!' : 'Type your name.' }}</p>
```

A live text field has the debounce of the other text fields (150 ms), and `phem:debounce` changes
it: `phem:debounce={{ 'blur' }}` sends the value when the field loses the focus. A checkbox, a
radio button and a `<select>` send on `change`. A field with `phem:bind` and an `onChange` handler
sets the property first, then it runs the handler.

The type of the property must take the value of the field (E033):

| Field | Property type |
|---|---|
| `<input>` (text, number, date, ...), `<textarea>`, `<select>` | `string`, `int`, `float`, or a backed enum |
| `<input type="radio">` | the same: the `value` of the checked button |
| `<input type="checkbox">` | `bool`; or `array` for a group of checkboxes, with the `value` of each checked box |
| `<select multiple>` | `array`: the values of the selected options |

The server converts the text of a field: `"36"` to `36` for an `int`, `"l"` to the case of a
backed enum, and an empty field to `null` for a nullable `int`, `float` or enum. A value that the
type does not take (`"abc"` for an `int`) is ignored: the property keeps its value, and the field
shows the value of the server again.

A path binds a value in an `array` property. A segment is a name (`.status`), an index (`.0`), or
a key that the render evaluates (`[key]`), for example the key of a loop:

```php
#[State] public array $filters = ['status' => 'open', 'min' => 0, 'tags' => []];
#[State] public array $rows = [['name' => 'Ada'], ['name' => 'Eve']];
```

```php
<input phem:bind={{ filters.status }} />
<input type="number" phem:bind={{ filters['min'] }} />
{% for i, row in rows %}
    <input phem:bind={{ rows[i].name }} />
{% endfor %}
```

A value in an array has no declared type, so the server converts the text of the field to the
type of the current value: `"5"` to `5` when `filters.min` is an int, and a list of ints stays a
list of ints. A `null` value takes the text as it is, so give each key a value of the correct type.
The server sets only a key that the array has: the browser cannot add a key. The value of the
property must have the key of each bound field, or the render fails. Write the path with no
spaces, and with no filter or function call.

A child component can bind a property of its parent. Put `phem:bind` on the tag of the child,
and declare a prop of type `Phem\Bound` in the child. The field of the child binds this prop:

```php
use Phem\Bound;
use Phem\Node;

#[Component]
function TextField(Bound $value, string $label): Node
{
    return <label>{{ label }} <input phem:bind={{ value }} phem:live /></label>;
}
```

```php
#[State] public string $query = '';
#[State] public array $filters = ['status' => 'open'];
```

```php
<TextField phem:bind={{ query }} label="Search" />
<TextField phem:bind={{ filters.status }} label="Status" />
```

The `Bound` prop holds the property of the parent and its value. The field of the child shows the
value, and the next event sets the property of the parent, with the same rules. The child keeps no
copy, so it can be a function component or a template component, with no state. A child passes its
`Bound` prop on to another child with `phem:bind={{ value }}`. The child must have one `Bound` prop
(E033). The type of the parent property is not checked against the field of the child: the server
ignores a value that does not fit.

The values of the fields are not signed: the browser can send any value. The server accepts a
value only for a property (or a path) that the page binds, and only when it fits the type. Keep
the same rules as for all the state: no security decision in a bound property.

phemc writes `value` (or `checked`, or the `selected` of the options), so these attributes are an
error on a bound field. Each `<option>` of a bound `<select>` needs a `value`, or a static text.
A bound `<textarea>` has no text. A morph keeps a field that the user changed after the request
left.

#### Unsaved changes

A bound field that the user changed, and that the server did not get yet, has the `phem-dirty`
class. Its component (`<phem-c>`), its form, its `data-phem-guard` element, and the root element
get the class too, while they have a dirty field. The class goes when an event sends the value
and the server takes it. Use it in CSS:

```css
input.phem-dirty { border-color: orange; }
.unsaved-hint { display: none; }
form.phem-dirty .unsaved-hint { display: inline; }
```

Add `data-phem-guard` to an element to ask the user before they leave the page while a bound
field in it is dirty. On the `<Root>` element, it is for all the fields of the page:

```php
<form onSubmit={{ save }} data-phem-guard>
    <input phem:bind={{ title }} />
    <span className="unsaved-hint">Unsaved changes</span>
    <button type="submit">Save</button>
</form>
```

A reload, a tab close, or a link that Turbo does not follow shows the question of the browser
(`beforeunload`). A Turbo visit shows a `confirm()` with the value of the attribute
(`data-phem-guard={{ 'Discard the draft?' }}`), or with `data-phem-guard-message-value` of the
root element ("You have unsaved changes. Leave the page?" by default). The back and forward buttons
of a Turbo page do not ask. A redirect of a handler does not ask: the server got the values with
the event.

Only bound fields count. A field with no `phem:bind` (for example a field of a Symfony form) sends
its value with the submit, and it is never dirty. A `phem:live` field is dirty only until its event
leaves.


### Rate limits

The `#[RateLimit]` attribute of Symfony 8.1 limits the events of a page, as other controller
attributes do. Install `symfony/rate-limiter`, and configure a limiter:

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        phem_events:
            policy: sliding_window
            limit: 30
            interval: '1 minute'
```

```php
use Symfony\Component\HttpKernel\Attribute\RateLimit;

#[RateLimit('phem_events', methods: 'POST')]
#[Page('/search')]
final class SearchPage
{
    // ...
}
```

An event is a `POST` request, so `methods: 'POST'` counts the events and not the page views.
The default key is the client IP, the method and the path: each page has its own count. The
handler id is in the JSON body, so the default key counts all the handlers of the page together.

To limit one handler, put the attribute on its method. The other handlers have no limit:

```php
#[Page('/search')]
final class SearchPage
{
    #[RateLimit('phem_search')]
    public function search(Event $e): void
    {
        // ...
    }
}
```

The default key of a handler is the client IP, the method, the path, and the class and the method
of the handler: each handler has its own count. The `key` and `tokens` options work as for a
controller. A `Closure` key gets `([], $request, $component)`. An `Expression` key gets `request`,
`args` (empty) and `this` (the component), and it needs `symfony/expression-language`. Handlers
with the same key share one count. A closure in `render()` has no method, so it cannot have a
limit of its own. The attribute works on the handlers of all class components, not only pages.

Over the limit, the server answers `429 Too Many Requests` with a `Retry-After` header, and it
does not run the handler. The page does not change. The browser dispatches a
`phem:rate-limited` event on the root of the component of the handler. Its detail has the
`handler` id and `retryAfter`, in seconds (or `null`). It bubbles, as the other browser events:

```html
<div data-controller="toast" data-action="phem:rate-limited@window->toast#slowDown"></div>
```

Do not use `rate-limited` or `error` as the name of a browser event of a handler.

### Code around the handlers

A handler middleware runs code around each event handler: a security check, a log entry, a
metric. Make a service that implements `Phem\Handler\HandlerMiddleware`. Phem autoconfigures it
with the `phem.handler_middleware` tag:

```php
use App\Page\LoginPage;
use Phem\Event;
use Phem\Handler\HandlerMiddleware;
use Phem\RedirectTo;
use Symfony\Bundle\SecurityBundle\Security;

final class LoginRequired implements HandlerMiddleware
{
    public function __construct(private readonly Security $security)
    {
    }

    public function handle(\Closure $handler, Event $event, \Closure $next): mixed
    {
        if (null === $this->security->getUser()) {
            return new RedirectTo(LoginPage::class);
        }

        return $next();
    }
}
```

- `$next()` runs the next middleware, and then the handler. Return its result: for example the
  `RedirectTo` of the handler.
- To stop the event, return without a call to `$next()`. The handler does not run, and the
  handler gets no arguments. The event renders as usual. A middleware can also return a
  `RedirectTo`, or throw (for example an `AccessDeniedException`: the browser then dispatches
  `phem:error`, see [Failed events](#failed-events)).
- `$handler` is the handler closure. Its bound object is the component of the handler: a method,
  or a closure in `render()`. Use `(new \ReflectionFunction($handler))->getClosureThis()` to
  read the component, for example to find an attribute on its class.
- The middleware runs for all the handlers of all the pages. Check the component or the event
  in the middleware to apply it to some handlers only.

The tag priority sets the order. A higher priority runs first, around the others. The built-in
middleware has these priorities:

| Priority | Middleware |
|---|---|
| 100 | `#[RateLimit]` (see [Rate limits](#rate-limits)) |
| 50 | the Symfony profiler (debug only) |
| 10 | `RedirectTo` to a redirect response |
| 0 | the pushes of `Phem\Stream\Streams` (see [Real-time updates](#real-time-updates)) |
| -100 | `#[Transactional]` (see [Transactions](#transactions)) |
| -200 | `#[Async]` (see [Slow work after the response](#slow-work-after-the-response)) |

The default priority is 0: the middleware runs outside the transaction. To run inside the
transaction, so that an error rolls back the handler, give a priority between -100 and -200:

```php
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: -150)]
final class AuditLog implements HandlerMiddleware
{
    // ...
}
```

An `#[Async]` handler runs the middleware again in the Messenger worker, with the user of the
request. The worker does not run the rate limit, the redirect, and the profiler middleware.

### Event size

An event body has a maximum size, with the state of the page: `phem.max_event_size` bytes
(default 1048576, 1 MiB; 0 for no limit). For an event with files, the limit is for its JSON part
(see [File uploads](#file-uploads) for the limit of the files). Over the limit, the server answers
`413 Content Too Large` and does not run the handler. The JSON of an event body also has a
maximum depth (8). The browser sends less than that, so a deeper body gets `400 Bad Request`.

The browser compresses a JSON body larger than 1 KiB with gzip (`Content-Encoding: gzip`), when
it has the `CompressionStream` API. An event with files is not compressed. The limit applies to
the compressed body and to the JSON after the server unzips it, so a small body that unzips to a
very large one also gets `413`. A body that is not valid gzip gets `400`, and another content
encoding gets `415`.

```yaml
# config/packages/phem.yaml
phem:
    max_event_size: 2097152
```

### State cache

The browser keeps the full signed state of the page. A small state goes with each event. For a
large state, an event sends only its signature: the server keeps each large state that it signs
(the page render and each event) in the `cache.phem.state` pool, by its signature, so the request
of an event stays small on a big page.

A state is large from `phem.state_cache_min_size` bytes (default 4096). A smaller state is about
one KiB or less after gzip, so it costs less to send it than to write it to the pool and read it
again. A page view with a small state writes nothing to the pool, and its events never get a
miss. The `<Root>` element tells the browser the size (`data-phem-state-inline-value`). Set the
option to `0` to keep all the states in the pool.

```yaml
# config/packages/phem.yaml
phem:
    state_cache_min_size: 8192
```

The response of an event is small too: it has only the entries of the state that changed (the
state of a component, the shape of the render), and the new signature. The signed JSON has one
entry on each line, so the browser sets the new entries with no JSON encoder, and its copy is
the text that the server signed.

When the pool does not have the state (an expired entry, a restart, another machine with its own
pool), the server answers `409` with `{"state": "required"}` and does not run the handler. The
browser sends the same event again with the full state, compressed (see
[Event size](#event-size)). So the pool can lose an entry at any time: the only cost is one more
request. An event with files always sends the full state, so the files do not upload two times.

The state from the pool has the same checks as a state from the browser: the signature,
`phem.state_max_age` and `phem.state_bind_session`. A wrong entry in the pool cannot give a state
that the server did not sign.

The pool uses the `cache.app` adapter, with a lifetime of 3600 seconds. A lifetime shorter than
`phem.state_max_age` is correct. Each page view and each event with a large state writes one entry, so use a pool
that removes old entries: Redis or APCu in production. A filesystem pool (the default
`cache.app`) needs `bin/console cache:pool:prune`. With many machines, a shared pool (Redis)
gives more hits.

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.phem.state:
                adapter: cache.adapter.redis
                default_lifetime: 1800
```

`phem.state_cache: false` turns the cache off: then each event sends the full state, with no
miss.

A `#[RateLimit]` on a page counts each request: a miss of the cache (and the second request) uses
two tokens. A `#[RateLimit]` on a handler counts only the request that runs the handler.

### Failed events

When the server answers an event with a status that is not 2xx (for example a 500 for an
exception in the handler, or a 403), or with a body that is not JSON (for example a PHP error
that PHP prints before the handler runs), the page does not change. The browser dispatches a
`phem:error` event on the root of the component of the handler. Its detail has the `handler` id
and the `status`. It bubbles, as the other browser events.

When no listener calls `event.preventDefault()`, the browser shows a default message: a
`.phem-error` element with `role="alert"` at the end of the `<body>`. A click on the message,
or the next event that succeeds, removes it. The style is in `phem.css`. Change the text with
`data-phem-error-message-value` on the `<Root>` element:

```html
<Root data-phem-error-message-value={{ 'The change was not saved.' }}>
```

To show your own message, prevent the default:

```html
<div data-controller="toast" data-action="phem:error@window->toast#failed"></div>
```

```js
failed(event) {
    event.preventDefault()
    this.show(`Error ${event.detail.status}`)
}
```

A `409` (the state is not valid any more) loads the page again, and a `429` dispatches
`phem:rate-limited` (see [Rate limits](#rate-limits)). They do not dispatch `phem:error`.

### Retries

A network error, a `502` or a `503` can be temporary. The browser sends the event again, with
the same state, up to 3 times. It waits 500 ms before the first new try, and the wait doubles
each time (1 s, 2 s). A `Retry-After` header makes the wait longer. While the browser is offline,
the next try waits until the browser is online again. The loading state and the lock of the form
stay until the last answer. The events after it wait in the queue.

When the last try fails, the browser dispatches `phem:error`. After a network error, its
`status` is `null`.

Change the number of tries and the first wait on the `<Root>` element:

```html
<Root data-phem-retries-value={{ 5 }} data-phem-retry-delay-value={{ 1000 }}>
```

A network error or a `502` can come after the handler ran and its transaction committed. Then the
handler runs two times. Make a handler safe to run again (for example, check that the row does not
exist yet), or set `data-phem-retries-value={{ 0 }}` to turn the retries off.

### Transitions

Add `data-phem-transition` to an element to animate the changes of its components with the
[View Transitions API](https://developer.mozilla.org/docs/Web/API/View_Transition_API). When the
component of the handler is in that element (or is that element), the browser runs the morph in
`document.startViewTransition()`. The default animation is a cross-fade of the page. On the
`<Root>` element, all events of the page have a transition.

The value of the attribute is the type of the transition. Use it in CSS with
`:active-view-transition-type()`:

```html
<ul data-phem-transition={{ 'slide' }}>
```

```css
html:active-view-transition-type(slide)::view-transition-old(root) { animation: slide-out .2s ease-in; }
html:active-view-transition-type(slide)::view-transition-new(root) { animation: slide-in .2s ease-out; }
```

Give an element its own `view-transition-name` in CSS to animate it alone (for example, a row
that moves). A browser without the View Transitions API, or with `prefers-reduced-motion: reduce`,
changes the page with no transition.

### Client code with Stimulus

Some changes need no server: open a menu, toggle a class, give the focus to a field. Write them as
[Stimulus](https://stimulus.hotwired.dev/) controllers, and use them in markup with
`data-controller` and `data-action`, as in any Symfony page.

The morph of an event sets the attributes of the server render, so it undoes the changes of a
controller. `data-phem-client` gives a list of attributes to the client: the morph does not change
them, also when the server render changes them. The other attributes of the element still change.

```html
<span data-controller="dropdown">
    <button data-action="dropdown#toggle" data-phem-client="aria-expanded" aria-expanded="false">⋯</button>
    <span data-dropdown-target="menu" data-phem-client="hidden" hidden>
        <button onClick={{ star }}>Star</button>
    </span>
</span>
```

A click on "Star" sends an event, and the menu stays open after the morph. (The `phem-toggle`
controller does this job for a dropdown: see "Show and hide" below.) The server render gives
the first value of the attribute, and the list. The morph matches the items of a list by their
`key`, so the attributes stay on the correct item when an item before it goes away. Two lists with
the same keys in one component match by position: give each list an `id`.

`data-phem-ignore` keeps an element as it is: its attributes and its content. Use it for the
element of a JS library (a chart, a map, a rich text editor). The morph removes the element when
the server render does not have it.

```html
<div data-controller="chart" data-phem-ignore></div>
```

The element that sent an event gets four events, with the `handler` id in their detail:

- `phem:queue` in the dispatch of the DOM event, after the Stimulus actions of the element. Its
  `id` is the id of the DOM event.
- `phem:send` when the request starts.
- `phem:receive` when the server took the event, before the morph.
- `phem:done` when the event ends. After the morph, `ok` is `true`. When the server did not take
  the event (an error, a network error, or a `429`), or the page could not render its answer, `ok`
  is `false`, `status` is the status (as in `phem:error`), and the page does not change. A live
  field with nothing to send ends with `ok` `true` and no `status`.

`phem:send`, `phem:receive` and `phem:done` give in `ids` the ids of the DOM events that the event
carries. An event with a debounce or a throttle, and an input event that waits in the queue, take
the next DOM events of the element too: one request carries all of them.

When the morph removed the element, the event starts on the root of its component. The events
bubble. A redirect, and a reload for a state that is not valid, send no `phem:done`. A handler
cannot send browser events with these names (or `error`, `rate-limited` and `upload-progress`).

### Optimistic UI

A Stimulus action on the element runs before the event goes, so it can change the page at once
(optimistic UI). The `phem-optimistic` controller does this. The change stays until the server
answers the event, also after the morph of another event. Then the page shows the server render:
the morph confirms the change, and a failed event reverts it. The changes of the events that wait
in the queue stay: two fast clicks on a "Like" button with `toggleClass` show the class of the
second click while the first event runs. A DOM event that sends no event (a second submit of a
form that is locked) keeps its change until the last event ends.

This row hides at once on a click on "Delete". If the server deleted it, the morph removed it. If
the server still renders it (the handler did not delete it, or the event failed), it shows again.

```html
<li key={{ id }} data-controller="phem-optimistic">
    {{ subject }}
    <button onClick={{ delete }} data-action="phem-optimistic#hide">Delete</button>
</li>
```

Put the action on the element with the event handler: the controller follows the events of the
elements that ran an action. The actions and their params:

| Action | Change | Params |
|---|---|---|
| `hide`, `show` | the `hidden` attribute | |
| `addClass`, `removeClass`, `toggleClass` | a class | `class` (else the `class` value of the controller) |
| `setAttribute` | an attribute | `attribute`, `value` (`""` by default) |
| `removeAttribute`, `toggleAttribute` | an attribute | `attribute` |
| `setText` | the text | `text` |
| `increment`, `decrement` | the first integer in the text | `by` (`1` by default) |
| `insert` | a clone of a `<template>` | |

A param is an attribute of the element of the action, for example
`data-phem-optimistic-class-param="liked"`. The value is a string, as it is in the attribute.

An action changes the controller element. With a `target` param, it changes all the targets with
that name (`data-phem-optimistic-target="count"`) in place of the controller element. A param
applies to all the actions of the element. A param with the name of the action in it applies to
that action only (`data-phem-optimistic-increment-target-param`). Here the click adds the class to
the button and adds 1 to the count:

```html
<button onClick={{ like }} class={{ liked ? 'liked' : '' }} data-controller="phem-optimistic"
        data-action="phem-optimistic#toggleClass phem-optimistic#increment"
        data-phem-optimistic-class-param="liked" data-phem-optimistic-increment-target-param="count">
    ♥ <span data-phem-optimistic-target="count">{{ likes }} likes</span>
</button>
```

A morph of another event shows the new server render, and the changes apply to it again: when
the server render gives `20 likes`, the count shows `21 likes`. `setText` and the number actions
replace the content of the element: do not use them on an element that contains the element of the
action.

`insert` adds a clone of the content of a `<template>` target before the template. An element of
the clone with `data-phem-optimistic-field="<name>"` gets the value of that field of the form of
the action, as text (the values of a field with more than one value are joined with `, `). The
morph of the event shows the item of the server render in its place, and a failed event removes
the clone:

```html
<div data-controller="phem-optimistic">
    <ul>
        {% for message in messages %}<li key={{ message.id }}>{{ message.body }}</li>{% endfor %}
        <template data-phem-optimistic-target="message">
            <li class="pending"><span data-phem-optimistic-field="body"></span></li>
        </template>
    </ul>
    <form onSubmit={{ send }} data-action="phem-optimistic#insert" data-phem-optimistic-target-param="message">
        <input name="body">
    </form>
</div>
```

The controller is not enabled by default. Enable it in `assets/controllers.json`:

```json
"@phem/phem": {
    "optimistic": { "enabled": true, "fetch": "lazy" }
}
```

### Show and hide

The `phem-toggle` controller shows and hides a dropdown, a menu or a section on the client, with
no event. It stays open after the morph of an event:

```html
<span data-controller="phem-toggle">
    <button data-action="phem-toggle#toggle" data-phem-toggle-target="button" aria-controls="actions">⋯</button>
    <span id="actions" data-phem-toggle-target="content" hidden>
        <button onClick={{ star }}>Star</button>
        <button onClick={{ archive }} data-action="phem-toggle#close">Archive</button>
    </span>
</span>
```

The actions are `toggle`, `open` and `close`. The `content` targets get the `hidden` attribute,
and the `button` targets get `aria-expanded`. The `open` value
(`data-phem-toggle-open-value="true"`) of the server render opens it at the start. The morph does
not change these three attributes: the state belongs to the client. The other attributes of the
elements still change. When the server render gives a new `open` value, the state takes it. To
close the menu after an item, add `phem-toggle#close` to the item. In a list, give each item a
`key`, so the state stays on the correct item when an item before it goes away.

Escape and a click outside the controller element close it. An Escape that another element
handled (`preventDefault()`) does not close it. Escape gives the focus back to the first button
outside the content when the focus was in the content. For a section that must stay open, set
`data-phem-toggle-dismiss-value="false"`.

The controller is not enabled by default. Enable it in `assets/controllers.json`:

```json
"@phem/phem": {
    "toggle": { "enabled": true, "fetch": "lazy" }
}
```

### Dialog

The `phem-dialog` controller opens a native `<dialog>` as a modal (`showModal()`), with no event.
It stays open after the morph of an event:

```html
<div data-controller="phem-dialog">
    <button data-action="phem-dialog#open">Edit</button>
    <dialog data-phem-dialog-target="dialog" aria-labelledby="edit-title">
        <h2 id="edit-title">Edit the title</h2>
        <input phem:bind={{ title }} />
        <button onClick={{ save }} data-action="phem-dialog#close">Save</button>
        <button data-action="phem-dialog#close">Cancel</button>
    </dialog>
</div>
```

The actions are `open` and `close`. The `open` value (`data-phem-dialog-open-value="true"`) of the
server render opens it at the start. The morph does not change the `open` attribute of the dialog
and the `open` value: the state belongs to the client. When the server render gives a new `open`
value, the state takes it. For example, `data-phem-dialog-open-value={{ editing }}` with an
`editing` state that the handlers change. The browser moves the focus into the dialog when it
opens, and back to the button when it closes.

When the morph moves the dialog, the browser takes it out of the top layer. The controller shows
it as a modal again.

Escape and a click on the backdrop close it. A click on the padding of the dialog does not close
it. A dialog that closes in another way (a `<form method="dialog">`) also sets the state. For a
dialog that must stay open, set `data-phem-dialog-dismiss-value="false"`.

The controller is not enabled by default. Enable it in `assets/controllers.json`:

```json
"@phem/phem": {
    "dialog": { "enabled": true, "fetch": "lazy" }
}
```

### Tabs

The `phem-tabs` controller shows the panel of the selected tab on the client, with no event. The
selected tab stays after the morph of an event:

```html
<div data-controller="phem-tabs">
    <div data-phem-tabs-target="list" aria-label="Settings">
        <button id="tab-profile" data-phem-tabs-target="tab">Profile</button>
        <button id="tab-security" data-phem-tabs-target="tab">Security</button>
    </div>
    <div id="profile" data-phem-tabs-target="panel">
        <input phem:bind={{ name }} />
    </div>
    <div id="security" data-phem-tabs-target="panel" hidden>
        <button onClick={{ resetPassword }}>Reset the password</button>
    </div>
</div>
```

The tab at a position shows the panel at the same position. A click on a tab selects it. On a tab,
the arrow keys select the next or the previous tab and move the focus to it, and Home and End select
the first or the last tab. The arrow keys skip a disabled tab (`disabled` or
`aria-disabled="true"`). For a vertical list, set `aria-orientation="vertical"` on the `list`
target: the up and down arrow keys then replace the left and right arrow keys.

The controller sets the ARIA attributes:

- the `list` target gets `role="tablist"`;
- a tab gets `role="tab"`, `aria-selected`, `tabindex` (`0` for the selected tab, `-1` for the
  other tabs) and `aria-controls`;
- a panel gets `role="tabpanel"`, `hidden`, `tabindex="0"` and `aria-labelledby`.

`aria-controls` and `aria-labelledby` need an `id` on the panel and on the tab. When the server
render gives `aria-controls`, `aria-labelledby` or the `tabindex` of a panel, the controller keeps
it. The morph removes the attributes that the server render does not have: the controller sets
them again after the morph. The other attributes of the elements still change.

The `index` value (`data-phem-tabs-index-value="1"`) of the server render selects a tab at the
start. The first tab has the index `0`. The morph does not change the `index` value: the state
belongs to the client. When the server render gives a new `index` value, the state takes it. When
the server render has less tabs, the last tab is selected.

The controller is not enabled by default. Enable it in `assets/controllers.json`:

```json
"@phem/phem": {
    "tabs": { "enabled": true, "fetch": "lazy" }
}
```

### Toasts

The `phem-toast` controller shows the browser events of Phem as toasts: short messages that go
away after some seconds. Put one element in the layout of the page, or in the page component:

```html
<div data-controller="phem-toast"></div>
```

It listens on `window` for these events:

- `phem:toast`, from the dispatcher of a handler event (`$e->dispatcher`). The detail is the
  message, or an object with `message`, and an optional `type` and `duration`:

  ```php
  $e->dispatcher->dispatch('toast', ['message' => 'Saved', 'type' => 'success']);
  ```

- `phem:error` (see [Failed events](#failed-events)). It shows the error message in place of the
  default message, and prevents the default. The error message is the `errorMessage` value, or else
  the `data-phem-error-message-value` of the `<Root>` element. When a listener before it prevents
  the default (to show its own message), the controller shows nothing.
- `phem:rate-limited` (see [Rate limits](#rate-limits)). It shows the `rateLimitedMessage` value.

The `show` action shows the detail of another event, with the same format as `phem:toast`:
`data-action="cart:added@window->phem-toast#show"`. With two `phem-toast` elements, only one of
them shows each event.

A toast with the same type and message as a toast on the screen does not show again: the toast on
the screen starts its time again. So 20 fast clicks over a rate limit show one toast. A `type` that
is not one of the four types gives `info`, and a `duration` that is not a number gives the
`duration` value.

The message is text, not HTML. A toast gets the `phem-toast` class and a class for its type:
`phem-toast-info` (the default), `phem-toast-success`, `phem-toast-warning` or `phem-toast-error`.
The style is in `phem.css`. It has a low specificity (`:where()`), so the CSS of the app can change
it.

A toast goes away after the `duration` value, in milliseconds (`5000` by default), or with its
close button. A duration of `0` keeps the toast until the close button. The time stops while the
pointer or the focus is on the toast. The element is a live region (`aria-live="polite"`), so a
screen reader reads each toast. The morph does not remove the toasts and the `aria-live`
attribute, and the server render can still add elements to the element. A toast stays when the
morph moves the element, and in a page from the Turbo cache.

Change the texts with the values of the controller:

```html
<div data-controller="phem-toast"
     data-phem-toast-duration-value={{ 8000 }}
     data-phem-toast-error-message-value={{ 'The change was not saved.' }}
     data-phem-toast-rate-limited-message-value={{ 'Slow down, please.' }}
     data-phem-toast-close-label-value={{ 'Close' }}></div>
```

The controller is not enabled by default. Enable it in `assets/controllers.json`:

```json
"@phem/phem": {
    "toast": { "enabled": true, "fetch": "lazy" }
}
```

## 7. State: class components

A component with state is a class with the `#[Component]` attribute and a `render()` method.
A page class (`#[Page]`) is a component too, with no `#[Component]`.

```php
use Phem\Attribute\{Component, Prop, State};
use Phem\Node;

#[Component]
final class Counter
{
    #[Prop] public string $label = 'Clicked';
    #[State] public int $count = 0;

    public function increment(): void
    {
        $this->count = min($this->count + 1, 10);
    }

    public function render(): Node
    {
        return <button onClick={{ increment }}>{{ label }} {{ count }} times</button>;
    }
}
```

- A `#[Prop]` property is a prop. It must be public, not static, not promoted, and not
  `#[State]` (compiler error E042). A prop without a default value (and not nullable) is a
  required prop. A public property without `#[Prop]` is not a prop: the markup cannot set it.
- A `#[Prop(rest: true)]` property is the rest prop: it gets the props that no other prop
  takes (see [Rest props](#rest-props)).
- A `#[State]` property is state. A method changes it, and Phem calls `render()` again.
- In the markup of a class, a name is a local variable, then a property (`$this->name`),
  then a method (`$this->name(...)`, so a method passes as a handler).
- The props, the properties and the methods of a parent class (`extends`) and of the traits
  are also available. When the parent class or a trait is not in a `.phpm` file, phemc does not
  see it: it does not check the props and the slots of the component, and it does not report
  the names that it cannot find.
- Each component instance has its own state.
- The state lives in the browser. The `phem` Stimulus controller keeps it, and the server signs it.
  An event sends the signature of the state, and the server finds the state in its cache (or the
  browser sends it again, see [State cache](#state-cache)). The server makes the component
  again, restores the state, runs the handler, renders the components that can change (see
  [Keep `render()` pure](#keep-render-pure)), and sends back Turbo Streams that morph only the
  components that changed.
- State values must be JSON values (scalars and arrays), or backed enums.
- The state is UI state, not trusted data. The signature stops a forged state, but the browser
  can send back any state that the server signed for the page before: an older state (so a
  user can undo a change), or the state of another visitor. Keep limits (tries, votes) and
  security decisions in the database or the session, not in `#[State]`. Two options make the
  window smaller: a signed state is valid for `phem.state_max_age` seconds (default 86400; 0
  for no limit), and `phem.state_bind_session: true` makes it valid only in its session (a
  login then reloads the open pages on their next event). An invalid state reloads the page.

### State in the URL

A `#[Url]` property is in the query string of the page URL. Filters, search terms, tabs and
page numbers then survive a reload, a shared link, and the back button.

```php
use Phem\Attribute\{Component, State, Url};

#[Component]
final class Catalog
{
    #[State, Url(as: 'q')] public string $query = '';      // ?q=php
    #[State, Url(history: true)] public int $page = 1;      // ?page=2, a new history entry
    #[State, Url] public array $tags = [];                  // ?tags[]=a&tags[]=b
    #[State, Url] public array $filters = ['status' => 'all', 'price' => ['max' => 100]];
                                                            // ?filters[status]=open&filters[price][max]=50
}
```

- The property must be a `#[State]` property. Its type is a string, an int, a float, a bool
  (`1` or `0` in the URL), a backed enum, or an array of them, nullable or not.
- An array with keys is nested in the URL: `?filters[status]=open`. Its keys can have lists and
  other arrays with keys. The URL has only the keys with a value other than the default value.
  The first render sets only the keys that the array has after `mount()`, and the type of the
  current value is the type of the key, as for a `phem:bind` path. Do not add keys in a
  handler: a reload does not read them. An object is not possible: `#[State]` keeps arrays.
- `as` gives another query key (letters, digits, `_` and `-`). Two components of a page cannot
  have the same key: give one of them another key.
- The first render of the page reads the query string, after `mount()`. A value that does not
  fit the type is ignored, and the property keeps its value. An event does not read the URL:
  the signed state has the values.
- After each event, the browser writes the values into the URL, and keeps the other query keys.
  A value that is equal to the default value of the property removes its key.
- `except` gives another value that removes the key, for example for a property with no default
  value: `#[State, Url(except: 'name')] public string $sort;`. Then the default value has a key.
  Give the property the `except` value when the URL has no key (in `mount()`), or a reload
  shows another value.
- By default, the browser replaces the current history entry. With `history: true`, a change of
  the value adds an entry. Back and forward load the page of that URL again, with Turbo.
- The query string comes from the user: as for `phem:bind`, do not trust its values.

Phem has no Hooks. A call to `Phem\useState` (or `useReducer`, `useRef`, `useMemo`,
`useCallback`, `useId`, `useContext`) is the compiler error E020. For `useContext`, see
[Context](#context).

## 8. Services and `mount()`

A class component is a Symfony service. The container makes a new object for each component
instance, and the constructor gets its dependencies (autowiring). Constructor parameters are
never props.

```php
use App\Repository\TodoRepository;
use Phem\Attribute\{Component, Prop, State};
use Phem\Node;

#[Component]
final class TodoList
{
    #[Prop] public int $limit = 10;
    #[State] public array $todos = [];

    public function __construct(private readonly TodoRepository $repository)
    {
    }

    // Runs when the component has no state yet: on its first render. The props are set.
    public function mount(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

    public function refresh(): void
    {
        $this->todos = $this->repository->latest($this->limit);
    }

    public function render(): Node
    {
        return (
            <section>
                <ul>{% for todo in todos %}<li key={{ todo.id }}>{{ todo.title }}</li>{% endfor %}</ul>
                <button onClick={{ refresh }}>Refresh</button>
            </section>
        );
    }
}
```

The order for each component in a request:

1. The container makes the object (constructor injection).
2. Phem writes the props into the `#[Prop]` properties, and the contexts into the `#[Context]`
   properties (see [Context](#context)).
3. If the browser sent state for this component, Phem writes it into the `#[State]`
   properties. If not, Phem calls `mount()` (when the class has it).
4. Phem calls the event handler (for an event), then `render()`.

A component without `#[State]` properties has no state in the browser, so `mount()` runs each
time that the component renders. To configure a component service (for example to bind a scalar
argument), define it in `config/services.yaml` with its class name as the id. Phem keeps your
definition and only makes it not shared.

### Keep `render()` pure

As in React, `render()` is a pure function of the component. Its output depends only on:

- the props,
- the `#[State]` properties,
- the `#[Context]` properties (see [Context](#context)),
- the plain properties that `mount()` or a handler of the component set in the same request
  (for example a Symfony form with its errors).

`render()` does not call services, and it does not read the time, the session, the superglobals
or static properties. Read the data in `mount()` or in a handler:

```php
// Not correct: the output changes when the database changes.
public function render(): Node
{
    $unread = $this->messages->countUnread();
    return <span>{{ unread }}</span>;
}

// Correct: mount() reads the service, and render() reads the state.
#[State] public int $unread = 0;

public function mount(): void
{
    $this->unread = $this->messages->countUnread();
}

public function render(): Node
{
    return <span>{{ unread }}</span>;
}
```

When two components show the same data, lift the data up to their common parent, and pass it
down as props (see [section 9](#9-props-and-lifting-state-up)).

Phem uses this rule to render only the components that can change, as React does. An event:

1. renders only the components on the path from the page to the handler (they give the
   handler), and the components of the bound values with their children;
2. runs the handler;
3. renders the *dirty* components with their children: the component of the handler, and the
   components whose `#[State]` changed. In these subtrees, a child that gets the same props as
   before does not render again. The browser keeps its HTML.

The props compare as React compares them (`Object.is`): the same values, and the same objects.
A handler that changes an object in place (an entity) does not change the props of the other
components that show it: they do not render again. A component with no `#[State]` and a
`mount()` always renders with its parent: `mount()` can read services.

When a handler changes data that other components read, call `$e->refresh()`: the full page
renders again, as `router.refresh()` of Next.js does. For example, a form that adds a row to a
list of the page:

```php
public function add(Event $e): void
{
    $this->scientists->add($scientist);
    $e->refresh();
}
```

Two checks find a `render()` that is not pure:

- `phemc` reports W008 for a `render()` (or a function component) that reads a service of the
  constructor, a static property, a superglobal, the clock (`time()`, `date()`,
  `new DateTime()`), or a random value (`rand()`, `random_int()`, `uniqid()`).
- With `kernel.debug`, after each event, Phem renders the full page again and compares the
  components that it did not render. A component whose HTML changed is a warning in the log, in
  the console of the browser, and in the Phem panel of the Symfony profiler (see
  [compiling.md](compiling.md#the-symfony-profiler)). This check doubles the work of an event: Phem does it only in
  debug mode. A class component with a plain property that is not readonly (a form that a
  handler sets) is not checked.

### Transactions

Add `#[Transactional]` to an event handler method to run it in a Doctrine ORM transaction. Add it
to a class component to run all of its event handlers in a transaction: its methods and the
closures of `render()`. This needs DoctrineBundle. The other handlers run without a transaction. When the handler returns, Phem flushes and commits. When the handler throws an
exception, Phem rolls back all of its changes. Thus the handler does not call `flush()`:

- To add an entity, call `persist()` (for example through a repository method).
- To delete an entity, call `remove()`.
- To change an entity that Doctrine loaded, set its properties. The flush finds the changes.

```php
use Phem\Attribute\{Component, Prop, Transactional};

#[Component, Transactional]
final class TodoItem
{
    #[Prop] public Todo $todo;             // a managed entity

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function toggle(): void
    {
        $this->todo->done = !$this->todo->done;   // saved at the commit
    }

    public function delete(): void
    {
        $this->em->remove($this->todo);           // deleted at the commit
    }

    // render() ...
}
```

The transaction contains only the handler. `render()` and `mount()` run outside of it.

A handler that a parent passes as a prop belongs to the parent: the parent's attribute applies.

When only some handlers write to the database, put the attribute on these methods. The other
handlers (a toggle of the UI, a search) run without a transaction:

```php
#[Component]
final class AddScientist
{
    #[State] public bool $help = false;

    // __construct(), render() ...

    #[Transactional]
    public function add(Event $e): void
    {
        $this->scientists->add(new Scientist($e->form['name'], $e->form['profession']));
    }

    public function toggleHelp(): void   // no transaction
    {
        $this->help = !$this->help;
    }
}
```

### Content that loads after the page

A slow component (a report with a heavy query, a call to an external API) makes the full page
slow: the server renders the full page before it sends it. Put the slow component in a
`<Suspense>` boundary, as in React. The page renders the `fallback` slot in place of the
children. Then the browser loads the children with an event:

```php
use Phem\Node;
use Phem\Suspense;

#[Page('/dashboard')]
final class Dashboard
{
    public function render(): Node
    {
        return (
            <Document>
                <Suspense>
                    <Suspense:fallback><p>Loading the sales report…</p></Suspense:fallback>
                    <SalesReport />
                </Suspense>
                <Suspense lazy>
                    <Suspense:fallback><p>Loading the chart…</p></Suspense:fallback>
                    <Suspense:error><p>The chart is not available now.</p></Suspense:error>
                    <ActivityChart />
                </Suspense>
            </Document>
        );
    }
}
```

- The children do not render before the load. So the `mount()` of `SalesReport` does not run
  in the page request: the slow work runs in the load event.
- Without `lazy`, the browser loads the children when the page starts. With `lazy`, it loads
  them when the fallback comes into view (200px before).
- The load is a normal event: it sends the state of the page, and has the same access rules,
  rate limits and profiler panel. It renders only the boundary, and the morph puts the children
  in place of the fallback.
- The boundaries that start to load together (when the page starts, after a morph, or when they
  come into view at the same time) send one load event, in the event queue: one request, and
  one render for all of them. A slow load makes the events after it wait.
- The state keeps the status of the boundary. After the load, the next events render the
  children directly, and they keep their state.
- The fallback is in a `<phem-suspense>` element, with `display: block`. Style it with CSS.

When the render of the children throws, the `error` slot shows in place of the children, only
for this boundary. The response is a normal answer (200). The boundary drops what the children
rendered before the exception, and the logger gets the exception (the profiler shows it). With
no `error` slot, the exception goes on: the event fails (see [Failed events](#failed-events)),
and the fallback stays. When this event loads more than one boundary, the browser then sends one
load event for each of them, so the other boundaries show their children. A boundary loads one
time: the browser does not send its load event again after a failed load. A reload of the page
tries again.

The status of a boundary does not go back. An error of a later event (for example after a click
in the children) also shows the `error` slot.

### Errors in a part of the page

An exception in the render of one component makes the full event fail. When a part of the page
can fail (a call to an external service, a widget with bad data), put it in an `<ErrorBoundary>`,
as in React. When the render of the children throws, the `fallback` slot shows in place of the
children, only for this boundary:

```php
use Phem\ErrorBoundary;
use Phem\Node;

#[Page('/dashboard')]
final class Dashboard
{
    public function render(): Node
    {
        return (
            <Document>
                <ErrorBoundary>
                    <ErrorBoundary:fallback><p>The stock quote is not available now.</p></ErrorBoundary:fallback>
                    <StockQuote />
                </ErrorBoundary>
            </Document>
        );
    }
}
```

- The `fallback` slot is required.
- The response is a normal answer (200). The boundary drops what the children rendered before
  the exception, and the logger gets the exception (the profiler shows it).
- The boundary has no state. Each render tries the children again: in the page request, and in
  each event. When the children do not throw any more, they show again.
- When the fallback shows, the children are not in the page, so their state goes away. When
  they show again, they mount again, with new state.
- The boundary catches the exceptions of the render, and of `mount()`. It does not catch the
  exceptions of an event handler, as in React. An exception in a handler fails the event (see
  [Failed events](#failed-events)): the transaction rolls back, and the page keeps the state
  that it had before the event.

`<Suspense>` with an `error` slot does the same for the content that loads after the page (see
[Content that loads after the page](#content-that-loads-after-the-page)). But its status stays
failed, and the `<ErrorBoundary>` tries again on each render.

### Real-time updates

A change by one user does not show for another user until that user sends an event. A page with
`#[Broadcast]` gets the Turbo Streams of other requests: a handler (or any service) pushes them
with `Phem\Stream\Streams`, and the Mercure hub of FrankenPHP sends them to the open pages.

```php
use Phem\Attribute\{Component, Page, State, Broadcast, Transactional};
use Phem\Stream\Streams;

#[Page('/guestbook'), Broadcast]
final class Guestbook
{
    // ... renders <SignForm />, <GuestbookStats /> (id="guestbook-stats") and <ul id="entries">
}

#[Component]
final class SignForm
{
    #[State] public string $message = '';

    public function __construct(
        private readonly GuestbookRepository $repository,
        private readonly Streams $streams,
    ) {
    }

    #[Transactional]
    public function sign(): void
    {
        $entry = new Entry($this->message);
        $this->repository->add($entry);
        $this->message = '';

        $this->streams->toPage(Guestbook::class)
            ->prepend('entries', <GuestbookEntry entry={{ entry }} />)
            ->refresh('guestbook-stats');
    }
    // ...
}
```

- `toPage(Page::class)` names all the URLs of a page. `toPage(Page::class, ['id' => 42])` names
  one URL: give all the route values of the page, as the URL has them. `everywhere()` names all
  the pages with `#[Broadcast]`, for a part of the layout. A page with no `#[Broadcast]` throws.
- A function page can have `#[Broadcast]` too. A function has no `::class`, so give its
  first-class callable: `toPage(Guestbook(...), ['room' => 'lobby'])` (with `use function`). Its
  route values are the parameters that its routes give a value, not the services or the Request.
- A component can have `#[Broadcast]` too (`#[Component, Broadcast]`), for example a part of the
  layout. Each page that renders it connects to the hub, also a page with no `#[Broadcast]`.
  `toComponent(Inbox::class)` (or `toComponent(Inbox(...))` for a function component) names all
  the pages that render the component. It takes no route values. `toPage()` of a component and
  `toComponent()` of a page throw. The page connects for the components of its first render: a
  component that renders first in an event gets the pushes after the page loads again. With
  private updates, an event extends the access to the topic of a component only when the
  component renders in the event: a component that stops to render (for example after the
  viewer loses a role) loses its pushes when its access expires.
- Each action takes the `id` of the target element: `append`, `prepend`, `before`, `after`,
  `replace`, `update` and `remove`, as in Turbo. `replace` and `update` morph by default
  (`morph: false` turns it off), so the focus, a bound field that the user changes, and the
  client attributes (`data-phem-client`) stay.
- The markup is **plain markup**: it renders at once, outside the page, with the data of now. A
  component in it renders with no `<phem-c>`. An event handler, a `phem:bind`, a `phem:loading`
  or a `<Suspense>` in it throws: no page has its fiber.
- **`refresh($target)`** renders the component around the target again in each browser, with a
  normal event, with its subtree. With no target, `refresh()` renders again the instances of the
  component of `toComponent()` (see [The refresh of a component after its
  handlers](#the-refresh-of-a-component-after-its-handlers)), or the content of the page of
  `toPage()` and `everywhere()`. Use it for interactive content, and for data that depends on
  the user: each browser sends its own request, with its access rules and its state. As after
  `$e->refresh()`, a component with no `#[State]` reads its data again in `mount()`, and a
  `#[State]` keeps the value of the browser. So put the data that a push changes in a component
  with no `#[State]`, and give it as a prop to a child with the state of the UI (an open panel).
  The browser sends one event for all the refreshes that come together, and none for a target
  that the page does not show.
- In the root element, the browser marks the component around each target as **stale**: its
  element is not the last render of the server any more. The next event sends the stale
  components, and the server renders them again with their full subtree, so the state of the
  page knows the change. When a stale component is not in the page any more, its parent renders.
  So the content that you push into a target must also be in the server render (read it from
  the database in `mount()`). Else the next event of that browser renders the component again
  without it, and the morph removes it. For a target that only the pushes fill (a chat with no
  history), put `data-phem-ignore` on it: the morph keeps its content.
- Before an `append` or a `prepend`, the browser removes an element of the target with the id of
  a new element. So give each pushed item an `id`: the user who made the change gets the push
  too, and sees the item one time.
- While an event runs, the pushes wait, and they come after its morph.
- The streams go to the hub at the end of the request, after the transaction of the event: a
  refresh reads the new data. When a handler throws (also when the commit fails), its streams are
  dropped. In a long process (a command), call `$streams->flush()`.
- The same markup goes to all the viewers of the page: do not push data for one user only. Push
  a `refresh()` instead.
- A stream outside the root element (`#phem-root`) is plain Turbo.

#### The refresh of a component after its handlers

A class component with `#[Broadcast]` needs no stream for its own changes. After each of its
event handlers that does not throw, the other browsers that show the component render it again,
as with `toComponent()->refresh()`:

```php
use Phem\Attribute\{Component, Prop, State, Broadcast, BroadcastKey, Transactional};

#[Component]
final class LikeButton
{
    #[Prop, BroadcastKey] public Post $post;
    #[Prop] public string $size = 'md';
    #[State] public bool $open = false;

    #[Transactional, Broadcast]
    public function like(): void
    {
        ++$this->post->likes;
    }

    // A state of this browser only: no refresh.
    public function toggle(): void
    {
        $this->open = !$this->open;
    }
    // ...
}
```

- With `#[Broadcast]` on the class and on no handler method, all the handlers of the component
  refresh it. With `#[Broadcast]` on some handler methods, only these handlers do, and the
  class needs no `#[Broadcast]`: the component connects its pages to the hub. A component with
  no handler that only gets the pushes of other requests needs `#[Broadcast]` on the class.
- The refresh goes to all the instances of the component, on all the pages. The props with
  `#[BroadcastKey]` identify an instance: the refresh goes only to the instances with the same
  values. An entity is its identifier, an enum is its value. Mark all the props that identify
  the data, and no prop that only changes the look: a page that renders the same post with
  another `size` must get the refresh too. A function component marks its parameters
  (`function LikeCount(#[BroadcastKey] Post $post)`).
- `toComponent(LikeButton::class, ['post' => $post])->refresh()` does the same from any
  handler or service: give the values of all the key props, or none for all the instances.
- The page that sent the event does not render again the component of the handler: the event
  rendered it already. Its other instances on that page render again.
- A closure of `render()` that calls a handler with `#[Broadcast]` needs its own
  `#[Broadcast]`: `$like = #[Broadcast] fn () => $this->like($id);`. Phem sees the closure, not
  the methods that it calls.
- The component of a handler is the object of its closure, as for `#[Transactional]`: a
  handler that a parent gives as a prop refreshes the parent. A function component has no
  object, so its handlers do not refresh it: push `toComponent()->refresh()` yourself.
- The refresh goes after the transaction, as the other streams. An `#[Async]` handler refreshes
  after its work, in the worker.
- Each browser renders the component with a normal event, with its own state and its access
  rules. A component with no `#[State]` reads its data again in `mount()`.
- `phemc` reports E053 for `#[Broadcast]` on a method of a page, and E057 for
  `#[BroadcastKey]` on a property that is not a prop, or in a page or a component that does not
  broadcast.

The page opens one connection to the hub, for its topics: the page, the page with its route
values, and the topic of `everywhere()`. A topic is an HMAC of its name with the secret of Phem
(`phem.secret`): the HTML, the access token and the hub do not show the class names or the route
values. The profiler shows the names. Phem publishes with `mercure_publish()` of FrankenPHP:
no HTTP request, and no publisher key. Add the hub to the site of the Caddyfile:

```caddyfile
mercure {
    transport local
    issuer phem {
        subscriber {
            jwt {$MERCURE_SUBSCRIBER_JWT_KEY}
        }
    }
}
```

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        subscriber_key: '%env(MERCURE_SUBSCRIBER_JWT_KEY)%'
        # hub_url: '/.well-known/mercure'
        # issuer: 'phem'
        # cookie_name: '__Secure-mercure_access_token'
```

With the subscriber key, the updates are private. Each page response sets the cookie of the hub
with an access token for the topics of the page. The topics of the other tabs of the same
session stay in it: after a logout or a login, they go. Each topic expires in the time of a
token after its page passed its access rules, and only a render or an event of that page extends
it: a viewer that lost access to a page stops getting its updates. The token expires with its
first topic. An event renews the token when it lived half of its time. The page passed its access rules, so the viewer can see what the server
renders for its topics. With no key, the updates are public, and the hub needs the `anonymous`
directive. The `local` transport has no history: after a lost connection, the browser refreshes
the content of the root element, because the updates of that time are lost. When the hub refuses
the connection (an expired token), the browser sends a refresh, which renews the token, and
connects again.

- The token is for the URL of the hub that the browser connects to. Behind a proxy that ends
  TLS, configure the trusted proxies of Symfony, or set an absolute `hub_url`.
- The cookie is `Secure`: a browser keeps it on HTTPS and on localhost. On another plain HTTP host
  (`http://app.test`), set `cookie_name` (and the `cookie_name` of the hub) to a name with no
  `__Secure-` prefix. Phem logs a warning.
- When `mercure_publish()` does not exist (no FrankenPHP), or the publish fails, the logger gets
  the error, and the response does not change.

The Symfony profiler shows the streams that a request pushed, and the stale components of an
event. `phemc` reports E053 for `#[Broadcast]` on a class or a function that is not a page or a
component, or on a method of a page. The tests get the pushes with `receive()` (see [Testing](testing.md#actions)).

### Slow work after the response

A slow handler (an export, a payment, a call to an API) blocks the event and its morph. Put
`#[Async]` on the handler method, and return the slow work in a `Phem\Async\Promise`. The event
answers at once, and a Symfony Messenger worker runs the work after the response:

```php
use Phem\Async\Promise;
use Phem\Attribute\{Async, Component, State, Transactional};
use Phem\Event;
use Phem\Stream\Streams;

#[Component]
final class BuildReport
{
    // The ticket of the next report: the request and the worker read the same value.
    #[State] public string $ticket = '';

    public function __construct(
        private readonly ReportRepository $reports,
        private readonly Streams $streams,
    ) {
    }

    public function mount(): void
    {
        $this->ticket = bin2hex(random_bytes(4));
    }

    #[Async, Transactional]
    public function build(Event $e): Promise
    {
        $ticket = $this->ticket;
        $this->ticket = bin2hex(random_bytes(4));
        $this->streams->toPage(Reports::class)
            ->prepend('reports', <li id="report-{{ ticket }}">Building a report…</li>);

        return new Promise(function () use ($ticket): void {
            $report = $this->reports->build($ticket);   // slow
            $this->streams->toPage(Reports::class)
                ->replace('report-'.$ticket, <ReportRow report={{ report }} />);
        });
    }
    // ...
}
```

1. **In the request**, the method runs as a normal handler. The event renders as usual, with the
   state changes of the method body. The pushes of the method body go out at the end of the
   request. Phem does not run the closure: it dispatches a `Phem\Async\AsyncHandlerMessage`, in
   the transaction of the handler. When the dispatch fails, the event fails: the writes of the
   method body roll back, and its pushes are dropped.
2. **In the worker**, Phem builds the page again from the state of the event, renders the path to
   the handler, sets the bound values, and calls the method again. The handler gets its props,
   its context, its state, the form fields of the event, the route values of the request, and the
   values of its closure in `render()` (`fn () => $this->ship($id)`) again. The request of the
   worker has no session, no cookies and no files. Phem drops the state changes and the pushes of
   this second call, and runs the closure of the Promise. Then it publishes the pushes of the
   closure.

Rules:

- The method body before `return` runs **two times**, with the same state: keep it fast, with
  no side effects. Read the values that the work needs in the body, and pass them to the closure
  (`use ($ticket)`).
- The worker has no response. The closure shows its result with `Streams` (the page needs
  `#[Broadcast]`). The same markup goes to all the viewers of the page.
- A retry of Messenger runs the closure again: make it idempotent (the ticket above finds the
  report of a retry).
- With `#[Transactional]` on the method or the class, the method body runs in a transaction in
  the request, and the closure runs in a transaction in the worker. When the closure throws, the
  transaction rolls back, its pushes are dropped, and Messenger retries the message.
- The worker has the user of the request: Phem serializes the security token, as the session
  does, and the worker loads the user again with the user provider. A user that does not exist
  any more drops the message.
- Do not read the session, the cookies or a CSRF token in the method body: the worker does not
  have them. When the method returns no `Promise` in the worker, the worker drops the message.
- The event cannot have files (PHP removes them at the end of the request): move the files in a
  normal handler. Phem checks the files and Messenger before the method runs.
- Use the Doctrine transport on the connection of the entities: the message is then part of the
  commit of a `#[Transactional]` handler. With another transport (Redis, AMQP), the worker can
  start before the commit: the closure must not need the rows of the method body.
- The message has an HMAC with the secret of Phem: the worker rejects a message that it did not
  make. When the page changed since the event (a deploy), the worker logs a warning and drops the
  message.

Install Messenger, route the message to a transport, and run a worker:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            Phem\Async\AsyncHandlerMessage: async
```

```sh
php bin/console messenger:consume async
```

The worker has no FrankenPHP request, so `mercure_publish()` does not work in it. Give Phem the
publisher key of the hub: the worker publishes with the HTTP API of the hub, and a request still
uses `mercure_publish()`.

```caddyfile
mercure {
    issuer phem {
        subscriber {
            jwt {$MERCURE_SUBSCRIBER_JWT_KEY}
        }
        publisher {
            jwt {$MERCURE_PUBLISHER_JWT_KEY}
        }
    }
}
```

```yaml
# config/packages/phem.yaml
phem:
    mercure:
        publisher_key: '%env(MERCURE_PUBLISHER_JWT_KEY)%'
        publish_url: 'http://localhost:8080/.well-known/mercure'   # the URL of the hub for the server
```

Performance: a handler with no `#[Async]` has no extra cost. An `#[Async]` event writes one
message (with the state of the page) to the transport. The worker renders the path to the
handler again (as the first pass of an event), calls the method body again, and publishes with
one HTTP request for each topic. The Doctrine transport polls the database: use Redis or AMQP in
production, and enable OPcache in the CLI (`opcache.enable_cli=1`).

`phemc` reports E054 for `#[Async]` on a function, and E055 for an `#[Async]` method that does not
declare `Promise` as its return type. The runtime also checks the handler that an event calls: an
`#[Async]` handler that returns no `Promise`, or a handler method that returns a `Promise` with no
`#[Async]`, throws. The tests run the work with `settle()` (see
[Testing](testing.md#actions)). The Reports example uses it.

## 9. Props and lifting state up

The props of a function component (a function with `#[Component]`) are its named parameters.
A function component has no state.
It can receive a handler as a prop.

```php
#[Component]
function MyButton(int $count, Closure $onClick): Node {
    return <button onClick={{ onClick }}>Clicked {{ count }} times</button>;
}

#[Page('/counters')]
final class MyApp
{
    #[State] public int $count = 0;

    public function handleClick(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return (
            <Document>
                <h1>Counters that update together</h1>
                <MyButton count={{ count }} onClick={{ handleClick }} />
                <MyButton count={{ count }} onClick={{ handleClick }} />
            </Document>
        );
    }
}
```

- `prop="text"` passes a string, `prop={{ expr }}` passes any value, and a bare `prop` passes `true`.
- Children go to the `?Html $children` parameter (or `#[Prop]` property): `<Card>...</Card>`.
- Named slots: `<Card:footer>...</Card:footer>` goes to the `?Html $footer` parameter.
- An `Html` parameter (or `#[Prop]` property) with no `?` and no default is a required slot:
  `Html $title`. When the caller does not give it, the compiler reports E011 (`<Card>` needs
  the slot `title`).
- A default for children or a slot: `{{ footer ?? 'No footer' }}` shows when the caller gives none.
- An unknown prop is an error, unless the function has a variadic `...$rest` parameter (or
  the class a `#[Prop(rest: true)]` property).
- `<Root>` (`use Phem\Root;`) is a built-in component: the root element of a page, in the
  document. It takes `tag` and any attribute (see [compiling.md](compiling.md#the-document-of-the-pages)).

### Rest props

A variadic parameter collects the props that no other parameter takes, as a hash. This is
`function CountButton({ count, ...rest })` in React. Spread it on an element to forward the
attributes and the handlers:

```php
#[Component]
function CountButton(int $count, mixed ...$rest): Node {
    return <button type="button" {{ ...rest }}>Clicked {{ count }} times</button>;
}

<CountButton count={{ count }} onClick={{ handleClick }} className="primary" aria-label="Count" />
```

The button gets `class`, `aria-label`, and the click handler. The caller can also replace
`type`, because the spread comes after it. Put an attribute after the spread to make it fixed.
Use `mixed` (or no type) for the variadic parameter, because the values can be strings,
booleans, or handlers. To forward the props to another component, spread them on its tag:
`<CountButton count={{ 1 }} {{ ...rest }} />`.

A class component has a rest prop for this: a `#[Prop(rest: true)]` property. It gets the same
hash, and it is empty when the caller gives no other props. You choose its name:

```php
#[Component]
final class CountButton
{
    #[Prop] public int $count = 0;
    #[Prop(rest: true)] public array $attrs = [];

    public function render(): Node
    {
        return <button type="button" {{ ...attrs }}>Clicked {{ count }} times</button>;
    }
}
```

A class has at most one rest prop, and its type must accept an array: `array`, `iterable`,
`mixed`, or no type (compiler error E043). The other rules of a prop also apply to it (E042).
The rest prop is not a named prop: `<CountButton attrs="x" />` puts `attrs` in the hash.

### Context

Props pass data one level at a time. When a deep component needs a value of a component far
above it, each component in between must take the prop and pass it on ("prop drilling"). A
context gives the value directly, as `createContext` and `useContext` do in React.

Use a context for a value that **depends on the position in the tree**: the theme of a section,
the density of a table, or the nearest `<Tabs>` or `<Form>` of an item and its state. A value that
is the same for the full request (the current user, the locale of the request, a repository) is
a service: inject it in the constructor (see [Services and `mount()`](#8-services-and-mount)).

A class component provides a context with `#[Provide]`. On the class, the component itself is
the context: the items of a compound component read its state and call its handlers. A component
below reads it with `#[Context]`, on a property of a class or on a parameter of a function:

```php
use Phem\Attribute\{Component, Context, Prop, Provide, State};
use Phem\{Html, Node};

#[Component, Provide]
final class Tabs
{
    #[State] public string $active = 'general';
    #[Prop] public ?Html $children = null;

    public function select(Event $e): void
    {
        $this->active = $e->value;
    }

    public function render(): Node
    {
        return <div role="tablist">{{ children }}</div>;
    }
}

#[Component]
function Tab(string $name, string $label, #[Context] Tabs $tabs): Node
{
    return (
        <button role="tab" aria-selected={{ tabs.active == name }} onClick={{ tabs.select }} value={{ name }}>
            {{ label }}
        </button>
    );
}

<Tabs>
    <Tab name="general" label="General" />
    <Tab name="security" label="Security" />
</Tabs>
```

On a property, the value of the property is the context:

```php
enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
}

#[Component]
final class ThemeScope
{
    #[State, Provide] public Theme $theme = Theme::Light;
    #[Prop] public ?Html $children = null;

    public function toggle(): void
    {
        $this->theme = Theme::Light === $this->theme ? Theme::Dark : Theme::Light;
    }

    public function render(): Node
    {
        return <div><button onClick={{ toggle }}>Toggle</button>{{ children }}</div>;
    }
}

#[Component]
final class Chart
{
    #[Context] public Theme $theme = Theme::Light;   // optional: the value when no component above provides it

    public function render(): Node { /* ... */ }
}
```

- The **declared type is the key**: one class, interface or enum, nullable or not. The lookup
  walks up the tree and takes the nearest provider whose value is an instance of the type. So
  an interface (`#[Context] FormContext $form`) works with each form that implements it. A
  scalar, an array, `Closure`, `object`, `mixed` or a union type is the compiler error E044: use
  an enum or a value object, and provide the object that has a handler.
- A context with no default value and a type that does not accept null is **required**. When no
  component above provides it, the render throws, with the path of the components (an
  `<ErrorBoundary>` catches it). `phemc` reports E015 when it is sure: a page renders the
  component, through markup that the components return, with no provider on the way. When the
  tag is in the slot of another component (`<Layout><Tab /></Layout>`), the compiler does not
  know where `Layout` prints it, and only the runtime checks it.
- A `#[Context]` property is not a prop and not state: the parent cannot set it (E011), and the
  browser does not keep it. It is not static and not readonly. Phem sets it after the props and
  before the state or `mount()`, so `mount()` and the handlers can read it. A consumer that must
  keep a copy (an initial value) puts it in a `#[State]` property in `mount()`.
- The value is live: a consumer reads its provider when it renders. After an event, the
  consumers show the new value, also under a component that did not render again, as in React.
  A provider of itself, or of a mutable object (an entity), renders its consumers each time that
  it renders. A provider of an enum or of a readonly object renders them only when the value is
  another one.
- A consumer can change its provider: `onClick={{ tabs.select }}` runs the handler of the
  provider (see [Events](#6-events)), and a method of the consumer can call a method of the
  provider. The provider renders again.
- A function component with a `#[Context]` parameter is not a template component: it needs the
  render tree for the lookup.
- In a plain Twig template, `{% component %}` renders in its own tree: no component outside it
  provides a context.
- The Symfony profiler shows the contexts that each component reads and provides, and the
  components that rendered because a context changed.

## 10. Markup values

A markup literal is a value, like a React element: phemc compiles `<p>{{ x }}</p>` to
`new \Phem\Element('file.0.twig', ['x' => $x])`. Phem renders it when markup prints it
(`{{ content }}`), or when the component returns it. Markup that does not print (the other
branch of `{{ on ? yes : no }}`) renders no component. An array of markup prints as a list,
and its `null` and `false` items print nothing. Do not cast a markup literal to a string in PHP.

`Phem\Node` is the type of markup, like `ReactNode`. Use it as the return type of a function
component and of `render()`:

- A markup literal is a `Phem\Element` (not rendered yet).
- A prop or a slot with markup (`$this->children`) is a `Phem\Html` (rendered markup).

Both are a `Node`. Use `?Node` for a component that can render nothing (`return null;`), and
`Node|string` for a component that can return text.

The type of a prop decides what an array of markup becomes (`items={{ [<li>A</li>, null] }}`):

- A prop with the type `array` (or `?array`) gets a PHP array, with the same items. The markup
  items are `Phem\Html`, and the `null` and boolean items stay. Use it to read or reorder the items.
- A prop with another type (`Node`, `Html`, `iterable`, `mixed`) gets a `Phem\HtmlList`: it prints
  as one block, and you can iterate it. It has only the markup items: a string or a number becomes
  text, and a `null` or a boolean item goes out. phemc does not need the return type, but
PHP and PHPStan then check what the component returns.

Components also work in the normal Twig templates of the application, without state:
`{% component 'App\\Component\\Profile' with {person: person} %}`.

## 11. Template components (fast path)

A component whose body is only `return <markup>;` (no PHP statements) compiles to a
plain Twig template. It renders through Twig `include` (no children) or `embed` (children and
slots, as Twig blocks), with no PHP call. You write it in the same way as other components.
