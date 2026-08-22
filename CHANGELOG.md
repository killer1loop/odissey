# Changelog

All notable changes to Odissey will be documented here. The project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and intends to use
[Semantic Versioning](https://semver.org/) after the first tagged release.

## [Unreleased]

### Fixed

- IPTV guide imports apply the import memory budget, and the generic queue
  worker runs with an explicit limit and restart threshold.
- Provider catalog synchronization commits in bounded chunks, restores the
  previous live catalog when a write fails mid-sync, and marks the VOD source
  failed instead of holding one long exclusive SQLite transaction.
- Transcode capacity waits now span the full conversion timeout; watchdog-
  completed outputs survive late runner failures.
- Web playback of H.264 video with incompatible audio converts only the audio
  track instead of re-encoding video.
- Actively watched HLS output has its lease extended during playback.
- Silent remote inputs fail after a bounded stall deadline; direct streams and
  source snapshots use time-based stall guards instead of fixed empty-read
  cutoffs.
- FFmpeg failures log a bounded, secret-redacted stderr tail for diagnosis.
- Media source discovery failures propagate to queue retries and failed-job
  bookkeeping.
- The LiveTV guide payload caps programmes per channel; channel icon proxying
  is rate limited per user.
- IPTV player arrow keys no longer hijack focus over interactive controls,
  rewind-style recoveries no longer trip the stall watchdog, boosted forms
  cannot be double-submitted, and TV spatial navigation measures once per
  keypress with instant scrolling.
- Web login burns a bcrypt comparison for unknown accounts to equalize
  response timing.
- Marketing site: scroll-driven animations honor reduced motion, secondary
  text meets contrast AA, keyboard focus is visible with a skip link on the
  production page, HSTS and HTML cache headers ship, workers.dev/preview
  duplicates are disabled, and the unused animation stack was removed.

### Changed

- Removed the retired signed loopback transcode source feed and its HTTP
  protocol-whitelist branch.
- Added catalog, playback-history retention, and foreign key indexes.

### Added

- Laravel 13, SQLite, Blade, HTMX, hls.js, and FFmpeg application foundation.
- Single-image FrankenPHP deployment with supervised web, queue, and scheduler
  processes.
- Token-protected first-admin setup, local multi-user authentication, and
  admin-managed viewer accounts.
- Per-user media playback progress and history.
- Synthetic direct-play and FFmpeg HLS fixtures for end-to-end validation.
- Encrypted Xtream-compatible IPTV provider onboarding, asynchronous catalog
  and short-guide sync, groups, search, favorites, and opaque HLS proxy
  sessions.
- Versioned `/api/v1` native-client API with an OpenAPI contract covering
  server discovery, setup and authentication, profiles, catalog, search,
  favorites, history, Live TV and EPG, playback, and administrative
  operations.
- Device-scoped native sessions with short-lived access tokens, rotating
  refresh tokens, replay detection, per-device revocation, and bounded
  retention.
- Native playback resolution for direct, remux, transcode, subtitle, and live
  delivery using resource-scoped grants that expire within ten minutes and
  rotate when the client re-resolves the same resource.
- Persistent, user-owned music playlists with ordered tracks, bounded
  mutations, and owner-scoped concurrency control.
- Additive SQLite migrations for native sessions, playback grants, consumed
  refresh-token hashes, native track selections, administrative audit events,
  and music playlists. Existing web users and media records are retained.
- Plex-inspired responsive dark interface.
- Beta installation and operations runbook covering HTTPS, source onboarding,
  backup, upgrade, rollback, and redacted diagnostics.

### Fixed

- Serialized native profile preferences now match the OpenAPI contract, sparse
  media summaries remain JSON objects, and unavailable artwork is nullable.
- Native episode artwork can fall back to the matching parent series.
- Administrator role changes are atomic and preserve at least one active
  administrator.
- Generic M3U providers can be submitted without Xtream-only browser fields.
- Media discovery and scan queues use retry windows longer than their worker
  and overlap timeouts.
- Bounded, credential-safe remote probing can avoid unnecessary native
  transcoding when an S3 or WebDAV asset exposes sufficient technical metadata
  in its initial byte range.
- Failed remote probes use a persistent cooldown and per-scan budget; queued
  media-object paths are encrypted and transient failures now use real retries.
- Partial file-library scans, empty IPTV catalog/category/episode responses, and
  empty or partial guide responses preserve the corresponding last known data.
- Offline restore verifies that transcode and IPTV VOD workers have been
  stopped.
- Guide errors are tracked independently from catalog errors, including empty,
  unmatched, truncated, unconfigured, and unexpected failures; failed queue
  records are pruned after seven days.

### Security

- Production setup fails closed without a configured setup token.
- Provider settings and media locators are encrypted at rest.
- Playback routes enforce authenticated ownership and avoid exposing
  credential-bearing upstream URLs to the browser.
- Native access, refresh, playback-grant, and consumed-refresh tokens are
  stored only as hashes and excluded from model serialization.
- Native playback bearer paths are limited to a ten-minute grant window;
  access logs must redact or exclude `/api/v1/playback/assets/*`.
- Artwork generation and playlist mutations have dedicated request throttles,
  cross-process locks, and bounded worker admission.
- Production containers log at `info` by default, and first-launch setup tokens
  can be cleared after installation state closes setup permanently.
