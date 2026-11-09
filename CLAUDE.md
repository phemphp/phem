# Phem

## Project status

This project has no release yet. We are still designing it, so the public API can change at any time.

- Do not keep backward compatibility when you refactor. Rename, move, or remove classes, methods,
  options, and syntax when this makes the design better.
- Do not add deprecation layers, aliases, or shims for old names.
- Update all callers, tests, examples, and docs in the same change.

## Repository layout

The repository root is the `phem/phem` Composer package (`src/`, `config/`, `templates/`,
`assets/`, `bin/phemc`). The compiler (`compiler/`), the editor support (`editors/`), the docs
(`docs/`), the docs website (`website/`) and the Docker dev image (`docker/`) live in the same
repository, so one change can update all of them. `.gitattributes` keeps them out of the Composer
dist.

## Docs

Update the docs in `docs/` in the same change when you add a feature or change a behavior. This
includes a new option, attribute, event, syntax or command, a changed default, and a bug fix that
changes what a user sees or can rely on. Find the doc that describes the behavior and change it,
or add a new doc when no doc covers it. Do not report the work as done while the docs show the old
behavior.

The docs in `docs/` are plain Markdown: GitHub, the website and LLMs read the same files. Do not
add frontmatter or MDX to them. A new doc must also go in `website/src/navigation.ts`, or the
website build fails. Run `make docs-build` after a change in `docs/` or `website/`.

## Feature discussions

Always show usage examples when we discuss a feature: a TODO item, a design, a proposal, or a
change that you made.

- Show the `.phem` code that an app or a package writes.
- Show the result: the rendered HTML, and the compiled Twig when the compiler changes.
- Show the config (`config/packages/phem.yaml`) when the feature adds an option.
- Use the real output of the tests or the snapshots when it exists.

## Design

Use the SOLID principles in all new and changed code. Follow the patterns that the code already has:

- **Single responsibility:** give each class one job. For example, `UrlQuery` keeps the query
  string, `BoundValues` sets the bound inputs, and `ComponentMetadata` reads the attributes. Put
  new behavior in a new class when it does not fit the job of an existing class.
- **Open/closed:** add behavior with a new implementation of an interface (for example a
  `HandlerMiddleware`, a `PathValueConverter`, or a `ComponentRenderer`). Do not add a new
  `if`/`match` case to a class for each new kind of thing.
- **Liskov substitution:** an implementation must keep the contract of its interface: the same
  input types, return types, exceptions, and side effects.
- **Interface segregation:** keep interfaces small. Make a new interface for a new role. Do not
  add methods to an existing interface that only some implementations need.
- **Dependency inversion:** get dependencies in the constructor. Use an interface when there is
  more than one implementation, or when a test must replace it. A default value
  (`= new ComponentMetadata()`) is correct for a stateless helper.

Do not add an abstraction that has no second use or no test need. Simple code is better than an
unused interface.

## Code style

Use blank lines to show the steps of a method. Keep the statements of one step together, and put
one blank line between two steps:

- Put a blank line after the guard clauses (an early `return`, `continue` or `throw`).
- Put a blank line between the setup of the values and the loop or the call that uses them.
- Always put a blank line after a block (`if`, `foreach`, `for`, `while`, `switch`, `try`). Do
  not add it when the closing `}` is followed by `else`, `catch`, `finally`, or the end of the
  enclosing block.
- Put a blank line before a block when it is a different step from the statements before it. An
  empty value that the loop fills (`$items = [];`) stays directly above the loop.
- Put a blank line before the last `return`.
- Put the comment of a step directly above the first line of the step, after the blank line.

Do not add a blank line in a short method where all the statements do one step. Do not add a
blank line at the start or at the end of a block.

```php
public function detached(Node $markup): string
{
    $tree = new Tree(detached: true);
    $tree->begin();

    // Its own pass, inside the pass of the page.
    $pass = $this->pass;
    $this->pass = ++$this->passes;

    try {
        $html = $this->within($tree, fn (): string => $this->markup->html($markup));
    } finally {
        $this->pass = $pass;
    }

    if (1 === preg_match('/<phem-c[\s>]/', $html)) {
        $this->notDetached('a component of this page', true);
    }

    return $html;
}
```

## Tests

Run the tests after each change in the core library (`src/`, `config/`, `templates/`, `assets/`),
before you report the work as done:

- Run `make test` from the root of the repository. It runs the JS tests (Vitest), the e2e tests
  (Playwright PHP in Chromium), cargo test and PHPUnit.
- For a fast check while you work, run PHPUnit in the dev image:
  `docker compose -f docker/compose.yaml run --rm dev vendor/bin/phpunit`. Run `make test` at the end.
- Run `make test-e2e` alone after a change that a real browser must check: the client
  (`assets/`), the morph, the events or the Mercure pushes. Add `ARGS="--filter Guestbook"` for one
  test. The specs are in `e2e/tests/`, and their app is in `e2e/src/` (see `docs/contributing.md`).
- Report the result. If a test fails, show the output. A failed e2e test saves a screenshot and the
  HTML of each page in `e2e/var/failures/`.

## Editor plugins

The VS Code and PhpStorm plugins do not use the compiler source. They run a bundled build of the
language server (`editors/server/phem-lsp.wasm`). An old build shows old diagnostics in the editor.

- After a change in `compiler/` (the diagnostics, the analysis, the codegen or the language
  server) or in `editors/`, run `make vscode-install phpstorm-install`. It rebuilds the server and
  installs the two plugins.
- Tell the user to reload the VS Code window and to restart PhpStorm.
