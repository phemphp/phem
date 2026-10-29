<p align="center">
  <img src="cover.png" alt="Phem">
</p>

# Phem

Phem is the place where PHP and Twig stop passing notes and start writing together. Logic and
markup live in one file, one component, one thought. It is built for Symfony web apps.

A `.phpm` file is PHP at heart, with a component layer built on top of Twig, so your markup lives
right beside the code that gives it life:

```php
<?phpm

namespace App\Page;

use Phem\Attribute\{Component, Page, State};
use Phem\{Node, Root};

#[Component]
final class LikeButton
{
    #[State]
    public bool $liked = false;

    public function toggle(): void
    {
        $this->liked = !$this->liked;
    }

    public function render(): Node
    {
        return (
            <button onClick={{ toggle }}>
                {{ liked ? '♥ Liked' : '♡ Like' }}
            </button>
        );
    }
}

#[Page('/')]
function Home(): Node
{
    return (
        <html lang="en">
            <head>
                <meta charset="utf-8" />
                <title>Phem</title>
                {{ importmap('app') }}
            </head>
            <body>
                <Root>
                    <h1>Hello Phem!</h1>
                    <p>Components for Symfony web apps, written the way you think.</p>
                    <LikeButton />
                </Root>
            </body>
        </html>
    );
}
```

Phem is new, and it is made of old friends. It brings a modern component model to Symfony, built
from PHP and Twig, with no new language to learn and no second stack to keep.

## Install

```sh
symfony new my-app --webapp && cd my-app
composer require phem/phem
symfony serve
```

See [Getting started](docs/getting-started.md). Build a new app from scratch, and a production image.

## Documentation

- [The `.phpm` syntax](docs/phpm-syntax.md)
- [Compiling and running `.phpm` files](docs/compiling.md), with the list of diagnostics
- [Testing components and pages](docs/testing.md)
- [How it works](docs/how-it-works.md), from React to Phem, and the limits
- [Contributing](docs/contributing.md): the repository layout, the editor support and the release steps

## License

MIT. See [LICENSE](LICENSE).
