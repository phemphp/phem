# The `.phpm` syntax

A `.phpm` file is a PHP file with **markup literals** in it, as a `.jsx` file is JavaScript with
JSX in it. The `phemc` compiler turns each markup literal into a Twig template, and keeps the PHP
code as it is. All the parsing happens at build time.

Open PHP with `<?phpm`. A `<?php` tag is an error (E006). Phem needs PHP 8.4 or later. The syntax
of PHP 8.5 (for example the `|>` operator) works only when the app runs on PHP 8.5.

This page starts with the basics and goes step by step to the advanced features. Read the first
two parts to make your first interactive page. Come back to the other parts when you need them.

## The basics

### Your first component

A component is a PHP function with the `#[Component]` attribute. It returns markup, with the
return type `Phem\Node`:

```php
<?phpm
namespace App\Component;

use Phem\Attribute\Component;
use Phem\Node;

#[Component]
function MyButton(): Node
{
    return <button>I'm a button</button>;
}
```

Use it with a tag: `<MyButton />`. The compiler finds the component of a tag with the PHP rules:
a `use function` import, then a `use` import, then the current namespace.

A function with no `#[Component]` is a plain PHP function. You can call it in PHP, but a tag
cannot use it (E010). A component that has state is a class: see [State](#state).

### Markup rules

Markup follows the rules of JSX. Close all the tags: `<br />`, `<img ... />`. Return one root. To
return more than one element, put them in a fragment: `<>...</>`. A lowercase tag is an HTML
element, and a capitalized tag is a component. You can write `className` for `class`, and
`htmlFor` for `for`.

A markup literal starts where a PHP expression starts: after `return`, `=`, `(`, `,`, `=>`, `?`,
`:`, `??`, `[` or `echo`. Put parentheses around markup on more than one line.

Phem marks the root element of each component with the id of the component (`data-phem-c`). When
the root is not one element (a fragment, a text, another component), Phem puts a `<phem-c>`
element around it. A `<phem-c>` is not valid in a `<table>`, a `<tr>` or a `<select>`, so a row
or an option component must return one element.

### Show values

In markup, the text and the attribute values are Twig. The Twig variables are the PHP variables
of the scope: `$user` is `user`. The `app` global of Symfony is there too
(`{{ app.user.userIdentifier }}`).

```php
#[Component]
function Profile(): Node
{
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

| You write | You get |
|---|---|
| `{{ expr }}` in text | The value, escaped. Markup prints as HTML. |
| `attr="text {{ expr }}"` | A string with the value in it. |
| `attr={{ expr }}` | The value. `null` and `false` remove the attribute. `true` gives a boolean attribute. |
| `style={{ {...} }}` | A style from a hash. camelCase keys become kebab-case. Numbers get `px`, except for unitless properties (`opacity`, `zIndex`, ...). |
| `{{ ...attrs }}` in a tag | All the attributes of a hash. As in JSX, a later attribute replaces an earlier one. |

As in React, `data-*`, `aria-*` and `value` print a boolean as text: `data-open={{ false }}`
gives `data-open="false"`. Use `null` to remove them.

All the Twig filters, tests and tags work. Do not write PHP code in markup: compute the values in
PHP before the `return`.

Phem does not render a `javascript:` URL that comes from an expression in a URL attribute (`href`,
`src`, `action`). A spread never renders an inline script, such as `onclick` with a string.

### Conditions

A markup literal is a value, so a PHP `if` works:

```php
if ($isLoggedIn) {
    $content = <AdminPanel />;
} else {
    $content = <LoginForm />;
}

return <div>{{ content }}</div>;
```

You can also write the condition in Twig:

```twig
<div>{{ isLoggedIn ? <AdminPanel /> : <LoginForm /> }}</div>
<div>{{ isLoggedIn and <AdminPanel /> }}</div>   {# like && in JSX #}
<div>{% if isLoggedIn %}<AdminPanel />{% else %}<LoginForm />{% endif %}</div>
```

### Lists

Use a Twig loop, and give each item a `key`:

```php
#[Component]
function ShoppingList(array $products): Node
{
    return (
        <ul>
            {% for product in products %}
                <li key={{ product.id }}>{{ product.title }}</li>
            {% endfor %}
        </ul>
    );
}
```

An array of markup also prints as a list, as `map()` in React:

```php
$items = array_map(fn ($product) => <li key={{ product.id }}>{{ product.title }}</li>, $products);

return <ul>{{ items }}</ul>;
```

After an event, the browser matches the items by their key. Thus an item keeps its element (its
focus, its client changes) when an item before it goes away.

### Props, children and slots

The props of a function component are its parameters:

```php
use Phem\{Html, Node};

#[Component]
function Card(string $title, ?Html $children = null, ?Html $footer = null): Node
{
    return (
        <section className="card">
            <h2>{{ title }}</h2>
            {{ children }}
            <footer>{{ footer ?? 'No footer' }}</footer>
        </section>
    );
}
```

```php
<Card title="Hello">
    <p>The content of the card.</p>
    <Card:footer>Updated today</Card:footer>
</Card>
```

`prop="text"` gives a string, `prop={{ expr }}` gives any value, and a bare `prop` gives `true`.
The content of the tag goes to the `$children` parameter. A named slot (`<Card:footer>`) goes to
the parameter with its name. A slot with the type `Html`, with no `?` and no default, is
required: the compiler reports E011 when the caller does not give it. An unknown prop is an
error, unless the component has [rest props](#rest-props).

A normal Twig template of the app can also render a component, with no state:
`{% component 'App\\Component\\Profile' with {person: person} %}`.

### Pages

A page is a component that has a URL. Add `#[Page]` to a class or to a function. A page needs no
`#[Component]`.

```php
use Phem\Attribute\Page;
use Phem\Node;

use function App\Component\Document;

#[Page('/', name: 'home')]
final class Home
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

The page renders its content in the document of the app: a component that renders the `<html>`
element and the `<Root>` of the page (see
[compiling.md](compiling.md#the-document-of-the-pages)). The page gives its title with the
`title` slot.

`#[Page]` extends the `#[Route]` attribute of Symfony, so it has the same options: `name`,
`methods`, `requirements`, `defaults`, `host`, and the others. Use it more than one time to give
a page more than one URL. With no `name`, the route name comes from the class:
`App\Page\Example\Counter` gives `app_page_example_counter`.

A page with no state of its own can be a function. Its parameters get the same arguments as a
Symfony controller: the route values, the `Request`, the services, `#[CurrentUser]`, and the
other value resolvers.

```php
#[Page('/items/{id}')]
function Item(int $id, ItemRepository $items): Node
{
    $item = $items->find($id);

    return <Document><h1>{{ item.title }}</h1></Document>;
}
```

The components of a function page can have state. Their events go to the URL of the page, and the
page function runs again in each event, with a `POST` request. Use `#[Page]` on a function, not
`#[Route]` (E052).

### Route values

A class page gets a route value in a public property with `#[Path]`. The name of the property is
the name of the route value:

```php
use Phem\Attribute\{Page, Path};

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

Each route value needs a `#[Path]` property (or a parameter, on a function page). Phem converts
the value to the type of the property: `/items/3` gives `$id = 3`. A value that does not fit
the type gives a 404 response. The events of the page get the same route values, and the client
cannot change them.

A `#[Path]` property with a Doctrine entity type gets the entity, as the entity arguments of a
Symfony controller do:

```php
#[Page('/scientists/{id:scientist}')]
final class ScientistPage
{
    #[Path] public Scientist $scientist;

    // ...
}
```

`{scientist}` finds the entity by its identifier. `{id:scientist}` maps the route value `id` to
the property `scientist`, and finds the entity by its field `id`. When no entity matches, the
response is a 404, or `null` for a nullable property. For other types (a date, a value object),
see [Other types of route values](#other-types-of-route-values).

## Interactivity

### Events

Give a handler to an `on*` attribute. Pass the function, do not call it:

```php
#[Component]
function MyButton(): Node
{
    $handleClick = fn () => error_log('You clicked me!');

    return <button onClick={{ handleClick }}>Click me</button>;
}
```

The handler runs on the server. Phem then renders the page again and morphs only the components
that changed. In a class, a method name is a handler: `onClick={{ increment }}`. A member path
calls the method of an object: `onClick={{ tabs.select }}` runs `$tabs->select()`.

The supported events are `onClick`, `onDoubleClick`, `onChange`, `onInput`, `onSubmit`,
`onKeyDown`, `onKeyUp`, `onFocus` and `onBlur`. phemc warns about other events (W003).

A handler can take a `Phem\Event` argument:

| Property | Value |
|---|---|
| `$e->value` | The value of the element. |
| `$e->checked` | The state of a checkbox. |
| `$e->key` | The key of a key event. |
| `$e->form` | The fields of the form, by name. A submit, a change or a click sends them. |
| `$e->files` | The files of the form (see [File uploads](#file-uploads)). |

`onChange` on a text field fires as the user types, as in React. The browser waits until the user
stops typing for 150 ms before it sends the event (see
[Debounce and throttle](#debounce-and-throttle)).

An event is a `POST` request to the URL of the page. Thus the access rules of the page apply to
its events too: the firewall, `access_control`, `#[IsGranted]`. Phem accepts an event only from
the same origin, so another site cannot send one.

### Handler arguments

A handler gets its arguments as a Symfony controller action does. The event is one of them, at
any position, or not there. A method of a class can also get the `Request`, the services,
`#[CurrentUser]` and the other value resolvers:

```php
public function save(Event $e, NoteRepository $notes, #[CurrentUser] User $user): void
{
    $notes->add($user, $e->value);
}
```

A closure in `render()` gets only the event and the `Request`. Give its parameter a type:
`fn (Event $e) => ...`. Get the services in the constructor.

### State

A component with state is a class with `#[Component]` and a `render()` method:

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

A `#[Prop]` property is a prop. A prop with no default value is required. A `#[State]` property
is state: a handler changes it, and Phem calls `render()` again. Each instance of the component
has its own state. A page class is a component too, so it can have state.

In the markup of a class, a name is a local variable, then a property, then a method. The
properties and the methods of a parent class and of its traits work too.

The state lives in the browser, and the server signs it. Each event sends it back, so the state
must be JSON values (scalars and arrays) or backed enums. The state is UI state, not trusted data:
the browser can send back an older state that the server signed. Keep limits and security
decisions (tries, votes, roles) in the database or the session. `phem.state_max_age` (one day by
default) limits the age of a state, and `phem.state_bind_session: true` binds it to the session.

Phem has no hooks. A call to `Phem\useState` (or `useReducer`, `useRef`, `useMemo`,
`useCallback`, `useId`) is the error E020. For
`useContext`, see [Context](#context).

### Lift state up

When two components show the same data, keep the state in their common parent, and pass it down.
A function component can get a handler as a prop:

```php
#[Component]
function MyButton(int $count, Closure $onClick): Node
{
    return <button onClick={{ onClick }}>Clicked {{ count }} times</button>;
}

#[Page('/counters')]
final class Counters
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
                <MyButton count={{ count }} onClick={{ handleClick }} />
                <MyButton count={{ count }} onClick={{ handleClick }} />
            </Document>
        );
    }
}
```

The two buttons show the same count. A click on one of them changes both.

### Services and `mount()`

A class component is a Symfony service. Its constructor gets the services with autowiring. The
constructor parameters are never props.

```php
#[Component]
final class TodoList
{
    #[Prop] public int $limit = 10;
    #[State] public array $todos = [];

    public function __construct(private readonly TodoRepository $repository)
    {
    }

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

`mount()` runs on the first render, when the component has no state yet. The props are already
set. In an event, Phem restores the state from the browser and does not call `mount()`. A
component with no `#[State]` has no state to restore, so its `mount()` runs each time it renders.

In each request, Phem makes the object with the container, sets the props and the contexts,
restores the state (or calls `mount()`), runs the handler of the event, and then calls
`render()`.

### Keep `render()` pure

As in React, the output of `render()` depends only on the props, the state, the contexts, and the
properties that `mount()` or a handler set in the same request. `render()` does not call services,
and it does not read the time, the session or a static property. Read the data in `mount()` or in
a handler:

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

Phem uses this rule to render only the components that can change. An event renders the component
of the handler and the components whose state changed. A child that gets the same props as before
does not render again: the browser keeps its HTML.

When a handler changes data that other components read, call `$e->refresh()`. The full page then
renders again, as `router.refresh()` does in Next.js.

phemc reports W008 for a `render()` that reads a service, a static property, a superglobal, the
clock or a random value. In debug mode, Phem also renders the full page after each event, and
logs a warning for each component whose HTML changed with no render.

### Bound fields

A field that only copies its value into the state needs no handler. `phem:bind` links a form
field to a `#[State]` property:

```php
#[Component]
final class ScientistSearch
{
    #[State] public string $query = '';
    #[State] public bool $activeOnly = false;

    public function search(): void
    {
        // $this->query and $this->activeOnly have the values of the fields.
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

The field shows the value of the property. A change sends no request: the next event of the page
sends the new value, and the server sets the property before the handler runs. Add `phem:live` to
send each change at once, with no handler:

```php
<input phem:bind={{ name }} phem:live />
<p>{{ name ? 'Hello, ' ~ name ~ '!' : 'Type your name.' }}</p>
```

The type of the property must take the value of the field (E033):

| Field | Property type |
|---|---|
| `<input>`, `<textarea>`, `<select>`, a radio button | `string`, `int`, `float`, or a backed enum |
| `<input type="checkbox">` | `bool`, or `array` for a group of checkboxes |
| `<select multiple>` | `array` |

The server converts the text of the field to the type. A value that does not fit (`"abc"` for an
`int`) does not change the property. Phem writes the `value` (or `checked`, or `selected`) of a
bound field, so do not write these attributes yourself.

A path binds a key of an `array` property: `phem:bind={{ filters.status }}`, or
`phem:bind={{ rows[i].name }}` in a loop. The key must already be in the array.

A child component can bind a property of its parent. Declare a prop of the type `Phem\Bound` in
the child, bind it in the child, and put `phem:bind` on the tag of the child:

```php
#[Component]
function TextField(Bound $value, string $label): Node
{
    return <label>{{ label }} <input phem:bind={{ value }} phem:live /></label>;
}

// In the parent:
<TextField phem:bind={{ query }} label="Search" />
```

The browser can send any value in a bound field. As for all the state, make no security decision
with a bound property.

### Loading states

While an event runs, the element that sent it and its component get `aria-busy="true"` and the
`phem-loading` class, after a delay of 200 ms. Style them with CSS.

To show an element only while a handler runs, give the handler to `phem:loading`:

```html
<button onClick={{ save }}>Save</button>
<span phem:loading={{ save }} className="spinner">Saving…</span>
```

The server renders the element with `hidden`. The browser shows it while a `save` event of this
component runs. To show a component, put it in an element:
`<span phem:loading={{ save }}><Spinner /></span>`.

The `data-phem-loading-delay-value` attribute of the `<Root>` element changes the delay.

### Debounce and throttle

The browser waits until the user stops typing for 150 ms before it sends the `onChange` and
`onInput` events of a text field. The other events do not wait. Change the wait of one element
with `phem:debounce` or `phem:throttle`:

```html
<input onChange={{ search }} phem:debounce={{ 300 }} />
<input onChange={{ validate }} phem:debounce={{ 'blur' }} />
<input type="range" onInput={{ preview }} phem:throttle={{ 500 }} />
```

| Attribute | The browser sends the event |
|---|---|
| `phem:debounce={{ 300 }}` | When no new event comes for 300 ms. `0` turns the wait off. |
| `phem:debounce={{ 'blur' }}` | When the field loses the focus. |
| `phem:throttle={{ 500 }}` | At once, then at most one time in each 500 ms. The last event always goes. |

`onSubmit` and `onBlur` never wait. Another event (a click, a submit) first sends all the events
that wait, so the server always gets the input before the click. The `data-phem-debounce-value`
attribute of the `<Root>` element changes the default wait.

### Redirects

A handler can return a `Phem\RedirectTo`. The page then does not render again, and the browser
goes to the other page with Turbo:

```php
use Phem\RedirectTo;

public function delete(): RedirectTo
{
    $this->scientists->remove($this->scientist);

    return new RedirectTo(ScientistList::class, ['page' => 2]);
}
```

Name a class page by its class, and a function page by its callable: `ScientistPage(...)`, with
`use function`. The second argument has the route values. The other values go to the query
string. For a URL that is not a page, return a Symfony `RedirectResponse`.

### Browser events

A handler can send events to the JavaScript of the page:

```php
public function save(Event $e): void
{
    $this->scientists->save($this->scientist);
    $e->dispatcher->dispatch('toast', ['message' => 'Saved']);
}
```

After the morph, the browser dispatches a `phem:toast` event. A Stimulus controller can listen to
it:

```html
<div data-controller="toast" data-action="phem:toast@window->toast#show"></div>
```

The event starts on the element of the component, and it bubbles up to `window`. A service can
send events too: inject `Phem\BrowserEventDispatcher`. A handler that fails or redirects sends no
events.

## Forms

### Symfony forms

The `Request` of a handler has the form fields as its POST data, so a Symfony form reads it with
`handleRequest()`. Keep the form in a property, so `render()` shows the errors after a failed
submit:

```php
private ?FormInterface $form = null {
    get => $this->form ??= $this->forms->create(ScientistType::class);
}

public function add(Request $request): void
{
    $this->form->handleRequest($request);

    if ($this->form->isSubmitted() && $this->form->isValid()) {
        // $this->form->getData()
    }
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

The `get` hook (a PHP 8.4 property hook) makes a new form when no handler made one.

### File uploads

The browser sends the files of a form with its submit event. The handler gets each file as a
Symfony `UploadedFile`, in `$e->files` and in the `Request`. A form with a `FileType` field works
as in a normal POST:

```php
public function add(Request $request): void
{
    $form = $this->forms->create(ScientistType::class)->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $photo = $form->get('photo')->getData();
        $photo?->move($this->photoDir, $photo->guessExtension() ?? 'bin');
    }
}
```

PHP removes the file at the end of the request, so move it in the handler. Check its size and
type with `#[Assert\File]`.

The files stay in the input after the submit, so a form with a validation error keeps them. When
the handler stored the files, call `$e->clearFiles()`: the browser then empties the input.

To show the progress of the upload, give the handler to `phem:progress`:

```html
<form onSubmit={{ save }}>
    <input type="file" name="photos[]" multiple />
    <progress phem:progress={{ save }} max="100"></progress>
    <button type="submit">Upload</button>
</form>
```

The element shows only while the files upload. Its `--phem-progress` CSS property goes from 0 to
1, so any element can show it: `width: calc(var(--phem-progress) * 100%)`. For the progress of
each file, listen to the `phem:upload-progress` event of the form.

The files of one event have a maximum total size: `phem.max_upload_size` (8 MiB by default, `0`
for no limit). Over the limit, the server answers `413` and the handler does not run.

### Live validation

To show the errors of a field before the submit, put Symfony constraints on the `#[State]`
properties, and add a `#[State]` property of the type `Phem\Validation`. Phem sets this property:

```php
use Phem\Attribute\{Component, State, Validated};
use Phem\Validation;
use Symfony\Component\Validator\Constraints as Assert;

#[Component]
final class SignupForm
{
    #[State, Assert\NotBlank, Assert\Email]
    public string $email = '';

    #[State, Assert\Length(min: 8)]
    public string $password = '';

    #[State] public Validation $validation;

    #[Validated]
    public function save(): void
    {
        $this->accounts->open($this->email, $this->password);
        $this->validation = new Validation();
    }

    public function render(): Node
    {
        return (
            <form onSubmit={{ save }}>
                <input type="email" phem:bind={{ email }} phem:live phem:debounce={{ 'blur' }} />
                {% if validation.error('email') %}<p className="error">{{ validation.error('email') }}</p>{% endif %}
                <input type="password" phem:bind={{ password }} phem:live phem:debounce={{ 'blur' }} />
                {% if validation.error('password') %}<p className="error">{{ validation.error('password') }}</p>{% endif %}
                <button type="submit">Sign up</button>
            </form>
        );
    }
}
```

When the user changes a field, Phem validates the component. It shows only the errors of the
fields that the user touched, so the other fields stay clean. `#[Validated]` on the submit
handler touches all the fields. When there are errors, the handler does not run, and the form
shows all its errors.

| Method | Result |
|---|---|
| `error($field)` | The first error of a touched field, or `null`. |
| `errors()` | All the errors. |
| `touched($field)` | `true` when the user touched the field. |
| `valid()` | `true` when there is no error. |
| `touch($field)` | A new `Validation` with this field touched. |

The name of a field is the path of its `phem:bind`, for example `address.city`. The error of a
constraint on the class is `validation.error('')`. To start again (for example after a save), set
`new Validation()`. `#[Validated(groups: ['draft'])]` validates other groups.

Install `symfony/validator`. phemc reports E058 for `#[Validated]` in a component with no
`Validation` property.

### Live validation of a Symfony form

A Symfony form keeps its values in the page, not in the state. Give the form an `onChange`
handler that submits it. Phem shows only the errors of the fields that the user touched:

```php
public function validate(Request $request): void
{
    $this->form->handleRequest($request);
}

public function save(Request $request): void
{
    $this->form->handleRequest($request);

    if (!$this->form->isSubmitted() || !$this->form->isValid()) {
        return;
    }

    $this->saved = $this->form->getData()->email;
    $this->form = null;
}

public function render(): Node
{
    $view = $this->form->createView();

    return (
        <form onChange={{ validate }} onSubmit={{ save }} novalidate>
            {{ form_row(view.email) }}
            {{ form_row(view.password) }}
            <button type="submit">Save</button>
        </form>
    );
}
```

A submit touches all the fields, so all the errors show. Add `novalidate`, or the browser checks
the `required` fields itself and does not send the submit. After a save, set `$this->form = null`
to start with a new form. A handler of another event (a click on a "Save" button) can touch all
the fields with `Phem\Form\TouchedFields::touchAll($request)`.

`isValid()` always checks all the fields, so a changed request cannot save data with errors.
Install `symfony/form`.

### Unsaved changes

A bound field that the user changed, and that the server did not get yet, has the `phem-dirty`
class. Its component, its form and the root element get the class too. Use it in CSS:

```css
input.phem-dirty { border-color: orange; }
form.phem-dirty .unsaved-hint { display: inline; }
```

Add `data-phem-guard` to an element to ask the user before they leave the page with dirty fields
in it. Put it on the `<Root>` element for all the page:

```php
<form onSubmit={{ save }} data-phem-guard={{ 'Discard the draft?' }}>
    <input phem:bind={{ title }} />
    <button type="submit">Save</button>
</form>
```

Only bound fields and file inputs can be dirty.

## Reusable components

### Rest props

A variadic parameter gets the props that no other parameter takes, as a hash. Spread it on an
element to forward the attributes and the handlers:

```php
#[Component]
function CountButton(int $count, mixed ...$rest): Node
{
    return <button type="button" {{ ...rest }}>Clicked {{ count }} times</button>;
}

<CountButton count={{ count }} onClick={{ handleClick }} className="primary" aria-label="Count" />
```

The order counts. The caller can replace `type`, because it comes before the spread. Put an
attribute after the spread to make it fixed. Use `mixed`, because the values can be strings,
booleans or handlers.

In a class, the rest prop is a property with `#[Prop(rest: true)]` and the type `array`:

```php
#[Prop(rest: true)] public array $attrs = [];
```

### Merge of classes

With a spread, a `className` of the caller replaces the classes of the component. To keep the
default classes, mark them with `classes()`. The classes of the caller then merge into them:

```php
#[Component]
function Button(?Html $children = null, mixed ...$rest): Node
{
    return (
        <button className={{ classes('px-4 py-2 bg-blue-500') }} {{ ...rest }}>
            {{ children }}
        </button>
    );
}

<Button className="bg-red-500">Delete</Button>
// class="px-4 py-2 bg-red-500" with the Tailwind merger
```

`classes()` before the spread gives defaults that the caller can change. `classes()` after the
spread gives classes that the caller cannot remove. `classes()` takes the same inputs as clsx:
strings, lists, and maps of conditions (`{'active': isActive}`). A `class` attribute takes a list
too: `className={{ ['btn', {'active': isActive}] }}`. In PHP code, use `Phem\classes()`.

The default merger removes the duplicates. The Tailwind merger (Tailwind CSS v4) also removes the
earlier classes of the same group: `px-2` and then `px-4` gives `px-4`. Install
`tales-from-a-dev/tailwind-merge-php`, and turn it on:

```yaml
# config/packages/phem.yaml
phem:
    class_merger:
        tailwind:
            css: '%kernel.project_dir%/assets/styles/app.css'
            # prefix: tw
            # cache: app.psr16_cache
```

The Tailwind merger knows only the default theme. The `css` option gives it the custom values of
the `@theme` blocks of your CSS. Without it, a custom `text-caption` size counts as a color, and a
later color class removes it. You can also give the names with the `theme` option:
`theme: { text: [caption, price-lg] }`. Clear the cache in production after a change of the theme.

`style` does not merge: the last value wins.

### Dynamic element

A tag name is static. To choose the tag at runtime, use the built-in `<Dynamic>`
(`use Phem\Dynamic;`):

```php
#[Component]
function Button(string $tag = 'button', ?Html $children = null, mixed ...$rest): Node
{
    return (
        <Dynamic tag={{ tag }} className={{ classes('px-4 py-2') }} {{ ...rest }}>
            {{ children }}
        </Dynamic>
    );
}

<Button type="submit">Save</Button>          // <button class="px-4 py-2" type="submit">Save</button>
<Button tag="a" href="/docs">Docs</Button>   // <a class="px-4 py-2" href="/docs">Docs</a>
```

The other props are the attributes and the handlers of the element. `<Dynamic>` renders an HTML
element, not a component. The tag can come from data, so the runtime rejects the elements that can
run a script or load a resource (`script`, `style`, `iframe`, `object`, `base`, ...). Write them as
static markup.

### Enum props

A prop with a backed enum type takes the value of a case. The caller writes the value, and Phem
converts it with `::from()`:

```php
enum Variant: string
{
    case Primary = 'primary';
    case Outline = 'outline';
}

#[Component]
function Button(Variant $variant = Variant::Primary, ?Html $children = null): Node
{
    return <button className="btn btn-{{ variant.value }}">{{ children }}</button>;
}

<Button variant="outline">Save</Button>   // $variant is Variant::Outline
```

A value that is not a case is an error, with the list of the values. phemc checks a literal value
at build time (E018). For the classes of each case, see
[Variants of a component](packages.md#variants-of-a-component).

### Context

A context gives a value to all the components below, with no props in between. It is
`createContext` and `useContext` of React. Use it for a value that depends on the position in the
tree: the theme of a section, or the `<Tabs>` around a `<Tab>`. A value that is the same for all
the request (the current user, a repository) is a service.

A class with `#[Provide]` gives itself as the context. A component below reads it with
`#[Context]`:

```php
use Phem\Attribute\{Component, Context, Prop, Provide, State};
use Phem\{Event, Html, Node};

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

`#[Provide]` on a property gives the value of the property:
`#[State, Provide] public Theme $theme = Theme::Light;`.

The type is the key: Phem takes the nearest component above that provides a value of this type.
The type is a class, an interface or an enum (E044 for a scalar or an array). A context with no
default and no `null` is required: the render fails when no component above provides it (E015 when
the compiler can see it).

The value is live. When the provider changes, its consumers render again. A consumer can call a
handler of the provider, as `tabs.select` above.

### Markup values

A markup literal is a value, as a React element. phemc compiles `<p>{{ x }}</p>` to a
`Phem\Element` object, and Phem renders it when markup prints it. Markup that does not print (the
other branch of a condition) renders no component. Do not cast markup to a string in PHP.

`Phem\Node` is the type of markup, as `ReactNode`. A markup literal is a `Phem\Element`, and a
prop with markup (`$children`) is a `Phem\Html`. Both are a `Node`. Use `?Node` for a component
that can render nothing, and `Node|string` for a component that can return text.

The type of a prop decides what an array of markup becomes. A prop with the type `array` gets a
PHP array, so you can read or reorder the items. A prop with another type gets a `Phem\HtmlList`,
which prints as one block.

### Template components

A component whose body is only `return <markup>;` compiles to a plain Twig template. It renders
with a Twig `include` or `embed`, with no PHP call, so it is faster. You write it as any other
component.

## Client-side behavior

### Client code with Stimulus

Some changes need no server: open a menu, toggle a class, move the focus. Write them as
[Stimulus](https://stimulus.hotwired.dev/) controllers, with `data-controller` and `data-action`.

The morph of an event sets the attributes of the server render again. `data-phem-client` gives a
list of attributes to the client, and the morph does not change them:

```html
<button data-action="dropdown#toggle" data-phem-client="aria-expanded" aria-expanded="false">⋯</button>
```

`data-phem-ignore` keeps all the element: its attributes and its content. Use it for the element
of a JS library (a chart, a map, an editor):

```html
<div data-controller="chart" data-phem-ignore></div>
```

The element that sent an event gets these events, with the `handler` id in their detail:

| Event | When |
|---|---|
| `phem:before-queue` | Before the event goes in the queue. Call `preventDefault()` to stop it. |
| `phem:queue` | When the event goes in the queue. |
| `phem:send` | When the request starts. |
| `phem:receive` | When the server took the event, before the morph. |
| `phem:done` | When the event ends. `detail.ok` is `false` when it failed. |

### Optimistic UI

The `phem-optimistic` controller changes the page at once, before the server answers. When the
answer comes, the page shows the server render: the morph confirms the change, and a failed event
reverts it.

```html
<li key={{ id }} data-controller="phem-optimistic">
    {{ subject }}
    <button onClick={{ delete }} data-action="phem-optimistic#hide">Delete</button>
</li>
```

Put the action on the element that has the event handler. The actions are:

| Action | Change | Params |
|---|---|---|
| `hide`, `show` | the `hidden` attribute | |
| `addClass`, `removeClass`, `toggleClass` | a class | `class` |
| `setAttribute` | an attribute | `attribute`, `value` |
| `removeAttribute`, `toggleAttribute` | an attribute | `attribute` |
| `setText` | the text | `text` |
| `increment`, `decrement` | the first integer in the text | `by` |
| `insert` | a clone of a `<template>` | |

A param is an attribute of the element of the action:
`data-phem-optimistic-class-param="liked"`. With a `target` param, the action changes the
targets with that name in place of the controller element:

```html
<button onClick={{ like }} data-controller="phem-optimistic"
        data-action="phem-optimistic#toggleClass phem-optimistic#increment"
        data-phem-optimistic-class-param="liked" data-phem-optimistic-increment-target-param="count">
    ♥ <span data-phem-optimistic-target="count">{{ likes }} likes</span>
</button>
```

`insert` adds a copy of a `<template>` target. An element of the copy with
`data-phem-optimistic-field="body"` shows the value of the `body` field of the form.

### Built-in controllers

Phem has Stimulus controllers for common jobs. Their state belongs to the client, so it stays
after the morph of an event. They are not enabled by default. Enable each one that you use in
`assets/controllers.json`:

```json
"@phem/phem": {
    "optimistic": { "enabled": true, "fetch": "lazy" },
    "toggle": { "enabled": true, "fetch": "lazy" },
    "dialog": { "enabled": true, "fetch": "lazy" },
    "tabs": { "enabled": true, "fetch": "lazy" },
    "toast": { "enabled": true, "fetch": "lazy" },
    "clipboard": { "enabled": true, "fetch": "lazy" },
    "autofocus": { "enabled": true, "fetch": "lazy" },
    "confirm": { "enabled": true, "fetch": "lazy" }
}
```

#### Show and hide

`phem-toggle` shows and hides a dropdown, a menu or a section:

```html
<span data-controller="phem-toggle">
    <button data-action="phem-toggle#toggle" data-phem-toggle-target="button" aria-controls="actions">⋯</button>
    <span id="actions" data-phem-toggle-target="content" hidden>
        <button onClick={{ star }}>Star</button>
        <button onClick={{ archive }} data-action="phem-toggle#close">Archive</button>
    </span>
</span>
```

The actions are `toggle`, `open` and `close`. Escape and a click outside close it. Set
`data-phem-toggle-open-value="true"` to open it at the start, and
`data-phem-toggle-dismiss-value="false"` to keep it open on Escape.

#### Dialog

`phem-dialog` opens a native `<dialog>` as a modal:

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

The actions are `open` and `close`. Escape and a click on the backdrop close it. The server can
open it with `data-phem-dialog-open-value={{ editing }}`.

#### Tabs

`phem-tabs` shows the panel of the selected tab:

```html
<div data-controller="phem-tabs">
    <div data-phem-tabs-target="list" aria-label="Settings">
        <button id="tab-profile" data-phem-tabs-target="tab">Profile</button>
        <button id="tab-security" data-phem-tabs-target="tab">Security</button>
    </div>
    <div id="profile" data-phem-tabs-target="panel">...</div>
    <div id="security" data-phem-tabs-target="panel" hidden>...</div>
</div>
```

The tab at a position shows the panel at the same position. The arrow keys, Home and End move
between the tabs. The controller sets the ARIA roles and attributes. Give an `id` to each tab and
panel, so it can link them. `data-phem-tabs-index-value="1"` selects a tab at the start.

#### Toasts

`phem-toast` shows short messages that go away after some seconds. Put one element in the layout:

```html
<div data-controller="phem-toast"></div>
```

It shows the `phem:toast` events of the handlers, the failed events (`phem:error`), and the rate
limits (`phem:rate-limited`):

```php
$e->dispatcher->dispatch('toast', ['message' => 'Saved', 'type' => 'success']);
```

The types are `info`, `success`, `warning` and `error`. Change the duration and the texts with the
values of the controller: `data-phem-toast-duration-value={{ 8000 }}`,
`data-phem-toast-error-message-value`, `data-phem-toast-rate-limited-message-value`.

#### Clipboard

`phem-clipboard` copies a text to the clipboard and shows a short feedback:

```html
<div data-controller="phem-clipboard" data-phem-clipboard-copied-class="copied">
    <code data-phem-clipboard-target="source">{{ token }}</code>
    <button data-action="phem-clipboard#copy">Copy</button>
    <span data-phem-clipboard-target="feedback"></span>
</div>
```

It copies the `text` value, or else the text of the `source` target. The `feedback` targets show
"Copied" for two seconds. The browser lets a page copy only on HTTPS or `localhost`.

#### Autofocus

`phem-autofocus` gives the focus to its element when it connects. The native `autofocus` works
only when the page loads. This controller also works when an event adds the element:

```html
{% if editing %}
    <input name="title" data-controller="phem-autofocus" />
{% endif %}
```

It does not take the focus from a field that the user edits.

#### Confirm

`phem-confirm` asks the user before an event goes to the server:

```html
<button onClick={{ delete }} data-controller="phem-confirm"
        data-phem-confirm-message-value="Delete this item?">Delete</button>
```

When the user cancels, no event goes.

### Transitions

Add `data-phem-transition` to an element to animate the changes of its components with the
[View Transitions API](https://developer.mozilla.org/docs/Web/API/View_Transition_API). On the
`<Root>` element, all the events of the page have a transition. The default is a cross-fade.

The value is the type of the transition. Use it in CSS:

```html
<ul data-phem-transition={{ 'slide' }}>
```

```css
html:active-view-transition-type(slide)::view-transition-old(root) { animation: slide-out .2s ease-in; }
html:active-view-transition-type(slide)::view-transition-new(root) { animation: slide-in .2s ease-out; }
```

A browser with no support, or with `prefers-reduced-motion: reduce`, shows no transition.

## Server features

### State in the URL

A `#[Url]` property is in the query string. Filters, search terms and page numbers then stay after
a reload, in a shared link, and with the back button:

```php
use Phem\Attribute\{Component, State, Url};

#[Component]
final class Catalog
{
    #[State, Url(as: 'q')] public string $query = '';    // ?q=php
    #[State, Url(history: true)] public int $page = 1;    // ?page=2, with a history entry
    #[State, Url] public array $tags = [];                // ?tags[]=a&tags[]=b
}
```

The first render reads the query string, after `mount()`. After each event, the browser writes the
values into the URL. A value equal to the default removes its key. `as` gives another key, and
`history: true` adds a history entry for each change. The query string comes from the user, so do
not trust its values.

### Transactions

Add `#[Transactional]` to a handler method to run it in a Doctrine transaction. On the class, it
applies to all the handlers. Phem flushes and commits when the handler returns, and rolls back
when it throws. Thus the handler does not call `flush()`:

```php
#[Component, Transactional]
final class TodoItem
{
    #[Prop] public Todo $todo;

    public function toggle(): void
    {
        $this->todo->done = !$this->todo->done;   // saved at the commit
    }

    public function delete(): void
    {
        $this->em->remove($this->todo);           // deleted at the commit
    }
}
```

The transaction contains only the handler: `render()` and `mount()` run outside of it. A handler
that a parent gives as a prop uses the attribute of the parent.

### Content that loads after the page

A slow component makes all the page slow. Put it in a `<Suspense>` boundary. The page sends the
`fallback` slot first, and the slow content comes after:

```php
use Phem\Suspense;

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
```

With no `lazy`, the same response streams the content: the server sends the page with the
fallbacks, and then a chunk for each boundary. With `lazy`, the browser loads the content with an
event when the fallback comes into view. When the content throws, the `error` slot shows.

A Turbo visit cannot read a stream, so it loads the boundaries with an event, as `lazy` does. A
streamed page has no HTTP cache and no web debug toolbar. Turn off the buffers between PHP and the
browser (for example the compression of a proxy). `phem.suspense_stream: false` loads all the
boundaries with an event.

### Errors in a part of the page

An exception in the render of one component makes all the event fail. When a part of the page can
fail, put it in an `<ErrorBoundary>`. When the content throws, the `fallback` slot shows in its
place:

```php
use Phem\ErrorBoundary;

<ErrorBoundary>
    <ErrorBoundary:fallback><p>The stock quote is not available now.</p></ErrorBoundary:fallback>
    <StockQuote />
</ErrorBoundary>
```

The logger gets the exception. Each render tries the content again. As in React, the boundary
catches the errors of the render and of `mount()`, but not of a handler.

### Real-time updates

A change by one user does not show for another user until that user sends an event. A page with
`#[Broadcast]` gets updates from other requests. A handler pushes them with `Phem\Stream\Streams`,
and the Mercure hub sends them to the open pages:

```php
use Phem\Attribute\{Broadcast, Component, Page, State, Transactional};
use Phem\Stream\Streams;

#[Page('/guestbook'), Broadcast]
final class Guestbook
{
    // ... renders <SignForm />, <GuestbookStats id="guestbook-stats" /> and <ul id="entries">
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
}
```

`toPage(Guestbook::class)` sends to all the URLs of a page, and
`toPage(Guestbook::class, ['id' => 42])` to one URL. `toComponent(Inbox::class)` sends to all the
pages that render a component with `#[Broadcast]`. `everywhere()` sends to all the pages with
`#[Broadcast]`.

The actions are the Turbo Stream actions: `append`, `prepend`, `before`, `after`, `replace`,
`update` and `remove`. Each one takes the `id` of the target element. The markup goes to all the
viewers, so do not push data for one user only. Use `refresh($target)` for that: each browser
renders the component around the target again, with its own access rules.

The content that you push must also be in the server render (read it in `mount()`), or the next
event removes it. Give each pushed item an `id`, so the user who made the change sees it one time.
The streams go out after the transaction. When the handler throws, they are dropped.

#### The refresh of a component after its handlers

A class component with `#[Broadcast]` needs no stream for its own changes. After each of its
handlers, the other browsers that show it render it again:

```php
use Phem\Attribute\{Broadcast, BroadcastKey, Component, Prop, State, Transactional};

#[Component]
final class LikeButton
{
    #[Prop, BroadcastKey] public Post $post;
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
}
```

With `#[Broadcast]` on some handler methods, only these handlers refresh the component. With
`#[Broadcast]` on the class, all of them do. The props with `#[BroadcastKey]` identify an
instance: the refresh goes only to the instances of the same post.

#### Set up the hub

Phem publishes with `mercure_publish()` of FrankenPHP. Add the hub to the site in the Caddyfile,
and give Phem the subscriber key:

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
```

With the subscriber key, the updates are private: each page gets a cookie with access to its own
topics only. With no key, the updates are public, and the hub needs the `anonymous` directive.
The cookie is `Secure`, so it works on HTTPS and on `localhost`. On another plain HTTP host, set
`cookie_name` to a name with no `__Secure-` prefix.

#### A standalone hub

With another server (PHP-FPM), or when the hub runs apart from the app, Phem publishes with the
HTTP API of the hub. Add a publisher key to the hub and to Phem:

```caddyfile
mercure {
    transport local
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
        subscriber_key: '%env(MERCURE_SUBSCRIBER_JWT_KEY)%'
        publisher_key: '%env(MERCURE_PUBLISHER_JWT_KEY)%'
        publish_url: 'http://mercure/.well-known/mercure'   # the URL of the hub for the server
```

For private updates, serve the hub on the host of the app (`/.well-known/mercure`), and turn off
the buffer of the proxy:

```nginx
location /.well-known/mercure {
    proxy_pass http://mercure;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_buffering off;
    proxy_read_timeout 24h;
}
```

### Slow work after the response

A slow handler (an export, a payment, a call to an API) blocks the event. Put `#[Async]` on the
handler, and return the slow work in a `Phem\Async\Promise`. The event answers at once, and a
Symfony Messenger worker does the work after the response:

```php
use Phem\Async\Promise;
use Phem\Attribute\{Async, Component, State, Transactional};

#[Component]
final class BuildReport
{
    #[State] public string $ticket = '';

    public function mount(): void
    {
        $this->ticket = bin2hex(random_bytes(4));
    }

    #[Async, Transactional]
    public function build(): Promise
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
}
```

In the request, the method runs as a normal handler, and the event renders. Then the worker builds
the page again from the state of the event, calls the method again, and runs the closure of the
`Promise`. Thus the method body runs **two times**: keep it fast, with no side effects. Pass the
values that the work needs to the closure (`use ($ticket)`).

The worker has no response, so the closure shows its result with `Streams`. The worker has the
user of the request, but no session, no cookies and no files. Messenger can retry the closure, so
make it safe to run again.

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

The worker cannot use `mercure_publish()`. Give Phem the publisher key, as for
[a standalone hub](#a-standalone-hub). Tests run the work with `settle()` (see
[Testing](testing.md#actions)). phemc reports E054 for `#[Async]` on a function, and E055 for an
`#[Async]` method that does not return `Promise`.

### Rate limits

The `#[RateLimit]` attribute of Symfony limits the events of a page. Install
`symfony/rate-limiter`, configure a limiter, and add the attribute to the page or to one handler:

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
    #[RateLimit('phem_search')]
    public function search(Event $e): void
    {
        // ...
    }
}
```

An event is a `POST` request, so `methods: 'POST'` counts the events and not the page views. On a
method, the limit applies to that handler only. Over the limit, the server answers `429`, and the
browser dispatches `phem:rate-limited`, with `retryAfter` in its detail.

### Code around the handlers

A handler middleware runs code around each handler: a security check, a log, a metric. Make a
service that implements `Phem\Handler\HandlerMiddleware`:

```php
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

`$next()` runs the next middleware and then the handler. Return without a call to `$next()` to
stop the event. The middleware runs for all the handlers: check `$handler` or `$event` to apply it
to some of them only.

The tag priority sets the order. A higher priority runs first, around the others:

| Priority | Built-in middleware |
|---|---|
| 100 | `#[RateLimit]` |
| 50 | the Symfony profiler (debug only) |
| 10 | `RedirectTo` |
| 0 | the pushes of `Streams` |
| -50 | `#[Validated]` |
| -100 | `#[Transactional]` |
| -200 | `#[Async]` |

The default priority is 0, outside the transaction. To run in the transaction, set a priority
between -100 and -200 with `#[AsTaggedItem(priority: -150)]`.

### Other types of route values

A converter gives a route value another type: a date, an enum, a value object. Make a service that
implements `Phem\Path\PathValueConverter`:

```php
use Phem\Path\{PathProp, PathValueConverter};

final class DateConverter implements PathValueConverter
{
    public function supports(PathProp $prop): bool
    {
        return \DateTimeImmutable::class === $prop->type->getName();
    }

    public function convert(PathProp $prop, mixed $value): ?\DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value)
            ?: throw new \UnexpectedValueException(\sprintf('"%s" is not a date.', $value));
    }
}
```

```php
#[Page('/agenda/{day}')]
final class AgendaPage
{
    #[Path] public \DateTimeImmutable $day;
}
```

Throw an `\UnexpectedValueException` when the value does not fit: the page gives a 404. The
converters of the app run before the built-in converters.

## Errors and limits

### Failed events

When the server answers an event with an error (a 500, a 403), the page does not change. The
browser dispatches `phem:error` on the component of the handler, with the `status` in its detail.
With no listener, the browser shows a default message at the end of the page. Change its text on
the `<Root>` element:

```html
<Root data-phem-error-message-value={{ 'The change was not saved.' }}>
```

To show your own message, call `event.preventDefault()` in a listener of `phem:error`, or use the
[toasts](#toasts).

### Retries

A network error, a `502` or a `503` can be temporary. The browser sends the event again, up to 3
times, with a wait of 500 ms, 1 s and 2 s. When the last try fails, it dispatches `phem:error`.

A network error can come after the handler committed, so the handler can run two times. Make a
handler safe to run again, or turn off the retries:

```html
<Root data-phem-retries-value={{ 0 }} data-phem-retry-delay-value={{ 1000 }}>
```

### Event size

An event body has a maximum size, with the state of the page: `phem.max_event_size` (1 MiB by
default, `0` for no limit). Over the limit, the server answers `413`. The browser compresses a
large body with gzip.

The state of a page must fit in an event body. A state that is too large throws in debug mode,
with its largest entry. Keep less in `#[State]` (an id, not the rows), or raise the limit:

```yaml
# config/packages/phem.yaml
phem:
    max_event_size: 2097152
```

### State cache

For a large state, an event sends only its signature. The server keeps the state in the
`cache.phem.state` pool. A state is large from `phem.state_cache_min_size` bytes (4096 by
default). When the pool lost the state, the browser sends the event again with the full state, so
a miss costs only one more request.

Use a pool that removes old entries, such as Redis or APCu. With more than one server, a shared
pool gives more hits:

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.phem.state:
                adapter: cache.adapter.redis
                default_lifetime: 1800
```

`phem.state_cache: false` turns the cache off.
