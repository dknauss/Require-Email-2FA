<?php

namespace Force2FA\Tests;

use Force2FA\TestCase;

/**
 * force_2fa_update_checker(): the shared accessor through which diagnostics and
 * the update E2E reach the exact Plugin Update Checker instance WordPress uses,
 * instead of guessing it from hook tables.
 */
final class UpdateCheckerAccessorTest extends TestCase {

	public function test_returns_null_before_bootstrap_wires_a_checker(): void {
		$this->assertNull( force_2fa_update_checker() );
	}

	public function test_records_and_returns_the_wired_checker_instance(): void {
		$checker = new \stdClass();
		$this->assertSame( $checker, force_2fa_update_checker( $checker ) );
		$this->assertSame( $checker, force_2fa_update_checker() );
	}
}
