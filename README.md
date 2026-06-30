# Force Email Two-Factor (Enforcement)

A single-file WordPress **must-use plugin** that makes two-factor authentication
mandatory for every user, and locks down the XML-RPC / REST API-login path to a
named allowlist of service accounts.

It builds on the [Two Factor plugin](https://wordpress.org/plugins/two-factor/) —
that plugin must be installed and active for this to do anything.

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

Must-use plugins must be a **flat `.php` file** directly inside `mu-plugins/`
(files in subdirectories are not auto-loaded).

```
wp-content/mu-plugins/force-email-two-factor.php
```

Create the `mu-plugins` directory if it doesn't exist. There is no activation
step — must-use plugins load automatically on every request and don't appear in
the Plugins screen.

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
`editor`) is still enforced — so excluding a low-privilege role can never
accidentally exempt a privileged account. Users with no role are never exempted.

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

## Requirements

- WordPress with the **Two Factor** plugin active.
- Working outbound email (SMTP) for the Email provider.
- Application Passwords enabled (WordPress core, on by default over HTTPS) for any
  allowlisted service account.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
