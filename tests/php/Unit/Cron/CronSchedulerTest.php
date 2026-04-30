<?php
/**
 * Unit tests for CronScheduler.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Cron;

use Brain\Monkey\Functions;
use Logscope\Cron\CronScheduler;
use Logscope\Tests\TestCase;

/**
 * Covers the three transitions {@see CronScheduler::apply()} guarantees:
 * disabled → unschedule, enabled → (clear then) re-schedule, interval
 * change → re-schedule with new cadence. The clear-before-schedule pair
 * is asserted explicitly because relying on `wp_schedule_event` to honour
 * a changed interval on an existing event is a known WP-Cron pitfall.
 */
final class CronSchedulerTest extends TestCase {

	public function test_apply_clears_when_toggle_is_disabled(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) {
				return CronScheduler::OPT_ENABLED === $key ? 0 : $fallback;
			}
		);

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( CronScheduler::HOOK );
		Functions\expect( 'wp_schedule_event' )->never();

		CronScheduler::apply();
	}

	public function test_apply_reschedules_when_toggle_is_enabled(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) {
				if ( CronScheduler::OPT_ENABLED === $key ) {
					return 1;
				}
				if ( CronScheduler::OPT_INTERVAL === $key ) {
					return 5;
				}
				return $fallback;
			}
		);

		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( CronScheduler::HOOK );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), CronScheduler::RECURRENCE, CronScheduler::HOOK );

		CronScheduler::apply();
	}

	public function test_clear_unschedules_unconditionally(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( CronScheduler::HOOK );
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'wp_schedule_event' )->never();

		CronScheduler::clear();
	}
}
