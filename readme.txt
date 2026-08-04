=== Require Email 2FA ===
Contributors: dpknauss
Tags: two-factor, 2fa, security, authentication, login
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.13.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Requires the Two Factor plugin and makes emailed 2FA a required login factor for all users, with optional capability-scoping (e.g. admins-only) and per-role exclusions.

== Description ==

Builds on the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin and
makes its emailed 2FA codes a mandatory baseline for every user — so the login
challenge appears even for accounts that never set up two-factor. Enforcement can
optionally be narrowed to a capability (e.g. admins-only) or scoped with per-role
exclusions. Two Factor must
be active for any enforcement to happen; if it is not, this plugin activates but
stays a no-op and shows an admin notice with a one-click installer. On multisite
it is network-only: Network Activate it (per-site activation is blocked).

It does two things:

1. **Forces 2FA for everyone (by default).** It ensures the always-available,
   zero-setup Email provider is enabled for every user, so the login challenge
   appears for all accounts — including those that never configured 2FA.
   Enforcement is appended rather than replacing the user's provider list, so
   users who set up a stronger factor (TOTP, hardware key / WebAuthn) keep it as
   their primary method, and backup codes remain available as a recovery path.
   Enforcement can optionally be narrowed to a capability via
   `FORCE_2FA_ENFORCED_CAPABILITY` (e.g. `manage_options` for admins-only) and
   further scoped with per-role exclusions.

2. **Restricts XML-RPC logins.** Non-interactive logins bypass the interactive
   2FA screen. This plugin permits such a login to skip 2FA only when both the
   account is on an explicit allowlist and the request authenticated with an
   Application Password (never the real login password); everyone else is denied.
   Scope note: this allowlist governs XML-RPC, not REST. REST Application Password
   logins authenticate via WordPress core's determine_current_user path and are
   never gated by Two Factor, so the allowlist does not restrict them — limit REST
   access via each account's role/capabilities instead. See the FAQ.

= Plugin dependencies =

* **Required:** Two Factor (`two-factor`). This plugin activates on its own and
  no-ops until Two Factor is active; while it is missing, an admin notice warns
  that 2FA is not being enforced and offers a one-click install/activate from
  WordPress.org. (Through 1.7.0 this was a hard `Requires Plugins` gate that
  blocked activation; 1.8.0 replaced it with this softer, guided flow.)
* **Recommended:** WebAuthn Provider for Two Factor (`two-factor-provider-webauthn`)
  for passkeys / hardware security keys. Optional — this plugin works without it.
* **Testing only:** WP Mail Logging (`wp-mail-logging`) to read 2FA email codes in
  environments with no real mail server (e.g. WordPress Playground). Not needed in
  production.

= Features =

* Mandatory email two-factor as a universal floor, with no per-user setup.
* Stronger user-configured factors (TOTP, WebAuthn) and backup codes preserved.
* Optional capability-scoping to narrow enforcement (e.g. admins-only) via
  `FORCE_2FA_ENFORCED_CAPABILITY`; default is all users.
* Per-role exclusions, defaulting to "all users" (`FORCE_2FA_EXCLUDED_ROLES`).
* A `force_2fa_user_is_exempt` filter for one-off, per-user exemptions.
* Service-account allowlist for API logins, gated on Application Passwords.
* Emergency kill switch via a wp-config constant (`FORCE_2FA_DISABLE`).

== Installation ==

1. Install the plugin folder at
   `wp-content/plugins/force-email-two-factor/` and activate it.
2. On multisite the plugin is network-only: **Network Activate** it (Network
   Admin → Plugins). A per-site activation is refused — an activation-hook guard
   rolls it back with a "must be Network Activated" notice, covering the admin UI
   and WP-CLI / programmatic paths. For a true network-wide guarantee, also
   **network-activate Two Factor**; the Network Admin notice warns you when it isn't.
3. Optional "cannot be deactivated" mode: WordPress only auto-loads flat PHP
   files in `wp-content/mu-plugins/` (it does not descend into subdirectories), so
   keep the full plugin folder in `wp-content/plugins/force-email-two-factor/` and
   copy ONLY the bundled `mu-loader.php` into `wp-content/mu-plugins/`. The loader
   then force-loads the plugin on every request. To disable, remove the loader
   file. A `FORCE_2FA_LOADED` guard makes it safe to also activate normally.

The [Two Factor](https://wordpress.org/plugins/two-factor/) plugin must be
installed and active. Confirm outbound email (SMTP) delivers reliably before
rollout, since email becomes the required factor for users with no stronger one.

= Rollout checklist =

Before production activation:

* **Email is the second factor, so email must work, or people get locked out.**
  Confirm SMTP / transactional email delivery works.
* **Keep a way back in while you roll out.** Keep a known-good administrator session open.
* **Have a recovery path if mail fails.** Generate and safely store administrator backup codes.
* **Prove the flow before you trust it.** Test one non-admin login before broad rollout.
* **Decide how enforcement turns on.** Choose the right activation mode: single-site,
  Network Activate, or `mu-loader.php`.
* **Plan for integrations that can't read an email code.** Identify XML-RPC service
  accounts and decide whether they need allowlisting. (The allowlist covers XML-RPC,
  not REST; scope REST integrations by role/capability instead.)
* **Don't assume SSO logins are covered.** If the site uses SSO/SAML/OIDC/OAuth/LDAP/
  Jetpack SSO, test both SSO and direct `wp-login.php` on staging; enforce MFA at the
  identity provider.
* **Write down the escape hatch before you need it.** Document the `FORCE_2FA_DISABLE`
  kill switch in your incident runbook.

== Frequently Asked Questions ==

= Does it still require the Two Factor plugin? =

Yes — Two Factor (`two-factor`) provides the Email provider this plugin makes
mandatory, so nothing is enforced without it. As of 1.8.0 the dependency is no
longer a hard activation gate. This plugin activates on its own, no-ops while Two
Factor is inactive, and shows an admin notice with a one-click install/activate
button. The `Requires at least: 6.8` floor tracks Two Factor's own requirement:
the current Two Factor release requires WordPress 6.8+, so on older WordPress the
one-click installer cannot install it and nothing can be enforced. Run WordPress
6.8 or newer (or install a Two Factor build that supports your WordPress version).

= What if email delivery breaks and users are locked out? =

Add `define( 'FORCE_2FA_DISABLE', true );` to `wp-config.php`. The plugin checks
this at load time and registers nothing while it is set. Remove the line to
re-enable enforcement.

= What if an email code times out? =

Two Factor email codes are valid for 15 minutes by default. An expired code is
rejected like any invalid code; use **Resend Code** or restart login to generate
a fresh email code. Repeated invalid attempts are handled by the Two Factor
plugin's rate limiting and failed-attempt protections.

= Who is required to use 2FA, and how do I change it? =

By default, every user is. Right after you activate the plugin, a one-time admin notice
lets you pick the scope with a radio — All users / Contributors and up / Administrators only
(administrators pre-selected) — and one click saves it; until you choose, the secure
default (all users) applies. To change the choice later, deactivate and reactivate the
plugin.

For a code-managed setup, define `FORCE_2FA_ENFORCED_CAPABILITY` in wp-config.php with
the capability a user must hold to be in scope (this overrides the first-run choice and
hides the prompt) — for administrators only:

`
define( 'FORCE_2FA_ENFORCED_CAPABILITY', 'manage_options' );
`

(or `add_filter( 'force_2fa_enforced_capability', function () { return 'manage_options'; } );`).
Use a lower capability such as `edit_posts` to cover contributors and up. Leaving it
undefined (or `''`) keeps the default of all users. On multisite the check is
network-wide: a user is in scope if they are a super admin or hold the capability on
any site they belong to (WordPress logins are network-wide, so a per-site check would
let an admin of one subsite log in through another and skip enforcement).

Note: narrowing the scope does NOT weaken the XML-RPC allowlist. It is enforced
independently of the 2FA scope — every XML-RPC login is held to the allowlist +
Application-Password policy for all users, in scope or not — so scoping interactive 2FA
to admins never opens XML-RPC to everyone else. (This governs XML-RPC, not REST; REST
Application-Password logins bypass the authenticate chain.) Per-role exclusions still
apply on top for the interactive challenge.

= Where do these settings go — wp-config.php or a filter? =

Every `FORCE_2FA_*` constant can go in **wp-config.php** — they are all read with
`defined()`, so `define()`-ing any of them is safe (no "cannot redeclare constant"
fatal): `FORCE_2FA_DISABLE`, `FORCE_2FA_ENFORCED_CAPABILITY`, `FORCE_2FA_EXCLUDED_ROLES`,
`FORCE_2FA_API_LOGIN_ALLOWLIST`, `FORCE_2FA_BLOCKING_MODE`, `FORCE_2FA_DISABLE_SELF_UPDATE`.

Filters must NOT go in wp-config.php — `add_filter()` is not defined yet when
wp-config.php runs, so a filter there fatals. Put `force_2fa_enforced_capability`,
`force_2fa_excluded_roles`, `force_2fa_api_login_allowlist`, `force_2fa_user_is_exempt`,
etc. in a small plugin, a companion mu-plugin, or your theme's functions.php. A filter
overrides its constant; the `FORCE_2FA_DISABLE` kill switch is constant-only.

If you use the mu-loader ("cannot be deactivated" mode), keep filter-based config in a
companion mu-plugin so it is force-loaded alongside the plugin. Create a second flat
file in wp-content/mu-plugins/, e.g. force-2fa-config.php:

`
<?php
// wp-content/mu-plugins/force-2fa-config.php
add_filter( 'force_2fa_excluded_roles', function () {
	return array( 'subscriber', 'customer' );
} );
add_filter( 'force_2fa_api_login_allowlist', function () {
	return array( 'svc-deploy', 'svc-monitoring' );
} );
`

Load order is fine: the plugin reads these filters lazily during login (when Two
Factor evaluates a user's providers), long after every mu-plugin, plugin, and theme
has registered its filters — so mu-loading the plugin early never races the config.

= How do I exempt a role from forced 2FA? =

Set the role slugs (lowercase keys such as `subscriber`, not display names) in the
`FORCE_2FA_EXCLUDED_ROLES` constant in wp-config.php:

`
define( 'FORCE_2FA_EXCLUDED_ROLES', array( 'subscriber', 'customer' ) );
`

This carves roles out of the enforcement *scope* (see above); a user is exempt only if
every role they hold is on the list, so excluding a low-privilege role can never
accidentally exempt a privileged account that also holds a higher role.

You can also set the effective list at runtime with the `force_2fa_excluded_roles`
filter (from a plugin, a companion mu-plugin, or your theme — not wp-config.php):

`
add_filter( 'force_2fa_excluded_roles', function () {
	return array( 'subscriber', 'customer' );
} );
`

= What if users already use another 2FA plugin, like Wordfence? =

Users whose 2FA is handled by Wordfence Login Security are skipped — the emailed floor is
not added for them, so no one is pushed through two 2FA plugins at once (even though
Wordfence offers no email method). The Wordfence check is built in and fails safe: if it
errors, the email floor stays in place. To skip users protected by another external 2FA
system, use the `force_2fa_user_is_exempt` filter to return true for them. Users on the
Two Factor plugin are unaffected — email is still their floor, and their TOTP/WebAuthn
stays primary.

= How do I let an integration log in over XML-RPC? =

Add its user ID or login to the `FORCE_2FA_API_LOGIN_ALLOWLIST` constant in
wp-config.php (or via the `force_2fa_api_login_allowlist` filter from a plugin /
companion mu-plugin / theme), and have it authenticate with an Application Password.
A real-password XML-RPC login is always denied, even for allowlisted accounts.

`
define( 'FORCE_2FA_API_LOGIN_ALLOWLIST', array( 123, 'svc_headless' ) );
`

Important: this allowlist governs XML-RPC, not the REST API. Two Factor's only
API-login gate runs on the `authenticate` filter, which XML-RPC uses; REST
Application Password logins authenticate via WordPress core's
`determine_current_user` path and never reach that gate, so the allowlist does not
restrict them. Any account with an Application Password can authenticate over REST
regardless of the allowlist. To limit REST access, give each account the least
role/capabilities it needs (Application Passwords inherit the user's caps), turn
off Application Passwords for users who shouldn't have them
(`wp_is_application_passwords_available_for_user`), or add a `rest_authentication_errors`
gate.

You can also override the effective allowlist without editing this plugin:

`
add_filter( 'force_2fa_api_login_allowlist', function () {
	return array( 123, 'svc_headless' );
} );
`

= Can I use this with SSO, SAML, OAuth, OIDC, LDAP, or Jetpack SSO? =

Use caution. This plugin enforces email 2FA through the Two Factor plugin's normal
WordPress login and API-login hooks; it is not an SSO MFA enforcement layer. Some
SSO plugins bypass the normal local login challenge, while others may trigger a
second local Two Factor prompt or conflict with the SSO callback.

If SSO is your primary login path, enforce MFA at the identity provider, test both
SSO and direct `wp-login.php` paths on staging, and keep a break-glass administrator
account with known-good local access and backup codes.

= How does it work on multisite? =

It is network-only: you must Network Activate it, and per-site activation is
blocked. This is deliberate — users are network-global, so a per-site install
would let someone log in via a site where the plugin is inactive and skip
enforcement. Network activation closes that gap. Note that a true network-wide
guarantee also requires the Two Factor plugin to be network-active; if it is only
site-active (or absent) somewhere, enforcement silently no-ops there, and the
Network Admin notice will tell you. For an un-deactivatable install, use the
bundled `mu-loader.php`.

= How does the plugin update, and can I turn that off? =

It updates itself from its GitHub Releases (not WordPress.org) via the bundled
Plugin Update Checker: new versions appear under **Dashboard &rarr; Updates** and
through unattended auto-updates. On sites patched by a central manager (MainWP,
Composer, git deploys), turn the self-updater off so each site does not poll
GitHub:

`
define( 'FORCE_2FA_DISABLE_SELF_UPDATE', true );
`

Your management layer then delivers updates, and enforcement is unchanged. A
**Tools &rarr; Site Health** check reports each site's update posture. See
`docs/DEPLOYMENT.md` for the managed-vs-standalone deployment guide.

= Where do updates come from, and is the supply chain hardened? =

Updates install from GitHub Releases with no WordPress.org review gate, so the
release-publishing path is the plugin's trust boundary — a published release runs
on every site that auto-updates. The pipeline is hardened: the updater is vendored,
Actions are pinned to commit SHAs, the build is a reproducible `git archive`, only
the reviewed release asset installs, and each release carries a SHA-256 checksum you
can verify (plus a build-provenance attestation on public repositories; private
Free/Pro/Team forks are checksum-only, as attestations need GitHub Enterprise Cloud).
If you fork the plugin to serve
your own sites, point the `Update URI` header at your own repository and re-apply
the repository protections. Full guide — GitHub settings, artifact verification,
safe forking, incident response — in `docs/SUPPLY-CHAIN-SECURITY.md`:
https://github.com/we-are-pixel/Require-Email-2FA/blob/main/docs/SUPPLY-CHAIN-SECURITY.md

= Does this remove a user's authenticator app or hardware key? =

No. It appends the Email provider as a floor; any stronger factor the user
configured stays in place and remains their primary method.

= What are the known limitations? =

Mail delivery is part of the security boundary; if outbound email fails, users
without a stronger factor can be locked out until mail is fixed or
`FORCE_2FA_DISABLE` is enabled. This plugin only enforces the Two Factor plugin
and does not integrate with other 2FA plugins or enforce MFA inside external SSO
providers. On multisite it is network-only (per-site activation is blocked); a true
network-wide guarantee also depends on Two Factor itself being network-active. The
API-login allowlist governs XML-RPC only: an XML-RPC login can skip the interactive
challenge only for allowlisted accounts using Application Passwords, while REST
Application Password logins are not gated by Two Factor and are not restricted by
the allowlist (scope REST access via roles/capabilities). Extending the allowlist to
cover REST is on the roadmap (issue #41). The XML-RPC allowlist is enforced for every
account independently of the 2FA scope, so narrowing the scope (or excluding a role)
never opens XML-RPC to accounts left out of the interactive challenge.

== Changelog ==

= 1.13.2 =
* Fixed: the first-run enforcement-scope prompt offered the `edit_posts` option under the
  label "Editors and up", but that capability is held by contributors and authors too, so
  choosing it enforced the emailed floor on everyone who can write posts — broader than
  the label promised. The option now reads "Contributors and up (anyone who can write
  posts)", matching what it has always done. Wording only: the stored capability is
  unchanged, so enforcement on existing installs is unaffected and no re-prompt is needed.

= 1.13.1 =
* New: users whose 2FA is handled by Wordfence Login Security are exempt from the
  emailed floor, so no one is driven through two 2FA plugins at once. The Wordfence
  check is built in and fail-safe (any detection error leaves the floor in place); to
  skip users on another external 2FA system, use the existing `force_2fa_user_is_exempt`
  filter. Email remains a floor for everyone else, including users who already have a
  native Two Factor method (TOTP/WebAuthn get their method AND email).
* Improved: the emailed method becomes the *primary* provider only when the user has no
  other real method (no method, or backup-codes-only). A real method (TOTP, WebAuthn,
  …) always keeps primary — the appended email floor no longer demotes it — via Two
  Factor's `two_factor_primary_provider_for_user` filter. Stored settings are not
  mutated.

= 1.13.0 =
* New: a first-run **enforcement-scope prompt**. On activation an admin notice offers a
  one-time choice — All users / Editors and up / Administrators only (administrators
  pre-selected) — folded into the existing setup notices, not a standing settings page.
  Until a choice is made the secure default (all users) applies; the choice is stored,
  cleared on deactivation (so reactivating re-prompts — the "start over" path), and
  purged on uninstall. A `FORCE_2FA_ENFORCED_CAPABILITY` constant overrides the choice
  and suppresses the prompt (infra-as-code wins).
* Changed: the **XML-RPC API-login allowlist is now enforced independently of the 2FA
  scope**. Previously, narrowing enforcement (or excluding a role) dropped out-of-scope
  accounts from the allowlist too; now every XML-RPC login is held to the allowlist +
  Application-Password policy for all users, whatever the scope. (Governs XML-RPC, not
  REST.)
* Fixed: `FORCE_2FA_EXCLUDED_ROLES` and `FORCE_2FA_API_LOGIN_ALLOWLIST` are now read via
  `defined()`, so setting them in wp-config.php no longer fatals with "cannot redeclare
  constant". Every FORCE_2FA_* constant is now safe to define in wp-config.php.
* Docs: a "Where configuration goes" section — constants in wp-config.php, filters in a
  plugin/companion mu-plugin/theme — plus a companion-mu-plugin example.
* Performance: the per-user enforcement-scope evaluation is memoized for the duration of
  a request (flushed per user on `clean_user_cache`), so the network-wide capability/role
  lookup no longer repeats each time Two Factor reads a user's providers. No behavior
  change.

= 1.12.0 =
* New **optional capability-scoping** to narrow enforcement to privileged accounts.
  Define `FORCE_2FA_ENFORCED_CAPABILITY` in wp-config.php (or use the
  `force_2fa_enforced_capability` filter) with the capability a user must hold to be
  forced — e.g. `manage_options` for admins-only, or `edit_posts` for contributors and
  up. **The default is unchanged: all users are still enforced** (the constant
  defaults to `''`). Read via `defined()` (like `FORCE_2FA_DISABLE`) so it is safe to
  `define()` in wp-config.php.
* The capability check is network-wide on multisite: a user is in scope if they are a
  super admin or hold the capability on any site they belong to (so an admin of one
  subsite can't skip enforcement by logging in through another). The per-site role
  exclusion (`FORCE_2FA_EXCLUDED_ROLES`) is likewise evaluated across the user's sites.
* Raised the minimum WordPress version to 6.8, matching the current Two Factor
  release's own requirement (Two Factor 0.16.0 requires WordPress 6.8+); on older
  WordPress the one-click installer cannot install it and nothing enforces.
* Note: narrowing the scope also removes out-of-scope accounts from the XML-RPC
  API-login hardening (governs XML-RPC, not REST), the same trade-off as
  `FORCE_2FA_EXCLUDED_ROLES` — documented in the FAQ. `FORCE_2FA_EXCLUDED_ROLES` and
  the `force_2fa_user_is_exempt` filter are unchanged. No change to blocking mode or
  the kill switch.

= 1.11.0 =
* New optional **blocking mode** (`FORCE_2FA_BLOCKING_MODE`, or the
  `force_2fa_blocking_mode_enabled` filter; **off by default**). When enabled, a
  logged-in, non-exempt user who has not configured any two-factor method is
  redirected to their profile page — where the Two Factor setup UI lives — until they
  enable one. It keys "configured" off Two Factor's own stored providers so setup
  genuinely clears the gate, never gates the profile/setup screen or AJAX/REST/cron/
  XML-RPC/WP-CLI paths that 2FA setup depends on, respects excluded roles and the
  `FORCE_2FA_DISABLE` kill switch, and no-ops when Two Factor is inactive — so it
  cannot dead-end or lock a site. Existing installs are unaffected unless they opt in.

= 1.10.6 =
* Docs: added a "Security model" section stating plainly what the plugin enforces
  and — importantly — what it does not. The API-login allowlist governs XML-RPC,
  NOT the REST API: REST Application Password logins authenticate via WordPress
  core's determine_current_user path and are not gated by Two Factor or the
  allowlist. Corrected the README/readme where they implied REST was covered, added
  guidance to scope REST access via roles/capabilities, and added plain-language
  lead sentences to the rollout checklist.
* Tests: substantially expanded unit and end-to-end coverage — the Two-Factor-absent
  fail-safe, dependency-notice rendering, install-handler guards, the emergency
  kill switch and mu-loader behavior, the XML-RPC API-login allowlist, and the
  one-click installer — plus a combined-coverage floor check in CI.
* No change to enforcement behavior — functionally identical to 1.10.5.

= 1.10.5 =
* Pixel fork: publish a fresh fork-channel release after upstream v1.10.4, keeping
  Pixel-owned sites on `we-are-pixel/Require-Email-2FA` for self-updates.
* No change to 2FA enforcement behavior — functionally identical to 1.10.4.
* Merged from upstream: uninstall cleanup — deleting the plugin now removes the
  only persistent data it can leave behind, the bundled Plugin Update Checker's
  cached-update option (`external_updates-*`) and its update-check cron event
  (`puc_cron_check_updates-*`), on every site, including multisite. Deactivating
  already stopped all enforcement immediately; the plugin still stores no
  options, user meta, or transients of its own.
* Merged from upstream: tests — added single-site and multisite end-to-end
  coverage asserting uninstall leaves no Plugin Update Checker option or cron
  behind.
* No change to enforcement behavior beyond the uninstall cleanup above.

= 1.10.4 =
* Pixel fork: point the plugin and self-updater at `we-are-pixel/Require-Email-2FA`,
  so Pixel-owned sites installed from this fork receive updates from the Pixel release
  channel rather than the upstream `dknauss` repository.
* Repo polish: badge and Playground links now target the Pixel fork.
* No change to 2FA enforcement behavior — functionally identical to 1.10.3.

= 1.10.3 =
* Security tooling: CodeQL Actions analysis now uses SHA-pinned actions and the
  `security-extended` query set, matching the repository supply-chain convention.
* Security tooling: added Semgrep PHP SAST (`p/php` and `p/security-audit`) with
  SARIF upload to GitHub code scanning. Findings are report-only, while scanner,
  configuration, or SARIF-upload failures still fail the job.
* Supply chain: documented the fork-release approval caveat — forks must create
  and protect their own `release` environment before the first tag if they want a
  human approval pause.
* No change to enforcement behavior — functionally identical to 1.10.2.

= 1.10.2 =
* Docs: clarified that this plugin enforces through the Two Factor plugin's normal
  WordPress login/API hooks and is **not** an SSO/SAML/OIDC/OAuth/LDAP MFA layer.
  Enforce MFA at your identity provider, keep a break-glass administrator, and test
  both the SSO callback and the direct `wp-login.php` path. Adds an SSO FAQ entry and
  a rollout-checklist note.
* Maintenance: releases are now published with a SHA-256 checksum and a build
  provenance attestation, and the release pipeline is hardened (reviewed-commit gate,
  SHA-pinned actions, optional release-approval gate). See docs/SUPPLY-CHAIN-SECURITY.md.
* No change to enforcement behavior — functionally identical to 1.10.1.

= 1.10.1 =
* Admin UX: the dependency notice and its one-click button now reflect whether the
  Two Factor plugin is missing versus installed-but-inactive. When it is already
  installed, the button reads **Activate Two Factor** (or **Network-activate Two
  Factor** on multisite) and the copy says "installed but not active" rather than
  always offering to install it. The multisite per-site heads-up notice is likewise
  accurate about installed-but-inactive versus not installed. And when Two Factor is
  active but its Email provider has been removed (e.g. via the `two_factor_providers`
  filter), the notice says the provider must be restored instead of offering an
  Activate button that would do nothing. On multisite this restore-the-provider
  warning now also appears on the **Network Admin** plugins screen when Two Factor is
  network-active but its Email provider is unavailable — otherwise that gap (2FA
  silently not enforced network-wide) was only visible on individual site screens.
* Admin UX: after deleting Two Factor via the Plugins screen's AJAX "Delete" link,
  the dependency notice updates its copy in place — and scrolls into view — instead
  of showing stale pre-delete text, with no jarring full-page reload. This works on
  both the single-site notice and the **Network Admin** notice, and the repainted
  copy follows the screen: **Install & network-activate Two Factor** in Network
  Admin, **Install & activate Two Factor** on a single site — so the button never
  offers to activate a plugin that is no longer installed. If the current user can
  activate but not install plugins, the notice reloads (rendering the correct
  non-actionable message) rather than showing an install button they can't use.
  (The plugin's first, tiny admin script, loaded only on the Plugins screen.)

= 1.10.0 =
* Deployment: new `FORCE_2FA_DISABLE_SELF_UPDATE` constant (and
  `force_2fa_self_update_enabled` filter) to turn off the self-updater on sites
  patched by a central manager (MainWP, Composer, git deploys), so each site does
  not independently poll GitHub. One codebase, two modes; enforcement is unchanged.
  See `docs/DEPLOYMENT.md`.
* Site Health: a new **Tools &rarr; Site Health** check reports whether — and why —
  self-update is running (active, intentionally disabled, a working-copy `.git`,
  missing updater files, or a blank Update URI), instead of a page-nagging notice.
* Updater hardening: the release-asset match is anchored to exactly
  `force-email-two-factor.zip` (defense-in-depth).
* Removed the pre-1.9.0 per-site "legacy activation" migration notice. Since 1.9.0
  blocks per-site activation, no new install can reach that state, so the notice
  guarded a scenario that can no longer occur.
* i18n: use a literal `&` in the install-button labels (translators no longer see
  a raw `&amp;` entity).
* Tests & docs: added coverage for the Application-Password user binding, the
  enforcement filter's argument count, and the dependency-unmet fail-safe;
  corrected stale hook-ordering and dependency-guard descriptions.

= 1.9.1 =
* Updater: **require** the release asset. The self-updater now installs only the
  reviewed `force-email-two-factor.zip` and never falls back to GitHub's source
  archive, so a release missing that asset offers no update instead of installing
  a wrongly-structured one (`REQUIRE_RELEASE_ASSETS`).
* Security: bind the API-login Application Password check to the authenticating
  account. The bypass now requires that *this* user presented an Application
  Password this request, not merely that some app-password authentication happened.
* Enforcement: treat the dependency as met only when Two Factor actually registers
  the Email provider (checks `Two_Factor_Core::get_providers()`), not merely that
  the class exists — so a removed-Email edge case is reported instead of silently
  no-enforcing.
* Admin UX: the dependency notices show the one-click install button only when the
  current user holds every capability its handler needs; otherwise they inform
  without an action that would be denied.
* Release workflow: run the PHPCS + PHPStan + PHPUnit gate against the tagged commit before
  building and publishing the update asset.
* Self-updates from a git working copy are skipped (a `.git` present), so a dev
  clone is not overwritten by a release zip.

= 1.9.0 =
* Self-hosted updates: the plugin updates itself from its GitHub Releases (via the
  bundled Plugin Update Checker) rather than WordPress.org. The `Update URI` header
  is the source of truth and stops a same-named .org plugin from serving updates.
* The updater is vendored (committed) and installs a reviewed `force-email-two-factor.zip`
  release asset built by a tag-triggered workflow; workflow Actions are pinned to
  commit SHAs.
* Docs: add an FAQ explaining expired email 2FA codes.
* Multisite: the plugin is now **network-only**. A per-site activation is refused
  by an activation-hook guard (rolled back with a "must be Network Activated"
  notice, covering the admin UI and WP-CLI / programmatic paths), so enforcement
  can't be left with per-site gaps a network-global user could slip through.
  (A "Network: true" header is intentionally avoided — it would make core silently
  promote a per-site activation to network-wide instead of refusing it.)
* Add a **Network Admin notice**: when the plugin is network-active but Two Factor
  is not network-active, it warns that 2FA is not enforced network-wide and offers
  a one-click install + network-activate of Two Factor.
* Add a per-site **heads-up notice** on multisite: on any site where Two Factor is
  not loaded, site admins see that enforcement is off there and to contact the
  network admin (non-actionable — the fix lives in Network Admin).
* The one-click installer network-activates Two Factor when this plugin runs
  network-wide — including when it is loaded via the mu-loader (which now also
  triggers the Network Admin notice) — and checks `install_plugins` (to install)
  and `manage_network_plugins` (to network-activate) independently.
* Add a migration notice for installs that were activated per-site before 1.9.0:
  the activation guard only blocks NEW per-site activations, so a super admin is
  nudged to Network Activate an existing per-site install.
* Tests cover the network nag decision, the network capability rule, the per-site
  activation block, and the legacy per-site migration warning. A real multisite
  end-to-end check (`bin/multisite-e2e.sh`, run in CI) asserts that activation
  lands network-wide and the plugin safely no-ops when Two Factor is absent.

= 1.8.1 =
* Plugin header: add `Plugin URI` and `Text Domain`; set the author to Pixel
  (with `Author URI: https://wearepixel.ca`); remove the invalid `Network: false`
  header (it may only be `true`, otherwise omitted) flagged by Plugin Check.
* readme: correct the `Contributors` username to `dpknauss`.
* Add an interactive WordPress Playground "try it live" demo that lands on the
  Plugins screen with the guided Two Factor install notice and sample users.
* Tooling: enable the `WordPress.WP.I18n` sniff with a pinned text domain; bump
  pinned GitHub Actions (checkout, setup-node, upload-artifact).
* No plugin runtime or behavior changes.

= 1.8.0 =
* Replace the hard `Requires Plugins: two-factor` activation gate with a soft
  dependency: the plugin now activates on its own and no-ops until Two Factor is
  active, instead of failing activation with a "required plugins are missing"
  error.
* Add an admin notice, shown while Two Factor is inactive, warning that 2FA is
  NOT being enforced — with a one-click button that installs and activates Two
  Factor from WordPress.org (capability- and nonce-checked).
* Fold the enforcement filter's provider check into a single
  `force_2fa_dependency_met()` helper, reused by the notice.
* Tests: cover the nag gating and the required-capability logic.
* Docs: clarify the mu-plugin setup (flat loader in `mu-plugins/`, full plugin
  stays in `plugins/`) with a directory layout and how to disable it.

= 1.7.0 =
* No plugin runtime changes since 1.6.1 — this release is packaging and docs only.
* Playground: fix the demo login flow and the on-challenge 2FA-code display.
* README: rework badges (drop the static version badge; add a "Requires Two
  Factor" badge) and add a maintenance/release checklist.
* Update repository URLs after the GitHub repo was renamed to `Require-Email-2FA`
  (badges, Playground one-click link, issue-template links, Composer name).
* Correct the 1.6.1 changelog below to reflect everything that release shipped.

= 1.6.1 =
* Rename the plugin to "Require Email 2FA".
* Harden configuration handling: normalize role and allowlist values from the
  constants and filters, ignoring null, non-scalar, and empty entries without
  emitting PHP warnings.
* Add a WordPress Playground demo helper that prints the email 2FA code on the
  challenge screen (Playground/demo environments only — not for production).
* Add a production rollout checklist and expand the FAQ (runtime overrides for
  the `force_2fa_excluded_roles` / `force_2fa_api_login_allowlist` filters, and a
  known-limitations entry).
* Add a "Compatibility & interaction with other 2FA setups" section.
* Shorten and clarify the plugin description.
* Set "Requires at least" to 6.5 to match the `Requires Plugins` dependency
  gating (the header is ignored on older WordPress) and document this in the FAQ.
* Fix stale "must-use plugin" wording in the readme.
* Drop the Codecov badge/upload; keep coverage reporting in CI.

= 1.6.0 =
* Add filter accessors `force_2fa_excluded_roles` and `force_2fa_api_login_allowlist`
  so the config constants can be overridden at runtime and injected in tests.
* Extract the filter callbacks into named functions for testability.
* Add a PHPUnit unit-test suite (zero-dependency stub bootstrap) covering the
  exclusion logic, allowlist matching, and both filter callbacks.
* Add GitHub Actions CI: lint on PHP 7.2–8.4, unit tests on PHP 8.2–8.4.

= 1.5.0 =
* Declare the Two Factor plugin as a hard dependency via the `Requires Plugins`
  header (WP 6.5+), so activation is blocked until it is present and active.
* Document dependency tiers: Two Factor (required), WebAuthn provider
  (recommended), WP Mail Logging (testing only).
* Add a WordPress Playground multisite test blueprint.

= 1.4.0 =
* Repackage as a regular plugin supporting Network Activate or per-site
  activation on multisite (was must-use only).
* Add optional `mu-loader.php` for an un-deactivatable install, with a
  `FORCE_2FA_LOADED` re-load guard so double-loading is safe.
* Document the exclusion threat model: exclusions require filesystem access and
  are an operator convenience, not an attacker-facing control; no hard floor
  protects privileged accounts.
* Verified end-to-end on a WordPress multisite (network, per-site, mu-loader,
  and kill-switch paths).

= 1.3.0 =
* Add per-role enforcement exclusions (`FORCE_2FA_EXCLUDED_ROLES`, default all)
  and a `force_2fa_user_is_exempt` filter for per-user overrides.

= 1.2.0 =
* API-login allowlist now requires both allowlist membership and an Application
  Password; allowlist accepts user IDs or logins.

= 1.1.0 =
* Add service-account allowlist for API logins; expand internal documentation.

= 1.0.0 =
* Initial release: forced email two-factor for all users, API-login guard, and
  emergency kill switch.

== Upgrade Notice ==

= 1.12.0 =
No behavior change: all users are still enforced by default. Adds optional
capability-scoping — define FORCE_2FA_ENFORCED_CAPABILITY (e.g. 'manage_options')
to narrow enforcement to admins. See the changelog and the scope FAQ.
