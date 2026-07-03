# Deploying Require Email 2FA across a fleet

This plugin runs in one of two update modes. Both run the **same code** with the
same enforcement guarantees — they differ only in **how the plugin's own code is
updated**. Choose per site (or per group of sites) based on how you already patch
plugins. You do **not** need a second, older build for managed sites; disable the
updater instead (see [Mode B](#mode-b--centrally-managed-updater-off)).

| | Mode A — Standalone | Mode B — Centrally managed |
|---|---|---|
| **Best for** | Sites you don't manage from one console | Sites under MainWP / Composer / git deploys |
| **Self-updater** | On (default) | Off (`FORCE_2FA_DISABLE_SELF_UPDATE`) |
| **Who delivers patches** | The site itself, from GitHub Releases | Your management layer |
| **Each site polls GitHub?** | Yes (admin/cron only) | No |
| **Remote code path on the site** | Yes (GitHub Releases → auto-install) | None |
| **Install method** | Release zip | Release zip, Composer, or git |

Enforcement (email 2FA floor, role exclusions, the API-login allowlist, the
multisite network-only guard, the emergency kill switch) is identical in both
modes and is never affected by the updater setting.

---

## Mode A — Standalone (updater on)

The default. The plugin checks its `Update URI` GitHub repository for new Releases
and offers the `force-email-two-factor.zip` asset through **Dashboard → Updates**
and unattended auto-updates (where the site enables them).

**Use when** the site is not managed from a central console and you want it to keep
itself patched without manual intervention — the automatic security patching is
worth the plugin carrying its own update path.

**Requirements**

- Install from the **release zip** (not a `git clone` — a working copy is skipped
  on purpose; see [Site Health](#verifying-the-update-posture)).
- Outbound HTTPS to `api.github.com` and `github.com` must be reachable.
- The `Update URI` header must point at the source repository (it does by default).

Nothing to configure. Leave `FORCE_2FA_DISABLE_SELF_UPDATE` unset.

---

## Mode B — Centrally managed (updater off)

Patches are delivered by your management layer, so each site should **not**
independently poll GitHub or self-install. Turn the updater off in `wp-config.php`:

```php
define( 'FORCE_2FA_DISABLE_SELF_UPDATE', true );
```

(Or, from code, the `force_2fa_self_update_enabled` filter — return `false`.)

With this set, the plugin never builds Plugin Update Checker, never calls GitHub,
and never self-installs a release. Your pipeline is the only thing that changes the
plugin's code — one controlled, auditable patch path, and no per-site remote code
path at all.

**Use when** you run MainWP, InfiniteWP, a Composer-managed stack, or git-based
deploys — anything that already gives you a single place to review and roll out a
plugin update across many sites.

### MainWP

1. Add the constant above to each managed site's `wp-config.php` (e.g. via a
   MainWP code-snippet rollout, your provisioning template, or `wp config set`).
2. Install/update Require Email 2FA from the MainWP dashboard like any other
   plugin. Because WordPress.org never serves this slug (the `Update URI` header
   blocks it) and the self-updater is off, MainWP is the sole update source.
3. Verify posture across the fleet (below) reads `disabled_config` — that's the
   expected "intentionally managed" state.

> **Why not just let MainWP and the self-updater coexist?** Two update sources for
> one plugin can race (MainWP pushing version X while a site auto-updates to Y),
> and every managed site would keep its own outbound GitHub path. Turning the
> updater off makes MainWP authoritative and removes the redundant remote path.

### Composer / git deploys

- **git deploy:** a deployed working copy contains a `.git`, which the plugin
  already treats as "don't self-update." Setting the constant makes that explicit
  (and covers bare/exported checkouts without a `.git`).
- **Composer:** manage the version in your `composer.json`; set the constant so the
  site never second-guesses your lockfile by pulling from GitHub.

---

## Verifying the update posture

**Per site — Tools → Site Health.** The plugin adds a **"Require Email 2FA
self-update"** test. Expected results:

| Site Health result | Meaning |
|---|---|
| *receiving updates* (good) | Mode A, working normally |
| *self-update intentionally disabled* (good) | Mode B, as configured |
| *not self-updating (working copy)* (recommended) | a `.git` is present — fine on a dev box, a problem on production Mode A |
| *cannot self-update (updater files missing)* (recommended) | reinstall from the release zip |
| *not self-updating (no update source)* (recommended) | the `Update URI` header is blank |

**Across a fleet — scripted.** Check the effective toggle with WP-CLI:

```sh
wp eval 'echo force_2fa_self_update_enabled() ? "on\n" : "off\n";'
```

Run it over all sites from MainWP (Execute WP-CLI) or your own loop to confirm each
site is in the mode you intend.

---

## Multisite

The plugin is **network-only**: Network Activate it (per-site activation is
refused). Enforcement is only truly network-wide when the Two Factor plugin is
**also** network-active — the Network Admin dependency notice warns when it isn't.
The update mode (A or B) is orthogonal and set the same way.

---

## Operating notes (both modes)

- **Mail is part of the security boundary.** Email 2FA depends on outbound mail. On
  a fleet with heterogeneous mail setups, a broken mailer is your realistic lockout
  risk. Confirm transactional email works on each site before relying on
  enforcement.
- **Emergency kill switch.** If mail breaks and users are locked out, disable *all*
  enforcement without deleting the plugin:

  ```php
  define( 'FORCE_2FA_DISABLE', true ); // in wp-config.php
  ```

  Keep a known-good admin session or printed backup codes on hand the first time
  you enable enforcement on a site.
- **Kill switch vs. update switch are different.** `FORCE_2FA_DISABLE` turns off
  *enforcement*; `FORCE_2FA_DISABLE_SELF_UPDATE` turns off *self-updating*. They are
  independent.

---

## Why not run the old, simpler release on managed sites?

Earlier releases had no updater, which is appealing for centrally-managed fleets —
but they also predate the current security fixes (the per-account Application
Password binding, the multisite network-only guard, the provider-registration
dependency check) and would need every future fix backported by hand. Running the
current code with `FORCE_2FA_DISABLE_SELF_UPDATE` gives you the same "no self-update"
behavior **plus** those fixes, from one codebase you audit once. Prefer the toggle
over an old fork.
