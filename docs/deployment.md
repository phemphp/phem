# Deploy a Phem app

Serve your Phem app with [FrankenPHP](https://frankenphp.dev) or PHP-FPM, build it for production, and package it in a Docker image with static error pages for the web server. You need an app from [Getting started](getting-started.md). Run every command from the root of your app.

## Serve the app

The compiled application runs as any Symfony application, with FrankenPHP in worker mode or with PHP-FPM. With FrankenPHP, run this in the project directory:

```sh
frankenphp run --config Caddyfile
```

The server setup has these parts:

- **Front controller**: `public/index.php` uses Symfony Runtime. In a FrankenPHP worker, Symfony Runtime boots the kernel once and passes it many requests, and the kernel resets its services (`kernel.reset`) between requests. With PHP-FPM, use `public/index.php` as the front controller
- **Static files**: Caddy serves the files of `public/` at `/`, and a directory serves its `index.html`. In development, Symfony serves the AssetMapper assets at `/assets/`
- **Worker options**: `FRANKENPHP_WORKER_CONFIG` adds options to the worker. For example, `watch ./src/**/*.php` restarts the workers when a PHP class changes
- **Environment**: `APP_ENV=dev`, the default in `.env`, shows the Symfony error pages with the `.phem` location
- **Database**: the application expects the database to exist. Create it before the first run, for example with `bin/console doctrine:schema:create` and `bin/console doctrine:fixtures:load`. In Docker, run these commands in the entrypoint, only when the database doesn’t exist yet

For a watch loop that rebuilds and restarts the server, see [`phemc watch`](compiling.md#phemc-watch).

## Build for production

In production, Symfony doesn’t check its cache for changes, so rebuild the cache after each `phemc build`. Run this sequence:

```sh
export APP_ENV=prod APP_DEBUG=0
composer install --no-dev --optimize-autoloader
phemc build src --no-warmup
rm -rf var/cache/prod && php bin/console cache:warmup
php bin/console asset-map:compile
```

Each step has one job:

- **`phemc build src --no-warmup`**: compiles the sources. `cache:warmup` runs the Twig warmup later, so the build skips it
- **`cache:warmup`**: builds the container (with the component services), the routes, the Twig cache of the `phemc` templates, the route values of the page links (`path(ProductPage, {product: product})`), and the OPcache preload file of the components
- **`asset-map:compile`**: writes the assets to `public/assets/`

Set a random `APP_SECRET`, for example in `.env.local`. It signs the component state in the browser (the `phem.secret` option defaults to `%kernel.secret%`).

### Turn on the OPcache preload

`config/preload.php` loads the preload file of the Symfony container. `cache:warmup` writes `phem-preload.php` in the kernel build directory (`var/cache/prod/` by default), and Symfony adds it to that file, so `config/preload.php` needs no change. Enable the preload in `php.ini`:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```

## Build a Docker image

The image uses FrankenPHP and runs the production build. You need [Docker](https://docs.docker.com/get-docker/).

### Add the production files

The image needs four files next to your app code. Add them with this layout:

```text
my-app/
├── docker/
│   └── php-prod.ini        OPcache in production
├── .dockerignore
├── Caddyfile               FrankenPHP: public/index.php in worker mode
└── Dockerfile              the production image
```

#### Write the Dockerfile

The `Dockerfile` starts from FrankenPHP (PHP 8.5 and Caddy). It installs the packages without dev dependencies, compiles the `.phem` files, builds the Symfony cache, and writes the assets to `public/assets/`:

```dockerfile
# syntax=docker/dockerfile:1.7
FROM dunglas/frankenphp:1-php8.5
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod APP_DEBUG=0
WORKDIR /app
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

Phem signs the component state that it sends to the browser with `APP_SECRET`. The image writes a random value to `.env.local` at build time.

#### Exclude local files from the build

Keep local dependencies, caches, and secrets out of the image with `.dockerignore`:

```text
.git
vendor
var
public/assets
assets/vendor
.env.local
```

#### Add the OPcache settings

`docker/php-prod.ini` turns on the OPcache preload (see [Turn on the OPcache preload](#turn-on-the-opcache-preload)). PHP then loads the Symfony container and the compiled components from `config/preload.php` once, at startup:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```

#### Run the app in worker mode

The `Caddyfile` runs `public/index.php` in FrankenPHP worker mode. Each worker boots the kernel once and then handles many requests:

```caddyfile
{
    frankenphp {
        worker {
            file ./public/index.php
        }
    }
}

{$SERVER_NAME::8080} {
    encode zstd gzip

    root * public
    php_server
}
```

### Build and run the image

Build the image and start a container on port 8080:

```sh
docker build -t my-app .
docker run --rm -p 8080:8080 my-app
```

Open `http://localhost:8080`. In production, Symfony doesn’t check the cache for changes, so rebuild the image after each change.

To use FrankenPHP in development too, see [`phemc watch`](compiling.md#phemc-watch).

### Add static error pages for the web server

When your app doesn’t answer, the web server or proxy in front of it shows its own error. To show your app’s [error pages](error-pages.md) there too, write them to files in the image after `cache:warmup`:

```dockerfile
 && php bin/console cache:warmup \
 && php bin/console error:dump public/errors 502 503 504 --force \
```

The image sets `APP_DEBUG=0`, so the files don’t show the exception. Set `framework.router.default_uri` to your site’s URL so the links in the pages use its host. To make nginx or Caddy serve the files, see [Serve error pages when PHP is down](error-pages.md#serve-error-pages-when-php-is-down).
