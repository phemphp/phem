#!/bin/sh
# Dev entrypoint: build mamuc from the mounted sources, then run a command.
set -e
cargo build --release --quiet --manifest-path /app/compiler/Cargo.toml -p mamuc-cli

case "$1" in
  serve)
    cd /app/examples/quickstart
    # Build the .phpm files first: the Composer scripts boot the kernel, and a stale cache
    # from older sources can make it fail.
    mamuc build src --out var/cache/mamu --no-warmup
    composer install --quiet --no-interaction
    /app/docker/init-db.sh
    # mamuc restarts FrankenPHP after each build of the .phpm files. The workers also restart
    # when a PHP class in src/ changes (for example a repository).
    export FRANKENPHP_WORKER_CONFIG="${FRANKENPHP_WORKER_CONFIG-watch ./src/**/*.php}"
    exec mamuc watch src --out var/cache/mamu --exec "frankenphp run --config Caddyfile"
    ;;
  test)
    cargo test --quiet --manifest-path /app/compiler/Cargo.toml
    cd /app/mamu
    composer install --quiet --no-interaction
    exec vendor/bin/phpunit
    ;;
  *)
    exec "$@"
    ;;
esac
