#!/usr/bin/env bash
#
# Real-WordPress end-to-end check for the activation-time enforcement-scope choice.
#
# Proves the stateful lifecycle against a real single-site WordPress + real Two Factor:
#   - with no choice stored, the SECURE default applies (all users enforced),
#   - storing a choice (as the first-run prompt handler does) narrows enforcement — a
#     subscriber becomes exempt under admins-only while an administrator stays enforced,
#   - a FORCE_2FA_ENFORCED_CAPABILITY constant OVERRIDES the stored choice and suppresses
#     the prompt (infra-as-code wins), and
#   - DEACTIVATING the plugin clears the stored choice (the register_deactivation_hook),
#     so reactivating re-prompts — the intended "start over" path.
#
# Uses the SQLite drop-in (no MySQL). Usage: bin/scope-activation-e2e.sh
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d 2>/dev/null || echo "${TMPDIR:-/tmp}/force2fa-scope-e2e-$$")"
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
wp core install --url=http://localhost --title="Scope E2E" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email

echo "==> Install + activate the REAL Two Factor plugin"
wp plugin install two-factor --activate

echo "==> Install + activate the plugin under test (copied — a symlink breaks plugin_basename)"
mkdir -p "$WP/wp-content/plugins/force-email-two-factor"
cp "$PLUGIN_DIR/force-email-two-factor.php" "$WP/wp-content/plugins/force-email-two-factor/"
cp "$PLUGIN_DIR/uninstall.php" "$WP/wp-content/plugins/force-email-two-factor/"
wp plugin activate force-email-two-factor

echo "==> Create a subscriber (out of scope once we narrow to admins-only)"
wp user create sub sub@example.com --role=subscriber --user_pass=x >/dev/null

echo "==> Default: no choice stored → all users enforced, prompt would show"
wp eval '
$sub = get_user_by( "login", "sub" );
$cap = force_2fa_enforced_capability();
$prompt = force_2fa_should_prompt_scope( defined( "FORCE_2FA_ENFORCED_CAPABILITY" ), null !== force_2fa_scope_choice_get(), true );
$sub_enforced = Two_Factor_Core::is_user_using_two_factor( $sub->ID );
if ( "" === $cap && $prompt && $sub_enforced ) { echo "FORCE2FA_DEFAULT_OK\n"; exit( 0 ); }
fwrite( STDERR, sprintf( "FAIL default: cap=%s prompt=%d sub_enforced=%d\n", var_export( $cap, true ), $prompt, $sub_enforced ) );
exit( 1 );
'

echo "==> Store the admins-only choice (as the prompt handler does) → subscriber exempt"
wp eval '
force_2fa_scope_choice_set( "manage_options" );
$sub   = get_user_by( "login", "sub" );
$admin = get_user_by( "login", "admin" );
$cap    = force_2fa_enforced_capability();
$prompt = force_2fa_should_prompt_scope( defined( "FORCE_2FA_ENFORCED_CAPABILITY" ), null !== force_2fa_scope_choice_get(), true );
$sub_enforced   = Two_Factor_Core::is_user_using_two_factor( $sub->ID );
$admin_enforced = Two_Factor_Core::is_user_using_two_factor( $admin->ID );
if ( "manage_options" === $cap && ! $prompt && ! $sub_enforced && $admin_enforced ) { echo "FORCE2FA_SCOPED_OK\n"; exit( 0 ); }
fwrite( STDERR, sprintf( "FAIL scoped: cap=%s prompt=%d sub_enforced=%d admin_enforced=%d\n", var_export( $cap, true ), $prompt, $sub_enforced, $admin_enforced ) );
exit( 1 );
'

echo "==> Constant overrides the stored choice and suppresses the prompt"
wp config set FORCE_2FA_ENFORCED_CAPABILITY '' --type=constant
wp eval '
$cap    = force_2fa_enforced_capability();
$prompt = force_2fa_should_prompt_scope( defined( "FORCE_2FA_ENFORCED_CAPABILITY" ), null !== force_2fa_scope_choice_get(), true );
// Constant "" wins over the stored manage_options → all users, and no prompt.
if ( "" === $cap && ! $prompt ) { echo "FORCE2FA_CONSTANT_WINS_OK\n"; exit( 0 ); }
fwrite( STDERR, sprintf( "FAIL constant: cap=%s prompt=%d\n", var_export( $cap, true ), $prompt ) );
exit( 1 );
'
wp config delete FORCE_2FA_ENFORCED_CAPABILITY --type=constant

echo "==> Deactivate clears the stored choice (redo path)"
# The choice is still stored (manage_options). Deactivation must remove it.
stored_before="$(wp option get force_2fa_enforced_capability --format=json 2>/dev/null || echo MISSING)"
wp plugin deactivate force-email-two-factor
stored_after="$(wp option get force_2fa_enforced_capability --format=json 2>/dev/null || echo MISSING)"
echo "    stored before=${stored_before} after=${stored_after}"
if [ "$stored_before" = "MISSING" ] || [ "$stored_after" != "MISSING" ]; then
  echo "FAIL: deactivation did not clear the stored scope choice" >&2
  exit 1
fi
echo "    cleared on deactivation, as expected"

echo "==> Scope-activation E2E passed."
