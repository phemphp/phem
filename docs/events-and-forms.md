# Events and forms

This page explains what happens when the user clicks, types or submits a form. For the syntax
of the `on*` attributes and the `phem:*` directives, see [The `.phpm` syntax](phpm-syntax.md).

## What an event does

When the user clicks a button with `onClick={{ save }}`, the browser sends an event to the
server. The server runs the `save` handler, renders the components that can change, and sends
back only the HTML that changed. The browser morphs it into the page.

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

## Handler arguments

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

## Bound fields

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

## Loading states

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

## Debounce and throttle

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

## Redirects

A handler can return a `Phem\RedirectTo`. The page then does not render again, and the browser
goes to the other page with Turbo:

```php
use Phem\RedirectTo;

public function delete(): RedirectTo
{
    $this->scientists->remove($this->scientist);

    return new RedirectTo(ScientistsPage::class, ['page' => 2]);
}
```

Name a class page by its class, and a function page by its callable: `ScientistPage(...)`, with
`use function`. The second argument has the route values. The other values go to the query
string. For a URL that is not a page, return a Symfony `RedirectResponse`.

## Browser events

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

## Symfony forms

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

## File uploads

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

## Live validation

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

## Live validation of a Symfony form

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

## Unsaved changes

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
