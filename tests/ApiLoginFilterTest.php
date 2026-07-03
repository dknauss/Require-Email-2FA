<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The API-login filter callback: force_2fa_filter_api_login_enable().
 *
 * Policy = allowlisted AND authenticated via an Application Password as THIS user.
 */
final class ApiLoginFilterTest extends TestCase {

	public function test_allowlisted_with_app_password_is_allowed(): void {
		$this->allowlist( array( 5 ) );
		$user = $this->user( 5, 'svc', array( 'author' ) );
		$this->appPasswordUsed( true, 5 );
		$this->assertTrue( force_2fa_filter_api_login_enable( false, $user ) );
	}

	public function test_allowlisted_without_app_password_is_denied(): void {
		// Real-password API login: no Application Password authenticated this request.
		$this->allowlist( array( 5 ) );
		$user = $this->user( 5, 'svc', array( 'author' ) );
		$this->appPasswordUsed( false );
		$this->assertFalse( force_2fa_filter_api_login_enable( true, $user ) );
	}

	public function test_app_password_for_a_different_user_is_denied(): void {
		// An Application Password auth happened this request, but for another account.
		// The allowlisted user did NOT present one, so the bypass must not apply.
		$this->allowlist( array( 5 ) );
		$user = $this->user( 5, 'svc', array( 'author' ) );
		$this->appPasswordUsed( true, 7 );
		$this->assertFalse( force_2fa_filter_api_login_enable( false, $user ) );
	}

	public function test_non_allowlisted_with_app_password_is_denied(): void {
		$this->allowlist( array( 5 ) );
		$user = $this->user( 3, 'editoruser', array( 'editor' ) );
		$this->appPasswordUsed( true, 3 );
		$this->assertFalse( force_2fa_filter_api_login_enable( true, $user ) );
	}

	public function test_resolves_user_from_id(): void {
		$this->allowlist( array( 5 ) );
		$this->user( 5, 'svc', array( 'author' ) );
		$this->appPasswordUsed( true, 5 );
		// Pass the ID rather than a WP_User object.
		$this->assertTrue( force_2fa_filter_api_login_enable( false, 5 ) );
	}

	public function test_unknown_user_is_denied(): void {
		$this->allowlist( array( 5 ) );
		$this->appPasswordUsed( true, 999 );
		$this->assertFalse( force_2fa_filter_api_login_enable( true, 999 ) );
	}
}
