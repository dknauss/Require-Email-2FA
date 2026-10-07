<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The primary-provider policy: force_2fa_first_real_2fa_method() and the
 * 'two_factor_primary_provider_for_user' filter (force_2fa_filter_primary_provider).
 *
 * Rule: a real method (not Email, not Backup Codes) always outranks the appended email
 * floor for "primary". Email becomes primary only when the user has no real method
 * (no method, or backup-codes-only). Stored primary meta is never mutated.
 */
final class PrimaryProviderTest extends TestCase {

	// --- force_2fa_first_real_2fa_method() ------------------------------------

	public function test_first_real_method_ignores_email_and_backup_codes(): void {
		$user = $this->user( 1, 'u', array( 'editor' ) );
		$this->availableProviders( 1, array( 'Two_Factor_Email', 'Two_Factor_Backup_Codes', 'Two_Factor_Totp' ) );
		$this->assertSame( 'Two_Factor_Totp', force_2fa_first_real_2fa_method( $user ) );
	}

	public function test_no_real_method_when_only_email_and_backup_codes(): void {
		$user = $this->user( 2, 'u', array( 'editor' ) );
		$this->availableProviders( 2, array( 'Two_Factor_Backup_Codes', 'Two_Factor_Email' ) );
		$this->assertNull( force_2fa_first_real_2fa_method( $user ) );
	}

	public function test_dummy_provider_is_not_a_real_method(): void {
		// Two_Factor_Dummy always validates, so it must never count as a real method or
		// the email challenge could be bypassed by making Dummy primary.
		$user = $this->user( 20, 'dbg', array( 'editor' ) );
		$this->availableProviders( 20, array( 'Two_Factor_Dummy', 'Two_Factor_Email' ) );
		$this->assertNull( force_2fa_first_real_2fa_method( $user ) );
	}

	public function test_dummy_does_not_become_primary_over_email(): void {
		$this->user( 21, 'dbg', array( 'editor' ) );
		$this->availableProviders( 21, array( 'Two_Factor_Dummy', 'Two_Factor_Email' ) );
		$this->assertSame(
			'Two_Factor_Email',
			force_2fa_filter_primary_provider( 'Two_Factor_Dummy', 21 )
		);
	}

	// --- the filter -----------------------------------------------------------

	public function test_backup_codes_only_makes_email_primary(): void {
		$this->user( 3, 'bc', array( 'editor' ) );
		$this->availableProviders( 3, array( 'Two_Factor_Backup_Codes', 'Two_Factor_Email' ) );
		$this->assertSame(
			'Two_Factor_Email',
			force_2fa_filter_primary_provider( 'Two_Factor_Backup_Codes', 3 )
		);
	}

	public function test_no_method_makes_email_primary(): void {
		$this->user( 4, 'nobody', array( 'subscriber' ) );
		$this->availableProviders( 4, array( 'Two_Factor_Email' ) );
		$this->assertSame(
			'Two_Factor_Email',
			force_2fa_filter_primary_provider( 'Two_Factor_Email', 4 )
		);
	}

	public function test_totp_already_primary_is_kept(): void {
		$this->user( 5, 'totp', array( 'editor' ) );
		$this->availableProviders( 5, array( 'Two_Factor_Totp', 'Two_Factor_Email' ) );
		$this->assertSame(
			'Two_Factor_Totp',
			force_2fa_filter_primary_provider( 'Two_Factor_Totp', 5 )
		);
	}

	public function test_email_floor_does_not_demote_an_available_totp(): void {
		// The edge: Two Factor resolved Email as primary (it sorts first) but TOTP is
		// available with no stored selection — TOTP must reclaim primary.
		$this->user( 6, 'totp', array( 'editor' ) );
		$this->availableProviders( 6, array( 'Two_Factor_Totp', 'Two_Factor_Email' ) );
		$this->assertSame(
			'Two_Factor_Totp',
			force_2fa_filter_primary_provider( 'Two_Factor_Email', 6 )
		);
	}

	public function test_exempt_user_primary_is_untouched(): void {
		// External-2FA (or out-of-scope) user: the plugin isn't supplying Email, so leave
		// whatever Two Factor resolved alone.
		$this->wordfence2fa( 7 );
		$this->user( 7, 'wf', array( 'editor' ) );
		$this->availableProviders( 7, array( 'Two_Factor_Backup_Codes', 'Two_Factor_Email' ) );
		$this->assertSame(
			'Two_Factor_Backup_Codes',
			force_2fa_filter_primary_provider( 'Two_Factor_Backup_Codes', 7 )
		);
	}

	public function test_dependency_absent_primary_is_untouched(): void {
		// No usable Two Factor (Email provider unregistered) → no-op.
		$GLOBALS['__force2fa_providers'] = array( 'Two_Factor_Totp' => new \stdClass() );
		$this->user( 8, 'u', array( 'editor' ) );
		$this->assertSame(
			'Two_Factor_Backup_Codes',
			force_2fa_filter_primary_provider( 'Two_Factor_Backup_Codes', 8 )
		);
	}

	public function test_primary_filter_is_registered(): void {
		$GLOBALS['__force2fa_added_filters'] = array();
		force_2fa_register_hooks();
		$this->assertContains(
			array( 'two_factor_primary_provider_for_user', 'force_2fa_filter_primary_provider', 10, 2 ),
			$GLOBALS['__force2fa_added_filters']
		);
	}
}
