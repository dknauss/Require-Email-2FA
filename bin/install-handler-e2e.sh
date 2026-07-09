#!/usr/bin/env bash
#
# Real WordPress end-to-end check for the one-click "Install & activate Two Factor"
# handler — force_2fa_handle_install_two_factor().
#
# The handler's front gate (nonce + capability wall) is unit-tested, but its upgrader
# body — plugins_api(), Plugin_Upgrader->install() from wordpress.org, resolving the
# installed file via plugin_info(), then activate_plugin() — is not, and the
# Playground blueprint installs Two Factor via a blueprint step, so the button flow
# never runs there. This drives the real handler on a real WordPress with Two Factor
# absent and asserts it installs AND activates it.
#
# The handler is invoked through the real check_admin_referer() with a genuine nonce
# and an authenticated admin (wp_set_current_user), rather than scripting a browser
# login — the same handler code runs, including the full upgrader path. The redirect
# that ends the handler is intercepted so the run exits cleanly with a marker.
#
# Uses the SQLite drop-in; Two Factor is fetched from wordpress.org by the handler
# itself (the code path under test). Tooling is pinned in bin/lib/e2e-common.sh.
#
# Usage: bin/install-handler-e2e.sh
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

echo "==> Install single-site WordPress"
wp config create --dbname=wp --dbuser=root --dbpass="" --dbhost=localhost --skip-check --force
wp core install --url=http://localhost --title="Install Handler E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install and activate the plugin under test (Two Factor deliberately absent)"
mkdir -p "$WP/wp-content/plugins/force-email-two-factor"
cp "$PLUGIN_DIR/force-email-two-factor.php" "$WP/wp-content/plugins/force-email-two-factor/"
wp plugin activate force-email-two-factor

echo "==> Precondition: Two Factor is not installed"
if wp plugin is-installed two-factor 2>/dev/null; then
  echo "FAIL: Two Factor is already installed; the handler cannot exercise the install path" >&2
  exit 1
fi

echo "==> Invoke the one-click install handler (real nonce, admin user)"
cat > "$WORK/run-handler.php" <<'PHP'
<?php
// Authenticate as the admin (holds install_plugins/activate_plugins) and mint a
// genuine nonce for the action, so the real check_admin_referer() and capability
// wall run exactly as they do for a browser click.
wp_set_current_user( 1 );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'force_2fa_install_two_factor' );

// The handler ends with wp_safe_redirect()+exit; intercept the redirect so the run
// exits cleanly (and before any header is sent) once the install/activate is done.
add_filter( 'wp_redirect', function ( $location ) {
	echo "FORCE2FA_INSTALL_REDIRECT " . $location . "\n";
	exit( 0 );
} );

force_2fa_handle_install_two_factor();

fwrite( STDERR, "FAIL: handler returned without redirecting\n" );
exit( 1 );
PHP
wp eval-file "$WORK/run-handler.php"

echo "==> Assert the handler installed AND activated Two Factor"
if ! wp plugin is-installed two-factor; then
  echo "FAIL: Two Factor was not installed by the handler" >&2
  exit 1
fi
if ! wp plugin is-active two-factor; then
  echo "FAIL: Two Factor was installed but not activated by the handler" >&2
  exit 1
fi

echo "==> Assert enforcement now engages (dependency met, Email floor applied)"
wp eval '
$ok = force_2fa_dependency_met()
   && in_array( "Two_Factor_Email", (array) Two_Factor_Core::get_enabled_providers_for_user( 1 ), true );
if ( ! $ok ) { fwrite( STDERR, "FAIL: enforcement did not engage after install\n" ); exit( 1 ); }
echo "FORCE2FA_INSTALL_HANDLER_OK\n";
'

echo "==> Install-handler E2E passed."
