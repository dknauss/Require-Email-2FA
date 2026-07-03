#!/usr/bin/env bash
#
# Shared bootstrap for the disposable-WordPress E2E scripts (multisite-e2e.sh,
# update-e2e.sh). Sourced, not executed.
#
# Third-party tooling that these scripts EXECUTE (wp-cli.phar as PHP, the SQLite
# drop-in as WordPress code) is version-pinned and checksum-verified against
# hashes committed here, so a compromised or broken upstream "latest" endpoint
# can neither run arbitrary code in CI nor make failures unreproducible.
#
# Bump the pins deliberately: take the new wp-cli hash from the .sha512 file
# published next to the release phar (github.com/wp-cli/wp-cli/releases); for
# the SQLite plugin, download the versioned zip and hash it yourself.
#
# Caller contract: set WORK (temp dir) and WP (WordPress path) before sourcing,
# then call e2e_fetch_wp_cli / e2e_install_sqlite_dropin and use wp().

E2E_WP_CLI_VERSION="2.12.0"
E2E_WP_CLI_SHA512="be928f6b8ca1e8dfb9d2f4b75a13aa4aee0896f8a9a0a1c45cd5d2c98605e6172e6d014dda2e27f88c98befc16c040cbb2bd1bfa121510ea5cdf5f6a30fe8832"
E2E_SDI_VERSION="2.2.23"
E2E_SDI_SHA256="44be096a14ebcea424b5e4bf764436ec85fb067f74ab47822c4c5346df21591e"

# e2e_verify_checksum <algo-bits> <file> <expected-hex>
e2e_verify_checksum() {
  local algo="$1" file="$2" expected="$3" actual
  if command -v shasum >/dev/null 2>&1; then
    actual="$(shasum -a "$algo" "$file" | cut -d' ' -f1)"
  else
    actual="$("sha${algo}sum" "$file" | cut -d' ' -f1)"
  fi
  if [ "$actual" != "$expected" ]; then
    echo "FAIL: sha${algo} mismatch for ${file}" >&2
    echo "  expected: ${expected}" >&2
    echo "  actual:   ${actual}" >&2
    echo "If the upstream re-released this pinned version, re-verify and update the pin in bin/lib/e2e-common.sh." >&2
    return 1
  fi
}

# Download the pinned WP-CLI release phar and verify it against the committed hash.
e2e_fetch_wp_cli() {
  WP_CLI_PHAR="$WORK/wp-cli.phar"
  curl -fsSL "https://github.com/wp-cli/wp-cli/releases/download/v${E2E_WP_CLI_VERSION}/wp-cli-${E2E_WP_CLI_VERSION}.phar" \
    -o "$WP_CLI_PHAR"
  e2e_verify_checksum 512 "$WP_CLI_PHAR" "$E2E_WP_CLI_SHA512"
}

# WP-CLI wrapper: enough memory for extraction/update, E_DEPRECATED silenced
# (WP-CLI on bleeding-edge PHP is noisy), and --path baked in.
wp() { php -d memory_limit=512M -d error_reporting=24575 "$WP_CLI_PHAR" --path="$WP" "$@"; }

# Install the pinned SQLite database drop-in (no MySQL server needed).
e2e_install_sqlite_dropin() {
  curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.${E2E_SDI_VERSION}.zip" \
    -o "$WORK/sdi.zip"
  e2e_verify_checksum 256 "$WORK/sdi.zip" "$E2E_SDI_SHA256"
  unzip -q "$WORK/sdi.zip" -d "$WP/wp-content/plugins/"
  cp "$WP/wp-content/plugins/sqlite-database-integration/db.copy" "$WP/wp-content/db.php"
}
