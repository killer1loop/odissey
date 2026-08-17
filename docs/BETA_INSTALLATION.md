# Beta installation and operations

This guide is for a single beta server running one Odissey container. Odissey
does not provide media or IPTV service. Connect only sources and providers that
you are authorized to use.

Odissey is beta software. Keep a verified backup, do not expose port `8000`
directly to the Internet, and install only the immutable release tag or full
commit supplied by the maintainer. Do not deploy a moving `main` branch.

## 1. Server requirements

- 64-bit Linux on `amd64` or `arm64`.
- A current Docker Engine and Docker Compose plugin. Use Docker's
  [official Engine installation guide](https://docs.docker.com/engine/install/)
  and [Compose plugin guide](https://docs.docker.com/compose/install/linux/).
- `git`, `curl`, `openssl`, and `unzip` available on the host.
- Caddy for the documented host proxy, or an existing TLS reverse proxy. Use
  Caddy's [official installation instructions](https://caddyserver.com/docs/install/).
- Four CPU cores and 12 GiB host RAM recommended for the fixed worker pool and
  one concurrent FFmpeg conversion.
- At least 80 GiB free local disk in addition to source media. Transient HLS
  output and FFmpeg seek caches are bounded and pruned automatically.
- A domain name pointing to the server, with inbound TCP ports 80 and 443
  available to the HTTPS reverse proxy.
- Local block storage for the Odissey data volume. Do not put SQLite on NFS.

Use one application replica. Odissey's SQLite database, generated encryption
key, metadata, artwork, and captions live in the `odissey-data` Docker volume.
Source media remains in the configured local, S3, WebDAV, or IPTV source.

## 2. Install a pinned version

```sh
git clone https://github.com/killer1loop/odissey.git
cd odissey
git fetch --tags
RELEASE="<release-tag-or-full-commit-from-the-maintainer>"
git checkout --detach "$RELEASE"
git rev-parse HEAD
```

Record the printed commit with the beta test report.

Create the private runtime configuration:

```sh
cp .env.docker.example .env.docker
chmod 600 .env.docker
openssl rand -hex 32
```

Edit `.env.docker` and:

1. Set `APP_URL` to the final `https://` URL.
2. Paste the generated 64-character value into `ODISSEY_SETUP_TOKEN`.
3. Set `ODISSEY_RELEASE` to the full commit printed by `git rev-parse HEAD`.
4. Leave `SESSION_SECURE_COOKIE=true` for an Internet-facing server.

The example file deliberately defaults to `http://localhost:8000`, non-secure
cookies, and an empty setup token so an unedited copy cannot expose a
claimable administrator setup. Never commit or send `.env.docker`; it is
ignored by Git.

The supplied limits reserve 8 GiB for the container. Transient HLS and FFmpeg
seek-cache files use bounded disposable local disk. Do not lower the container
limit without measuring the fixed worker pool and FFmpeg peak memory. Monitor
Docker disk usage or mount the transcode path on dedicated local ephemeral
storage for stricter isolation.

## 3. Build and start

```sh
docker compose --env-file .env.docker build --pull
docker compose --env-file .env.docker up -d \
  --wait --wait-timeout 180
docker compose --env-file .env.docker ps
curl --fail http://127.0.0.1:8000/up
docker compose --env-file .env.docker exec -T app \
  supervisorctl -c /etc/supervisor/conf.d/odissey.conf status
```

The health request must return HTTP 200 and all 14 Supervisor processes must
show `RUNNING`. `docker compose down` retains the named data volume.
`docker compose down -v` permanently deletes it and must not be used on a
server containing beta data.

### HTTPS with Caddy

The supplied Compose service binds only to `127.0.0.1:8000`. A simple host
Caddy configuration is:

```caddyfile
media.example.com {
    reverse_proxy 127.0.0.1:8000
}
```

Replace the hostname with the value in `APP_URL`, save it in
`/etc/caddy/Caddyfile`, validate it, and reload Caddy:

```sh
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

Caddy obtains and renews certificates when DNS and ports 80/443 are correct;
see its [automatic HTTPS](https://caddyserver.com/docs/automatic-https) and
[reverse proxy](https://caddyserver.com/docs/quick-starts/reverse-proxy)
documentation.

An existing Traefik or Dokploy installation may instead route HTTPS to internal
port `8000`; keep one replica and do not publish a second public host port.
Exclude or redact `/api/v1/playback/assets/*` from proxy access logs because
these paths contain short-lived bearer grants. See
[Deployment](DEPLOYMENT.md) for the full proxy security contract.

## 4. Create the first administrator

Open `https://your-domain.example/setup`, enter the one-time setup token, and
create a unique administrator password of at least 12 characters. Confirm that:

- `/setup` returns 404 after the account is created;
- anonymous visits show only the login page;
- the administrator can sign out and sign in again.

Then clear the token value in `.env.docker`:

```text
ODISSEY_SETUP_TOKEN=
```

Recreate the container and verify health:

```sh
docker compose --env-file .env.docker up -d \
  --wait --wait-timeout 180
curl --fail http://127.0.0.1:8000/up
```

Setup remains permanently closed by database state.

## 5. Add media sources

Open **Administration → Media sources**.

### Local read-only files

Save this as `compose.override.yaml` beside `compose.yaml`. That exact filename
is loaded automatically by Docker Compose and is ignored by this repository:

```yaml
services:
  app:
    volumes:
      - /srv/media:/media:ro
```

Recreate and verify the service:

```sh
docker compose --env-file .env.docker up -d \
  --wait --wait-timeout 180
```

Create a Local source such as `/media/movies`. The host mount must be readable
by container UID/GID `33` and must remain read-only.

### S3-compatible storage

Enter the HTTPS service endpoint, bucket, optional object-key prefix, region,
access key, and secret key shown by the form. The endpoint must not include
the bucket name: Odissey sends path-style requests as
`<endpoint>/<bucket>/<object-key>` and does not expose a path-style toggle.
Use a dedicated read-only credential with only:

- `s3:ListBucket` on the selected bucket, restricted to the configured prefix;
- `s3:GetObject` on objects below that prefix.

Do not reuse an account that can upload, overwrite, or delete objects.

### WebDAV

Enter the complete collection URL, username, and password shown by the form.
There is no separate WebDAV prefix field: include the desired collection path
in the URL. Use a read-only account. The server must support depth-one
`PROPFIND` and ranged `GET` requests without relying on redirects. Enable
private-network access only for a deliberately selected LAN endpoint that
cannot be represented by a public HTTPS address.

### IPTV

Choose one of:

- **Xtream-compatible:** base URL, username, password, and connection limit.
- **Generic M3U + XMLTV:** playlist URL, optional XMLTV guide URL, and
  connection limit. Xtream credentials are not required.

Plain HTTP sends provider credentials and media unencrypted. Enable the HTTP
consent checkbox only when the provider offers no HTTPS service and the risk is
accepted. After saving, monitor the provider catalog and guide status. EPG
refresh runs hourly.

Odissey rejects a completely empty replacement for an established IPTV
catalog, category list, series, or guide. It cannot reliably distinguish a
valid provider-side removal from a syntactically valid but truncated non-empty
catalog response. Keep a recent backup, investigate an unexpected large item
drop, and re-run synchronization after the provider is stable.

For every source, wait for discovery and processing counts to stop changing,
open several movies and episodes, verify artwork/captions, and test both a
direct-play item and an item that requires HLS conversion.

Remote S3/WebDAV technical probing is deliberately bounded. A scan probes at
most 250 previously unprobed items, reads no more than the first 16 MiB of each,
and waits 30 days before retrying an unchanged item whose probe failed. The
item is still cataloged from its extension. Files whose MP4 metadata is only at
the end may therefore use conservative HLS conversion until a later successful
probe; this is a beta limitation, not permission to increase the limits without
measuring storage-provider traffic.

## 6. Back up before every change

The portable backup contains both the database and application encryption key.
Treat it as a plaintext copy of all stored credentials.

```sh
sudo install -d -m 0700 -o "$(id -u)" -g "$(id -g)" \
  /srv/odissey-backups
BACKUP_FILE="/srv/odissey-backups/odissey-$(date -u +%Y%m%dT%H%M%SZ).zip"
docker compose --env-file .env.docker exec -T app \
  php artisan odissey:backup /tmp/odissey-backup.zip
docker compose --env-file .env.docker cp \
  app:/tmp/odissey-backup.zip "$BACKUP_FILE"
chmod 600 "$BACKUP_FILE"
unzip -t "$BACKUP_FILE"
unzip -p "$BACKUP_FILE" manifest.json
docker compose --env-file .env.docker exec -T app \
  rm -f /tmp/odissey-backup.zip
```

The backup directory is deliberately outside the Git checkout and Docker build
context. Record the path and the manifest's `application_version`, then move
the verified archive to encrypted off-host storage. Artwork and downloaded
captions are regenerable and are not included.

## 7. Upgrade

1. Create and verify a backup.
2. Record `git rev-parse HEAD`.
3. Fetch and check out the new immutable release.
4. Build the replacement image before changing the running container.
5. Recreate the single replica and run every verification below.

```sh
git fetch --tags
NEW_RELEASE="<new-release-tag-or-full-commit>"
git checkout --detach "$NEW_RELEASE"
NEW_COMMIT="$(git rev-parse HEAD)"
grep -q '^ODISSEY_RELEASE=' .env.docker
sed -i "s/^ODISSEY_RELEASE=.*/ODISSEY_RELEASE=${NEW_COMMIT}/" .env.docker
docker compose --env-file .env.docker build --pull
docker compose --env-file .env.docker up -d --remove-orphans \
  --wait --wait-timeout 180
curl --fail http://127.0.0.1:8000/up
docker compose --env-file .env.docker exec -T app php artisan migrate:status
docker compose --env-file .env.docker exec -T app \
  supervisorctl -c /etc/supervisor/conf.d/odissey.conf status
docker compose --env-file .env.docker exec -T app \
  sqlite3 /var/lib/odissey/database.sqlite \
  'PRAGMA integrity_check; PRAGMA journal_mode; PRAGMA foreign_key_check;'
```

Expected SQLite output starts with `ok` and `wal`, with no foreign-key rows.
Also verify browser login, one direct-play asset, one converted asset, one Live
TV channel, EPG data, and resume progress before declaring the upgrade healthy.

## 8. Restore or roll back

Application rollback after a database migration may be unsafe. Never point old
code at a newer database. For a rollback, read `manifest.json` with
`unzip -p`, check out the exact full commit recorded when that backup was
created, update `ODISSEY_RELEASE` in `.env.docker`, and build it **without
recreating the running container yet**:

```sh
BACKUP_FILE="/srv/odissey-backups/<verified-backup>.zip"
unzip -p "$BACKUP_FILE" manifest.json
ROLLBACK_RELEASE="<full-commit-recorded-for-that-backup>"
git fetch --tags
git checkout --detach "$ROLLBACK_RELEASE"
ROLLBACK_COMMIT="$(git rev-parse HEAD)"
grep -q '^ODISSEY_RELEASE=' .env.docker
sed -i "s/^ODISSEY_RELEASE=.*/ODISSEY_RELEASE=${ROLLBACK_COMMIT}/" .env.docker
docker compose --env-file .env.docker build --pull
```

Do not continue unless the manifest's `application_version` identifies the
same commit. If the backup predates exact-commit release recording, ask the
maintainer to map its recorded tag to a commit before proceeding.

For an in-place restore that keeps the current application release, skip the
checkout/build block and select a backup compatible with the current release.

Stream the archive as the image's `www-data` user, stop every database-using
process, restore offline, remove the temporary plaintext archive, and recreate
the container from the selected image. Stop immediately if any command fails;
do not restart a partially restored service:

```sh
set -eu
BACKUP_FILE="/srv/odissey-backups/<verified-backup>.zip"
docker compose --env-file .env.docker exec -T app sh -c \
  'umask 077; cat > /tmp/odissey-restore.zip' < "$BACKUP_FILE"
docker compose --env-file .env.docker exec -T app \
  supervisorctl -c /etc/supervisor/conf.d/odissey.conf \
  stop web queue queue-transcodes 'queue-iptv-vod:*' \
  queue-media-discovery 'queue-media-scan:*' \
  'queue-media-enrichment:*' scheduler media-supervisor
docker compose --env-file .env.docker exec -T app \
  php artisan odissey:restore /tmp/odissey-restore.zip --force --offline
docker compose --env-file .env.docker exec -T app \
  rm -f /tmp/odissey-restore.zip
docker compose --env-file .env.docker up -d --force-recreate \
  --wait --wait-timeout 180
```

Repeat the complete upgrade verification after the restart. If restore reports
an interrupted-operation marker, keep the service offline and follow
[Deployment](DEPLOYMENT.md); do not remove the marker until a matching
database/key pair has passed integrity and encrypted-setting checks.

## 9. Beta diagnostics and reporting

Useful read-only diagnostics:

```sh
docker compose --env-file .env.docker logs --tail=200 app
docker compose --env-file .env.docker exec -T app php artisan schedule:list
docker compose --env-file .env.docker exec -T app ffmpeg -version
docker version
docker compose version
```

A beta report should include the Odissey commit, host architecture, Docker and
Compose versions, the affected source type, exact reproduction steps, and
redacted logs. Use the repository's
[beta bug report form](https://github.com/killer1loop/odissey/issues/new/choose).

Never send provider credentials, source URLs with tokens, `.env.docker`, the
SQLite database, a backup archive, the application key, or paths under
`/api/v1/playback/assets/`. Replace private hostnames, usernames, object keys,
and media titles in logs before sharing them.
