# RHP for Visual Studio Code

Syntax highlighting and completion for rephp `.rhp` files. A `.rhp` file is a PHP file
that contains markup literals, the same way a `.jsx` file contains JSX.

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

The extension reads the `.rhp` files of the project and the Composer autoload maps
(`vendor/composer/autoload_*.php`, or the `autoload` section of `composer.json` when `vendor/`
is not there). A project is the nearest directory with a `composer.json` file.

- **Component tags**: after `<`, it suggests the components of the project (function components
  and `#[Component]` classes). The tag gets the required props, and a closing tag when the
  component has `$children` or slots. If the component is not in scope, the extension adds the
  import: `use function App\Components\Layout;` or `use App\Components\Counter;`. It uses the
  rules of rhpc: a `use function` import, then a `use` import, then the current namespace.
- **Props and slots**: in a component tag, it suggests the props that are not set yet (required
  props first). After `<Layout:`, it suggests the named slots. In an HTML tag, it suggests the
  rephp events (`onClick`, `onSubmit`, ...), `className`, and `key`.
- **Closing tags**: after `</`, it suggests the name of the open element.
- **Imports**: in `use`, `use function`, and `use const` statements (and in groups,
  `use App\{...}`), it suggests the next part of the namespace and the symbols.
- **Classes and functions**: in PHP code, it suggests the classes and the namespaced functions
  of the project and of `vendor/`, and adds the `use` statement when you accept one. A name with a
  leading `\` (`\Rephp\Html`) completes part by part, with no import.

The index updates when you save a `.rhp` file, add or remove a `.php` file, or run Composer.

## Install

Build and install the package (you need Docker and the `code` command):

```sh
make vscode-install    # builds editors/vscode/rhp-0.2.0.vsix and installs it
```

Then run **Developer: Reload Window** in VS Code. To only build the package, run `make vscode`.
`make vscode` does not install the package. If you install it yourself, add `--force` to replace
an installed version:

```sh
code --install-extension editors/vscode/rhp-0.2.0.vsix --force
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
