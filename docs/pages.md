# Build pages and routes

A page is a component with a URL. This guide shows you how to declare a page, wrap the pages in the HTML document of your app, render the root element, load the routes of the pages, read and convert route values, link to a page, and redirect before a page renders.

## Declare a page

Add `#[Page]` to a class or a function to make it a page. A page needs no `#[Component]`, and by convention its name ends with `Page` (see [Organize the UI](structure.md#organize-the-ui)):

```php
use Phem\Attribute\Page;
use Phem\Node;

use function App\Ui\Layout\Document;

#[Page('/')]
final class HomePage
{
    public function render(): Node
    {
        return (
            <Document title="Home">
                <h1>Welcome to my app</h1>
            </Document>
        );
    }
}

#[Page('/about')]
function AboutPage(): Node
{
    return <Document title="About us"><h1>About us</h1></Document>;
}
```

`#[Page]` extends the Symfony `#[Route]` attribute, so it takes the same options: `name`, `methods`, `requirements`, `defaults`, `host` and the others. Repeat it to give a page more than one URL. With no `name`, the route name comes from the class: `App\Ui\Example\CounterPage` becomes `app_ui_example_counterpage`, and the next routes of the page get `app_ui_example_counterpage_1`, `_2` and so on. A link with the class of the page (`path(CounterPage)`, `RedirectTo`) goes to its first route. Markup links to a page with its class, so most pages need no `name` (see [Link to a page](#link-to-a-page)). On a function, use `#[Page]`, not `#[Route]` (E052).

The parameters of a function page get the same arguments as a Symfony controller: route values, the `Request`, services, `#[CurrentUser]` and the other value resolvers. A function page returns its markup, or a `RedirectTo` to send the browser to another page (see [Redirect before a page renders](#redirect-before-a-page-renders)).

## Wrap the pages in a document

The application owns the HTML document. It’s a component in the `.phem` sources like any other: it returns the `<html>` element and prints the page content in a `<Root>`. Phem adds the `<!DOCTYPE html>`:

```php
<?phem

namespace App\Ui\Layout;

use Phem\Attribute\Component;
use Phem\{Html, Node, Root};

#[Component]
function Document(string $title, Html $children): Node
{
    return (
        <html lang="en">
            <head>
                <title>{{ title }}</title>
                {{ importmap('app') }}
            </head>
            <body><Root>{{ children }}</Root></body>
        </html>
    );
}
```

Each page renders its content inside the document and sets its title with the `title` prop (`<Document title="Home">`), as `HomePage` does above. The title is a string, so it can hold the values of the page: `<Document title="Error {{ status }}">`. The document can have more props and slots, for example a `?Html $head` slot for the `<meta>` tags of a page. For another layout, such as an admin area, write another document component and use it in the pages of that area.

A `<title>` in the markup of a page isn’t the page title, and `phemc` reports it (W005). A `<title>` inside `<head>` or an `<svg>` is valid.

## Render the root element

`<Root>` (`Phem\Root`) is the built-in component that renders the root element of a page. Import it with `use Phem\Root;`:

| In the document | Renders |
|---|---|
| `<Root>{{ children }}</Root>` | `<div id="phem-root" data-controller="phem" …>` with the page |
| `<Root tag="main" className="app">{{ children }}</Root>` | The same, with another tag and more attributes |

The root element carries the attributes of the `phem` Stimulus controller, which pass the component state to the controller. Its props work this way:

- **`tag`**: the tag name, `div` by default
- **Other props**: attributes, as on an element (`className`, a class list, `style`, `data-*`)
- **`id`**: `phem-root` by default. `id={{ null }}` removes it
- **`data-controller`, `data-action`**: your values come after the Phem values
- **State attributes**: you can’t change them, and the root element can’t have an event handler

A page renders exactly one `<Root>`. A page with no `<Root>` or with two is an error, and so is a `<Root>` in a Twig template outside a page.

Events render only the components that can change (see [Keep `render()` pure](components.md#keep-render-pure)), and the browser updates only the content of the `<Root>`. The document around it, such as the title or a footer, doesn’t change. A component after the root element renders, but the `phem` controller doesn’t receive its DOM events. The browser sends the events of a page to the page URL, and only a page route runs them.

## Load the routes of the pages

A Symfony application (7.4 and later) imports the `routing.controllers` resource in `config/routes.yaml`, which holds the routes of the `#[Route]` controllers. `PhemBundle` adds the routes of the `#[Page]` pages to that resource, so the pages need no routes file:

```yaml
controllers:
    resource: routing.controllers
```

An application without this resource, created before Symfony 7.4, imports the pages with the `phem` type:

```yaml
# config/routes/phem.yaml
phem:
    resource: .
    type: phem
```

Use only one of the two. With both, each page gets its routes twice, and the last import replaces the routes of the first.

## Read route values

A class page receives a route value in a public `#[Path]` property whose name is the name of the route value. A function page receives it in the parameter with that name:

```php
use Phem\Attribute\{Page, Path};

#[Page('/items/{id}', requirements: ['id' => '\d+'])]
final class ItemPage
{
    #[Path]
    public int $id;

    public function render(): Node
    {
        return <Document title="Item {{ id }}"><h1>Item {{ id }}</h1></Document>;
    }
}

#[Page('/tags/{name}')]
function TagPage(string $name): Node
{
    return <Document title="Tag {{ name }}"><h1>{{ name }}</h1></Document>;
}
```

Each route value needs a `#[Path]` property or a parameter. Phem converts the value to the declared type, so `/items/3` sets `$id = 3`. A value that doesn’t fit the type returns a 404 response. A type with no converter, such as a backed enum, is an error of the page: add a converter for it (see [Convert route values to your own types](#convert-route-values-to-your-own-types)).

A Doctrine entity type receives the entity. `{scientist}` finds it by its identifier, and `{id:scientist}` maps the route value `id` to the property `scientist`. When no entity matches, the page returns a 404, but a nullable property (`?Scientist`) gets `null`:

```php
#[Page('/scientists/{id:scientist}')]
final class ScientistPage
{
    #[Path] public Scientist $scientist;
}
```

For other types, such as a date or a value object, see [Other types of route values](#convert-route-values-to-your-own-types).

### Convert route values to your own types

A converter turns a route value into another type, such as a date, an enum or a value object. Create a service that implements `Phem\Path\PathValueConverter`:

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

A `#[Path]` property of that type now receives the converted value:

```php
#[Page('/agenda/{day}')]
final class AgendaPage
{
    #[Path] public \DateTimeImmutable $day;
}
```

Throw an `\UnexpectedValueException` when the value doesn’t fit, and the page returns a 404. Your app’s converters run before the built-in ones.

A link doesn’t convert the value back. Give the route value itself: `path(AgendaPage, {day: '2026-11-10'})`, or `['day' => $day->format('Y-m-d')]` in PHP.

## Link to a page

Link to a page with its class or its function in `path()`, or in `url()` for an absolute URL. Import the page with `use` (or `use function`), as for a component tag:

```php
use App\Entity\Scientist;
use App\Ui\Scientists\ScientistPage;
use Phem\Attribute\Component;
use Phem\Node;

use function App\Ui\HomePage;

#[Component]
function ScientistLink(Scientist $scientist): Node
{
    return (
        <nav>
            <a href={{ path(HomePage) }}>Home</a>
            <a href={{ path(ScientistPage, {scientist: scientist, tab: 'papers'}) }}>{{ scientist.name }}</a>
        </nav>
    );
}
```

With the route `/scientists/{id:scientist}` of `ScientistPage`, the links render this way:

```html
<nav>
    <a href="/">Home</a>
    <a href="/scientists/5?tab=papers">Marie Curie</a>
</nav>
```

The values are by prop or by route value:

- **A prop with a route value** gives its route value: `{scientist: scientist}` gives `id`. An entity gives the value of the mapped field (`id` of `{id:scientist}`, `slug` of `{slug:post}`): its identifier, or a public property or a getter of the field. A prop with two route values (`/blog/{year:post.year}/{slug:post}`) gives the two. `{id: 5}` works too.
- **A backed enum** gives its value.
- **`_fragment`** sets the fragment of the URL: `{_fragment: 'comments'}` gives `#comments`.
- **The other values** go to the query string (`tab=papers`).

The third argument works as in Twig: `path(ScientistPage, {...}, true)` gives a relative path, and `url(ScientistPage, {...}, true)` gives a URL with no scheme (`//example.com/...`).

`phemc` checks that the class is a page with a route (E063). A page of a package has a route only when your app imports the pages of the package (see [Import the pages of a package](packages.md#import-the-pages-of-a-package)). The same values work in PHP, in `RedirectTo` and in `PageUrlGenerator::generate()`: `new RedirectTo(ScientistPage::class, ['scientist' => $scientist])`. Markup also reads the cases of an enum with its class name (see [Classes in markup](syntax.md#classes-in-markup)).

## Redirect before a page renders

A page can redirect when the browser opens it, for example after it checks a link token. Return a `RedirectTo` from a function page, or from the `mount()` of a class page. The page doesn’t render, and the response is a `302`:

```php
use Phem\Attribute\Page;
use Phem\RedirectTo;

use function App\Ui\Account\AccountPage;
use function App\Ui\Account\LinkVoidPage;

#[Page('/v/{token}', requirements: ['token' => Customers::TOKEN_PATTERN])]
function VerifyLink(string $token, VerifyLinks $links): RedirectTo
{
    return $links->verify($token)
        ? new RedirectTo(AccountPage(...), headers: ['Referrer-Policy' => 'no-referrer'])
        : new RedirectTo(LinkVoidPage(...));
}
```

`GET /v/abc123` with a valid token gets this response, with no HTML:

```http
HTTP/1.1 302 Found
Location: /account
Referrer-Policy: no-referrer
```

A function page can return a `RedirectTo` or markup. Declare the return type as `RedirectTo|Node`. In a class page, `mount()` returns `?RedirectTo`, and `null` renders the page:

```php
use App\Entity\Order;
use Phem\Attribute\{Page, Path, State};
use Phem\Node;
use Phem\RedirectTo;

use function App\Ui\Layout\Document;

#[Page('/orders/{id:order}')]
final class OrderPage
{
    #[Path] public Order $order;
    #[State] public string $note = '';

    public function mount(): ?RedirectTo
    {
        if ($this->order->isArchived()) {
            return new RedirectTo(ArchivedOrderPage::class, ['order' => $this->order]);
        }

        $this->note = $this->order->note;

        return null;
    }

    public function render(): Node
    {
        return (
            <Document title="Order {{ order.id }}">
                <h1>Order {{ order.id }}</h1>
                <p>{{ note }}</p>
            </Document>
        );
    }
}
```

`ArchivedOrderPage` is another class page in the same namespace, with the route `/orders/{id:order}/archived`: the prop `order` gives the route value `id` (see [Link to a page](#link-to-a-page)).

In an event, a page redirects only when it renders, and then the browser visits the URL, as for a handler that redirects:

- A page with `#[State]` runs `mount()` only on the first render, so it doesn’t redirect in an event.
- A function page, or a page with no `#[State]`, renders before the handler. If it returns a `RedirectTo` then, the handler doesn’t run.
- After the handler, the page renders again only when the handler is a method of the page, or when the handler calls `$e->refresh()`. A handler of a child component doesn’t make the page redirect.

Only a page can redirect from `mount()`. A component that isn’t a page throws a `LogicException`, so return the `RedirectTo` from a handler instead.
