# Implementation plan

## Current implementation snapshot

The repository is in a pre-release vertical-slice phase. The following paths
are implemented and covered by automated tests:

- production-token-protected first launch, login/logout, admin-created users,
  account disablement, and per-user authorization;
- explicit synthetic local-video fixtures, byte-range direct playback,
  playback progress/history, and bounded FFmpeg H.264/AAC HLS conversion;
- encrypted Xtream-compatible IPTV onboarding, channel/category sync, bounded
  short-EPG refresh, groups, search, per-user favorites, and opaque HLS proxy
  sessions;
- single-image FrankenPHP deployment with SQLite, one queue worker, scheduler,
  FFmpeg, and health checks.

This slice is not the complete product. Arbitrary local-library indexing, S3,
WebDAV, music, generic M3U/XMLTV, richer metadata, backup/restore commands, and
full release-hardening remain planned below.

## Scope and adopted defaults

The first public release is a responsive web application, not a native TV or
mobile client. The planned defaults are:

- Laravel 13, PHP 8.5, Blade, HTMX 2, and small player-specific JavaScript;
- SQLite on a local persistent volume, one application replica;
- FrankenPHP, one finite queue worker, and the scheduler in one image;
- IPTV live TV first through an Xtream-style adapter, followed by generic
  M3U/XMLTV;
- CPU transcoding with one concurrent FFmpeg session initially;
- no public registration: the first admin creates or invites other users;
- no upload, source mutation, IPTV recording, DRM bypass, or content
  redistribution;
- metadata and user state are persistent; HLS derivatives are transient.

Each milestone ends in a deployable build. Work does not proceed to public
release merely because the happy path works.

## Milestone 0 — foundation (implemented; release validation pending)

Deliverables:

- Laravel/SQLite project skeleton;
- Blade app shell and HTMX asset pipeline;
- SQLite WAL/busy-timeout configuration;
- multi-stage single-image Docker build with FFmpeg;
- supervised web, queue, and scheduler processes;
- persistent data and transient-cache conventions;
- health endpoint, architecture, security, and Dokploy deployment
  documentation.

Exit criteria:

- PHP tests and frontend production build pass;
- route/config/view caches build;
- container builds on Linux AMD64;
- fresh start creates and migrates SQLite;
- restart with the same data volume preserves the key and database;
- no supplied test or deployment credentials are present in the repository,
  image, logs, or Git diff.

## Milestone 1 — secure first launch and users (vertical slice implemented)

Deliverables:

- installation-state middleware;
- one-time setup token supplied by environment, with production failing closed
  when it is missing;
- atomic first-admin creation guarded by both the token and `users = 0`;
- login, logout, session regeneration, and rate limiting;
- `admin` and `user` authorization;
- admin-only user creation and disablement;
- password recovery/reset, user preferences, and timezone;
- database/key backup and restore commands.

Exit criteria:

- concurrent setup attempts can create only one first admin;
- an Internet visitor without the setup token cannot claim the server;
- `/setup` is permanently unavailable after installation;
- disabled users lose active sessions;
- backup/restore preserves users and the ability to decrypt a test setting;
- authorization tests cover every admin route.

## Milestone 2 — IPTV catalog, groups, guide, and favorites (Xtream slice implemented)

Deliverables:

- encrypted provider configuration;
- base-URL normalization, redirect/DNS validation, and explicit insecure-HTTP
  warning;
- asynchronous provider test with sanitized error reporting;
- Xtream-style category/channel adapter;
- generic M3U adapter behind the same contract (planned);
- bounded XMLTV parser and EPG channel mapper (planned); the current slice uses
  a bounded short-EPG importer;
- sync-run status fragments for HTMX polling;
- group navigation, channel search, and current/next program display;
- keyboard-accessible time-grid guide (planned);
- per-user channel favorites.

Exit criteria:

- synthetic fixtures cover authentication failure, malformed payloads,
  duplicates, channel removal, timezone/DST boundaries, large XMLTV input,
  redirects, timeouts, and retry behavior;
- resync is idempotent and does not lose favorites;
- expired EPG data is pruned;
- credentials never appear in job payloads, responses, logs, screenshots, or
  exceptions;
- the approved live test provider passes only through injected secrets and its
  credentials are rotated before repository publication.

## Milestone 3 — live IPTV playback (vertical slice implemented)

Deliverables:

- opaque stream sessions with owner, lease, heartbeat, and expiry;
- authenticated upstream proxy and manifest/key/segment URL rewriting;
- direct HLS proxy path;
- HTML video player with browser-native controls and recovery states;
- remuxing, selectable quality/audio/captions, detailed watched-duration
  history, and configurable provider-wide concurrency limits (planned).

Exit criteria:

- no origin URL or provider credential is visible to the browser;
- another user cannot use or enumerate a stream session;
- upstream disconnect, expired session, invalid manifest, and provider rate
  limit produce bounded, user-readable failures;
- abandoned sessions and transient files are reaped;
- container shutdown leaves no FFmpeg child process.

## Milestone 4 — local, S3, and WebDAV libraries (fixture slice only)

Deliverables:

- read-only source contract and capability test UI;
- local adapter with allowed-root and symlink escape protection;
- S3/S3-compatible adapter with prefix-scoped configuration;
- WebDAV adapter with range/seek capability detection;
- asynchronous scans, stable identity, incremental updates, and missing-item
  handling;
- `ffprobe` metadata extraction;
- video browse/detail/direct-play experience;
- artist/album/track views, play queue, and mini-player;
- per-user favorites, history, watched state, and resume position.

Exit criteria:

- no adapter exposes a create, update, or delete operation;
- a browser-compatible sample supports `206` range seeking;
- credentials and signed origin URLs remain server-side;
- rescans preserve user state when a source item is unchanged;
- WebDAV sources without range support degrade clearly;
- tests include unusual filenames, symlinks, pagination, clock skew, remote
  interruption, and large catalogs.

## Milestone 5 — bounded FFmpeg HLS (fixture slice implemented)

Deliverables:

- `media:supervise` daemon that owns long-running FFmpeg process groups
  (planned; the current bounded fixture job is finite queue work);
- direct/remux/transcode decision service;
- H.264/AAC HLS profiles with aligned GOP/segment duration;
- subtitles and audio-track selection;
- signed segment access;
- per-session scratch limits, TTL cleanup, and global cache quota;
- configurable CPU/concurrency limits and graceful shutdown;
- optional hardware acceleration only after a separate capability design.

Exit criteria:

- incompatible video starts HLS playback within the target startup budget;
- seek, pause/resume, audio/subtitle switching, completion, and reconnect work;
- multiple sessions cannot exceed configured CPU/transcode limits;
- malformed media and hostile metadata cannot inject FFmpeg arguments;
- terminating the container stops the entire process group within the grace
  period;
- the transient cache returns to baseline after expiry.

## Milestone 6 — release hardening and publication

Deliverables:

- end-to-end browser tests for setup, provider sync, favorites, guide, playback,
  user isolation, and recovery;
- AMD64 and ARM64 image builds;
- image vulnerability scan, secret scan, SBOM, and dependency-license review;
- FFmpeg licensing/distribution review for the chosen build;
- upgrade, rollback, backup, and restore drills;
- accessibility and responsive-layout audit;
- security disclosure policy, contribution guide, changelog, and release notes;
- tagged releases deployed from `main` by Dokploy's server-side Dockerfile
  build, with the deployed Git commit recorded for rollback.

Publication gate:

- rotate every credential used during private testing;
- scan the complete Git history, not only the current tree;
- verify the GitHub repository contains no private provider data;
- publish only after restore, rollback, and user-isolation tests pass.

## Test strategy

| Layer | Coverage |
| --- | --- |
| Unit | URL normalization, source capabilities, identity hashing, EPG mapping, playback decision, progress sequencing. |
| Feature | Setup/auth/policies, provider CRUD, sync state, guide fragments, favorites, signed stream access, user isolation. |
| Contract | Recorded synthetic responses for Xtream-like, M3U, XMLTV, S3, and WebDAV adapters. |
| Integration | Local disposable provider fixtures and FFmpeg-generated media samples. |
| Live private | Provider connectivity and playback with secrets injected at runtime; sanitized output only. |
| Browser | Keyboard/touch navigation, HTMX history/focus/errors, EPG grid, player, responsive layouts. |
| Container | Fresh boot, health, process recovery, persistence, graceful shutdown, backup/restore, resource bounds. |

## Adopted decisions and later choices

- Odissey persists metadata and per-user state, never source media. HLS
  derivatives are short-lived and disposable. Poster-art caching is deferred.
- The current IPTV scope is live TV. Provider VOD, series, catch-up, and
  recording are excluded until separately designed.
- All active users currently see configured providers; favorites and viewing
  state are separate. Per-source grants are a later product choice.
- The initial runtime permits one software transcode. Capacity targets and
  optional hardware acceleration require deployment-specific profiling.
- External metadata enrichment is deferred; current screens use provider data
  and explicit fixture metadata.
- Live-provider tests require an authorized, runtime-injected account. Test
  credentials must never enter Git and should be rotated after private testing.
