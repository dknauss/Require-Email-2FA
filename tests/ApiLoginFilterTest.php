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

	public function test_exempt_user_bypasses_the_api_gate(): void {
		// Exclusion means "do not force 2FA," including this plugin's API-login gate.
		// This remains important for an excluded account that has configured its own
		// provider, because Two Factor will then reach this filter for that account.
		$this->excludeRoles( array( 'subscriber' ) );
		$user = $this->user( 4, 'subuser', array( 'subscriber' ) );
		$this->appPasswordUsed( false );
		$this->assertTrue( force_2fa_filter_api_login_enable( false, $user ) );
	}

	public function test_out_of_scope_user_does_not_bypass_the_api_gate(): void {
		// Out of the enforced-capability scope is not an exclusion: an editor under an
		// admins-only scope is exempt from forced 2FA but still needs the allowlist.
		$this->enforceCapability( 'manage_options' );
		$user = $this->user( 6, 'scopededitor', array( 'editor' ) );
		$this->appPasswordUsed( true, 6 );
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

	public function test_user_with_empty_login_is_denied(): void {
		// A resolved user whose user_login is empty (malformed record) hits the
		// `empty( $user->user_login )` guard and is denied before the allowlist or
		// app-password checks — fail-closed. Distinct from test_unknown_user_is_denied,
		// where get_userdata() returns false outright.
		$this->allowlist( array( 5 ) );
		$user = $this->user( 5, '', array( 'author' ) );
		$this->appPasswordUsed( true, 5 );
		$this->assertFalse( force_2fa_filter_api_login_enable( true, $user ) );
	}
}
