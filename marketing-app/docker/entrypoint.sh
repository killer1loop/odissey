#!/bin/sh

set -eu

cd /app

php artisan config:cache --no-ansi >/dev/null
php artisan route:cache --no-ansi >/dev/null
php artisan view:cache --no-ansi >/dev/null

exec /usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
