# Develop the VS Code extension

This document is for work on the extension. For its features, see [README.md](README.md).

## Install

Build and install the package (you need Docker and the `code` command). Run the command in the
repository root:

```sh
make vscode-install    # builds editors/vscode/phem-<version>.vsix and installs it
```

The command installs the extension in the Default profile of VS Code. To install it in a different
profile, set `VSCODE_PROFILE`:

```sh
make vscode-install VSCODE_PROFILE=Personal
```

Then run **Developer: Reload Window** in VS Code. A running server keeps its old build until it
starts again: after a change in the server only, **Phem: Restart Language Server** is
enough. To only build the package, run `make vscode`.
`make vscode` builds the server (`make lsp`, the WebAssembly build of `phemc-lsp`), copies it to
`editors/vscode/server/`, and packages the extension. It does not install the package. If you
install it yourself, add `--force` to replace an installed version:

```sh
code --install-extension editors/vscode/phem-0.5.0.vsix --force
```

Or, in VS Code, open the Extensions view, select **...** > **Install from VSIX...**, and select the file.

## Develop

The extension is a thin client: `src/extension.js` starts the server with
`vscode-languageclient`, and colors the Twig delimiters. The server is in
`compiler/crates/phemc-lsp/`. Its tests run with `make test`. The PhpStorm plugin
(`editors/phpstorm/`) uses the same server.

esbuild bundles `src/extension.js` and its dependencies into one file, `dist/extension.js`
(`npm run vscode:prepublish`). The package has only this file, not `node_modules/`.

To run the extension from the sources, build the server, install the dependencies and bundle
the extension. Then open `editors/vscode/` in VS Code and push `F5`. Bundle again after a change
in `src/`.

```sh
make lsp
mkdir -p editors/vscode/server && cp editors/server/phem-lsp.cjs editors/server/phem-lsp.wasm editors/vscode/server/
(cd editors/vscode && npm install && npm run vscode:prepublish)
```

To see the scopes of a token, run **Developer: Inspect Editor Tokens and Scopes**.
