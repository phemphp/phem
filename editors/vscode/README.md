# Phem for Visual Studio Code

Language support for [Phem](https://github.com/phemphp/phem) `.phem` files. A `.phem` file is
PHP with markup in it, the same way a `.jsx` file is JavaScript with JSX.

```php
<?phem

namespace App\Ui\Common;

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

The extension includes the Phem language server. You do not need to install `phemc`, PHP or
Rust.

## Features

- **Syntax highlighting** for PHP, markup, Twig, and the JavaScript and CSS in `<script>` and
  `<style>`.
- **Diagnostics as you type**, also for unsaved changes. The extension checks all the `.phem`
  files in your source directory, because one file can use the components of another file.
- **Completion** for components and their props and slots, HTML elements and attributes, Phem
  events (`onClick`) and attributes (`phem:loading`), Twig variables, filters and tags, PHP
  members, and route names in `path('...')`.
- **Auto import.** When you accept a component, a class or a function, the extension adds the
  `use` statement.
- **Go to definition** (`F12` or `Cmd`+click) on components, slots, props, PHP symbols, Twig
  variables, event handlers and route names.
- **Hover** with signatures, props, and the docs of Twig, HTML elements and HTML attributes.
- **Find references** (`Shift+F12`) for components, functions and methods. A usage count
  (`2 usages`) shows above each declaration. Click it to see the usages.
- **Outline** (`Cmd+Shift+O`) with the namespace, the components, the pages and the classes.
- **Twig comments.** `Cmd+/` adds `{# ... #}` in markup.

On Windows and Linux, use `Ctrl` instead of `Cmd`.

## Requirements

Keep the built-in PHP extension (`vscode.php`) enabled. The extension uses its grammar to
highlight the PHP code.

The extension finds your project from the nearest `composer.json` file. It reads the Composer
autoload files to find your classes, so run `composer install` first.

## Settings

| Setting                | Default | Description                                                                                  |
|------------------------|---------|----------------------------------------------------------------------------------------------|
| `phem.phemc.sourceDir` | `src`   | The directory of your `.phem` files, relative to the project root. Only these files get diagnostics. |
| `phem.phemc.path`      | empty   | The path of a native `phemc` binary. When empty, the extension uses its bundled server.     |

The server restarts when you change a setting. To hide the usage counts, set
`"editor.codeLens": false`.

## Troubleshooting

- **No PHP highlighting:** enable the built-in PHP extension.
- **No diagnostics for a file:** make sure that the file is in `phem.phemc.sourceDir`.
- **Something looks wrong:** open the **Output** view and select **Phem** to see the server
  messages. Run **Phem: Restart Language Server** to start the server again.

PHP extensions such as Intelephense do not read `.phem` files. The Phem server gives the PHP,
Twig and HTML support in these files.

## Known limits

- The members of the PHP built-in classes (`DateTime`, `ArrayObject`, ...) do not show.
- Generic types are not read. For example, `@extends ServiceEntityRepository<Scientist>` does not
  make `find()` return a `Scientist`. Add a `@return` or `@var` tag instead.
- Find references searches only `.phem` files, not `.php` files.

## Links

- [Phem documentation](https://github.com/phemphp/phem/tree/main/docs)
- [Report an issue](https://github.com/phemphp/phem/issues)
- [Build the extension from source](https://github.com/phemphp/phem/blob/main/editors/vscode/DEVELOPMENT.md)
