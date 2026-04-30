<?php
/**
 * Plugin deactivation handler.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope;

use Logscope\Cron\CronScheduler;

/**
 * Runs when the plugin is deactivated. Clears scheduled cron events so
 * orphaned hooks do not fire after the plugin code is gone.
 *
 * Capabilities and options are intentionally preserved on deactivation —
 * users often deactivate temporarily. Full cleanup happens in
 * {@see uninstall.php}.
 */
final class Deactivator {

	/**
	 * Cron hook names owned by Logscope. Cleared on deactivation.
	 *
	 * @var list<string>
	 */
	private const CRON_HOOKS = array(
		CronScheduler::HOOK,
		CronScheduler::HOOK_ROTATE,
	);

	/**
	 * Deactivation callback registered via `register_deactivation_hook()`.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
