<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * uninstall.php's single-site cleanup path.
 *
 * The multisite branch (the get_sites() per-site loop) is exercised end-to-end by
 * bin/multisite-e2e.sh against a real network; the single-site branch has no such
 * coverage. This asserts that deleting the plugin on single-site purges exactly the
 * two artifacts Plugin Update Checker leaves behind — the cached-metadata site
 * option and the update-check cron — and nothing more.
 *
 * uninstall.php runs at include time, declares a function, and exit()s unless
 * WP_UNINSTALL_PLUGIN is defined, so it can be included only once per process; this
 * test owns that single include and drives the single-site branch via the
 * is_multisite() stub.
 */
final class UninstallTest extends TestCase {

	public function test_single_site_uninstall_purges_puc_option_and_cron_only(): void {
		// The slug uninstall.php derives from basename( __DIR__ ) — its own directory,
		// the repository root here — so compute the expected keys the same way.
		$slug        = basename( dirname( __DIR__ ) );
		$option_name = 'external_updates-' . $slug;
		$cron_hook   = 'puc_cron_check_updates-' . $slug;

		$GLOBALS['__force2fa_is_multisite'] = false;
		// Fail loudly if the single-site branch ever consults the network site list.
		$GLOBALS['__force2fa_sites'] = array( 2, 3 );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'force-email-two-factor/force-email-two-factor.php' );
		}

		require dirname( __DIR__ ) . '/uninstall.php';

		// The cached-metadata option is deleted, exactly once, by name.
		$this->assertSame( array( $option_name ), $GLOBALS['__force2fa_deleted_site_options'] );

		// The update-check cron is cleared once, on the current site (blog 1), and the
		// get_sites() multisite loop is never entered (no per-site blog switches).
		$this->assertCount( 1, $GLOBALS['__force2fa_cleared_crons'] );
		$this->assertSame( $cron_hook, $GLOBALS['__force2fa_cleared_crons'][0]['hook'] );
		$this->assertSame( 1, $GLOBALS['__force2fa_cleared_crons'][0]['blog_id'] );
	}
}
