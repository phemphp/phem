# Connect a coding agent to the MCP server

`phemc mcp` is the Model Context Protocol (MCP) server of Phem. A coding agent (Claude Code, Cursor, VS Code, Codex or any MCP client) calls its tools to read the docs of the installed version of Phem, to find the components and the examples of the installed packages, and to check its `.phem` code with the compiler. This page shows you how to add the server to a project.

The server is a command of the `phemc` binary, so each app that installs `phem/phem` has it as `vendor/bin/phemc mcp`. It runs on the computer of the developer, on stdin and stdout, and it needs no network and no API key. For the workflow that the agent follows, see [Use Phem with coding agents](ai-agents.md).

## Know the tools

The server gives six tools. Each tool gives the same text as a `phemc` command:

| Tool | What the agent gets | Same as |
|---|---|---|
| `phem_search` | The best docs, components and examples for a task | `phemc search "<task>"` |
| `phem_doc` | A doc or a section, or the keys of all the sections | `phemc docs <key>` |
| `phem_components` | The components of the app and of the installed packages | `phemc components` |
| `phem_component` | The props, the context and the anatomy of one component | `phemc components <name>` |
| `phem_examples` | The examples of the packages, as complete files | `phemc examples <name>` |
| `phem_check` | The errors and the warnings of a file or of a draft | `phemc check <file>` |

When the agent connects, the server gives it the workflow in its `instructions`: search, read the best keys, start from an example, then check each file. The tools read the project again for each call, and they write nothing. For the arguments of each tool, see [`phemc mcp`](compiler.md#phemc-mcp).

## Prepare the project

The server needs these items:

- **`phem/phem` in the project**: `composer require phem/phem` installs `vendor/bin/phemc`
- **PHP on the computer of the agent**: `vendor/bin/phemc` is a PHP script that starts the binary of your platform
- **The project root**: the server reads `composer.json`, `vendor/` and the source directory from its working directory. When the agent starts the server in another directory, give the project root with `--project <dir>`
- **The source directory**: give `--src <dir>` when the `.phem` files of the app aren't in `src/`. The path is relative to the project root

```sh
vendor/bin/phemc mcp                                  # in the project root
vendor/bin/phemc mcp --project /path/to/app --src app # from another directory
```

Commit the config file of the agent, so all the developers of the project get the server.

## Add the server to the agent

### Claude Code

Run this command from the project root:

```sh
claude mcp add --scope project phem -- vendor/bin/phemc mcp
```

The command writes `.mcp.json`:

```json
{
    "mcpServers": {
        "phem": {
            "command": "vendor/bin/phemc",
            "args": ["mcp"]
        }
    }
}
```

Claude Code starts the server in the directory where you start `claude`, so start it in the project root. Run `/mcp` to see the server and its tools. Claude Code asks you to approve a server of `.mcp.json` the first time.

The [Phem plugin](compiler.md#use-claude-code) starts the same server in the project root, so you don't need `.mcp.json` with the plugin. The plugin also adds the skills, the check hook and the language server.

### Cursor

Add the server to `.cursor/mcp.json` in the project root. `${workspaceFolder}` is the project root:

```json
{
    "mcpServers": {
        "phem": {
            "command": "${workspaceFolder}/vendor/bin/phemc",
            "args": ["mcp", "--project", "${workspaceFolder}"]
        }
    }
}
```

Open **Cursor Settings › MCP** to see the server and its tools.

### VS Code

Add the server to `.vscode/mcp.json` in the project root. VS Code uses the `servers` key:

```json
{
    "servers": {
        "phem": {
            "type": "stdio",
            "command": "${workspaceFolder}/vendor/bin/phemc",
            "args": ["mcp", "--project", "${workspaceFolder}"]
        }
    }
}
```

Run **MCP: List Servers** from the Command Palette to start the server and to see its tools in the agent mode of the chat.

### Codex

Add the server to `.codex/config.toml` in the project root:

```toml
[mcp_servers.phem]
command = "vendor/bin/phemc"
args = ["mcp"]
```

Codex reads the config file of a project only when you trust the project. Start `codex` in the project root.

### Another agent

An agent that supports MCP servers on stdio takes a command and its arguments. Give absolute paths when you don't know the working directory of the server:

- **Command**: `/path/to/app/vendor/bin/phemc`
- **Arguments**: `mcp`, `--project`, `/path/to/app`, and `--src <dir>` when the source directory isn't `src`

### PHP in a container

When PHP runs only in a container, start the server in the container. Use `-T`, so Docker gives the messages on stdin and stdout with no terminal:

```json
{
    "mcpServers": {
        "phem": {
            "command": "docker",
            "args": ["compose", "exec", "-T", "php", "vendor/bin/phemc", "mcp"]
        }
    }
}
```

Replace `php` with the name of the service of your app. The paths that `phem_check` gives are relative to the project root, so they are the same in the container and on the computer.

## Check the server

Send an `initialize` message and a tool call to the server, from the project root:

```sh
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"phem_search","arguments":{"query":"confirmation dialog","limit":3}}}' \
  | vendor/bin/phemc mcp
```

The server gives one response on each line. With `phem/ui-base`, the first result of the search is an example:

```text
{"id":1,"jsonrpc":"2.0","result":{"capabilities":{"tools":{"listChanged":false}},"instructions":"Phem: `.phem` files are PHP with markup literals, …","protocolVersion":"2025-06-18","serverInfo":{"name":"phem","title":"Phem","version":"0.6.0"}}}
{"id":2,"jsonrpc":"2.0","result":{"content":[{"text":"# Search: confirmation dialog\n\n…1. `Phem\\Ui\\Base\\Examples\\DialogCloseConfirmationDemo` (example) …","type":"text"}],"isError":false}}
```

## Troubleshoot

- **A tool gives `source directory `src` does not exist`**: the server doesn't run in the project root. Give `--project <dir>`, or give `--src <dir>` when the source directory has another name
- **The agent shows no tools**: the agent can't start the command. Run the command of the config in a terminal, from the same directory. Check that `vendor/bin/phemc` exists (`composer install`) and that `php` is on the `PATH` of the agent
- **The components of a package are missing**: the server reads the packages that Composer installed. Run `composer install`, then call the tool again. The server needs no restart
- **The docs are of an old version**: the docs are in the binary. Run `composer update phem/phem`, then restart the server

The server runs the `vendor/bin/phemc` of the project. Add it only to projects that you trust.
