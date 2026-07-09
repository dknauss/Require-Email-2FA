<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;
use Force2FA_WpDieException;

/**
 * The one-click install handler's guards: force_2fa_handle_install_two_factor().
 *
 * The upgrader body (plugins_api / Plugin_Upgrader / activate_plugin) calls admin
 * APIs the zero-dependency harness does not stub and is exercised by the E2E jobs.
 * What is unit-tested here is the front gate that runs BEFORE any of that: the nonce
 * check and the capability wall. Both must stop an unauthorized request cold, so a
 * regression that let it fall through to the installer is the dangerous one.
 *
 * Runs under the no-two-factor bootstrap so Two Factor reads as not-installed
 * (file absent by default) — the natural state when the button is shown.
 */
final class InstallHandlerTest extends TestCase {

	public function test_bad_nonce_stops_before_the_installer(): void {
		$GLOBALS['__force2fa_nonce_ok'] = false;

		$this->expectException( Force2FA_WpDieException::class );
		force_2fa_handle_install_two_factor();
	}

	public function test_missing_install_cap_stops_at_the_capability_wall(): void {
		$GLOBALS['__force2fa_is_multisite'] = false;
		// Two Factor is not on disk (default), so the handler needs install_plugins;
		// a user who can only activate must be stopped before the upgrader runs.
		$this->userCaps( array( 'activate_plugins' ) );

		$threw = false;
		try {
			force_2fa_handle_install_two_factor();
		} catch ( Force2FA_WpDieException $e ) {
			$threw = true;
			$this->assertStringContainsString( 'permission', $e->getMessage() );
		}
		$this->assertTrue( $threw, 'The handler must wp_die() when the user lacks install_plugins.' );
	}

	public function test_installed_but_only_activate_cap_still_requires_activate(): void {
		// Two Factor already on disk → install_plugins not required, but activate_plugins
		// is. A user with neither must still be stopped.
		$GLOBALS['__force2fa_is_multisite'] = false;
		$this->installTwoFactorFile();
		$this->userCaps( array( 'read' ) ); // no activate_plugins, no install_plugins

		$this->expectException( Force2FA_WpDieException::class );
		force_2fa_handle_install_two_factor();
	}
}
