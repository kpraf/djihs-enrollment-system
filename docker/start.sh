#!/bin/sh
# Runs on every container start, which on Render means every deploy.
# Each step is safe to repeat.
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

php artisan migrate --force
php artisan db:seed --force
php artisan optimize
php artisan storage:link 2>/dev/null || true

exec apache2-foreground
