<?php
/**
 * Tests for the alert deduplicator.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Alerts;

use Brain\Monkey\Functions;
use Logscope\Alerts\AlertDeduplicator;
use Logscope\Tests\TestCase;

/**
 * Unit coverage for the dedup window: floor coercion, transient
 * round-trip, and key shaping.
 *
 * @coversDefaultClass \Logscope\Alerts\AlertDeduplicator
 */
final class AlertDeduplicatorTest extends TestCase {

	public function test_window_floor_coerces_short_values_up_to_60(): void {
		$dedup = new AlertDeduplicator( 5 );
		$this->assertSame( 60, $dedup->window_seconds() );
	}

	public function test_window_passthrough_for_reasonable_values(): void {
		$dedup = new AlertDeduplicator( 300 );
		$this->assertSame( 300, $dedup->window_seconds() );
	}

	public function test_should_send_returns_true_when_no_transient_present(): void {
		$dedup = new AlertDeduplicator( 300 );

		Functions\when( 'get_transient' )->justReturn( false );

		$this->assertTrue( $dedup->should_send( 'email', 'sigabc' ) );
	}

	public function test_should_send_returns_false_when_transient_present(): void {
		$dedup = new AlertDeduplicator( 300 );

		Functions\when( 'get_transient' )->justReturn( 1 );

		$this->assertFalse( $dedup->should_send( 'email', 'sigabc' ) );
	}

	public function test_record_sent_writes_transient_with_window_ttl(): void {
		$dedup = new AlertDeduplicator( 600 );

		Functions\expect( 'set_transient' )
			->once()
			->with( 'logscope_alert_email_sigabc', 1, 600 )
			->andReturn( true );

		$dedup->record_sent( 'email', 'sigabc' );
	}

	public function test_distinct_dispatchers_get_distinct_transient_keys(): void {
		$dedup = new AlertDeduplicator( 300 );

		$keys = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key ) use ( &$keys ) {
				$keys[] = $key;
				return true;
			}
		);

		$dedup->record_sent( 'email', 'sigabc' );
		$dedup->record_sent( 'webhook', 'sigabc' );

		$this->assertSame(
			array( 'logscope_alert_email_sigabc', 'logscope_alert_webhook_sigabc' ),
			$keys
		);
	}

	public function test_dispatcher_name_sanitised_against_unsafe_chars(): void {
		$dedup = new AlertDeduplicator( 300 );

		$captured = '';
		Functions\when( 'set_transient' )->alias(
			function ( $key ) use ( &$captured ) {
				$captured = $key;
				return true;
			}
		);

		$dedup->record_sent( 'web hook!@#', 'sigabc' );

		$this->assertSame( 'logscope_alert_webhook_sigabc', $captured );
	}

	public function test_clear_deletes_transient(): void {
		$dedup = new AlertDeduplicator( 300 );

		Functions\expect( 'delete_transient' )
			->once()
			->with( 'logscope_alert_email_sigabc' )
			->andReturn( true );

		$dedup->clear( 'email', 'sigabc' );
	}
}
