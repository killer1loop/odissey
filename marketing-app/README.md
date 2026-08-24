# Odissey Marketing Website

Standalone Laravel application serving the public marketing site
(Aurora design) with Blade templates, Tailwind CSS 4 and HTMX.

## Local development

    composer install
    cp .env.example .env && php artisan key:generate
    npm install
    npm run build
    php artisan serve

## Deployment

Dokploy builds `Dockerfile` from this directory (`buildPath: marketing-app`)
on every push to `main` and serves it via Traefik with Let's Encrypt TLS.
Required runtime environment: `APP_KEY`, optional `APP_URL`.
