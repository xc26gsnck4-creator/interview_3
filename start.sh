#!/usr/bin/env bash
set -euo pipefail

# Not cp -n: some cp versions (BSD cp on macOS among them) exit 1 when .env exists,
# and set -e would stop here.
[ -f .env ] || cp .env.example .env
touch database/database.sqlite

docker compose up --build --remove-orphans -d
docker compose exec -T app composer install
# The server started with the container, before composer install, died without
# vendor/ and supervisord gave up on it, so it is started again.
docker compose restart app
docker compose exec -T app php artisan migrate:fresh --seed
