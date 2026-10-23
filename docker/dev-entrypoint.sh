#!/bin/sh
# Dev entrypoint: build phemc from the mounted sources, then run a command.
set -e
cargo build --release --quiet --manifest-path /app/compiler/Cargo.toml -p phemc-cli

case "$1" in
  serve)
    cd /app/examples/quickstart
    # Build the .phpm files first: the Composer scripts boot the kernel, and a stale cache
    # from older sources can make it fail.
    phemc build src --out var/cache/phem --no-warmup
    composer install --quiet --no-interaction
    /app/docker/init-db.sh
    # phemc restarts FrankenPHP after each build of the .phpm files. The workers also restart
    # when a PHP class in src/ changes (for example a repository).
    export FRANKENPHP_WORKER_CONFIG="${FRANKENPHP_WORKER_CONFIG-watch ./src/**/*.php}"
    # The Messenger worker of the #[Async] handlers. It stops after each message, so the loop
    # starts it again with the new code.
    (while true; do php bin/console messenger:consume async --limit=1 --time-limit=60 --quiet || sleep 2; done) &
    exec phemc watch src --out var/cache/phem --exec "frankenphp run --config Caddyfile"
    ;;
  test)
    cargo test --quiet --manifest-path /app/compiler/Cargo.toml
    cd /app/phem
    composer install --quiet --no-interaction
    exec vendor/bin/phpunit
    ;;
  *)
    exec "$@"
    ;;
esac
