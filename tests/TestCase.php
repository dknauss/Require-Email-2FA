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

	/** Simulate (or not) a successful Application Password authentication. */
	protected function appPasswordUsed( bool $used = true ): void {
		$GLOBALS['__force2fa_did_action']['application_password_did_authenticate'] = $used ? 1 : 0;
	}
}
