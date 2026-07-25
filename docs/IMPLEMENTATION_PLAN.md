# Implementation plan

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

## Milestone 0 — foundation (initialized)

Deliverables:

- Laravel/SQLite project skeleton;
- Blade app shell and HTMX asset pipeline;
- SQLite WAL/busy-timeout configuration;
- multi-stage single-image Docker build with FFmpeg;
- supervised web, queue, and scheduler processes;
- persistent data and transient-cache conventions;
- health endpoint, CI, architecture, security, and deployment documentation.

Exit criteria:

- PHP tests and frontend production build pass;
- route/config/view caches build;
- container builds on Linux AMD64;
- fresh start creates and migrates SQLite;
- restart with the same data volume preserves the key and database;
- no supplied test or deployment credentials are present in the repository,
  image, logs, or Git diff.

## Milestone 1 — secure first launch and users

Deliverables:

- installation-state middleware;
- one-time setup token supplied by environment or generated to container logs;
- atomic first-admin creation guarded by both the token and `users = 0`;
- login, logout, password reset/recovery policy, session regeneration, and rate
  limiting;
- `admin` and `user` roles with Laravel policies;
- admin-only user creation, disablement, and password reset;
- user preferences and timezone;
- database/key backup and restore commands.

Exit criteria:

- concurrent setup attempts can create only one first admin;
- an Internet visitor without the setup token cannot claim the server;
- `/setup` is permanently unavailable after installation;
- disabled users lose active sessions;
- backup/restore preserves users and the ability to decrypt a test setting;
- authorization tests cover every admin route.

## Milestone 2 — IPTV catalog, groups, guide, and favorites

Deliverables:

- encrypted provider configuration;
- base-URL normalization, redirect/DNS validation, and explicit insecure-HTTP
  warning;
- asynchronous provider test with sanitized error reporting;
- Xtream-style category/channel adapter;
- generic M3U adapter behind the same contract;
- bounded XMLTV parser, EPG channel mapper, retention, and scheduled refresh;
- sync-run status fragments for HTMX polling;
- group navigation, channel search, current/next program display, and a
  keyboard-accessible time-grid guide;
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

## Milestone 3 — live IPTV playback and history

Deliverables:

- opaque stream sessions with owner, lease, heartbeat, and expiry;
- authenticated upstream proxy and manifest/key/segment URL rewriting;
- direct-stream and remux paths;
- HTML video player with quality, audio, captions, fullscreen, and recovery
  states;
- per-user playback session history and watched duration;
- connection and stream concurrency limits.

Exit criteria:

- no origin URL or provider credential is visible to the browser;
- another user cannot use or enumerate a stream session;
- upstream disconnect, expired session, invalid manifest, and provider rate
  limit produce bounded, user-readable failures;
- abandoned sessions and transient files are reaped;
- container shutdown leaves no FFmpeg child process.

## Milestone 4 — local, S3, and WebDAV libraries

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

## Milestone 5 — bounded FFmpeg HLS

Deliverables:

- `media:supervise` daemon that owns FFmpeg process groups;
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
- tagged image pushed to GHCR and Dokploy switched from server-side builds to a
  pinned image.

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

## Questions to confirm

These do not block the initialized foundation, but they change later schema,
security, and infrastructure decisions:

1. Does “does not store any file” allow persistent catalog/EPG metadata and
   short-lived HLS segments? May Odissey cache poster art or only proxy it?
2. Should the IPTV MVP be live TV only, or also include provider VOD, series,
   and catch-up? Recording is excluded by default.
3. Should every user see every configured source, with only state separated, or
   should admins grant sources and channel groups per user?
4. What is the expected simultaneous-user count, maximum input resolution, and
   target hardware? The default is one software transcode and unlimited
   practical direct plays subject to bandwidth.
5. Should metadata enrichment use external services such as TMDB/MusicBrainz,
   or should the first release use only source filenames, embedded tags, and
   IPTV EPG data?
6. Please confirm the test IPTV account is authorized for this use and can be
   rotated before the repository becomes public.
