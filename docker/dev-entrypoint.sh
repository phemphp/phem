#!/bin/sh
# Dev entrypoint: build rhpc from the mounted sources, then run a command.
set -e
cargo build --release --quiet --manifest-path /app/compiler/Cargo.toml -p rhpc-cli

case "$1" in
  serve)
    cd /app/examples/quickstart
    # Build the .rhp files first: the Composer scripts boot the kernel, and a stale cache
    # from older sources can make it fail.
    rhpc build src --out var/cache/rhp --no-warmup
    composer install --quiet --no-interaction
    # rhpc restarts FrankenPHP after each build of the .rhp files. The workers also restart
    # when a PHP class in src/ changes (for example a repository).
    export FRANKENPHP_WORKER_CONFIG="${FRANKENPHP_WORKER_CONFIG-watch ./src/**/*.php}"
    exec rhpc watch src --out var/cache/rhp --exec "frankenphp run --config Caddyfile"
    ;;
  test)
    cargo test --quiet --manifest-path /app/compiler/Cargo.toml
    cd /app/runtime
    composer install --quiet --no-interaction
    exec vendor/bin/phpunit
    ;;
  *)
    exec "$@"
    ;;
esac
