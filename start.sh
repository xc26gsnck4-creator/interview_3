#!/usr/bin/env bash
set -euo pipefail

cp -n .env.example .env
touch database/database.sqlite

docker compose up --build --remove-orphans -d
docker compose exec -T app composer install
docker compose exec -T app php artisan migrate:fresh --seed
