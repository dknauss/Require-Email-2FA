#!/usr/bin/env bash
#
# Real-WordPress end-to-end check for the email-as-fallback behavior (1.13.1).
#
# Proves, against a real single-site WordPress + real Two Factor, that:
#   - a user with a native method (TOTP) still gets the emailed floor appended (both
#     Two_Factor_Totp AND Two_Factor_Email are enabled), and
#   - a real method keeps PRIMARY even with no stored primary selection — the appended
#     email floor must not demote it (TOTP sorts after Email in Two Factor's order, so
#     without the fix Email would win first-available), and
#   - an unprotected user gets Email as their sole provider and primary.
#
# (The Wordfence external-2FA exemption is covered by its built-in detector
# force_2fa_wordfence_2fa_active() and a unit stub; installing real Wordfence here would
# be heavyweight. Other external systems are handled via the force_2fa_user_is_exempt
# filter.)
#
# Uses the SQLite drop-in. Usage: bin/email-fallback-e2e.sh
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d 2>/dev/null || echo "${TMPDIR:-/tmp}/force2fa-fallback-e2e-$$")"
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
wp core install --url=http://localhost --title="Fallback E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install + activate the REAL Two Factor plugin"
wp plugin install two-factor --activate

echo "==> Install + activate the plugin under test"
mkdir -p "$WP/wp-content/plugins/force-email-two-factor"
cp "$PLUGIN_DIR/force-email-two-factor.php" "$WP/wp-content/plugins/force-email-two-factor/"
wp plugin activate force-email-two-factor

echo "==> Create a TOTP user (native method, no stored primary) and an unprotected user"
wp user create totper totper@example.com --role=editor --user_pass=x >/dev/null
wp user create nobody nobody@example.com --role=editor --user_pass=x >/dev/null

# Enable a real TOTP secret for 'totper' via Two Factor's own API (no primary selected).
wp eval '
$u    = get_user_by( "login", "totper" );
$totp = Two_Factor_Totp::get_instance();
$totp->set_user_totp_key( $u->ID, Two_Factor_Totp::generate_key() );
Two_Factor_Core::enable_provider_for_user( $u->ID, "Two_Factor_Totp" );
'

echo "==> Assert: TOTP user keeps TOTP + gets the email floor, and TOTP stays primary"
wp eval '
$u        = get_user_by( "login", "totper" );
$enabled  = (array) Two_Factor_Core::get_enabled_providers_for_user( $u->ID );
$primary  = Two_Factor_Core::get_primary_provider_for_user( $u->ID );
$primary  = is_object( $primary ) ? get_class( $primary ) : "(none)";
$has_totp = in_array( "Two_Factor_Totp", $enabled, true );
$has_mail = in_array( "Two_Factor_Email", $enabled, true );
if ( $has_totp && $has_mail && "Two_Factor_Totp" === $primary ) {
    echo "FORCE2FA_TOTP_FLOOR_OK\n"; exit( 0 );
}
fwrite( STDERR, sprintf( "FAIL totp: has_totp=%d has_mail=%d primary=%s\n", $has_totp, $has_mail, $primary ) );
exit( 1 );
'

echo "==> Assert: unprotected user gets Email as sole provider and primary"
wp eval '
$u       = get_user_by( "login", "nobody" );
$enabled = (array) Two_Factor_Core::get_enabled_providers_for_user( $u->ID );
$primary = Two_Factor_Core::get_primary_provider_for_user( $u->ID );
$primary = is_object( $primary ) ? get_class( $primary ) : "(none)";
if ( array( "Two_Factor_Email" ) === array_values( $enabled ) && "Two_Factor_Email" === $primary ) {
    echo "FORCE2FA_EMAIL_PRIMARY_OK\n"; exit( 0 );
}
fwrite( STDERR, sprintf( "FAIL nobody: enabled=%s primary=%s\n", implode( ",", $enabled ), $primary ) );
exit( 1 );
'

echo "==> Email-fallback E2E passed."
