# Getting started: a new app from scratch

This guide adds Phem to a new Symfony application, step by step. The Symfony CLI makes the
application. Docker runs it: PHP, Composer, [FrankenPHP](https://frankenphp.dev) and the `phemc`
compiler.

At the end, you have:

- a dev server on http://localhost:8080 that builds the `.phpm` files again when you save them,
- a page with an interactive component,
- a production image.

You need:

- the [Symfony CLI](https://symfony.com/download), with PHP 8.5 and Composer,
- [Docker](https://docs.docker.com/get-docker/) with Docker Compose.

## The files

`symfony new` and the Symfony Flex recipes make most of the files. The guide adds these files,
and changes one (`config/preload.php`):

```
my-app/
├── config/
│   ├── routes/phem.yaml
│   └── preload.php         changed: the compiled components
├── docker/
│   ├── dev.sh              the command of the dev server
│   └── php-prod.ini        OPcache in production
├── src/
│   ├── Component/
│   │   ├── Counter.phpm
│   │   └── Document.phpm
│   └── Page/
│       └── Home.phpm
├── .dockerignore
├── Caddyfile               FrankenPHP: public/index.php in worker mode
├── compose.yaml            the dev server
└── Dockerfile              the dev and the prod images
```

## Step 1: Make the Symfony application

```sh
symfony new my-app && cd my-app
```

Run all the commands of this guide in `my-app/`.

## Step 2: Install Phem

Install AssetMapper and StimulusBundle first, then Phem:

```sh
composer require symfony/asset-mapper symfony/stimulus-bundle
composer require phem/phem
```

Symfony Flex does the setup:

- It enables `PhemBundle` (and `TwigBundle`) in `config/bundles.php`.
- It adds the `phem` Stimulus controller and its CSS to `assets/controllers.json`. This controller
  is the client of Phem. It sends the events to the server and updates the page with Turbo.
- It adds `@hotwired/turbo` to `importmap.php`.

`phem/phem` also gives `vendor/bin/phemc`. On the first run, it downloads the `phemc` binary of
the same version from the GitHub release. The compiler and the runtime always have the same
version.

The defaults of PhemBundle are correct for this layout: it reads `src/` and the build output is
in `var/cache/phem/`. You do not need a `config/packages/phem.yaml` file.

## Step 3: Configure Phem

`config/routes/phem.yaml` loads the routes of the pages (the `#[Page]` attributes) and the
routes of their events:

```yaml
phem:
    resource: .
    type: phem
```

`config/preload.php` loads the Symfony container in production. Add the compiled components at
the end of the file:

```php
if (file_exists(dirname(__DIR__).'/var/cache/phem/preload.php')) {
    require dirname(__DIR__).'/var/cache/phem/preload.php';
}
```

## Step 4: Add the Docker files

The image is based on the FrankenPHP image. It has PHP 8.5 and the Caddy web server. The `dev`
target runs the project from a bind mount. The `prod` target copies the project and compiles it.

`Dockerfile`:

```dockerfile
# syntax=docker/dockerfile:1.7
FROM dunglas/frankenphp:1-php8.5 AS base
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*
# pcntl: vendor/bin/phemc gives the signals (Ctrl+C) to the compiler.
RUN install-php-extensions pcntl
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

# --- Dev: the project is mounted at /app --------------------------------
FROM base AS dev
CMD ["docker/dev.sh"]

# --- Prod: the compiled application -------------------------------------
FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY . /app
# composer install boots the kernel before phemc build: build its cache again after.
RUN printf 'APP_SECRET=%s\n' "$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')" > .env.local \
 && composer install --no-dev --optimize-autoloader --no-interaction \
 && vendor/bin/phemc build src --no-warmup \
 && rm -rf var/cache/prod \
 && php bin/console cache:warmup \
 && php bin/console asset-map:compile
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-app.ini
CMD ["frankenphp", "run", "--config", "Caddyfile"]
```

`APP_SECRET` signs the component state in the browser. The prod image makes a random value in
`.env.local`.

`.dockerignore`:

```
.git
vendor
var
public/assets
assets/vendor
.env.local
```

`compose.yaml`:

```yaml
services:
  app:
    build: { context: ., target: dev }
    ports: ["8080:8080"]
    volumes:
      - .:/app
```

`docker/dev.sh` is the command of the dev server. `phemc watch` builds the `.phpm` files, starts
FrankenPHP, and builds again when a `.phpm` file changes:

```sh
#!/bin/sh
# Install the dependencies, then run FrankenPHP in watch mode.
set -e
composer install --no-interaction
# The workers restart when a PHP class in src/ changes.
export FRANKENPHP_WORKER_CONFIG='watch ./src/**/*.php'
# phemc builds again when a .phpm file changes, then restarts FrankenPHP.
exec vendor/bin/phemc watch src --exec "frankenphp run --config Caddyfile"
```

Make it executable:

```sh
chmod +x docker/dev.sh
```

`docker/php-prod.ini` enables the OPcache preload in production:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```

## Step 5: Configure FrankenPHP

`Caddyfile`:

```caddyfile
{
	frankenphp {
		worker {
			file ./public/index.php
			{$FRANKENPHP_WORKER_CONFIG}
		}
	}
}

{$SERVER_NAME::8080} {
	encode zstd gzip

	root * public
	php_server
}
```

`public/index.php` runs in worker mode: each worker boots the kernel once and handles many
requests.

## Step 6: Write the HTML document

The application owns the HTML document. It is a component, as the others. Each page renders its
content in it. `<Root>` is the root element of the page: the `phem` controller gets the events of
the elements in it.

`src/Component/Document.phpm`:

```php
<?phpm

namespace App\Component;

use Phem\Attribute\Component;
use Phem\{Html, Node, Root};

// The HTML document of the pages. A page gives its title with <Document:title>.
#[Component]
function Document(Html $title, Html $children): Node
{
    return (
        <html lang="en">
            <head>
                <meta charset="utf-8" />
                <meta name="viewport" content="width=device-width, initial-scale=1" />
                <title>{{ title }}</title>
                <link rel="icon" href="data:," />
                {{ importmap('app') }}
            </head>
            <body>
                <Root>{{ children }}</Root>
            </body>
        </html>
    );
}
```

`{{ importmap('app') }}` loads `assets/app.js` from the AssetMapper recipe. This file starts
Stimulus with the `phem` controller.

## Step 7: Write the first page

A page is a class with the `#[Page]` attribute: a Symfony route. Its `render()` method returns
the markup.

`src/Page/Home.phpm`:

```php
<?phpm

namespace App\Page;

use Phem\Attribute\Page;
use Phem\Node;

use function App\Component\Document;

#[Page('/')]
final class Home
{
    public function render(): Node
    {
        return (
            <Document>
                <Document:title>My app</Document:title>
                <h1>Hello, Phem!</h1>
            </Document>
        );
    }
}
```

## Step 8: Start the dev server

```sh
docker compose up --build
```

On the first start, the container installs the Composer packages, downloads `phemc` and the
JavaScript packages, then builds the `.phpm` files:

```
phemc: compiled 2 file(s), 2 template(s), 1 page(s); 8 file(s) changed, 0 warning(s) in 5 ms
phem: compiled 2 template(s) into the Twig cache in 27 ms
phemc: $ frankenphp run --config Caddyfile
phemc: watching src for changes
```

Open http://localhost:8080. The page shows "Hello, Phem!".

Keep the server on. The next steps change the files while it runs.

## Step 9: Add an interactive component

A class component keeps its state in `#[State]` properties. The state stays in the browser
between the events. An event handler, such as `increment()`, runs on the server.

`src/Component/Counter.phpm`:

```php
<?phpm

namespace App\Component;

use Phem\Attribute\{Component, State};
use Phem\Node;

#[Component]
final class Counter
{
    #[State]
    public int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function render(): Node
    {
        return (
            <button type="button" onClick={{ increment }}>
                Clicked {{ count }} times
            </button>
        );
    }
}
```

Use it two times in the page. Each `<Counter />` has its own state.

`src/Page/Home.phpm`:

```php
<?phpm

namespace App\Page;

use Phem\Attribute\Page;
use Phem\Node;

use App\Component\Counter;
use function App\Component\Document;

#[Page('/')]
final class Home
{
    public function render(): Node
    {
        return (
            <Document>
                <Document:title>My app</Document:title>
                <h1>Hello, Phem!</h1>
                <Counter />
                <Counter />
            </Document>
        );
    }
}
```

Save the files. `phemc` builds them again and restarts FrankenPHP:

```
phemc: change detected, rebuilding
phemc: compiled 3 file(s), 3 template(s), 1 page(s); 7 file(s) changed, 0 warning(s) in 13 ms
phemc: $ frankenphp run --config Caddyfile
```

Reload the page, and click the buttons. Each button counts its own clicks.

If a file has an error, `phemc` shows the diagnostic with the `.phpm` line, and the previous
server keeps running. To see all the diagnostics, run:

```sh
docker compose exec app vendor/bin/phemc check src
```

## Step 10: Use the console

Run the Symfony console in the container:

```sh
docker compose exec app bin/console debug:router     # the routes of the pages and their events
docker compose exec app bin/console phem:render /    # the HTML of a page, with no server
```

## Step 11: Build the production image

The `prod` target installs the packages with no dev dependencies, compiles the `.phpm` files,
builds the Symfony cache, and writes the assets to `public/assets/`:

```sh
docker build --target prod -t my-app .
docker run --rm -p 8081:8080 my-app
```

Open http://localhost:8081.

In production, Symfony does not check the cache for changes. Build the image again after each
change.

## Next steps

- [The `.phpm` syntax](phpm-syntax.md): props, slots, lists, forms, and events
- [Compiling and running `.phpm` files](compiling.md): the build output and the diagnostics
- [Testing components and pages](testing.md)
- [How it works](how-it-works.md), and the limits
