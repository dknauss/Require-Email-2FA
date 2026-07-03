<?php
/**
 * Plugin Name:      Require Email 2FA
 * Plugin URI:       https://github.com/dknauss/Require-Email-2FA
 * Update URI:       https://github.com/dknauss/Require-Email-2FA
 * Description:      Requires the Two Factor plugin and makes emailed 2FA the default, required login factor for all users.
 * Author:           Pixel
 * Author URI:       https://wearepixel.ca
 * Version:          1.10.1
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
 * it like any plugin. On MULTISITE it is network-only: it must be Network
 * Activated (Network Admin → Plugins) and per-site activation is refused. This is
 * deliberate — enforcement keys off whether the plugin (and Two Factor) is active
 * in the current request's site context, so a per-site install would leave gaps a
 * network-global user could slip through. force_2fa_block_single_site_activation()
 * (a register_activation_hook guard) rolls back and refuses any per-site activation
 * with a "must be Network Activated" notice, covering the admin UI and WP-CLI /
 * programmatic paths alike. (A "Network: true" header is intentionally avoided: it
 * would make core silently promote a per-site activation to network-wide rather
 * than refuse it — see force_2fa_block_single_site_activation().)
 *
 * For a true network-wide guarantee Two Factor must ALSO be network-active; if it
 * is only site-active (or absent) on some sites, enforcement silently no-ops
 * there. The Network Admin notice warns when Two Factor is not network-active.
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
 * SELF-HOSTED UPDATES (and forking)
 * ---------------------------------------------------------------------------
 * This plugin is distributed from GitHub, not WordPress.org. The "Update URI"
 * header above is the single source of truth for updates: WordPress core (5.8+)
 * reads it and refuses to let WordPress.org serve updates for this slug (so a
 * same-named .org plugin can never hijack it), and force_2fa_bootstrap_self_update()
 * feeds it to Plugin Update Checker to pull new versions from that repo's Releases.
 *
 * TO FORK: point the "Update URI" header at your own repository. That one change
 * redirects both the updater and core's update-ownership to your fork. (Leaving it
 * on the upstream repo would auto-update every site back to upstream — do not.)
 * Everything else (slug, download asset) derives from the plugin folder name; only
 * a rename also needs the release workflow's PLUGIN_SLUG updated to match.
 *
 * TO DISABLE self-update (e.g. sites patched by a central manager such as MainWP,
 * Composer, or git deploys) add to wp-config.php:
 *
 *     define( 'FORCE_2FA_DISABLE_SELF_UPDATE', true );
 *
 * The plugin then never polls GitHub or self-installs; your pipeline delivers
 * updates. Enforcement is unchanged. See docs/DEPLOYMENT.md for the managed vs.
 * standalone deployment guide.
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
define( 'FORCE_2FA_LOADED', '1.10.1' );
// @codeCoverageIgnoreEnd

/**
 * The Two Factor plugin's main file, relative to the plugins directory.
 *
 * Used both to detect an existing (possibly inactive) install on disk and as the
 * target passed to activate_plugin().
 */
const FORCE_2FA_TWO_FACTOR_PLUGIN_FILE = 'two-factor/two-factor.php';

/**
 * Whether the Two Factor dependency is present and usable.
 *
 * Single source of truth for "can we enforce?": the enforcement filter and the
 * admin nag both key off this. We confirm Two Factor actually *registers* the Email
 * provider — via Two_Factor_Core::get_providers() — not merely that the class is
 * loadable. That closes the edge case where another plugin removes Email from the
 * 'two_factor_providers' registry: the class would still exist, but the provider we
 * inject could not resolve, so enforcement would silently no-op. Requiring
 * registration means a "met" result really does mean the injected provider is usable.
 *
 * @return bool True when Two Factor registers the Email provider.
 */
function force_2fa_dependency_met() {
	if ( ! class_exists( 'Two_Factor_Core' ) || ! class_exists( 'Two_Factor_Email' ) ) {
		return false;
	}

	$providers = Two_Factor_Core::get_providers();

	return is_array( $providers ) && array_key_exists( 'Two_Factor_Email', $providers );
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
 * Whether to show the Network Admin "dependency missing" notice on multisite.
 *
 * Only relevant when THIS plugin is network-active (the only supported multisite
 * mode). Nag when Two Factor is NOT network-active — so enforcement is not truly
 * network-wide — and the current user can manage network plugins. Pure decision,
 * unit-tested; the notice/install glue delegates to it.
 *
 * @param bool $self_network_active     Whether Require Email 2FA is network-active.
 * @param bool $dependency_met_network  Whether Two Factor is network-active.
 * @param bool $user_can_manage_network Whether the user can manage network plugins.
 * @return bool True if the network notice should render.
 */
function force_2fa_should_nag_network( $self_network_active, $dependency_met_network, $user_can_manage_network ) {
	return (bool) $self_network_active && ! $dependency_met_network && (bool) $user_can_manage_network;
}

/**
 * The capabilities required to satisfy the dependency from the admin notice.
 *
 * Returns EVERY capability the action needs, so they are checked independently
 * (setups can grant network plugin management while withholding install_plugins):
 *   - installing a missing plugin always requires install_plugins;
 *   - network activation requires manage_network_plugins;
 *   - single-site activation requires activate_plugins.
 * Split out so the authorization rule is unit-tested independently of the glue.
 *
 * @param bool $already_installed Whether two-factor/two-factor.php exists on disk.
 * @param bool $network           Whether this is the network-wide (multisite) path.
 * @return string[] All required capability slugs.
 */
function force_2fa_required_install_caps( $already_installed, $network = false ) {
	$caps = array();
	if ( ! $already_installed ) {
		$caps[] = 'install_plugins';
	}
	$caps[] = $network ? 'manage_network_plugins' : 'activate_plugins';
	return $caps;
}

/**
 * Button label for the dependency notice's one-click action, by state.
 *
 * The action installs Two Factor only when it is not already on disk; when it is
 * present-but-inactive the action just activates it — so the label says "Activate"
 * rather than always "Install & activate". Pure decision, unit-tested; the notice
 * glue renders it (see force_2fa_dependency_notice / _network_dependency_notice).
 *
 * @param bool $installed Whether two-factor/two-factor.php is present on disk.
 * @param bool $network   Whether this is the network-wide (multisite) action.
 * @return string Translated button label.
 */
function force_2fa_dependency_action_label( $installed, $network ) {
	if ( $network ) {
		return $installed
			? __( 'Network-activate Two Factor', 'force-email-two-factor' )
			: __( 'Install & network-activate Two Factor', 'force-email-two-factor' );
	}
	return $installed
		? __( 'Activate Two Factor', 'force-email-two-factor' )
		: __( 'Install & activate Two Factor', 'force-email-two-factor' );
}

/**
 * Body copy for the actionable dependency notice, by state.
 *
 * Mirrors force_2fa_dependency_action_label(): when Two Factor is present but
 * inactive the copy says so and asks to activate it, instead of telling the admin
 * to install a plugin that is already there. Pure decision, unit-tested.
 *
 * @param bool $installed Whether two-factor/two-factor.php is present on disk.
 * @param bool $network   Whether this is the network-wide (multisite) action.
 * @return string Translated body sentence(s).
 */
function force_2fa_dependency_action_body( $installed, $network ) {
	if ( $network ) {
		return $installed
			? __( 'It is network-active, but the Two Factor plugin is installed but not network-active — so 2FA is not enforced on sites where Two Factor is inactive. Network-activate Two Factor to close the gap.', 'force-email-two-factor' )
			: __( 'It is network-active, but the Two Factor plugin is not — so 2FA is not enforced on sites where Two Factor is inactive. Install and network-activate Two Factor to close the gap.', 'force-email-two-factor' );
	}
	return $installed
		? __( 'The Two Factor plugin is installed but not active. Activate it to start enforcing 2FA for all users.', 'force-email-two-factor' )
		: __( 'It needs the Two Factor plugin to be installed and active. Until then, 2FA is not enforced for any user.', 'force-email-two-factor' );
}

/**
 * Body copy for the multisite per-site heads-up notice, by installed state.
 *
 * Shown to a site admin on a subsite where Two Factor is not active. It is
 * informational (they cannot network-activate it themselves — it points them to
 * the network admin), but it still reflects whether Two Factor is installed on the
 * network but inactive here, versus not installed at all. Pure decision, unit-tested.
 *
 * @param bool $installed Whether two-factor/two-factor.php is present on disk.
 * @return string Translated body sentence(s).
 */
function force_2fa_dependency_heads_up_body( $installed ) {
	return $installed
		? __( 'The Two Factor plugin is installed but not active on this site, so 2FA is not enforced here. Ask your network administrator to network-activate Two Factor.', 'force-email-two-factor' )
		: __( 'The Two Factor plugin is not installed, so 2FA is not enforced on this site. Ask your network administrator to install and network-activate Two Factor.', 'force-email-two-factor' );
}

/**
 * Classify why the Two Factor dependency is unmet, for the notice copy.
 *
 * Called only when force_2fa_dependency_met() is already false. Distinguishes the
 * three reasons so a notice never offers a fix that can't work:
 *   - 'absent'   — not installed on disk (needs install + activate),
 *   - 'inactive' — installed but not active (needs activate),
 *   - 'unusable' — Two Factor is loaded/active but its Email provider is
 *                  unregistered (activation is a no-op; the provider must be
 *                  restored). This is why $tf_loaded wins over $installed.
 * Pure decision, unit-tested.
 *
 * @param bool $installed Whether two-factor/two-factor.php is present on disk.
 * @param bool $tf_loaded Whether Two_Factor_Core is loaded (Two Factor active here).
 * @return string One of 'absent', 'inactive', 'unusable'.
 */
function force_2fa_dependency_state( $installed, $tf_loaded ) {
	if ( $tf_loaded ) {
		return 'unusable';
	}
	return $installed ? 'inactive' : 'absent';
}

/**
 * Whether this plugin runs network-wide on multisite.
 *
 * True when it is formally network-active OR mu-loaded — an mu-loader install runs
 * on every site but appears in neither the site nor the network active-plugins
 * list, so it is effectively network-wide and must get the Network Admin notice.
 * A per-site activation is NOT network-wide. Pure decision, unit-tested.
 *
 * @param bool $is_multisite    Whether this is a multisite network.
 * @param bool $network_active  Whether it is in the network active-plugins list.
 * @param bool $per_site_active Whether it is in the current site's active-plugins list.
 * @return bool
 */
function force_2fa_is_effectively_network_wide( $is_multisite, $network_active, $per_site_active ) {
	return (bool) $is_multisite && ( (bool) $network_active || ! (bool) $per_site_active );
}

/**
 * Whether a plugin activation attempt should be blocked.
 *
 * On multisite this plugin is network-only: a per-site activation ($network_wide
 * false) is refused so enforcement can't be left with per-site gaps a
 * network-global user could slip through. On single-site there is nothing to
 * block. Pure decision; force_2fa_block_single_site_activation() is the glue that
 * rolls back and wp_die()s on a true block.
 *
 * @param bool $is_multisite Whether this is a multisite network.
 * @param bool $network_wide Whether the activation is network-wide.
 * @return bool True if the activation must be blocked.
 */
function force_2fa_activation_blocked( $is_multisite, $network_wide ) {
	return (bool) $is_multisite && ! $network_wide;
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
	// for when the Two Factor plugin is absent or has had the Email provider
	// unregistered; covered by EnabledProvidersFilterTest via the __force2fa_providers
	// stub.)
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
 * Record the user that authenticated via an Application Password this request.
 *
 * Hooked to 'application_password_did_authenticate', which passes the WP_User core
 * just authenticated. force_2fa_filter_api_login_enable() compares this to the user
 * it is asked about, so the API bypass is bound to the account that actually
 * presented the Application Password — not merely "some app-password auth happened
 * in this request".
 *
 * @param WP_User|mixed $user The user WordPress authenticated via Application Password.
 */
function force_2fa_note_app_password_user( $user ) {
	$GLOBALS['force_2fa_app_password_user_id'] = ( $user instanceof WP_User ) ? (int) $user->ID : (int) $user;
}

/**
 * The user ID that authenticated via an Application Password this request, or 0.
 *
 * @return int User ID, or 0 when no Application Password authenticated this request.
 */
function force_2fa_app_password_user_id() {
	return isset( $GLOBALS['force_2fa_app_password_user_id'] ) ? (int) $GLOBALS['force_2fa_app_password_user_id'] : 0;
}

/**
 * Permit an API login to skip the second factor only when BOTH:
 *   (a) the user is an allowlisted service account, AND
 *   (b) THIS user authenticated via an Application Password this request.
 *
 * Condition (b) is bound to the specific account via force_2fa_app_password_user_id()
 * (captured from 'application_password_did_authenticate'), not the request-global
 * did_action() signal Two Factor uses by default — so an app-password auth for some
 * other account in the same request can't unlock the bypass for this one. The
 * capture runs on 'application_password_did_authenticate', which core fires while
 * authenticating the request; Two Factor evaluates 'two_factor_user_api_login_enable'
 * afterward, during its API-login gating — so the authenticating user's ID is already
 * recorded by the time this filter runs. Both hooks are registered at the default
 * priority 10 (see force_2fa_register_hooks); the ordering comes from the stages
 * (authentication before API-login gating), not from priorities.
 *
 * @param bool             $enable Ignored; the decision is recomputed here.
 * @param WP_User|int|null $user   The authenticating user (object or ID).
 * @return bool True only for an allowlisted account that used an Application Password.
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

	// (b) THIS user must have authenticated via an Application Password this request.
	// A real-password API login never records a user, and an app-password auth for a
	// different account does not count — so a leaked password can't be used here.
	$app_password_user_id = force_2fa_app_password_user_id();
	if ( 0 === $app_password_user_id || (int) $user->ID !== $app_password_user_id ) {
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
// force_2fa_required_install_caps() — are unit-tested directly.
// @codeCoverageIgnoreStart

/**
 * Whether the current user can complete the one-click dependency install/activate.
 *
 * Mirrors force_2fa_handle_install_two_factor(): the user must hold EVERY capability
 * the situation requires — install_plugins when Two Factor is not on disk, plus the
 * relevant activate capability. The notices use this to decide whether to render the
 * action button, so a user who would only hit the handler's permission wall never
 * sees a button that does nothing.
 *
 * @param bool $network Whether the dependency is resolved network-wide.
 * @return bool True when the current user holds every required capability.
 */
function force_2fa_current_user_can_install_dependency( $network ) {
	$installed = file_exists( WP_PLUGIN_DIR . '/' . FORCE_2FA_TWO_FACTOR_PLUGIN_FILE );

	foreach ( force_2fa_required_install_caps( $installed, $network ) as $required_cap ) {
		if ( ! current_user_can( $required_cap ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Per-site admin notice shown when Two Factor is not active.
 *
 * Two shapes, by context:
 *   - Single-site WordPress: the ACTIONABLE install/activate notice (per-site is
 *     the mode) — a one-click install/activate of Two Factor from WordPress.org.
 *   - Multisite (this plugin is network-only, so it is network-active here): a
 *     NON-actionable heads-up on any site where Two Factor isn't loaded, telling
 *     the site admin that enforcement is off here and to contact the network
 *     admin. The actionable fix lives in force_2fa_network_dependency_notice().
 */
function force_2fa_dependency_notice() {
	// Single site: actionable install/activate.
	if ( ! is_multisite() ) {
		if ( ! force_2fa_should_nag( force_2fa_dependency_met(), current_user_can( 'activate_plugins' ) ) ) {
			return;
		}

		$installed = file_exists( WP_PLUGIN_DIR . '/' . FORCE_2FA_TWO_FACTOR_PLUGIN_FILE );

		// Two Factor is loaded but the dependency is still unmet: its Email provider
		// is unregistered, so activating it again is a no-op. Point to restoring the
		// provider instead of offering a button that can't fix it.
		if ( 'unusable' === force_2fa_dependency_state( $installed, class_exists( 'Two_Factor_Core' ) ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'The Require Email 2FA plugin is not enforcing email 2FA yet.', 'force-email-two-factor' ),
				esc_html__( 'The Two Factor plugin is active, but its Email provider is not available, so email 2FA cannot be enforced. Restore the Email provider — it may have been removed by another plugin or a two_factor_providers filter.', 'force-email-two-factor' )
			);
			return;
		}

		// Only offer the one-click button when the user holds every capability its
		// handler requires; otherwise inform without a button that would be denied.
		if ( force_2fa_current_user_can_install_dependency( false ) ) {
			$action      = 'force_2fa_install_two_factor';
			$install_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );

			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a href="%3$s" class="button button-primary">%4$s</a> &nbsp; <a href="%5$s" target="_blank" rel="noopener noreferrer">%6$s</a></p></div>',
				esc_html__( 'The Require Email 2FA plugin is not enforcing email 2FA yet.', 'force-email-two-factor' ),
				esc_html( force_2fa_dependency_action_body( $installed, false ) ),
				esc_url( $install_url ),
				esc_html( force_2fa_dependency_action_label( $installed, false ) ),
				esc_url( 'https://wordpress.org/plugins/two-factor/' ),
				esc_html__( 'Learn more', 'force-email-two-factor' )
			);
		} else {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'The Require Email 2FA plugin is not enforcing email 2FA yet.', 'force-email-two-factor' ),
				esc_html__( 'It needs the Two Factor plugin installed and active. Ask an administrator who can install and activate plugins to add Two Factor. Until then, 2FA is not enforced for any user.', 'force-email-two-factor' )
			);
		}
		return;
	}

	// Multisite: non-actionable heads-up on sites where Two Factor isn't loaded.
	if ( ! force_2fa_should_nag( force_2fa_dependency_met(), current_user_can( 'manage_options' ) ) ) {
		return;
	}

	$installed = file_exists( WP_PLUGIN_DIR . '/' . FORCE_2FA_TWO_FACTOR_PLUGIN_FILE );

	// Same unusable case on a subsite: Two Factor is active here but its Email
	// provider is gone, so "activate it" would be wrong.
	if ( 'unusable' === force_2fa_dependency_state( $installed, class_exists( 'Two_Factor_Core' ) ) ) {
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'The Require Email 2FA plugin is not enforcing email 2FA on this site.', 'force-email-two-factor' ),
			esc_html__( 'The Two Factor plugin is active here, but its Email provider is not available, so email 2FA cannot be enforced on this site. Ask your network administrator to restore the Email provider.', 'force-email-two-factor' )
		);
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'The Require Email 2FA plugin is not enforcing email 2FA on this site.', 'force-email-two-factor' ),
		esc_html( force_2fa_dependency_heads_up_body( $installed ) )
	);
}

/**
 * Whether Require Email 2FA runs network-wide (multisite): network-active or mu-loaded.
 *
 * @return bool
 */
function force_2fa_self_network_active() {
	if ( ! is_multisite() ) {
		return false;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$self = plugin_basename( __FILE__ );
	return force_2fa_is_effectively_network_wide(
		true,
		is_plugin_active_for_network( $self ),
		in_array( $self, (array) get_option( 'active_plugins', array() ), true )
	);
}

/**
 * Whether the Two Factor dependency is active NETWORK-WIDE.
 *
 * Distinct from force_2fa_dependency_met() (which only sees the current request):
 * for the Network Admin view we care whether Two Factor is guaranteed on every
 * site, i.e. network-active.
 *
 * @return bool
 */
function force_2fa_dependency_met_network() {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active_for_network( FORCE_2FA_TWO_FACTOR_PLUGIN_FILE );
}

/**
 * Network Admin notice: Two Factor is not network-active.
 *
 * The actionable counterpart to the per-site heads-up. Offers a one-click install
 * + NETWORK-activate of Two Factor so the super admin closes the gap in one place.
 */
function force_2fa_network_dependency_notice() {
	if ( ! force_2fa_should_nag_network(
		force_2fa_self_network_active(),
		force_2fa_dependency_met_network(),
		current_user_can( 'manage_network_plugins' )
	) ) {
		return;
	}

	// Only offer the one-click button when the user holds every capability its
	// handler requires; otherwise inform without a button that would be denied.
	if ( force_2fa_current_user_can_install_dependency( true ) ) {
		$installed   = file_exists( WP_PLUGIN_DIR . '/' . FORCE_2FA_TWO_FACTOR_PLUGIN_FILE );
		$action      = 'force_2fa_install_two_factor';
		$install_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . $action ), $action );

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a href="%3$s" class="button button-primary">%4$s</a> &nbsp; <a href="%5$s" target="_blank" rel="noopener noreferrer">%6$s</a></p></div>',
			esc_html__( 'The Require Email 2FA plugin is not enforcing email 2FA network-wide.', 'force-email-two-factor' ),
			esc_html( force_2fa_dependency_action_body( $installed, true ) ),
			esc_url( $install_url ),
			esc_html( force_2fa_dependency_action_label( $installed, true ) ),
			esc_url( 'https://wordpress.org/plugins/two-factor/' ),
			esc_html__( 'Learn more', 'force-email-two-factor' )
		);
	} else {
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'The Require Email 2FA plugin is not enforcing email 2FA network-wide.', 'force-email-two-factor' ),
			esc_html__( 'It is network-active, but the Two Factor plugin is not. Ask an administrator who can install and network-activate plugins to add Two Factor so 2FA is enforced on every site.', 'force-email-two-factor' )
		);
	}
}

/**
 * Refuse per-site activation on multisite (this plugin is network-only).
 *
 * Registered via register_activation_hook(); receives $network_wide. On multisite
 * a per-site activation ($network_wide false) is rolled back and refused with a
 * notice, so the plugin can only ever run network-wide and enforcement can't be
 * left with per-site gaps a network-global user could slip through. Network
 * activation ($network_wide true) passes through. This is the PRIMARY enforcement
 * and also catches WP-CLI / programmatic per-site activation.
 *
 * NOTE: a "Network: true" header is deliberately NOT used. Core silently PROMOTES
 * a per-site activation of a network-only plugin to network-wide before this hook
 * runs, so the header would never refuse a per-site attempt — it would roll 2FA
 * out network-wide unexpectedly instead. Explicit rejection here is what makes the
 * "must be Network Activated" behavior actually happen.
 *
 * @param bool $network_wide Whether the activation is network-wide.
 */
function force_2fa_block_single_site_activation( $network_wide ) {
	if ( ! force_2fa_activation_blocked( is_multisite(), $network_wide ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	deactivate_plugins( plugin_basename( __FILE__ ) );

	wp_die(
		esc_html__( 'Require Email 2FA is network-only on multisite. Please Network Activate it from Network Admin → Plugins instead of activating it on a single site.', 'force-email-two-factor' ),
		esc_html__( 'Network activation required', 'force-email-two-factor' ),
		array( 'back_link' => true )
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
	// Mirror our own activation scope: when Require Email 2FA is network-active,
	// resolve the dependency network-wide too (install + network-activate).
	$network = force_2fa_self_network_active();

	foreach ( force_2fa_required_install_caps( $installed, $network ) as $required_cap ) {
		if ( ! current_user_can( $required_cap ) ) {
			wp_die( esc_html__( 'You do not have permission to install or activate plugins.', 'force-email-two-factor' ) );
		}
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
		if ( true !== $result ) {
			wp_die( esc_html__( 'Two Factor could not be installed.', 'force-email-two-factor' ) );
		}

		// Activate the exact plugin file the upgrader just installed. This avoids
		// validating a stale/hard-coded plugin path if the install destination differs
		// or WordPress has a cached plugin inventory from earlier in the request.
		wp_clean_plugins_cache( true );
		clearstatcache();

		$installed_plugin_file = $upgrader->plugin_info();
		if ( ! is_string( $installed_plugin_file ) || '' === $installed_plugin_file ) {
			wp_die( esc_html__( 'Two Factor was installed, but WordPress could not identify its main plugin file.', 'force-email-two-factor' ) );
		}

		$plugin_file = $installed_plugin_file;
	}

	$activated = activate_plugin( $plugin_file, '', $network );
	if ( is_wp_error( $activated ) ) {
		wp_die( esc_html( $activated->get_error_message() ) );
	}

	wp_safe_redirect( $network ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) );
	exit;
}
// @codeCoverageIgnoreEnd

/**
 * Get — or, from the bootstrap, record — the wired Plugin Update Checker instance.
 *
 * Shared accessor so diagnostics and the update E2E (bin/update-e2e.sh) reach the
 * exact checker instance WordPress uses instead of guessing it from hook tables.
 * Null when self-update is inactive: a git checkout, a missing vendored PUC, or a
 * blank Update URI header (see force_2fa_bootstrap_self_update()).
 *
 * @param object|null $checker Internal — the instance to record (bootstrap only).
 * @return object|null The active update checker, or null when none is wired.
 */
function force_2fa_update_checker( $checker = null ) {
	static $instance = null;
	if ( null !== $checker ) {
		$instance = $checker;
	}
	return $instance;
}

/**
 * Whether this plugin's self-updater should run.
 *
 * Defaults to enabled. Turn it OFF on sites whose plugin updates are delivered by a
 * central management layer (MainWP, Composer, git-based deploys) so each site does
 * not independently poll GitHub and self-install releases — updates then flow only
 * through your controlled pipeline. In wp-config.php:
 *
 *     define( 'FORCE_2FA_DISABLE_SELF_UPDATE', true );
 *
 * or via the 'force_2fa_self_update_enabled' filter (also how tests inject it).
 * Disabling self-update does NOT weaken 2FA enforcement — every enforcement guard
 * is independent of the updater; it only changes how this plugin's own code updates.
 *
 * @return bool
 */
function force_2fa_self_update_enabled() {
	$enabled = ! ( defined( 'FORCE_2FA_DISABLE_SELF_UPDATE' ) && FORCE_2FA_DISABLE_SELF_UPDATE );

	/**
	 * Filter whether the self-updater runs.
	 *
	 * @param bool $enabled Whether self-update is enabled (default true, unless the
	 *                      FORCE_2FA_DISABLE_SELF_UPDATE constant is truthy).
	 */
	return (bool) apply_filters( 'force_2fa_self_update_enabled', $enabled );
}

/**
 * Classify the self-update posture, for diagnostics (the Site Health check).
 *
 * Mirrors the order of the guards in force_2fa_bootstrap_self_update() so the
 * reported reason matches why the updater actually did or didn't wire up. Pure
 * decision, unit-tested; force_2fa_site_health_self_update() supplies the inputs.
 *
 * @param bool $enabled        Result of force_2fa_self_update_enabled().
 * @param bool $has_vcs        Whether a .git entry is present (a working copy).
 * @param bool $puc_readable   Whether the vendored Plugin Update Checker is readable.
 * @param bool $has_update_uri Whether the Update URI header is non-empty.
 * @return string One of: active, disabled_config, disabled_vcs, unavailable_no_puc,
 *                disabled_no_update_uri.
 */
function force_2fa_self_update_status( $enabled, $has_vcs, $puc_readable, $has_update_uri ) {
	if ( ! $enabled ) {
		return 'disabled_config';
	}
	if ( $has_vcs ) {
		return 'disabled_vcs';
	}
	if ( ! $puc_readable ) {
		return 'unavailable_no_puc';
	}
	if ( ! $has_update_uri ) {
		return 'disabled_no_update_uri';
	}
	return 'active';
}

/**
 * Map a self-update status to its Site Health severity and label.
 *
 * Intended states (updating, or a deliberate opt-out) are "good"; likely-unintended
 * ones (a working-copy .git on production, missing updater files, a blank Update
 * URI) are "recommended". Pure decision, unit-tested — keeping the classification
 * out of the disk-reading glue is what guards against, e.g., a disabled site being
 * labelled "receiving updates".
 *
 * @param string $status A force_2fa_self_update_status() value.
 * @return array{status:string,label:string}
 */
function force_2fa_self_update_health( $status ) {
	switch ( $status ) {
		case 'active':
			return array(
				'status' => 'good',
				'label'  => __( 'Require Email 2FA is receiving updates', 'force-email-two-factor' ),
			);
		case 'disabled_config':
			return array(
				'status' => 'good',
				'label'  => __( 'Require Email 2FA self-update is turned off (managed externally)', 'force-email-two-factor' ),
			);
		case 'disabled_vcs':
			return array(
				'status' => 'recommended',
				'label'  => __( 'Require Email 2FA is not self-updating (working copy)', 'force-email-two-factor' ),
			);
		case 'unavailable_no_puc':
			return array(
				'status' => 'recommended',
				'label'  => __( 'Require Email 2FA cannot self-update (updater files missing)', 'force-email-two-factor' ),
			);
		case 'disabled_no_update_uri':
			return array(
				'status' => 'recommended',
				'label'  => __( 'Require Email 2FA is not self-updating (no update source)', 'force-email-two-factor' ),
			);
		default:
			return array(
				'status' => 'recommended',
				'label'  => __( 'Require Email 2FA update status is unknown', 'force-email-two-factor' ),
			);
	}
}

/**
 * Register a Site Health test that reports the self-update posture.
 *
 * Surfaces a self-update that isn't running — an intentional opt-out, a stray .git
 * in a production install, a missing Update URI, or absent updater files — under
 * Tools → Site Health, where an admin can find it, instead of a nagging admin
 * notice on every page. Pure array transform, unit-tested.
 *
 * @param array $tests Site Health tests, keyed by 'direct' and 'async'.
 * @return array
 */
function force_2fa_register_site_health( $tests ) {
	$tests['direct']['force_2fa_self_update'] = array(
		'label' => __( 'Require Email 2FA self-update', 'force-email-two-factor' ),
		'test'  => 'force_2fa_site_health_self_update',
	);
	return $tests;
}

/**
 * Wire self-hosted plugin updates from the GitHub repository named in Update URI.
 *
 * WordPress core only checks WordPress.org for plugin updates; this plugin ships
 * from GitHub. Plugin Update Checker (PUC) injects update metadata into WP's update
 * system: it compares the installed Version header against the latest GitHub Release
 * and, when a newer one exists, offers that release's attached "<slug>.zip" through
 * the normal update flow — Dashboard → Updates, and unattended auto-updates where
 * the site has them enabled.
 *
 * Configuration is header-driven so a fork changes exactly one line (see the
 * "SELF-HOSTED UPDATES" note in the file header):
 *   - the repository comes from the Update URI header, which also stops
 *     WordPress.org from serving updates for this slug (collision protection: core
 *     honours a non-.org Update URI, and PUC additionally excludes this plugin from
 *     the wordpress.org update check by default);
 *   - the slug and the download asset name derive from the installed plugin folder,
 *     which the release workflow builds under the same name — nothing is hardcoded.
 *
 * PUC is vendored — committed under vendor/yahnis-elsts/ (a Composer dependency,
 * managed with `composer update` but tracked in git via a .gitignore exception) so
 * the exact bytes that auto-update installed sites are reviewed in-repo rather than
 * resolved from Packagist at release time. Because it is therefore also present in
 * a git checkout, the .git guard below keeps self-update from clobbering a working
 * copy; real installs (release zip, no .git) self-update normally.
 *
 * Registered on plugins_loaded rather than at file load so the zero-dependency
 * unit-test bootstrap — which records add_action() without ever firing it — never
 * instantiates PUC against WordPress functions it does not stub.
 *
 * Wired on every request (not gated to admin/cron/CLI): Plugin Update Checker
 * injects available-update data on read via the site_transient_update_plugins
 * filter, and that data is read outside the admin too — the front-end admin
 * toolbar's update count for logged-in admins, REST requests, and management
 * tools — so the injector must always be registered. Only self-update being
 * disabled or a working copy short-circuits it (below).
 */
function force_2fa_bootstrap_self_update() {
	// @codeCoverageIgnoreStart
	// Explicit opt-out for centrally-managed fleets (MainWP, Composer, git deploys):
	// leave patching to the management layer instead of each site polling GitHub.
	if ( ! force_2fa_self_update_enabled() ) {
		return;
	}

	// Never self-update a working copy under version control. Plugin Update Checker
	// is vendored (committed), so it is present in a git clone too — but a clone (or
	// a git worktree, where .git is a file) is updated with `git pull`, and letting
	// WordPress replace it with a release zip would clobber the checkout. A release
	// build carries no .git, so real installs are unaffected.
	if ( file_exists( __DIR__ . '/.git' ) ) {
		return;
	}

	$puc_bootstrap = __DIR__ . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
	if ( ! is_readable( $puc_bootstrap ) ) {
		return;
	}

	// Repository comes from the Update URI header (the fork's single knob). Bail if
	// it is absent so an un-pointed fork simply does not self-update.
	$headers  = get_file_data( __FILE__, array( 'update_uri' => 'Update URI' ) );
	$repo_url = isset( $headers['update_uri'] ) ? trim( $headers['update_uri'] ) : '';
	if ( '' === $repo_url ) {
		return;
	}

	require_once $puc_bootstrap;

	// Slug = the installed plugin folder name. The release workflow builds the zip,
	// its top-level folder, and the release asset under this same name, so the
	// checker, the downloaded asset, and PUC's WordPress.org exclusion all key off
	// one value with nothing hardcoded to this particular fork.
	$slug = basename( __DIR__ );

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		$repo_url,
		__FILE__,
		$slug
	);

	// Follow published Releases and download the versioned "<slug>.zip" asset built
	// by the release workflow. REQUIRE (not merely prefer) that asset: a release
	// without it offers no update at all, rather than falling back to GitHub's
	// source archive — which unzips to a differently-named folder and omits vendor/
	// (and so PUC itself on the next update). This keeps the reviewed release asset
	// as the only update payload, which is the documented trust boundary.
	$vcs_api = $update_checker->getVcsApi();
	// Anchor both ends: the release asset is named exactly "<slug>.zip", so a
	// start anchor keeps a differently-prefixed asset (e.g. "x-<slug>.zip") from
	// also matching. Defense-in-depth — the release trust boundary already
	// controls what assets exist.
	$vcs_api->enableReleaseAssets( '/^' . preg_quote( $slug, '/' ) . '\.zip$/i', $vcs_api::REQUIRE_RELEASE_ASSETS );

	force_2fa_update_checker( $update_checker );
	// @codeCoverageIgnoreEnd
}

/**
 * Site Health test callback: report whether — and why — self-update is running.
 *
 * Computes the same inputs the updater bootstrap uses and defers the verdict to the
 * pure force_2fa_self_update_status(). An intentional state (active, or a
 * deliberate opt-out) reports "good"; a likely-unintended one (a working-copy .git
 * on a production site, absent updater files, or a blank Update URI) reports
 * "recommended" — visible to an admin, never a page-nagging notice.
 *
 * @return array A Site Health result.
 */
function force_2fa_site_health_self_update() {
	// @codeCoverageIgnoreStart
	$puc_bootstrap  = __DIR__ . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
	$headers        = get_file_data( __FILE__, array( 'update_uri' => 'Update URI' ) );
	$has_update_uri = isset( $headers['update_uri'] ) && '' !== trim( $headers['update_uri'] );

	$status = force_2fa_self_update_status(
		force_2fa_self_update_enabled(),
		file_exists( __DIR__ . '/.git' ),
		is_readable( $puc_bootstrap ),
		$has_update_uri
	);
	$health = force_2fa_self_update_health( $status );

	$descriptions = array(
		'active'                 => __( 'This plugin updates directly from its GitHub Releases, through the normal Dashboard &rarr; Updates flow and unattended auto-updates.', 'force-email-two-factor' ),
		'disabled_config'        => __( 'Self-update is intentionally disabled (FORCE_2FA_DISABLE_SELF_UPDATE). Updates are expected to be delivered by your plugin management layer, e.g. MainWP, Composer, or git deploys.', 'force-email-two-factor' ),
		'disabled_vcs'           => __( 'A .git directory is present, so WordPress will not replace this working copy with a release. On a production site, install the release zip instead; if this is intentional, update it via git or your management layer.', 'force-email-two-factor' ),
		'unavailable_no_puc'     => __( 'The bundled updater files are missing, so this plugin cannot check for updates. Reinstall it from the official release zip.', 'force-email-two-factor' ),
		'disabled_no_update_uri' => __( 'The Update URI header is empty, so no update source is configured. Point it at the source repository to receive updates.', 'force-email-two-factor' ),
	);
	$description  = isset( $descriptions[ $status ] ) ? $descriptions[ $status ] : '';

	return array(
		'label'       => $health['label'],
		'status'      => $health['status'],
		'badge'       => array(
			'label' => __( 'Security', 'force-email-two-factor' ),
			'color' => 'blue',
		),
		'description' => '' === $description ? '' : '<p>' . esc_html( $description ) . '</p>',
		'test'        => 'force_2fa_self_update',
	);
	// @codeCoverageIgnoreEnd
}

/**
 * Register this plugin's WordPress hooks.
 *
 * Called once at load (below); also unit-tested directly, so the registrations
 * are exercised under coverage rather than only at include time.
 */
function force_2fa_register_hooks() {
	add_filter( 'two_factor_enabled_providers_for_user', 'force_2fa_filter_enabled_providers', 10, 2 );
	add_filter( 'two_factor_user_api_login_enable', 'force_2fa_filter_api_login_enable', 10, 2 );

	// Bind the API-login app-password check to the account that authenticated (see
	// force_2fa_filter_api_login_enable): record the user on each app-password auth.
	add_action( 'application_password_did_authenticate', 'force_2fa_note_app_password_user', 10, 1 );

	// Soft-dependency UX: nag + one-click installer when Two Factor is absent.
	// Per-site notice (single-site actionable / multisite heads-up) and the
	// Network Admin notice (actionable, network-wide) both fire; each callback
	// no-ops when its context doesn't apply.
	add_action( 'admin_notices', 'force_2fa_dependency_notice' );
	add_action( 'network_admin_notices', 'force_2fa_network_dependency_notice' );
	add_action( 'admin_post_force_2fa_install_two_factor', 'force_2fa_handle_install_two_factor' );

	// Site Health: report the self-update posture (Tools → Site Health) instead of a
	// nagging notice when the updater isn't running.
	add_filter( 'site_status_tests', 'force_2fa_register_site_health' );
}

// Network-only on multisite: refuse per-site activation (see the function docblock).
register_activation_hook( __FILE__, 'force_2fa_block_single_site_activation' );

// Self-hosted updates from GitHub Releases (see force_2fa_bootstrap_self_update()).
add_action( 'plugins_loaded', 'force_2fa_bootstrap_self_update' );

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
