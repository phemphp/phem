# Phem for Visual Studio Code

Language support for Phem `.phem` files. A `.phem` file is a PHP file with markup literals, the
same way a `.jsx` file is JavaScript with JSX.

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

The extension contains the Phem language server, compiled to WebAssembly. It runs with the Node
of VS Code. You do not need to install `phemc`, PHP, or Rust.

## Features at a glance

| Feature             | What you get                                                                      | How to use it                          |
|---------------------|-----------------------------------------------------------------------------------|----------------------------------------|
| Syntax highlighting | PHP, markup, Twig, and the JavaScript and CSS of `<script>` and `<style>`.         | Open a `.phem` file.                   |
| Diagnostics         | The errors and warnings of the compiler, as you type. Unsaved changes too.         | Problems view (`Cmd+Shift+M`)          |
| Completion          | Components, props, slots, HTML, Twig, PHP members, and imports.                    | Type, or `Ctrl+Space`                  |
| Auto import         | A `use` statement when you accept a component, a class, or a function.             | Accept a completion item.              |
| Go to definition    | Components, slots, props, PHP symbols, Twig variables, handlers, and routes.       | `F12` or `Cmd`+click                   |
| Hover               | Signatures, props, and the docs of Twig, HTML elements, and HTML attributes.       | Move the pointer on a name.            |
| Find references     | The tags of a component, and the calls of a function or a method.                  | `Shift+F12`, `Shift+Alt+F12`           |
| Usage counts        | `2 usages` above each component, function, and method.                             | Click the count.                       |
| Outline             | The namespace, components, pages, and classes of the file.                         | Outline view, `Cmd+Shift+O`            |
| Twig comments       | `{# ... #}` in markup, because a PHP comment is not valid there.                   | `Cmd+/` or `Ctrl+/`                    |

On Windows and Linux, use `Ctrl` for `Cmd`.

## Syntax highlighting

The extension highlights:

- **PHP code.** It uses the grammar of the built-in PHP extension (`vscode.php`). Keep that
  extension enabled.
- **Markup literals:** HTML elements, components (`<MyButton />`), named slots
  (`<Card:footer>`), fragments (`<>...</>`), attributes, and HTML entities.
- **Twig in markup:** `{{ expr }}`, `{% tag %}`, `{# comment #}`, filters, tests, hashes
  (`style={{ {width: 90} }}`), spreads (`{{ ...props }}`), and markup in Twig expressions
  (`{{ ok ? <A /> : <B /> }}`).
- **JavaScript** in `<script>` and **CSS** in `<style>`.

A markup literal starts where a PHP expression starts: after `return`, `(`, `=`, `,`, `=>`, `?`,
`:`, `??`, `[`, or `echo`, or at the start of a line. Thus `$a < $b` stays a comparison.

## Diagnostics

The server shows the errors and the warnings of `phemc` as you type. It checks all the `.phem`
files of the source directory, because a file can use the components of other files. An open
document replaces its file on disk, also when you did not save it.

The server checks only the files in `phem.phemc.sourceDir` (default: `src`). See
[Settings](#settings).

## Completion

| Where you type                    | What it suggests                                                                                       |
|-----------------------------------|--------------------------------------------------------------------------------------------------------|
| `<`                               | The components of the project, the built-in `<Root>`, and the HTML elements.                           |
| `<Card ` (in a component tag)     | The props that are not set yet. The required props come first.                                        |
| `<Layout:`                        | The named slots of the component.                                                                      |
| `<a ` (in an HTML tag)            | The attributes of the element, global and `aria-*` attributes, Phem events, and Phem attributes.        |
| `type="` (an enumerated value)    | The values of the attribute (`type`, `target`, `rel`, `loading`, ...).                                  |
| `</`                              | The name of the open element.                                                                          |
| `->`, `?->`, `::`                 | The properties, methods, and constants of the class, with its parents, traits, and interfaces.        |
| `$`                               | The variables of the function.                                                                         |
| `{{ ` or `{% `                    | Twig variables, filters (after `\|`), tests (after `is`), functions, and tags.                          |
| `{{ user.`                        | Twig attributes: public properties, public methods, getters (`getFullName()` is `fullName`), and array keys. |
| `path('` or `url('`               | The route names of the pages.                                                                          |
| `use App\`                        | The next part of the namespace, and the symbols.                                                       |
| A name in PHP code                | The classes and namespaced functions of the project and of `vendor/`.                                  |

### Components

A component tag gets its required props, and a closing tag when the component has `$children`
or slots:

```php
// Type <Lay and accept "Layout". You get:
return <Layout title="">

</Layout>;
```

If the component is not in scope, the server adds the import (`use function App\Ui\Common\Layout;`
or `use App\Ui\Common\Counter;`). It uses the rules of `phemc`: a `use function` import, then a
`use` import, then the current namespace.

Only a function or a class with `#[Component]` is a component. Other functions are plain PHP
functions, and completion does not suggest them as tags.

### HTML and Phem attributes

In an HTML tag, completion also gives:

- the Phem events: `onClick`, `onSubmit`, ...;
- the Phem attributes: `phem:loading`, `phem:progress`, `phem:debounce`, `phem:throttle`,
  `phem:bind`, and `phem:live`;
- `className` and `key`.

A void element gets ` />` (`<img />`). Other elements get a closing tag. In
`phem:debounce={{ `, completion also suggests `'blur'`.

### Twig

In `{{ }}` and `{% %}`, completion uses the Twig rules:

- **Variables:** the PHP variables of the scope, the properties and methods of a
  `#[Component]` class, and the `{% for %}` and `{% set %}` variables (with `loop`).
- **Attributes after a dot:** the public properties, the public methods, and the getters as
  names. An array literal (`$user = ['name' => ...]`) gives its keys.
- **Filters, tests, and functions:** the ones of Twig and of Symfony (`path`, `asset`,
  `form_widget`, `is_granted`, ...).
- **Tags:** the end tags of the open blocks come first (`endfor`, `endif`).

### PHP types

The type of an expression comes from:

- the parameter types, the property types, and the return types;
- `new Foo()` and `foreach`;
- the docblocks: `@var`, `@param`, `@return`, `@property`, `@method`, `list<Foo>`, and `Foo[]`.

Private and protected members show only where PHP allows them.

### Classes, functions, and imports

In PHP code, completion suggests the classes and the namespaced functions of the project and of
`vendor/`. When you accept one, the server adds the `use` statement. A name with a leading `\`
(`\Phem\Html`) completes part by part, with no import.

In `use`, `use function`, and `use const` statements (and in groups, `use App\{...}`),
completion suggests the next part of the namespace and the symbols.

## Go to definition and hover

**Go to Definition** (`F12` or `Cmd`+click) and the hover work on:

| You point at                                 | It goes to                                     |
|----------------------------------------------|------------------------------------------------|
| A component tag (`<Layout>`, `</Layout>`)    | The component                                  |
| A named slot (`<Layout:footer>`) or a prop   | The slot or the prop of the component          |
| A class, function, member, or PHP variable   | Its declaration                                |
| A Twig variable (`{{ count }}`)              | The PHP variable, parameter, property, or method |
| A Twig attribute (`scientist.name`)          | The property or the getter                     |
| A handler (`onClick={{ like }}`)             | The method                                     |
| A route name (`path('blog_show')`)           | The page                                       |

The hover also shows the description of the Twig filters, functions, tests, and tags, and of
the HTML elements and attributes.

## Find references and usage counts

**Go to References** (`Shift+F12`) and **Find All References** (`Shift+Alt+F12`) show where the
project uses a declaration:

| Declaration | Its references                                                                   |
|-------------|----------------------------------------------------------------------------------|
| Component   | Its tags (`<Card />`). The closing tags, the slot tags, and the imports do not count. |
| Function    | Its calls                                                                        |
| Method      | Its calls (`$this->increment()`) and the Twig handlers (`onClick={{ increment }}`) |

`Cmd`+click on the name of a declaration also shows its references.

Above each component, function, and method, a usage count (`2 usages`) shows the number of
usages. Click it to see the usages in a peek view. Then click a usage to go to it. A page,
`render()`, and the magic methods (`__construct()`) have no usage count. To hide the counts, set
`"editor.codeLens": false`.

The server searches the `.phem` files of the project. It does not search the `.php` files.

## Outline

The **Outline** view, the breadcrumbs, and **Go to Symbol in Editor** (`Cmd+Shift+O`) show:

- the namespace;
- the components, with their props and slots;
- the pages, with their paths;
- the classes, with their members.

## How the server finds your code

- The project is the nearest directory with a `composer.json` file.
- The server reads the `.phem` files of the project and the Composer autoload maps
  (`vendor/composer/autoload_*.php`). When `vendor/` is not there, it reads the `autoload`
  section of `composer.json`.
- The index updates when you change a `.phem` file, add or remove a `.php` file, or run
  Composer.
- The server reads a class file when it needs the members of the class. It reads the file again
  when the file changes.

## Settings

| Setting                | Default | Description                                                                                                         |
|------------------------|---------|---------------------------------------------------------------------------------------------------------------------|
| `phem.phemc.sourceDir` | `src`   | The source directory of the `.phem` files, relative to the project root. The server checks only the files in it.    |
| `phem.phemc.path`      | empty   | The path of a native `phemc` binary. When you set it, the extension runs `phemc lsp` as the server. Empty: the bundled server. |

```json
{
    "phem.phemc.sourceDir": "src"
}
```

The extension restarts the server when you change a setting. In an untrusted workspace, VS Code
ignores the `phem.phemc.path` value of the workspace settings.

## Commands

| Command                            | What it does                                                         |
|------------------------------------|----------------------------------------------------------------------|
| **Phem: Restart Language Server**  | Starts the server again. Use it after you install a new server build. |

## Troubleshooting

- **No PHP highlighting:** enable the built-in PHP extension (`vscode.php`).
- **No diagnostics for a file:** make sure that the file is in `phem.phemc.sourceDir`.
- **Server messages:** open the **Output** view and select **Phem**.
- **Token scopes:** run **Developer: Inspect Editor Tokens and Scopes**.

The PHP extensions (Intelephense, PHP Tools) do not read `.phem` files. The Phem server gives the
PHP, Twig, and HTML support in these files.

## Limits

- The server has no stubs for the PHP built-in classes (`DateTime`, `ArrayObject`, ...). Their
  members do not show.
- The server does not read the generic templates of a class. For example,
  `@extends ServiceEntityRepository<Scientist>` does not make `find()` return a `Scientist`. Add a
  `@return` or a `@var` tag for these cases.
- A Twig attribute resolves only from a known type. An array without an array literal
  (`array $items`) gives no keys.

## Build from source

To build, install, or work on the extension, see
[DEVELOPMENT.md](https://github.com/phemphp/phem/blob/main/editors/vscode/DEVELOPMENT.md).
