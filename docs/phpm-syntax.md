# The `.phpm` file format

A `.phpm` file is a PHP 8.5 file that can contain **markup literals**, the same way a `.jsx` file is a
JavaScript file that contains JSX. The `phemc` compiler turns each markup literal into a Twig
template and turns the PHP code into plain PHP. All parsing happens at build time.

A `.phpm` file opens PHP with `<?phpm`. A plain `<?php` tag is an error (E006). The compiler
writes `<?php` in the PHP that it generates.

## 1. Components

A component is a PHP function with the `#[Component]` attribute. It returns markup.
As in React, a string that it returns is text: Phem escapes it.
A component with state is a `#[Component]` class (see section 7).

```php
<?phpm
namespace App\Components;

use Phem\Attribute\Component;

#[Component]
function MyButton() {
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

A page is a class with the `#[Page]` attribute. React uses `export default` for the page
component of a file. In Phem, `#[Page]` exposes a class at a URL, as `#[Route]` does for a
Symfony controller. The file stays valid PHP. A page class is a component: it needs no
`#[Component]`. It renders its content in the document of the application, a component that
renders the `<html>` element and the `<Root>` of the page (see
[compiling.md](compiling.md#the-document-of-the-pages)). The page gives the title with the
`title` slot of the document. A function cannot be a page.

```php
use Phem\Attribute\Page;

use function App\Components\Document;

#[Page('/', name: 'home')]
final class MyApp
{
    public function render()
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
`App\Pages\Examples\Counter` → `app_pages_examples_counter`. A file can have any number of
pages, in any directory. The Symfony `#[Route]` attribute works too, on a `#[Component]` class.

### Route values

A route value goes to the page as a prop. Mark the public property with `#[Path]` (it needs no
`#[Prop]`). The name of the property is the name of the route value.

```php
use Phem\Attribute\{Page, Path};

use function App\Components\Document;

#[Page('/hello/{name}', defaults: ['name' => 'World'])]
final class Hello
{
    #[Path] public string $name;

    public function render()
    {
        return <Document><p>Hello {{ name }}</p></Document>;
    }
}

#[Page('/items/{id}', requirements: ['id' => '\d+'])]
final class ItemPage
{
    #[Path] public int $id;

    public function render()
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

use function App\Components\Document;

#[Page('/scientists/{id:scientist}', requirements: ['id' => '\d+'])]
final class ScientistPage
{
    #[Path] public Scientist $scientist;

    public function render()
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
- A mapped route value needs a `#[Path]` prop with an entity type. Other class types are an
  error.

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
function Profile() {
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
function ShoppingList() {
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

## 6. Events

Pass the function. Do not call it.

```php
#[Component]
function MyButton() {
    $handleClick = fn() => error_log('You clicked me!');

    return <button onClick={{ handleClick }}>Click me</button>;
}
```

Supported events: `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`, `onKeyDown`, `onKeyUp`,
`onFocus`, `onBlur`. phemc warns about another event (W003): the browser does not send it.
The handler runs on the server. It receives a `Phem\Event` object with `$e->value`,
`$e->checked`, `$e->key`, `$e->form` (an array, for `onSubmit`), `$e->files` (the files of the
form, see [File uploads](#file-uploads)), and `$e->request`.
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
apply to a page class, for the page and for its events. Phem accepts an event only
from the same origin (`Sec-Fetch-Site`, or `Origin`), as JSON or as `multipart/form-data` with
files, so another site cannot send one (CSRF). A `POST` request without the header renders the page, if its route accepts `POST`.

`$e->request` is the Symfony `Request` of the event, with the form fields as its POST data.
The keys with brackets become nested arrays (`scientist[name]` → `['scientist' => ['name' => ...]]`),
as in a normal POST. A Symfony form can read it directly:

```php
public function add(Event $e): void
{
    $form = $this->forms->create(ScientistType::class);
    $form->handleRequest($e->request);
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

public function render()
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
Symfony `UploadedFile`, in `$e->files` and in `$e->request->files`. Thus a form with a `FileType`
field works with `handleRequest($e->request)`, as in a normal POST:

```php
public function add(Event $e): void
{
    $form = $this->forms->create(ScientistType::class)->handleRequest($e->request);
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
in the form. The `File` and `Image` constraints need the `symfony/mime` package. The
[PhotoUpload](../examples/quickstart/src/Pages/Examples/PhotoUpload.phpm) example of the
quickstart checks images with the `Image` constraint.

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

A handler can return a Symfony `RedirectResponse`. Then the page does not render again: the
browser goes to its URL (a Turbo visit). The URL is relative, or an `http:` or `https:` URL:
another scheme (`javascript:`) is an error. For example, after a delete:

```php
use Symfony\Component\HttpFoundation\RedirectResponse;

public function delete(): RedirectResponse
{
    $this->scientists->remove($this->scientist);

    return new RedirectResponse('/scientists');
}
```

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

The events go in their order. A handler that fails, or that returns a `RedirectResponse`,
sends no events: the browser leaves the page.

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

    public function render()
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

#[Component]
function TextField(Bound $value, string $label)
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

The browser keeps the full signed state of the page, but an event sends only its signature. The
server keeps each state that it signs (the page render and each event) in the `cache.phem.state`
pool, by its signature, so the request of an event stays small on a big page.

When the pool does not have the state (an expired entry, a restart, another machine with its own
pool), the server answers `409` with `{"state": "required"}` and does not run the handler. The
browser sends the same event again with the full state, compressed (see
[Event size](#event-size)). So the pool can lose an entry at any time: the only cost is one more
request. An event with files always sends the full state, so the files do not upload two times.

The state from the pool has the same checks as a state from the browser: the signature,
`phem.state_max_age` and `phem.state_bind_session`. A wrong entry in the pool cannot give a state
that the server did not sign.

The pool uses the `cache.app` adapter, with a lifetime of 3600 seconds. A lifetime shorter than
`phem.state_max_age` is correct. Each page view and each event writes one entry, so use a pool
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

`phem.state_cache: false` turns the cache off: then each event sends the full state.

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

## 7. State: class components

A component with state is a class with the `#[Component]` attribute and a `render()` method.
A page class (`#[Page]`) is a component too, with no `#[Component]`.

```php
use Phem\Attribute\{Component, Prop, State};

#[Component]
final class Counter
{
    #[Prop] public string $label = 'Clicked';   // prop
    #[State] public int $count = 0;             // state

    public function increment(): void
    {
        $this->count = min($this->count + 1, 10);
    }

    public function render()
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
`useCallback`, `useId`, `useContext`) is the compiler error E020.

## 8. Services and `mount()`

A class component is a Symfony service. The container makes a new object for each component
instance, and the constructor gets its dependencies (autowiring). Constructor parameters are
never props.

```php
use App\Repository\TodoRepository;
use Phem\Attribute\{Component, Prop, State};

#[Component]
final class TodoList
{
    #[Prop] public int $limit = 10;        // prop
    #[State] public array $todos = [];     // state

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

    public function render()
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
2. Phem writes the props into the `#[Prop]` properties.
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
- the plain properties that `mount()` or a handler of the component set in the same request
  (for example a Symfony form with its errors).

`render()` does not call services, and it does not read the time, the session, the superglobals
or static properties. Read the data in `mount()` or in a handler:

```php
// Not correct: the output changes when the database changes.
public function render()
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

public function render()
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
  components that it did not render. A component whose HTML changed is a warning in the log and
  in the console of the browser. This check doubles the work of an event: Phem does it only in
  debug mode. A class component with a plain property that is not readonly (a form that a
  handler sets) is not checked.

### Transactions

When DoctrineBundle is installed, Phem runs each event handler in a Doctrine ORM transaction.
When the handler returns, Phem flushes and commits. When the handler throws an exception, Phem
rolls back all of its changes. Thus the handler does not call `flush()`:

- To add an entity, call `persist()` (for example through a repository method).
- To delete an entity, call `remove()`.
- To change an entity that Doctrine loaded, set its properties. The flush finds the changes.

```php
use Phem\Attribute\{Component, Prop};

#[Component]
final class TodoItem
{
    #[Prop] public Todo $todo;             // prop: a managed entity

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

The `transactional` option controls this behavior. Its default is `true` when Doctrine ORM is
installed and enabled, and `false` if not. To select the components that use a transaction, set
the option to `false` and add `#[Transactional]` to each of these class components:

```yaml
# config/packages/phem.yaml
phem:
    transactional: false
```

```php
use Phem\Attribute\{Component, Transactional};

#[Component]
#[Transactional]
final class TodoItem
{
    // ...
}
```

A handler that a parent passes as a prop belongs to the parent: the parent's attribute applies.

## 9. Props and lifting state up

The props of a function component (a function with `#[Component]`) are its named parameters.
A function component has no state.
It can receive a handler as a prop.

```php
#[Component]
function MyButton(int $count, Closure $onClick) {
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

    public function render()
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
function CountButton(int $count, mixed ...$rest) {
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

    public function render()
    {
        return <button type="button" {{ ...attrs }}>Clicked {{ count }} times</button>;
    }
}
```

A class has at most one rest prop, and its type must accept an array: `array`, `iterable`,
`mixed`, or no type (compiler error E043). The other rules of a prop also apply to it (E042).
The rest prop is not a named prop: `<CountButton attrs="x" />` puts `attrs` in the hash.

## 10. Markup values

A markup literal is a value, like a React element: phemc compiles `<p>{{ x }}</p>` to
`new \Phem\Element('file.0.twig', ['x' => $x])`. Phem renders it when markup prints it
(`{{ content }}`), or when the component returns it. Markup that does not print (the other
branch of `{{ on ? yes : no }}`) renders no component. An array of markup prints as a list,
and its `null` and `false` items print nothing. Do not cast a markup literal to a string in PHP.

Components also work in the normal Twig templates of the application, without state:
`{% component 'App\\Components\\Profile' with {person: person} %}`.

## 11. Template components (fast path)

A component whose body is only `return <markup>;` (no PHP statements) compiles to a
plain Twig template. It renders through Twig `include` (no children) or `embed` (children and
slots, as Twig blocks), with no PHP call. You write it in the same way as other components.
