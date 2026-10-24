#!/bin/sh
# Build phemc from the mounted sources, then run a command.
set -e
cargo build --release --quiet --manifest-path /app/compiler/Cargo.toml -p phemc-cli

case "$1" in
  test)
    cargo test --quiet --manifest-path /app/compiler/Cargo.toml
    cd /app
    composer install --quiet --no-interaction
    exec vendor/bin/phpunit
    ;;
  *)
    exec "$@"
    ;;
esac
