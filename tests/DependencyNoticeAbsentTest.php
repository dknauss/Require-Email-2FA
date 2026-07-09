<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * Rendering glue for the dependency notices when Two Factor is NOT loaded — the
 * 'absent' (not on disk) and 'inactive' (on disk, not active) states, plus the
 * no-capability fallbacks and the multisite heads-up.
 *
 * Runs under the no-two-factor bootstrap: with Two_Factor_Core unloaded, the state
 * classifier can reach 'absent'/'inactive' (under the default bootstrap it is always
 * 'unusable', which is covered by tests/DependencyNoticeTest.php). On-disk presence
 * is toggled with installTwoFactorFile() / the default absent state.
 */
final class DependencyNoticeAbsentTest extends TestCase {

	public function test_single_site_absent_offers_install_and_activate_button(): void {
		$GLOBALS['__force2fa_is_multisite'] = false; // full caps by default

		$html = $this->renderNotice( 'force_2fa_dependency_notice' );

		$this->assertStringContainsString( 'Install &amp; activate Two Factor', $this->amp( $html ) );
		$this->assertStringContainsString( 'needs the Two Factor plugin to be installed and active', $html );
		$this->assertStringContainsString( 'button-primary', $html );
		// The action button points at the nonce-protected install handler.
		$this->assertStringContainsString( 'action=force_2fa_install_two_factor', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_single_site_inactive_offers_activate_only(): void {
		$GLOBALS['__force2fa_is_multisite'] = false;
		$this->installTwoFactorFile(); // present but (Two_Factor_Core unloaded) not active

		$html = $this->renderNotice( 'force_2fa_dependency_notice' );

		$this->assertStringContainsString( 'Activate Two Factor', $html );
		$this->assertStringContainsString( 'installed but not active', $html );
		// "Activate", not "Install & activate": it is already on disk.
		$this->assertStringNotContainsString( 'Install &amp; activate', $this->amp( $html ) );
		$this->assertStringContainsString( 'button-primary', $html );
	}

	public function test_single_site_absent_without_install_cap_shows_no_button(): void {
		$GLOBALS['__force2fa_is_multisite'] = false;
		// Can activate but not install: the one-click handler would hit its permission
		// wall, so no button — an informational notice pointing to an admin instead.
		$this->userCaps( array( 'activate_plugins' ) );

		$html = $this->renderNotice( 'force_2fa_dependency_notice' );

		$this->assertStringContainsString( 'Ask an administrator', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}

	public function test_multisite_subsite_absent_heads_up_points_to_network_admin(): void {
		$GLOBALS['__force2fa_is_multisite'] = true; // full caps → manage_options true

		$html = $this->renderNotice( 'force_2fa_dependency_notice' );

		$this->assertStringContainsString( 'not enforcing email 2FA on this site', $html );
		$this->assertStringContainsString( 'not installed', $html );
		$this->assertStringContainsString( 'network administrator', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}

	public function test_network_absent_offers_install_and_network_activate_button(): void {
		$self = basename( dirname( __DIR__ ) ) . '/force-email-two-factor.php';
		$GLOBALS['__force2fa_is_multisite']   = true;
		$GLOBALS['__force2fa_network_active'] = array( $self => true ); // self network-active; Two Factor not

		$html = $this->renderNotice( 'force_2fa_network_dependency_notice' );

		$this->assertStringContainsString( 'Install &amp; network-activate Two Factor', $this->amp( $html ) );
		$this->assertStringContainsString( 'button-primary', $html );
		$this->assertStringContainsString( 'action=force_2fa_install_two_factor', $html );
	}

	public function test_network_absent_without_install_cap_shows_no_button(): void {
		$self = basename( dirname( __DIR__ ) ) . '/force-email-two-factor.php';
		$GLOBALS['__force2fa_is_multisite']   = true;
		$GLOBALS['__force2fa_network_active'] = array( $self => true );
		$this->userCaps( array( 'manage_network_plugins' ) ); // can manage, cannot install

		$html = $this->renderNotice( 'force_2fa_network_dependency_notice' );

		$this->assertStringContainsString( 'Ask an administrator', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}

	/**
	 * The escapers are pass-throughs in the harness, so "&" stays "&". Real esc_html()
	 * would emit "&amp;"; normalize here so the label assertions read as they render.
	 */
	private function amp( string $html ): string {
		return str_replace( '&', '&amp;', $html );
	}
}
