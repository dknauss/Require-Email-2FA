# Security Policy

## Supported versions

Security fixes are provided for the current `main` branch and the latest tagged release once releases are published.

This plugin is intentionally small and security-sensitive. If you maintain a fork with local policy changes, please review upstream security fixes before deploying updates.

## Reporting a vulnerability

Please do **not** open a public GitHub issue for suspected vulnerabilities, lockout bypasses, authentication bypasses, or sensitive operational details.

Use GitHub's private vulnerability reporting for this repository if available. If it is not enabled, contact the maintainer privately through the GitHub profile for `dknauss` and include:

- A concise description of the issue.
- Affected version/commit.
- WordPress, PHP, and Two Factor plugin versions.
- Reproduction steps or a proof of concept.
- Whether the issue requires administrator/file-system access or can be triggered by a lower-privileged user.

## Expected response

- Initial acknowledgement target: 7 days.
- Triage target: 14 days after enough detail is available.
- Fix timeline depends on severity, exploitability, and coordination needs.

## Security scope notes

This plugin enforces policy through the WordPress.org [Two Factor](https://wordpress.org/plugins/two-factor/) plugin. Reports are most useful when they identify behavior in this plugin's enforcement, exemption, API-login allowlist, kill-switch, or multisite activation logic.

## Supply-chain security

Updates install straight from GitHub Releases with no WordPress.org review gate, so the release-publishing path is this plugin's trust boundary: a published release runs network-active on every site that auto-updates. The release pipeline, the GitHub repository settings that protect it, how to verify a release artifact (checksum + build-provenance attestation), and how to fork safely are documented in [docs/SUPPLY-CHAIN-SECURITY.md](docs/SUPPLY-CHAIN-SECURITY.md). Maintainers and forks should apply that hardening checklist.
