<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * Blocking mode: the accessor, the "configured" detection, and the two pure
 * decisions the request gate composes (who needs setup, and which requests may be
 * gated). The request glue itself (redirect/exit) is exercised in a real
 * environment; here we lock down every branch of its decision inputs.
 */
final class BlockingModeTest extends TestCase {

	// --- force_2fa_blocking_mode_enabled() -----------------------------------

	public function test_blocking_mode_disabled_by_default(): void {
		$this->assertFalse( force_2fa_blocking_mode_enabled() );
	}

	public function test_blocking_mode_can_be_enabled_via_filter(): void {
		$this->setFilter( 'force_2fa_blocking_mode_enabled', true );
		$this->assertTrue( force_2fa_blocking_mode_enabled() );
	}

	// --- force_2fa_meta_indicates_configured() -------------------------------

	public function test_unset_meta_is_not_configured(): void {
		$this->assertFalse( force_2fa_meta_indicates_configured( '' ) );
	}

	public function test_empty_array_meta_is_not_configured(): void {
		$this->assertFalse( force_2fa_meta_indicates_configured( array() ) );
	}

	public function test_false_meta_is_not_configured(): void {
		$this->assertFalse( force_2fa_meta_indicates_configured( false ) );
	}

	public function test_scalar_meta_is_not_configured(): void {
		// A stray scalar is not the array Two Factor stores → treat as unconfigured.
		$this->assertFalse( force_2fa_meta_indicates_configured( 'Two_Factor_Totp' ) );
	}

	public function test_nonempty_array_meta_is_configured(): void {
		$this->assertTrue( force_2fa_meta_indicates_configured( array( 'Two_Factor_Totp' ) ) );
	}

	// --- force_2fa_user_has_configured_2fa() ---------------------------------

	public function test_user_without_stored_providers_is_unconfigured(): void {
		$user = $this->user( 3, 'editoruser', array( 'editor' ) );
		// No meta set → only the runtime-forced Email floor, which is not persisted.
		$this->assertFalse( force_2fa_user_has_configured_2fa( $user ) );
	}

	public function test_user_with_stored_providers_is_configured(): void {
		$user = $this->user( 4, 'totpuser', array( 'administrator' ) );
		$this->userMeta( 4, '_two_factor_enabled_providers', array( 'Two_Factor_Totp' ) );
		$this->assertTrue( force_2fa_user_has_configured_2fa( $user ) );
	}

	public function test_user_with_empty_stored_providers_is_unconfigured(): void {
		$user = $this->user( 5, 'emptyuser', array( 'editor' ) );
		$this->userMeta( 5, '_two_factor_enabled_providers', array() );
		$this->assertFalse( force_2fa_user_has_configured_2fa( $user ) );
	}

	// --- force_2fa_should_require_setup() truth table ------------------------

	public function test_should_require_setup_when_all_conditions_met(): void {
		$this->assertTrue( force_2fa_should_require_setup( true, true, true, false, false ) );
	}

	public function test_no_setup_when_blocking_disabled(): void {
		$this->assertFalse( force_2fa_should_require_setup( false, true, true, false, false ) );
	}

	public function test_no_setup_when_dependency_unmet(): void {
		$this->assertFalse( force_2fa_should_require_setup( true, false, true, false, false ) );
	}

	public function test_no_setup_when_not_logged_in(): void {
		$this->assertFalse( force_2fa_should_require_setup( true, true, false, false, false ) );
	}

	public function test_no_setup_when_exempt(): void {
		$this->assertFalse( force_2fa_should_require_setup( true, true, true, true, false ) );
	}

	public function test_no_setup_when_already_configured(): void {
		$this->assertFalse( force_2fa_should_require_setup( true, true, true, false, true ) );
	}

	// --- force_2fa_request_is_gateable() exclusions --------------------------

	public function test_ordinary_page_load_is_gateable(): void {
		$this->assertTrue( force_2fa_request_is_gateable( false, false, false, false, false, false ) );
	}

	public function test_ajax_is_not_gateable(): void {
		$this->assertFalse( force_2fa_request_is_gateable( true, false, false, false, false, false ) );
	}

	public function test_cron_is_not_gateable(): void {
		$this->assertFalse( force_2fa_request_is_gateable( false, true, false, false, false, false ) );
	}

	public function test_rest_is_not_gateable(): void {
		$this->assertFalse( force_2fa_request_is_gateable( false, false, true, false, false, false ) );
	}

	public function test_xmlrpc_is_not_gateable(): void {
		$this->assertFalse( force_2fa_request_is_gateable( false, false, false, true, false, false ) );
	}

	public function test_cli_is_not_gateable(): void {
		$this->assertFalse( force_2fa_request_is_gateable( false, false, false, false, true, false ) );
	}

	public function test_setup_screen_is_not_gateable(): void {
		// The profile/user-edit screen must never be gated, or setup is a dead-end.
		$this->assertFalse( force_2fa_request_is_gateable( false, false, false, false, false, true ) );
	}

	// --- force_2fa_screen_is_own_setup() (the user-edit.php privilege guard) ---

	public function test_profile_php_is_always_own_setup(): void {
		$this->assertTrue( force_2fa_screen_is_own_setup( 'profile.php', 0, 7 ) );
	}

	public function test_user_edit_of_self_is_own_setup(): void {
		$this->assertTrue( force_2fa_screen_is_own_setup( 'user-edit.php', 7, 7 ) );
	}

	public function test_user_edit_of_another_user_is_not_own_setup(): void {
		// Editing someone else must stay gated — this is the P1 fix.
		$this->assertFalse( force_2fa_screen_is_own_setup( 'user-edit.php', 9, 7 ) );
	}

	public function test_user_edit_with_no_current_user_is_not_own_setup(): void {
		$this->assertFalse( force_2fa_screen_is_own_setup( 'user-edit.php', 0, 0 ) );
	}

	public function test_other_admin_page_is_not_own_setup(): void {
		$this->assertFalse( force_2fa_screen_is_own_setup( 'users.php', 7, 7 ) );
	}

	// --- hook wiring ---------------------------------------------------------

	public function test_register_hooks_wires_the_blocking_gate(): void {
		$GLOBALS['__force2fa_added_actions'] = array();
		force_2fa_register_hooks();

		$this->assertContains(
			array( 'admin_init', 'force_2fa_enforce_setup_gate', 10, 1 ),
			$GLOBALS['__force2fa_added_actions']
		);
		$this->assertContains(
			array( 'template_redirect', 'force_2fa_enforce_setup_gate', 10, 1 ),
			$GLOBALS['__force2fa_added_actions']
		);
	}

	public function test_register_hooks_wires_the_mail_from_name_encoder(): void {
		$GLOBALS['__force2fa_added_filters'] = array();
		force_2fa_register_hooks();

		$tags = array_map(
			static function ( $registration ) {
				return $registration[0];
			},
			$GLOBALS['__force2fa_added_filters']
		);

		$this->assertContains( 'wp_mail_from_name', $tags );
	}
}
