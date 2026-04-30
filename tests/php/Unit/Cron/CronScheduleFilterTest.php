<?php
/**
 * Unit tests for Plugin::register_cron_schedule (the cron_schedules filter).
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Cron;

use Brain\Monkey\Functions;
use Logscope\Cron\CronScheduler;
use Logscope\Plugin;
use Logscope\Tests\TestCase;

/**
 * The filter callback is the bridge between the schema-stored interval
 * and a WP-Cron recurrence usable by `wp_schedule_event`. These tests
 * cover the happy path, the [1, 1440] clamp (defense-in-depth so a
 * pre-13.3 corrupted row cannot register a 0-second recurrence), and
 * the non-array `$schedules` argument that some plugins occasionally
 * pass to `apply_filters`.
 */
final class CronScheduleFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( '_n' )->returnArg( 2 );
	}

	public function test_filter_registers_recurrence_at_configured_minutes(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) {
				return CronScheduler::OPT_INTERVAL === $key ? 7 : $fallback;
			}
		);

		$result = Plugin::register_cron_schedule( array() );

		$this->assertArrayHasKey( CronScheduler::RECURRENCE, $result );
		$this->assertSame( 7 * 60, $result[ CronScheduler::RECURRENCE ]['interval'] );
		$this->assertIsString( $result[ CronScheduler::RECURRENCE ]['display'] );
	}

	public function test_filter_clamps_below_minimum(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) {
				return CronScheduler::OPT_INTERVAL === $key ? 0 : $fallback;
			}
		);

		$result = Plugin::register_cron_schedule( array() );

		$this->assertSame( 60, $result[ CronScheduler::RECURRENCE ]['interval'] );
	}

	public function test_filter_clamps_above_maximum(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) {
				return CronScheduler::OPT_INTERVAL === $key ? 99999 : $fallback;
			}
		);

		$result = Plugin::register_cron_schedule( array() );

		$this->assertSame( 1440 * 60, $result[ CronScheduler::RECURRENCE ]['interval'] );
	}

	public function test_filter_tolerates_non_array_input(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) {
				return CronScheduler::OPT_INTERVAL === $key ? 5 : $fallback;
			}
		);

		$result = Plugin::register_cron_schedule( null );

		$this->assertArrayHasKey( CronScheduler::RECURRENCE, $result );
		$this->assertSame( 300, $result[ CronScheduler::RECURRENCE ]['interval'] );
	}

	public function test_filter_preserves_existing_schedules(): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) {
				return CronScheduler::OPT_INTERVAL === $key ? 5 : $fallback;
			}
		);

		$existing = array(
			'hourly' => array(
				'interval' => 3600,
				'display'  => 'Once Hourly',
			),
		);

		$result = Plugin::register_cron_schedule( $existing );

		$this->assertArrayHasKey( 'hourly', $result );
		$this->assertArrayHasKey( CronScheduler::RECURRENCE, $result );
	}
}
