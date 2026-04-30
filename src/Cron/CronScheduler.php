<?php
/**
 * Aligns the `logscope_scan_fatals` cron schedule with current settings.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Cron;

/**
 * Single owner of the `logscope_scan_fatals` schedule. The activation
 * handler, deactivation handler, and the option-update listeners all
 * route through {@see CronScheduler::apply()} so there is no other path
 * by which the schedule can drift out of sync with the persisted toggle
 * + interval.
 *
 * The setting names match the schema keys added in Phase 13.3
 * (`cron_scan_enabled`, `cron_scan_interval_minutes`); reads here go
 * directly through `get_option` rather than through `Settings` so this
 * class stays usable from the activation hook, which fires before the
 * plugin's DI graph is built. The same defaults the schema uses are
 * applied as fallbacks.
 */
final class CronScheduler {

	/**
	 * Cron event hook the scanner listens on.
	 */
	public const HOOK = 'logscope_scan_fatals';

	/**
	 * Recurrence key added by Logscope's `cron_schedules` filter (Phase
	 * 13.3). Held as a constant so the filter and the scheduler agree on
	 * a single name.
	 */
	public const RECURRENCE = 'logscope_scan_interval';

	/**
	 * Option key for the master cron toggle.
	 */
	public const OPT_ENABLED = 'logscope_cron_scan_enabled';

	/**
	 * Option key for the interval (minutes).
	 */
	public const OPT_INTERVAL = 'logscope_cron_scan_interval_minutes';

	/**
	 * Default interval used when the setting is absent or out of range
	 * (mirrors the schema default introduced in Phase 13.3).
	 */
	public const DEFAULT_INTERVAL_MINUTES = 5;

	/**
	 * Aligns the WP schedule with the current toggle + interval.
	 *
	 * Disabled → unschedule. Enabled → unschedule then re-schedule so
	 * a changed interval takes effect; re-using `wp_schedule_event` on
	 * an existing event would keep the original cadence.
	 *
	 * @return void
	 */
	public static function apply(): void {
		$enabled = 1 === (int) get_option( self::OPT_ENABLED, 0 );

		if ( ! $enabled ) {
			wp_clear_scheduled_hook( self::HOOK );
			return;
		}

		// Always clear before re-scheduling so an interval change is
		// honoured rather than silently absorbed by the existing event.
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + 60, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Clears the scheduled event regardless of toggle state. Used by the
	 * deactivation hook.
	 *
	 * @return void
	 */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
