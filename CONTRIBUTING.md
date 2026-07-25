# Contributing to Odissey

Thanks for helping improve Odissey.

## Before opening a change

- Discuss substantial product or schema changes in an issue first.
- Never include real provider credentials, origin URLs, private media,
  deployment keys, or copied proprietary artwork.
- Keep source adapters read-only and preserve per-user authorization.
- Use synthetic media and recorded, sanitized provider fixtures in automated
  tests.

## Development

```sh
composer setup
composer dev
```

Before submitting:

```sh
composer test
npm run build
vendor/bin/pint --test
composer validate --strict
```

Add focused regression coverage for behavior changes. Browser-facing work
should remain usable without HTMX where practical and preserve keyboard-visible
focus, labels, and status announcements.

## Pull requests

Keep pull requests narrowly scoped. Explain the user-visible outcome, security
considerations, migration or deployment impact, and the checks you ran.

Report vulnerabilities through [the security policy](SECURITY.md), not a public
issue.
