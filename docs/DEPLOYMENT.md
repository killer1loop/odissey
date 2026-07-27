# Deployment

## Runtime contract

The production image:

- listens on `0.0.0.0:8000`;
- runs FrankenPHP, a finite database-backed worker pool, Laravel's scheduler,
  and the media cleanup/lease supervisor;
- includes FFmpeg, FFprobe, SQLite, and the required PHP extensions;
- runs application processes as `www-data`;
- performs migrations and Laravel optimization at startup;
- reports health at `/up`.

All Dockerfile base images are pinned to immutable multi-architecture digests.
Refresh and review those digests deliberately when updating Composer, Node, or
FrankenPHP; a floating tag must not be merged.

Required persistent mount:

```text
/var/lib/odissey
```

This directory contains the SQLite database, WAL/SHM neighbors, generated
application key, and bounded cached artwork. Mount the directory, not only
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

Set `ODISSEY_LOCAL_SOURCE_ROOTS=/media` (or a comma-separated narrower set),
then add the mounted path under **Administration → Media sources**. S3 and
WebDAV sources are configured in the same screen and are probed for range/seek
capability before their first asynchronous scan.

Container startup reclaims only scans that were interrupted while queued or
running. The parallel-scan migration also marks every existing enabled local,
S3, and WebDAV source for one complete rescan, so catalogs produced by the old
serial scanner are rebuilt once after upgrading. Each attempt has a fresh claim
token; jobs left behind by an older attempt become safe no-ops.

The parallel-VOD migration similarly queues one refresh for every enabled
Xtream provider. Four bounded `iptv-vod` workers fetch series details while the
managed IPTV media source retains durable discovered, processed, and failed
counts. Normal container restarts do not trigger another complete provider
refresh.

SQLite runs with WAL, a busy timeout, and `IMMEDIATE` transaction mode. Keep
`DB_TRANSACTION_MODE=IMMEDIATE` for the database-backed parallel worker pool.
At startup, Odissey removes queued automatic caption lookups when neither SubDL
nor OpenSubtitles is configured; manual caption fetching remains available once
a provider is added.

## Environment

The image supplies secure production defaults. Set at least:

```text
APP_URL=https://media.example.com
ODISSEY_SETUP_TOKEN=a-long-random-one-time-secret
ODISSEY_RELEASE=the-deployed-tag-or-commit
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

The production image always marks session cookies `Secure`, `HttpOnly`, and
`SameSite=Lax`. Browser access to the application therefore requires the HTTPS
Traefik route. The loopback-only local Docker examples explicitly disable the
`Secure` flag so setup and login work over `http://localhost`; never carry that
override into an Internet-facing deployment.

Odissey accepts only the exact hostname from `APP_URL`, loopback health-check
hosts, and any additional exact operator-controlled hostnames listed in
`ODISSEY_TRUSTED_HOSTS`. Set `APP_URL` to the public HTTPS URL used by Traefik.
For an additional private ingress name, use a comma-separated list:

```text
ODISSEY_TRUSTED_HOSTS=media.internal.example
```

Useful limits:

```text
ODISSEY_MAX_TRANSCODES=1
ODISSEY_MAX_PENDING_TRANSCODES_PER_USER=3
ODISSEY_MAX_PENDING_TRANSCODES=50
ODISSEY_TRANSCODE_TTL_MINUTES=30
ODISSEY_TRANSCODE_MAX_BYTES=5368709120
ODISSEY_TRANSCODE_MIN_FREE_BYTES=268435456
ODISSEY_REMOTE_TRANSCODE_MAX_SOURCE_BYTES=3221225472
ODISSEY_REMOTE_STREAM_MAX_BYTES=34359738368
ODISSEY_REMOTE_STREAM_MAX_SECONDS=900
ODISSEY_REMOTE_STREAM_LEASE_SECONDS=915
ODISSEY_REMOTE_STREAM_USER_CONCURRENCY=4
ODISSEY_REMOTE_STREAM_SOURCE_CONCURRENCY=12
ODISSEY_REMOTE_STREAM_GLOBAL_CONCURRENCY=32
ODISSEY_MEDIA_ASSET_MAX_BYTES=10737418240
ODISSEY_MEDIA_ASSET_MIN_FREE_BYTES=268435456
ODISSEY_FFMPEG_THREADS=2
ODISSEY_FFMPEG_MAX_ALLOC_BYTES=268435456
ODISSEY_FFMPEG_MAX_PIXELS=33177600
ODISSEY_FFMPEG_MAX_VIDEO_BITRATE_KBPS=10000
ODISSEY_SOURCE_CATALOG_MAX_ITEMS=100000
ODISSEY_PLAYBACK_HISTORY_RETENTION_DAYS=365
IPTV_IMPORT_MEMORY_LIMIT_MB=768
IPTV_GUIDE_CHANNEL_LIMIT=20
```

Transient HLS is also pruned every ten minutes. The byte quota is enforced
during conversion even when the deployment platform does not mount the
transcode path as tmpfs.

Large Xtream live, VOD, series, and external-logo catalogs run in the single
background worker with a temporary memory budget clamped between 256 MiB and
1 GiB. The default is 768 MiB. Catalog response byte and row ceilings remain
enforced independently, and the importer releases the live catalog before
downloading VOD data.

Library discovery remains serial per source, while two 384 MiB media workers
probe and enrich separate files concurrently. Two 256 MiB enrichment workers
handle metadata and caption network work without blocking discovery or IPTV
synchronization. These fixed counts intentionally limit SQLite write
contention and peak memory.

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
  --env SESSION_SECURE_COOKIE=false \
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

Expected results are HTTP 200, nine `RUNNING` processes, all migrations
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
- stop-first deployment semantics for SQLite;
- `no-new-privileges`, all Linux capabilities dropped, a PID limit, and CPU /
  memory limits sized for the configured FFmpeg concurrency.

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

Built-in commands create and validate the database/key pair:

```sh
php artisan odissey:backup /safe/off-host/odissey.zip
```

The destination must be absolute, its parent directory must already exist, and
it must not be inside the application/build context, a web-served directory, or
resolve through a destination symlink. Backups fail closed while
`APP_PREVIOUS_KEYS` is configured because a
bundle containing only the active key could not decrypt older ciphertext.
Re-encrypt persisted encrypted fields with the active key before taking the
portable backup.

The ZIP is intentionally a self-contained recovery bundle and is not encrypted
inside the archive. It contains the application key and must be treated like a
plaintext copy of every stored credential: retain mode `0600`, transfer only
over an authenticated encrypted channel, and place it in encrypted off-host
storage.

Restore is deliberately offline. In the production container, stop every
database-using program, run the command with both confirmations, and restart
the container immediately:

```sh
docker exec odissey supervisorctl \
  -c /etc/supervisor/conf.d/odissey.conf \
  stop web queue queue-media-discovery queue-media-scan:* queue-media-enrichment:* scheduler media-supervisor
docker exec odissey php artisan odissey:restore \
  /safe/off-host/odissey.zip --force --offline
docker restart odissey
```

The command independently verifies the Supervisor state when its socket is
available, checkpoints SQLite, stages the database and key beside their target
files, validates the Odissey application identifier and applied migrations, and
swaps them only after validation. The manifest records `ODISSEY_RELEASE`; set it
to the deployed tag or commit for an auditable recovery artifact. Restore
accepts an older migration prefix that this image can migrate forward and
rejects unknown/newer migrations. It retains a matching
`database.sqlite.before-restore` and `app.key.before-restore` rollback pair.
When `APP_KEY` is managed by Dokploy, set it to the key belonging to the backup
before running restore; a mismatch fails before any live file is changed.

Before changing either live file, restore durably writes
`database.sqlite.restore-in-progress`. A normal success or successful rollback
removes it. If the container or host stops during the swap, the entrypoint sees
the marker and refuses to migrate or serve traffic. Keep the service offline,
inspect the live and `.before-restore` database/key files as pairs, restore one
matching pair, then remove the marker only after `PRAGMA integrity_check` and an
encrypted-setting check succeed. The entrypoint also refuses to invent a new
file-backed key when a non-empty database already exists.

Artwork and downloaded captions are regenerable caches and are not included in
the recovery archive. Restore invalidates their database references without
recursively deleting operator-configured paths; subsequent scans fetch and
replace the required cache files.
