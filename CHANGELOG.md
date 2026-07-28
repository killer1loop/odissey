# Changelog

All notable changes to Odissey will be documented here. The project follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and intends to use
[Semantic Versioning](https://semver.org/) after the first tagged release.

## [Unreleased]

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
