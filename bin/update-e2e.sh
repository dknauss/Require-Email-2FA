#!/usr/bin/env bash
#
# Real WordPress end-to-end check for the GitHub Releases update facility.
#
# Uses a disposable SQLite-backed WordPress install. It installs the plugin from
# the committed tree (`git archive`, the same layout the release workflow ships),
# rewrites only the installed copy's Version / FORCE_2FA_LOADED marker to simulate
# an older release, forces the WordPress update check, and asserts that Plugin
# Update Checker offers EXACTLY this repository's latest release asset — owner,
# repo, tag, and asset name pinned by string equality — and installs it.
#
# Note: the fixture is built from HEAD, so uncommitted changes are not exercised.
#
# Usage: bin/update-e2e.sh
# Optional env:
#   WP_VERSION=7.0                         WordPress version to download (default: latest)
#   FORCE2FA_FAKE_VERSION=0.0.1            Version written into the disposable install
#   FORCE2FA_UPDATE_E2E_KEEP=1             Keep the temp WordPress directory for inspection
#   FORCE2FA_UPDATE_E2E_WPCLI_CACHE_DIR=...
#                                          Override the isolated WP-CLI cache directory
#                                          (default: temp dir, removed after each run)
#   GITHUB_TOKEN=...                       Authenticate GitHub API calls (script + PUC),
#                                          avoiding the anonymous per-IP rate limit in CI
#   FORCE2FA_UPDATE_E2E_TOLERATE_MISSING_ASSET=1
#                                          Skip (exit 0, with a warning) when the latest
#                                          release lacks the zip asset — external release
#                                          state a PR cannot influence. CI sets this for
#                                          pull_request runs only.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SLUG="force-email-two-factor"
PLUGIN_MAIN="${PLUGIN_SLUG}.php"
FAKE_VERSION="${FORCE2FA_FAKE_VERSION:-0.0.1}"
WORK="$(mktemp -d)"
WP="$WORK/wp"
WP_CLI_CACHE_DIR="${FORCE2FA_UPDATE_E2E_WPCLI_CACHE_DIR:-$WORK/wp-cli-cache}"
export WP_CLI_CACHE_DIR

# shellcheck source=bin/lib/e2e-common.sh
. "$PLUGIN_DIR/bin/lib/e2e-common.sh"

cleanup() {
  if [ "${FORCE2FA_UPDATE_E2E_KEEP:-0}" = "1" ]; then
    echo "==> Keeping temp WordPress at: $WP"
  else
    rm -rf "$WORK"
  fi
}
trap cleanup EXIT

mkdir -p "$WP" "$WP_CLI_CACHE_DIR"

echo "==> WP-CLI cache: $WP_CLI_CACHE_DIR"

UPDATE_URI="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Update URI:[[:space:]]*//p' "$PLUGIN_DIR/$PLUGIN_MAIN" | head -n1 | tr -d '\r')"
if [ -z "$UPDATE_URI" ]; then
  echo "FAIL: Update URI header is missing from $PLUGIN_MAIN" >&2
  exit 1
fi

OWNER_REPO="$(printf '%s' "$UPDATE_URI" | sed -E 's#^https://github.com/([^/]+/[^/?#]+).*$#\1#')"
if [ "$OWNER_REPO" = "$UPDATE_URI" ]; then
  echo "FAIL: Update URI is not a supported GitHub repository URL: $UPDATE_URI" >&2
  exit 1
fi

github_curl() {
  local args=(
    -fsSL
    -H "Accept: application/vnd.github+json"
    -H "X-GitHub-Api-Version: 2022-11-28"
  )
  if [ -n "${GITHUB_TOKEN:-}" ]; then
    args+=( -H "Authorization: Bearer ${GITHUB_TOKEN}" )
  fi
  curl "${args[@]}" "$@"
}

RELEASE_JSON="$(github_curl "https://api.github.com/repos/${OWNER_REPO}/releases/latest")"
LATEST_TAG="$(printf '%s' "$RELEASE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); if (!is_array($j) || empty($j["tag_name"])) { exit(1); } echo $j["tag_name"];')"
LATEST_VERSION="${LATEST_TAG#v}"
# The exact asset URLs from this repository's release JSON. PUC offers
# browser_download_url when anonymous and the API asset URL when authenticated;
# the assertion accepts exactly these two strings and nothing else.
ASSET_URL="$(printf '%s' "$RELEASE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); foreach (($j["assets"] ?? array()) as $a) { if (($a["name"] ?? "") === "force-email-two-factor.zip") { echo $a["browser_download_url"] ?? ""; exit(0); } } exit(1);' || true)"
ASSET_API_URL="$(printf '%s' "$RELEASE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); foreach (($j["assets"] ?? array()) as $a) { if (($a["name"] ?? "") === "force-email-two-factor.zip") { echo $a["url"] ?? ""; exit(0); } } exit(1);' || true)"

if [ -z "$ASSET_URL" ]; then
  MSG="latest release ${LATEST_TAG} has no ${PLUGIN_SLUG}.zip asset"
  if [ "${FORCE2FA_UPDATE_E2E_TOLERATE_MISSING_ASSET:-0}" = "1" ]; then
    echo "::warning::Update E2E skipped: ${MSG} — external release state, not this change. Fix the release, then re-run."
    echo "SKIP: ${MSG}"
    exit 0
  fi
  echo "FAIL: ${MSG}" >&2
  exit 1
fi

echo "==> Update source: $UPDATE_URI"
echo "==> Latest release: $LATEST_TAG"
echo "==> Release asset:  $ASSET_URL"

echo "==> Fetch pinned WP-CLI ${E2E_WP_CLI_VERSION} (checksum-verified)"
e2e_fetch_wp_cli

echo "==> Download WordPress (${WP_VERSION:-latest})"
wp core download --version="${WP_VERSION:-latest}"

echo "==> SQLite database drop-in ${E2E_SDI_VERSION} (checksum-verified, no MySQL server needed)"
e2e_install_sqlite_dropin

echo "==> Install WordPress"
wp config create --dbname=wp --dbuser=root --dbpass="" --dbhost=localhost --skip-check --force
wp core install --url=http://localhost --title="Require Email 2FA Update E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install plugin under test from the committed tree (git archive = release layout)"
DEST="$WP/wp-content/plugins/$PLUGIN_SLUG"
git -C "$PLUGIN_DIR" archive --format=tar --prefix="$PLUGIN_SLUG/" HEAD | tar -x -C "$WP/wp-content/plugins/"

if [ ! -f "$DEST/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php" ]; then
  echo "FAIL: Plugin Update Checker was not part of the archived plugin install" >&2
  exit 1
fi

echo "==> Install E2E helper mu-plugin (checker discovery + optional GitHub auth)"
mkdir -p "$WP/wp-content/mu-plugins"
cp "$PLUGIN_DIR/bin/lib/update-e2e-mu-shim.php" "$WP/wp-content/mu-plugins/"

echo "==> Simulate an older installed version (${FAKE_VERSION})"
# One pass, two substitutions; \x27 = single quote. Fails loudly when either
# pattern stops matching (perl s/// exits 0 on no match, so count them).
FAKE_VERSION="$FAKE_VERSION" perl -0pi -e '
  $n += s/(\*[ \t]*Version:[ \t]*)[^\r\n]+/$1$ENV{FAKE_VERSION}/;
  $n += s/define\(\s*\x27FORCE_2FA_LOADED\x27\s*,\s*\x27[^\x27]+\x27\s*\);/define( \x27FORCE_2FA_LOADED\x27, \x27$ENV{FAKE_VERSION}\x27 );/;
  END { exit( 2 == $n ? 0 : 1 ); }
' "$DEST/$PLUGIN_MAIN" || {
  echo "FAIL: could not rewrite Version and FORCE_2FA_LOADED in the disposable copy — patterns need updating" >&2
  exit 1
}

wp plugin activate "$PLUGIN_SLUG"
INSTALLED_VERSION="$(wp plugin get "$PLUGIN_SLUG" --field=version)"
if [ "$INSTALLED_VERSION" != "$FAKE_VERSION" ]; then
  echo "FAIL: expected fake installed version ${FAKE_VERSION}, got ${INSTALLED_VERSION}" >&2
  exit 1
fi

echo "==> Force updater check (must offer exactly the latest release asset)"
export FORCE2FA_EXPECT="update"
export FORCE2FA_EXPECTED_VERSION="$LATEST_VERSION"
export FORCE2FA_EXPECTED_PACKAGE="$ASSET_URL"
export FORCE2FA_EXPECTED_PACKAGE_API="$ASSET_API_URL"
wp eval-file "$PLUGIN_DIR/bin/lib/update-check.php"

echo "==> Apply update"
wp plugin update "$PLUGIN_SLUG"

UPDATED_VERSION="$(wp plugin get "$PLUGIN_SLUG" --field=version)"
if [ "$UPDATED_VERSION" != "$LATEST_VERSION" ]; then
  echo "FAIL: expected updated version ${LATEST_VERSION}, got ${UPDATED_VERSION}" >&2
  exit 1
fi

if [ ! -f "$DEST/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php" ]; then
  echo "FAIL: updated plugin is missing Plugin Update Checker vendor files" >&2
  exit 1
fi

echo "==> Confirm current release offers no further update"
export FORCE2FA_EXPECT="none"
wp eval-file "$PLUGIN_DIR/bin/lib/update-check.php"

echo "==> Uninstall must purge Plugin Update Checker state (option + cron)"
# The plugin is otherwise stateless; its only persistent footprint is the PUC
# StateStore option and the update-check cron. uninstall.php must remove both so
# deletion leaves nothing behind.
PUC_OPTION="external_updates-${PLUGIN_SLUG}"
PUC_CRON="puc_cron_check_updates-${PLUGIN_SLUG}"

# The forced checks above persist PUC's StateStore option; assert it is really
# present so the post-uninstall check below cannot pass vacuously.
if ! wp option get "$PUC_OPTION" >/dev/null 2>&1; then
  echo "FAIL: expected PUC state option ${PUC_OPTION} to exist before uninstall" >&2
  exit 1
fi

# PUC normally schedules this cron itself, but whether it is present depends on
# request lifecycle, so ensure it idempotently: schedule one only if none exists
# (a plain `wp cron event schedule` would error on PUC's already-scheduled hook).
PUC_CRON="$PUC_CRON" wp eval '
$hook = getenv( "PUC_CRON" );
if ( ! wp_next_scheduled( $hook ) ) {
    wp_schedule_event( time() + HOUR_IN_SECONDS, "daily", $hook );
}
if ( ! wp_next_scheduled( $hook ) ) {
    fwrite( STDERR, "could not seed PUC cron " . $hook . "\n" );
    exit( 1 );
}
'
if ! wp cron event list --fields=hook --format=csv 2>/dev/null | grep -qx "$PUC_CRON"; then
  echo "FAIL: could not seed PUC cron ${PUC_CRON} for the uninstall check" >&2
  exit 1
fi

echo "==> Delete the plugin (runs uninstall.php)"
wp plugin uninstall "$PLUGIN_SLUG" --deactivate

if wp option get "$PUC_OPTION" >/dev/null 2>&1; then
  echo "FAIL: uninstall left PUC state option ${PUC_OPTION} behind" >&2
  exit 1
fi
if wp cron event list --fields=hook --format=csv 2>/dev/null | grep -qx "$PUC_CRON"; then
  echo "FAIL: uninstall left PUC cron ${PUC_CRON} scheduled" >&2
  exit 1
fi
echo "    uninstall left no PUC option or cron behind"

echo "FORCE2FA_UPDATE_E2E_OK"
