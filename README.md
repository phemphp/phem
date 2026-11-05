> [!WARNING]
> This repository is under construction. The code, the API and the docs can change at any time.

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

## Documentation

Read the docs on the website: https://phem.dev/. New to Phem? Start here:

- [What is Phem](docs/what-is-phem.md): the five ideas of Phem
- [Getting started](docs/getting-started.md): install Phem in a new Symfony app
- [Tutorial: a task list](docs/tutorial.md): build a small app, one idea at a time

## License

MIT.
