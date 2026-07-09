#!/usr/bin/env bash
#
# Real WordPress + real Two Factor end-to-end check for the API-login allowlist.
#
# The allowlist policy — a service account may skip the second factor over the REST
# API only when it is BOTH allowlisted AND authenticated with an Application Password
# — is unit-tested in isolation, but nothing proves that the real Two Factor plugin
# actually invokes our filter, or that core records the app-password user before Two
# Factor evaluates the gate (the hook-ordering the whole design rests on). This drives
# genuine authenticated REST requests through a running WordPress to prove it.
#
# Uses the SQLite database drop-in and the PHP built-in server (`wp server`), so it
# needs no MySQL and no Apache. Tooling versions/checksums are pinned in
# bin/lib/e2e-common.sh. Two Factor is installed from wordpress.org (unpinned), as in
# the Playground blueprint and the update E2E.
#
# Asserts, with two editor users that differ ONLY in allowlist membership:
#   - control (our plugin inactive): a non-allowlisted user's Application Password
#     REST login succeeds — the baseline Two Factor allows — so the later denial is
#     attributable to THIS plugin, not to roles or core, and
#   - allowlisted account + Application Password  -> 200 (bypass fires), and
#   - non-allowlisted account + Application Password -> 401 (our filter denies, and
#     real Two Factor is what blocks it).
#
# Usage: bin/api-login-e2e.sh
# Optional env:
#   WP_VERSION=6.9         WordPress version to download (default: latest)
#   FORCE2FA_API_E2E_PORT  Port for the built-in server (default: 8899)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
WP="$WORK/wp"
PORT="${FORCE2FA_API_E2E_PORT:-8899}"
HOST="127.0.0.1"
BASE="http://${HOST}:${PORT}"
SERVER_PID=""
mkdir -p "$WP"

cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null || true
  rm -rf "$WORK"
}
trap cleanup EXIT

# shellcheck source=bin/lib/e2e-common.sh
. "$PLUGIN_DIR/bin/lib/e2e-common.sh"

echo "==> Fetch pinned WP-CLI ${E2E_WP_CLI_VERSION} (checksum-verified)"
e2e_fetch_wp_cli

echo "==> Download WordPress (${WP_VERSION:-latest})"
wp core download --version="${WP_VERSION:-latest}"

echo "==> SQLite database drop-in ${E2E_SDI_VERSION} (checksum-verified, no MySQL server needed)"
e2e_install_sqlite_dropin

echo "==> Install single-site WordPress at ${BASE}"
wp config create --dbname=wp --dbuser=root --dbpass="" --dbhost=localhost --skip-check --force
wp core install --url="$BASE" --title="API E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install and activate the real Two Factor plugin (from wordpress.org)"
wp plugin install two-factor --activate

# Allow Application Passwords over plain http on localhost (core disables them off-SSL
# by default), so the REST Basic-auth legs below can run without a TLS setup.
mkdir -p "$WP/wp-content/mu-plugins"
cat > "$WP/wp-content/mu-plugins/00-app-passwords-available.php" <<'PHP'
<?php
// E2E only: permit Application Passwords over http://localhost.
add_filter( 'wp_is_application_passwords_available', '__return_true' );
PHP

echo "==> Create two editor users differing only in allowlist membership"
wp user create svc   svc@example.com   --role=editor --user_pass=svc-real-pw     >/dev/null
wp user create other other@example.com --role=editor --user_pass=other-real-pw   >/dev/null

# Mint an Application Password for each (the plaintext is returned once, with spaces).
SVC_APP="$(wp user application-password create svc   e2e --porcelain)"
OTHER_APP="$(wp user application-password create other e2e --porcelain)"

# Authenticated, edit-context endpoint: returns 200 only for a real logged-in user.
ME="${BASE}/wp-json/wp/v2/users/me?context=edit"

start_server() {
  wp server --host="$HOST" --port="$PORT" >"$WORK/server.log" 2>&1 &
  SERVER_PID="$!"
  # Wait for the built-in server to accept connections (up to ~30s).
  for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "${BASE}/wp-json/" 2>/dev/null; then
      return 0
    fi
    sleep 0.5
  done
  echo "FAIL: wp server did not become ready" >&2
  cat "$WORK/server.log" >&2 || true
  exit 1
}

# HTTP status for a Basic-auth GET of the me-endpoint as "<login>:<app password>".
status_for() {
  curl -s -o /dev/null -w '%{http_code}' -u "$1:$2" "$ME"
}

echo "==> Start the built-in server"
start_server

echo "==> Control: with THIS plugin inactive, a non-allowlisted app-password login succeeds"
code="$(status_for other "$OTHER_APP")"
if [ "$code" != "200" ]; then
  echo "FAIL: baseline app-password REST login expected 200, got ${code}" >&2
  exit 1
fi
echo "    baseline 200 (Two Factor alone allows app-password API logins)"

echo "==> Activate Require Email 2FA and allowlist only 'svc'"
mkdir -p "$WP/wp-content/plugins/force-email-two-factor"
cp "$PLUGIN_DIR/force-email-two-factor.php" "$WP/wp-content/plugins/force-email-two-factor/"
cat > "$WP/wp-content/mu-plugins/10-allowlist.php" <<'PHP'
<?php
// E2E only: allowlist the 'svc' service account by login.
add_filter( 'force_2fa_api_login_allowlist', function () { return array( 'svc' ); } );
PHP
wp plugin activate force-email-two-factor

# Sanity: the dependency is met (real Two Factor registers the Email provider) and
# both users are now 2FA-enforced, so the API gate is actually in play for both.
wp eval '
$ok = force_2fa_dependency_met()
   && in_array( "Two_Factor_Email", (array) Two_Factor_Core::get_enabled_providers_for_user( get_user_by( "login", "svc" )->ID ), true )
   && in_array( "Two_Factor_Email", (array) Two_Factor_Core::get_enabled_providers_for_user( get_user_by( "login", "other" )->ID ), true );
if ( ! $ok ) { fwrite( STDERR, "FAIL: enforcement precondition not met\n" ); exit( 1 ); }
echo "FORCE2FA_PRECOND_OK\n";
'

echo "==> Allowlisted account + Application Password must be ALLOWED (200)"
code="$(status_for svc "$SVC_APP")"
if [ "$code" != "200" ]; then
  echo "FAIL: allowlisted app-password login expected 200, got ${code}" >&2
  exit 1
fi
echo "    200, as expected (bypass fires: app-password user recorded, allowlist matched)"

echo "==> Non-allowlisted account + Application Password must be DENIED (401/403)"
code="$(status_for other "$OTHER_APP")"
# "Denied" is the security property; accept either unauthorized (401) or forbidden
# (403) so the test does not turn a Two Factor status-code choice into a false CI
# failure. What must never happen is a 200 — that would be a real bypass.
if [ "$code" != "401" ] && [ "$code" != "403" ]; then
  echo "FAIL: non-allowlisted app-password login expected 401/403, got ${code}" >&2
  exit 1
fi
echo "    ${code}, as expected (real Two Factor blocks the API login our filter denied)"

echo "==> API-login E2E passed."
