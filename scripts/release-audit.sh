#!/bin/sh
set -eu

php artisan test
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction
npm ci --ignore-scripts
npm run build
npm audit --audit-level=high
php artisan optimize
php artisan odissey:sbom

if command -v gitleaks >/dev/null 2>&1; then
    gitleaks git --redact --no-banner
else
    echo "gitleaks is required for the complete-history release scan." >&2
    exit 2
fi

if command -v docker >/dev/null 2>&1; then
    docker buildx build --platform linux/amd64,linux/arm64 --check .
else
    echo "Docker buildx is required for multi-architecture validation." >&2
    exit 2
fi
