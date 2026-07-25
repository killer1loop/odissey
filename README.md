# Odissey

Odissey is a self-hosted, server-rendered media browser and player for sources
you already control. It is being built with Laravel, SQLite, Blade, HTMX, and
FFmpeg, and is packaged as one Docker image.

> [!IMPORTANT]
> This repository currently contains the tested project foundation and
> implementation plan. Authentication, source adapters, catalog sync, and
> playback are not implemented yet.

## Product principles

- Source media stays in local read-only mounts, S3-compatible storage, WebDAV,
  or an external IPTV provider.
- Odissey persists only application metadata, encrypted connection settings,
  user state, and short-lived streaming derivatives.
- Every user's favorites, playback history, watched state, and resume position
  are independent.
- The interface is server rendered with progressively enhanced HTMX fragments.
- A single container runs the web server, finite background jobs, and scheduler.
- The SQLite data directory is persistent and the application runs as one
  replica.

## Local development

Requirements:

- PHP 8.3–8.5
- Composer 2
- Node.js 24
- SQLite

```sh
composer setup
composer dev
```

Open [http://localhost:8000](http://localhost:8000).

Run the checks:

```sh
composer test
npm run build
vendor/bin/pint --test
```

## Docker

```sh
docker compose up --build
```

The application listens on port `8080`. The Compose file persists the SQLite
database and generated application key in the `odissey-data` volume. Transient
HLS output uses tmpfs.

For a local media library, add a read-only bind mount such as
`/path/to/media:/media/movies:ro`; never mount source media read-write.

## Deployment flow

Dokploy builds the repository Dockerfile and deploys automatically whenever a
commit reaches `main`. Feature branches do not deploy, and this repository does
not use GitHub Actions. See the deployment guide for the single-replica SQLite
and persistent-volume requirements.

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Implementation plan](docs/IMPLEMENTATION_PLAN.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Security model](docs/SECURITY.md)

## Credentials

Never commit provider, S3, WebDAV, or deployment credentials. Live integration
tests read secrets from the test environment and sanitize all output. Synthetic
fixtures are the default test path.

## License

[MIT](LICENSE)
