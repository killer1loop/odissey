#!/bin/sh

set -eu

cd /app

if [ -z "${APP_KEY:-}" ]; then
    key_file="/data/app.key"
    umask 077

    if [ ! -s "$key_file" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$key_file"
    fi

    APP_KEY="$(cat "$key_file")"
    export APP_KEY
fi

touch "${DB_DATABASE:-/data/database.sqlite}"
php artisan migrate --force --no-ansi >/dev/null
php artisan config:cache --no-ansi >/dev/null
php artisan route:cache --no-ansi >/dev/null
php artisan view:cache --no-ansi >/dev/null

exec /usr/local/bin/frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
