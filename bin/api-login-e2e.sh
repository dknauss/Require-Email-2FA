#!/usr/bin/env bash
#
# Real WordPress + real Two Factor end-to-end check for the API-login allowlist,
# exercised over XML-RPC.
#
# The allowlist policy — a service account may skip the second factor on an API
# login only when it is BOTH allowlisted AND authenticated with an Application
# Password — is unit-tested in isolation, but nothing proves that the real Two
# Factor plugin invokes our filter, or that core records the app-password user
# before Two Factor evaluates the gate (the hook ordering the whole design rests on).
#
# Why XML-RPC and not REST: Two Factor's only API-login gate is filter_authenticate()
# on the 'authenticate' filter (priority 31). XML-RPC logins run through
# wp_authenticate() and hit that gate; REST Application Password logins authenticate
# via core's 'determine_current_user' path (wp_validate_application_password) and
# never touch the 'authenticate' chain, so Two Factor — and therefore this plugin's
# 'two_factor_user_api_login_enable' filter — does not gate them. The allowlist only
# constrains the authenticate path (XML-RPC and other non-REST logins); see the note
# in force-email-two-factor.php. This test targets the path where enforcement runs.
#
# Uses the SQLite drop-in and the PHP built-in server. Two Factor is installed from
# wordpress.org (unpinned), as in the Playground blueprint and the update E2E.
#
# Asserts, with two editor users that differ ONLY in allowlist membership:
#   - control (our plugin inactive): a non-allowlisted user's app-password XML-RPC
#     login succeeds, so the later denial is attributable to THIS plugin, and
#   - allowlisted account + Application Password  -> allowed, and
#   - non-allowlisted account + Application Password -> denied (real Two Factor
#     blocks it), and
#   - allowlisted account + REAL password -> denied (proves condition (b): an
#     Application Password, not merely allowlist membership, is required).
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
# by default), so the XML-RPC app-password legs below can run without a TLS setup.
mkdir -p "$WP/wp-content/mu-plugins"
cat > "$WP/wp-content/mu-plugins/00-app-passwords-available.php" <<'PHP'
<?php
// E2E only: permit Application Passwords over http://localhost.
add_filter( 'wp_is_application_passwords_available', '__return_true' );
PHP

echo "==> Create two editor users differing only in allowlist membership"
SVC_REAL_PW="svc-real-pw"
wp user create svc   svc@example.com   --role=editor --user_pass="$SVC_REAL_PW" >/dev/null
wp user create other other@example.com --role=editor --user_pass=other-real-pw   >/dev/null

# Mint an Application Password for each (the plaintext is returned once, with spaces).
SVC_APP="$(wp user application-password create svc   e2e --porcelain)"
OTHER_APP="$(wp user application-password create other e2e --porcelain)"

# POST an authenticated wp.getUsersBlogs XML-RPC call as "<user>" / "<password>" and
# echo the raw response body. Success returns a struct containing <name>isAdmin</name>;
# an auth/2FA denial returns a <fault>.
xmlrpc_get_users_blogs() {
  local user="$1" pass="$2"
  curl -s "${BASE}/xmlrpc.php" -H 'Content-Type: text/xml' --data-binary @- <<XML
<?xml version="1.0"?>
<methodCall>
  <methodName>wp.getUsersBlogs</methodName>
  <params>
    <param><value><string>${user}</string></value></param>
    <param><value><string>${pass}</string></value></param>
  </params>
</methodCall>
XML
}

assert_allowed() {
  local label="$1" resp="$2"
  if printf '%s' "$resp" | grep -q '<fault>'; then
    echo "FAIL: ${label} expected success, got a fault:" >&2
    printf '%s\n' "$resp" | head -8 >&2
    exit 1
  fi
  if ! printf '%s' "$resp" | grep -qi 'isAdmin'; then
    echo "FAIL: ${label} expected a getUsersBlogs struct, got:" >&2
    printf '%s\n' "$resp" | head -8 >&2
    exit 1
  fi
}

assert_denied() {
  local label="$1" resp="$2"
  if ! printf '%s' "$resp" | grep -q '<fault>'; then
    echo "FAIL: ${label} expected a fault (denied), got:" >&2
    printf '%s\n' "$resp" | head -8 >&2
    exit 1
  fi
}

start_server() {
  wp server --host="$HOST" --port="$PORT" >"$WORK/server.log" 2>&1 &
  SERVER_PID="$!"
  # Wait for the built-in server to accept connections (up to ~30s).
  for _ in $(seq 1 60); do
    if curl -fsS -o /dev/null "${BASE}/xmlrpc.php" 2>/dev/null; then
      return 0
    fi
    sleep 0.5
  done
  echo "FAIL: wp server did not become ready" >&2
  cat "$WORK/server.log" >&2 || true
  exit 1
}

echo "==> Start the built-in server"
start_server

echo "==> Control: with THIS plugin inactive, a non-allowlisted app-password login succeeds"
assert_allowed "control (plugin inactive)" "$(xmlrpc_get_users_blogs other "$OTHER_APP")"
echo "    allowed, as expected (Two Factor alone permits app-password API logins)"

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

echo "==> Allowlisted account + Application Password must be ALLOWED"
assert_allowed "allowlisted + app password" "$(xmlrpc_get_users_blogs svc "$SVC_APP")"
echo "    allowed, as expected (bypass fires: app-password user recorded, allowlist matched)"

echo "==> Non-allowlisted account + Application Password must be DENIED"
assert_denied "non-allowlisted + app password" "$(xmlrpc_get_users_blogs other "$OTHER_APP")"
echo "    denied, as expected (real Two Factor blocks the API login our filter denied)"

echo "==> Allowlisted account + REAL password must be DENIED (Application Password required)"
assert_denied "allowlisted + real password" "$(xmlrpc_get_users_blogs svc "$SVC_REAL_PW")"
echo "    denied, as expected (condition (b): a real-password API login never bypasses)"

echo "==> API-login (XML-RPC) E2E passed."
