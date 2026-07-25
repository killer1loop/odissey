# Deployment

## Runtime contract

The production image:

- listens on `0.0.0.0:8080`;
- runs FrankenPHP, one finite database queue worker, and Laravel's scheduler;
- includes FFmpeg, FFprobe, SQLite, and the required PHP extensions;
- runs application processes as `www-data`;
- performs migrations and Laravel optimization at startup;
- reports health at `/up`.

Required persistent mount:

```text
/var/lib/odissey
```

This directory contains the SQLite database, WAL/SHM neighbors, and a generated
application key when `APP_KEY` is not supplied. Mount the directory, not only
the database file. Use local block storage or a Docker volume, never NFS.

Recommended transient mount:

```text
/var/cache/odissey/transcodes
```

Use tmpfs or disposable local storage with a strict size limit. It is not part
of backups.

Optional local media mounts are read-only:

```text
/host/movies:/media/movies:ro
/host/music:/media/music:ro
```

## Environment

The image supplies secure production defaults. Set at least:

```text
APP_URL=https://media.example.com
```

You may supply `APP_KEY` through a secret manager. If omitted, the entrypoint
creates `/var/lib/odissey/app.key` with mode `0600`; preserve it with the data
volume.

Useful limits:

```text
ODISSEY_MAX_TRANSCODES=1
ODISSEY_TRANSCODE_TTL_MINUTES=30
```

Never use provider or storage secrets as Docker build arguments. They must be
runtime secrets and later be stored encrypted through Odissey's admin UI.

## Docker

```sh
docker build -t odissey:test .
docker run --detach \
  --name odissey \
  --publish 8080:8080 \
  --volume odissey-data:/var/lib/odissey \
  --tmpfs /var/cache/odissey/transcodes:uid=33,gid=33,mode=0750,size=4g \
  --env APP_URL=http://localhost:8080 \
  odissey:test
```

Verify:

```sh
curl --fail http://127.0.0.1:8080/up
docker exec odissey supervisorctl -c /etc/supervisor/conf.d/odissey.conf status
docker exec odissey php artisan migrate:status
docker exec odissey sqlite3 /var/lib/odissey/database.sqlite \
  'PRAGMA integrity_check; PRAGMA journal_mode; PRAGMA foreign_key_check;'
```

Expected results are HTTP 200, three `RUNNING` processes, all migrations
applied, `ok`, `wal`, and no foreign-key errors.

## Dokploy

Use an Application with:

- build type: Dockerfile;
- context: `.`;
- Dockerfile: `Dockerfile`;
- internal port: `8080`;
- one replica;
- a named volume mounted at `/var/lib/odissey`;
- a TLS-enabled domain routed through Traefik;
- stop-first deployment semantics for SQLite.

Do not publish a Docker host port when Traefik routing is sufficient. A
published port can bypass host firewall expectations.

Before API automation:

1. Verify the panel runs Dokploy `0.29.13` or newer.
2. Create a fresh, narrowly scoped API key.
3. Keep it outside the repository and shell history.
4. Read the exact project and environment before creating an application.
5. Merge, rather than replace, an existing application's runtime environment.
6. Avoid any delete/remove endpoint during deployment testing.

For private testing, building from the repository Dockerfile on the Dokploy
server is acceptable. For public releases, CI should build a versioned image,
push it to GHCR, and Dokploy should pull a pinned tag or digest.

## Backup and restore

Use SQLite's online backup API, `.backup`, or `VACUUM INTO`; do not copy only a
live main database file. A usable backup consists of:

- the consistent SQLite backup;
- the matching application key;
- a record of the application version.

Restore into a new data volume, run migrations, perform
`PRAGMA integrity_check`, and verify an encrypted test setting before switching
traffic.
