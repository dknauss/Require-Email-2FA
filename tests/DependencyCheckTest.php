<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The soft dependency check that replaced the hard `Requires Plugins` gate:
 * force_2fa_dependency_met(), force_2fa_should_nag(), force_2fa_required_install_cap(),
 * and the admin-hook registration.
 */
final class DependencyCheckTest extends TestCase {

	public function test_dependency_met_is_true_when_email_provider_registered(): void {
		// The stub's get_providers() includes Two_Factor_Email, mirroring an active
		// Two Factor plugin with the Email provider registered.
		$this->assertTrue( force_2fa_dependency_met() );
	}

	public function test_dependency_not_met_when_email_provider_unregistered(): void {
		// Class present but another plugin removed Email from the provider registry:
		// the injected provider could not resolve, so we must report the dep unmet.
		$GLOBALS['__force2fa_providers'] = array( 'Two_Factor_Totp' => new \stdClass() );
		$this->assertFalse( force_2fa_dependency_met() );
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

	public function test_install_caps_network_missing_needs_install_and_network(): void {
		// Installing still requires install_plugins even when a setup grants network
		// plugin management but withholds it.
		$this->assertSame(
			array( 'install_plugins', 'manage_network_plugins' ),
			force_2fa_required_install_caps( false, true )
		);
	}

	public function test_install_caps_network_present_needs_network_only(): void {
		// Already on disk → only network activation is needed.
		$this->assertSame( array( 'manage_network_plugins' ), force_2fa_required_install_caps( true, true ) );
	}

	public function test_install_caps_single_site_missing_needs_install_and_activate(): void {
		$this->assertSame(
			array( 'install_plugins', 'activate_plugins' ),
			force_2fa_required_install_caps( false )
		);
	}

	public function test_install_caps_single_site_present_needs_activate(): void {
		$this->assertSame( array( 'activate_plugins' ), force_2fa_required_install_caps( true ) );
	}

	public function test_effectively_network_wide_when_formally_network_active(): void {
		$this->assertTrue( force_2fa_is_effectively_network_wide( true, true, false ) );
	}

	public function test_effectively_network_wide_when_mu_loaded(): void {
		// Multisite, not network-active, not per-site → mu-loaded → treat network-wide.
		$this->assertTrue( force_2fa_is_effectively_network_wide( true, false, false ) );
	}

	public function test_not_network_wide_when_per_site_active(): void {
		$this->assertFalse( force_2fa_is_effectively_network_wide( true, false, true ) );
	}

	public function test_not_network_wide_on_single_site(): void {
		$this->assertFalse( force_2fa_is_effectively_network_wide( false, false, false ) );
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

	// --- Dependency notice copy responds to installed-vs-inactive ---

	public function test_action_label_install_and_activate_when_absent_single_site(): void {
		$this->assertSame( 'Install & activate Two Factor', force_2fa_dependency_action_label( false, false ) );
	}

	public function test_action_label_activate_only_when_installed_single_site(): void {
		$this->assertSame( 'Activate Two Factor', force_2fa_dependency_action_label( true, false ) );
	}

	public function test_action_label_install_and_network_activate_when_absent(): void {
		$this->assertSame( 'Install & network-activate Two Factor', force_2fa_dependency_action_label( false, true ) );
	}

	public function test_action_label_network_activate_only_when_installed(): void {
		$this->assertSame( 'Network-activate Two Factor', force_2fa_dependency_action_label( true, true ) );
	}

	public function test_action_body_prompts_install_when_absent(): void {
		$this->assertStringContainsStringIgnoringCase( 'installed and active', force_2fa_dependency_action_body( false, false ) );
		$this->assertStringContainsStringIgnoringCase( 'install and network-activate', force_2fa_dependency_action_body( false, true ) );
	}

	public function test_action_body_prompts_activate_only_when_installed(): void {
		// Installed-but-inactive: say it's installed and to activate it, not to install it.
		$single = force_2fa_dependency_action_body( true, false );
		$this->assertStringContainsStringIgnoringCase( 'installed but not active', $single );

		$network = force_2fa_dependency_action_body( true, true );
		$this->assertStringContainsStringIgnoringCase( 'not network-active', $network );
		$this->assertStringNotContainsStringIgnoringCase( 'install and network-activate', $network );
	}
}
