#!/usr/bin/env bash
#
# Real WordPress multisite end-to-end check for the network-only behavior.
#
# Uses the SQLite database drop-in so it needs no MySQL server — it runs
# identically on a laptop and in CI. WP-CLI is invoked with a raised memory limit
# because unpacking the core tarball exceeds the stock 128M on some setups.
#
# Asserts, on a real multisite:
#   - a per-site `wp plugin activate` is REFUSED (the register_activation_hook
#     guard rolls it back), while `--network` succeeds, and
#   - with Two Factor absent the plugin loads and safely no-ops, and
#   - the plugin ends up network-active and never per-site.
#
# Usage: bin/multisite-e2e.sh
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
WP="$WORK/wp"
mkdir -p "$WP"
trap 'rm -rf "$WORK"' EXIT

WP_CLI_PHAR="$WORK/wp-cli.phar"
curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WP_CLI_PHAR"

# WP-CLI wrapper: enough memory for extraction, E_DEPRECATED silenced (WP-CLI on
# bleeding-edge PHP is noisy), and --path baked in.
wp() { php -d memory_limit=512M -d error_reporting=24575 "$WP_CLI_PHAR" --path="$WP" "$@"; }

echo "==> Download WordPress (${WP_VERSION:-latest})"
wp core download --version="${WP_VERSION:-latest}"

echo "==> SQLite database drop-in (no MySQL server needed)"
curl -sSL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "$WORK/sdi.zip"
unzip -q "$WORK/sdi.zip" -d "$WP/wp-content/plugins/"
cp "$WP/wp-content/plugins/sqlite-database-integration/db.copy" "$WP/wp-content/db.php"

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
echo "==> Multisite E2E passed."
