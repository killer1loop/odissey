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
- Plex-inspired responsive dark interface.

### Security

- Production setup fails closed without a configured setup token.
- Provider settings and media locators are encrypted at rest.
- Playback routes enforce authenticated ownership and avoid exposing
  credential-bearing upstream URLs to the browser.
