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

# Builds the / and /deployment pages' assets (resources/css, resources/js)
# on first boot -- the repo's whole directory is bind-mounted over this
# image at runtime, so anything baked into the image at build time would
# be shadowed by the host's (gitignored, empty) public/build anyway. Only
# runs once per clone; re-run manually (`npm run build`) after editing
# resources/css or resources/js.
if [ ! -f public/build/manifest.json ]; then
    npm ci --no-audit --no-fund
    npm run build
fi

mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
