<?php
/**
 * Zero-dependency test bootstrap.
 *
 * Defines just enough of the WordPress surface for the plugin's pure decision
 * logic to run under PHPUnit — no WP install, no Brain Monkey/Patchwork (which
 * are fragile on bleeding-edge PHP). Stub behaviour is driven by globals that
 * each test sets via Force2FA\TestCase helpers and resets in setUp().
 */

define( 'ABSPATH', __DIR__ . '/' ); // satisfies the plugin's `defined('ABSPATH') || exit`.

$GLOBALS['__force2fa_filters']        = array(); // hook => override return value
$GLOBALS['__force2fa_users']          = array(); // id => WP_User
$GLOBALS['__force2fa_did_action']     = array(); // hook => count
$GLOBALS['__force2fa_added_filters']  = array(); // [ tag, cb, priority, accepted_args ]
$GLOBALS['__force2fa_added_actions']  = array(); // [ tag, cb, priority, accepted_args ]

// --- WordPress function stubs -------------------------------------------------

// We call the plugin's named callbacks directly; add_filter just records the
// registration so force_2fa_register_hooks() can be asserted.
function add_filter( $tag = '', $cb = null, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__force2fa_added_filters'][] = array( $tag, $cb, $priority, $accepted_args );
	return true;
}

// Mirrors add_filter: records the registration so force_2fa_register_hooks() can
// be asserted. The admin callbacks themselves are glue (notice HTML, plugin
// installer) and are exercised by the Playground integration test, not here.
function add_action( $tag = '', $cb = null, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__force2fa_added_actions'][] = array( $tag, $cb, $priority, $accepted_args );
	return true;
}

// The activation guard is registered at load; the callback (which calls
// is_multisite()/wp_die()) only runs on a real activation, never in unit tests.
// Its pure decision is covered directly via force_2fa_activation_blocked().
function register_activation_hook( $file, $cb ) {
	return true;
}

/**
 * Returns a per-hook override when a test set one, otherwise passes the value
 * through unchanged (WordPress's default behaviour with no filters attached).
 */
function apply_filters( $hook, $value = null ) {
	return array_key_exists( $hook, $GLOBALS['__force2fa_filters'] )
		? $GLOBALS['__force2fa_filters'][ $hook ]
		: $value;
}

function get_userdata( $user_id ) {
	return $GLOBALS['__force2fa_users'][ $user_id ] ?? false;
}

function did_action( $hook ) {
	return $GLOBALS['__force2fa_did_action'][ $hook ] ?? 0;
}

// i18n: return the string unchanged. Enough for the pure functions that build
// translatable labels (e.g. the Site Health test registration).
function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress core stub.
	return $text;
}

// Script-enqueue stubs for the AJAX-delete notice-refresh glue: record what the
// plugin localizes/injects so a test can assert the wording it hands the browser.
function wp_localize_script( $handle, $object_name, $l10n ) {
	$GLOBALS['__force2fa_localized'][ $object_name ] = $l10n;
	return true;
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['__force2fa_inline_scripts'][] = $data;
	return true;
}

// Whether the current request is a Network Admin screen. Driven by a global so a
// test can render the refresh script in either the network or single-site context.
function is_network_admin() {
	return ! empty( $GLOBALS['__force2fa_is_network_admin'] );
}

// Whether this is a multisite network. Driven by a global so a test can exercise
// both the single-site and multisite branches (e.g. uninstall.php).
function is_multisite() {
	return ! empty( $GLOBALS['__force2fa_is_multisite'] );
}

// Delete a network/site option. Records the keys deleted so the uninstall test can
// assert exactly which options were purged, without a real WordPress options store.
function delete_site_option( $option ) {
	$GLOBALS['__force2fa_deleted_site_options'][] = $option;
	return true;
}

// Clear all scheduled occurrences of a cron hook. Records the hooks cleared (with
// the current blog id, so the multisite per-site loop is observable) for the
// uninstall test.
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['__force2fa_cleared_crons'][] = array(
		'hook'    => $hook,
		'blog_id' => $GLOBALS['__force2fa_current_blog_id'] ?? 1,
	);
}

// Coerce a value to a non-negative integer (WordPress core helper).
function absint( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress core stub.
	return abs( (int) $value );
}

// Return the network's site IDs. Driven by a global so the multisite uninstall
// path can be pointed at a specific set of sites.
function get_sites( $args = array() ) {
	return $GLOBALS['__force2fa_sites'] ?? array();
}

// Switch the "current" blog. Records the active blog id so wp_clear_scheduled_hook()
// above attributes each cleared cron to the right site.
function switch_to_blog( $blog_id ) {
	$GLOBALS['__force2fa_current_blog_id'] = (int) $blog_id;
	return true;
}

// Restore the "current" blog to the network default (blog 1 in these stubs).
function restore_current_blog() {
	$GLOBALS['__force2fa_current_blog_id'] = 1;
	return true;
}

// Current-user capability check. Defaults to a fully-capable admin (every cap); a
// test can set $GLOBALS['__force2fa_user_caps'] to the exact caps the user holds
// to exercise a capability-limited role (e.g. can activate but not install).
function current_user_can( $cap ) {
	if ( isset( $GLOBALS['__force2fa_user_caps'] ) ) {
		return in_array( $cap, $GLOBALS['__force2fa_user_caps'], true );
	}
	return true;
}

// --- Notice / install-handler glue stubs --------------------------------------
// Enough of the WordPress admin surface for the dependency-notice renderers and
// the one-click install handler to run under output buffering, so the branch that
// *selects* which notice/label/body to emit is unit-tested (the pure decisions it
// delegates to are tested separately). Escapers pass through unchanged; URL helpers
// return deterministic strings so a test can assert the nonce action and target.

// Plugins directory. Points at a per-run temp dir the tests populate, so the
// on-disk "is Two Factor installed?" check (file_exists) is controllable. The
// TestCase creates/removes two-factor/two-factor.php under here to toggle state.
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	// Per-process directory (getmypid) so the default and no-two-factor PHPUnit
	// configs — which run as separate processes and each toggle two-factor.php on
	// disk — can never collide if CI ever runs them concurrently.
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/force2fa-test-plugins-' . getmypid() );
}

function esc_html( $text ) {
	return $text;
}
function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress core stub.
	return $text;
}
function esc_attr( $text ) {
	return $text;
}
function esc_url( $url ) {
	return $url;
}
function admin_url( $path = '' ) {
	return 'http://example.test/wp-admin/' . $path;
}
function network_admin_url( $path = '' ) {
	return 'http://example.test/wp-admin/network/' . $path;
}
function wp_nonce_url( $url, $action = -1 ) {
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . '_wpnonce=' . rawurlencode( (string) $action );
}
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

// is_plugin_active_for_network(): driven by a global so the network notices can be
// pointed at "Two Factor is / isn't network-active". Keyed by plugin file.
function is_plugin_active_for_network( $plugin ) {
	return ! empty( $GLOBALS['__force2fa_network_active'][ $plugin ] );
}

// get_option(): only 'active_plugins' matters here (per-site active list). Driven
// by a global; defaults to the passed default.
function get_option( $name, $default_value = false ) {
	if ( array_key_exists( $name, $GLOBALS['__force2fa_options'] ?? array() ) ) {
		return $GLOBALS['__force2fa_options'][ $name ];
	}
	return $default_value;
}

// Nonce verification for the install handler. Driven by a global so a test can
// simulate a bad/missing nonce; defaults to valid.
function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	if ( isset( $GLOBALS['__force2fa_nonce_ok'] ) && ! $GLOBALS['__force2fa_nonce_ok'] ) {
		throw new \Force2FA_WpDieException( 'nonce check failed' );
	}
	return true;
}

// wp_die(): the plugin's hard stop. Throw so a test can assert the handler bailed
// (e.g. the capability wall) and capture the message, instead of killing PHP.
function wp_die( $message = '', $title = '', $args = array() ) {
	throw new \Force2FA_WpDieException( is_scalar( $message ) ? (string) $message : '' );
}

/** Thrown by the wp_die()/check_admin_referer() stubs so tests can assert a hard stop. */
class Force2FA_WpDieException extends \RuntimeException {}

// --- WordPress class stubs ----------------------------------------------------

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID;
		public $user_login;
		public $roles;

		public function __construct( $id = 0, $user_login = '', array $roles = array() ) {
			$this->ID         = $id;
			$this->user_login = $user_login;
			$this->roles      = $roles;
		}
	}
}

// Present by default so the enforcement filter takes its append path. The
// class-absent guard in force_2fa_dependency_met() is exercised by the separate
// bootstrap-no-two-factor.php run, which defines FORCE2FA_TEST_NO_TWO_FACTOR to
// suppress both Two Factor stubs and simulate the plugin being inactive/removed.
if ( ! defined( 'FORCE2FA_TEST_NO_TWO_FACTOR' ) && ! class_exists( 'Two_Factor_Email' ) ) {
	class Two_Factor_Email {}
}

// Minimal stand-in for the Two Factor plugin's core, mirroring the real contract
// the integration tests depend on: enabled providers are produced by the
// 'two_factor_enabled_providers_for_user' filter (which our plugin hooks), the
// primary provider is the first enabled one, and a user "uses 2FA" when a primary
// provider exists. We feed providers through the plugin's own filter callback so
// the test exercises real enforcement behaviour, not a reimplementation of it.
if ( ! defined( 'FORCE2FA_TEST_NO_TWO_FACTOR' ) && ! class_exists( 'Two_Factor_Core' ) ) {
	class Two_Factor_Core {
		/**
		 * Registered providers, keyed by class name. Defaults to Email present (the
		 * normal state); a test can set $GLOBALS['__force2fa_providers'] to simulate
		 * another plugin unregistering Email.
		 */
		public static function get_providers() {
			return $GLOBALS['__force2fa_providers'] ?? array( 'Two_Factor_Email' => new Two_Factor_Email() );
		}

		public static function get_enabled_providers_for_user( $user_id, array $stored = array() ) {
			return force_2fa_filter_enabled_providers( $stored, $user_id );
		}

		public static function get_primary_provider_for_user( $user_id, array $stored = array() ) {
			$providers = self::get_enabled_providers_for_user( $user_id, $stored );
			return $providers ? reset( $providers ) : null;
		}

		public static function is_user_using_two_factor( $user_id ) {
			return ! empty( self::get_primary_provider_for_user( $user_id ) );
		}
	}
}

// --- Load the plugin under test ----------------------------------------------

require dirname( __DIR__ ) . '/force-email-two-factor.php';

require __DIR__ . '/TestCase.php';
