=== Force Email Two-Factor (Enforcement) ===
Contributors: dknauss
Tags: two-factor, 2fa, security, authentication, login
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes two-factor mandatory for all users via the Two Factor plugin's Email provider, with per-role exclusions and a hardened API-login allowlist.

== Description ==

A single-file must-use plugin that enforces two-factor authentication across a
site, building on the [Two Factor](https://wordpress.org/plugins/two-factor/)
plugin (which must be installed and active).

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

= Features =

* Mandatory email two-factor as a universal floor, with no per-user setup.
* Stronger user-configured factors (TOTP, WebAuthn) and backup codes preserved.
* Per-role exclusions, defaulting to "all users" (`FORCE_2FA_EXCLUDED_ROLES`).
* A `force_2fa_user_is_exempt` filter for one-off, per-user exemptions.
* Service-account allowlist for API logins, gated on Application Passwords.
* Emergency kill switch via a wp-config constant (`FORCE_2FA_DISABLE`).

== Installation ==

This is a must-use plugin. It must be a flat `.php` file directly inside
`wp-content/mu-plugins/` (files in subdirectories are not auto-loaded):

`wp-content/mu-plugins/force-email-two-factor.php`

Create the `mu-plugins` directory if it does not exist. There is no activation
step — must-use plugins load automatically and do not appear on the Plugins
screen.

The [Two Factor](https://wordpress.org/plugins/two-factor/) plugin must be
installed and active. Confirm outbound email (SMTP) delivers reliably before
rollout, since email becomes the required factor for users with no stronger one.

== Frequently Asked Questions ==

= What if email delivery breaks and users are locked out? =

Add `define( 'FORCE_2FA_DISABLE', true );` to `wp-config.php`. The plugin checks
this at load time and registers nothing while it is set. Remove the line to
re-enable enforcement.

= How do I exempt a role from forced 2FA? =

List the role slugs (lowercase keys such as `subscriber`, not display names) in
the `FORCE_2FA_EXCLUDED_ROLES` constant. A user is exempt only if every role they
hold is on the list, so excluding a low-privilege role can never accidentally
exempt a privileged account.

= How do I let an integration log in over the REST API or XML-RPC? =

Add its user ID or login to `FORCE_2FA_API_LOGIN_ALLOWLIST`, and have it
authenticate with an Application Password. A real-password API login is always
denied, even for allowlisted accounts.

= Does this remove a user's authenticator app or hardware key? =

No. It appends the Email provider as a floor; any stronger factor the user
configured stays in place and remains their primary method.

== Changelog ==

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
