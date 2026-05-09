<?php
/**
 * Logscope uninstall routine. Fired by WordPress when the plugin is deleted
 * via the admin UI. Runs outside the plugin bootstrap — do not rely on
 * autoload or the `Plugin` class being available.
 *
 * @package Logscope
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Enumerated option keys. Keep this list in sync as new options are added
 * by later roadmap steps. Mirrors the seed list in
 * Logscope\Activator::DEFAULT_OPTIONS plus the scanner's runtime cursors
 * (logscope_last_scanned_*) which the activator does not seed.
 */
$logscope_options = array(
	'logscope_log_path',
	'logscope_tail_interval',
	'logscope_alert_email_enabled',
	'logscope_alert_email_to',
	'logscope_alert_webhook_enabled',
	'logscope_alert_webhook_url',
	'logscope_alert_dedup_window',
	'logscope_cron_scan_enabled',
	'logscope_cron_scan_interval_minutes',
	'logscope_retention_enabled',
	'logscope_retention_max_size_mb',
	'logscope_retention_max_archives',
	'logscope_default_per_page',
	'logscope_default_severity_filter',
	'logscope_timestamp_tz',
	'logscope_admin_bar_enabled',
	'logscope_last_scanned_byte',
	'logscope_last_scanned_at',
	'logscope_last_scanned_dispatched',
	'logscope_db_version',
);

foreach ( $logscope_options as $logscope_option ) {
	delete_option( $logscope_option );
	delete_site_option( $logscope_option );
}

/*
 * Transient sweep. The plugin writes transients with several distinct
 * prefixes (logscope_alert_dedup_*, logscope_stats_*, logscope_admin_bar_today_*)
 * keyed off signature hashes, file mtimes, and rolling dates — enumerating
 * them at runtime is impractical, so we delete by `option_name` LIKE pattern
 * on the wp_options table directly. The shared `logscope_` prefix on every
 * Logscope-owned transient bounds the wildcard so we do not touch foreign
 * transients. Both the value rows (`_transient_<key>`) and the matching
 * timeout rows (`_transient_timeout_<key>`) are removed in the same query.
 *
 * On a multisite install, `delete_transient` only addresses the site's own
 * transients; the same wildcard sweep is run against `sitemeta` for network-
 * wide transients via `_site_transient_*`.
 */
global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall runs once and bypasses the options API by necessity (set_transient/get_transient have no enumerator).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_logscope_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_logscope_' ) . '%'
		)
	);

	if ( is_multisite() && isset( $wpdb->sitemeta ) ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
				$wpdb->esc_like( '_site_transient_logscope_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_logscope_' ) . '%'
			)
		);
	}

	/*
	 * Per-user filter presets (Phase 14.8). Stored under the
	 * `logscope_filter_presets` user-meta key — one row per admin who
	 * saved a preset. `delete_metadata` with `$delete_all = true` removes
	 * every user's row in a single query without enumerating users.
	 */
	delete_metadata( 'user', 0, 'logscope_filter_presets', '', true );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

/*
 * Scheduled events. The plugin registers two recurring crons —
 * `logscope_scan_fatals` (the alert scanner) and `logscope_rotate_logs`
 * (the retention rotator). Deactivation already clears them, but a forced
 * delete that bypasses deactivate would leave the WP-Cron rows behind, so
 * we clear them here too. The hook names are duplicated here as literals
 * because uninstall runs without autoload — we cannot reference the
 * `Logscope\Cron\CronScheduler` constants.
 */
wp_clear_scheduled_hook( 'logscope_scan_fatals' );
wp_clear_scheduled_hook( 'logscope_rotate_logs' );

$logscope_roles = wp_roles();
if ( $logscope_roles instanceof WP_Roles ) {
	foreach ( array_keys( $logscope_roles->roles ) as $logscope_role_name ) {
		$logscope_role = $logscope_roles->get_role( $logscope_role_name );
		if ( null !== $logscope_role && $logscope_role->has_cap( 'logscope_manage' ) ) {
			$logscope_role->remove_cap( 'logscope_manage' );
		}
	}
}
