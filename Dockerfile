# syntax=docker/dockerfile:1.7

FROM composer:2.10 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY . .
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

FROM node:24-bookworm-slim AS frontend

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM dunglas/frankenphp:1-php8.5-trixie AS runtime

ENV APP_NAME=Odissey \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_STACK=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/lib/odissey/database.sqlite \
    DB_BUSY_TIMEOUT=5000 \
    DB_JOURNAL_MODE=WAL \
    DB_SYNCHRONOUS=NORMAL \
    DB_QUEUE_RETRY_AFTER=720 \
    SESSION_DRIVER=database \
    SESSION_ENCRYPT=true \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    SERVER_NAME=:8000 \
    ODISSEY_DATA_PATH=/var/lib/odissey \
    ODISSEY_TRANSCODE_PATH=/var/cache/odissey/transcodes

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        ffmpeg \
        gosu \
        sqlite3 \
        supervisor \
    && install-php-extensions \
        curl \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_sqlite \
        sqlite3 \
        zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/odissey-entrypoint
COPY --chmod=755 docker/healthcheck.sh /usr/local/bin/odissey-healthcheck
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/supervisord.conf /etc/supervisor/conf.d/odissey.conf

RUN mkdir -p \
        /var/lib/odissey \
        /var/cache/odissey/transcodes \
        /var/cache/odissey/e2e \
        /config/caddy \
        /data/caddy \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && chown -R www-data:www-data \
        /var/lib/odissey \
        /var/cache/odissey \
        /config/caddy \
        /data/caddy \
        bootstrap/cache \
        storage

EXPOSE 8000

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["/usr/local/bin/odissey-healthcheck"]

ENTRYPOINT ["/usr/local/bin/odissey-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/odissey.conf"]
