# Phem

Phem brings the React component model to PHP and Symfony. A `.phpm` file is a PHP file that contains markup literals, the same way a `.jsx` file contains JSX:

```php
<?phpm
namespace App\Pages\Examples;

use Phem\Attribute\{Component, Page, State};
use Phem\Node;

use function App\Components\Document;

#[Component]
function MyButton(int $count, Closure $onClick): Node 
{
    return (
        <button onClick={{ onClick }}>
            Clicked {{ count }} times
        </button>
    );
}

#[Page('/')]
final class MyApp
{
    #[State]
    public int $count = 0;

    public function handleClick(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return (
            <Document>
                <Document:title>Counters</Document:title>
                <h1>Counters that update together</h1>
                <MyButton count={{ count }} onClick={{ handleClick }} />
                <MyButton count={{ count }} onClick={{ handleClick }} />
            </Document>
        );
    }
}
```

A page is a class with `#[Page]`: a Symfony route. A `#[Component]` or `#[Page]` class is a
Symfony service, so its constructor gets the services that it needs. A `#[State]` property keeps
its value in the browser between events, and an event handler such as `handleClick()` runs on the
server.

## Install

In a Symfony application:

```sh
composer require phem/phem
vendor/bin/phemc build src --out var/cache/phem
```

On the first run, `vendor/bin/phemc` downloads the `phemc` compiler of the installed version from
the GitHub release. Without Composer, use
`curl -fsSL https://github.com/phemphp/phem/raw/refs/heads/main/install.sh | sh`
(on Windows, `install.ps1`).

## Documentation

- [The `.phpm` syntax](docs/phpm-syntax.md)
- [Compiling and running `.phpm` files](docs/compiling.md), with the list of diagnostics
- [Testing components and pages](docs/testing.md)
- [How it works](docs/how-it-works.md), from React to Phem, and the limits

## Contributing

You need only Docker:

```sh
make up      # the quickstart on http://localhost:8080/learn, in watch mode
make test    # the JS tests, cargo test and PHPUnit
```

See [docs/contributing.md](docs/contributing.md) for the repository layout, the editor support and
the release steps.

## License

MIT. See [LICENSE](LICENSE).
