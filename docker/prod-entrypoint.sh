#!/bin/sh
# Prod entrypoint: make the database, then run the command with the entrypoint of the
# FrankenPHP image. With the default command (the server), also run the Messenger worker of the
# #[Async] handlers.
set -e
init-db
if [ "$1" != frankenphp ]; then
  exec docker-php-entrypoint "$@"
fi
set +e
# The loop starts the worker again when it stops (its time or memory limit, or an error). On
# SIGTERM, messenger:consume ends the message that runs, then stops.
(
  trap 'kill -TERM "$pid" 2>/dev/null; wait "$pid"; exit 0' TERM
  while true; do
    php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M --quiet &
    pid=$!
    wait "$pid" || sleep 2
  done
) &
worker=$!
docker-php-entrypoint "$@" &
server=$!
# `docker stop` sends SIGTERM to this shell (PID 1): it sends it to the server and the worker.
trap 'kill -TERM "$server" "$worker" 2>/dev/null' TERM INT
wait "$server"
status=$?
# A signal stops `wait` before the end of the server: wait again.
while kill -0 "$server" 2>/dev/null; do
  wait "$server"
  status=$?
done
kill -TERM "$worker" 2>/dev/null
wait "$worker"
exit "$status"
