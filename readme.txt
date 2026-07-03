=== Require Email 2FA ===
Contributors: dpknauss
Tags: two-factor, 2fa, security, authentication, login
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Requires the Two Factor plugin and makes emailed 2FA the default, required login factor for all users, with per-role exclusions.

== Description ==

Builds on the [Two Factor](https://wordpress.org/plugins/two-factor/) plugin and
makes its emailed 2FA codes a mandatory baseline for every user — so the login
challenge appears even for accounts that never set up two-factor. Two Factor must
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
   Enforcement can be scoped with per-role exclusions.

2. **Restricts API logins.** XML-RPC and REST logins bypass the interactive 2FA
   screen. This plugin permits an API login to skip 2FA only when both the
   account is on an explicit allowlist and the request authenticated with an
   Application Password (never the real login password). Everyone else is denied
   on the API path.

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
* Per-role exclusions, defaulting to "all users" (`FORCE_2FA_EXCLUDED_ROLES`).
* A `force_2fa_user_is_exempt` filter for one-off, per-user exemptions.
* Service-account allowlist for API logins, gated on Application Passwords.
* Emergency kill switch via a wp-config constant (`FORCE_2FA_DISABLE`).

== Installation ==

1. Install the plugin folder at
   `wp-content/plugins/force-email-two-factor/` and activate it.
2. On multisite the plugin is network-only: **Network Activate** it (Network
   Admin → Plugins). The `Network: true` header makes WordPress treat it as
   network-only, so activating it (admin UI or WP-CLI) always lands network-wide,
   never on a single site; an activation-hook guard is a header-independent
   backstop. For a true network-wide guarantee, also **network-activate Two
   Factor**; the Network Admin notice warns you when it isn't.
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

* Confirm SMTP / transactional email delivery works.
* Keep a known-good administrator session open.
* Generate and safely store administrator backup codes.
* Test one non-admin login before broad rollout.
* Choose the right activation mode: single-site, Network Activate, or `mu-loader.php`.
* Identify REST/XML-RPC service accounts and decide whether they need allowlisting.
* Document the `FORCE_2FA_DISABLE` kill switch in your incident runbook.

== Frequently Asked Questions ==

= Does it still require the Two Factor plugin? =

Yes — Two Factor (`two-factor`) provides the Email provider this plugin makes
mandatory, so nothing is enforced without it. As of 1.8.0 the dependency is no
longer a hard activation gate. This plugin activates on its own, no-ops while Two
Factor is inactive, and shows an admin notice with a one-click install/activate
button. The `Requires at least: 6.5` floor is now just a conservative baseline,
not a technical requirement of the (removed) `Requires Plugins` header — you can
lower it if you need to run on older WordPress.

= What if email delivery breaks and users are locked out? =

Add `define( 'FORCE_2FA_DISABLE', true );` to `wp-config.php`. The plugin checks
this at load time and registers nothing while it is set. Remove the line to
re-enable enforcement.

= How do I exempt a role from forced 2FA? =

List the role slugs (lowercase keys such as `subscriber`, not display names) in
the `FORCE_2FA_EXCLUDED_ROLES` constant. A user is exempt only if every role they
hold is on the list, so excluding a low-privilege role can never accidentally
exempt a privileged account.

You can also override the effective list without editing this plugin:

`
add_filter( 'force_2fa_excluded_roles', function () {
	return array( 'subscriber', 'customer' );
} );
`

= How do I let an integration log in over the REST API or XML-RPC? =

Add its user ID or login to `FORCE_2FA_API_LOGIN_ALLOWLIST`, and have it
authenticate with an Application Password. A real-password API login is always
denied, even for allowlisted accounts.

You can also override the effective allowlist without editing this plugin:

`
add_filter( 'force_2fa_api_login_allowlist', function () {
	return array( 123, 'svc_headless' );
} );
`

= How does it work on multisite? =

It is network-only: you must Network Activate it, and per-site activation is
blocked. This is deliberate — users are network-global, so a per-site install
would let someone log in via a site where the plugin is inactive and skip
enforcement. Network activation closes that gap. Note that a true network-wide
guarantee also requires the Two Factor plugin to be network-active; if it is only
site-active (or absent) somewhere, enforcement silently no-ops there, and the
Network Admin notice will tell you. For an un-deactivatable install, use the
bundled `mu-loader.php`.

= Does this remove a user's authenticator app or hardware key? =

No. It appends the Email provider as a floor; any stronger factor the user
configured stays in place and remains their primary method.

= What are the known limitations? =

Mail delivery is part of the security boundary; if outbound email fails, users
without a stronger factor can be locked out until mail is fixed or
`FORCE_2FA_DISABLE` is enabled. This plugin only enforces the Two Factor plugin
and does not integrate with other 2FA plugins. On multisite it is network-only
(per-site activation is blocked); a true network-wide guarantee also depends on
Two Factor itself being network-active. API bypasses are intentionally narrow:
only allowlisted accounts using Application Passwords can skip the interactive
challenge.

== Changelog ==

= 1.9.0 =
* Multisite: the plugin is now **network-only**. The `Network: true` header makes
  WordPress treat it as network-only, so activating it (admin UI or WP-CLI) always
  lands network-wide, never per-site; an activation-hook guard is a
  header-independent backstop. This stops enforcement being left with per-site
  gaps a network-global user could slip through.
* Add a **Network Admin notice**: when the plugin is network-active but Two Factor
  is not network-active, it warns that 2FA is not enforced network-wide and offers
  a one-click install + network-activate of Two Factor.
* Add a per-site **heads-up notice** on multisite: on any site where Two Factor is
  not loaded, site admins see that enforcement is off there and to contact the
  network admin (non-actionable — the fix lives in Network Admin).
* The one-click installer now network-activates Two Factor when this plugin is
  network-active, and uses the `manage_network_plugins` capability.
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
