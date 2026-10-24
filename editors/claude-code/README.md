# Phem for Claude Code

A Claude Code plugin that runs the Phem language server for `.phpm` files. After each edit or
write of a `.phpm` file, Claude Code gets the errors and warnings of `phemc` in real time. It also
gets the `LSP` tool for go to definition, hover, and outline.

The plugin contains the server, compiled to WebAssembly (the same server as the VS Code extension
and the PhpStorm plugin), and runs it with Node. You need Node 20 or later on the `PATH`. You do
not need to install `phemc` or Rust.

## Build and load

Run the commands from the root of the repository.

```sh
make claude                                # copy the server into editors/claude-code/server/
claude --plugin-dir editors/claude-code    # start a session with the plugin
```

After `make claude` in a running session, run `/reload-plugins`.

To load the plugin in every session, install it from the marketplace of the repository
(`.claude-plugin/marketplace.json`):

```sh
make claude
claude plugin marketplace add "$PWD"
claude plugin install phem@phem
```

After a new `make claude`, run `claude plugin update phem@phem`, then `/reload-plugins`.

## Use

- The server starts the first time Claude edits a `.phpm` file.
- After each edit, the conversation shows a summary, for example
  `Found 1 new diagnostic issues in 1 files`. Press `Ctrl+O` to read the diagnostics.
- If the server does not start, open `/plugin` and read the **Errors** tab.

## Source directory

The server checks the `src/` directory of the project. A project is the nearest directory with a
`composer.json` file. To use another directory, change `sourceDir` in
`initializationOptions` of `.lsp.json`.
