#!/bin/sh
# Caches the configuration, routes, views and events for this environment,
# then runs the given command (php-fpm, horizon, schedule:work, artisan…).
# Migrations are not run here on purpose: see docs/runbook.md, "Deploy".
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set: refusing to start." >&2
    exit 1
fi

php artisan config:cache --ansi
php artisan route:cache --ansi
php artisan view:cache --ansi
php artisan event:cache --ansi

exec "$@"
