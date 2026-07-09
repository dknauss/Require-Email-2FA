<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The Application-Password user binding: force_2fa_note_app_password_user() (the
 * writer, hooked to 'application_password_did_authenticate') and
 * force_2fa_app_password_user_id() (the reader).
 *
 * This pair implements condition (b) of the API-login bypass — "THIS account
 * presented its own Application Password this request." The api-login filter is
 * tested via the appPasswordUsed() helper, which sets the same global; these
 * tests exercise the real writer directly so a regression in the binding (e.g.
 * recording a constant ID, or losing the WP_User branch) is caught.
 */
final class AppPasswordBindingTest extends TestCase {

	public function test_reader_is_zero_when_no_app_password_authenticated(): void {
		$this->assertSame( 0, force_2fa_app_password_user_id() );
	}

	public function test_writer_records_the_authenticated_wp_user_id(): void {
		$user = new \WP_User( 5, 'svc', array( 'author' ) );
		force_2fa_note_app_password_user( $user );
		$this->assertSame( 5, force_2fa_app_password_user_id() );
	}

	public function test_writer_accepts_a_bare_user_id(): void {
		// Fallback branch: core normally passes a WP_User, but a bare ID must still
		// be recorded as an int.
		force_2fa_note_app_password_user( 7 );
		$this->assertSame( 7, force_2fa_app_password_user_id() );
	}

	public function test_writer_coerces_a_non_user_non_int_to_zero(): void {
		// Defensive: an unexpected value records 0, which the reader treats as
		// "no app password this request" — fail-closed, never a spurious match.
		force_2fa_note_app_password_user( null );
		$this->assertSame( 0, force_2fa_app_password_user_id() );
	}

	public function test_register_hooks_wires_the_app_password_writer(): void {
		// The whole API-login bypass rests on force_2fa_note_app_password_user()
		// running on every Application Password auth: if this add_action were
		// dropped, force_2fa_app_password_user_id() would always read 0 and EVERY
		// service account would be denied (a fail-closed outage). None of the other
		// register_hooks tests assert this action, so guard it explicitly.
		$GLOBALS['__force2fa_added_actions'] = array();
		force_2fa_register_hooks();

		$found = false;
		foreach ( $GLOBALS['__force2fa_added_actions'] as $registration ) {
			if ( 'application_password_did_authenticate' === $registration[0]
				&& 'force_2fa_note_app_password_user' === $registration[1] ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'The app-password writer must be hooked to application_password_did_authenticate.' );
	}
}
