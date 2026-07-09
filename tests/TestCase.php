<?php

namespace Force2FA;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: resets the stub-driving globals before every test and offers
 * small helpers for arranging users, config overrides, and the app-password marker.
 */
abstract class TestCase extends BaseTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__force2fa_filters']       = array();
		$GLOBALS['__force2fa_users']         = array();
		$GLOBALS['__force2fa_did_action']    = array();
		$GLOBALS['__force2fa_added_filters'] = array();
		$GLOBALS['__force2fa_added_actions'] = array();
		$GLOBALS['__force2fa_localized']      = array();
		$GLOBALS['__force2fa_inline_scripts'] = array();
		$GLOBALS['__force2fa_deleted_site_options'] = array();
		$GLOBALS['__force2fa_cleared_crons']        = array();
		$GLOBALS['__force2fa_current_blog_id']      = 1;
		$GLOBALS['__force2fa_options']              = array();
		$GLOBALS['__force2fa_network_active']       = array();
		unset( $GLOBALS['force_2fa_app_password_user_id'], $GLOBALS['__force2fa_providers'], $GLOBALS['__force2fa_is_network_admin'], $GLOBALS['__force2fa_user_caps'], $GLOBALS['__force2fa_is_multisite'], $GLOBALS['__force2fa_sites'], $GLOBALS['__force2fa_nonce_ok'] );

		// Start every test from "Two Factor not on disk" so the on-disk check is
		// deterministic; installTwoFactorFile() opts a test into the installed state.
		$this->removeTwoFactorFile();
	}

	protected function tearDown(): void {
		$this->removeTwoFactorFile();
		parent::tearDown();
	}

	/** Absolute path to the fake Two Factor main file under the test plugins dir. */
	private function twoFactorFile(): string {
		return WP_PLUGIN_DIR . '/' . FORCE_2FA_TWO_FACTOR_PLUGIN_FILE;
	}

	/** Create the fake two-factor/two-factor.php so file_exists() sees it installed. */
	protected function installTwoFactorFile(): void {
		$file = $this->twoFactorFile();
		if ( ! is_dir( dirname( $file ) ) ) {
			mkdir( dirname( $file ), 0777, true );
		}
		file_put_contents( $file, "<?php\n" );
	}

	/** Remove the fake Two Factor file so file_exists() sees it absent. */
	protected function removeTwoFactorFile(): void {
		$file = $this->twoFactorFile();
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}

	/** Set the current user's exact capabilities (defaults to all-caps otherwise). */
	protected function userCaps( array $caps ): void {
		$GLOBALS['__force2fa_user_caps'] = $caps;
	}

	/** Render a notice callback and return its echoed HTML. */
	protected function renderNotice( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/** Register a WP_User that get_userdata() will return for its ID. */
	protected function user( int $id, string $login, array $roles = array() ): \WP_User {
		$user = new \WP_User( $id, $login, $roles );
		$GLOBALS['__force2fa_users'][ $id ] = $user;
		return $user;
	}

	/** Override a filter's return value (what apply_filters() yields for $hook). */
	protected function setFilter( string $hook, $value ): void {
		$GLOBALS['__force2fa_filters'][ $hook ] = $value;
	}

	/** Set the excluded-roles list seen by the plugin. */
	protected function excludeRoles( array $roles ): void {
		$this->setFilter( 'force_2fa_excluded_roles', $roles );
	}

	/** Set the API-login allowlist seen by the plugin. */
	protected function allowlist( array $entries ): void {
		$this->setFilter( 'force_2fa_api_login_allowlist', $entries );
	}

	/**
	 * Simulate an Application Password authentication for a specific user (or none).
	 *
	 * Mirrors force_2fa_note_app_password_user() recording the account that core
	 * authenticated. Pass the authenticating user's ID; $used = false clears it.
	 */
	protected function appPasswordUsed( bool $used = true, int $user_id = 0 ): void {
		if ( $used ) {
			$GLOBALS['force_2fa_app_password_user_id'] = $user_id;
		} else {
			unset( $GLOBALS['force_2fa_app_password_user_id'] );
		}
	}
}
