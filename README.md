> [!WARNING]
> This repository is under construction. The code, the API and the docs can change at any time.

# Phem

Phem is the place where PHP and Twig stop passing notes and start writing together. Logic and
markup live in one file, one component, one thought. It is built for Symfony web apps.

A `.phem` file is PHP at heart, with a component layer built on top of Twig, so your markup lives
right beside the code that gives it life:

```php
<?phem

namespace App\Ui;

use Phem\Attribute\{Component, Page, State};
use Phem\{Html, Node, Root};

#[Page('/')]
function HomePage(): Node
{
    return <Document title="Phem">
            <h2>Hello, Phem!</h2>
            <p>UI server components for Symfony web apps, made of old friends.</p>
        </Document>;
}

#[Component]
function Document(string $title, Html $chidren): Node
{
    return (
        <html>
            <head>
                <title>{{ title }}</title>
                {{ importmap('app') }}
            </head>
            <body>
                <Root>{{ children }}</Root>
            </body>
        </html>
    )
}
```

> [!NOTE]
> Phem brings a modern UI component model to Symfony and PHP.

## Install

```sh
symfony new my-app --webapp && cd my-app
composer require phem/phem
symfony serve
```

Build a new app from scratch using Phem. See [Create your first Phem app](docs/quickstart.md).

## Documentation

Read the docs on the website: https://phem.dev/.

## License

MIT.
