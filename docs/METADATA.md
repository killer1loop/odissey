# Media metadata

Odissey derives technical metadata, embedded music tags, thumbnails, and
movie/episode identities locally with FFprobe and FFmpeg. Filename matching
understands common movie-year and `SxxEyy` episode conventions.

Optional TMDB enrichment is enabled with `TMDB_API_TOKEN`. Odissey searches
movies and series, stores the TMDB identifier, and caches bounded
poster/backdrop images in the persistent data volume.
Scanning continues without external metadata when the token is absent or the
provider is unavailable.

Administrators can enter the token in **Administration → Metadata & captions**
instead of the environment. UI-entered tokens take precedence and are stored
encrypted.

TV episodes also use TVmaze's free public API as a no-key fallback for series,
episode, image, summary, genre, and rating data.

Xtream movie and series imports enter this same enrichment pipeline. Provider
titles, years, series/season/episode numbers, and external identifiers seed the
match; fetched artwork and captions are cached under the same bounded private
storage rules as local, S3, and WebDAV items.

Only parsed title, year, and media type are sent to an enabled metadata
provider. Paths, source credentials, and media bytes remain private.

## Captions

Embedded subtitle tracks are detected by FFprobe and converted on demand to
authenticated WebVTT tracks. Optional automated caption searches support
SubDL and OpenSubtitles using operator-supplied free-account API keys:

```text
SUBDL_API_KEY=
OPENSUBTITLES_API_KEY=
ODISSEY_CAPTION_LANGUAGES=en,de,it
```

The same keys and language list can be managed from **Administration →
Metadata & captions** without shell or Dokploy access.

Searches use the matched TMDB identifier when available, otherwise the parsed
title, year, season, and episode. Downloads are hostname-restricted, size
bounded, archive-entry checked, converted to WebVTT without a shell, and stored
privately under the persistent Odissey data directory. Caption providers apply
their own free-account quotas and terms. Odissey never uploads subtitles or
modifies the source repository.
