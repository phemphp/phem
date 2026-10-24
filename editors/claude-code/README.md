# Phem for Claude Code

A Claude Code plugin that runs the Phem language server for `.phpm` files. After each edit or
write of a `.phpm` file, Claude Code gets the errors and warnings of `phemc` in real time. It also
gets the `LSP` tool for go to definition, hover, and outline.

## Install

```sh
claude plugin marketplace add phemphp/phem
claude plugin install phem@phem
```

In a running session, run `/reload-plugins` after the install.

## The server

`bin/phem-lsp` starts the first server that it finds in the project directory:

1. `vendor/bin/phemc lsp` of the application, with PHP. The server has the same version as the
   compiler and the runtime of the application. You need PHP on the host.
2. `editors/server/phem-lsp.cjs` next to the plugin (`make lsp`), with Node, when you load the
   plugin from a clone of the Phem repository. The launcher never runs this file from the
   project, so another project cannot give its own server.
3. `phemc lsp`, with the `phemc` on the `PATH`.

The plugin runs the `vendor/bin/phemc` of the project. Use it only in projects that you trust.

## Use

- The server starts the first time Claude edits a `.phpm` file.
- After each edit, the conversation shows a summary, for example
  `Found 1 new diagnostic issues in 1 files`. Press `Ctrl+O` to read the diagnostics.
- If the server does not start, open `/plugin` and read the **Errors** tab.

## Source directory

The server checks the `src/` directory of the project. A project is the nearest directory with a
`composer.json` file. To use another directory, change `sourceDir` in
`initializationOptions` of `.lsp.json`.

## Work on the plugin

Run the commands from the root of the repository. `make lsp` builds the server that the plugin
uses in the repository.

```sh
make lsp
claude --plugin-dir editors/claude-code    # start a session with the plugin
```

After a new `make lsp` in a running session, run `/reload-plugins`.

To load the plugin of the repository in every session, install it from the marketplace of the
repository (`.claude-plugin/marketplace.json`):

```sh
claude plugin marketplace add "$PWD"
claude plugin install phem@phem
```

Claude Code reads this plugin from `editors/claude-code/`. After a change to the plugin files or a
new `make lsp`, run `/reload-plugins`.
