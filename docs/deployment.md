# Deployment: the production image

This guide builds a production image of a Phem application with
[FrankenPHP](https://frankenphp.dev). Do the [Getting started](getting-started.md) guide first.

You need [Docker](https://docs.docker.com/get-docker/). Run all the commands in the directory of
the application.

## The image

The production image runs `public/index.php` in the worker mode of FrankenPHP (PHP 8.5 and
Caddy). It installs the packages with no dev dependencies, compiles the `.phpm` files, builds the
Symfony cache, and writes the assets to `public/assets/`. Add these files:

```
my-app/
├── docker/
│   └── php-prod.ini        OPcache in production
├── .dockerignore
├── Caddyfile               FrankenPHP: public/index.php in worker mode
└── Dockerfile              the production image
```

`Dockerfile`:

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

`APP_SECRET` signs the component state in the browser. The image puts a random value in
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

`docker/php-prod.ini` enables the OPcache preload. `config/preload.php` loads the Symfony
container and the compiled components:

```ini
opcache.validate_timestamps=0
opcache.preload=/app/config/preload.php
opcache.preload_user=root
```

`Caddyfile` runs `public/index.php` in worker mode: each worker boots the kernel once and
handles many requests:

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

Build and run the image:

```sh
docker build -t my-app .
docker run --rm -p 8080:8080 my-app
```

Open http://localhost:8080. In production, Symfony does not check the cache for changes: build
the image again after each change.

To use FrankenPHP in development too, see
[Watch mode](compiling.md#5-watch-mode-development).
