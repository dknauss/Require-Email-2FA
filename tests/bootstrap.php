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

// Present by default so the enforcement filter takes its append path; the
// class-absent guard is exercised manually rather than in unit tests.
if ( ! class_exists( 'Two_Factor_Email' ) ) {
	class Two_Factor_Email {}
}

// Minimal stand-in for the Two Factor plugin's core, mirroring the real contract
// the integration tests depend on: enabled providers are produced by the
// 'two_factor_enabled_providers_for_user' filter (which our plugin hooks), the
// primary provider is the first enabled one, and a user "uses 2FA" when a primary
// provider exists. We feed providers through the plugin's own filter callback so
// the test exercises real enforcement behaviour, not a reimplementation of it.
if ( ! class_exists( 'Two_Factor_Core' ) ) {
	class Two_Factor_Core {
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
