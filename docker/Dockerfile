# syntax=docker/dockerfile:1.7
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5
ARG RUST_IMAGE=rust:1-bookworm

# --- Build the rhpc compiler ---------------------------------------------
FROM ${RUST_IMAGE} AS rhpc-build
WORKDIR /src
COPY compiler/ ./
RUN --mount=type=cache,target=/usr/local/cargo/registry \
    --mount=type=cache,target=/src/target \
    cargo build --release -p rhpc-cli && cp target/release/rhpc /usr/local/bin/rhpc

# --- FrankenPHP (PHP 8.5) with Composer ------------------------------------
FROM ${FRANKENPHP_IMAGE} AS php-base
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# --- Dev: FrankenPHP + Rust toolchain (cargo, rhpc watch) ------------------
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

# --- Prod: compiled app only (rhpc + Symfony and Twig caches + OPcache preload)
FROM php-base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=rhpc-build /usr/local/bin/rhpc /usr/local/bin/rhpc
COPY runtime/ /app/runtime/
COPY examples/quickstart/ /app/examples/quickstart/
WORKDIR /app/examples/quickstart
# A random APP_SECRET for this image (it signs the component state in the browser).
# The prod container does not see changes: build it again after rhpc build. cache:warmup
# also compiles the rhpc templates into the Twig cache.
RUN printf 'APP_SECRET=%s\n' "$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')" > .env.local \
 && composer install --no-dev --optimize-autoloader --no-interaction \
 && rhpc build src --out var/cache/rhp --no-warmup \
 && rm -rf var/cache/prod \
 && php bin/console cache:warmup
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-rephp.ini
COPY docker/init-db.sh /usr/local/bin/init-db
COPY docker/prod-entrypoint.sh /usr/local/bin/prod-entrypoint
EXPOSE 8080
# The entrypoint makes the database in the volume on the first start.
ENTRYPOINT ["prod-entrypoint"]
# The Caddyfile runs public/index.php in FrankenPHP worker mode.
CMD ["frankenphp", "run", "--config", "Caddyfile"]
