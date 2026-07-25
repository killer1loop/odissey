# Deployment

## Runtime contract

The production image:

- listens on `0.0.0.0:8000`;
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
ODISSEY_SETUP_TOKEN=a-long-random-one-time-secret
```

You may supply `APP_KEY` through a secret manager. If omitted, the entrypoint
creates `/var/lib/odissey/app.key` with mode `0600`; preserve it with the data
volume.

Production first launch fails closed when `ODISSEY_SETUP_TOKEN` is empty. Keep
the token in the runtime environment until the first administrator has been
created. It is never accepted again after setup completes.

Odissey trusts forwarded request metadata only from private reverse-proxy
networks. The default covers RFC 1918 and IPv6 ULA container networks. Narrow it
to the actual Traefik network when practical, or override it with a
comma-separated list:

```text
ODISSEY_TRUSTED_PROXIES=172.20.0.0/16
```

The proxy must overwrite or append the real client address to
`X-Forwarded-For`. Direct public access to port `8000` is unsupported because it
bypasses the TLS boundary; untrusted direct peers cannot influence Laravel's
client IP with forwarded headers.

Useful limits:

```text
ODISSEY_MAX_TRANSCODES=1
ODISSEY_TRANSCODE_TTL_MINUTES=30
ODISSEY_TRANSCODE_MAX_BYTES=5368709120
IPTV_GUIDE_CHANNEL_LIMIT=20
```

Transient HLS is also pruned every ten minutes. The byte quota is enforced
during conversion even when the deployment platform does not mount the
transcode path as tmpfs.

Never use provider or storage secrets as Docker build arguments. They must be
runtime secrets and later be stored encrypted through Odissey's admin UI.

## Legacy user recovery

The access-control migration intentionally does not guess which account on an
existing installation should become administrator. It closes anonymous setup
when legacy users exist. Promote one explicitly selected account from the
server:

```sh
docker exec odissey php artisan \
  odissey:user:promote-admin existing-user@example.com --force
```

Production requires `--force`. The command fails when the email is missing or
ambiguous, reactivates only the named account, and marks first-launch setup
complete. It never promotes the first user implicitly.

## Docker

```sh
docker build -t odissey:test .
docker run --detach \
  --name odissey \
  --publish 127.0.0.1:8000:8000 \
  --volume odissey-data:/var/lib/odissey \
  --tmpfs /var/cache/odissey/transcodes:uid=33,gid=33,mode=0750,size=4g \
  --env APP_URL=http://localhost:8000 \
  --env ODISSEY_SETUP_TOKEN=replace-with-a-long-random-secret \
  odissey:test
```

Verify:

```sh
curl --fail http://127.0.0.1:8000/up
docker exec odissey supervisorctl -c /etc/supervisor/conf.d/odissey.conf status
docker exec odissey php artisan migrate:status
docker exec odissey sqlite3 /var/lib/odissey/database.sqlite \
  'PRAGMA integrity_check; PRAGMA journal_mode; PRAGMA foreign_key_check;'
```

Expected results are HTTP 200, three `RUNNING` processes, all migrations
applied, `ok`, `wal`, and no foreign-key errors.

## Dokploy

Use an Application with:

- source provider: the existing Dokploy GitHub App;
- repository: `killer1loop/odissey`;
- branch: `main`;
- automatic deployment on GitHub push enabled;
- build type: Dockerfile;
- context: `.`;
- Dockerfile: `Dockerfile`;
- internal port: `8000`;
- one replica;
- a named volume mounted at `/var/lib/odissey`;
- a TLS-enabled domain routed through Traefik;
- stop-first deployment semantics for SQLite.

Do not publish a Docker host port when Traefik routing is sufficient. A
published port can bypass host firewall expectations.

Dokploy is the deployment authority for this project. A merge or direct push
to `main` triggers a fresh server-side Dockerfile build and deployment. Feature
branches, including `codex/**`, do not deploy. GitHub Actions is intentionally
not used, which keeps application build logs and rollout state in one place.
The connected GitHub App supplies the push webhook; do not add a separate
repository webhook.

The API configuration for Dokploy `0.29.13` is:

1. Save the existing GitHub provider with `branch: main`, `triggerType: push`,
   `buildPath: /`, and no `watchPaths` restriction through
   `POST /api/application.saveGithubProvider`.
2. Set `autoDeploy: true` explicitly through
   `POST /api/application.update`.
3. Set `buildType: dockerfile`, `dockerfile: Dockerfile`, and
   `dockerContextPath: .` through `POST /api/application.saveBuildType`.
4. Read the application back through `GET /api/application.one` and verify the
   provider, repository, branch, push trigger, build type, and auto-deploy flag
   before merging to `main`.
5. After the first merge, verify that the Dokploy deployment commit matches the
   new `main` commit and that `/up` is healthy.

Before API automation:

1. Verify the panel runs Dokploy `0.29.13` or newer.
2. Create a fresh, narrowly scoped API key.
3. Keep it outside the repository and shell history.
4. Read the exact project and environment before creating an application.
5. Merge, rather than replace, an existing application's runtime environment.
6. Avoid any delete/remove endpoint during deployment testing.

Before enabling the first automatic deployment, the Dokploy GitHub App must
have access to this repository. Keep the repository private throughout testing.
After the application is public, retain the same `main`-branch trigger and
record the source commit for each release and rollback.

## Backup and restore

Use SQLite's online backup API, `.backup`, or `VACUUM INTO`; do not copy only a
live main database file. A usable backup consists of:

- the consistent SQLite backup;
- the matching application key;
- a record of the application version.

Restore into a new data volume, run migrations, perform
`PRAGMA integrity_check`, and verify an encrypted test setting before switching
traffic.
