# RHP for Visual Studio Code

Syntax highlighting for rephp `.rhp` files. A `.rhp` file is a PHP file
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

## Install

Build the package (you need only Docker):

```sh
make vscode        # writes editors/vscode/rhp-0.1.0.vsix
```

Then install it:

```sh
code --install-extension editors/vscode/rhp-0.1.0.vsix
```

Or, in VS Code, open the Extensions view, select **...** > **Install from VSIX...**, and select the file.

## Develop

Open `editors/vscode/` in VS Code and push `F5`. A new window starts with the extension loaded.
To see the scopes of a token, run **Developer: Inspect Editor Tokens and Scopes**.
