#!/bin/sh
# Dev entrypoint: build rhpc from the mounted sources, then run a command.
set -e
cargo build --release --quiet --manifest-path /app/compiler/Cargo.toml -p rhpc-cli

case "$1" in
  serve)
    cd /app/examples/quickstart
    composer install --quiet --no-interaction
    exec rhpc watch src --out var/cache/rhp \
      --exec "php bin/console rephp:serve --host=0.0.0.0 --port=8080 --mount=/examples-src/=src/pages/examples --mount=/components-src/=src/components --mount=/repository-src/=src/Repository"
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
