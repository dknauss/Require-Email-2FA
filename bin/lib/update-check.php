<?php
/**
 * Update E2E assertion snippet, run inside the disposable site via
 * `wp eval-file` by bin/update-e2e.sh. Requires the update-e2e-mu-shim.php
 * mu-plugin (checker discovery + optional GitHub authentication).
 *
 * Env contract (exported by bin/update-e2e.sh):
 *   FORCE2FA_EXPECT                 "update" (an update must be offered) or
 *                                   "none" (no update may be offered).
 *   FORCE2FA_EXPECTED_VERSION       Exact version the check must offer.  [update]
 *   FORCE2FA_EXPECTED_PACKAGE       Exact release-asset browser_download_url
 *                                   PUC must offer when unauthenticated. [update]
 *   FORCE2FA_EXPECTED_PACKAGE_API   Exact release-asset API URL PUC offers
 *                                   instead when authenticated.          [update]
 *
 * The package assertion is an exact string match against URLs taken from THIS
 * repository's release JSON — it pins owner, repo, tag, and asset, which is the
 * trust boundary the updater is documented to enforce.
 *
 * @package force-email-two-factor
 */

$force2fa_expect = getenv( 'FORCE2FA_EXPECT' );
if ( 'update' !== $force2fa_expect && 'none' !== $force2fa_expect ) {
	fwrite( STDERR, "FAIL: FORCE2FA_EXPECT must be 'update' or 'none'\n" );
	exit( 1 );
}

if ( ! function_exists( 'force2fa_e2e_update_checker' ) ) {
	fwrite( STDERR, "FAIL: update-e2e-mu-shim.php mu-plugin is not installed\n" );
	exit( 1 );
}

$force2fa_checker = force2fa_e2e_update_checker();
if ( ! $force2fa_checker ) {
	fwrite( STDERR, "FAIL: Plugin Update Checker did not register on site_transient_update_plugins\n" );
	exit( 1 );
}

$force2fa_update = $force2fa_checker->checkForUpdates();

if ( 'none' === $force2fa_expect ) {
	if ( $force2fa_update ) {
		fwrite( STDERR, "FAIL: update still offered after applying latest release\n" );
		fwrite( STDERR, wp_json_encode( $force2fa_update, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		exit( 1 );
	}
	echo "FORCE2FA_UPDATE_NOOP_OK\n";
	exit( 0 );
}

if ( ! $force2fa_update ) {
	fwrite( STDERR, "FAIL: Plugin Update Checker did not find an update\n" );
	if ( method_exists( $force2fa_checker, 'getLastRequestApiErrors' ) ) {
		fwrite( STDERR, wp_json_encode( $force2fa_checker->getLastRequestApiErrors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}
	exit( 1 );
}

$force2fa_offered_version = isset( $force2fa_update->version ) ? $force2fa_update->version : null;
$force2fa_offered_package = isset( $force2fa_update->download_url ) ? $force2fa_update->download_url : null;

echo wp_json_encode(
	array(
		'new_version' => $force2fa_offered_version,
		'package'     => $force2fa_offered_package,
		'slug'        => isset( $force2fa_update->slug ) ? $force2fa_update->slug : null,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";

$force2fa_expected_version = getenv( 'FORCE2FA_EXPECTED_VERSION' );
if ( $force2fa_offered_version !== $force2fa_expected_version ) {
	fwrite( STDERR, sprintf( "FAIL: update check offered version %s, expected %s\n", var_export( $force2fa_offered_version, true ), var_export( $force2fa_expected_version, true ) ) );
	exit( 1 );
}

// Exact-match the package URL: no wildcards, so no other owner/repo/tag/asset
// can satisfy the assertion.
$force2fa_allowed_packages = array_values(
	array_filter(
		array(
			getenv( 'FORCE2FA_EXPECTED_PACKAGE' ),
			getenv( 'FORCE2FA_EXPECTED_PACKAGE_API' ),
		)
	)
);
if ( ! $force2fa_allowed_packages ) {
	fwrite( STDERR, "FAIL: no expected package URL provided\n" );
	exit( 1 );
}
if ( ! in_array( $force2fa_offered_package, $force2fa_allowed_packages, true ) ) {
	fwrite( STDERR, sprintf( "FAIL: update package %s is not the expected GitHub Release asset:\n", var_export( $force2fa_offered_package, true ) ) );
	foreach ( $force2fa_allowed_packages as $force2fa_allowed ) {
		fwrite( STDERR, '  ' . $force2fa_allowed . "\n" );
	}
	exit( 1 );
}

echo "FORCE2FA_UPDATE_OFFER_OK\n";
