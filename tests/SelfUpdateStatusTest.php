<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * Self-update opt-out and posture classification:
 * force_2fa_self_update_enabled() (the toggle) and force_2fa_self_update_status()
 * (the pure classifier the Site Health check reports).
 */
final class SelfUpdateStatusTest extends TestCase {

	public function test_self_update_enabled_by_default(): void {
		$this->assertTrue( force_2fa_self_update_enabled() );
	}

	public function test_self_update_can_be_disabled_by_filter(): void {
		// The FORCE_2FA_DISABLE_SELF_UPDATE constant is the wp-config escape hatch;
		// the filter is the runtime/testable equivalent a management layer can use.
		$this->setFilter( 'force_2fa_self_update_enabled', false );
		$this->assertFalse( force_2fa_self_update_enabled() );
	}

	public function test_status_active_when_enabled_and_shippable(): void {
		$this->assertSame( 'active', force_2fa_self_update_status( true, false, true, true ) );
	}

	public function test_status_disabled_config_takes_precedence(): void {
		// A deliberate opt-out is reported as such even if a .git also happens to be present.
		$this->assertSame( 'disabled_config', force_2fa_self_update_status( false, true, true, true ) );
	}

	public function test_status_disabled_vcs_when_working_copy(): void {
		$this->assertSame( 'disabled_vcs', force_2fa_self_update_status( true, true, true, true ) );
	}

	public function test_status_unavailable_when_puc_missing(): void {
		$this->assertSame( 'unavailable_no_puc', force_2fa_self_update_status( true, false, false, true ) );
	}

	public function test_status_disabled_when_update_uri_absent(): void {
		$this->assertSame( 'disabled_no_update_uri', force_2fa_self_update_status( true, false, true, false ) );
	}
}
