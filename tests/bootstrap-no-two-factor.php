<?php
/**
 * Test bootstrap for the "Two Factor is absent" fail-safe.
 *
 * The default bootstrap.php always defines the Two_Factor_Core / Two_Factor_Email
 * stubs, so the class-absent guards in force_2fa_dependency_met() (and the pass-through
 * in force_2fa_filter_enabled_providers()) are unreachable there. Defining
 * FORCE2FA_TEST_NO_TWO_FACTOR before delegating to the shared bootstrap suppresses
 * both stubs, so this run exercises the plugin exactly as it behaves when the Two
 * Factor plugin is inactive or removed: every enforcement guard must no-op safely
 * and never inject a provider WordPress cannot resolve.
 *
 * Run as a separate PHPUnit invocation (one process cannot un-define a class):
 *
 *     vendor/bin/phpunit -c phpunit-no-two-factor.xml.dist
 */

define( 'FORCE2FA_TEST_NO_TWO_FACTOR', true );

require __DIR__ . '/bootstrap.php';
