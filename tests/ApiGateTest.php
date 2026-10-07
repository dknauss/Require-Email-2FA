<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The scope-independent XML-RPC gate: force_2fa_api_login_should_deny() (pure) and
 * force_2fa_gate_api_login() (glue on the 'authenticate' filter).
 *
 * The gate applies the allowlist + Application-Password policy to EVERY XML-RPC login,
 * regardless of the interactive-2FA enforcement scope — that decoupling is the point.
 * An explicitly excluded account (role denylist or the exempt filter) is the one that
 * passes without the allowlist; being merely out of scope is not an exclusion.
 * The XML-RPC deny path (which reads the XMLRPC_REQUEST constant) is proven end-to-end
 * in bin/api-login-e2e.sh; here the pure truth table carries the logic and the glue is
 * checked for its "never touch a non-XML-RPC login" guarantee.
 */
final class ApiGateTest extends TestCase {

	// --- force_2fa_api_login_should_deny() truth table ------------------------

	public function test_explicitly_excluded_account_is_never_denied(): void {
		// An operator exclusion opts the account out of this plugin's API policy, so it
		// passes with neither allowlist membership nor an Application Password.
		$this->assertFalse( force_2fa_api_login_should_deny( true, true, true, false, false, true ) );
	}

	public function test_exclusion_defaults_to_off(): void {
		// Omitting the sixth argument must keep the strict decision.
		$this->assertTrue( force_2fa_api_login_should_deny( true, true, true, false, false ) );
	}

	// --- force_2fa_user_is_explicitly_excluded() ------------------------------

	public function test_role_excluded_user_is_explicitly_excluded(): void {
		$this->excludeRoles( array( 'subscriber' ) );

		$this->assertTrue( force_2fa_user_is_explicitly_excluded( $this->user( 21, 'sub', array( 'subscriber' ) ) ) );
	}

	public function test_out_of_scope_user_is_not_explicitly_excluded(): void {
		// Admins-only scope: an editor is exempt from forced 2FA, but nobody excluded
		// them, so the XML-RPC gate must still hold them to the allowlist.
		$this->enforceCapability( 'manage_options' );
		$editor = $this->user( 22, 'ed', array( 'editor' ) );

		$this->assertTrue( force_2fa_user_is_exempt( $editor ) );
		$this->assertFalse( force_2fa_user_is_explicitly_excluded( $editor ) );
	}

	public function test_out_of_scope_user_in_an_excluded_role_is_explicitly_excluded(): void {
		// The exclusion is explicit whether or not the account is also out of scope.
		$this->enforceCapability( 'manage_options' );
		$this->excludeRoles( array( 'subscriber' ) );

		$this->assertTrue( force_2fa_user_is_explicitly_excluded( $this->user( 23, 'sub2', array( 'subscriber' ) ) ) );
	}

	public function test_user_with_an_excluded_and_a_kept_role_is_not_excluded(): void {
		$this->excludeRoles( array( 'subscriber' ) );

		$this->assertFalse( force_2fa_user_is_explicitly_excluded( $this->user( 24, 'both', array( 'subscriber', 'editor' ) ) ) );
	}

	public function test_non_xmlrpc_request_never_denies(): void {
		// Interactive login (not XML-RPC): must pass through even for a nobody.
		$this->assertFalse( force_2fa_api_login_should_deny( false, true, true, false, false ) );
	}

	public function test_soft_dependency_two_factor_absent_never_denies(): void {
		$this->assertFalse( force_2fa_api_login_should_deny( true, false, true, false, false ) );
	}

	public function test_no_authenticated_user_passes_through(): void {
		// Core returned an error/anonymous — leave it alone.
		$this->assertFalse( force_2fa_api_login_should_deny( true, true, false, false, false ) );
	}

	public function test_allowlisted_with_app_password_is_allowed(): void {
		$this->assertFalse( force_2fa_api_login_should_deny( true, true, true, true, true ) );
	}

	public function test_allowlisted_without_app_password_is_denied(): void {
		$this->assertTrue( force_2fa_api_login_should_deny( true, true, true, false, true ) );
	}

	public function test_app_password_but_not_allowlisted_is_denied(): void {
		$this->assertTrue( force_2fa_api_login_should_deny( true, true, true, true, false ) );
	}

	public function test_neither_allowlisted_nor_app_password_is_denied(): void {
		$this->assertTrue( force_2fa_api_login_should_deny( true, true, true, false, false ) );
	}

	// --- force_2fa_gate_api_login() glue --------------------------------------

	public function test_interactive_login_is_never_touched(): void {
		// XMLRPC_REQUEST is undefined under PHPUnit, so this exercises the non-XML-RPC
		// path: even a non-allowlisted user with no app password must pass through
		// unchanged (their interactive login goes to the normal 2FA challenge).
		$user = $this->user( 3, 'editoruser', array( 'editor' ) );
		$this->assertSame( $user, force_2fa_gate_api_login( $user ) );
	}

	public function test_allowlisted_user_passes_through_outside_xmlrpc(): void {
		$this->allowlist( array( 5 ) );
		$user = $this->user( 5, 'svc', array( 'author' ) );
		$this->appPasswordUsed( true, 5 );
		$this->assertSame( $user, force_2fa_gate_api_login( $user ) );
	}

	public function test_error_result_is_passed_through(): void {
		$error = new \WP_Error( 'incorrect_password', 'nope' );
		$this->assertSame( $error, force_2fa_gate_api_login( $error ) );
	}

	public function test_null_result_is_passed_through(): void {
		$this->assertNull( force_2fa_gate_api_login( null ) );
	}

	// --- hook wiring ----------------------------------------------------------

	public function test_gate_is_registered_on_authenticate(): void {
		$GLOBALS['__force2fa_added_filters'] = array();
		force_2fa_register_hooks();

		$this->assertContains(
			array( 'authenticate', 'force_2fa_gate_api_login', 90, 3 ),
			$GLOBALS['__force2fa_added_filters']
		);
	}
}
