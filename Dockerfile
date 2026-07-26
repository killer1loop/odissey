# syntax=docker/dockerfile:1.7

FROM composer:2.10@sha256:5946476338742b200bb9ff88f8be56275ddae4b3949c72305cb0dbf10cfcb760 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-autoloader \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist

COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY resources ./resources
COPY routes ./routes
RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

FROM node:24-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d AS frontend

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

FROM dunglas/frankenphp:1-php8.5-trixie@sha256:da270879b95225345b2ee984f717aef5cba7336e1f206ec005074a79235af347 AS runtime

ARG ODISSEY_RELEASE=development

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
    SESSION_SECURE_COOKIE=true \
    SESSION_HTTP_ONLY=true \
    SESSION_SAME_SITE=lax \
    CACHE_STORE=file \
    IPTV_LOCK_STORE=file \
    ODISSEY_RUNTIME_CACHE_STORE=file \
    QUEUE_CONNECTION=database \
    SERVER_NAME=:8000 \
    ODISSEY_DATA_PATH=/var/lib/odissey \
    ODISSEY_RELEASE=${ODISSEY_RELEASE} \
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
        xml \
        zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --chown=www-data:www-data artisan ./
COPY --chown=www-data:www-data composer.json composer.lock package-lock.json ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
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
        build \
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
        build \
        bootstrap/cache \
        storage

EXPOSE 8000

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["/usr/local/bin/odissey-healthcheck"]

USER www-data:www-data

ENTRYPOINT ["/usr/local/bin/odissey-entrypoint"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/odissey.conf"]
