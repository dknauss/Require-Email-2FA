<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * Activation-time enforcement-scope choice: the stored option, its precedence in
 * force_2fa_enforced_capability(), sanitization, lifecycle, and the prompt decision.
 * The notice HTML + admin-post handler are WP-glue (coverage-ignored, E2E-covered).
 */
final class ScopePromptTest extends TestCase {

	// --- force_2fa_should_prompt_scope() truth table --------------------------

	public function test_prompt_when_unset_and_no_constant_and_can_manage(): void {
		$this->assertTrue( force_2fa_should_prompt_scope( false, false, true ) );
	}

	public function test_no_prompt_when_constant_defines_the_scope(): void {
		$this->assertFalse( force_2fa_should_prompt_scope( true, false, true ) );
	}

	public function test_no_prompt_once_a_choice_is_stored(): void {
		$this->assertFalse( force_2fa_should_prompt_scope( false, true, true ) );
	}

	public function test_no_prompt_without_capability(): void {
		$this->assertFalse( force_2fa_should_prompt_scope( false, false, false ) );
	}

	// --- choices + sanitization -----------------------------------------------

	public function test_choices_keys_are_the_capabilities(): void {
		$choices = force_2fa_scope_choices();
		$this->assertSame( array( '', 'edit_posts', 'manage_options' ), array_keys( $choices ) );
		$this->assertSame( 'manage_options', force_2fa_scope_default_choice() );
	}

	public function test_sanitize_accepts_only_known_choices(): void {
		$this->assertSame( '', force_2fa_sanitize_scope_choice( '' ) );
		$this->assertSame( 'edit_posts', force_2fa_sanitize_scope_choice( 'edit_posts' ) );
		$this->assertSame( 'manage_options', force_2fa_sanitize_scope_choice( 'manage_options' ) );
	}

	public function test_sanitize_rejects_arbitrary_or_non_string(): void {
		$this->assertNull( force_2fa_sanitize_scope_choice( 'delete_users' ) );
		$this->assertNull( force_2fa_sanitize_scope_choice( 'edit_posts; drop table' ) );
		$this->assertNull( force_2fa_sanitize_scope_choice( array( 'manage_options' ) ) );
		$this->assertNull( force_2fa_sanitize_scope_choice( null ) );
	}

	// --- storage (single site) ------------------------------------------------

	public function test_unset_choice_reads_as_null(): void {
		$this->assertNull( force_2fa_scope_choice_get() );
	}

	public function test_set_and_get_roundtrip_single_site(): void {
		force_2fa_scope_choice_set( 'manage_options' );
		$this->assertSame( 'manage_options', force_2fa_scope_choice_get() );
	}

	public function test_all_users_choice_is_distinct_from_unset(): void {
		// Choosing "all users" stores '' — a real choice, not "unchecked".
		force_2fa_scope_choice_set( '' );
		$this->assertSame( '', force_2fa_scope_choice_get() );
	}

	public function test_corrupt_stored_value_reads_as_not_chosen(): void {
		update_option( FORCE_2FA_SCOPE_OPTION, 'delete_users' );
		$this->assertNull( force_2fa_scope_choice_get() );
	}

	public function test_clear_removes_the_choice_single_site(): void {
		force_2fa_scope_choice_set( 'edit_posts' );
		force_2fa_clear_scope_choice();
		$this->assertNull( force_2fa_scope_choice_get() );
	}

	// --- storage (multisite: network option) ----------------------------------

	public function test_set_and_get_roundtrip_multisite(): void {
		$this->multisite( true );
		force_2fa_scope_choice_set( 'manage_options' );
		$this->assertSame( 'manage_options', force_2fa_scope_choice_get() );
		// Stored as a NETWORK option, not a per-site option.
		$this->assertArrayHasKey( FORCE_2FA_SCOPE_OPTION, $GLOBALS['__force2fa_site_options'] );
	}

	public function test_clear_removes_the_choice_multisite(): void {
		$this->multisite( true );
		force_2fa_scope_choice_set( 'manage_options' );
		force_2fa_clear_scope_choice();
		$this->assertNull( force_2fa_scope_choice_get() );
	}

	// --- precedence inside force_2fa_enforced_capability() ---------------------

	public function test_stored_choice_drives_the_enforced_capability(): void {
		force_2fa_scope_choice_set( 'manage_options' );
		$this->assertSame( 'manage_options', force_2fa_enforced_capability() );
	}

	public function test_default_is_all_users_when_unset(): void {
		$this->assertSame( '', force_2fa_enforced_capability() );
	}

	public function test_filter_overrides_the_stored_choice(): void {
		force_2fa_scope_choice_set( 'manage_options' );
		$this->setFilter( 'force_2fa_enforced_capability', 'edit_posts' );
		$this->assertSame( 'edit_posts', force_2fa_enforced_capability() );
	}

	public function test_manage_capability_is_network_aware(): void {
		$this->assertSame( 'manage_options', force_2fa_scope_manage_capability() );
		$this->multisite( true );
		$this->assertSame( 'manage_network_options', force_2fa_scope_manage_capability() );
	}

	// --- wiring ---------------------------------------------------------------

	public function test_scope_notice_and_handler_are_registered(): void {
		$GLOBALS['__force2fa_added_actions'] = array();
		force_2fa_register_hooks();

		$this->assertContains(
			array( 'admin_post_force_2fa_set_scope', 'force_2fa_handle_set_scope', 10, 1 ),
			$GLOBALS['__force2fa_added_actions']
		);
		$this->assertContains(
			array( 'admin_notices', 'force_2fa_scope_notice', 10, 1 ),
			$GLOBALS['__force2fa_added_actions']
		);
		$this->assertContains(
			array( 'network_admin_notices', 'force_2fa_scope_notice', 10, 1 ),
			$GLOBALS['__force2fa_added_actions']
		);
	}
}
