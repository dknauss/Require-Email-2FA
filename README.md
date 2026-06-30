# Force Email Two-Factor (Enforcement)

[![CI](https://github.com/dknauss/force-email-two-factor/actions/workflows/ci.yml/badge.svg)](https://github.com/dknauss/force-email-two-factor/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/dknauss/force-email-two-factor/graph/badge.svg)](https://codecov.io/gh/dknauss/force-email-two-factor)
[![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)
![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4?logo=php&logoColor=white)
![Version](https://img.shields.io/badge/version-1.6.1-green.svg)
[![Open in WordPress Playground](https://img.shields.io/badge/WordPress_Playground-Try_it_live-3858e9?logo=wordpress&logoColor=white)][playground]

A WordPress plugin that makes two-factor authentication mandatory for every user,
and locks down the XML-RPC / REST API-login path to a named allowlist of service
accounts. On multisite it can be **network-activated or activated per-site**, and
an optional `mu-loader.php` makes it un-deactivatable.

It builds on the [Two Factor plugin](https://wordpress.org/plugins/two-factor/) —
a declared `Requires Plugins` dependency that must be installed and active.

**▶ [Try it live in WordPress Playground][playground]** — boots a multisite with
the full 2FA stack (Two Factor, WebAuthn, mail logging) already wired up; no local
install needed. See [`playground/`](playground/) for details.

[playground]: https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/force-email-two-factor/main/playground/blueprint.json

---

## What it does

1. **Forces 2FA for everyone (by default).** It ensures the always-available,
   zero-setup **Email** provider is enabled for every user, so the login
   challenge appears for all accounts — including ones that never configured 2FA.
   Enforcement can be scoped with **per-role exclusions** (see Configuration).

   It *appends* Email rather than replacing the provider list, so users who set
   up a stronger factor (TOTP, hardware key / WebAuthn) keep it as their primary
   method, and **backup codes remain available** as a recovery path.

2. **Restricts API logins.** XML-RPC and REST logins bypass the interactive 2FA
   screen. This plugin allows an API login to skip 2FA **only** when *both*:
   - the account is on an explicit allowlist, **and**
   - the request authenticated with an **Application Password** (not the real
     login password).

   Everyone else is denied on the API path.

---

## Installation

Install the plugin folder and activate it like any plugin:

```
wp-content/plugins/force-email-two-factor/force-email-two-factor.php
```

### Activation modes (multisite)

* **Network Activate** (Network Admin → Plugins) — enforces across **all** sites.
  This is the robust security baseline and the recommended mode.
* **Per-site activate** (a single site's Plugins screen) — enforces only when the
  plugin is active in the **current request's** site context.

  ⚠️ On multisite, users and their Two Factor settings are network-global, but
  this plugin only registers its filters where it is active. So per-site
  activation keys enforcement off the **login entry point, not the user** — a
  global user could authenticate via a site where the plugin is inactive and skip
  enforcement. Use per-site only for "this site's team must use 2FA"; use Network
  Activate for a true network-wide guarantee.

On single-site WordPress, just activate it normally.

### Optional: "cannot be deactivated" mode (mu-loader)

To force-load the plugin so it can't be turned off from the admin UI, copy the
included `mu-loader.php` into `wp-content/mu-plugins/` (a flat file). It
`require`s the plugin from `wp-content/plugins/force-email-two-factor/`. Safe to
combine with normal/network activation — a `FORCE_2FA_LOADED` re-load guard
prevents double execution. The `FORCE_2FA_DISABLE` kill switch still applies.

### Before first activation

Email 2FA depends on outbound mail. Confirm transactional email actually delivers
(a working SMTP setup) **before** rolling this out, and keep a known-good admin
session or printed backup codes on hand in case mail is misconfigured. Otherwise
a mail outage can lock out every user who has no stronger factor.

---

## Configuration

### Excluding roles from forced 2FA

Enforcement applies to **all** users by default. To exempt specific roles, list
their slugs (lowercase keys like `subscriber`, `customer` — not display names) in
the `FORCE_2FA_EXCLUDED_ROLES` constant:

```php
const FORCE_2FA_EXCLUDED_ROLES = array( 'subscriber', 'customer' );
```

Empty (the default) = enforce on everyone.

**Security rule:** a user is exempt only if *every* role they hold is on the list.
A user with both an excluded role and a non-excluded one (e.g. `subscriber` +
`editor`) is still enforced. Users with no role are never exempted.

**Threat model / warning:** exclusions live in code (this constant or the
`force_2fa_user_is_exempt` filter), which requires filesystem access — a trust
level that can already disable 2FA entirely. Exclusions are therefore an
*operator convenience, not an attacker-facing control*, and there is **no hard
floor** protecting privileged accounts. If you exclude a role that a super admin
or administrator holds *as their only role on a site*, that account will be
exempt on that site. Choose excluded roles deliberately, and prefer the
`force_2fa_user_is_exempt` filter to exempt a specific account surgically.

Exclusion means "don't *force* 2FA"; it doesn't forbid it. An excluded user who
set up their own 2FA keeps it.

For one-off cases (e.g. exempt a single user ID rather than a whole role), use the
`force_2fa_user_is_exempt` filter instead of editing the role list:

```php
add_filter( 'force_2fa_user_is_exempt', function ( $exempt, $user ) {
    if ( 42 === $user->ID ) {
        return true;
    }
    return $exempt;
}, 10, 2 );
```

### Allowlisting service accounts (API logins)

Edit the `FORCE_2FA_API_LOGIN_ALLOWLIST` constant in the plugin file. Each entry
is either a numeric **user ID** or a **user_login** (case-insensitive):

```php
const FORCE_2FA_API_LOGIN_ALLOWLIST = array(
	123,            // by user ID — preferred, survives login renames
	'svc_headless', // by user_login
);
```

Leave it empty (the default) to permit **no** API logins at all.

A "service account" here is a non-human integration that can't type an emailed
code — e.g. a headless frontend, a CI/CD pipeline, an automation platform
(Zapier / Make / n8n), a backup/monitoring tool, or the Jetpack / WordPress
mobile apps. For each one you add:

1. Have it authenticate with an **Application Password**
   (Users → Profile → Application Passwords), never the real login password.
2. Give it the **least-privilege role** the integration needs.
3. **Remove it** from the list the moment the integration is retired.

### Optional: disable XML-RPC entirely

The plugin includes a commented-out line to turn XML-RPC off completely:

```php
// add_filter( 'xmlrpc_enabled', '__return_false' );
```

Uncomment it **only** if nothing legitimately uses XML-RPC. The Jetpack /
WordPress mobile apps and some remote-publishing / pingback tools rely on it. The
API-login allowlist above already forces 2FA on XML-RPC logins without breaking
the endpoint, so leave this off unless you specifically need XML-RPC shut down.

---

## Emergency kill switch

If email delivery breaks and users are locked out, disable **all** enforcement in
this file without deleting it by adding to `wp-config.php`:

```php
define( 'FORCE_2FA_DISABLE', true );
```

The plugin checks this at load time and registers nothing when it's set. Remove
the line (or set it to `false`) to re-enable enforcement.

---

## How it works (for maintainers)

- **Forcing 2FA:** filters `two_factor_enabled_providers_for_user` to add
  `Two_Factor_Email`. `Two_Factor_Email::is_available_for_user()` returns `true`
  unconditionally and needs no per-user setup, so it becomes an available — and
  for unconfigured users, the primary — provider. That makes
  `Two_Factor_Core::is_user_using_two_factor()` true, which triggers the login
  challenge. A `class_exists( 'Two_Factor_Email' )` guard means the filter
  no-ops safely (never stripping an existing factor) if the Two Factor plugin is
  inactive.

- **API logins:** filters `two_factor_user_api_login_enable`. The plugin's own
  default for this filter is `did_action( 'application_password_did_authenticate' )`,
  i.e. it already allows API logins without 2FA only via Application Passwords.
  This plugin recomputes the decision as *(app password used) AND (user is
  allowlisted)*. The enforcement runs at priority 31 on `authenticate`, after
  core's application-password handler at priority 20, so the app-password marker
  is reliably set by the time the decision is made.

---

## Requirements & dependencies

- **Required:** the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin
  (`two-factor`). Declared via the `Requires Plugins` header (WP 6.5+), so
  WordPress blocks activation until Two Factor is installed and active.
- **Recommended:** [WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)
  (`two-factor-provider-webauthn`) for passkeys / hardware keys. Optional — this
  plugin works without it.
- **Testing only:** [WP Mail Logging](https://wordpress.org/plugins/wp-mail-logging/)
  (`wp-mail-logging`) to read 2FA email codes where there is no real mail server
  (e.g. Playground). Not needed in production.
- Working outbound email (SMTP) for the Email provider in production.
- Application Passwords enabled (WordPress core, on by default over HTTPS) for any
  allowlisted service account.

---

## Development

Tests run on a zero-dependency stub bootstrap (no WordPress install required):

- **Unit** — the security-critical decision logic: role exclusions, the API-login
  allowlist, and both filter callbacks.
- **Integration** — the plugin's contract with Two Factor: that appending Email
  makes `Two_Factor_Core::is_user_using_two_factor()` true (the login challenge
  fires), excluded users stay unenforced, and a user's stronger factor is kept.

```sh
composer install
composer test                       # PHPUnit: unit + integration
composer phpcs                      # PHPCompatibility (7.2+) + WordPress-Extra
composer phpcbf                     # auto-fix coding-standards issues
composer check                      # phpcs + tests
vendor/bin/phpunit --testsuite integration   # one suite
vendor/bin/phpunit --coverage-text  # coverage (needs Xdebug or PCOV)
```

Coding standards are **WordPress-Extra** (style + security sniffs) plus
**PHPCompatibility** with `testVersion 7.2-`, scoped to the production files
(`phpcs.xml.dist`).

CI ([GitHub Actions](.github/workflows/ci.yml)) runs on every push and pull
request:

- **lint** — `php -l`, PHP 7.2–8.4
- **PHPCS / PHPCompatibility** — coding standards + the PHP 7.2 floor
- **PHPUnit** — unit + integration, PHP 8.2–8.4
- **coverage** — PHPUnit coverage (PCOV); summary in the job's GitHub summary,
  clover uploaded as an artifact (and to Codecov if the repo is enabled there)
- **Playground integration** — boots a real WordPress + the real Two Factor
  plugin headlessly and asserts enforcement end-to-end (see
  [`playground/ci-blueprint.json`](playground/ci-blueprint.json))

The config constants (`FORCE_2FA_EXCLUDED_ROLES`, `FORCE_2FA_API_LOGIN_ALLOWLIST`)
are read through filter accessors (`force_2fa_excluded_roles`,
`force_2fa_api_login_allowlist`), so values are overridable at runtime and
injectable in tests.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
