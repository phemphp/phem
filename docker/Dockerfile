# syntax=docker/dockerfile:1.7
ARG PHP_IMAGE=php:8.6-rc-cli
ARG RUST_IMAGE=rust:1-bookworm

# --- Build the rhpc compiler ---------------------------------------------
FROM ${RUST_IMAGE} AS rhpc-build
WORKDIR /src
COPY compiler/ ./
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/src/target \
    cargo build --release -p rhpc-cli && cp target/release/rhpc /usr/local/bin/rhpc

# --- PHP 8.6 base with Composer -------------------------------------------
FROM ${PHP_IMAGE} AS php-base
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libonig-dev \
 && docker-php-ext-install -j"$(nproc)" mbstring pcntl \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- Dev: PHP 8.6 + Rust toolchain (cargo, rhpc watch) ---------------------
FROM php-base AS dev
RUN apt-get update \
 && apt-get install -y --no-install-recommends gcc libc6-dev \
 && rm -rf /var/lib/apt/lists/*
COPY --from=rust:1-bookworm /usr/local/rustup /usr/local/rustup
COPY --from=rust:1-bookworm /usr/local/cargo /usr/local/cargo
ENV RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/usr/local/cargo \
    CARGO_TARGET_DIR=/cargo-target \
    PATH=/usr/local/cargo/bin:/cargo-target/release:$PATH
WORKDIR /app
# The repository is mounted at /app, so edits to the entrypoint apply without a rebuild.
ENTRYPOINT ["/app/docker/dev-entrypoint.sh"]
CMD ["serve"]

# --- Prod: compiled app only (rhpc + Twig cache + OPcache preload) ---------
FROM php-base AS prod
COPY --from=rhpc-build /usr/local/bin/rhpc /usr/local/bin/rhpc
COPY runtime/ /app/runtime/
COPY examples/quickstart/ /app/examples/quickstart/
WORKDIR /app/examples/quickstart
RUN composer install --no-dev --optimize-autoloader --no-interaction \
 && rhpc build src --out var/cache/rhp --prod
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-rephp.ini
EXPOSE 8080
CMD ["php", "vendor/bin/rephp", "serve", "--host=0.0.0.0", "--port=8080", "--mount=/examples-src/=src/pages/examples", "--mount=/components-src/=src/components"]
