<?php
/**
 * Regenerate playground/blueprint.json with the current plugin source inlined.
 * Run:  php playground/build-blueprint.php
 *
 * The blueprint installs Two Factor, the WebAuthn provider, and WP Mail Logging
 * from wordpress.org, inlines this (private) plugin via writeFile, enables
 * multisite, creates a subsite, and network-activates everything.
 */

$repo   = dirname( __DIR__ );
$plugin = file_get_contents( "$repo/force-email-two-factor.php" );

$dir = '/wordpress/wp-content/plugins/force-email-two-factor';

$blueprint = array(
	'$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
	'landingPage' => '/wp-admin/profile.php',
	'login'       => true,
	'features'    => array( 'networking' => true ), // allow wordpress.org downloads
	'steps'       => array(
		array(
			'step'       => 'installPlugin',
			'pluginData' => array( 'resource' => 'wordpress.org/plugins', 'slug' => 'two-factor' ),
			'options'    => array( 'activate' => false ),
		),
		array(
			'step'       => 'installPlugin',
			'pluginData' => array( 'resource' => 'wordpress.org/plugins', 'slug' => 'two-factor-provider-webauthn' ),
			'options'    => array( 'activate' => false ),
		),
		array(
			'step'       => 'installPlugin',
			'pluginData' => array( 'resource' => 'wordpress.org/plugins', 'slug' => 'wp-mail-logging' ),
			'options'    => array( 'activate' => false ),
		),
		array( 'step' => 'mkdir', 'path' => $dir ),
		array(
			'step' => 'writeFile',
			'path' => "$dir/force-email-two-factor.php",
			'data' => $plugin,
		),
		array( 'step' => 'enableMultisite' ),
		array(
			'step'    => 'wp-cli',
			'command' => 'wp plugin activate two-factor two-factor-provider-webauthn wp-mail-logging force-email-two-factor --network',
		),
		array(
			'step'    => 'wp-cli',
			'command' => "wp site create --slug=site2 --title='Subsite 2'",
		),
	),
);

$out = "$repo/playground/blueprint.json";
file_put_contents( $out, json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

echo 'wrote ' . $out . ' (' . filesize( $out ) . " bytes)\n";
echo 'json valid: ' . ( json_decode( file_get_contents( $out ) ) !== null ? 'yes' : 'NO' ) . "\n";
