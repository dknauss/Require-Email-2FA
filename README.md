# Require Email 2FA

[![CI](https://github.com/dknauss/Require-Email-2FA/actions/workflows/ci.yml/badge.svg)](https://github.com/dknauss/Require-Email-2FA/actions/workflows/ci.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Requires Plugin: Two Factor](https://img.shields.io/badge/requires-Two%20Factor-3858e9.svg)](https://wordpress.org/plugins/two-factor/)

This is a Multisite compatible, single-purpose, WordPress utility plugin with no admin user interface. It's intended to help site owners and maintainers rapidly roll out and enforce a mandatory two-factor authentication policy on WordPress sites or networks that may have many users — in the simplest and least disruptive way.

Require Email 2FA imposes three requirements site- or network-wide:

1. The [Two Factor plugin](https://wordpress.org/plugins/two-factor/) must be installed and activated.
2. All users must use Two Factor to log in. (Exceptions can be set with a constant or filter.)
3. Users who do not have a different method selected in their two-factor settings will receive time-based, one-time passcodes by email.

The plugin also locks down the XML-RPC / REST API-login path. (A named allowlist of service accounts can be added with a constant or filter.)

On multisite the plugin is **network-only** (Network Activate; per-site activation is blocked), and an optional `mu-loader.php` file can be moved to the `/mu-plugins` folder to make it un-deactivatable within the WordPress admin interface.

Require Email 2FA's dependency on Two Factor is *soft*: the Require Email 2FA plugin activates on its own and does nothing until Two Factor is active. It only displays a prominent admin notice with a one-click installer for the Two Factor plugin. Administrators should pre-install and activate Two Factor or do so immediately after installing and activating Require Email 2FA. Then Require Email 2FA will automatically select emailed passcodes as the primary (default) 2FA method for all users who do not have a different one selected. This enforcement will continue for all existing and new users as long as Require Email 2FA is active.

> [!TIP]
> **▶ [Try it live in WordPress Playground][playground]** — boots a disposable WordPress with this plugin already active and Two Factor *not yet installed*, so you land on the Plugins screen and see the guided **"Install & activate Two Factor"** notice. A handful of sample users (across roles) are created so you can browse profiles. No local install needed; nothing is saved.

[playground]: https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Require-Email-2FA/main/playground/demo-blueprint.json

---

## What it does

1. **Forces 2FA for everyone (by default).** It ensures the (assumed) always-available,
   zero-setup **Email** provider is enabled for every user, so the login
   challenge appears for all accounts — including ones that never configured 2FA.
   Enforcement can be scoped with **per-role exclusions**. (See Configuration.)

   It *appends* Email rather than replacing the provider list, so users who set
   up a stronger factor (TOTP, hardware key / WebAuthn) keep it as their primary
   method, and **backup codes remain available** as a recovery path.

2. **Restricts API logins.** XML-RPC and REST logins bypass the interactive 2FA
   screen. This plugin allows an API login to skip 2FA **only** when *both*:
   - the account is on an explicit allowlist, **and**
   - the request authenticated with an **Application Password** (not the real
     login password).

   Everyone else is denied on the API path.

> [!IMPORTANT]
> For security hardening purposes, it's strongly recommended that you set up Require Email 2FA as a mu-plugin if you establish user role exclusions and/or an API user allowlist.

---

## Installation

Install the plugin folder and activate it like any plugin:

```
wp-content/plugins/force-email-two-factor/force-email-two-factor.php
```

### Activation on multisite (network-only)

On multisite this plugin is **network-only** — you **Network Activate** it
(Network Admin → Plugins), and **per-site activation is refused**.

* `force_2fa_block_single_site_activation()` (a `register_activation_hook` guard)
  rolls back and refuses any per-site activation with a "must be Network Activated"
  notice — covering the admin UI and WP-CLI / programmatic paths alike. Network
  activation passes through.
* A `Network: true` header is intentionally **not** used: core silently *promotes*
  a per-site activation of a network-only plugin to network-wide before the guard
  runs, so it would roll 2FA out network-wide unexpectedly rather than refuse the
  attempt. Explicit rejection is what delivers the "must be Network Activated"
  behavior.
* This is verified end-to-end on a real multisite by `bin/multisite-e2e.sh` (the
  `multisite-e2e` CI job): a per-site activation is refused, `--network` succeeds.

  > [!WARNING]
  > This is deliberate. Users and their Two Factor settings are network-global, so a per-site activation would key enforcement off the **login entry point, not the user** — a global user could authenticate via a site where the plugin was inactive and skip enforcement. Network activation closes that gap.
  >
  > A true network-wide guarantee **also requires the Two Factor plugin to be network-active.** If it's only site-active (or absent) somewhere, enforcement silently no-ops there — the **Network Admin notice** warns you when this is the case and offers a one-click install + network-activate.

On single-site WordPress, just activate it normally.

### Optional: "cannot be deactivated" mode (mu-loader)

WordPress only auto-loads *flat* PHP files in `wp-content/mu-plugins/` — it does
**not** descend into subdirectories. So you keep the full plugin folder where it
is and drop only the included one-line loader alongside it:

1. Keep the plugin at `wp-content/plugins/force-email-two-factor/` (you can leave
   it activated normally, or not — the loader pulls it in either way).
2. Copy the bundled `mu-loader.php` into `wp-content/mu-plugins/` (create that
   directory if it doesn't exist; rename the file if you like).

```text
wp-content/
├── mu-plugins/
│   └── mu-loader.php                  ← only this flat file is auto-loaded
└── plugins/
    └── force-email-two-factor/        ← full plugin stays here
        ├── force-email-two-factor.php
        └── mu-loader.php              ← copy THIS up to mu-plugins/
```

The loader `require`s the plugin from `wp-content/plugins/force-email-two-factor/`,
so the plugin folder must remain in place. A `FORCE_2FA_LOADED` re-load guard makes
it safe to also activate normally. To disable, remove the loader file from
`mu-plugins/`. The `FORCE_2FA_DISABLE` kill switch still applies.

### Before first activation

Email 2FA depends on outbound mail. Confirm transactional email actually delivers
(a working SMTP setup) **before** rolling this out, and keep a known-good admin
session or printed backup codes on hand in case mail is misconfigured. Otherwise
a mail outage can lock out every user who has no stronger factor.

### Operational rollout checklist

Before enabling enforcement on a production site:

- [ ] Confirm SMTP / transactional email delivery works for real user mailboxes.
- [ ] Keep at least one known-good administrator session open during rollout.
- [ ] Generate and safely store backup codes for administrator accounts.
- [ ] Test the login flow with one non-admin user before broad activation.
- [ ] Choose the activation mode deliberately: normal single-site, Network Activate
      for multisite-wide enforcement, or the optional `mu-loader.php`.
- [ ] Identify any REST/XML-RPC service accounts up front and decide whether each
      one should be allowlisted for Application Password authentication.
- [ ] Document the `FORCE_2FA_DISABLE` kill switch in your incident runbook.

---

## Configuration

### Excluding roles from forced 2FA

Enforcement applies to **all** users by default. To exempt specific roles, list
their slugs (lowercase keys like `subscriber`, `customer` — not display names) in
the `FORCE_2FA_EXCLUDED_ROLES` constant:

```php
const FORCE_2FA_EXCLUDED_ROLES = array( 'subscriber', 'customer' );
```

Or, without editing this plugin file, override the effective list from a small
site-specific plugin or mu-plugin:

```php
add_filter( 'force_2fa_excluded_roles', function () {
	return array( 'subscriber', 'customer' );
} );
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

Or override the effective allowlist at runtime from site-specific code:

```php
add_filter( 'force_2fa_api_login_allowlist', function () {
	return array(
		123,            // by user ID — preferred
		'svc_headless', // by user_login
	);
} );
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

If email delivery breaks and users are locked out, you may disable **all** enforcement in the Require Email 2FA plugin without deleting it by adding the following line to your site's `wp-config.php` file:

```php
define( 'FORCE_2FA_DISABLE', true );
```

The plugin checks this constant at load time and registers nothing when it's set. Remove the line (or set it to `false`) to re-enable enforcement.

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

- **Self-hosted updates (no WordPress.org, no collisions):** distributed from
  GitHub, so `force_2fa_bootstrap_self_update()` registers
  [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
  on `plugins_loaded`. It reads the repository from the **`Update URI`** plugin
  header, compares the installed `Version` against the latest GitHub **Release**,
  and offers that release's attached `<slug>.zip` through the normal Dashboard →
  Updates flow and unattended auto-updates.
  [`.github/workflows/release.yml`](.github/workflows/release.yml) builds and
  publishes that zip on every `v*` tag. Self-update is skipped in a working copy
  under version control (a `.git` present), so a dev clone updates via `git`, not
  by having WordPress overwrite it with a release zip.

  Two independent guards keep a same-named WordPress.org plugin from ever hijacking
  the update: WordPress core honours the non-`.org` `Update URI` and declines to
  serve `.org` updates for the slug, and PUC also excludes this plugin from the
  wordpress.org update check by default.

  > [!IMPORTANT]
  > Because updates install straight from GitHub with no WordPress.org review gate,
  > the release pipeline is this plugin's trust boundary. It is hardened so that
  > only reviewed code can reach a release: PUC is **vendored** (committed under
  > `vendor/yahnis-elsts/`, so the exact updater bytes are in-repo and Packagist is
  > not resolved at release time), workflow **Actions are pinned to full commit
  > SHAs** (a moved tag can't inject code into the `contents: write` job), and the
  > release token requests only `contents: write`. Keep branch protection on the
  > tag/release path and review any change to the release workflow, its actions, or
  > the vendored updater.

- **Forking:** point the `Update URI` header at your own repository — that single
  change redirects both the updater and core's update-ownership to your fork.
  (Leaving it on the upstream repo would auto-update every site back to upstream.)
  The slug and download-asset name derive from the plugin folder, so only a rename
  additionally needs the workflow's `PLUGIN_SLUG` updated to match.

---

## Requirements & dependencies

- **Required:** the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin
  (`two-factor`). Soft dependency: this plugin activates on its own and no-ops
  until Two Factor is active, surfacing an admin notice with a one-click
  install/activate button while it is missing. (Through 1.7.0 this was a hard
  `Requires Plugins` gate that blocked activation; 1.8.0 made it soft.)
- **Recommended:** [WebAuthn Provider for Two Factor](https://wordpress.org/plugins/two-factor-provider-webauthn/)
  (`two-factor-provider-webauthn`) for passkeys / hardware keys. Optional — this
  plugin works without it.
- **Testing only:** [WP Mail Logging](https://wordpress.org/plugins/wp-mail-logging/)
  (`wp-mail-logging`) to read 2FA email codes where there is no real mail server
  (e.g. Playground). Not needed in production.
- Working outbound email (SMTP) for the Email provider in production.
- Application Passwords enabled (WordPress core, on by default over HTTPS) for any
  allowlisted service account.

> [!TIP]
> Ensure your WordPress site and/or host's method for sending transactional emails is properly configured and effective. Otherwise, you and your users may be locked out of the site when the email passcodes do not arrive or land in spam folders.

---

## Compatibility & interaction with other 2FA setups

This plugin works **exclusively through the [Two Factor](https://wordpress.org/plugins/two-factor/)
plugin** — it hooks Two Factor's filters and the `Two_Factor_Email` provider, and
does nothing else.

- **Two Factor already active (with or without the WebAuthn provider):** fully
  additive. The Email provider is *appended* to each user's enabled providers, never
  substituted. Users who already set up TOTP, WebAuthn/passkeys, or backup codes
  **keep them as their primary factor**; email is just added as an always-available
  floor. Existing per-user settings are untouched — deactivating this plugin reverts
  everyone to exactly what they had.

- **Users with no 2FA yet:** email enforcement applies at their next login.

- **Two Factor not active:** this plugin still activates, but the runtime
  `force_2fa_dependency_met()` guard (a `class_exists( 'Two_Factor_Email' )` check)
  makes it a **safe no-op** — no errors, no enforcement — and an admin notice warns
  that 2FA is not being enforced, with a one-click button to install/activate Two
  Factor. The same guard covers the case where Two Factor is disabled later.

- **A different 2FA plugin** (Wordfence Login Security, WP 2FA, miniOrange, Duo,
  etc.): this plugin **does not integrate with or affect them** — they don't expose
  Two Factor's hooks. A competing 2FA plugin also does **not** satisfy this
  dependency: without the actual Two Factor plugin active, this plugin stays a
  no-op and keeps prompting you to install it.

> [!CAUTION]
> **Don't run two 2FA enforcement stacks at once.** If both Two Factor (with this plugin) and a separate 2FA plugin gate the login flow, you risk double prompts or lockouts. Pick one stack; this plugin assumes that stack is Two Factor.

> [!NOTE]
> **Edge case:** if an admin has deliberately unregistered the Email provider via the `two_factor_providers` filter, the provider class still exists, so email is still appended — but Two Factor won't resolve it as a usable factor, so email enforcement won't actually take effect.

---

## Known limitations

- **Mail delivery is part of the security boundary.** If outbound email fails,
  users without a stronger factor can be locked out until mail is fixed or
  `FORCE_2FA_DISABLE` is enabled.
- **Email passcodes expire.** Two Factor email codes are valid for 15 minutes by
  default. An expired code is rejected like any invalid code; the user should use
  **Resend Code** or restart the login to generate a fresh email code. Repeated
  invalid attempts are handled by the Two Factor plugin's rate limiting and
  failed-attempt protections.
- **Only the Two Factor plugin is enforced.** Other 2FA plugins are not affected,
  and they do not satisfy the Two Factor dependency this plugin needs.
- **Multisite is network-only, and depends on Two Factor being network-active.**
  Per-site activation is blocked; but even network-activated, a true network-wide
  guarantee requires the Two Factor plugin to *also* be network-active (the
  Network Admin notice warns when it isn't).
- **API bypasses are intentionally narrow.** XML-RPC/REST logins can skip the
  interactive challenge only for allowlisted accounts using Application Passwords.
- **Email can be weaker than TOTP/WebAuthn.** This plugin uses Email as a universal
  no-setup floor while preserving stronger factors users have already configured.


---

## Contributing, support, and security

- See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, test commands, and pull request expectations.
- See [SUPPORT.md](SUPPORT.md) for support boundaries and the information needed in bug reports.
- See [SECURITY.md](SECURITY.md) to report vulnerabilities privately.
- This project follows a [Code of Conduct](CODE_OF_CONDUCT.md).

---

## Development

> **PHP version:** the *plugin* supports PHP 7.2+ (enforced by the lint matrix and
> PHPCompatibility). The *dev tooling* (PHPUnit 11) requires **PHP 8.2+**, so run
> `composer install` and the test suite on PHP 8.2 or newer.

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
bash bin/multisite-e2e.sh           # disposable real multisite, network-only guard
bash bin/update-e2e.sh              # disposable real site, GitHub Release update path
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
  clover uploaded as an artifact
- **Playground integration** — boots a real WordPress + the real Two Factor
  plugin headlessly and asserts enforcement end-to-end (see
  [`playground/ci-blueprint.json`](playground/ci-blueprint.json))
- **Multisite E2E** — boots a disposable SQLite-backed multisite and asserts
  per-site activation is refused while network activation succeeds.
- **Update E2E** — boots a disposable SQLite-backed site, copies this working tree
  as an older installed plugin, forces an update check, and asserts WordPress
  updates it from the latest GitHub Release `force-email-two-factor.zip` asset.

The config constants (`FORCE_2FA_EXCLUDED_ROLES`, `FORCE_2FA_API_LOGIN_ALLOWLIST`)
are read through filter accessors (`force_2fa_excluded_roles`,
`force_2fa_api_login_allowlist`), so values are overridable at runtime and
injectable in tests.

### Release checklist

Before tagging a release:

- [ ] Update `Version` and `FORCE_2FA_LOADED` in `force-email-two-factor.php`.
- [ ] Update `Stable tag`, changelog, and compatibility fields in `readme.txt`.
- [ ] Update README badges or examples if version/support claims changed.
- [ ] Run `composer check`.
- [ ] Run `vendor/bin/phpunit --coverage-text` with PCOV or Xdebug if validating
      local coverage.
- [ ] Smoke-test at least one real login flow (sign in → email 2FA challenge → in).
- [ ] Tag the release as `vX.Y.Z` and push the tag. The `Release` workflow verifies
      the tag matches the `Version` header, builds `force-email-two-factor.zip`
      (plugin + production `vendor/`), and publishes it as a Release asset — this
      asset is what Plugin Update Checker serves to installed sites, so a release is
      not complete until the workflow has published it.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
