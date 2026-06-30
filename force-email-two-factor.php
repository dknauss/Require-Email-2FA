<?php
/**
 * Plugin Name: Require Email 2FA
 * Description:      Requires the Two Factor plugin and makes emailed 2FA the default, required login factor for all users.
 * Author:           Dan Knauss
 * Version:          1.8.0
 * Network:          false
 * Requires PHP:     7.2
 * License:          GPL-2.0-or-later
 * License URI:      https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:      force-email-two-factor
 *
 * Soft dependency: the Two Factor plugin (slug "two-factor"). This plugin
 * activates on its own and then no-ops safely until Two Factor is active — every
 * enforcement guard below bails via force_2fa_dependency_met(). While Two Factor
 * is missing, an admin notice warns that 2FA is NOT being enforced and offers a
 * one-click install/activate from WordPress.org. (Earlier versions used the hard
 * "Requires Plugins" header, which blocked activation entirely; that was dropped
 * so first-run is a guided fix rather than a dead-end activation error.)
 * Recommended companion: "two-factor-provider-webauthn" (passkeys / hardware
 * keys) — optional; this plugin works without it. "wp-mail-logging" is only a
 * testing aid for reading 2FA codes without a real mail server.
 *
 * ---------------------------------------------------------------------------
 * INSTALLATION
 * ---------------------------------------------------------------------------
 * Install the folder at wp-content/plugins/force-email-two-factor/ and activate
 * it like any plugin. On multisite you can either:
 *
 *   - Network Activate (Network Admin → Plugins) to enforce across ALL sites.
 *     This is the robust security baseline and what we recommend.
 *   - Activate per-site (a single site's Plugins screen). NOTE: on multisite,
 *     users and their Two Factor settings are network-global, while this plugin
 *     only enforces when it is active in the CURRENT request's site context.
 *     Per-site activation therefore keys enforcement off the login entry point,
 *     not off the user — a global user could authenticate via a site where this
 *     is inactive and skip enforcement. Use per-site only for "this site's team
 *     must use 2FA"; use Network Activate for a true network-wide guarantee.
 *
 * Optional "cannot be deactivated" mode: copy mu-loader.php from this folder
 * into wp-content/mu-plugins/ (a flat file). It force-loads this plugin on every
 * request so it can't be turned off from the admin. Safe to combine with normal
 * activation — a re-load guard prevents double execution.
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

// Load-time guards and the load marker run once when the file is included, before
// PHPUnit starts measuring per-test coverage, so they are exempt from unit
// coverage. Their behaviour is exercised by the Playground integration test
// (real activation) and by the local multisite verification.
// @codeCoverageIgnoreStart

// If the emergency kill switch is set, register nothing at all and bail early.
// This is checked at load time so a broken-mail recovery is a single wp-config
// edit away, with no code changes here.
if ( defined( 'FORCE_2FA_DISABLE' ) && FORCE_2FA_DISABLE ) {
	return;
}

// Re-load guard: this file may be loaded both via the optional mu-loader and as
// a normally-activated plugin. Bail on the second load so the const/function
// below are never re-declared (which would be a fatal error).
if ( defined( 'FORCE_2FA_LOADED' ) ) {
	return;
}
define( 'FORCE_2FA_LOADED', '1.8.0' );
// @codeCoverageIgnoreEnd

/**
 * The Two Factor plugin's main file, relative to the plugins directory.
 *
 * Used both to detect an existing (possibly inactive) install on disk and as the
 * target passed to activate_plugin().
 */
const FORCE_2FA_TWO_FACTOR_PLUGIN_FILE = 'two-factor/two-factor.php';

/**
 * Whether the Two Factor dependency is present and loaded.
 *
 * Single source of truth for "can we enforce?": the enforcement filter and the
 * admin nag both key off this. We probe the Email provider class specifically —
 * the exact symbol the enforcement filter appends — so a "met" result guarantees
 * the provider we inject can actually be resolved.
 *
 * @return bool True when the Two Factor plugin's Email provider is available.
 */
function force_2fa_dependency_met() {
	return class_exists( 'Two_Factor_Email' );
}

/**
 * Whether to show the "dependency missing" admin notice.
 *
 * Pure decision split out from the notice glue so the gating is unit-tested: nag
 * only when the dependency is absent AND the current user could do something about
 * it (manage plugins). A user who can't manage plugins gets no actionable notice.
 *
 * @param bool $dependency_met  Result of force_2fa_dependency_met().
 * @param bool $user_can_manage Whether the current user can activate plugins.
 * @return bool True if the notice should be rendered.
 */
function force_2fa_should_nag( $dependency_met, $user_can_manage ) {
	return ! $dependency_met && (bool) $user_can_manage;
}

/**
 * The capability required to satisfy the dependency from the admin notice.
 *
 * If Two Factor is already on disk (just inactive) the user only needs to
 * activate it; otherwise an install is required, which is a higher bar
 * (network-admin-only on multisite). Split out so the authorization rule is
 * unit-tested independently of the install glue.
 *
 * @param bool $already_installed Whether two-factor/two-factor.php exists on disk.
 * @return string The required capability slug.
 */
function force_2fa_required_install_cap( $already_installed ) {
	return $already_installed ? 'activate_plugins' : 'install_plugins';
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
 * low-privilege role can never accidentally exempt a privileged account that
 * also holds a higher role.
 *
 * THREAT MODEL / WARNING: exclusions are configured in code (this constant or
 * the force_2fa_user_is_exempt filter), which requires filesystem-level access —
 * a trust level that can already disable 2FA entirely. So exclusions are not an
 * attacker-facing control; they are an operator convenience. There is no hard
 * floor protecting privileged accounts: if you exclude a role that a super admin
 * or administrator holds *as their only role on a site*, that account WILL be
 * exempted on that site. Choose excluded roles deliberately. To exempt or
 * re-include a specific account surgically, prefer the force_2fa_user_is_exempt
 * filter over broad role exclusions.
 *
 * Exclusion means "don't FORCE 2FA" — it does not forbid it. An excluded user
 * who configured their own 2FA keeps it.
 *
 * @var string[] Role slugs exempt from forced two-factor.
 */
const FORCE_2FA_EXCLUDED_ROLES = array();

/**
 * Effective list of excluded role slugs.
 *
 * Defaults to the FORCE_2FA_EXCLUDED_ROLES constant; the 'force_2fa_excluded_roles'
 * filter lets code override it at runtime (e.g. environment-specific config) and
 * makes the value injectable for unit tests.
 *
 * @return string[]
 */
function force_2fa_excluded_roles() {
	return (array) apply_filters( 'force_2fa_excluded_roles', FORCE_2FA_EXCLUDED_ROLES );
}

/**
 * Normalize a list of role or login strings from constants/filters.
 *
 * Configuration filters are operator-controlled, but defensive normalization
 * avoids PHP warnings if a filter returns null, a scalar, or a mixed array.
 *
 * @param mixed $values Raw config value.
 * @return string[] Lowercase scalar entries; non-scalar entries are ignored.
 */
function force_2fa_normalize_string_list( $values ) {
	$normalized = array();

	foreach ( (array) $values as $value ) {
		if ( is_scalar( $value ) ) {
			$value = strtolower( trim( (string) $value ) );

			if ( '' !== $value ) {
				$normalized[] = $value;
			}
		}
	}

	return $normalized;
}

/**
 * Whether a user is exempt from forced two-factor.
 *
 * Exempt only when the user has at least one role AND all of their roles are in
 * the excluded list. Users with no role are never exempted (fail secure). The
 * 'force_2fa_user_is_exempt' filter allows programmatic overrides for edge cases
 * (e.g. a specific user ID) without editing the role list.
 *
 * @param WP_User $user The resolved user.
 * @return bool True if forced 2FA should be skipped for this user.
 */
function force_2fa_user_is_exempt( WP_User $user ) {
	$excluded = force_2fa_normalize_string_list( force_2fa_excluded_roles() );
	$roles    = force_2fa_normalize_string_list( $user->roles );
	$exempt   = ! empty( $roles )
		&& ! empty( $excluded )
		&& empty( array_diff( $roles, $excluded ) );

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
 * @param int      $user_id           User ID.
 * @return string[] The enabled providers, guaranteed to include Two_Factor_Email
 *                  when that provider exists.
 */
function force_2fa_filter_enabled_providers( $enabled_providers, $user_id ) {
	// Plugin gone / provider unregistered: do not touch the list. (Defensive guard
	// for when the Two Factor plugin is absent; unit tests always have the provider
	// class present, so this safety branch stays uncovered by design.)
	if ( ! force_2fa_dependency_met() ) {
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
}

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
 * Effective API-login allowlist.
 *
 * Defaults to the FORCE_2FA_API_LOGIN_ALLOWLIST constant; the
 * 'force_2fa_api_login_allowlist' filter lets code override it at runtime and
 * makes the value injectable for unit tests.
 *
 * @return array<int|string>
 */
function force_2fa_api_login_allowlist() {
	return (array) apply_filters( 'force_2fa_api_login_allowlist', FORCE_2FA_API_LOGIN_ALLOWLIST );
}

/**
 * Decide whether a given user is on the API-login allowlist.
 *
 * Compares against both the numeric ID and the (lowercased) user_login so either
 * form may appear in the allowlist.
 *
 * @param WP_User $user The resolved user.
 * @return bool True if the user matches an allowlist entry.
 */
function force_2fa_user_is_api_allowlisted( WP_User $user ) {
	foreach ( force_2fa_api_login_allowlist() as $entry ) {
		if ( ! is_scalar( $entry ) ) {
			continue;
		}

		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			continue;
		}

		if ( ctype_digit( $entry ) ) {
			if ( (int) $entry === (int) $user->ID ) {
				return true;
			}
		} elseif ( strtolower( $entry ) === strtolower( $user->user_login ) ) {
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
function force_2fa_filter_api_login_enable( $enable, $user ) {
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
}
// The notice and the one-click installer are admin glue: they call WordPress
// admin/upgrader APIs that the zero-dependency unit bootstrap does not stub, and
// are only ever invoked through the admin hooks (never at load), so they cannot
// fatally a unit run. Their behaviour is exercised by the Playground integration
// test (a real activation with Two Factor absent), mirroring the load-time guards
// above. The pure decisions they delegate to — force_2fa_should_nag() and
// force_2fa_required_install_cap() — are unit-tested directly.
// @codeCoverageIgnoreStart

/**
 * Admin notice shown when Two Factor is not active.
 *
 * Replaces the old hard `Requires Plugins` activation gate: this plugin now
 * activates immediately and degrades to a no-op (the enforcement filter bails via
 * force_2fa_dependency_met()), so the notice must be loud — while it shows, NO
 * two-factor is being enforced. Offers a one-click install/activate of Two Factor
 * straight from the WordPress.org repository.
 */
function force_2fa_dependency_notice() {
	if ( ! force_2fa_should_nag( force_2fa_dependency_met(), current_user_can( 'activate_plugins' ) ) ) {
		return;
	}

	$action      = 'force_2fa_install_two_factor';
	$install_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a href="%3$s" class="button button-primary">%4$s</a> &nbsp; <a href="%5$s" target="_blank" rel="noopener noreferrer">%6$s</a></p></div>',
		esc_html__( 'Require Email 2FA is not enforcing yet.', 'force-email-two-factor' ),
		esc_html__( 'It needs the Two Factor plugin to be installed and active. Until then, two-factor is NOT being enforced for any user.', 'force-email-two-factor' ),
		esc_url( $install_url ),
		esc_html__( 'Install &amp; activate Two Factor', 'force-email-two-factor' ),
		esc_url( 'https://wordpress.org/plugins/two-factor/' ),
		esc_html__( 'Learn more', 'force-email-two-factor' )
	);
}

/**
 * Handle the one-click "Install & activate Two Factor" action.
 *
 * Installs the plugin from the WordPress.org repository if it is not already on
 * disk, then activates it, then returns to the Plugins screen (where the now-met
 * dependency makes the notice disappear). Capability is checked against the
 * minimum the situation requires (activate vs. install) and the nonce is verified
 * before anything runs. On any upgrader error we wp_die() with WordPress's
 * standard back-linked error page rather than leaving the user on a blank screen.
 */
function force_2fa_handle_install_two_factor() {
	check_admin_referer( 'force_2fa_install_two_factor' );

	$plugin_file = FORCE_2FA_TWO_FACTOR_PLUGIN_FILE;
	$installed   = file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );

	if ( ! current_user_can( force_2fa_required_install_cap( $installed ) ) ) {
		wp_die( esc_html__( 'You do not have permission to install or activate plugins.', 'force-email-two-factor' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	if ( ! $installed ) {
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => 'two-factor',
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			wp_die( esc_html( $api->get_error_message() ) );
		}

		// WP_Ajax_Upgrader_Skin keeps the upgrader silent — no HTML echoed into our
		// redirect response — while still collecting any errors.
		$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
	}

	$activated = activate_plugin( $plugin_file );
	if ( is_wp_error( $activated ) ) {
		wp_die( esc_html( $activated->get_error_message() ) );
	}

	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
}
// @codeCoverageIgnoreEnd

/**
 * Register this plugin's WordPress hooks.
 *
 * Called once at load (below); also unit-tested directly, so the registrations
 * are exercised under coverage rather than only at include time.
 */
function force_2fa_register_hooks() {
	add_filter( 'two_factor_enabled_providers_for_user', 'force_2fa_filter_enabled_providers', 10, 2 );
	add_filter( 'two_factor_user_api_login_enable', 'force_2fa_filter_api_login_enable', 10, 2 );

	// Soft-dependency UX: nag + one-click installer when Two Factor is absent.
	add_action( 'admin_notices', 'force_2fa_dependency_notice' );
	add_action( 'admin_post_force_2fa_install_two_factor', 'force_2fa_handle_install_two_factor' );
}

force_2fa_register_hooks();

/*
 * Optional, stronger hardening — disable XML-RPC entirely.
 *
 * Uncomment ONLY if nothing legitimately uses XML-RPC (the Jetpack/WordPress
 * mobile apps and some remote-publishing/pingback tools do). The API-login
 * filter above already forces 2FA on XML-RPC logins for non-allowlisted users
 * without breaking the endpoint, so prefer leaving this off unless you have a
 * specific reason to shut XML-RPC down completely.
 */
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional opt-in example, left commented by design.
// add_filter( 'xmlrpc_enabled', '__return_false' );
