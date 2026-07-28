# Security model

Odissey handles credentials for systems that can expose private media. The
default posture is least privilege, read-only source access, and no content
ingestion.

## Secrets

- Provider, S3, WebDAV, deployment, and application credentials never belong in
  Git, image layers, build arguments, queue payloads, browser responses,
  screenshots, logs, or exception messages.
- Source settings are encrypted with Laravel's application key.
- Backups include both the database and matching key. The archive is a
  complete plaintext recovery bundle, not an independently encrypted vault;
  keep its `0600` permissions, move it only over an authenticated encrypted
  channel, and encrypt it at rest outside Odissey.
- Portable backups are refused while previous Laravel encryption keys remain
  configured; otherwise ciphertext that still needs an old key could become
  unrecoverable after restore.
- Test suites use synthetic fixtures by default and injected secrets for
  explicitly approved live tests.
- Every credential used before public release is rotated after testing.

## First launch

Allowing the first anonymous visitor to become admin is unsafe on an
Internet-facing deployment. First launch requires a one-time setup token
supplied as an environment secret. Production fails closed when it is absent.

Admin creation is a transaction guarded by:

- a valid setup token;
- zero existing users;
- an installation lock;
- rate limiting.

After success, the setup route is permanently disabled. Public registration
remains disabled; admins create or invite additional users.

## Outbound request and parser safety

Admin-configured URLs are an SSRF boundary:

- allow only required schemes;
- normalize and validate host/port;
- resolve and revalidate DNS after redirects;
- bound redirects, connection/read timeouts, response bytes, and concurrency;
- make private-network access an explicit setting because WebDAV commonly runs
  on a LAN;
- reject link-local metadata endpoints and unexpected protocol changes.

EPG XML is parsed incrementally with external entities disabled. Compressed and
decompressed bytes, element depth, rows, string lengths, and date ranges are
bounded.

Provider-supplied channel icon URLs are ignored. External IPTV-org catalog
documents and matched logo images are fetched over HTTPS with public-IP
pinning, redirect and byte limits, raster-image validation, and authenticated
same-origin delivery. SVG logos are excluded.

## Media processing

- FFmpeg and FFprobe receive an argument array, not a shell command.
- Input protocols, filters, codecs, threads, resolution, bitrate, lifetime, and
  concurrent sessions are restricted.
- FFmpeg processes run unprivileged and belong to a supervised process group.
- Artwork variants use exact-key generation locks and a shared global FFmpeg
  admission limit; a busy cache miss serves the original image.
- Buffered JSON, M3U, XMLTV, S3, and WebDAV catalog documents have immutable
  hard ceilings sized for the container's PHP memory limit. Environment values
  may lower these limits but cannot raise them past the safe ceiling.
- Source mounts are read-only.
- Transient HLS directories use unguessable session identifiers and strict
  permissions.
- Direct source streams hold global, per-source, and per-user admission leases
  until completion or disconnect, with a hard request lifetime below the cache
  lock TTL.
- Every manifest and segment request rechecks the authenticated owner and
  expiry.
- Cache cleanup is both sliding-window and TTL based.

## Browser boundary

Origin credentials and credential-bearing URLs never reach the browser. IPTV
manifests, segments, redirects, and key URLs are rewritten through opaque stream
sessions. S3 direct play may use a short-lived signed URL only when it does not
grant broader access.

State-changing requests require CSRF protection. Sessions are regenerated after
authentication and use secure, HTTP-only, same-site cookies in production.
Authorization is enforced in policies, not only hidden in the interface.

Native playback URLs carry a random bearer grant in their path because
AVFoundation must be able to fetch HLS resources without the JSON API access
token. The grant has a rolling window of at most ten minutes. Re-resolving the
same media item or channel from the same native client session revokes the
previous grant before returning a replacement; grants for another resource or
device session are unaffected. Heartbeats can move expiry at most ten minutes
forward and never beyond the native client session's refresh-token expiry.

Request paths under `/api/v1/playback/assets/` are credentials and must not be
written to access logs, traces, analytics, exception context, or support
captures. The bundled Caddy configuration deliberately leaves HTTP access
logging disabled. An upstream proxy does not inherit that protection.

## Transport

Odissey should be exposed only through HTTPS. Dokploy/Traefik or another reverse
proxy terminates TLS in production. A provider that supports only plaintext
HTTP receives a prominent warning and explicit admin opt-in because credentials
and streams can otherwise be intercepted between Odissey and the provider.

## Reporting vulnerabilities

Use the private vulnerability-reporting instructions in the repository-root
`SECURITY.md`. Do not report live credentials or exploitable details in a
public issue.
