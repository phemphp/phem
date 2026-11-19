# syntax=docker/dockerfile:1.7
# The production image of the phem/ui-base demo, served by FrankenPHP in worker mode. The build
# context is the root of the repository: the demo requires phem/phem and phem/ui-base from it
# (Composer path repositories), and the image builds phemc from compiler/.
#
#   docker build -f ui/base/demo/etc/Dockerfile -t phem-ui-base-demo .
#   docker run --rm -p 8080:8080 phem-ui-base-demo
#
# The image copies demo/.env.local (not in git) with its APP_SECRET. `make demo` writes it.
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5

# --- phemc, built from the compiler of this repository ---
FROM rust:1-bookworm AS phemc
WORKDIR /src
COPY compiler/ compiler/
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/src/compiler/target \
    cargo build --release --locked --manifest-path compiler/Cargo.toml -p phemc-cli \
 && cp compiler/target/release/phemc /usr/local/bin/phemc

# --- The demo app ---
FROM ${FRANKENPHP_IMAGE}
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=phemc /usr/local/bin/phemc /usr/local/bin/phemc
ENV COMPOSER_ALLOW_SUPERUSER=1 APP_ENV=prod APP_DEBUG=0
COPY . /app
WORKDIR /app/ui/base/demo
# composer.lock is not in git, so the build resolves the packages. The cache:warmup step comes
# after phemc build, because composer update boots the kernel before the components exist.
RUN composer update --no-dev --optimize-autoloader --no-interaction --no-progress \
 && bin/console importmap:install \
 && phemc build src --no-warmup \
 && bin/console tailwind:build --minify \
 && rm -rf var/cache/prod \
 && bin/console cache:warmup \
 && bin/console asset-map:compile
COPY ui/base/demo/etc/php-prod.ini /usr/local/etc/php/conf.d/zz-demo.ini
CMD ["frankenphp", "run", "--config", "etc/Caddyfile"]
