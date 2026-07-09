<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * Rendering glue for the dependency notices — the branch that SELECTS which notice
 * to emit, which no test previously exercised (only the pure decisions it delegates
 * to were covered, so a swapped condition would pass the whole suite).
 *
 * This file runs under the default bootstrap, where Two_Factor_Core IS loaded, so
 * unregistering the Email provider drives the 'unusable' state — Two Factor active
 * but its Email provider gone, which activation cannot fix. The 'absent'/'inactive'
 * actionable notices need Two_Factor_Core to be UNloaded and are covered separately
 * (tests/DependencyNoticeAbsentTest.php, no-two-factor config).
 */
final class DependencyNoticeTest extends TestCase {

	/** Simulate Two Factor loaded but its Email provider unregistered by another plugin. */
	private function unregisterEmailProvider(): void {
		$GLOBALS['__force2fa_providers'] = array( 'Two_Factor_Totp' => new \stdClass() );
	}

	public function test_no_notice_when_dependency_is_met(): void {
		// Default state: Email provider registered → nothing to nag about.
		$html = $this->renderNotice( 'force_2fa_dependency_notice' );
		$this->assertSame( '', $html );
	}

	public function test_single_site_unusable_warns_to_restore_provider_without_a_button(): void {
		$GLOBALS['__force2fa_is_multisite'] = false;
		$this->unregisterEmailProvider();

		$html = $this->renderNotice( 'force_2fa_dependency_notice' );

		$this->assertStringContainsString( 'Email provider is not available', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		// Activation cannot fix an unregistered provider, so no action button is offered.
		$this->assertStringNotContainsString( 'button-primary', $html );
	}

	public function test_subsite_unusable_warns_to_ask_network_admin(): void {
		$GLOBALS['__force2fa_is_multisite'] = true;
		$this->unregisterEmailProvider();

		$html = $this->renderNotice( 'force_2fa_dependency_notice' );

		// Assert the string that DISTINGUISHES the 'unusable' subsite branch from the
		// generic heads-up branch (whose title and "network administrator" wording are
		// otherwise identical). Without this, a regression that fell through to the
		// heads-up notice — telling the admin to "install and network-activate" instead
		// of "restore the Email provider" — would still pass.
		$this->assertStringContainsString( 'Email provider is not available', $html );
		$this->assertStringContainsString( 'restore the Email provider', $html );
		$this->assertStringContainsString( 'network administrator', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}

	public function test_network_notice_warns_unusable_when_two_factor_network_active_but_provider_gone(): void {
		$self          = basename( dirname( __DIR__ ) ) . '/force-email-two-factor.php';
		$two_factor    = 'two-factor/two-factor.php';
		$GLOBALS['__force2fa_is_multisite']    = true;
		$GLOBALS['__force2fa_network_active']  = array(
			$self       => true, // Require Email 2FA is network-active...
			$two_factor => true, // ...and so is Two Factor (so activation isn't the fix).
		);
		$this->unregisterEmailProvider(); // ...but its Email provider is gone.

		$html = $this->renderNotice( 'force_2fa_network_dependency_notice' );

		$this->assertStringContainsString( 'not enforcing email 2FA network-wide', $html );
		$this->assertStringContainsString( 'Email provider is not available', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}
}
