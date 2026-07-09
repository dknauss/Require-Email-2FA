# Require Email 2FA

[![CI](https://github.com/dknauss/Require-Email-2FA/actions/workflows/ci.yml/badge.svg)](https://github.com/dknauss/Require-Email-2FA/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/dknauss/Require-Email-2FA?sort=semver&label=release)](https://github.com/dknauss/Require-Email-2FA/releases/latest)
[![Docs](https://img.shields.io/badge/docs-deployment%20%26%20supply%20chain-3858e9.svg)](docs/DEPLOYMENT.md)

[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
[![Requires WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Tested up to WordPress 7.0](https://img.shields.io/badge/tested%20up%20to-WordPress%207.0-21759b?logo=wordpress&logoColor=white)](https://wordpress.org/download/)
[![Requires PHP 7.2+](https://img.shields.io/badge/PHP-7.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net/supported-versions.php)
[![Requires Plugin: Two Factor](https://img.shields.io/badge/requires-Two%20Factor-3858e9.svg)](https://wordpress.org/plugins/two-factor/)
[![Try in WordPress Playground](https://img.shields.io/badge/try-WordPress%20Playground-3858e9?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Require-Email-2FA/main/playground/demo-blueprint.json)

This is a Multisite compatible, single-purpose, WordPress utility plugin with no admin user interface. It's intended to help site owners and maintainers rapidly roll out and enforce a mandatory two-factor authentication policy on WordPress sites or networks that may have many users — in the simplest and least disruptive way.

Require Email 2FA imposes three requirements site- or network-wide:

1. The [Two Factor plugin](https://wordpress.org/plugins/two-factor/) must be installed and activated.
2. All users must use Two Factor to log in. (Exceptions can be set with a constant or filter.)
3. Users who do not have a different method selected in their two-factor settings will receive time-based, one-time passcodes by email.

The plugin also hardens the XML-RPC login path with a named allowlist of service accounts (added via a constant or filter). Note this allowlist governs XML-RPC, not REST — see **Restricts XML-RPC logins** below.

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

2. **Restricts XML-RPC logins.** Non-interactive logins bypass the interactive
   2FA screen. This plugin allows such a login to skip 2FA **only** when *both*:
   - the account is on an explicit allowlist, **and**
   - the request authenticated with an **Application Password** (not the real
     login password).

   Everyone else is denied.

   > [!IMPORTANT]
   > **Scope — this allowlist governs XML-RPC, not the REST API.** Two Factor's
   > only API-login gate runs on the `authenticate` filter, which XML-RPC logins
   > pass through. REST requests authenticated with an Application Password set the
   > current user via WordPress core's `determine_current_user` path
   > (`wp_validate_application_password`) and never touch that filter — so Two
   > Factor, and therefore this allowlist, does **not** gate them. Any account with
   > an Application Password can authenticate over the REST API regardless of the
   > allowlist. To restrict REST access, scope each account's role/capabilities
   > (Application Passwords inherit the user's caps), disable Application Passwords
   > for users who shouldn't have them (`wp_is_application_passwords_available_for_user`),
   > or add a REST-layer gate (`rest_authentication_errors`).

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
> Mutisite Users and their Two Factor settings are network-global, so a per-site activation would key enforcement off the **login entry point, not the user** — a global user could authenticate via a site where the plugin was inactive and skip enforcement. Network activation closes that gap. A true network-wide guarantee **also requires the Two Factor plugin to be network-active.** If it's only site-active (or absent) somewhere, enforcement silently no-ops there — the **Network Admin notice** warns you when this is the case and offers a one-click install + network-activate. On single-site WordPress, just activate it normally.

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

- [ ] **Email is the second factor, so email must work — or people get locked out.**
      Confirm SMTP / transactional email delivery works for real user mailboxes.
- [ ] **Keep a way back in while you roll out.** Keep at least one known-good
      administrator session open during rollout.
- [ ] **Have a recovery path if mail fails.** Generate and safely store backup codes
      for administrator accounts.
- [ ] **Prove the flow before you trust it.** Test the login flow with one non-admin
      user before broad activation.
- [ ] **Decide how enforcement turns on.** Choose the activation mode deliberately:
      normal single-site, Network Activate for multisite-wide enforcement, or the
      optional `mu-loader.php`.
- [ ] **Plan for integrations that can't read an email code.** Identify any XML-RPC
      service accounts up front and decide whether each should be allowlisted for
      Application Password authentication. (The allowlist covers XML-RPC, not REST —
      scope REST integrations by role/capability instead; see **Security model**.)
- [ ] **Don't assume SSO logins are covered.** If the site uses SSO/SAML/OIDC/OAuth/
      LDAP/Jetpack SSO, test both the SSO callback and the direct `wp-login.php` path
      on staging, and enforce MFA at the identity provider.
- [ ] **Write down the escape hatch before you need it.** Document the
      `FORCE_2FA_DISABLE` kill switch in your incident runbook.

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

> **Excluding a role also removes those accounts from the API-login hardening.**
> Two Factor only gates the API login of a user it considers "using 2FA." An
> excluded account with no other factor configured is not using 2FA, so Two
> Factor never gates its XML-RPC/REST logins — the API-login allowlist does not
> apply to it. Don't exclude a role for accounts you also expect the API allowlist
> to govern.

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

> **Numeric entries always match by user ID.** An entry made of digits (e.g.
> `'1001'`) is compared against the user ID only — never against `user_login`. If a
> service account's `user_login` happens to be all digits, it cannot be allowlisted
> by that login (and the numeric value would match whichever account holds that
> *ID* instead). Allowlist such accounts by their user ID, which is unambiguous.

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

## Uninstalling and data footprint

The plugin is effectively stateless: it stores **no** options, user meta, or transients of its own. All enforcement happens through runtime filters and actions on the Two Factor plugin's hooks, so it holds nothing that could outlive it or interfere with other login flows.

- **Deactivating** unhooks everything immediately — 2FA enforcement stops that instant, with no residue. If self-update is active, the bundled Plugin Update Checker also clears its update-check cron on deactivation.
- **Deleting (uninstall)** runs `uninstall.php`, which purges the only persistent footprint the plugin can create — the Plugin Update Checker's cached-update option (`external_updates-<slug>`) and its update-check cron event (`puc_cron_check_updates-<slug>`), across all sites on multisite. Nothing is left in the database.

Nothing this plugin creates touches an SSO/SAML/OIDC/OAuth/LDAP integration's configuration or session state, so removing it cannot break those login paths. See [Compatibility & interaction with other 2FA setups](#compatibility--interaction-with-other-2fa-setups) for how it behaves alongside SSO while active.

> **Note:** if you enabled the optional [“cannot be deactivated” mode](#optional-cannot-be-deactivated-mode-mu-loader), delete `wp-content/mu-plugins/mu-loader.php` as well — a must-use plugin is not removed by deactivating or deleting the plugin from the Plugins screen.

---

## How it works (for maintainers)

- **Forcing 2FA:** filters `two_factor_enabled_providers_for_user` to add
  `Two_Factor_Email`. `Two_Factor_Email::is_available_for_user()` returns `true`
  unconditionally and needs no per-user setup, so it becomes an available — and
  for unconfigured users, the primary — provider. That makes
  `Two_Factor_Core::is_user_using_two_factor()` true, which triggers the login
  challenge. A `force_2fa_dependency_met()` guard — Two Factor loaded **and** the
  Email provider registered in `Two_Factor_Core::get_providers()` — means the
  filter no-ops safely (never stripping an existing factor) if Two Factor is
  inactive, and does not append a provider Two Factor can't resolve.

- **API logins:** filters `two_factor_user_api_login_enable`. Two Factor's default
  value for this filter is `did_action( 'application_password_did_authenticate' )`,
  i.e. it already allows an API login to skip 2FA only when an Application Password
  authenticated the request. This plugin recomputes the decision as *(this account
  used an Application Password this request) AND (user is allowlisted)*. This gate
  runs only on the `authenticate` path (XML-RPC); REST Application Password logins
  bypass it via core's `determine_current_user` and are not gated (see the API
  hardening note under Security model). The account is captured on
  `application_password_did_authenticate` — fired by core while authenticating the
  request — and Two Factor evaluates `two_factor_user_api_login_enable` afterward,
  during its API-login gating, so the marker is reliably set by the time the
  decision is made. (Both hooks use the default priority 10; the ordering comes
  from the authentication → API-login-gating stages, not from priorities.)

- **Self-hosted updates (no WordPress.org, no collisions):** distributed from
  GitHub, so `force_2fa_bootstrap_self_update()` wires up
  [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
  on `plugins_loaded`. It reads the repository from the **`Update URI`** plugin
  header, compares the installed `Version` against the latest GitHub **Release**,
  and offers that release's attached `<slug>.zip` through the normal Dashboard →
  Updates flow and unattended auto-updates.
  [`.github/workflows/release.yml`](.github/workflows/release.yml) builds and
  publishes that zip on every `v*` tag. Self-update is skipped when it is turned
  off (`FORCE_2FA_DISABLE_SELF_UPDATE`; see **Fleet deployment** below) or in a
  working copy under version control (a `.git` present), so a dev clone updates via
  `git`, not by having WordPress overwrite it with a release zip.

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
  > release token requests only `contents: write` plus the `id-token`/`attestations`
  > write scopes for build provenance. Keep branch protection on the
  > tag/release path and review any change to the release workflow, its actions, or
  > the vendored updater. Full hardening guide — GitHub settings, artifact
  > provenance/checksums, safe forking, incident response — in
  > [`docs/SUPPLY-CHAIN-SECURITY.md`](docs/SUPPLY-CHAIN-SECURITY.md).

- **Forking:** point the `Update URI` header at your own repository — that single
  change redirects both the updater and core's update-ownership to your fork.
  (Leaving it on the upstream repo would auto-update every site back to upstream.)
  The slug and download-asset name derive from the plugin folder, so only a rename
  additionally needs the workflow's `PLUGIN_SLUG` updated to match. A fork inherits
  none of the upstream repo's branch/tag protection — re-apply it, and never embed a
  broad token for a private update repo (see
  [`docs/SUPPLY-CHAIN-SECURITY.md`](docs/SUPPLY-CHAIN-SECURITY.md#4-forking-safely)).

- **Fleet deployment (managed vs. standalone):** the self-updater is on by default
  (standalone sites patch themselves from GitHub Releases). On sites patched by a
  central manager — MainWP, Composer, git deploys — turn it off with
  `define( 'FORCE_2FA_DISABLE_SELF_UPDATE', true );` (or the
  `force_2fa_self_update_enabled` filter) so each site doesn't independently poll
  GitHub; your pipeline owns patching and enforcement is unchanged. A
  **Tools → Site Health** check reports each site's update posture. See
  [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for the full managed-vs-standalone
  guide.

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
  `force_2fa_dependency_met()` guard (Two Factor loaded **and** the Email provider
  registered in `Two_Factor_Core::get_providers()`) makes it a **safe no-op** — no
  errors, no enforcement — and an admin notice warns that 2FA is not being enforced,
  with a one-click button to install/activate Two Factor. The same guard covers the
  case where Two Factor is disabled later.

- **A different 2FA plugin** (Wordfence Login Security, WP 2FA, miniOrange, Duo,
  etc.): this plugin **does not integrate with or affect them** — they don't expose
  Two Factor's hooks. A competing 2FA plugin also does **not** satisfy this
  dependency: without the actual Two Factor plugin active, this plugin stays a
  no-op and keeps prompting you to install it.

> [!WARNING]
> **SSO is a separate authentication boundary.** This plugin is not an SSO MFA enforcement layer. It enforces through the Two Factor plugin's normal WordPress login and API-login hooks. Some SSO plugins (SAML, OIDC/OAuth, LDAP, Jetpack SSO, etc.) can bypass the local login challenge; others may trigger a second local Two Factor prompt or conflict with the SSO callback. If SSO is your primary login path, enforce MFA at the identity provider, test both SSO and direct `wp-login.php` on staging, and keep a break-glass administrator account with known-good local access and backup codes.

> [!CAUTION]
> **Don't run two 2FA enforcement stacks at once.** If both Two Factor (with this plugin) and a separate 2FA plugin gate the login flow, you risk double prompts or lockouts. Pick one stack; this plugin assumes that stack is Two Factor.

> [!NOTE]
> **Edge case:** if an admin has deliberately unregistered the Email provider via the `two_factor_providers` filter, `force_2fa_dependency_met()` reports the dependency as unmet (it checks `Two_Factor_Core::get_providers()`, not just that the class exists), so this plugin does **not** append Email — it would be unusable — and enforcement safely no-ops rather than injecting a provider Two Factor can't resolve.

---

## Security model

This section states plainly what the plugin **does** guarantee, what it **does
not**, and how to configure it so those guarantees hold. Read it before you rely on
the plugin as a control.

### What it enforces

- **Interactive logins (`wp-login.php`): every non-exempt user must clear a second
  factor.** Email is added as a universal, no-setup floor, so a user who never
  configured 2FA is still challenged. Users who set up a stronger factor (TOTP,
  WebAuthn) keep it as their primary method; the floor never replaces it.
- **XML-RPC logins: a real-password login by a 2FA-enforced user is denied.**
  WordPress's interactive challenge can't run over the API, so Two Factor blocks
  the API login instead — unless the request used an Application Password.
- **XML-RPC Application Password logins are narrowed to an allowlist.** By default
  Two Factor lets *any* account skip 2FA over the API with an Application Password;
  this plugin tightens that so a login skips the challenge only when the account is
  **both** on `FORCE_2FA_API_LOGIN_ALLOWLIST` **and** authenticating with an
  Application Password. Everything else on the XML-RPC path is denied.

### What it does NOT enforce (know these boundaries)

- **REST API Application Password logins are not gated.** Two Factor's only
  API-login gate runs on the `authenticate` filter; REST requests authenticated
  with an Application Password set the current user via WordPress core's
  `determine_current_user` path and never reach that filter. So neither Two Factor
  nor this allowlist restricts them: **any account that can create an Application
  Password can authenticate over the REST API with its own capabilities**,
  allowlisted or not. This is a defense-in-depth gap, not a new hole — Application
  Passwords are a core feature that behaves this way with or without this plugin.
  Closing it (making the allowlist cover REST too) is on the roadmap:
  [#41](https://github.com/dknauss/Require-Email-2FA/issues/41).
- **Excluding a role opts those accounts out of the API-login gate as well.** An
  excluded account with no other factor isn't "using 2FA," so Two Factor never
  gates *any* of its logins — XML-RPC included. Exclusion is broader than "don't
  force the email step."
- **It does not enforce MFA inside external identity providers.** SSO/SAML/OIDC/
  OAuth/LDAP/Jetpack SSO logins that bypass `wp-login.php` are the identity
  provider's responsibility; enforce MFA there.
- **Email is a floor, and email is weaker than TOTP/WebAuthn.** It exists so
  *everyone* has a second factor with zero setup, not because it is the strongest
  option. Encourage privileged users to configure a stronger factor.

### How to use it properly

- **Give every account the least privilege it needs — this is the real REST
  control.** Because the allowlist does not gate REST, an integration's blast
  radius over REST is whatever its role allows. Scope service accounts to the
  minimum capabilities, and turn off Application Passwords for users who shouldn't
  have them (filter `wp_is_application_passwords_available_for_user`).
- **Keep the API allowlist minimal, and prefer user IDs.** Add only accounts that
  genuinely need non-interactive XML-RPC access, and remove them when the
  integration is retired. Numeric entries always match by user **ID** (a numeric
  `user_login` can't be allowlisted by login — use the ID).
- **Choose exclusions deliberately.** Excluding a role removes it from *both*
  forced 2FA and the API-login gate; never exclude a role a privileged account
  holds as its only role. Prefer the `force_2fa_user_is_exempt` filter for one-off
  accounts.
- **Keep a break-glass path.** Keep a known-good admin session or printed backup
  codes on hand, and document the `FORCE_2FA_DISABLE` kill switch in your incident
  runbook — a mail outage can otherwise lock out everyone without a stronger factor.
- **Make enforcement un-disablable where it matters.** If you configure exclusions
  or an allowlist, install the `mu-loader.php` so the plugin can't be turned off
  from the admin UI (see *"cannot be deactivated" mode* above).

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
- **The API allowlist governs XML-RPC, not REST.** An XML-RPC login can skip the
  interactive challenge only for allowlisted accounts using Application Passwords.
  REST Application Password logins are **not** gated by Two Factor (they
  authenticate via core's `determine_current_user` path), so the allowlist does
  not restrict them — scope REST access via roles/capabilities instead. See the
  **Security model** section above. Extending the allowlist to cover REST is on the
  roadmap: [#41](https://github.com/dknauss/Require-Email-2FA/issues/41).
- **Email can be weaker than TOTP/WebAuthn.** This plugin uses Email as a universal
  no-setup floor while preserving stronger factors users have already configured.


---

## Contributing, support, and security

- See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, test commands, and pull request expectations.
- See [SUPPORT.md](SUPPORT.md) for support boundaries and the information needed in bug reports.
- See [SECURITY.md](SECURITY.md) to report vulnerabilities privately.
- See [docs/SUPPLY-CHAIN-SECURITY.md](docs/SUPPLY-CHAIN-SECURITY.md) for the release
  trust boundary, the GitHub settings that protect the update repository, how to
  verify release artifacts, and safe-fork guidance.
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
composer phpcs                      # PHPCompatibility (7.2+) + WordPress (full)
composer phpcbf                     # auto-fix coding-standards issues
composer check                      # phpcs + tests
vendor/bin/phpunit --testsuite integration   # one suite
vendor/bin/phpunit --coverage-text  # coverage (needs Xdebug or PCOV)
bash bin/multisite-e2e.sh           # disposable real multisite, network-only guard
bash bin/update-e2e.sh              # disposable real site, GitHub Release update path
```

Coding standards are the full **WordPress** standard — Core + Extra + Docs (style,
security, and documentation sniffs) — plus **PHPCompatibility** with
`testVersion 7.2-`, scoped to the production files (`phpcs.xml.dist`). The one
excluded sub-rule (`InlineComment.InvalidEndChar`) is documented inline in the
ruleset; it conflicts with tool directives (`@codeCoverageIgnore`, `phpcs:`) and the
intentional commented-out example config.

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
- **Update E2E** — boots a disposable SQLite-backed site, installs the committed
  tree (`git archive`, the release layout) rewritten to an older version, forces
  an update check, and asserts by exact URL match that WordPress updates it from
  this repository's latest GitHub Release `force-email-two-factor.zip` asset.
  E2E tooling (WP-CLI, the SQLite drop-in) is version-pinned and
  checksum-verified in [`bin/lib/e2e-common.sh`](bin/lib/e2e-common.sh).

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
