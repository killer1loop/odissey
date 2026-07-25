# Security model

Odissey handles credentials for systems that can expose private media. The
default posture is least privilege, read-only source access, and no content
ingestion.

## Secrets

- Provider, S3, WebDAV, deployment, and application credentials never belong in
  Git, image layers, build arguments, queue payloads, browser responses,
  screenshots, logs, or exception messages.
- Source settings are encrypted with Laravel's application key.
- Backups include both the database and matching key.
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

## Media processing

- FFmpeg and FFprobe receive an argument array, not a shell command.
- Input protocols, filters, codecs, threads, resolution, bitrate, lifetime, and
  concurrent sessions are restricted.
- FFmpeg processes run unprivileged and belong to a supervised process group.
- Source mounts are read-only.
- Transient HLS directories use unguessable session identifiers and strict
  permissions.
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

## Transport

Odissey should be exposed only through HTTPS. Dokploy/Traefik or another reverse
proxy terminates TLS in production. A provider that supports only plaintext
HTTP receives a prominent warning and explicit admin opt-in because credentials
and streams can otherwise be intercepted between Odissey and the provider.

## Reporting vulnerabilities

Use the private vulnerability-reporting instructions in the repository-root
`SECURITY.md`. Do not report live credentials or exploitable details in a
public issue.
