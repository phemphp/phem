# Create your first Phem app

In this guide, you create a new Symfony app, install Phem, and build a page with an interactive counter. It takes about 10 minutes. When you’re done, you’ll have a dev server that compiles your `.phem` files each time you save them.

## Before you begin

You need these tools on your machine:

- **[Symfony CLI](https://symfony.com/download)**: it creates the app and runs the dev server
- **PHP 8.4 or later**: Phem doesn’t run on older versions
- **[Composer](https://getcomposer.org)**: it installs Phem

## Step 1: Create the app and install Phem

Start with a new Symfony web app, then add the `phem/phem` package. Run these commands:

```sh
symfony new my-app --webapp
cd my-app
composer require phem/phem
```

Symfony Flex asks before it runs the Phem recipe, because the recipe comes from the Flex “contrib” repository. Answer `y`. Run all the other commands in this guide from the `my-app/` directory.

The recipe sets up everything Phem needs, so you don’t create a config file or a routes file. It adds the first page of your app and the HTML document that wraps it:

```text
my-app/
├── src/
│   └── Ui/
│       ├── Common/
│       │   └── Document.phem   the HTML document of every page
│       └── HomePage.phem       the page at /
└── .symfony.local.yaml         runs the phemc watcher with the server
```

> [!TIP]
> If Composer finished without asking about the recipe, Flex skipped it. Allow the contrib recipes and install the recipe again:
>
> ```sh
> composer config extra.symfony.allow-contrib true
> composer recipes:install phem/phem --force
> ```

## Step 2: Start the dev server

The Symfony dev server serves your app and also runs `phemc`, the Phem compiler, in watch mode. Start it:

```sh
symfony serve
```

On the first start, the watcher downloads `phemc` and compiles your `.phem` files. You’ll see output like this:

```text
[phemc] Downloading phemc v0.3.1 (phemc-mac-arm64)...
[phemc] phemc: compiled 2 file(s), 2 template(s), 1 page(s); 7 file(s) changed, 0 warning(s) in 7 ms
[phemc] phem: compiled 2 template(s) into the Twig cache in 32 ms
[phemc] phemc: watching 1 source dir(s) for changes
```

Open the URL that `symfony serve` prints. By default, it’s `https://127.0.0.1:8000`. The page shows “Hello, Phem!”. Keep the server running for the rest of this guide.

## Step 3: Look at the document and the home page

Your app has two components so far. Open them in your editor to see how a page is built.

### The HTML document

`src/Ui/Common/Document.phem` is the HTML document that every page renders into. Because all pages share it, it lives in `src/Ui/Common/`:

```php title="src/Ui/Common/Document.phem"
<?phem

namespace App\Ui\Common;

use Phem\Attribute\Component;
use Phem\{Html, Node, Root};

// The HTML document of the pages. A page gives its title with <Document:title>.
#[Component]
function Document(Html $title, Html $children): Node
{
    return (
        <html lang="en">
            <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>{{ title }}</title>
                <link rel="icon" href="data:," />
                {{ importmap('app') }}
            </head>
            <body>
                <Root>{{ children }}</Root>
            </body>
        </html>
    );
}
```

Two lines in this file make the page interactive:

- **`<Root>`**: it marks the part of the page where Phem listens for events, such as clicks
- **`{{ importmap('app') }}`**: it loads `assets/app.js`, which starts the `phem` Stimulus controller in the browser

A page passes its title to the `title` slot with `<Document:title>`. Everything else inside `<Document>` goes to `children`.

### The home page

A page is a component with the `#[Page]` attribute, and that attribute is also its Symfony route. Page names end with `Page`. This page has no state, so it’s a function that returns markup:

```php title="src/Ui/HomePage.phem"
<?phem

namespace App\Ui;

use Phem\Attribute\Page;
use Phem\Node;

use function App\Ui\Common\Document;

#[Page('/')]
function HomePage(): Node
{
    return (
        <Document>
            <Document:title>My app</Document:title>
            <h1>Hello, Phem!</h1>
        </Document>
    );
}
```

## Step 4: Add an interactive counter

Now you’ll add a button that counts its clicks. A component with state is a class: its `#[State]` properties hold the state, and its methods handle the events. The browser keeps the state between events, and the event handler runs on the server.

Create `src/Ui/Counter.phem`:

```php title="src/Ui/Counter.phem"
<?phem

namespace App\Ui;

use Phem\Attribute\{Component, State};
use Phem\Node;

#[Component]
final class Counter
{
    #[State]
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return (
            <button type="button" onClick={{ increment }}>
                Clicked {{ count }} times
            </button>
        );
    }
}
```

When you click the button, `onClick={{ increment }}` runs `increment()` on the server, and Phem updates the button with the new count.

Next, use the counter twice on the home page. `Counter` is in the same namespace as `HomePage`, so you don’t need to import it. Change the `HomePage()` function in `src/Ui/HomePage.phem`:

```php title="src/Ui/HomePage.phem"
#[Page('/')]
function HomePage(): Node
{
    return (
        <Document>
            <Document:title>My app</Document:title>
            <h1>Hello, Phem!</h1>
            <Counter />
            <Counter />
        </Document>
    );
}
```

Save both files. The watcher compiles them again:

```text
[phemc] phemc: change detected, rebuilding
[phemc] phemc: compiled 3 file(s), 3 template(s), 1 page(s); 7 file(s) changed, 0 warning(s) in 8 ms
[phemc] phem: compiled 3 template(s) into the Twig cache in 15 ms
```

Reload the page and click the buttons. Each button counts its own clicks, because each `<Counter />` has its own state.

If a file has an error, the watcher prints the error with its line in the `.phem` file and keeps the previous build. To follow the watcher output from another terminal, run `symfony server:log`.

## Next steps

You now have a working Phem app. Here’s where to go from here:

- [Build a task list](tutorial.md): add props, lists, forms, and tests, one step at a time
- [Compiling and running `.phem` files](compiling.md): the `phemc` commands, diagnostics, and editor support
- [Deployment](deployment.md): build a production image with FrankenPHP
- [Philosophy and conventions](philosophy.md): the rules to keep in mind when you design with Phem
- [The `.phem` syntax](phem-syntax.md): markup, props, slots, lists, directives, and attributes
- [Components and state](components.md): the anatomy of a component, state, `mount()`, context, and URL state
- [Events and forms](events-and-forms.md): handlers, bound fields, forms, validation, and uploads
- [Testing components and pages](testing.md): test your components without a browser
