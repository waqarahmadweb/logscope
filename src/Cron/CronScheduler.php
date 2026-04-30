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
	 * Cron event hook the rotator listens on (Phase 14.3). Daily cadence
	 * via WP-Core's built-in `daily` recurrence — no custom interval is
	 * needed because retention is "check once a day if rotation is due"
	 * rather than "rotate every N minutes".
	 */
	public const HOOK_ROTATE = 'logscope_rotate_logs';

	/**
	 * Recurrence used for the rotation event. WP-Core registers `daily`,
	 * so the schedule does not need its own `cron_schedules` filter
	 * entry.
	 */
	public const RECURRENCE_ROTATE = 'daily';

	/**
	 * Option key for the retention master toggle.
	 */
	public const OPT_RETENTION_ENABLED = 'logscope_retention_enabled';

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

	/**
	 * Aligns the WP rotation schedule with the retention toggle.
	 *
	 * Mirrors {@see CronScheduler::apply()} for the scan event but uses
	 * WP-Core's built-in `daily` recurrence rather than a custom one —
	 * retention does not benefit from sub-daily checks. Disabled →
	 * unschedule. Enabled → unschedule then re-schedule, matching the
	 * scan pattern so flipping the toggle is always idempotent.
	 *
	 * @return void
	 */
	public static function apply_rotation(): void {
		$enabled = 1 === (int) get_option( self::OPT_RETENTION_ENABLED, 0 );

		if ( ! $enabled ) {
			wp_clear_scheduled_hook( self::HOOK_ROTATE );
			return;
		}

		wp_clear_scheduled_hook( self::HOOK_ROTATE );
		wp_schedule_event( time() + 60, self::RECURRENCE_ROTATE, self::HOOK_ROTATE );
	}

	/**
	 * Clears the rotation event regardless of toggle state.
	 *
	 * @return void
	 */
	public static function clear_rotation(): void {
		wp_clear_scheduled_hook( self::HOOK_ROTATE );
	}
}
