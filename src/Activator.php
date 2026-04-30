<?php
/**
 * Plugin activation handler.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope;

use Logscope\Cron\CronScheduler;

/**
 * Runs once when the plugin is activated. Seeds default options and grants
 * the `logscope_manage` capability to administrators.
 */
final class Activator {

	/**
	 * Default option values seeded on activation. `add_option()` is a no-op
	 * when the key already exists, so re-activation preserves user settings.
	 *
	 * Keep these keys + defaults in sync with {@see \Logscope\Settings\SettingsSchema}.
	 * The schema is the single source of truth at runtime; this map only
	 * exists to seed the rows on first activation.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULT_OPTIONS = array(
		'logscope_log_path'                   => '',
		'logscope_tail_interval'              => 3,
		'logscope_alert_email_enabled'        => 0,
		'logscope_alert_email_to'             => '',
		'logscope_alert_webhook_enabled'      => 0,
		'logscope_alert_webhook_url'          => '',
		'logscope_alert_dedup_window'         => 300,
		'logscope_cron_scan_enabled'          => 0,
		'logscope_cron_scan_interval_minutes' => 5,
		'logscope_retention_enabled'          => 0,
		'logscope_retention_max_size_mb'      => 50,
		'logscope_retention_max_archives'     => 5,
		'logscope_db_version'                 => '1',
	);

	/**
	 * Activation callback registered via `register_activation_hook()`.
	 *
	 * @return void
	 */
	public static function activate(): void {
		foreach ( self::DEFAULT_OPTIONS as $key => $value ) {
			add_option( $key, $value );
		}

		$admin = get_role( 'administrator' );
		if ( null !== $admin ) {
			$admin->add_cap( 'logscope_manage' );
		}

		// Align the scan event with the (just-seeded) toggle. Default is
		// off, so this is a no-op on a fresh install — but re-activation
		// after a user has enabled the scan will recreate the event if
		// it was lost (e.g. WP-Cron table truncation during a migration).
		CronScheduler::apply();
	}
}
