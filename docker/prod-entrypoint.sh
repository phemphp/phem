#!/bin/sh
# Prod entrypoint: make the database, then run the command with the entrypoint of the
# FrankenPHP image.
set -e
init-db
exec docker-php-entrypoint "$@"
