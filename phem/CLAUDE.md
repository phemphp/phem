# Phem

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

Run the tests after each change in the core library (`phem/`), before you report the work as
done:

- Run `make test` from the root of the repository. It runs the JS tests (Vitest), cargo test and
  PHPUnit.
- For a fast check while you work, run PHPUnit in the dev container:
  `docker exec phem-dev-1 sh -c 'cd /app/phem && vendor/bin/phpunit'`. Run `make test` at the end.
- Report the result. If a test fails, show the output.
