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
        storage/logs \
    && touch database/database.sqlite
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

FROM node:24-bookworm-slim@sha256:6f7b03f7c2c8e2e784dcf9295400527b9b1270fd37b7e9a7285cf83b6951452d AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM dunglas/frankenphp:1-php8.5-trixie@sha256:da270879b95225345b2ee984f717aef5cba7336e1f206ec005074a79235af347 AS runtime

ENV APP_NAME="Odissey" \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=https://marketing.odissey.garavelli.io \
    LOG_CHANNEL=stderr \
    LOG_STACK=stderr \
    SESSION_DRIVER=cookie \
    SESSION_SECURE_COOKIE=true \
    SESSION_HTTP_ONLY=true \
    SESSION_SAME_SITE=lax \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    SERVER_NAME=:8000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
    && install-php-extensions \
        intl \
        mbstring \
        opcache \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

COPY --chown=www-data:www-data artisan ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/marketing-entrypoint
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

RUN mkdir -p \
        bootstrap/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /data/caddy \
        /config/caddy \
    && chown -R www-data:www-data bootstrap storage /data/caddy /config/caddy

EXPOSE 8000

STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["curl", "--fail", "--silent", "http://127.0.0.1:8000/health"]

USER www-data:www-data

ENTRYPOINT ["/usr/local/bin/marketing-entrypoint"]
