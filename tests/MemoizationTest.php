<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * Per-request memoization of the enforcement-scope evaluation
 * (force_2fa_compute_exemption / force_2fa_flush_exemption_cache).
 *
 * The site-iterating work only runs on multisite when a capability scope and/or a
 * role exclusion is configured, so these tests arrange exactly that and count
 * get_blogs_of_user() invocations (see the stub) to prove the loop is not repeated.
 */
final class MemoizationTest extends TestCase {

	/** Multisite user: subscriber on the login site, administrator on site 2. */
	private function arrangeCrossSiteUser( int $id ): \WP_User {
		$this->enforceCapability( 'manage_options' ); // capability loop runs
		$this->excludeRoles( array( 'subscriber' ) ); // network-role loop runs
		$this->multisite( true );
		$user = $this->user( $id, 'u' . $id, array( 'subscriber' ) );
		$this->userBlogs( $id, array( 1, 2 ) );
		$this->siteCaps( 2, $id, array( 'manage_options' => true ) );
		$this->siteRoles( 1, $id, array( 'subscriber' ) );
		$this->siteRoles( 2, $id, array( 'administrator' ) );
		return $user;
	}

	public function test_repeat_calls_do_not_re_iterate_sites(): void {
		$user = $this->arrangeCrossSiteUser( 30 );

		$first             = force_2fa_user_is_exempt( $user );
		$calls_after_first = $GLOBALS['__force2fa_get_blogs_calls'];

		$second             = force_2fa_user_is_exempt( $user );
		$calls_after_second = $GLOBALS['__force2fa_get_blogs_calls'];

		$this->assertFalse( $first, 'cross-site admin stays enforced' );
		$this->assertSame( $first, $second, 'memo returns the same decision' );
		$this->assertGreaterThan( 0, $calls_after_first, 'first call iterates the user\'s sites' );
		$this->assertSame( $calls_after_first, $calls_after_second, 'second call is served from the memo' );
	}

	public function test_flush_forces_a_recompute(): void {
		$user = $this->arrangeCrossSiteUser( 31 );

		force_2fa_user_is_exempt( $user );
		$calls_after_first = $GLOBALS['__force2fa_get_blogs_calls'];

		force_2fa_flush_exemption_cache();
		force_2fa_user_is_exempt( $user );

		$this->assertGreaterThan(
			$calls_after_first,
			$GLOBALS['__force2fa_get_blogs_calls'],
			'a full flush recomputes'
		);
	}

	public function test_flush_targets_a_single_user(): void {
		$a = $this->arrangeCrossSiteUser( 32 );
		$b = $this->user( 33, 'u33', array( 'subscriber' ) );
		$this->userBlogs( 33, array( 1, 2 ) );
		$this->siteCaps( 2, 33, array( 'manage_options' => true ) );
		$this->siteRoles( 1, 33, array( 'subscriber' ) );
		$this->siteRoles( 2, 33, array( 'administrator' ) );

		force_2fa_user_is_exempt( $a );
		force_2fa_user_is_exempt( $b );
		$calls = $GLOBALS['__force2fa_get_blogs_calls'];

		// Flush only user 32; user 33 must stay memoized.
		force_2fa_flush_exemption_cache( 32 );
		force_2fa_user_is_exempt( $b );
		$this->assertSame( $calls, $GLOBALS['__force2fa_get_blogs_calls'], 'untouched user stays cached' );

		force_2fa_user_is_exempt( $a );
		$this->assertGreaterThan( $calls, $GLOBALS['__force2fa_get_blogs_calls'], 'flushed user recomputes' );
	}

	public function test_cheap_path_never_iterates_sites(): void {
		// Single site, no excluded roles: neither loop should run at all.
		$this->enforceCapability( 'manage_options' );
		$user = $this->adminUser( 34, 'admin' );

		force_2fa_user_is_exempt( $user );

		$this->assertSame( 0, $GLOBALS['__force2fa_get_blogs_calls'] );
	}

	public function test_override_filter_still_runs_on_every_call(): void {
		// The memo caches only the pre-filter computation; the user-facing filter must
		// still apply on each call so a programmatic override stays authoritative.
		$user = $this->arrangeCrossSiteUser( 35 );
		$this->setFilter( 'force_2fa_user_is_exempt', true );

		$this->assertTrue( force_2fa_user_is_exempt( $user ) );
		$this->assertTrue( force_2fa_user_is_exempt( $user ) );
	}

	public function test_clean_user_cache_hook_is_registered(): void {
		$GLOBALS['__force2fa_added_actions'] = array();
		force_2fa_register_hooks();

		$this->assertContains(
			array( 'clean_user_cache', 'force_2fa_flush_exemption_cache', 10, 1 ),
			$GLOBALS['__force2fa_added_actions']
		);
	}
}
