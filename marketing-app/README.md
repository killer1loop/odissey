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
The production image defaults `APP_URL` to `https://odissey.app`; override it
for another public HTTPS address. You may supply `APP_KEY`; otherwise
the entrypoint generates it once in `/data/app.key`. Persist `/data` so the key
and launch signups survive image replacements. The supplied Compose definition
uses the `odissey-website-data` named volume for that purpose.
