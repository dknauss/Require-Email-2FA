# Roadmap / Backlog

This plugin should stay small, stateless, and easy to audit. Items here are
intentionally conservative: they improve maintainability or operational assurance
without expanding the runtime policy surface.

## Completed

- ✓ **External release verification** — GitHub Release zips now include a SHA-256
  checksum and build-provenance attestation (see
  `docs/SUPPLY-CHAIN-SECURITY.md`). Operators can verify release assets independently;
  the automatic Plugin Update Checker path still relies on HTTPS and exact asset-name
  selection rather than verifying the checksum or attestation itself.

## Backlog

- Keep dependency maintenance boring and regular. Prefer semver-safe updates first
  (for example PHPStan patch releases), and evaluate major PHPUnit/PHPCS upgrades
  separately so the PHP 7.2 runtime-compatibility policy remains clear.
- Preserve the one-file production architecture unless the main plugin file grows
  enough that auditability suffers. If it ever splits, keep security decisions as
  pure functions and keep WordPress glue thin.
