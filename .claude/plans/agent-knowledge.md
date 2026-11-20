# Phem knowledge for coding agents

Status: phases 1 to 4 done (2026-11-20): the engine (`compiler/crates/phemc-knowledge`), the
CLI, the examples of ui-base (`ui/base/examples/`), `phemc mcp` (`phemc-cli/src/mcp.rs`), and the
Claude Code plugin (`editors/claude-code/`: skills, hook, `.mcp.json`, `docs/cheatsheet.md`).
Phase 5 is open. The illustrated version is a private artifact:
https://claude.ai/artifact/GpUdLcBbsR3ptYYUswxLhJ. This file is the source of truth.

## Context

Agents (Claude Code first) write `.phem` code in Symfony apps. Today they get only `llms.txt` on
the website and the LSP plugin (`editors/claude-code`). They do not know:

- the syntax, features and rules of the **installed** phem version,
- which ui-base components exist, their props, and which parts go inside which root,
- how to compose components for a use case (dialog with a form, menu in a toolbar, ...).

Goal: give the agent the correct, version-matched data for each prompt, and make the result as
deterministic as possible. Decisions: Claude Code first; data through the `phemc` CLI and an MCP
server; ui-base recipes come from shipped examples.

## Principles

1. **Generate, do not write.** The API data comes from the code: signatures, `#[Prop]`, types,
   defaults, enum cases, docblocks, `#[Context]` and `#[Provide]`. Only intent (rules, when to use
   a recipe) is hand-written, and it lives next to the code that it describes.
2. **Version-matched.** The docs are embedded in the `phemc` binary at build time. `bin/phemc`
   already downloads the binary of the installed `phem/phem` version. The component data comes
   from the installed sources in `vendor/`.
3. **Stable keys, lexical search.** Each fact has a key: `events#debounce`, `Phem\Ui\Base\Dialog`,
   `Phem\Ui\Base\Examples\DialogFormDemo`. Search is BM25 with a fixed tokenizer and a tie-break on
   the key. The same query always gives the same result. No embeddings at runtime.
4. **Three layers.** Core (small, always loaded: the rules and the maps) → Retrieval (on demand,
   by key or search) → Verification (`phemc check`, the ground truth).

## Retrieval design (checked against Anthropic's "Contextual Retrieval")

The article: a chunk loses the context of its document, so retrieval misses it. Add a short
context to each chunk before you index it (embeddings −35 % misses, + BM25 −49 %, + rerank −67 %).
Give the model the top 20, not 5 or 10. Under ~200k tokens, put the whole corpus in the prompt.
Run evals on your own queries. What we take, and how we keep it deterministic:

1. **Size decides: map in context, territory on demand.** The corpus is too big to load: docs
   ~160k tokens (`docs/*.md`, 647 KB), ui-base sources ~48k, examples ~105k. A coding agent also
   needs its context for the code. But the maps are small: the section list of the docs (394
   headings, ~5k tokens) and the component families with one line each (~2k tokens). The core loads
   the maps, so the model chooses a key itself. Search is the fallback for vague prompts.
2. **Contextual chunks, from the structure.** Each chunk gets a context header before indexing.
   The engine builds it from the structure, with no LLM:
   - doc section: `Events › Debounce — server events in .phem markup` (doc title, H2, H3, the
     first sentence of the doc);
   - component: `DialogPopup — part of Dialog (phem/ui-base), goes inside DialogViewport`;
   - recipe: `DialogFormDemo — example of Dialog, uses Dialog, DialogPopup, Field, Form`.
   BM25 indexes the header plus the text ("contextual BM25"). Chunk boundaries: one section per
   `##`/`###`, never split a code block; one chunk per component; one chunk per example.
3. **Close the vocabulary gap without embeddings.** Embeddings find "modal" for `Dialog`; BM25
   does not. Add an `@aliases` docblock tag on the roots (`@aliases modal, lightbox`) and use it
   in the index. The tokenizer splits camelCase (`DialogPopup` → `dialog popup`) and keeps codes
   whole (`E042`). Aliases are hand-written intent next to the code (principle 1).
4. **The agent is the reranker.** `phem_search` returns the top 20 with their context headers.
   The agent reads them and fetches 1–3 by key. No rerank model, no network.
5. **Mark context and text.** The JSON output has `context` and `text` fields; the Markdown output
   puts the context in one line above the text. The agent can tell them apart.
6. **Byte-stable output.** The core output has no dates and a fixed order, so Claude Code's prompt
   cache keeps it across turns.
7. **Retrieval eval in phase 1.** A YAML file of real prompts → the keys that must be in the top
   20. `phemc search --eval <file>` exits 1 under 100 % recall@20. phem has its eval for the docs
   and the fixture package (`compiler/crates/phemc-knowledge/tests/retrieval.yaml`, in
   `cargo test`). Each package has its own (`ui/base/tests/retrieval.yaml`, ~50 prompts). A new
   doc or component that breaks a query shows in the diff.
8. **Build-time LLM contexts only if the eval fails.** If structural headers and aliases do not
   reach the target, a maintainer runs `make knowledge-contexts` (Claude writes a 50–100 token
   context per chunk, with prompt caching), commits the result, and phemc embeds it. Runtime stays
   deterministic; a person reviews the diff.

```
 Sources (repo)                 Engine (phemc, Rust)            Interfaces
 ─────────────────              ─────────────────────           ───────────────────────────
 docs/*.md  ── include_str! ──▶ docs index (slug#section) ──┐   phemc docs / components /
 src/**/*.phem (app)  ───────┐                              ├──▶ examples / search / check
 vendor/*/extra.phem.source ─┼▶ component catalog ──────────┤   phemc mcp (stdio, MCP tools)
 vendor/*/extra.phem.examples┘  recipe index ───────────────┤   Claude Code plugin:
 package README (overview)  ──▶ package index ──────────────┘     skills + hook + .mcp.json + LSP
```

## ui-base as a separate package

ui-base moves to its own repository soon (`git subtree split --prefix=ui/base`). The design must
not need the monorepo. The boundary:

| phem repository owns | Each package repository owns (ui-base or any other) |
|---|---|
| The engine, the CLI, `phemc mcp`, the Claude Code plugin | Its sources (`extra.phem.source`) |
| The package contract: `extra.phem.examples`, `extra.phem.guide`, `@aliases`, the docblock rules | Its examples, its aliases, its guide (Markdown) |
| `phemc search --eval <file>` (the eval runner) | Its retrieval eval file and its CI |
| The docs and the cheatsheet of phem | Its demo (it reads its own `examples/`) |

Rules that follow from it:

1. **No ui-base knowledge in phem.** The engine, the skills and the tests of phem never name a
   ui-base component. This is already the rule in `CLAUDE.md` for `src/`; it now applies to
   `compiler/` and `editors/claude-code/` too. The skill `phem-ui` works with any installed
   component package.
2. **The contract is public.** `docs/package-authoring.md` documents `extra.phem.examples`,
   `extra.phem.guide`, `@aliases` and the docblock rules. A change of the contract needs a phem
   release first, then a release of each package.
3. **Package guide.** `extra.phem.guide: "README.md"` (or a shorter `AGENTS.md`) points to the
   package text for agents: naming rules, install steps. Its keys have the package prefix:
   `phem/ui-base#naming` (the same `#` as a doc section). `phemc components` shows the section
   keys of the guide of each package.
4. **Version match per package.** The data of a package comes from `vendor/<package>/`, so it
   matches the installed version of that package, not the version of phem.
5. **Dist.** The ui-base `.gitattributes` keeps `src/`, `examples/` and the guide in the Composer
   dist, and can `export-ignore` `demo/` and `tests/`. Examples are part of the dist because the
   agent reads them in `vendor/`.
6. **Version constraint.** ui-base requires the first phem version that reads
   `extra.phem.examples` (for example `^0.5`). An older phemc ignores the unknown keys, so
   nothing breaks.
7. **Demo with no path tricks.** The demo compiles `examples/` with a phemc option that any app
   can use (for example `phemc build src --examples-of phem/ui-base`), not with a `../examples`
   path that works only in the monorepo.
8. **Tests on each side.** The cargo tests of phem use a fixture package in
   `compiler/crates/phemc-knowledge/tests/fixtures/`, not `ui/base/src`. ui-base runs
   `phemc check examples` and `phemc search --eval tests/retrieval.yaml` in its own CI (and in
   `make ui-test` while it is still here).
9. **Order.** Phase 1 (phem) comes first. If the split happens before phase 2, phase 2 is a
   change in the ui-base repository that needs the phem release of phase 1.

## Architecture

### 1. Engine: new crate `compiler/crates/phemc-knowledge`

- **Catalog.** Reuse the registry of `phemc-analyze` (`ComponentDef` in
  `compiler/crates/phemc-analyze/src/lib.rs:101`). It already has `types`, `contexts`, `provides`,
  `required`, `rest`, `slots` (now `#[serde(skip)]`). Add: the docblock summary and body, the
  default value of each prop, the cases of an enum type.
  - Move `doc_tags` from `compiler/crates/phemc-lsp/src/classes.rs` to a shared crate, so the LSP
    hover and the catalog read docblocks the same way.
  - Derive the anatomy: a part with a required `#[Context]` of type `R` goes inside `R`. A root
    with `#[Provide]` lists its parts. This gives the tree with no hand-written data.
- **Docs index.** A `build.rs` embeds `docs/*.md` (not `contributing.md`, `releasing.md`). Split
  each doc by `##`/`###` headings. Use the same anchor algorithm as the website
  (`website/src/docs.ts`), so a key is also a website URL.
- **Recipe index.** Read the folders in `extra.phem.examples` of each package (found by
  `phemc-sources`). Each example is a `#[Component]` function: the docblock summary is the title,
  the body says when to use it. Derive the components that it uses from its tags.
- **Search.** One BM25 index over the contextual chunks (doc sections, components, recipes), with
  `@aliases` and the camelCase tokenizer. It returns the top 20, sorted by score, then by key.
- **Maps.** `phemc docs --toc` (all section keys and titles) and `phemc components`
  (families, one line each). The skills load both.

### 2. CLI (thin over the engine, `compiler/crates/phemc-cli/src/main.rs`)

| Command | Output |
|---|---|
| `phemc docs [<slug>[#section]]` | The doc index, a doc, or one section |
| `phemc components [<tag>] [--package <name>]` | The catalog, or one component with its anatomy |
| `phemc examples [<tag>]` | The recipes that use a component |
| `phemc search <query>` | Ranked keys with one-line summaries |
| `phemc check <src> --format json` | Exists. Add a file argument and `-` (stdin) for one snippet |

All commands take `--format md|json`. Markdown is for the agent, JSON is for tools.

### 3. MCP server: `phemc mcp` (stdio)

Tools: `phem_search`, `phem_doc`, `phem_component`, `phem_components`, `phem_examples`,
`phem_check` (a file or a code snippet → diagnostics). Each tool calls the same engine function as
its CLI command. Reuse the stdio loop style of `phemc-lsp/src/server.rs`; MCP uses one JSON line
per message.

### 4. ui-base: shipped examples

- Move the example functions of `ui/base/demo/src/Ui/Demos/*.phem` to `ui/base/examples/*.phem`,
  namespace `Phem\Ui\Base\Examples`. Keep one file per component family.
- `ui/base/composer.json`: `"extra": {"phem": {"source": "src", "examples": "examples"}}`.
- `extra.phem.guide: "README.md"`, and `@aliases` on the roots.
- The demo pages render the examples, and `Gallery::source()` reads them from `examples/`
  (`ui/base/demo/src/Ui/Gallery.php`). The demo compiles them with the phemc option of rule 7
  in "ui-base as a separate package".
- `phemc check examples` and `phemc search --eval tests/retrieval.yaml` run in `make ui-test`,
  so a recipe cannot go stale.
- `.gitattributes` of ui-base: keep `examples/` in the dist, `export-ignore` `demo/`.
- `phemc` never compiles `extra.phem.examples` into a user app. It only indexes them.
- Improve the docblocks of `ui/base/src/*.phem` where the summary is missing: they are now the API
  docs.

### 5. Claude Code plugin (`editors/claude-code`)

- `.mcp.json`: starts `bin/phem-mcp`, a wrapper like `bin/phem-lsp` (`vendor/bin/phemc mcp`, then
  `phemc mcp` on the PATH).
- Skill `phem`: triggers on `.phem` files and Symfony UI work. Its body loads the core with
  dynamic context, `` !`vendor/bin/phemc docs cheatsheet` `` and the map
  `` !`vendor/bin/phemc docs --toc` ``, and gives the workflow: look up
  (`phem_search`, `phem_doc`) before you write, run `phem_check` after you write.
- Skill `phem-ui`: triggers on UI tasks (forms, dialogs, menus, lists). Workflow: 1)
  `phem_examples`/`phem_search` for the use case, 2) `phem_component` for each tag, 3) compose
  from the closest recipe, 4) `phem_check`. Its body injects
  `` !`vendor/bin/phemc components` ``: the installed packages and their roots.
- Hook `PostToolUse` on `Write|Edit` of `*.phem`: run `phemc check <file> --format json`. Exit
  code 2 sends the errors back to Claude. This is the deterministic gate.
- Keep the LSP. Bump the plugin version in `.claude-plugin/plugin.json`.

### 6. Core content

New doc `docs/cheatsheet.md`: the syntax in one page and the 10–15 rules that agents break most
(from `principles.md` and the diagnostic table in `compiler.md`). It is also a website page. It is
the only text that the skill always loads. Add it to `website/src/navigation.ts`.

### 7. Other agents (later, small)

- `llms-full.txt` route next to `website/src/pages/llms.txt.ts`.
- A short `AGENTS.md` text in `docs/ai-agents.md` that a user can copy: "use `phemc search`,
  `phemc components`, and run `phemc check`". Any agent with a shell can use the CLI.

## Usage examples

An example in ui-base (`ui/base/examples/Dialog.phem`):

```php
namespace Phem\Ui\Base\Examples;

/**
 * Dialog with a form.
 *
 * Use it to edit a record without a page change: the submit button runs a server event and
 * closes the dialog when the form is valid.
 */
#[Component]
function DialogFormDemo(): Node
{
    return (
        <Dialog>
            <DialogTrigger>Edit profile</DialogTrigger>
            <DialogPopup>
                <DialogTitle>Edit profile</DialogTitle>
                <form>...</form>
                <DialogClose>Cancel</DialogClose>
            </DialogPopup>
        </Dialog>
    );
}
```

`phemc components Dialog` (Markdown output, the shape to snapshot):

```md
# Dialog — Phem\Ui\Base\Dialog (phem/ui-base)
A popup that opens on top of the page. ...
Import: use Phem\Ui\Base\Dialog; use function Phem\Ui\Base\{DialogTrigger, DialogPopup, ...};

## Props
| Prop | Type | Default |
| handle | ?string | null |
| defaultOpen | bool | false |
| modal | bool | true |
| ...rest | attributes | |

## Anatomy
Dialog
├─ DialogTrigger
├─ DialogViewport
│  └─ DialogPopup
│     ├─ DialogTitle, DialogDescription, DialogClose
## Examples
- Phem\Ui\Base\Examples\DialogFormDemo — Dialog with a form.
- Phem\Ui\Base\Examples\DialogNestedDemo — Nested dialogs.
```

MCP call from the agent:

```json
{"name": "phem_search", "arguments": {"query": "confirm before delete"}}
→ [{"key": "Phem\\Ui\\Base\\Examples\\AlertDialogHeroDemo", "summary": "..."},
   {"key": "events#confirm", "summary": "..."}]
```

Hook in `editors/claude-code/hooks/hooks.json`:

```json
{"PostToolUse": [{"matcher": "Write|Edit",
  "hooks": [{"type": "command", "command": "${CLAUDE_PLUGIN_ROOT}/bin/phem-check-hook"}]}]}
```

## Phases (each one is a separate change)

1. Done. Engine + CLI: `phemc-knowledge`, shared `doc_tags`, catalog fields, contextual chunks,
   `@aliases`, `extra.phem.examples` and `extra.phem.guide`, `docs` (with `--toc`), `components`,
   `search` (with `--eval`), `check` on a file or stdin, the demo option. Snapshot tests on a
   fixture package. The phem retrieval eval. The contract in `docs/package-authoring.md`.
2. Done. ui-base examples (in this repository or in the ui-base repository after the split): move them,
   composer keys, guide, aliases, dist rules, demo and `Gallery` read them, docblocks, its eval.
3. Done. `phemc mcp` with protocol tests.
4. Done. Claude Code plugin: skills, hook, `.mcp.json`, `docs/cheatsheet.md`.
5. Other agents: `llms-full.txt`, `docs/ai-agents.md` (done with phase 1). Evals: a fixed set of prompts run with
   `claude -p`, scored on `phemc check` passing and on the expected tags.

## Docs, changelog, releases (each phase)

- `docs/compiler.md` (new commands), `docs/package-authoring.md` (`extra.phem.examples`,
  docblocks are the API docs), new `docs/ai-agents.md` and `docs/cheatsheet.md` in
  `website/src/navigation.ts`. `CHANGELOG.md` entry at the top of `## Unreleased`.
- Moving `doc_tags` changes the language server: prepare the VS Code and PhpStorm versions and
  changelogs as `CLAUDE.md` says. Do not publish.

## Verification

- `cargo test` (snapshots of `phemc components`, `docs`, `search` on the fixture package; MCP
  request/response tests; the phem retrieval eval at 100 % recall@20).
- In ui-base: `phemc check examples` and `phemc search --eval tests/retrieval.yaml`.
- Split check: `grep -rn "Ui\\\\Base" compiler/ editors/claude-code/` finds nothing.
- `make test`; `make ui-test` and `make ui-test-e2e` after phase 2; `make docs-build` after doc
  changes.
- Manual check: in `ui/base/demo`, run `vendor/bin/phemc components Dialog`, `phemc search
  "dialog with form"` two times and compare the output (same result). Load the plugin for one
  session only (`claude --plugin-dir editors/claude-code`, no install), ask for "a settings dialog
  with a form", and confirm that the agent calls `phem_examples` and that the hook blocks a wrong
  part.
