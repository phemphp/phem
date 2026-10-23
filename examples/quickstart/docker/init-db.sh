#!/bin/sh
# Make the database of the quickstart. Run it in the quickstart directory. A new database
# gets the tables from the entity mapping and the fixtures. An existing database keeps its
# rows, and it gets the tables of new entities.
set -e
mkdir -p var/data   # SQLite makes the file, but not its directory
if ! php bin/console dbal:run-sql --quiet 'SELECT 1 FROM scientist' 2>/dev/null; then
    php bin/console doctrine:schema:create --quiet
    php bin/console doctrine:fixtures:load --no-interaction --quiet
else
    php bin/console doctrine:schema:update --force --quiet
fi
