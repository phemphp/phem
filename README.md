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

use Phem\Attribute\{Component, Page};
use Phem\{Html, Node, Root};

#[Page('/')]
function HomePage(): Node
{
    return (
        <Document title="Phem">
            <h2>Hello, Phem!</h2>
            <p>UI server components for Symfony web apps, made of old friends.</p>
        </Document>
    );
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

## Editor support

The Phem language server (`phemc lsp`) gives diagnostics as you type, completion, go to
definition, find references, hover and the outline of `.phem` files. These plugins use it:

- [VS Code](editors/vscode/README.md): install the `yceruto.phem` extension from the
  Marketplace. It includes the server, so you do not need `phemc`, PHP or Rust.
- [PhpStorm](editors/phpstorm/README.md): install the
  [Phem plugin](https://plugins.jetbrains.com/plugin/36117-phem) from the JetBrains Marketplace
  (Settings > Plugins > Marketplace, search for "Phem"). It runs the bundled server with
  Node.js, or a native `phemc` binary.
- [Claude Code](editors/claude-code/README.md): the plugin runs `vendor/bin/phemc lsp` of your
  app, so Claude gets the compiler errors after each edit of a `.phem` file.

  ```sh
  claude plugin marketplace add phemphp/phem
  claude plugin install phem@phem
  ```

Other editors with LSP support can run `phemc lsp` on stdin and stdout. See
[`phemc lsp`](docs/compiler.md#phemc-lsp).

## Documentation

Read the docs on the website: https://phem.dev/.