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
$GLOBALS['__force2fa_user_meta']      = array(); // id => [ key => value ]
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

// Deactivation hook: registered at load for the scope-choice clear. Recorded so a
// test can assert the wiring; the callback runs only on a real deactivation.
function register_deactivation_hook( $file, $cb ) {
	$GLOBALS['__force2fa_deactivation_hooks'][] = $cb;
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

// Per-user meta, driven by a global a test sets via TestCase::userMeta(). Only the
// single-value form ($single = true) the plugin uses is modelled; returns '' when
// unset, mirroring WordPress's default for a missing single meta value.
function get_user_meta( $user_id, $key = '', $single = false ) {
	$value = $GLOBALS['__force2fa_user_meta'][ $user_id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? array() : array( $value ) );
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
// assert exactly which options were purged, and also removes it from the network-option
// store so get_site_option() reflects the deletion (scope-choice lifecycle tests).
function delete_site_option( $option ) {
	$GLOBALS['__force2fa_deleted_site_options'][] = $option;
	unset( $GLOBALS['__force2fa_site_options'][ $option ] );
	return true;
}

// --- Options store stubs (single-site: __force2fa_options; network: __force2fa_site_options) ---

function update_option( $name, $value ) {
	$GLOBALS['__force2fa_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['__force2fa_options'][ $name ] );
	return true;
}

function get_site_option( $name, $default_value = false ) {
	$store = $GLOBALS['__force2fa_site_options'] ?? array();
	return array_key_exists( $name, $store ) ? $store[ $name ] : $default_value;
}

function update_site_option( $name, $value ) {
	$GLOBALS['__force2fa_site_options'][ $name ] = $value;
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

// The current site's blog id (defaults to 1); used by the network-aware scope gate.
function get_current_blog_id() {
	return (int) ( $GLOBALS['__force2fa_current_blog_id'] ?? 1 );
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

// Per-user capability check used by the enforcement-scope gate
// (force_2fa_user_is_exempt). Accepts a WP_User or a user ID and reads the caps
// carried by the WP_User stub (see its constructor / has_cap()).
function user_can( $user, $cap ) {
	if ( ! $user instanceof WP_User ) {
		$user = get_userdata( $user );
	}
	return $user instanceof WP_User ? $user->has_cap( $cap ) : false;
}

// Site-specific capability check (WordPress 6.7+), used by the network-aware scope
// gate. A test can set $GLOBALS['__force2fa_site_caps'][ $site_id ][ $user_id ] to a
// cap => true map to model different capabilities on different subsites; otherwise it
// falls back to the user's own caps (same as user_can()).
function user_can_for_site( $user, $site_id, $cap ) {
	$id  = $user instanceof WP_User ? $user->ID : (int) $user;
	$map = $GLOBALS['__force2fa_site_caps'][ $site_id ][ $id ] ?? null;
	if ( is_array( $map ) ) {
		return ! empty( $map[ $cap ] );
	}
	$resolved = $user instanceof WP_User ? $user : get_userdata( $id );
	return $resolved instanceof WP_User ? $resolved->has_cap( $cap ) : false;
}

// Super-admin check. A test lists super-admin user IDs in
// $GLOBALS['__force2fa_super_admins']; empty/unset means none.
function is_super_admin( $user_id = 0 ) {
	return in_array( (int) $user_id, $GLOBALS['__force2fa_super_admins'] ?? array(), true );
}

// The sites a user belongs to (multisite), used by the network-wide scope check.
// A test lists a user's site IDs in $GLOBALS['__force2fa_user_blogs'][ $user_id ];
// each is returned as an object with a userblog_id property, mirroring core.
function get_blogs_of_user( $user_id ) {
	// Count invocations so a test can assert the memoized exemption avoids re-running
	// the per-site loop (see MemoizationTest).
	$GLOBALS['__force2fa_get_blogs_calls'] = ( $GLOBALS['__force2fa_get_blogs_calls'] ?? 0 ) + 1;

	$blogs = array();
	foreach ( $GLOBALS['__force2fa_user_blogs'][ $user_id ] ?? array() as $site_id ) {
		$blogs[] = (object) array( 'userblog_id' => (int) $site_id );
	}
	return $blogs;
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

// Minimal WP_Error: enough for the API-login gate to signal a denied authenticate
// result and for a test to read the error code back.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();

		public function __construct( $code = '', $message = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][] = $message;
			}
		}

		public function get_error_code() {
			foreach ( $this->errors as $code => $messages ) {
				return $code;
			}
			return '';
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID;
		public $user_login;
		public $roles;
		public $allcaps;

		public function __construct( $id = 0, $user_login = '', array $roles = array(), array $caps = array() ) {
			$this->ID         = $id;
			$this->user_login = $user_login;

			// When re-hydrated by ID alone (as the plugin does inside switch_to_blog()
			// to read another site's roles/caps), pull this blog's roles/caps from the
			// per-site test globals — mirroring WordPress loading per-site capabilities.
			if ( '' === $user_login && empty( $roles ) && empty( $caps ) ) {
				$blog  = $GLOBALS['__force2fa_current_blog_id'] ?? 1;
				$roles = $GLOBALS['__force2fa_site_roles'][ $blog ][ $id ] ?? array();
				$caps  = $GLOBALS['__force2fa_site_caps'][ $blog ][ $id ] ?? array();
			}

			$this->roles = $roles;

			// Faithful-enough default: the 'administrator' role implies
			// manage_options in WordPress's real role→cap map, so derive it when
			// no explicit caps are supplied. Tests wanting a different capability
			// posture pass $caps as a map of cap => true.
			if ( empty( $caps ) && in_array( 'administrator', $roles, true ) ) {
				$caps = array( 'manage_options' => true );
			}

			$this->allcaps = $caps;
		}

		public function has_cap( $cap ) {
			return ! empty( $this->allcaps[ $cap ] );
		}
	}
}

// Wordfence Login Security stand-in for the external-2FA integration. has_2fa_active()
// is driven by $GLOBALS['__force2fa_wordfence_2fa_users'] (a list of user IDs); a test
// sets __force2fa_wordfence_throw to exercise the fail-safe try/catch. Aliased to the
// real namespaced class name the plugin looks up.
if ( ! class_exists( 'WordfenceLS\\Controller_Users' ) ) {
	class Force2FA_Wordfence_Users_Stub {
		public static function shared() {
			static $instance;
			if ( null === $instance ) {
				$instance = new self();
			}
			return $instance;
		}

		public function has_2fa_active( $user ) {
			if ( ! empty( $GLOBALS['__force2fa_wordfence_throw'] ) ) {
				throw new \RuntimeException( 'Simulated Wordfence integration failure.' );
			}
			return in_array( (int) $user->ID, $GLOBALS['__force2fa_wordfence_2fa_users'] ?? array(), true );
		}
	}
	class_alias( 'Force2FA_Wordfence_Users_Stub', 'WordfenceLS\\Controller_Users' );
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

		// Available (configured + usable) providers, keyed by class name. Driven by a
		// global a test sets via TestCase::availableProviders(); used by the primary-
		// provider logic (force_2fa_user_has_real_2fa_method).
		public static function get_available_providers_for_user( $user ) {
			$id  = is_object( $user ) ? (int) $user->ID : (int) $user;
			$out = array();
			foreach ( $GLOBALS['__force2fa_available_providers'][ $id ] ?? array() as $key ) {
				$out[ $key ] = new \stdClass();
			}
			return $out;
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
