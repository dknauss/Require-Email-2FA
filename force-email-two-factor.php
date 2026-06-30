<?php
/**
 * Plugin Name: Force Email Two-Factor (Enforcement)
 * Description: Requires the Two Factor plugin's Email provider for every user, so
 *              two-factor is mandatory at login while stronger factors (TOTP,
 *              hardware keys) and backup codes remain available. Enforcement
 *              defaults to ALL users, with optional per-role exclusions.
 *              Restricts the XML-RPC / REST API-login path to an allowlist of
 *              service accounts, and only when they authenticate with an
 *              Application Password.
 * Author:      Dan Knauss
 * Version:     1.3.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * ---------------------------------------------------------------------------
 * INSTALLATION
 * ---------------------------------------------------------------------------
 * Drop this file directly into wp-content/mu-plugins/ as a FLAT .php file.
 * Must-use plugins in subdirectories are NOT auto-loaded, so it has to sit at
 * wp-content/mu-plugins/force-email-two-factor.php (not in a nested folder).
 *
 * Requires the Two Factor plugin: https://wordpress.org/plugins/two-factor/
 * If that plugin is inactive, every guard below no-ops safely (see notes).
 *
 * ---------------------------------------------------------------------------
 * EMERGENCY KILL SWITCH
 * ---------------------------------------------------------------------------
 * Email 2FA depends on outbound mail. If mail delivery breaks, every user who
 * has no stronger factor configured can be locked out. To disable ALL
 * enforcement in this file without deleting it, add to wp-config.php:
 *
 *     define( 'FORCE_2FA_DISABLE', true );
 *
 * Keep a known-good admin session or printed backup codes on hand the first
 * time you activate this, in case mail is misconfigured.
 * ---------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

// If the emergency kill switch is set, register nothing at all and bail early.
// This is checked at load time so a broken-mail recovery is a single wp-config
// edit away, with no code changes here.
if ( defined( 'FORCE_2FA_DISABLE' ) && FORCE_2FA_DISABLE ) {
	return;
}

/**
 * Roles to EXCLUDE from forced two-factor.
 *
 * Default is an empty array → enforcement applies to ALL users. Add role slugs
 * (the lowercase keys, e.g. 'subscriber', 'customer', not display names) to
 * exempt those roles from having Email auto-enabled:
 *
 *     const FORCE_2FA_EXCLUDED_ROLES = array( 'subscriber', 'customer' );
 *
 * Security rule (see force_2fa_user_is_exempt): a user is exempt ONLY if EVERY
 * role they hold is on this list. A user with both an excluded role and a
 * non-excluded one (e.g. subscriber + editor) is still enforced, so excluding a
 * low-privilege role can never accidentally exempt a privileged account.
 *
 * Exclusion means "don't FORCE 2FA" — it does not forbid it. An excluded user
 * who configured their own 2FA keeps it.
 *
 * @var string[] Role slugs exempt from forced two-factor.
 */
const FORCE_2FA_EXCLUDED_ROLES = array();

/**
 * Whether a user is exempt from forced two-factor.
 *
 * Exempt only when the user has at least one role AND all of their roles are in
 * FORCE_2FA_EXCLUDED_ROLES. Users with no role are never exempted (fail secure).
 * The 'force_2fa_user_is_exempt' filter allows programmatic overrides for edge
 * cases (e.g. a specific user ID) without editing the role list.
 *
 * @param WP_User $user The resolved user.
 * @return bool True if forced 2FA should be skipped for this user.
 */
function force_2fa_user_is_exempt( WP_User $user ) {
	$roles  = array_map( 'strtolower', (array) $user->roles );
	$exempt = ! empty( $roles )
		&& ! empty( FORCE_2FA_EXCLUDED_ROLES )
		&& empty( array_diff( $roles, array_map( 'strtolower', FORCE_2FA_EXCLUDED_ROLES ) ) );

	/**
	 * Filter the per-user exemption from forced two-factor.
	 *
	 * @param bool    $exempt Whether the user is exempt based on roles.
	 * @param WP_User $user   The user being evaluated.
	 */
	return (bool) apply_filters( 'force_2fa_user_is_exempt', $exempt, $user );
}

/**
 * Make two-factor mandatory by ensuring the Email provider is enabled for every
 * user (except excluded roles — see FORCE_2FA_EXCLUDED_ROLES).
 *
 * Why this works: the Two Factor plugin treats a user as "using 2FA" whenever
 * they have at least one available provider. Two_Factor_Email::is_available_for_user()
 * returns true unconditionally and needs no per-user setup (it just mails a code
 * to the account address), so adding it as a floor forces the login challenge
 * for everyone — including users who never configured anything.
 *
 * Why APPEND (not replace): returning array( 'Two_Factor_Email' ) would strip
 * each user's stronger factors (TOTP, hardware keys) AND their backup codes on
 * every read, forcing the whole site down to email-only and removing the
 * recovery path. Appending instead guarantees an email floor while leaving any
 * stronger, user-chosen factor in place and primary.
 *
 * Fail-safe: if the Email provider class is absent (plugin inactive/removed) we
 * return the list untouched. We never silently delete an existing factor, and
 * we never inject a provider key the plugin can't resolve.
 *
 * @param string[] $enabled_providers Provider class-name keys enabled for the user.
 * @param int      $user_id           User ID (unused — policy is uniform).
 * @return string[] The enabled providers, guaranteed to include Two_Factor_Email
 *                  when that provider exists.
 */
add_filter(
	'two_factor_enabled_providers_for_user',
	function ( $enabled_providers, $user_id ) {
		// Plugin gone / provider unregistered: do not touch the list.
		if ( ! class_exists( 'Two_Factor_Email' ) ) {
			return $enabled_providers;
		}

		// Excluded roles: don't force Email (their own 2FA, if any, is untouched).
		$user = get_userdata( $user_id );
		if ( $user && force_2fa_user_is_exempt( $user ) ) {
			return $enabled_providers;
		}

		// Stored user meta is normally an array, but guard against malformed values.
		if ( ! is_array( $enabled_providers ) ) {
			$enabled_providers = array();
		}

		// Strict in_array(): these are class-name strings, so avoid loose matching.
		if ( ! in_array( 'Two_Factor_Email', $enabled_providers, true ) ) {
			$enabled_providers[] = 'Two_Factor_Email';
		}

		return $enabled_providers;
	},
	10,
	2
);

/**
 * Service-account allowlist for non-interactive API logins.
 *
 * Background — what the API-login path is and how the plugin already guards it:
 * the interactive wp_login 2FA challenge does NOT cover XML-RPC or REST logins.
 * For those paths, the Two Factor plugin's default policy is to allow a login to
 * skip 2FA ONLY when the request authenticated via an Application Password (it
 * keys off did_action('application_password_did_authenticate')). A plain
 * real-password login over XML-RPC/REST is therefore already blocked for any
 * 2FA-enabled user. Our enforcement filter above makes every user 2FA-enabled,
 * so that default applies site-wide.
 *
 * What THIS allowlist adds: the plugin's default still lets ANY user log in via
 * the API as long as they present an Application Password. We tighten that to a
 * named set of service accounts — non-human integrations that cannot present an
 * emailed code, e.g.:
 *
 *   - Headless / JAMstack frontends reading content over the REST API
 *   - CI/CD or deploy pipelines hitting authenticated REST endpoints
 *   - Automation platforms (Zapier / Make / n8n / IFTTT) posting via REST
 *   - Backup, migration, or uptime-monitoring tools
 *   - The Jetpack / WordPress mobile apps (XML-RPC)
 *
 * The resulting policy is the INTERSECTION of two conditions (see filter below):
 *   (a) the account is on this allowlist, AND
 *   (b) THIS request authenticated with an Application Password.
 * So an allowlisted account that tries its real login password over the API is
 * still denied, and a non-allowlisted account is denied even with an app password.
 *
 * Each entry is EITHER a numeric user ID (int or numeric string) OR a user_login
 * (matched case-insensitively). Prefer IDs — they don't change if a login is
 * renamed.
 *
 * Hardening expectations for any account you add here:
 *   1. It authenticates with an Application Password (Users → Profile → Application
 *      Passwords), NOT the real login password. App passwords are per-integration,
 *      individually revocable, and never satisfy condition (b) when the real
 *      password is used instead.
 *   2. It has the least-privilege role the integration actually needs.
 *   3. You remove it from this list the moment the integration is retired.
 *
 * Leave the array EMPTY to deny ALL API logins (no service accounts permitted).
 *
 * @var array<int|string> User IDs and/or user_login values permitted on the API path.
 */
const FORCE_2FA_API_LOGIN_ALLOWLIST = array(
	// 123,            // by user ID (preferred — stable across login renames)
	// 'svc_headless', // by user_login (case-insensitive)
);

/**
 * Decide whether a given user is on the API-login allowlist.
 *
 * Compares against both the numeric ID and the (lowercased) user_login so either
 * form may appear in FORCE_2FA_API_LOGIN_ALLOWLIST.
 *
 * @param WP_User $user The resolved user.
 * @return bool True if the user matches an allowlist entry.
 */
function force_2fa_user_is_api_allowlisted( WP_User $user ) {
	foreach ( FORCE_2FA_API_LOGIN_ALLOWLIST as $entry ) {
		if ( is_int( $entry ) || ctype_digit( (string) $entry ) ) {
			if ( (int) $entry === (int) $user->ID ) {
				return true;
			}
		} elseif ( strtolower( (string) $entry ) === strtolower( $user->user_login ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Permit an API login to skip the second factor only when BOTH:
 *   (a) the user is an allowlisted service account, AND
 *   (b) this request authenticated via an Application Password.
 *
 * Condition (b) reuses the exact signal the plugin itself uses as its default:
 * did_action('application_password_did_authenticate'). Our enforcement filter
 * runs at priority 31 on 'authenticate', after core's application-password
 * handler at priority 20, so this marker is reliably set by the time we run.
 *
 * @param bool             $enable Plugin's default (true iff an app password was used).
 * @param WP_User|int|null $user   The authenticating user (object or ID).
 * @return bool True only for an allowlisted account using an Application Password.
 */
add_filter(
	'two_factor_user_api_login_enable',
	function ( $enable, $user ) {
		unset( $enable ); // We recompute the decision from scratch below.

		// Resolve to a WP_User whether an object or an ID was passed.
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( (int) $user );
		}

		if ( ! $user || empty( $user->user_login ) ) {
			return false; // Unknown user → deny the API bypass.
		}

		// (b) Require an Application Password for this request — a real-password
		// API login never satisfies this, so leaked passwords can't be used here.
		if ( ! did_action( 'application_password_did_authenticate' ) ) {
			return false;
		}

		// (a) ...and only for named service accounts.
		return force_2fa_user_is_api_allowlisted( $user );
	},
	10,
	2
);

/*
 * Optional, stronger hardening — disable XML-RPC entirely.
 *
 * Uncomment ONLY if nothing legitimately uses XML-RPC (the Jetpack/WordPress
 * mobile apps and some remote-publishing/pingback tools do). The API-login
 * filter above already forces 2FA on XML-RPC logins for non-allowlisted users
 * without breaking the endpoint, so prefer leaving this off unless you have a
 * specific reason to shut XML-RPC down completely.
 */
// add_filter( 'xmlrpc_enabled', '__return_false' );
