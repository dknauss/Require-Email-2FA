<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The soft dependency check that replaced the hard `Requires Plugins` gate:
 * force_2fa_dependency_met(), force_2fa_should_nag(), force_2fa_required_install_cap(),
 * and the admin-hook registration.
 */
final class DependencyCheckTest extends TestCase {

	public function test_dependency_met_is_true_when_provider_class_present(): void {
		// The bootstrap defines Two_Factor_Email, mirroring an active Two Factor plugin.
		$this->assertTrue( force_2fa_dependency_met() );
	}

	public function test_should_nag_when_missing_and_user_can_manage(): void {
		$this->assertTrue( force_2fa_should_nag( false, true ) );
	}

	public function test_should_not_nag_when_dependency_met(): void {
		$this->assertFalse( force_2fa_should_nag( true, true ) );
	}

	public function test_should_not_nag_when_user_cannot_manage(): void {
		$this->assertFalse( force_2fa_should_nag( false, false ) );
	}

	public function test_should_not_nag_when_met_and_user_cannot_manage(): void {
		$this->assertFalse( force_2fa_should_nag( true, false ) );
	}

	public function test_required_cap_is_install_when_plugin_absent(): void {
		$this->assertSame( 'install_plugins', force_2fa_required_install_cap( false ) );
	}

	public function test_required_cap_is_activate_when_already_installed(): void {
		// Already on disk → only activation is needed, which is a lower bar.
		$this->assertSame( 'activate_plugins', force_2fa_required_install_cap( true ) );
	}

	public function test_register_hooks_wires_the_admin_dependency_hooks(): void {
		$GLOBALS['__force2fa_added_actions'] = array();
		force_2fa_register_hooks();

		$tags = array_map(
			static function ( $registration ) {
				return $registration[0];
			},
			$GLOBALS['__force2fa_added_actions']
		);

		$this->assertContains( 'admin_notices', $tags );
		$this->assertContains( 'admin_post_force_2fa_install_two_factor', $tags );
	}
}
