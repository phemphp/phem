# Phem for Visual Studio Code

Syntax highlighting, completion, go to definition, hover, outline, and compiler diagnostics for
Phem `.phpm` files. A `.phpm` file is a PHP file that contains markup literals, the same way a
`.jsx` file contains JSX.

The PHP extensions (Intelephense, PHP Tools) do not read `.phpm` files, so this extension has its
own small PHP, Twig, and HTML support.

The extension highlights:

- PHP code. It uses the grammar of the built-in PHP extension (`vscode.php`), so keep that
  extension enabled.
- Markup literals: HTML elements, components (`<MyButton />`), named slots (`<Card:footer>`),
  fragments (`<>...</>`), attributes, and HTML entities.
- Twig in markup: `{{ expr }}`, `{% tag %}`, `{# comment #}`, filters, tests, hashes
  (`style={{ {width: 90} }}`), spreads (`{{ ...props }}`), and markup in Twig expressions
  (`{{ ok ? <A /> : <B /> }}`).
- JavaScript in `<script>` and CSS in `<style>`.

In markup, **Toggle Line Comment** (`Cmd+/` or `Ctrl+/`) writes a Twig comment (`{# ... #}`),
because a PHP comment is not valid there.

A markup literal starts where a PHP expression starts: after `return`, `(`, `=`, `,`, `=>`, `?`,
`:`, `??`, `[`, or `echo`, or at the start of a line. Thus `$a < $b` stays a comparison.

## Completion

The extension reads the `.phpm` files of the project and the Composer autoload maps
(`vendor/composer/autoload_*.php`, or the `autoload` section of `composer.json` when `vendor/`
is not there). A project is the nearest directory with a `composer.json` file.

- **Component tags**: after `<`, it suggests the components of the project (`#[Component]`
  functions and classes). A function without `#[Component]` is a plain PHP function. The tag gets the required props, and a closing tag when the
  component has `$children` or slots. If the component is not in scope, the extension adds the
  import: `use function App\Components\Layout;` or `use App\Components\Counter;`. It uses the
  rules of phemc: a `use function` import, then a `use` import, then the current namespace.
  It also suggests the built-in `<Root>` (the root element of a page, in the document), and
  adds `use Phem\Root;`.
- **HTML elements**: after `<`, it also suggests the HTML elements. A void element gets ` />`
  (`<img />`); the others get a closing tag.
- **Props and slots**: in a component tag, it suggests the props that are not set yet (required
  props first). After `<Layout:`, it suggests the named slots.
- **HTML attributes**: in an HTML tag, it suggests the attributes of the element, the global
  attributes, the `aria-*` attributes, the Phem events (`onClick`, `onSubmit`, ...), the Phem
  attributes (`phem:loading`, `phem:progress`, `phem:debounce`, `phem:throttle`, `phem:bind`,
  `phem:live`), `className`, and `key`. In the value of an enumerated attribute (`type="`, `target="`, `rel="`,
  `loading="`, ...), it suggests the values. In `phem:debounce={{ `, it also suggests `'blur'`.
- **Closing tags**: after `</`, it suggests the name of the open element.
- **Members**: after `->`, `?->`, and `::`, it suggests the properties, the methods, and the
  constants of the class, with its parents, traits, and interfaces. Private and protected
  members show only where PHP allows them. The type of an expression comes from the parameter
  types, `new Foo()`, the property types, the return types, `foreach`, and the docblocks
  (`@var`, `@param`, `@return`, `@property`, `@method`, `list<Foo>`, `Foo[]`).
- **Variables**: after `$`, it suggests the variables of the function.
- **Twig**: in `{{ }}` and `{% %}`, it suggests:
  - the variables: the PHP variables of the scope, the properties and the methods of the class
    (in a `#[Component]` class), and the `{% for %}` and `{% set %}` variables (with `loop`);
  - the attributes after a dot, with the Twig rules: `scientist.` gives the public properties,
    the public methods, and the getters as names (`getFullName()` is `fullName`). An array
    literal (`$user = ['name' => ...]`) gives its keys;
  - the filters after `|`, the tests after `is`, and the functions of Twig and Symfony
    (`path`, `asset`, `form_widget`, `is_granted`, ...);
  - the tags after `{%`, with the end tags of the open blocks first (`endfor`, `endif`);
  - the route names of the pages in `path('` and `url('`.
- **Imports**: in `use`, `use function`, and `use const` statements (and in groups,
  `use App\{...}`), it suggests the next part of the namespace and the symbols.
- **Classes and functions**: in PHP code, it suggests the classes and the namespaced functions
  of the project and of `vendor/`, and adds the `use` statement when you accept one. A name with a
  leading `\` (`\Phem\Html`) completes part by part, with no import.

The index updates when you save a `.phpm` file, add or remove a `.php` file, or run Composer.
The extension reads a class file when it needs the members of the class, and reads it again when
the file changes.

## Go to definition and hover

**Go to Definition** (`F12` or `Cmd`+click) and the hover work on:

- component tags (`<Layout>`, `</Layout>`), named slots (`<Layout:footer>`), and props;
- class names, function names, members (`$this->repo->all()`, `Status::Active`), and variables
  in PHP code;
- Twig variables (they go to the PHP variable, parameter, property, or method), attributes
  (`scientist.name` goes to the property or the getter), and handlers (`onClick={{ like }}`);
- route names in `path('...')`: they go to the page.

The hover also shows the description of the Twig filters, functions, tests, and tags, and of the
HTML elements and attributes.

## Outline

The **Outline** view, the breadcrumbs, and **Go to Symbol in Editor** (`Cmd+Shift+O`) show the
namespace, the components (with their props and slots), the pages (with their paths), and the
classes with their members.

## Diagnostics

The extension can show the errors and the warnings of phemc in the editor. Set the path of the
`phemc` binary (it must run on the host, not in Docker):

```json
{
    "phem.phemc.path": "/path/to/phemc",
    "phem.phemc.sourceDir": "src"
}
```

When you open or save a `.phpm` file, the extension runs `phemc check <sourceDir> --format json`
in the project root and shows the diagnostics of all the files. `phem.phemc.sourceDir` is relative
to the project root (default: `src`). With an empty `phem.phemc.path` (the default), there are no
diagnostics. In an untrusted workspace, VS Code ignores the `phem.phemc.path` value of the
workspace settings.

## Limits

- The extension has no stubs for the PHP built-in classes (`DateTime`, `ArrayObject`, ...), so
  their members do not show.
- It does not read the generic templates of a class (`@extends ServiceEntityRepository<Scientist>`
  does not make `find()` return a `Scientist`). Add a `@return` or a `@var` tag for these cases.
- A Twig attribute resolves only from a known type. An array without an array literal
  (`array $items`) gives no keys.

## Install

Build and install the package (you need Docker and the `code` command):

```sh
make vscode-install    # builds editors/vscode/phem-0.3.0.vsix and installs it
```

Then run **Developer: Reload Window** in VS Code. To only build the package, run `make vscode`.
`make vscode` does not install the package. If you install it yourself, add `--force` to replace
an installed version:

```sh
code --install-extension editors/vscode/phem-0.3.0.vsix --force
```

Or, in VS Code, open the Extensions view, select **...** > **Install from VSIX...**, and select the file.

## Develop

Open `editors/vscode/` in VS Code and push `F5`. A new window starts with the extension loaded.
To see the scopes of a token, run **Developer: Inspect Editor Tokens and Scopes**.

The completion code is in `src/`. It is plain JavaScript with no dependencies and no build step.
`src/extension.js` connects it to VS Code. The other files do not use the `vscode` API, so the
tests run with Node:

```sh
make vscode-test
```
