# Phem for PhpStorm

This plugin adds support for Phem `.phpm` files to PhpStorm. A `.phpm` file is a PHP file that
contains markup literals and Twig.

The plugin gives:

- Syntax highlighting. The plugin uses the TextMate grammar of the VS Code extension
  (`editors/vscode/syntaxes/phpm.tmLanguage.json`).
- Comments and brace matching. They come from the language configuration of the VS Code
  extension. In markup, **Comment with Line Comment** writes a Twig comment (`{# ... #}`).
- A file icon for `.phpm` files.
- Language features from the Phem language server:
  - diagnostics, as you type;
  - completion, with snippets and `use` imports;
  - go to definition;
  - find usages of the components, the functions, and the methods, and a usage count above
    each declaration (see [Usages](#usages));
  - hover (quick documentation);
  - the structure view and the breadcrumbs (document symbols).

## Usages

**Find Usages** (`Alt+F7`) and **Show Usages** (`Cmd+Alt+F7`) on a component, a function, or a
method show where the project uses it:

- a component: its tags (`<Card />`). The closing tags, the slot tags, and the imports do not
  count;
- a function: its calls;
- a method: its calls (`$this->increment()`) and the Twig handlers (`onClick={{ increment }}`).

Above each component, function, and method of a `.phpm` file, a usage count (`2 usages`) shows
the number of usages. Click it to see the usages in a popup. Click a usage to go to its file and
position. A page, `render()`, and the magic methods (`__construct()`) have no usage count.

The server finds the usages in the `.phpm` files of the project. It does not search the `.php`
files.

## Requirements

- PhpStorm 2026.2 or later.
- One of these:
  - Node.js. The plugin runs the bundled server (`server/phem-lsp.cjs` and `server/phem-lsp.wasm`)
    with Node.js.
  - A native `phemc` binary. Set its path in the settings. The plugin then runs `phemc lsp`.

## Build

You need Docker (for the server), Java 21, and Gradle 9. Run the command in the repository root:

```sh
make phpstorm
```

`make phpstorm` builds the server (`make lsp`), then runs `gradle buildPlugin` in
`editors/phpstorm`. The build writes the plugin to
`editors/phpstorm/build/distributions/phem-phpstorm-<version>.zip`.

The build copies these files into the plugin:

- From `editors/vscode`: `package.json`, `language-configuration.json`,
  `markup-language-configuration.json`, and `syntaxes/phpm.tmLanguage.json`. They become the
  TextMate bundle in `bundles/phpm`.
- From `editors/vscode/icons/phpm.svg`: the file icon and the plugin icon.
- From `editors/server`: `phem-lsp.cjs` and `phem-lsp.wasm`. They go into `server`. If the files
  are missing, the build shows a warning and continues. The plugin then needs a `phemc` path.

The build uses the local PhpStorm in `/Applications/PhpStorm.app`. It does not download the IDE.
To use a different IDE, set a Gradle property (run the commands in `editors/phpstorm`):

```sh
# Use a different local PhpStorm.
gradle buildPlugin -PphpstormPath=/path/to/PhpStorm.app

# Download a PhpStorm version and build against it.
gradle buildPlugin -PphpstormVersion=2026.2
```

To check the plugin against the IDE, run the plugin verifier:

```sh
gradle verifyPlugin
```

## Install

On macOS, build the plugin and install it in the newest PhpStorm configuration:

```sh
make phpstorm-install
```

Then restart PhpStorm. To install it in a different configuration, set `PHPSTORM_PLUGINS` to its
`plugins` directory.

Or install the zip file in PhpStorm:

1. Open **Settings > Plugins**.
2. Click the gear icon, then click **Install Plugin from Disk...**.
3. Select `editors/phpstorm/build/distributions/phem-phpstorm-<version>.zip`.
4. Restart PhpStorm if it asks you to.

## Settings

Open **Settings > Languages & Frameworks > Phem**.

| Setting          | Scope       | Default | Description                                                                                                         |
|------------------|-------------|---------|---------------------------------------------------------------------------------------------------------------------|
| phemc path       | Application | Empty   | The path of a `phemc` binary. When you set it, the plugin runs `phemc lsp`. When it is empty, the plugin runs the bundled server. |
| Node.js path     | Application | Empty   | The `node` executable for the bundled server. When it is empty, the plugin uses `node` from `PATH`.                 |
| Source directory | Project     | `src`   | The directory of the `.phpm` files, relative to the project root (the nearest directory with `composer.json`).      |

The plugin keeps the paths of the executables in the application settings, not in the project.
Thus a project cannot make PhpStorm run an executable.

When you change a setting, the plugin restarts the server.

## Language server

The plugin uses the LSP API of the IntelliJ Platform (`com.intellij.platform.lsp`). PhpStorm 2026.2
has this API, and it supports all the features of the Phem server: diagnostics, completion
(with snippets, `additionalTextEdits`, `completionItem/resolve`, and trigger characters), go to
definition, references, code lenses, hover, and document symbols. Thus the plugin does not need LSP4IJ or another plugin.

The plugin starts one server for each project, when you open the first `.phpm` file. The plugin
sends the language id `phpm` and these `initializationOptions`:

```json
{ "sourceDir": "src" }
```

The plugin starts the server in one of two modes. Both modes use stdio.

- Bundled: `node --no-warnings <plugin>/server/phem-lsp.cjs`.
- Native: `<phemc> lsp`.

The working directory is the project directory.

To see the status of the server, click the language services widget (`{}`) in the status bar.
To see the messages between the IDE and the server, add `#com.intellij.platform.lsp:trace` in
**Help > Diagnostic Tools > Debug Log Settings**.

## Limits

- The file type of `.phpm` files is the TextMate file type. PhpStorm does not parse `.phpm`
  files as PHP. The PHP features of PhpStorm (inspections, refactorings) do not work in `.phpm`
  files. Find usages comes from the Phem server.
- The grammar includes `source.php`, `source.js`, and `source.css`. They come from the TextMate
  bundles of PhpStorm (PHP, JavaScript, and CSS). If you disable these bundles in
  **Settings > Editor > TextMate Bundles**, the PHP, JavaScript, and CSS code has no highlighting.
- If you assigned the `*.phpm` pattern to a different file type, remove it in
  **Settings > Editor > File Types**. Otherwise the TextMate grammar does not apply.
- The IDE sends `workspace/didChangeWatchedFiles` only for the patterns that the server registers
  (`client/registerCapability`). The IDE sends events only for files in the project.

If the plugin cannot load the TextMate bundle, add it manually:

1. Open **Settings > Editor > TextMate Bundles**.
2. Click **+** and select the `editors/vscode` directory.
