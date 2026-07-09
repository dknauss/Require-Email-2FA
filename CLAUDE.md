# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A single-purpose, security-focused WordPress plugin (slug `force-email-two-factor`) that makes the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin's emailed passcodes a mandatory login baseline for every user, and locks the XML-RPC/REST API-login path down to an allowlist of Application-Password-authenticated accounts. There is no admin UI, no options page, no database state — all operator configuration is via `wp-config.php` constants or filters. Changes should stay narrow, testable, and easy to audit (see CONTRIBUTING.md).

Production code is only three files, all procedural PHP with `force_2fa_` / `FORCE_2FA_` prefixes:

- `force-email-two-factor.php` — the entire plugin (~1,400 lines; extensively commented)
- `mu-loader.php` — optional one-line mu-plugin loader ("cannot be deactivated" mode)
- `uninstall.php` — purges the only persistent footprint (Plugin Update Checker's site option + cron)

`vendor/` is **committed to git** (the vendored `yahnis-elsts/plugin-update-checker` is a reviewed supply-chain decision — see `docs/SUPPLY-CHAIN-SECURITY.md`). Never hand-edit it.

## Commands

```sh
composer install          # PHP 8.2+ and Composer 2 required for dev tooling
composer check            # phpcs + phpstan + all phpunit suites — run before every PR
composer test             # vendor/bin/phpunit (unit + integration suites)
composer phpcs            # WordPress coding standards + PHPCompatibility (7.2-)
composer phpcbf           # auto-fix phpcs violations
composer phpstan          # PHPStan level 5 (uses phpstan-baseline.neon)
```

Single test file / single test / one suite:

```sh
vendor/bin/phpunit tests/AllowlistTest.php
vendor/bin/phpunit --filter test_method_name
vendor/bin/phpunit --testsuite unit          # or: integration
vendor/bin/phpunit --coverage-text           # needs PCOV or Xdebug
```

End-to-end scripts (what CI runs; both build a real WordPress with the SQLite drop-in):

```sh
bash bin/multisite-e2e.sh    # network-only activation behavior on a real multisite
bash bin/update-e2e.sh       # real update from a GitHub Release asset (set GITHUB_TOKEN)
```

The plugin **runtime** supports PHP 7.2+; dev tooling deliberately requires modern PHP. Cross-version safety is enforced by `php -l` across 7.2–8.4 and PHPCompatibility (`testVersion 7.2-`) in CI — do not use post-7.2 syntax in the three production files (tests and `bin/` may use newer syntax).

## Architecture

### One file, two layers: pure decisions vs. WordPress glue

`force-email-two-factor.php` is deliberately structured so that every security decision lives in a **pure function** taking explicit inputs (e.g. `force_2fa_activation_blocked( $is_multisite, $network_wide )`, `force_2fa_self_update_status(...)`, `force_2fa_dependency_state(...)`), while thin glue callbacks gather state from WordPress and delegate to them. Preserve this split: new logic should be a pure function the unit tests can call directly, with WP-touching glue kept minimal.

Hook registration is centralized in `force_2fa_register_hooks()` near the bottom of the file. The `FORCE_2FA_DISABLE` constant is the emergency kill switch that skips enforcement registration entirely; `FORCE_2FA_LOADED` is the re-load guard that makes the mu-loader safe alongside normal activation.

### The enforcement model

- **Soft dependency:** Two Factor being absent must never fatal — every enforcement path bails via `force_2fa_dependency_met()` (checks `Two_Factor_Core` and that the `Two_Factor_Email` provider is actually registered), and the plugin's only behavior in that state is an admin notice with a one-click installer.
- **Append, never replace:** `force_2fa_filter_enabled_providers()` (hooked to Two Factor's `two_factor_enabled_providers_for_user` filter) appends the Email provider to a user's list. Users' stronger factors (TOTP, WebAuthn) and backup codes must always be preserved.
- **API logins:** allowed to skip interactive 2FA only when the account is on the explicit allowlist **and** the request authenticated with an Application Password (tracked via `force_2fa_note_app_password_user()` binding the app-password user to the request). Everything else on the API path is denied.
- **Multisite is network-only:** a `register_activation_hook` guard (`force_2fa_block_single_site_activation()`) refuses per-site activation. A `Network: true` header is deliberately **not** used — core would silently promote per-site activation to network-wide instead of refusing. Don't "fix" this.

Operator configuration surface (keep it constants + filters, nothing else): `FORCE_2FA_DISABLE`, `FORCE_2FA_EXCLUDED_ROLES` / `force_2fa_excluded_roles`, `FORCE_2FA_API_LOGIN_ALLOWLIST` / `force_2fa_api_login_allowlist`, `force_2fa_user_is_exempt`, `FORCE_2FA_DISABLE_SELF_UPDATE` / `force_2fa_self_update_enabled`.

### Self-update path

Updates ship from **GitHub Releases, not WordPress.org** (the `Update URI` header points at this repo). `force_2fa_bootstrap_self_update()` wires the vendored Plugin Update Checker, with guards: disabled by constant/filter, skipped in a git working copy, and only the exact `force-email-two-factor.zip` release asset installs. A Site Health test reports the update posture. The release-publishing path is the supply chain — read `docs/SUPPLY-CHAIN-SECURITY.md` before touching `.github/workflows/release.yml` or the updater bootstrap.

### Test harness (`tests/`)

`tests/bootstrap.php` is a **zero-dependency stub layer** — no WP install, no Brain Monkey/Patchwork. It defines just enough WordPress surface (plus minimal `Two_Factor_Core` / `WP_User` stand-ins) for the pure functions to run under PHPUnit 11. Key mechanics:

- `add_filter`/`add_action` only **record** registrations (tests assert on them); tests invoke the plugin's named callbacks directly.
- Stub behavior is driven by `$GLOBALS['__force2fa_*']` globals, set via `Force2FA\TestCase` helpers and reset in `setUp()`. When you add a new WP function call to the plugin, you likely need a stub here.
- `tests/IntegrationTest.php` (the `integration` suite) exercises the plugin's filter through the stubbed `Two_Factor_Core` contract; real-WordPress coverage lives in CI's Playground job (`playground/ci-blueprint.json`) and the `bin/` E2E scripts, not in PHPUnit.

New test files must be registered in `phpunit.xml.dist` (suites are explicit file lists).

### Static analysis

PHPStan runs at level 5 over the three production files only. Two Factor is absent at analysis time — its called surface is stubbed in `phpstan/stubs/two-factor.stub`; extend that stub if you call new Two Factor APIs. Known accepted issues live in `phpstan-baseline.neon`. PHPCS enforces the full `WordPress` ruleset (docs sniffs included) with text domain `force-email-two-factor`.

## Conventions and gotchas

- **Versioning:** the version appears in three places that must stay in lockstep — the `Version:` plugin header, the `FORCE_2FA_LOADED` define in `force-email-two-factor.php`, and `Stable tag:` in `readme.txt`. The release workflow (tag-triggered, `v*`) refuses to publish if the tag doesn't match the `Version:` header or the commit isn't on `main`.
- **Docs travel with behavior:** `README.md` (GitHub) and `readme.txt` (WordPress-style) describe the same behavior in parallel and both need updating when behavior, requirements, or rollout guidance changes; `docs/DEPLOYMENT.md` covers the two update modes.
- **Don't add** UI, options, database tables, cron jobs, or remote calls without a strong reason — statelessness is a design guarantee (`uninstall.php`'s header documents the only persistent footprint).
- **Fail-safe over fail-closed on missing dependency:** if Two Factor or a provider is absent, avoid fatals and avoid corrupting users' provider lists.
- CI (`.github/workflows/ci.yml`) runs lint (PHP 7.2–8.4), PHPCS, PHPStan, PHPUnit (8.2–8.4), coverage, a Playground integration job, and both E2E scripts; CodeQL and Semgrep also scan PRs. All GitHub Actions are pinned to full commit SHAs — keep that when editing workflows.
