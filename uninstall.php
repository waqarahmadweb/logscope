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
 * by later roadmap steps (Phase 5 Settings, v1.1 Alerts, v1.2 Cron).
 */
$logscope_options = array(
	'logscope_log_path',
	'logscope_tail_interval',
	'logscope_db_version',
);

foreach ( $logscope_options as $logscope_option ) {
	delete_option( $logscope_option );
	delete_site_option( $logscope_option );
}

/*
 * Enumerated transient prefixes. Empty today; v1.1 Alerts adds
 * `logscope_alert_dedup_*` transients that must be purged here.
 */
$logscope_transients = array();

foreach ( $logscope_transients as $logscope_transient ) {
	delete_transient( $logscope_transient );
	delete_site_transient( $logscope_transient );
}

$logscope_roles = wp_roles();
if ( $logscope_roles instanceof WP_Roles ) {
	foreach ( array_keys( $logscope_roles->roles ) as $logscope_role_name ) {
		$logscope_role = $logscope_roles->get_role( $logscope_role_name );
		if ( null !== $logscope_role && $logscope_role->has_cap( 'logscope_manage' ) ) {
			$logscope_role->remove_cap( 'logscope_manage' );
		}
	}
}
