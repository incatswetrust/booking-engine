#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress --prefer-dist
fi

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
    php artisan key:generate --ansi
fi

mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
