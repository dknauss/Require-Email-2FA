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

	public function test_register_hooks_wires_the_network_admin_notice(): void {
		$GLOBALS['__force2fa_added_actions'] = array();
		force_2fa_register_hooks();

		$tags = array_map(
			static function ( $registration ) {
				return $registration[0];
			},
			$GLOBALS['__force2fa_added_actions']
		);

		$this->assertContains( 'network_admin_notices', $tags );
	}

	public function test_should_nag_network_when_self_network_active_and_dep_missing(): void {
		// Require Email 2FA is network-active but Two Factor is not network-active,
		// and the super admin can act → the network-admin notice should show.
		$this->assertTrue( force_2fa_should_nag_network( true, false, true ) );
	}

	public function test_should_not_nag_network_when_self_not_network_active(): void {
		// Per-site activation is handled by the per-site notice, not this one.
		$this->assertFalse( force_2fa_should_nag_network( false, false, true ) );
	}

	public function test_should_not_nag_network_when_dependency_is_network_active(): void {
		$this->assertFalse( force_2fa_should_nag_network( true, true, true ) );
	}

	public function test_should_not_nag_network_when_user_cannot_manage_network(): void {
		$this->assertFalse( force_2fa_should_nag_network( true, false, false ) );
	}

	public function test_required_cap_is_network_when_network_and_absent(): void {
		$this->assertSame( 'manage_network_plugins', force_2fa_required_install_cap( false, true ) );
	}

	public function test_required_cap_is_network_when_network_and_installed(): void {
		// Network-wide activation needs the network cap regardless of on-disk state.
		$this->assertSame( 'manage_network_plugins', force_2fa_required_install_cap( true, true ) );
	}

	public function test_required_cap_defaults_to_single_site_without_network_flag(): void {
		// Backwards-compatible: the network flag defaults off.
		$this->assertSame( 'install_plugins', force_2fa_required_install_cap( false ) );
		$this->assertSame( 'activate_plugins', force_2fa_required_install_cap( true ) );
	}

	public function test_activation_blocked_for_per_site_activation_on_multisite(): void {
		// is_multisite() true AND not network-wide → block.
		$this->assertTrue( force_2fa_activation_blocked( true, false ) );
	}

	public function test_activation_allowed_for_network_wide_activation_on_multisite(): void {
		$this->assertFalse( force_2fa_activation_blocked( true, true ) );
	}

	public function test_activation_allowed_on_single_site(): void {
		// Not multisite → per-site activation is the only mode; always allowed.
		$this->assertFalse( force_2fa_activation_blocked( false, false ) );
		$this->assertFalse( force_2fa_activation_blocked( false, true ) );
	}

	public function test_warn_legacy_per_site_when_active_only_per_site_on_multisite(): void {
		// Upgraded install that was activated per-site before 1.9.0: warn the super
		// admin so they migrate to network activation.
		$this->assertTrue( force_2fa_should_warn_legacy_per_site( true, true, true ) );
	}

	public function test_no_legacy_warning_when_not_multisite(): void {
		$this->assertFalse( force_2fa_should_warn_legacy_per_site( false, true, true ) );
	}

	public function test_no_legacy_warning_when_network_active(): void {
		// Not "only per-site" (it's network-active) → nothing to migrate.
		$this->assertFalse( force_2fa_should_warn_legacy_per_site( true, false, true ) );
	}

	public function test_no_legacy_warning_when_user_cannot_manage_network(): void {
		// Only a super admin can migrate it, so only they see the actionable warning.
		$this->assertFalse( force_2fa_should_warn_legacy_per_site( true, true, false ) );
	}
}
