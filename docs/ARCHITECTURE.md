# Architecture

## System boundary

Odissey catalogs and plays media from external, read-only sources. It does not
offer uploads, recordings, source-file copies, or write operations against a
media source.

The persistent application volume contains:

- SQLite metadata and user state;
- an application encryption key when one is not supplied externally;
- no source media.

FFmpeg necessarily produces manifests and segments for HLS playback. Those
files are transient derivatives held in a size-limited cache or tmpfs, scoped to
an authenticated stream session, and removed after expiry.

```mermaid
flowchart LR
    B["Browser<br>Blade + HTMX + media JS"] --> W["FrankenPHP + Laravel"]
    W --> D[("SQLite metadata")]
    W --> Q["Finite queue worker"]
    W --> S["Scheduler"]
    Q --> A["Read-only source adapters"]
    A --> L["Local mounts"]
    A --> O["S3-compatible storage"]
    A --> V["WebDAV"]
    A --> I["External IPTV provider"]
    Q --> M["Bounded transcode job<br>current vertical slice"]
    M --> F["FFmpeg / FFprobe"]
    F --> T[("Transient HLS cache")]
    T --> W
```

The Docker image supervises the web process, one finite queue worker, and the
scheduler. The current vertical slice runs a bounded, idempotently keyed VOD
transcode as a finite high-priority queue job. A dedicated media supervisor is
still planned for long-running, on-demand sessions because it can own leases,
process groups, resource limits, and graceful shutdown more directly.

## Request and job model

Blade renders complete pages. HTMX replaces bounded fragments for operations
such as filters, guide windows, favorites, provider sync progress, and search.
Every URL remains usable as a normal HTTP request, and server responses decide
redirects and authorization.

Finite work runs on the database queue:

- provider connectivity and channel/category synchronization;
- bounded hourly EPG refresh for every enabled provider;
- library scans;
- `ffprobe` analysis;
- cleanup and retention tasks.

Network reads and XML parsing happen outside database transactions. Imports
commit bounded, idempotent batches and hold a per-source lock. Jobs contain
record identifiers, not credentials.

## Source contracts

### Media sources

All storage drivers implement a read-only contract:

```text
list(path, cursor)
stat(locator)
openRange(locator, start, end)
capabilities()
```

- **Local:** configured roots must be mounted read-only. Canonical-path checks
  prevent traversal and symlink escape.
- **S3:** use a prefix-scoped, read-only identity. Direct play may use a
  short-lived signed URL when doing so does not expose source credentials.
- **WebDAV:** use a read-only account. Onboarding probes `PROPFIND`, `HEAD`, and
  byte-range behavior because seeking support varies by server.
- **IPTV VOD:** Xtream movie and episode identifiers are stored as encrypted
  opaque locators on a provider-managed media source. Credentials remain on
  the encrypted provider record and playback is proxied through the same
  authenticated range/transcode boundary.

The catalog stores stable external locators and metadata. It never stores the
source bytes.

### IPTV providers

The implemented adapter normalizes an Xtream-style API into the following
domain objects; generic M3U/XMLTV support will implement the same contract:

- provider;
- channel group;
- channel;
- EPG channel mapping;
- EPG program.
- VOD movie, series, and episode.

The IPTV-first flow is:

1. An admin enters a display name, base URL, username, and password.
2. The server validates the URL and tests authentication asynchronously.
3. Live categories/channels and on-demand movies/series are upserted by stable
   provider identifiers.
4. Current/next guide rows are fetched with bounded short-EPG requests and
   normalized to UTC. A streaming XMLTV importer is planned.
5. Missing channels become inactive instead of being deleted.
6. Users filter by provider/group and maintain their own favorites.

Provider channel icon URLs are deliberately ignored. Odissey resolves channel
identity against the public IPTV-org channel and logo catalogs using an exact
EPG channel ID first, then unique country-aware name aliases after removing
display-only quality suffixes. Only active HTTPS raster logos are eligible;
ambiguous or unmatched channels render local initials. The catalog is bounded,
cached, refreshed daily, and failures preserve the last approved match.

Origin URLs and credentials must never enter HTML, HLS playlists, logs, queue
payloads, or browser network requests. Playback uses an opaque, short-lived
stream session. Provider manifests, segments, redirects, and encryption-key URLs
are fetched through the authenticated server boundary.

## Playback decision

For each playable item:

1. **Direct play** when the browser supports the container/codecs and the source
   provides reliable byte ranges.
2. **Remux to HLS** with stream copy when only the container is incompatible.
3. **Transcode to H.264/AAC HLS** when codecs, bitrate, resolution, or subtitles
   require conversion.

FFmpeg is executed with an argument array, never a shell command. Protocols,
threads, resolution, bitrate, concurrent sessions, scratch bytes, and lifetime
are bounded. One daemon owns FFmpeg children and stream leases. A signed segment
route verifies the user and session before serving a small transient file.

The browser sends a sequenced playback heartbeat every 10–15 seconds and on
pause, seek, completion, and page exit. The server upserts the current resume
row and rejects out-of-order updates; it does not append a history row for every
heartbeat.

## Core data model

| Table | Purpose and important constraints |
| --- | --- |
| `users` | Role, preferences, timezone; public registration is disabled. |
| `storage_sources` | Type, display name, encrypted configuration, allowed prefix/root, capabilities, sync state. |
| `libraries` | A named video or music catalog backed by one source. |
| `media_items` | Normalized movie, episode, track, album, or other metadata. |
| `media_files` | Stable external locator, size, modified time, probe result, and media-item link. |
| `iptv_providers` | Encrypted provider settings, protocol, health, and sync state. |
| `channel_groups` | Provider category with stable external identifier and ordering. |
| `channels` | Provider channel, group, EPG mapping, logo URL, and active flag. |
| `epg_sources` | Guide source, refresh policy, last result, and retention settings. |
| `epg_programs` | UTC start/end, title, description, category; indexed by channel and time. |
| `channel_favorites` | Unique `(user_id, channel_id)`. |
| `playback_sessions` | One row per play with user, playable, start/end, mode, and sanitized outcome. |
| `playback_progress` | Unique user/playable resume state in integer milliseconds plus monotonic sequence. |
| `stream_sessions` | Opaque ID, owner, source/playable, mode, lease, heartbeat, expiry, and sanitized error. |
| `sync_runs` | Observable status and counts for provider/library background work. |

Live channels have playback history and watched duration but no resume position.
Resume applies to on-demand video, music, and any later catch-up/VOD support.

## SQLite discipline

SQLite is appropriate for the intended self-contained, single-replica
deployment:

- local persistent volume, never NFS or another network filesystem;
- WAL journal mode;
- foreign keys enabled;
- five-second busy timeout;
- short transactions and chunked EPG upserts;
- one finite background worker initially;
- periodic checkpoint, integrity check, and retention cleanup.

Backups use SQLite's online backup API or `VACUUM INTO`. Copying only a live
database file can omit WAL state. The application key must be backed up with the
database or encrypted source settings cannot be recovered.

## Interface architecture

The visual direction takes guidance from Plex's documented patterns without
copying branding or assets:

- a stable dark app shell with source navigation and prominent search;
- home shelves for continue-watching, recently added, favorites, and live now;
- source-level tabs for recommendations, browse, categories, and collections;
- poster-first detail pages with a clear play/resume action;
- a responsive timeline EPG with sticky playable channels, current-time
  markers, group/favorites filters, and an alternate channel-card grid;
- a persistent mini-player for music.

Relevant research:

- [Plex interface overview](https://support.plex.tv/articles/200484203-interface-overview/)
- [Customizing Plex Web](https://support.plex.tv/articles/customizing-plex-web/)
- [Plex library view](https://support.plex.tv/articles/200392126-using-the-library-view/)
- [Plex program guide](https://support.plex.tv/articles/225877387-program-guide/)
- [Plex web player](https://support.plex.tv/articles/200392226-plex-web-app-player/)

Hover shortcuts always have keyboard and touch equivalents. HTMX swaps restore
focus where appropriate and announce errors. The EPG follows the
[WAI-ARIA grid pattern](https://www.w3.org/WAI/ARIA/apg/patterns/grid/), player
controls have visible focus and large targets, and motion respects
`prefers-reduced-motion`.
