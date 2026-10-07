#!/usr/bin/env bash
#
# Real WordPress multisite end-to-end check for NETWORK-WIDE enforcement scope.
#
# Proves, against a real multisite with the real Two Factor plugin, that the
# capability scope and the role-exclusion carve-out are both evaluated network-wide —
# so a user who is low-privilege on the site they log in through, but privileged on
# another network site, cannot escape enforcement. WordPress auth cookies are
# network-wide, so a per-login-site check would be a real bypass (a session opened on
# the low-privilege site is usable on the site they administer).
#
# Reproduced configuration (the exact combination flagged in review):
#   - FORCE_2FA_ENFORCED_CAPABILITY = manage_options   (admins-only opt-in)
#   - FORCE_2FA_EXCLUDED_ROLES      = [ subscriber ]
#   - "crossadmin": subscriber on the primary site, administrator on a subsite
#   - "lowpriv":    subscriber on the primary site only
#
# Asserts, evaluated from the PRIMARY site's context:
#   - crossadmin is NOT exempt and Two Factor treats them as using 2FA (the Email
#     floor is applied) — neither the capability scope nor the subscriber exclusion
#     lets their cross-site administrator account slip through, and
#   - lowpriv IS exempt and is not using 2FA (a genuinely low-privilege account).
#
# Uses the SQLite drop-in so it needs no MySQL server (same pattern as the other E2E
# scripts). Usage: bin/multisite-scope-e2e.sh
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
wp core multisite-install --url=http://localhost --title="MS Scope E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install + NETWORK-activate the real Two Factor plugin"
wp plugin install two-factor
wp plugin activate two-factor --network

echo "==> Install + network-activate the plugin under test (copied — a symlink breaks plugin_basename)"
mkdir -p "$WP/wp-content/plugins/force-email-two-factor"
cp "$PLUGIN_DIR/force-email-two-factor.php" "$WP/wp-content/plugins/force-email-two-factor/"
wp plugin activate force-email-two-factor --network

echo "==> Configure admins-only scope + subscriber exclusion (via filters)"
mkdir -p "$WP/wp-content/mu-plugins"
cat > "$WP/wp-content/mu-plugins/10-scope.php" <<'PHP'
<?php
// E2E only: opt in to admins-only enforcement and exclude subscribers, to exercise
// the network-wide capability + role-exclusion evaluation together.
add_filter( 'force_2fa_enforced_capability', function () { return 'manage_options'; } );
add_filter( 'force_2fa_excluded_roles', function () { return array( 'subscriber' ); } );
PHP

echo "==> Create a second subsite"
SITE_B_ID="$(wp site create --slug=siteb --porcelain)"

echo "==> Create users: crossadmin (subscriber here, admin on siteb) and lowpriv (subscriber here)"
wp user create crossadmin cross@example.com --role=subscriber --user_pass=x >/dev/null
wp user create lowpriv    low@example.com   --role=subscriber --user_pass=x >/dev/null

# Make crossadmin an administrator on the second subsite (network-wide, they now hold
# manage_options somewhere even though they are only a subscriber on the primary site).
wp eval "add_user_to_blog( ${SITE_B_ID}, get_user_by( 'login', 'crossadmin' )->ID, 'administrator' );"

echo "==> Assert network-wide enforcement from the PRIMARY site's context"
wp eval '
$cross = get_user_by( "login", "crossadmin" );
$low   = get_user_by( "login", "lowpriv" );

$dep          = force_2fa_dependency_met();
$cross_exempt = force_2fa_user_is_exempt( $cross );
$cross_using  = Two_Factor_Core::is_user_using_two_factor( $cross->ID );
$low_exempt   = force_2fa_user_is_exempt( $low );
$low_using    = Two_Factor_Core::is_user_using_two_factor( $low->ID );

// crossadmin: privileged on another site → in scope network-wide → enforced.
// lowpriv: subscriber everywhere → exempt (out of scope) → not enforced.
if ( $dep && ! $cross_exempt && $cross_using && $low_exempt && ! $low_using ) {
    echo "FORCE2FA_MS_SCOPE_OK\n";
    exit( 0 );
}
fwrite( STDERR, sprintf(
    "FAIL dep=%d cross_exempt=%d cross_using=%d low_exempt=%d low_using=%d\n",
    $dep, $cross_exempt, $cross_using, $low_exempt, $low_using
) );
exit( 1 );
'

echo "==> Multisite scope E2E passed."
