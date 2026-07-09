<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * The "Two Factor is absent" fail-safe.
 *
 * Runs only under tests/bootstrap-no-two-factor.php, where neither Two_Factor_Core
 * nor Two_Factor_Email is defined — the state when the Two Factor plugin is inactive
 * or has been removed. The plugin must then report the dependency unmet and leave
 * every user's enabled-provider list untouched, so it never injects a provider that
 * cannot resolve (which would silently break login for everyone).
 *
 * Under the default bootstrap both classes exist, so these branches are unreachable;
 * that is why this lives in its own suite/config rather than the main unit suite.
 */
final class DependencyAbsentTest extends TestCase {

	public function test_two_factor_classes_really_are_absent(): void {
		// Guard the guard: if a future bootstrap change defined these stubs, the two
		// assertions below would pass for the wrong reason. Fail loudly instead.
		$this->assertFalse( class_exists( 'Two_Factor_Core' ), 'Two_Factor_Core must be undefined in this run.' );
		$this->assertFalse( class_exists( 'Two_Factor_Email' ), 'Two_Factor_Email must be undefined in this run.' );
	}

	public function test_dependency_is_unmet_when_two_factor_is_absent(): void {
		$this->assertFalse( force_2fa_dependency_met() );
	}

	public function test_enforcement_filter_passes_the_list_through_untouched(): void {
		// No Email floor is appended: with the provider class absent, injecting
		// 'Two_Factor_Email' would be an unresolvable key. The list is returned as-is.
		$existing = array( 'Two_Factor_Totp' );
		$this->assertSame( $existing, force_2fa_filter_enabled_providers( $existing, 42 ) );
	}

	public function test_enforcement_filter_leaves_an_empty_list_empty(): void {
		// A user with nothing configured stays at zero providers — the plugin does not
		// silently turn on a factor it cannot back with a registered provider.
		$this->assertSame( array(), force_2fa_filter_enabled_providers( array(), 42 ) );
	}
}
