# Handle forms

This guide shows you how to get the data of a form in a handler. It covers typed form data, fields bound to state, Symfony forms, file uploads, live validation and unsaved changes. For the events that a form sends, see [Handle events](events.md).

## Map a form to an object

A form that is not bound to state sends its fields as strings in `$e->form`. Add `#[MapForm]` to a parameter of the handler to get them as an object. The Serializer sets the properties from the fields, with the types of the properties. The Validator then checks the `#[Assert]` constraints of the object:

```php
final class ClosedDates
{
    #[Assert\NotNull(message: 'Choose the first day.')]
    public ?\DateTimeImmutable $from = null;

    public ?\DateTimeImmutable $to = null;

    #[Assert\Positive]
    public int $guests = 1;
}
```

```php
use Phem\Attribute\{Component, MapForm, State};
use Phem\Validation;

#[Component]
final class ClosedDays
{
    #[State] public Validation $validation;

    public function __construct(
        private readonly Calendar $calendar,
    ) {
    }

    public function addClosed(#[MapForm] ClosedDates $dates): void
    {
        $this->calendar->close($dates->from, $dates->to ?? $dates->from, $dates->guests);
    }

    public function render(): Node
    {
        return (
            <form onSubmit={{ addClosed }}>
                <input type="date" name="from" />
                {% if validation.hasError('from') %}<p id="from-error">{{ validation.error('from') }}</p>{% endif %}
                <input type="date" name="to" />
                <input name="guests" />
                {% if validation.hasError('guests') %}<p id="guests-error">{{ validation.error('guests') }}</p>{% endif %}
                <button type="submit">Add</button>
            </form>
        );
    }
}
```

When the object is not valid, the handler doesn’t run. A submit with no first day and `0` guests renders the errors:

```html
<input type="date" name="from">
<p id="from-error">Choose the first day.</p>
<input type="date" name="to">
<input name="guests">
<p id="guests-error">This value should be positive.</p>
```

The rules:

- The errors go to the `Validation` property of the component (see [Read the errors with `Validation`](#read-the-errors-with-validation)). The field of an error is its property path: `from`, or `line.qty` for a nested object. Each submit replaces the errors of the last submit, and a valid submit removes them, also when the handler changes no state. They aren’t errors of the `#[State]` properties, so they don’t stop a `#[Validated]` handler.
- When the component has no `Validation` property, the first error goes to an error toast: `{"message": "Choose the first day.", "type": "error"}` (see [Show toast messages](client-side.md#show-toast-messages)).
- A field whose value doesn’t fit the type of its property gets the error “This value is not valid.”, as a bound field does. With `allow_extra_attributes: false` in the context, a field that the object has no property for gets it too. An empty field gives `null` to a nullable property, so type a property that can be empty as nullable.
- The Serializer reads the fields as the form path of `#[MapRequestPayload]` does: it converts the strings to `int`, `float`, `bool`, a date, or a backed enum case. It ignores the fields that the object has no property for.
- Map to a class for the form (a DTO), not to an entity. The Serializer sets each writable property of the object from the event, not only the properties that the form shows. The browser can send any field: with `#[MapForm] Product $product`, an event with `price=0` sets the price, also when the form has no price field. Copy the values of the DTO to the entity in the handler.
- The map and the validation run before the transaction of a `#[Transactional]` handler. A form with errors opens no transaction.
- The handler can also take the event, services and the other arguments. A closure in `render()` can take a `#[MapForm]` parameter too: `fn (#[MapForm] ClosedDates $dates, Event $e) => ...`.
- A call in the markup can’t give a value to a `#[MapForm]` parameter. `onSubmit={{ addClosed(preset) }}` fails at the render. Give the call arguments to the parameters before it: `addClosedFor(string $side, #[MapForm] ClosedDates $dates)` with `onSubmit={{ addClosedFor('front') }}`.

Map only the fields with a prefix with `name`. The form `<input name="dates[from]" />` maps to `#[MapForm(name: 'dates')] ClosedDates $dates`, and the field of its error is `dates.from`. Give the validation groups with `validationGroups`, and the context of the Serializer with `serializationContext`:

```php
public function addClosed(#[MapForm(name: 'dates', validationGroups: ['closed'])] ClosedDates $dates): void
```

`#[MapForm]` needs `symfony/serializer` (with PropertyInfo, which gives the types of the properties) and, for the constraints, `symfony/validator`. Don’t use `#[MapRequestPayload]` in a handler: the body of an event is the event, not the form, and an error gives an HTTP 422 response, not the errors of the form.

## Bind a field to state without a handler

A field that only copies its value into the state doesn’t need a handler. Add `phem:bind` to link a form field to a `#[State]` property:

```php
#[Component]
final class ScientistSearch
{
    #[State] public string $query = '';
    #[State] public bool $activeOnly = false;

    public function search(): void
    {
        // $this->query and $this->activeOnly hold the field values.
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

The field shows the property value. A change sends no request: the next event on the page sends the new value, and the server sets the property before the handler runs. Phem writes the `value`, `checked` or `selected` attribute of a bound field, so don’t write these attributes yourself.

The browser can send any value in a bound field. As with all state, don’t base a security decision on a bound property.

### Send each change to the server

Add `phem:live` to send each change right away, with no handler:

```php
<input phem:bind={{ name }} phem:live />
<p>{{ name ? 'Hello, ' ~ name ~ '!' : 'Type your name.' }}</p>
```

### Match the property type to the field

The property type must accept the field value, or phemc reports E033:

| Field | Property type |
|---|---|
| `<input>`, `<textarea>`, `<select>`, a radio button | `string`, `int`, `float`, a backed enum, or a date |
| `<input type="checkbox">` | `bool`, or `array` for a group of checkboxes |
| `<select multiple>` | `array` |

The server converts the field text to the property type. A value that doesn’t fit, such as `"abc"` for an `int`, leaves the property unchanged.

A date property (`?\DateTimeImmutable`) shows its value in the format of the `type` of the input: `Y-m-d` for `date`, `Y-m-d\TH:i` for `datetime-local`, `H:i` for `time`, `Y-m` for `month`, and `o-\WW` for `week`. The field shows the date in its own timezone, and the server reads the new text in the same timezone. The Serializer makes the date from the field text, so install `symfony/serializer`.

### Bind a key of an array

Use a path to bind one key of an `array` property: `phem:bind={{ filters.status }}`, or `phem:bind={{ rows[i].name }}` in a loop. The key must already exist in the array.

### Bind a property of a draft object

Use a path to bind a property of an object in the state (see [Keep a draft object in the state](components.md#keep-a-draft-object-in-the-state)):

```php
#[State, Assert\Valid] public ProductDraft $draft;

// In render():
<input phem:bind={{ draft.name }} />
<input type="date" phem:bind={{ draft.availableFrom }} />
<input phem:bind={{ draft.address.city }} />
```

The HTML of the first field is:

```html
<input data-phem-bind="phem45a372830427.draft[&quot;name&quot;]" value="Desk">
```

The path sets a public property that is not `readonly`, with the type of the property. phemc doesn't see the class of the draft, so it checks only that the property is an object or an `array`. These paths are errors when the page renders:

- a property that the object doesn't have;
- a path in an enum (`phem:bind={{ size.value }}`): bind the enum itself;
- a path into an object in an `array` property (`phem:bind={{ pets.0.name }}`): only a property with a class type keeps objects.

### Bind a parent property from a child component

A child component can bind a property of its parent. Declare a `Phem\Bound` prop in the child, bind it inside the child, and put `phem:bind` on the child’s tag:

```php
#[Component]
function TextField(Bound $value, string $label): Node
{
    return <label>{{ label }} <input phem:bind={{ value }} phem:live /></label>;
}

// In the parent:
<TextField phem:bind={{ query }} label="Search" />
```

## Handle a Symfony form

The `Request` of a handler holds the form fields as its POST data, so a Symfony form reads them with `handleRequest()`. Keep the form in a property, so `render()` shows the errors after a failed submit:

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

The `get` hook, a PHP 8.4 property hook, creates a new form when no handler created one.

## Accept file uploads

The browser sends the files of a form with its submit event. The handler receives each file as a Symfony `UploadedFile`, both in `$e->files` and in the `Request`. A form with a `FileType` field works as it does in a regular POST:

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

PHP deletes the uploaded file at the end of the request, so move it in the handler. Check its size and type with `#[Assert\File]`.

The files stay in the input after the submit, so a form with a validation error keeps them. Once the handler stores the files, call `$e->clearFiles()` to empty the input in the browser.

### Show the upload progress

Pass the handler to `phem:progress` to show the progress of the upload:

```html
<form onSubmit={{ save }}>
    <input type="file" name="photos[]" multiple />
    <progress phem:progress={{ save }} max="100"></progress>
    <button type="submit">Upload</button>
</form>
```

The element shows only while the files upload. Its `--phem-progress` CSS property goes from 0 to 1, so any element can display it: `width: calc(var(--phem-progress) * 100%)`. For the progress of each file, listen for the `phem:upload-progress` event on the form.

### Limit the upload size

The `max_upload_size` option sets the maximum total size, in bytes, of the files in one event. The default is 8 MiB, and `0` turns the limit off. Over the limit, the server returns `413` and the handler doesn’t run:

```yaml
# config/packages/phem.yaml
phem:
    max_upload_size: 20971520 # 20 MiB
```

The options of PHP also apply. A body larger than `post_max_size` gets a `413`. A file larger than `upload_max_filesize` gets to the handler with an upload error, so check `$file->isValid()`.

## Validate a form while the user types

To show field errors before the submit, put Symfony constraints on the `#[State]` properties and add a `#[State]` property of type `Phem\Validation`. Phem sets that property for you:

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
```

The `render()` method binds each field and shows its first error with `phem:error` (see [Show the error of a bound field](#show-the-error-of-a-bound-field)):

```php
    public function render(): Node
    {
        return (
            <form onSubmit={{ save }}>
                <input type="email" phem:bind={{ email }} phem:live phem:debounce={{ 'blur' }} />
                <p phem:error={{ email }} className="error" />
                <input type="password" phem:bind={{ password }} phem:live phem:debounce={{ 'blur' }} />
                <p phem:error={{ password }} className="error" />
                <button type="submit">Sign up</button>
            </form>
        );
    }
}
```

When the user changes a field, Phem validates the component. It shows errors only for the fields the user touched, so the other fields stay clean. `#[Validated]` on the submit handler touches every field: when there are errors, the handler doesn’t run and the form shows all of them.

Install `symfony/validator`. phemc reports E058 for `#[Validated]` in a component with no `Validation` property.

### Read the errors with `Validation`

The `Validation` object has these methods:

| Method | Result |
|---|---|
| `error($field)` | The error of a handler, the first error of a touched field, the error of the last `#[MapForm]` form, or `null` |
| `hasError($field)` | `true` when the field has an error |
| `errors()` | All the errors |
| `touched($field)` | `true` when the user touched the field |
| `valid()` | `true` when there’s no error |
| `touch(...$fields)` | A new `Validation` with these fields touched |
| `withError($field, $message)` | A new `Validation` with an error of a handler on this field (see [Add an error from a handler](#add-an-error-from-a-handler)) |

A field’s name is the path of its `phem:bind`, for example `address.city`. For a draft object, put `#[Assert\Valid]` on the property: the errors of its constraints have the paths of the bound fields, for example `draft.name`. The error of a class constraint is `validation.error('')`. To start over, for example after a save, set `new Validation()`. Use `#[Validated(groups: ['draft'])]` to validate other groups. The next live validations of the component use these groups too, until you set a new `Validation`. `#[Validated]` also works on a closure in `render()`: `#[Validated] fn () => $this->save()`.

### Add an error from a handler

Some errors come from a check that no constraint does, for example a name that is already in the database. A handler adds such an error with `withError()`. The value is immutable, so set the new `Validation`:

```php
#[Validated]
public function add(): void
{
    if (null !== $this->categories->findByName(trim($this->name))) {
        $this->validation = $this->validation->withError('name', 'This name is taken.');

        return;
    }

    $this->categories->add(new Category(trim($this->name)));
    $this->name = '';
    $this->validation = new Validation();
}

public function render(): Node
{
    return (
        <form onSubmit={{ add }}>
            <input name="name" phem:bind={{ name }} phem:live />
            <p phem:error={{ name }} className="error" />
            <button type="submit">Add</button>
        </form>
    );
}
```

The field shows the error as the error of a constraint. `phem:bind`, `phem:error`, `error()`, `hasError()`, `errors()` and `valid()` all see it:

```html
<input name="name" data-phem-live="" data-phem-bind="phem70709d286391.name" value="Shoes" aria-invalid="true" aria-describedby="phem70709d286391-name-error">
<p class="error" id="phem70709d286391-name-error">This name is taken.</p>
```

The rules:

- The field is the path of its `phem:bind`, as for the other errors: `name`, `draft.name` for a draft object, or `''` for the error of the whole component. In a child with a `Bound` prop, the error of the parent property shows (see [Show the error of a bound field](#show-the-error-of-a-bound-field)).
- The error of the handler goes before the error of a constraint on the same field. A second `withError()` on the field replaces the first.
- The error stays in the state, also for the next events: a click on another button, or a change of another field, keeps it.
- The error goes when the user changes the field (or a parent or a child of its path), on the next submit of a handler (with or without `#[Validated]`), or with `new Validation()`. A submit runs the handler again, so the handler finds the error again.
- The error of the whole component (`''`) belongs to no field: a change of a field keeps it. It goes on the next submit, or with `new Validation()`.
- `withError()` touches no field.

### Show the error of a bound field

A bound field with an error gets two attributes from `phem:bind`:

- `aria-invalid="true"`. Use it in the CSS, for example the `aria-invalid:` variant of Tailwind.
- `aria-describedby` with the id of the error. A screen reader reads the error when the field gets the focus.

Put `phem:error` with the same property on the element of the message. The element renders only when the field has an error. Phem gives it the id of the error and the message as its content:

```php
<input name="email" phem:bind={{ email }} phem:live
       className="border border-line aria-invalid:border-danger" />
<p phem:error={{ email }} className="error" />
```

When the email has an error, the HTML is:

```html
<input name="email" class="border border-line aria-invalid:border-danger" data-phem-live="" data-phem-bind="phem45a372830427.email" value="ada" aria-invalid="true" aria-describedby="phem45a372830427-email-error">
<p class="error" id="phem45a372830427-email-error">This value is not a valid email address.</p>
```

When the field has no error, the field has no `aria-invalid` and the `<p>` is not in the page.

The rules:

- The id of the error is `<component id>-<field>-error`, with a `-` for each `.` of the field: `address.city` gives `…-address-city-error`. When the field has other characters than letters, digits, `_` and `.` (a key such as `rows['a-b'].name`), each run of them becomes one `-`, and the id also gets a hash of the field: `…-rows-a-b-name-<hash>-error`. The id is unique in the page, also when the page shows a component two times. The field needs no `id`.
- A spread on the element can't change the id of the error. On a bound field, Phem adds the error id after the `aria-describedby` of a spread too.
- The element of `phem:error` has no `id` and no content: Phem gives them. A void element (`<input>`, `<img>`) can't have it.
- When the field has its own `aria-describedby` (for example a help text), Phem adds the id of the error after it: `aria-describedby="city-help phem45a372830427-address-city-error"`.
- Don't write `aria-invalid` on a bound field. Phem sets it.
- The component needs a `Validation` property. phemc reports E061 when it has none.

`phem:error` also works on the tag of a component. The component gets the id in its `id` prop and the message in its `children`:

```php
#[Component]
function FieldError(string $id, ?Html $children = null): Node
{
    return <span id={{ id }} className="text-sm text-danger">{{ children }}</span>;
}
```

```php
<input name="name" phem:bind={{ name }} />
<FieldError phem:error={{ name }} />
```

In a child component with a `Bound` prop (see [Bind a parent property from a child component](#bind-a-parent-property-from-a-child-component)), use the prop: `<p phem:error={{ value }} />`. The error is the error of the property of the parent.

`phem:error` is not the `phem:error` event of the browser (see [Errors and limits](errors-and-limits.md)).

## Validate a Symfony form while the user types

A Symfony form keeps its values in the page, not in the state. Give the form an `onChange` handler that submits it, and Phem shows errors only for the fields the user touched. The `$form` property creates the form when it’s `null`, as in [Handle a Symfony form](#handle-a-symfony-form):

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

    // $this->form->getData()
    $this->form = null; // the next render creates a new, empty form
}
```

The form calls `validate` on each change and `save` on submit:

```php
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

A submit touches every field, so all the errors show. Add `novalidate`, or the browser checks the `required` fields itself and doesn’t send the submit. After a save, set `$this->form = null` to start with a new form. A handler for another event, such as a click on a “Save” button, can touch every field with `Phem\Form\TouchedFields::touchAll($request)`.

`isValid()` always checks every field, so a tampered request can’t save data with errors. Install `symfony/form`.

## Warn about unsaved changes

A bound field that the user changed, and that the server hasn’t received yet, gets the `phem-dirty` class. Its component, its form and the root element get the class too. Use it in CSS:

```css
input.phem-dirty { border-color: orange; }
form.phem-dirty .unsaved-hint { display: inline; }
```

Add `data-phem-guard` to an element to ask the user for confirmation before they leave the page with dirty fields inside it. Put it on the `<Root>` element to cover the whole page:

```php
<form onSubmit={{ save }} data-phem-guard={{ 'Discard the draft?' }}>
    <input phem:bind={{ title }} />
    <button type="submit">Save</button>
</form>
```

Only bound fields and file inputs can be dirty.

By default, `window.confirm()` asks the question. To ask it in a `<dialog>` of your page, give the selector of the dialog in `data-phem-guard-dialog`, and the label of the accept button in `data-phem-guard-accept`. The dialog is the same as for [`phem-confirm`](client-side.md#ask-in-a-dialog-of-the-page), so one dialog in the layout can serve the two:

```php
<form onSubmit={{ save }} data-phem-guard={{ 'Discard the draft?' }}
      data-phem-guard-dialog="#confirm" data-phem-guard-accept="Discard">
    <input phem:bind={{ title }} />
    <button type="submit">Save</button>
</form>

<dialog id="confirm" aria-describedby="confirm-message">
    <form method="dialog">
        <p id="confirm-message" data-phem-confirm-message></p>
        <button value="cancel">Back</button>
        <button value="confirm" data-phem-confirm-accept>Yes</button>
    </form>
</dialog>
```

When the user clicks a Turbo link with a dirty field in the form, the browser does these steps:

1. It cancels the click: Turbo doesn’t follow the link.
2. It puts the message of `data-phem-guard` in the `[data-phem-confirm-message]` elements of the dialog, and `data-phem-guard-accept` in its `[data-phem-confirm-accept]` elements. With no `data-phem-guard-accept`, the accept button keeps the label of the page.
3. It opens the dialog with `showModal()`.
4. When the dialog closes with the return value `confirm`, it visits the link with `Turbo.visit()`, with the `data-turbo-action` and the `data-turbo-stream` of the link, and doesn’t ask again. Any other close, such as **Back**, Escape or a tap on the backdrop, keeps the user on the page.

These edge cases apply:

- **A visit that is not a click on a link**: a visit that a script starts (`Turbo.visit()`), or that a Turbo form submit starts, asks with `window.confirm()`. Phem can’t start this visit again with the same options
- **A listener cancels the visit after the confirm**: the user stays on the page, and the guard asks again on the next visit and before the page unloads
- **A Turbo visit while the dialog asks** (the back button of the browser): the question ends when Turbo caches the page (`turbo:before-cache`). The dialog closes and gets the texts of the page again
- **A second click while the dialog asks**: the new question replaces the open question
- **The dialog cannot ask**: when the selector is not valid, finds no `<dialog>`, or finds a dialog that is open with no question, the browser logs an error and `window.confirm()` asks

A reload, a closed tab, or a link that leaves the app (a page with no Turbo) don’t show the dialog. The browser shows its own question on `beforeunload`, with its own text: a page can’t change it or show its own dialog.
