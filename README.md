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

#[Page('/')]
function Home(): Node
{
    return (
        <html lang="en">
            <head>
                <title>Welcome to Phem</title>
                {{ importmap('app') }}
            </head>
            <body>
                <Root>
                    <h1>Welcome to Phem!</h1>
                    <p>UI server components for Symfony web apps, written the way you think.</p>
                    <LikeButton />
                </Root>
            </body>
        </html>
    );
}

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
```

Phem is new, and it is made of old friends. It brings a modern component model to Symfony, built
from PHP and Twig, with no new language to learn and no second stack to keep.

## Install

```sh
symfony new my-app --webapp && cd my-app
composer require phem/phem
symfony serve
```

Build a new app from scratch using Phem. See [Getting started](docs/getting-started.md).

## More documentation

- [How it works](docs/how-it-works.md)
- [Philosophy and conventions](docs/philosophy.md)
- [Compiling `.phpm` files](docs/compiling.md)
- [The `.phpm` syntax](docs/phpm-syntax.md)
- [Testing components and pages](docs/testing.md)
- [Component packages](docs/packages.md)

## License

MIT.
