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

$playground_helper = <<<'PHP'
<?php
/**
 * Playground-only helper for Require Email 2FA demos.
 *
 * WordPress Playground sessions often do not have a real mailbox attached to the
 * pre-created account. This helper captures the Two Factor email token as it is
 * generated and prints it on the 2FA challenge screen. Do not install this file
 * outside disposable Playground/demo environments.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'two_factor_token_email_message',
	function ( $message, $token, $user_id ) {
		$user = get_userdata( (int) $user_id );

		set_transient(
			'require_email_2fa_playground_latest_token',
			array(
				'token' => (string) $token,
				'user'  => $user ? $user->user_login : '',
			),
			15 * MINUTE_IN_SECONDS
		);

		return $message;
	},
	10,
	3
);

add_action(
	'two_factor_after_authentication_prompt',
	function ( $provider ) {
		if ( ! ( $provider instanceof Two_Factor_Email ) ) {
			return;
		}

		$latest = get_transient( 'require_email_2fa_playground_latest_token' );
		if ( empty( $latest['token'] ) ) {
			return;
		}
		?>
		<div class="message" style="border-left-color:#3858e9;">
			<p><strong>Playground demo code:</strong> <code style="font-size:1.25em;"><?php echo esc_html( $latest['token'] ); ?></code></p>
			<p class="description">This code is shown only by the Playground blueprint helper because this browser demo has no real mailbox.</p>
		</div>
		<?php
	},
	10,
	1
);
PHP;

$dir    = '/wordpress/wp-content/plugins/force-email-two-factor';
$mu_dir = '/wordpress/wp-content/mu-plugins';

$blueprint = array(
	'$schema'     => 'https://playground.wordpress.net/blueprint-schema.json',
	'landingPage' => '/wp-login.php?redirect_to=/wp-admin/profile.php',
	'login'       => false,
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
		array(
			'step'    => 'wp-cli',
			'command' => 'wp user update 1 --user_pass=password --user_email=admin@example.com --display_name=Admin',
		),
		array( 'step' => 'mkdir', 'path' => $dir ),
		array( 'step' => 'mkdir', 'path' => $mu_dir ),
		array(
			'step' => 'writeFile',
			'path' => "$dir/force-email-two-factor.php",
			'data' => $plugin,
		),
		array(
			'step' => 'writeFile',
			'path' => "$mu_dir/require-email-2fa-playground-code.php",
			'data' => $playground_helper,
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
