#!/usr/bin/env bash
#
# Real-WordPress end-to-end check for blocking mode.
#
# Uses the SQLite drop-in so it needs no MySQL server (same pattern as
# multisite-e2e.sh / update-e2e.sh). Installs the REAL Two Factor plugin so the
# integration assumptions this feature rests on are exercised against the real
# thing, not a stub:
#
#   - force_2fa_user_has_configured_2fa() keys off Two Factor's actual
#     enabled-providers user meta, and flips true only once a provider is really
#     enabled (not from this plugin's runtime Email floor).
#   - the blocking gate's composed decision (force_2fa_should_require_setup +
#     force_2fa_request_is_gateable) evaluates correctly for a real unconfigured
#     user and releases once they configure.
#
# Usage: bin/blocking-mode-e2e.sh
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d 2>/dev/null || echo "${TMPDIR:-/tmp}/force2fa-blocking-e2e-$$")"
WP="$WORK/wp"
mkdir -p "$WP"
trap 'rm -rf "$WORK"' EXIT

# shellcheck source=bin/lib/e2e-common.sh
. "$PLUGIN_DIR/bin/lib/e2e-common.sh"

echo "==> Fetch pinned WP-CLI ${E2E_WP_CLI_VERSION} (checksum-verified)"
e2e_fetch_wp_cli

echo "==> Download WordPress (${WP_VERSION:-latest})"
wp core download --version="${WP_VERSION:-latest}"

echo "==> SQLite database drop-in ${E2E_SDI_VERSION} (checksum-verified)"
e2e_install_sqlite_dropin

echo "==> Install single-site WordPress"
wp config create --dbname=wp --dbuser=root --dbpass="" --dbhost=localhost --skip-check --force
wp core install --url=http://localhost --title="Blocking Mode E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install + activate the REAL Two Factor plugin"
wp plugin install two-factor --activate

echo "==> Install the plugin under test (copied — a symlink breaks plugin_basename)"
mkdir -p "$WP/wp-content/plugins/force-email-two-factor"
cp "$PLUGIN_DIR/force-email-two-factor.php" "$WP/wp-content/plugins/force-email-two-factor/"
wp plugin activate force-email-two-factor

echo "==> Create an unconfigured editor user"
# wp user create does not email unless --send-email is passed, so no skip flag needed.
wp user create alice alice@example.com --role=editor --user_pass=pw >/dev/null

echo "==> Enable blocking mode"
wp config set FORCE_2FA_BLOCKING_MODE true --raw

echo "==> Assert: real Two Factor meta key matches what the plugin reads"
wp eval '
$expected = defined( "Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY" )
    ? Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY : "(undefined)";
$actual = force_2fa_enabled_providers_meta_key();
if ( $expected !== "(undefined)" && $expected !== $actual ) {
    fwrite( STDERR, "FAIL meta key: plugin=$actual twofactor=$expected\n" ); exit( 1 );
}
echo "    OK meta key = $actual\n";
'

echo "==> Assert: unconfigured user needs setup, gate would fire on a page load"
wp eval '
$u = get_user_by( "email", "alice@example.com" );
if ( force_2fa_user_has_configured_2fa( $u ) ) { fwrite( STDERR, "FAIL: alice reads as configured before setup\n" ); exit( 1 ); }
$need = force_2fa_should_require_setup( force_2fa_blocking_mode_enabled(), force_2fa_dependency_met(), true, force_2fa_user_is_exempt( $u ), force_2fa_user_has_configured_2fa( $u ) );
if ( ! $need ) { fwrite( STDERR, "FAIL: unconfigured alice should require setup\n" ); exit( 1 ); }
// Ordinary interactive page load is gateable; the profile screen and API paths are not.
if ( ! force_2fa_request_is_gateable( false, false, false, false, false, false ) ) { fwrite( STDERR, "FAIL: page load should be gateable\n" ); exit( 1 ); }
if ( force_2fa_request_is_gateable( true, false, false, false, false, false ) ) { fwrite( STDERR, "FAIL: ajax must NOT be gateable\n" ); exit( 1 ); }
if ( force_2fa_request_is_gateable( false, false, false, false, false, true ) ) { fwrite( STDERR, "FAIL: setup screen must NOT be gateable\n" ); exit( 1 ); }
echo "    OK unconfigured alice is gated on page loads, exempt on setup/API paths\n";
'

echo "==> Enable a real provider for alice via Two Factor, then assert the gate releases"
wp eval '
$u = get_user_by( "email", "alice@example.com" );
// Enable TOTP — a provider this plugin does NOT inject via the
// two_factor_enabled_providers_for_user filter. (Enabling Two_Factor_Email would be a
// no-op: the filter already reports Email as enabled, so Two Factor short-circuits
// without writing _two_factor_enabled_providers, and the assertion below would never
// see a real configuration.) enable_provider_for_user() persists the enabled-providers
// meta through Two Factor is own path.
Two_Factor_Core::enable_provider_for_user( $u->ID, "Two_Factor_Totp" );
clean_user_cache( $u->ID );
if ( ! force_2fa_user_has_configured_2fa( $u ) ) { fwrite( STDERR, "FAIL: alice should read as configured after enabling a provider\n" ); exit( 1 ); }
$need = force_2fa_should_require_setup( force_2fa_blocking_mode_enabled(), force_2fa_dependency_met(), true, force_2fa_user_is_exempt( $u ), force_2fa_user_has_configured_2fa( $u ) );
if ( $need ) { fwrite( STDERR, "FAIL: configured alice should NOT require setup\n" ); exit( 1 ); }
echo "    OK gate releases once a provider is actually enabled\n";
'

echo "==> Blocking-mode E2E passed."
