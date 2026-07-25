# Odissey

Odissey is a self-hosted, server-rendered media browser and player for sources
you already control. It is being built with Laravel, SQLite, Blade, HTMX, and
FFmpeg, and is packaged as one Docker image.

The current vertical slice includes secure first-launch setup, multiple local
users, a transient local-video test library with direct and FFmpeg HLS
playback, and Xtream-compatible live IPTV with groups, short EPG data,
favorites, and credential-safe HLS proxying. Local-library indexing, S3,
WebDAV, music, generic M3U/XMLTV, and the complete player feature set remain on
the roadmap.

> [!CAUTION]
> Odissey does not supply media or IPTV service. Only connect sources and
> providers you are authorized to use.

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

## Implemented now

- one-time, production-token-protected first-admin setup;
- login, logout, admin-created users, disablement, and per-user authorization;
- direct MP4 range playback, resume position, and playback history;
- queued FFmpeg H.264/AAC HLS conversion with authenticated derivatives;
- encrypted Xtream provider settings and asynchronous catalog synchronization;
- channel groups, search, short EPG, per-user favorites, and opaque live HLS
  sessions;
- a Plex-inspired dark Blade interface enhanced with HTMX and hls.js;
- one Docker image containing FrankenPHP, SQLite, FFmpeg, the queue worker, and
  the scheduler.

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

In production, `ODISSEY_SETUP_TOKEN` is mandatory and setup fails closed when
it is missing. Development and test environments allow local first launch
without it.

## Docker

```sh
docker compose up --build
```

The application listens on port `8000`. The Compose file persists the SQLite
database and generated application key in the `odissey-data` volume. Transient
HLS output uses tmpfs.

For a local media library, add a read-only bind mount such as
`/path/to/media:/media/movies:ro`; never mount source media read-write.

The current UI does not index arbitrary mounted libraries yet. For an explicit
disposable playback test, generate synthetic fixtures for an existing user:

```sh
php artisan media:e2e:generate viewer@example.test --duration=30
php artisan media:e2e:clean
```

Both commands require `--force` in production. Fixtures and HLS derivatives
live outside the persistent data volume.

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
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)

## Credentials

Never commit provider, S3, WebDAV, or deployment credentials. Live integration
tests read secrets from the test environment and sanitize all output. Synthetic
fixtures are the default test path.

## License

[MIT](LICENSE)
