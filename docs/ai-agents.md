# Use Phem with coding agents

This page shows you how to give a coding agent (Claude Code, Codex, Cursor or any agent that can run a shell command) the correct data to write `.phem` code. The agent reads the docs of the installed version of Phem and the components of the installed packages, and it checks its code with the compiler.

## Know what the agent gets

The `phemc` commands give the agent four kinds of text. Each text has a stable key, so the agent can read it again later:

| Command | Text | Key |
|---|---|---|
| `phemc docs` | The docs of the installed version of Phem, in the `phemc` binary | `events`, `events#poll-the-server` |
| `phemc components` | The components of the app and of the installed packages, from their sources | `Acme\Ui\Dialog` |
| `phemc examples` | The examples that the packages ship | `Acme\Ui\Examples\DialogFormDemo` |
| `phemc docs <package>` | The guide that a package ships for agents | `acme/ui`, `acme/ui#naming` |

`phemc search` searches all of them. The same query always gives the same result, and the commands need no network and no API key.

The texts are small. The docs of Phem are about 170,000 tokens, and the agent needs its context for your code. The section map of the docs (`phemc docs --toc`) is about 7,600 tokens, the map of the components of `phem/ui-base` (`phemc components`) is about 1,800 tokens, and one component or one doc section is about 500 tokens.

## Follow the workflow

Tell the agent to use this workflow for each task that writes `.phem` code:

1. **Load the maps once.** Run `phemc docs --toc` and `phemc components`. The agent then knows the keys of the docs and the component families.
2. **Look up before you write.** Run `phemc search "<the task in a few words>"`, then read the 1 to 3 best keys: `phemc docs <key>`, `phemc components <name>`, or `phemc examples <key>`.
3. **Compose from an example.** Start from the closest example of the package, and use the props and the parts that `phemc components` shows. A part goes inside the component that the anatomy shows.
4. **Check after you write.** Run `phemc check <file>` for each changed `.phem` file. The command exits with code 1 when the file has an error. Correct the errors and check again.

For example, for "a settings dialog with a form":

```sh
vendor/bin/phemc search "dialog with a form"
vendor/bin/phemc examples DialogFormDemo
vendor/bin/phemc components Dialog
vendor/bin/phemc check src/Ui/SettingsDialog.phem
```

`phemc check -` checks a text on stdin before the agent saves it. Give `--stdin-path` with the path of the file, so the imports and the other components resolve:

```sh
cat draft.phem | vendor/bin/phemc check - --stdin-path src/Ui/SettingsDialog.phem --format json
```

## Connect the agent to the MCP server

`phemc mcp` gives the same texts as tools of the Model Context Protocol (MCP), so the agent can call them without a shell: `phem_search`, `phem_doc`, `phem_components`, `phem_component`, `phem_examples` and `phem_check`. The server also gives the agent the workflow above when it starts. See [`phemc mcp`](compiler.md#phemc-mcp) for the arguments of each tool.

For Claude Code, add the server to the project. Run this command from the project root:

```sh
claude mcp add --scope project phem -- vendor/bin/phemc mcp
```

The command writes the server to `.mcp.json`, so the other developers of the project get it too:

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

Other agents that support MCP servers on stdio take the same command and arguments. Start the server in the project root, and give `--src <dir>` when the source root of the application isn’t `src`.

## Add the workflow to the instructions of the agent

Most agents read a file of instructions in the project, for example `AGENTS.md` or `CLAUDE.md`. Copy this text into it:

```markdown
## Phem

This app uses Phem: `.phem` files are PHP with markup literals. Before you write `.phem` code:

- Run `vendor/bin/phemc docs --toc` and `vendor/bin/phemc components` once, to know the keys.
- Run `vendor/bin/phemc search "<task>"`, then read the best keys with `vendor/bin/phemc docs <key>`,
  `vendor/bin/phemc components <name>` or `vendor/bin/phemc examples <key>`.
- Start from the closest example, and use only the props and the parts that `phemc components` shows.
- After each change, run `vendor/bin/phemc check <file>` and correct all the errors.
```

The commands print Markdown. Add `--format json` when a tool reads the output.

## Use Claude Code

The Phem plugin for Claude Code does all of this for you (see [Use Claude Code](compiler.md#use-claude-code)):

- **MCP server**: it starts `phemc mcp` of the project, so you don't need `claude mcp add`
- **Skills**: the `phem` skill gives Claude the workflow, the cheatsheet and the list of the docs, and the `phem-ui` skill gives it the components of the installed packages
- **Check hook**: after each write or edit of a `.phem` file, it runs `phemc check` on the file. An error goes back to Claude, which corrects the file before it goes on
- **Language server**: Claude Code gets the diagnostics after each edit, and the `LSP` tool

```sh
claude plugin marketplace add phemphp/phem
claude plugin install phem@phem
```

You don't need the text of [Add the workflow to the instructions of the agent](#add-the-workflow-to-the-instructions-of-the-agent) with the plugin: the skills give it.

## Give the docs to another LLM

The website has the docs for the LLMs, as [llms.txt](https://llmstxt.org) proposes:

- **`/llms.txt`**: one line for each doc, with a link to its raw Markdown (`/events.md`)
- **`/llms-full.txt`**: all the docs in one file, in the order of the navigation, without the changelog. It is about 175,000 tokens: give it to a chat that has no shell, and prefer the `phemc` commands in an app, because they give the docs of the installed version and the components of the app

## Check what the agent finds

The search finds a text by its words, the words of its headings, and the `@aliases` of a component. When the agent doesn't find a text that it needs, add a query to a retrieval eval, and run it in CI:

```yaml
# tests/retrieval.yaml
- query: a modal with a form
  expect: [Acme\Ui\Dialog, Acme\Ui\Examples\DialogFormDemo]
```

```sh
vendor/bin/phemc search --eval tests/retrieval.yaml
```

The command exits with code 1 when a key isn’t in the top 20. To make a package easy to find, see [Help coding agents use the components](package-authoring.md#help-coding-agents-use-the-components). For all the options of the commands, see [Docs and components for coding agents](compiler.md#docs-and-components-for-coding-agents).
