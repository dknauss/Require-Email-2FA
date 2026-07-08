<?php
/**
 * Uninstall cleanup for Require Email 2FA.
 *
 * Runs once when the plugin is deleted from the Plugins screen (or via WP-CLI
 * `wp plugin uninstall`). WordPress only includes this file when it defines
 * WP_UNINSTALL_PLUGIN, so bail otherwise — this must never run on a normal
 * request.
 *
 * The plugin itself is stateless: it stores no options, user meta, or
 * transients — all of its behavior is runtime filters/actions that vanish the
 * moment the file stops loading (see force-email-two-factor.php). The ONLY
 * persistent footprint is created by the bundled Plugin Update Checker (PUC)
 * when self-update is active:
 *
 *   1. A site option `external_updates-<slug>` — cached update metadata written
 *      by PUC's StateStore via update_site_option().
 *   2. A cron event `puc_cron_check_updates-<slug>` — the periodic update check.
 *
 * PUC clears the cron on DEACTIVATION (register_deactivation_hook →
 * removeUpdaterCron), but nothing deletes the option, and on multisite the cron
 * can be scheduled per-site. This file purges both so uninstall leaves nothing
 * behind, and is safe when self-update never ran (the deletes simply no-op).
 *
 * @package force-email-two-factor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Slug matches how the plugin derives it: the installed folder name
// (basename( __DIR__ ) at runtime). Keep these in lockstep with PUC's
// getUniqueName()/optionName so we target exactly what it created.
$force_2fa_slug        = basename( __DIR__ );
$force_2fa_option_name = 'external_updates-' . $force_2fa_slug;
$force_2fa_cron_hook   = 'puc_cron_check_updates-' . $force_2fa_slug;

/**
 * Clear the PUC update-check cron event on the current site.
 *
 * WordPress's wp_clear_scheduled_hook() removes every scheduled occurrence of
 * the hook in one call, but only for the current site's cron array — so on
 * multisite we call this once per site below.
 *
 * @param string $hook The cron hook to unschedule.
 * @return void
 */
function force_2fa_clear_update_cron( $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// Cached update metadata. StateStore uses update_site_option(), so the matching
// delete is delete_site_option() — which resolves to wp_options on single site
// and network meta (wp_sitemeta) on multisite, covering both storage locations.
delete_site_option( $force_2fa_option_name );

// Update-check cron. On multisite the plugin is network-active and PUC loads on
// every site, so the event may be scheduled in more than one site's cron array;
// clear it on each. On single site, clear it directly.
if ( is_multisite() ) {
	$force_2fa_sites = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_cache'      => false,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( (array) $force_2fa_sites as $force_2fa_site_id ) {
		switch_to_blog( absint( $force_2fa_site_id ) );
		force_2fa_clear_update_cron( $force_2fa_cron_hook );
		restore_current_blog();
	}

	unset( $force_2fa_sites, $force_2fa_site_id );
} else {
	force_2fa_clear_update_cron( $force_2fa_cron_hook );
}

unset( $force_2fa_slug, $force_2fa_option_name, $force_2fa_cron_hook );
