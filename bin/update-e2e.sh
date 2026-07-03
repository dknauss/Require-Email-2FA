#!/usr/bin/env bash
#
# Real WordPress end-to-end check for the GitHub Releases update facility.
#
# Uses a disposable SQLite-backed WordPress install. It installs the plugin from
# the current working tree (copied, no .git), rewrites only the installed copy's
# Version / FORCE_2FA_LOADED marker to simulate an older release, forces the
# WordPress update check, and asserts that Plugin Update Checker finds and
# installs the latest GitHub Release asset.
#
# Usage: bin/update-e2e.sh
# Optional env:
#   WP_VERSION=7.0                         WordPress version to download (default: latest)
#   FORCE2FA_FAKE_VERSION=0.0.1            Version written into the disposable install
#   FORCE2FA_UPDATE_E2E_KEEP=1             Keep the temp WordPress directory for inspection
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SLUG="force-email-two-factor"
PLUGIN_MAIN="${PLUGIN_SLUG}.php"
FAKE_VERSION="${FORCE2FA_FAKE_VERSION:-0.0.1}"
WORK="$(mktemp -d)"
WP="$WORK/wp"

cleanup() {
  if [ "${FORCE2FA_UPDATE_E2E_KEEP:-0}" = "1" ]; then
    echo "==> Keeping temp WordPress at: $WP"
  else
    rm -rf "$WORK"
  fi
}
trap cleanup EXIT

mkdir -p "$WP"

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
  if [ -n "${GITHUB_TOKEN:-}" ]; then
    curl -fsSL \
      -H "Accept: application/vnd.github+json" \
      -H "Authorization: Bearer ${GITHUB_TOKEN}" \
      -H "X-GitHub-Api-Version: 2022-11-28" \
      "$@"
  else
    curl -fsSL "$@"
  fi
}

RELEASE_JSON="$(github_curl "https://api.github.com/repos/${OWNER_REPO}/releases/latest")"
LATEST_TAG="$(printf '%s' "$RELEASE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); if (!is_array($j) || empty($j["tag_name"])) { exit(1); } echo $j["tag_name"];')"
LATEST_VERSION="${LATEST_TAG#v}"
ASSET_URL="$(printf '%s' "$RELEASE_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN), true); foreach (($j["assets"] ?? array()) as $a) { if (($a["name"] ?? "") === "force-email-two-factor.zip") { echo $a["browser_download_url"] ?? ""; exit(0); } } exit(1);')"

if [ -z "$ASSET_URL" ]; then
  echo "FAIL: latest release ${LATEST_TAG} has no force-email-two-factor.zip asset" >&2
  exit 1
fi

echo "==> Update source: $UPDATE_URI"
echo "==> Latest release: $LATEST_TAG"
echo "==> Release asset:  $ASSET_URL"

WP_CLI_PHAR="$WORK/wp-cli.phar"
curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WP_CLI_PHAR"

# WP-CLI wrapper: enough memory for extraction/update, E_DEPRECATED silenced
# (WP-CLI on bleeding-edge PHP is noisy), and --path baked in.
wp() { php -d memory_limit=512M -d error_reporting=24575 "$WP_CLI_PHAR" --path="$WP" "$@"; }

echo "==> Download WordPress (${WP_VERSION:-latest})"
wp core download --version="${WP_VERSION:-latest}"

echo "==> SQLite database drop-in (no MySQL server needed)"
curl -fsSL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "$WORK/sdi.zip"
unzip -q "$WORK/sdi.zip" -d "$WP/wp-content/plugins/"
cp "$WP/wp-content/plugins/sqlite-database-integration/db.copy" "$WP/wp-content/db.php"

echo "==> Install WordPress"
wp config create --dbname=wp --dbuser=root --dbpass="" --dbhost=localhost --skip-check --force
wp core install --url=http://localhost --title="Require Email 2FA Update E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install plugin under test from working tree (copied, no .git)"
DEST="$WP/wp-content/plugins/$PLUGIN_SLUG"
mkdir -p "$DEST/vendor/yahnis-elsts"
cp "$PLUGIN_DIR/LICENSE" "$PLUGIN_DIR/README.md" "$PLUGIN_DIR/readme.txt" "$PLUGIN_DIR/$PLUGIN_MAIN" "$PLUGIN_DIR/mu-loader.php" "$DEST/"
cp -R "$PLUGIN_DIR/vendor/yahnis-elsts/plugin-update-checker" "$DEST/vendor/yahnis-elsts/"
rm -rf "$DEST/.git"

if [ ! -f "$DEST/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php" ]; then
  echo "FAIL: Plugin Update Checker was not copied into the disposable plugin install" >&2
  exit 1
fi

echo "==> Simulate an older installed version (${FAKE_VERSION})"
FAKE_VERSION="$FAKE_VERSION" perl -0pi -e 's/(\*[[:space:]]*Version:[[:space:]]*)[^\r\n]+/$1 . $ENV{FAKE_VERSION}/e' "$DEST/$PLUGIN_MAIN"
FAKE_VERSION="$FAKE_VERSION" perl -0pi -e 's/define\( '\''FORCE_2FA_LOADED'\'', '\''[^'\'']+'\'' \);/"define( '\''FORCE_2FA_LOADED'\'', '\''" . $ENV{FAKE_VERSION} . "'\'' );"/e' "$DEST/$PLUGIN_MAIN"

wp plugin activate "$PLUGIN_SLUG"
INSTALLED_VERSION="$(wp plugin get "$PLUGIN_SLUG" --field=version)"
if [ "$INSTALLED_VERSION" != "$FAKE_VERSION" ]; then
  echo "FAIL: expected fake installed version ${FAKE_VERSION}, got ${INSTALLED_VERSION}" >&2
  exit 1
fi

echo "==> Force updater check"
UPDATE_JSON="$(wp eval '
$checker = null;
global $wp_filter;
$hook = $wp_filter["site_transient_update_plugins"] ?? null;
$callbacks = is_object( $hook ) && isset( $hook->callbacks ) && is_array( $hook->callbacks ) ? $hook->callbacks : array();
foreach ( $callbacks as $priority_callbacks ) {
    foreach ( $priority_callbacks as $registration ) {
        $callback = $registration["function"] ?? null;
        if ( is_array( $callback ) && is_object( $callback[0] ?? null ) && method_exists( $callback[0], "checkForUpdates" ) ) {
            $checker = $callback[0];
            break 2;
        }
    }
}
if ( ! $checker ) {
    fwrite( STDERR, "FAIL: Plugin Update Checker did not register on site_transient_update_plugins\n" );
    exit( 1 );
}

$r = $checker->checkForUpdates();
if ( ! $r ) {
    fwrite( STDERR, "FAIL: Plugin Update Checker did not find an update\n" );
    fwrite( STDERR, wp_json_encode( $checker->getLastRequestApiErrors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
    exit( 1 );
}

echo wp_json_encode(
    array(
        "new_version" => $r->version ?? null,
        "package"     => $r->download_url ?? null,
        "slug"        => $r->slug ?? null,
    ),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
')"
echo "$UPDATE_JSON"

printf '%s' "$UPDATE_JSON" | grep -q '"new_version": "'"$LATEST_VERSION"'"' || {
  echo "FAIL: update check did not offer latest release version ${LATEST_VERSION}" >&2
  exit 1
}
printf '%s' "$UPDATE_JSON" | grep -q 'github.com/.*/releases/download/'"$LATEST_TAG"'/'"$PLUGIN_SLUG"'\.zip' || {
  echo "FAIL: update package was not the expected GitHub Release asset" >&2
  exit 1
}

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
wp eval '
$checker = null;
global $wp_filter;
$hook = $wp_filter["site_transient_update_plugins"] ?? null;
$callbacks = is_object( $hook ) && isset( $hook->callbacks ) && is_array( $hook->callbacks ) ? $hook->callbacks : array();
foreach ( $callbacks as $priority_callbacks ) {
    foreach ( $priority_callbacks as $registration ) {
        $callback = $registration["function"] ?? null;
        if ( is_array( $callback ) && is_object( $callback[0] ?? null ) && method_exists( $callback[0], "checkForUpdates" ) ) {
            $checker = $callback[0];
            break 2;
        }
    }
}
if ( ! $checker ) {
    fwrite( STDERR, "FAIL: Plugin Update Checker did not register after update\n" );
    exit( 1 );
}

$r = $checker->checkForUpdates();
if ( $r ) {
    fwrite( STDERR, "FAIL: update still offered after applying latest release\n" );
    fwrite( STDERR, wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
    exit( 1 );
}
echo "FORCE2FA_UPDATE_NOOP_OK\n";
'

echo "FORCE2FA_UPDATE_E2E_OK"
