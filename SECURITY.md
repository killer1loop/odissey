# Security policy

## Supported versions

Odissey is pre-1.0 software. Security fixes are applied to the latest commit on
`main`; older snapshots are not supported.

## Reporting a vulnerability

Use GitHub's private vulnerability reporting for this repository:

1. open the repository's **Security** tab;
2. choose **Advisories** and **Report a vulnerability**;
3. include the affected commit, impact, reproduction steps, and a proposed
   mitigation when available.

Do not open a public issue containing credentials, private media URLs, provider
details, personal data, or an unpatched exploit. If private reporting is
temporarily unavailable, open a public issue asking the maintainer to establish
a private contact channel without including sensitive details.

Please allow a reasonable remediation window before public disclosure. The
maintainer will acknowledge a complete report, validate its impact, and
coordinate disclosure and credit.

## Security boundaries

Odissey does not provide media or IPTV access. Deployers are responsible for
using authorized, read-only sources, protecting the application key and
database, terminating public traffic with HTTPS, and rotating any credential
used during testing.
