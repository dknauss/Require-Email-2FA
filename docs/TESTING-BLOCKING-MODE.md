# Testing blocking mode

This covers the optional **blocking mode** added in 1.11.0. It is **off by default**,
so the first thing to confirm is that an existing install is unchanged until you opt in.

## What is verified where

| Behaviour | Covered by |
| --- | --- |
| Pure decisions (`force_2fa_should_require_setup`, `force_2fa_request_is_gateable`, `force_2fa_meta_indicates_configured`, `force_2fa_user_has_configured_2fa`, `force_2fa_screen_is_own_setup`), hook wiring | PHPUnit (`tests/BlockingModeTest.php`) — `composer test` |
| Real integration: Two Factor's stored-providers meta key, gate release after a provider is enabled | `bin/blocking-mode-e2e.sh` (real WordPress + real Two Factor, SQLite) |
| The interactive redirect UX (browser is actually bounced to the profile page and released after setup) | Manual / Playground — see below |

The redirect *glue* (`force_2fa_enforce_setup_gate`) calls `wp_safe_redirect()`/`exit`
and is exercised end-to-end, not in unit tests; its decision inputs are each unit-tested.

## Automated: unit + E2E

```sh
composer check                 # phpcs + phpunit (unit incl. the new tests)
bash bin/blocking-mode-e2e.sh  # disposable real WP + Two Factor; asserts integration
```

`bin/blocking-mode-e2e.sh` (SQLite, no MySQL) asserts, against the **real** Two Factor
plugin: the meta key the plugin reads matches Two Factor's own constant; an
unconfigured user requires setup and is gateable on page loads but *not* on the setup
screen or AJAX; and enabling a provider flips the user to "configured" and releases the
gate.

## Interactive: WordPress Playground

Open the blueprint (installs Two Factor, this plugin, turns blocking mode on, and
creates an unconfigured `alice`):

https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Require-Email-2FA/main/playground/blocking-mode-blueprint.json

1. You land logged in as **admin**. The admin account also has no factor yet, so with
   blocking mode on you are redirected to **Profile** with the "Two-factor
   authentication is required" notice.
2. Scroll to **Two-Factor Options**, enable a method (Email is simplest), and save.
3. Navigate to the Dashboard — you are no longer redirected. The gate has released.
4. Log in as `alice` / `password123` to see the same first-run flow for a non-admin.

## Manual checklist

- [ ] **Default off.** Without `FORCE_2FA_BLOCKING_MODE`, an unconfigured user logs in
      and reaches the dashboard normally (soft floor only). No redirect.
- [ ] **Unconfigured user is gated.** With blocking mode on, an unconfigured, non-exempt
      user is redirected to their profile on any admin or front-end page load.
- [ ] **Setup releases the gate.** After enabling any provider on the profile page and
      saving, the user can navigate freely.
- [ ] **Profile page reachable.** The profile / user-edit screen is never redirected, so
      the user can always reach the setup UI. (Catch-22 check.)
- [ ] **Setup flows work.** TOTP QR verification / WebAuthn registration / backup-code
      generation complete — these run over AJAX/REST and must not be gated.
- [ ] **Excluded roles bypass.** A user whose every role is excluded is never redirected.
- [ ] **Two Factor inactive → no-op.** Deactivate Two Factor: blocking mode does nothing
      (no redirects, no lockout).
- [ ] **Kill switch.** `define( 'FORCE_2FA_DISABLE', true )` disables everything,
      including blocking mode.
- [ ] **Non-interactive paths.** REST/XML-RPC/cron/WP-CLI are not redirected.
- [ ] **Editing other users stays gated.** An unconfigured user with `edit_users` who
      opens `user-edit.php?user_id=<someone-else>` is still redirected to their own
      profile; only their own `profile.php` (or a self-targeted `user-edit.php`) is exempt.

## Debugging

- `wp eval 'var_dump( force_2fa_blocking_mode_enabled() );'` — is blocking on?
- `wp user meta get <id> _two_factor_enabled_providers` — empty ⇒ "unconfigured"
  (redirected); a non-empty array ⇒ "configured" (released).
- Enable `WP_DEBUG_LOG` and watch `wp-content/debug.log` during a login.
