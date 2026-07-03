<?php
/**
 * Update E2E helper mu-plugin. Installed into the disposable test site by
 * bin/update-e2e.sh — never shipped with the plugin.
 *
 * Two jobs:
 *  - force2fa_e2e_update_checker(): locate the Plugin Update Checker instance.
 *    Prefers the plugin's own force_2fa_update_checker() accessor and falls back
 *    to walking the update-check hook, because the E2E's post-update half runs
 *    against the latest *released* plugin, which may predate the accessor.
 *  - When GITHUB_TOKEN is set, authenticate PUC's GitHub API calls (including
 *    the ones inside `wp plugin update`) so CI runs don't consume the anonymous
 *    per-IP rate limit shared across GitHub-hosted runners.
 *
 * @package force-email-two-factor
 */

/**
 * Find the Plugin Update Checker instance the site under test wired up.
 *
 * @return object|null The checker, or null when none is registered.
 */
function force2fa_e2e_update_checker() {
	if ( function_exists( 'force_2fa_update_checker' ) ) {
		$checker = force_2fa_update_checker();
		if ( $checker ) {
			return $checker;
		}
	}

	// Fallback for released plugin versions without the accessor.
	global $wp_filter;
	$hook      = isset( $wp_filter['site_transient_update_plugins'] ) ? $wp_filter['site_transient_update_plugins'] : null;
	$callbacks = is_object( $hook ) && isset( $hook->callbacks ) && is_array( $hook->callbacks ) ? $hook->callbacks : array();
	foreach ( $callbacks as $priority_callbacks ) {
		foreach ( $priority_callbacks as $registration ) {
			$callback = isset( $registration['function'] ) ? $registration['function'] : null;
			if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) && method_exists( $callback[0], 'checkForUpdates' ) ) {
				return $callback[0];
			}
		}
	}
	return null;
}

add_action(
	'plugins_loaded',
	function () {
		$token = getenv( 'GITHUB_TOKEN' );
		if ( ! $token ) {
			return;
		}
		$checker = force2fa_e2e_update_checker();
		if ( $checker && method_exists( $checker, 'setAuthentication' ) ) {
			$checker->setAuthentication( $token );
		}
	},
	20 // After the plugin wires PUC on plugins_loaded (default priority).
);
