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
		$GLOBALS['__force2fa_user_meta']     = array();
		$GLOBALS['__force2fa_did_action']    = array();
		$GLOBALS['__force2fa_added_filters'] = array();
		$GLOBALS['__force2fa_added_actions'] = array();
		$GLOBALS['__force2fa_localized']      = array();
		$GLOBALS['__force2fa_inline_scripts'] = array();
		$GLOBALS['__force2fa_deleted_site_options'] = array();
		$GLOBALS['__force2fa_cleared_crons']        = array();
		$GLOBALS['__force2fa_current_blog_id']      = 1;
		$GLOBALS['__force2fa_options']              = array();
		$GLOBALS['__force2fa_site_options']         = array();
		$GLOBALS['__force2fa_deactivation_hooks']   = array();
		$GLOBALS['__force2fa_network_active']       = array();
		$GLOBALS['__force2fa_super_admins']         = array();
		$GLOBALS['__force2fa_site_caps']            = array();
		$GLOBALS['__force2fa_site_roles']           = array();
		$GLOBALS['__force2fa_user_blogs']           = array();
		$GLOBALS['__force2fa_get_blogs_calls']      = 0;
		$GLOBALS['force_2fa_exempt_cache']          = array();
		$GLOBALS['__force2fa_wordfence_2fa_users']  = array();
		$GLOBALS['__force2fa_available_providers']  = array();
		unset( $GLOBALS['__force2fa_wordfence_throw'] );
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

	/**
	 * Register a WP_User that get_userdata() will return for its ID.
	 *
	 * $caps is a map of capability => true carried by the user for user_can()
	 * checks (the enforcement-scope gate keys off 'manage_options'). When omitted,
	 * the 'administrator' role implies manage_options — see the WP_User stub.
	 */
	protected function user( int $id, string $login, array $roles = array(), array $caps = array() ): \WP_User {
		$user = new \WP_User( $id, $login, $roles, $caps );
		$GLOBALS['__force2fa_users'][ $id ] = $user;
		return $user;
	}

	/** Convenience: register an administrator (holds manage_options). */
	protected function adminUser( int $id, string $login, array $roles = array( 'administrator' ) ): \WP_User {
		return $this->user( $id, $login, $roles, array( 'manage_options' => true ) );
	}

	/** Override the capability that defines the enforcement scope ('' = everyone). */
	protected function enforceCapability( string $capability ): void {
		$this->setFilter( 'force_2fa_enforced_capability', $capability );
	}

	/** Toggle the multisite flag seen by is_multisite(). */
	protected function multisite( bool $on = true ): void {
		$GLOBALS['__force2fa_is_multisite'] = $on;
	}

	/** Mark a user ID as a network super admin (implies multisite). */
	protected function superAdmin( int $id ): void {
		$this->multisite( true );
		$GLOBALS['__force2fa_super_admins'][] = $id;
	}

	/** Set a user's capabilities on a specific site for user_can_for_site(). */
	protected function siteCaps( int $siteId, int $userId, array $caps ): void {
		$GLOBALS['__force2fa_site_caps'][ $siteId ][ $userId ] = $caps;
	}

	/** List the site IDs a user belongs to, for get_blogs_of_user(). */
	protected function userBlogs( int $userId, array $siteIds ): void {
		$GLOBALS['__force2fa_user_blogs'][ $userId ] = $siteIds;
	}

	/** Set a user's roles on a specific site (read when re-hydrated in that context). */
	protected function siteRoles( int $siteId, int $userId, array $roles ): void {
		$GLOBALS['__force2fa_site_roles'][ $siteId ][ $userId ] = $roles;
	}

	/** Set a single-value user meta that get_user_meta( $id, $key, true ) will return. */
	protected function userMeta( int $id, string $key, $value ): void {
		$GLOBALS['__force2fa_user_meta'][ $id ][ $key ] = $value;
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

	/** Mark a user as having active 2FA in the (stubbed) Wordfence integration. */
	protected function wordfence2fa( int $userId ): void {
		$GLOBALS['__force2fa_wordfence_2fa_users'][] = $userId;
	}

	/** Make the stubbed Wordfence integration throw, to exercise the fail-safe path. */
	protected function wordfenceThrows(): void {
		$GLOBALS['__force2fa_wordfence_throw'] = true;
	}

	/** Set the provider class-name keys Two Factor reports as available for a user. */
	protected function availableProviders( int $userId, array $providerKeys ): void {
		$GLOBALS['__force2fa_available_providers'][ $userId ] = $providerKeys;
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
