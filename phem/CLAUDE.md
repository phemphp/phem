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

## Tests

Run the tests after each change in the core library (`phem/`), before you report the work as
done:

- Run `make test` from the root of the repository. It runs the JS tests (Vitest), cargo test and
  PHPUnit.
- For a fast check while you work, run PHPUnit in the dev container:
  `docker exec phem-dev-1 sh -c 'cd /app/phem && vendor/bin/phpunit'`. Run `make test` at the end.
- Report the result. If a test fails, show the output.
