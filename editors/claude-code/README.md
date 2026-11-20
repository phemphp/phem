# Phem for Claude Code

A Claude Code plugin for Phem apps. It gives Claude the data to write correct `.phem` code, and
checks each file that Claude writes:

- **Language server**: after each edit or write of a `.phem` file, Claude Code gets the errors
  and warnings of `phemc` in real time. It also gets the `LSP` tool for go to definition, find
  references, hover, and outline.
- **MCP server**: the tools of `phemc mcp` (`phem_search`, `phem_doc`, `phem_components`,
  `phem_component`, `phem_examples`, `phem_check`): the docs, the components and the examples of
  the installed versions.
- **Skills**: `phem` loads the workflow, the cheatsheet and the list of the docs. `phem-ui` loads
  the components of the installed packages and the workflow to start from an example.
- **Check hook**: after each write or edit of a `.phem` file, the hook runs `phemc check` on the
  file. An error goes back to Claude, which corrects the file.

## Install

```sh
claude plugin marketplace add phemphp/phem
claude plugin install phem@phem
```

In a running session, run `/reload-plugins` after the install. The plugin needs PHP on the host,
and `phem/phem` in the project (`composer require phem/phem`).

## The servers

`bin/phem-lsp` starts the first language server that it finds in the project directory:

1. `vendor/bin/phemc lsp` of the application, with PHP. The server has the same version as the
   compiler and the runtime of the application.
2. `editors/server/phem-lsp.cjs` next to the plugin (`make lsp`), with Node, when you load the
   plugin from a clone of the Phem repository. The launcher never runs this file from the
   project, so another project cannot give its own server.
3. `phemc lsp`, with the `phemc` on the `PATH`.

`bin/phem-mcp` starts `phemc mcp` in the project directory (`CLAUDE_PROJECT_DIR`), with the first
`phemc` that it finds: `PHEMC_BIN`, `vendor/bin/phemc` of the application, then the `phemc` on the
`PATH`. To use a `phemc` that you build from a clone of the repository, set `PHEMC_BIN` to it.

The plugin runs the `vendor/bin/phemc` of the project. Use it only in projects that you trust.

## The check hook

`bin/phem-check-hook.php` runs after each `Write`, `Edit` and `MultiEdit` of a `.phem` file. It
runs `phemc check <file>` in the project of the file (the nearest directory with a
`composer.json`), with the same `phemc` as `bin/phem-mcp`:

- **An error**: the hook prints the diagnostics and exits with code 2. Claude reads them and
  corrects the file.
- **Warnings only**: Claude gets them as context, and goes on.
- **No `phemc`, or a project that `phemc` cannot read** (for example a wrong package root): the
  hook does nothing, because the file cannot fix it. The language server shows the error.

## Use

- The language server starts the first time Claude edits a `.phem` file.
- After each edit, the conversation shows a summary, for example
  `Found 1 new diagnostic issues in 1 files`. Press `Ctrl+O` to read the diagnostics.
- Run `/mcp` to see the Phem MCP server and its tools.
- If a server does not start, open `/plugin` and read the **Errors** tab.

## Source directory

The language server, the MCP server and the hook check the `src/` directory of the project. A
project is the nearest directory with a `composer.json` file. To use another directory, change
`sourceDir` in `initializationOptions` of `.lsp.json`, and add `--src <dir>` to the `phemc mcp`
command of `bin/phem-mcp`.

## Work on the plugin

Run the commands from the root of the repository. `make lsp` builds the language server that the
plugin uses in the repository. `make test-claude-code` runs the tests of the hook, of the MCP
launcher and of the commands of the skills (`tests/run.php`).

```sh
make lsp
make test-claude-code
claude plugin validate editors/claude-code
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
