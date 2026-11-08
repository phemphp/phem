# Handle events and forms

This guide shows you how to respond when the user clicks, types or submits a form. It covers handler arguments, arguments in the markup, typed form data, bound fields, loading states, redirects, Symfony forms, file uploads, live validation and unsaved changes. For the syntax of the `on*` attributes and the `phem:*` directives, see [The `.phem` syntax](phem-syntax.md).

## Respond to a click, a key or a change

Give a handler to an `on*` attribute, and the browser sends an event to the server when the user acts. With `onClick={{ save }}`, the server runs the `save` handler, renders the components that can change and returns only the HTML that changed. The browser then morphs that HTML into the page.

A handler can take a `Phem\Event` argument with these properties:

| Property | Value |
|---|---|
| `$e->value` | The value of the element |
| `$e->checked` | Whether a checkbox is checked |
| `$e->key` | The key of a key event |
| `$e->form` | The form fields, by name; a submit, a change or a click sends them (see [Map a form to an object](#map-a-form-to-an-object)) |
| `$e->files` | The files of the form (see [Accept file uploads](#accept-file-uploads)) |

`onChange` on a text field fires as the user types. The browser waits until the user stops typing for 150 ms before it sends the event (see [Control when typing sends an event](#control-when-typing-sends-an-event)).

### Protect events with your access rules

An event is a `POST` request to the URL of its page, so the page’s access rules apply to its events too: the firewall, `access_control` and `#[IsGranted]`. An event also has the route name of the page (`_route`), so a rule on a route name covers it:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { route: '^admin_', roles: ROLE_ADMIN } # admin pages and their events
```

Phem accepts events only from the same origin, so another site can’t send one.

## Pass arguments to a handler

Call the handler in the `on*` attribute to give it values of the render. The call doesn’t run during the render: Phem keeps the handler and its arguments, and runs them when the user clicks:

```php
enum CancelReason: string
{
    case OutOfStock = 'out_of_stock';
    case CustomerAsked = 'customer_asked';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::OutOfStock => 'Out of stock',
            self::CustomerAsked => 'The customer asked',
            self::Duplicate => 'Duplicate order',
        };
    }
}

#[Component]
final class CancelOrder
{
    #[State] public ?CancelReason $reason = null;

    public function pickReason(?CancelReason $reason): void
    {
        $this->reason = $reason;
    }

    public function render(): Node
    {
        $reasons = CancelReason::cases();

        return (
            <div>
                {% for r in reasons %}
                    <button key={{ r.value }} aria-pressed={{ r == reason ? 'true' : 'false' }} onClick={{ pickReason(r) }}>{{ r.label }}</button>
                {% endfor %}
                <button onClick={{ pickReason(null) }}>Clear</button>
            </div>
        );
    }
}
```

A click on “Out of stock” runs `pickReason(CancelReason::OutOfStock)`, and a click on “Clear” runs `pickReason(null)`. The handler gets the enum case, not the string `'out_of_stock'`.

The arguments go to the first parameters of the handler, in order. The other parameters get the event, the services and the user, as before: `pickReason(?CancelReason $reason, Event $e)` also gets the event. An argument keeps its PHP value: an enum case stays an enum case, and an entity stays an entity.

The browser can’t change an argument. The browser sends only the handler id, and the id contains a fingerprint of the arguments. The server renders the component again and finds the handler with the same id. A handler that read `$e->value` from a `value` attribute had to check the value again. A handler with arguments doesn’t.

A call also works for a closure prop. The child adds its arguments after the arguments of the parent. Here, a shirt has a print on the front and on the back, and each print gets its own size picker:

```php
enum Size: string
{
    case Small = 's';
    case Large = 'l';
}

#[Component]
function SizePicker(Closure $onPick): Node
{
    $sizes = Size::cases();

    return <div>{% for size in sizes %}<button key={{ size.value }} onClick={{ onPick(size) }}>{{ size.name }}</button>{% endfor %}</div>;
}

#[Component]
final class ShirtOrder
{
    /** @var array<string, string> the size of each print */
    #[State] public array $sizes = [];

    public function pickSize(string $print, Size $size): void
    {
        $this->sizes[$print] = $size->value;
    }

    public function render(): Node
    {
        return (
            <div>
                <SizePicker onPick={{ pickSize('front') }} />
                <SizePicker onPick={{ pickSize('back') }} />
            </div>
        );
    }
}
```

A click on “Large” in the second picker runs `pickSize('back', Size::Large)`. The state keeps the value of the case (`'l'`): a property typed as one enum gets its case back after an event, but the items of an array stay strings.

`phemc` checks a call of a method of the class component (error E047): the number of arguments, an argument for an `Event` parameter, a named argument, and a literal of another type (`pickReason('out_of_stock')` gives a string to a `?CancelReason` parameter).

## Get services and the user in a handler

A handler receives its arguments the way a Symfony controller action does. The event is one of them, at any position, and you can leave it out. A class method can also take the `Request`, services, `#[CurrentUser]` and any other value resolver:

```php
public function save(Event $e, NoteRepository $notes, #[CurrentUser] User $user): void
{
    $notes->add($user, $e->value);
}
```

A closure in `render()` receives only the event and the `Request`. Type its parameter, as in `fn (Event $e) => ...`, and inject services through the constructor.

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

    public function __construct(private readonly Calendar $calendar)
    {
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
                {% if validation.error('from') %}<p id="from-error">{{ validation.error('from') }}</p>{% endif %}
                <input type="date" name="to" />
                <input name="guests" />
                {% if validation.error('guests') %}<p id="guests-error">{{ validation.error('guests') }}</p>{% endif %}
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
| `<input>`, `<textarea>`, `<select>`, a radio button | `string`, `int`, `float`, or a backed enum |
| `<input type="checkbox">` | `bool`, or `array` for a group of checkboxes |
| `<select multiple>` | `array` |

The server converts the field text to the property type. A value that doesn’t fit, such as `"abc"` for an `int`, leaves the property unchanged.

### Bind a key of an array

Use a path to bind one key of an `array` property: `phem:bind={{ filters.status }}`, or `phem:bind={{ rows[i].name }}` in a loop. The key must already exist in the array.

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

## Show a loading state

While an event runs, the element that sent it and its component get `aria-busy="true"` and the `phem-loading` class after a 200 ms delay. Style them with CSS.

To show an element only while a handler runs, pass the handler to `phem:loading`:

```html
<button onClick={{ save }}>Save</button>
<span phem:loading={{ save }} className="spinner">Saving…</span>
```

The server renders the element with `hidden`, and the browser shows it while a `save` event of this component runs. With a call, the element shows only for the handler with these arguments: `<span phem:loading={{ remove(row.id) }}>` shows in the row that the user clicked. To show a component, wrap it in an element: `<span phem:loading={{ save }}><Spinner /></span>`.

Set the `data-phem-loading-delay-value` attribute on the `<Root>` element to change the delay.

## Control when typing sends an event

The browser waits until the user stops typing for 150 ms before it sends the `onChange` and `onInput` events of a text field. Other events don’t wait. Change the wait for one element with `phem:debounce` or `phem:throttle`:

```html
<input onChange={{ search }} phem:debounce={{ 300 }} />
<input onChange={{ validate }} phem:debounce={{ 'blur' }} />
<input type="range" onInput={{ preview }} phem:throttle={{ 500 }} />
```

Each directive sends the event at a different moment:

| Attribute | The browser sends the event |
|---|---|
| `phem:debounce={{ 300 }}` | When no new event comes for 300 ms; `0` turns the wait off |
| `phem:debounce={{ 'blur' }}` | When the field loses focus |
| `phem:throttle={{ 500 }}` | Right away, then at most once every 500 ms; the last event always goes |

`onSubmit` and `onBlur` never wait. Any other event, such as a click or a submit, first sends every event that’s waiting, so the server always gets the input before the click. Set the `data-phem-debounce-value` attribute on the `<Root>` element to change the default wait.

## Redirect after a handler

Return a `Phem\RedirectTo` from a handler to send the browser to another page. The current page doesn’t render again, and the browser visits the new page with Turbo:

```php
use Phem\RedirectTo;

public function delete(): RedirectTo
{
    $this->scientists->remove($this->scientist);

    return new RedirectTo(ScientistsPage::class, ['page' => 2]);
}
```

Name a class page by its class, and a function page by its callable, such as `ScientistPage(...)` with `use function`. The second argument holds the route values, and any other value goes into the query string. For a URL that isn’t a page, return a Symfony `RedirectResponse`.

## Send events to your JavaScript

A handler can dispatch events to the JavaScript on the page:

```php
public function save(Event $e): void
{
    $this->scientists->save($this->scientist);
    $e->dispatcher->dispatch('toast', ['message' => 'Saved']);
}
```

After the morph, the browser dispatches a `phem:toast` event. A Stimulus controller can listen for it:

```html
<div data-controller="toast" data-action="phem:toast@window->toast#show"></div>
```

The event starts on the component’s element and bubbles up to `window`. A service can dispatch events too: inject `Phem\BrowserEventDispatcher`. A handler that fails or redirects dispatches no events.

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
| `error($field)` | The first error of a touched field, or `null` |
| `errors()` | All the errors |
| `touched($field)` | `true` when the user touched the field |
| `valid()` | `true` when there’s no error |
| `touch($field)` | A new `Validation` with this field touched |

A field’s name is the path of its `phem:bind`, for example `address.city`. The error of a class constraint is `validation.error('')`. To start over, for example after a save, set `new Validation()`. Use `#[Validated(groups: ['draft'])]` to validate other groups.

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
