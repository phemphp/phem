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

The Phem plugin for Claude Code also runs the language server, so Claude Code gets the diagnostics after each edit (see [Use Claude Code](compiler.md#use-claude-code)). Add the workflow above to `CLAUDE.md` too: the language server shows the errors, and the `phemc` commands give the data that prevents them.

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
