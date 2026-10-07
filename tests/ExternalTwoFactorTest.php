<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * External-2FA exemption: force_2fa_wordfence_2fa_active() and the resulting skip of the
 * email floor — while the floor is STILL supplied for everyone else (the key difference
 * from the scrapped approach: email stays a floor for TOTP/WebAuthn/no-method users).
 * Wordfence is the only bundled integration; other external systems use the existing
 * 'force_2fa_user_is_exempt' filter, covered by ExemptTest.
 */
final class ExternalTwoFactorTest extends TestCase {

	public function test_wordfence_active_is_detected(): void {
		$user = $this->user( 1, 'wf', array( 'subscriber' ) );
		$this->wordfence2fa( 1 );
		$this->assertTrue( force_2fa_wordfence_2fa_active( $user ) );
	}

	public function test_wordfence_inactive_is_not_detected(): void {
		$user = $this->user( 2, 'plain', array( 'subscriber' ) );
		$this->assertFalse( force_2fa_wordfence_2fa_active( $user ) );
	}

	public function test_wordfence_failure_is_fail_safe(): void {
		// A thrown integration error must be swallowed and read as "no external 2FA",
		// so the email floor stays in place rather than being dropped.
		$user = $this->user( 3, 'boom', array( 'subscriber' ) );
		$this->wordfence2fa( 3 );
		$this->wordfenceThrows();
		$this->assertFalse( force_2fa_wordfence_2fa_active( $user ) );
		$this->assertFalse( force_2fa_user_is_exempt( $user ), 'fail-safe: not exempt, floor stays' );
	}

	public function test_other_external_2fa_via_exempt_filter(): void {
		// "Something else" is supported through the existing per-user exemption filter.
		$user = $this->user( 4, 'custom', array( 'subscriber' ) );
		$this->setFilter( 'force_2fa_user_is_exempt', true );
		$this->assertTrue( force_2fa_user_is_exempt( $user ) );
	}

	public function test_external_2fa_user_is_exempt(): void {
		$user = $this->user( 5, 'wf', array( 'subscriber' ) );
		$this->wordfence2fa( 5 );
		$this->assertTrue( force_2fa_user_is_exempt( $user ) );
	}

	public function test_external_2fa_user_skips_the_email_floor(): void {
		$this->wordfence2fa( 6 );
		$this->user( 6, 'wf', array( 'subscriber' ) );
		// Exempt → the enabled-providers list is returned untouched (no Email appended).
		$this->assertSame( array(), force_2fa_filter_enabled_providers( array(), 6 ) );
	}

	public function test_non_external_user_still_gets_the_email_floor(): void {
		// The core guarantee kept from 1.13.0: email remains a floor for everyone who is
		// NOT using external 2FA — INCLUDING users who already have a native method.
		$this->user( 7, 'totp', array( 'editor' ) );
		$result = force_2fa_filter_enabled_providers( array( 'Two_Factor_Totp' ), 7 );
		$this->assertContains( 'Two_Factor_Totp', $result, 'their native method is preserved' );
		$this->assertContains( 'Two_Factor_Email', $result, 'and email is still appended as a floor' );
	}

	public function test_unprotected_non_external_user_gets_email_floor(): void {
		$this->user( 8, 'nobody', array( 'subscriber' ) );
		$this->assertSame( array( 'Two_Factor_Email' ), force_2fa_filter_enabled_providers( array(), 8 ) );
	}
}
