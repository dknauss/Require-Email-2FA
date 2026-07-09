#!/usr/bin/env bash
#
# Real WordPress multisite end-to-end check for the network-only behavior.
#
# Uses the SQLite database drop-in so it needs no MySQL server — it runs
# identically on a laptop and in CI. Tooling versions and checksums are pinned
# in bin/lib/e2e-common.sh.
#
# Asserts, on a real multisite:
#   - a per-site `wp plugin activate` is REFUSED (the register_activation_hook
#     guard rolls it back), while `--network` succeeds, and
#   - with Two Factor absent the plugin loads and safely no-ops, and
#   - the plugin ends up network-active and never per-site, and
#   - the FORCE_2FA_DISABLE emergency kill switch makes the plugin return at load
#     (FORCE_2FA_LOADED undefined) with no enforcement filters attached, and
#   - the optional mu-loader force-loads the plugin exactly once alongside a normal
#     network activation (the FORCE_2FA_LOADED re-load guard prevents a fatal
#     redeclare), and the kill switch still wins when mu-loaded, and
#   - uninstall.php's multisite branch purges the network-level Plugin Update
#     Checker option once and clears the update-check cron on EVERY site.
#
# Usage: bin/multisite-e2e.sh
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
WP="$WORK/wp"
mkdir -p "$WP"
trap 'rm -rf "$WORK"' EXIT

# shellcheck source=bin/lib/e2e-common.sh
. "$PLUGIN_DIR/bin/lib/e2e-common.sh"

echo "==> Fetch pinned WP-CLI ${E2E_WP_CLI_VERSION} (checksum-verified)"
e2e_fetch_wp_cli

echo "==> Download WordPress (${WP_VERSION:-latest})"
wp core download --version="${WP_VERSION:-latest}"

echo "==> SQLite database drop-in ${E2E_SDI_VERSION} (checksum-verified, no MySQL server needed)"
e2e_install_sqlite_dropin

echo "==> Install multisite"
wp config create --dbname=wp --dbuser=root --dbpass="" --dbhost=localhost --skip-check --force
wp core multisite-install --url=http://localhost --title="MS E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install the plugin under test (copied — a symlink breaks plugin_basename on activation)"
mkdir -p "$WP/wp-content/plugins/force-email-two-factor"
cp "$PLUGIN_DIR/force-email-two-factor.php" "$WP/wp-content/plugins/force-email-two-factor/"

echo "==> Per-site activation must be REFUSED (network-only guard)"
if wp plugin activate force-email-two-factor >/dev/null 2>&1; then
  echo "FAIL: per-site activation was allowed"; exit 1
fi
echo "    refused, as expected"

echo "==> Network activation must succeed — Two Factor absent"
wp plugin activate force-email-two-factor --network

echo "==> Assert network-only behavior"
wp eval '
require_once ABSPATH . "wp-admin/includes/plugin.php";
$f       = "force-email-two-factor/force-email-two-factor.php";
$net     = is_plugin_active_for_network( $f );
$persite = in_array( $f, (array) get_option( "active_plugins", array() ), true );
$loaded  = defined( "FORCE_2FA_LOADED" );
$noop    = ! class_exists( "Two_Factor_Email" );
$guard   = function_exists( "force_2fa_block_single_site_activation" )
        && force_2fa_activation_blocked( true, false ) === true
        && force_2fa_activation_blocked( true, true ) === false;
if ( $net && ! $persite && $loaded && $noop && $guard ) {
    echo "FORCE2FA_MS_OK\n";
    exit( 0 );
}
fwrite( STDERR, sprintf( "FAIL network_active=%d per_site=%d loaded=%d noop=%d guard=%d\n", $net, $persite, $loaded, $noop, $guard ) );
exit( 1 );
'

echo "==> Emergency kill switch (FORCE_2FA_DISABLE) disables all enforcement"
# The plugin checks FORCE_2FA_DISABLE at load and returns before defining anything,
# so FORCE_2FA_LOADED must stay undefined and no enforcement filter may be attached —
# even though the plugin is still network-active. This is the break-glass recovery
# path for a mail outage, so it must be proven, not assumed.
wp config set FORCE_2FA_DISABLE true --raw --type=constant
wp eval '
$loaded = defined( "FORCE_2FA_LOADED" );
$prov   = has_filter( "two_factor_enabled_providers_for_user", "force_2fa_filter_enabled_providers" );
$api    = has_filter( "two_factor_user_api_login_enable", "force_2fa_filter_api_login_enable" );
if ( ! $loaded && false === $prov && false === $api ) {
    echo "FORCE2FA_KILL_OK\n";
    exit( 0 );
}
fwrite( STDERR, sprintf( "FAIL loaded=%d providers_filter=%s api_filter=%s\n", $loaded, var_export( $prov, true ), var_export( $api, true ) ) );
exit( 1 );
'
wp config delete FORCE_2FA_DISABLE --type=constant

echo "==> mu-loader force-loads the plugin once, alongside the network activation"
# Copy the shipped mu-loader (not a test shim) into mu-plugins/. mu-plugins load
# before regular plugins, so the plugin is included twice in one request; the
# FORCE_2FA_LOADED re-load guard must make the second include a no-op. A broken guard
# would fatal on redeclaring the const/functions, so reaching the assertion at all
# proves it held. Enforcement must still be wired (mu-loaded AND active).
mkdir -p "$WP/wp-content/mu-plugins"
cp "$PLUGIN_DIR/mu-loader.php" "$WP/wp-content/mu-plugins/"
wp eval '
$loaded = defined( "FORCE_2FA_LOADED" );
$prov   = has_filter( "two_factor_enabled_providers_for_user", "force_2fa_filter_enabled_providers" );
if ( $loaded && false !== $prov ) {
    echo "FORCE2FA_MU_OK\n";
    exit( 0 );
}
fwrite( STDERR, sprintf( "FAIL loaded=%d providers_filter=%s\n", $loaded, var_export( $prov, true ) ) );
exit( 1 );
'

echo "==> Kill switch wins even when the plugin is mu-loaded"
# FORCE_2FA_DISABLE is checked before the re-load guard, so an mu-loaded plugin must
# still bail: FORCE_2FA_LOADED undefined and no enforcement filter attached.
wp config set FORCE_2FA_DISABLE true --raw --type=constant
wp eval '
$loaded = defined( "FORCE_2FA_LOADED" );
$prov   = has_filter( "two_factor_enabled_providers_for_user", "force_2fa_filter_enabled_providers" );
if ( ! $loaded && false === $prov ) {
    echo "FORCE2FA_MU_KILL_OK\n";
    exit( 0 );
}
fwrite( STDERR, sprintf( "FAIL loaded=%d providers_filter=%s\n", $loaded, var_export( $prov, true ) ) );
exit( 1 );
'
wp config delete FORCE_2FA_DISABLE --type=constant

# Restore a clean state for the uninstall assertions below (no mu-loader, no kill switch).
rm -f "$WP/wp-content/mu-plugins/mu-loader.php"

echo "==> Uninstall must purge PUC state across all sites (uninstall.php multisite path)"
# Exercises uninstall.php's multisite branch: the network-level StateStore option
# is deleted once, and the update-check cron is cleared on EVERY site via the
# get_sites() loop. The Plugin Update Checker itself is absent here (no vendor/),
# so seed the two artifacts it would create and assert uninstall removes them.
cp "$PLUGIN_DIR/uninstall.php" "$WP/wp-content/plugins/force-email-two-factor/"

PUC_OPTION="external_updates-force-email-two-factor"
PUC_CRON="puc_cron_check_updates-force-email-two-factor"

# A second subsite so the get_sites() loop must clear more than one site's cron.
wp site create --slug=sub2 >/dev/null
SITE_IDS="$(wp site list --field=blog_id)"
SITE_COUNT="$(printf '%s\n' "$SITE_IDS" | grep -c .)"
if [ "$SITE_COUNT" -lt 2 ]; then
  echo "FAIL: expected at least 2 sites for the multisite uninstall check, got ${SITE_COUNT}" >&2
  exit 1
fi

# Network-level cached-update option (StateStore uses update_site_option()).
wp eval "update_site_option( '${PUC_OPTION}', array( 'seeded' => true ) );"

# Update-check cron on every site (WP-Cron is per-site).
for site_id in $SITE_IDS; do
  site_url="$(wp site url "$site_id")"
  PUC_CRON="$PUC_CRON" wp --url="$site_url" eval '
    $hook = getenv( "PUC_CRON" );
    if ( ! wp_next_scheduled( $hook ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, "daily", $hook );
    }
    if ( ! wp_next_scheduled( $hook ) ) {
        fwrite( STDERR, "could not seed cron on site " . get_current_blog_id() . "\n" );
        exit( 1 );
    }
  '
done

# Network-deactivate first (separate command) so the delete runs with the plugin
# unloaded, exactly like the real Network Admin "Delete" flow.
wp plugin deactivate force-email-two-factor --network
wp plugin uninstall force-email-two-factor

# The network-level option must be gone.
option_state="$(wp eval "echo ( false === get_site_option( '${PUC_OPTION}' ) ) ? 'GONE' : 'PRESENT';")"
if [ "$option_state" != "GONE" ]; then
  echo "FAIL: uninstall left the network option ${PUC_OPTION} (${option_state})" >&2
  exit 1
fi

# The cron must be cleared on every site.
for site_id in $SITE_IDS; do
  site_url="$(wp site url "$site_id")"
  if wp --url="$site_url" cron event list --fields=hook --format=csv 2>/dev/null | grep -qx "$PUC_CRON"; then
    echo "FAIL: uninstall left cron ${PUC_CRON} scheduled on site ${site_id} (${site_url})" >&2
    exit 1
  fi
done
echo "    network option deleted and cron cleared on all ${SITE_COUNT} sites"

echo "==> Multisite E2E passed."
