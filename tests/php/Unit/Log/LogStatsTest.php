<?php
/**
 * Unit tests for LogStats.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tests need raw filesystem access for tmp fixtures and best-effort cleanup;
// WP_Filesystem and structured error handling are inappropriate here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Unit\Log;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use Logscope\Log\FileLogSource;
use Logscope\Log\LogStats;
use Logscope\Log\LogStatsException;
use Logscope\Log\Severity;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;

/**
 * Coverage for window math, bucket assignment, top-N, and the
 * transient-backed cache.
 *
 * @coversDefaultClass \Logscope\Log\LogStats
 */
final class LogStatsTest extends TestCase {

	private string $root;

	private PathGuard $guard;

	private string $log_path;

	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-stats-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root     = $resolved;
		$this->guard    = new PathGuard( array( $this->root ) );
		$this->log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';

		// Default: cache is a no-op so we exercise compute() unless a
		// specific test wires up the round-trip.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_returns_empty_grid_when_log_missing(): void {
		$stats  = $this->stats();
		$result = $stats->summarize( '24h', 'hour', $this->ref() );

		$this->assertSame( '24h', $result['range'] );
		$this->assertSame( 'hour', $result['bucket'] );
		// 24 whole hours plus one extra slot: the grid snaps outward to
		// bucket boundaries around the exact [now - 24h, now] window.
		$this->assertCount( 25, $result['buckets'] );
		$this->assertSame( 0, $result['buckets'][0][ Severity::FATAL ] );
		$this->assertSame( array(), $result['top'] );
		foreach ( Severity::all() as $severity ) {
			$this->assertSame( 0, $result['totals'][ $severity ] );
		}
	}

	public function test_buckets_entries_into_expected_hour_slots(): void {
		// Reference time: 2026-04-30 12:00:00 UTC. Window = [29-Apr 12:00, 30-Apr 12:00].
		// Grid = 25 hour slots from 29-Apr 12:00 to 30-Apr 13:00.
		$lines = array(
			$this->log_line( '30-Apr-2026 12:00:00', 'PHP Warning:  oops in /a.php on line 5' ),  // index 24 (== now).
			$this->log_line( '30-Apr-2026 11:30:00', 'PHP Fatal error:  bang in /a.php on line 9' ), // index 23.
			$this->log_line( '29-Apr-2026 13:00:00', 'PHP Notice:  hi in /a.php on line 1' ),       // index 1.
			$this->log_line( '28-Apr-2026 12:00:00', 'PHP Warning:  too old in /a.php on line 5' ), // dropped.
			$this->log_line( '29-Apr-2026 11:59:59', 'PHP Warning:  before window in /a.php on line 5' ), // dropped.
			$this->log_line( '30-Apr-2026 12:00:01', 'PHP Warning:  future in /a.php on line 5' ), // dropped (> now).
		);
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );

		$result = $this->stats()->summarize( '24h', 'hour', $this->ref() );

		$this->assertSame( 1, $result['buckets'][1][ Severity::NOTICE ] );
		$this->assertSame( 1, $result['buckets'][23][ Severity::FATAL ] );
		$this->assertSame( 1, $result['buckets'][24][ Severity::WARNING ] );
		// Out-of-window entries are not in any bucket.
		$this->assertSame( 0, array_sum( $result['buckets'][0] ) - $result['buckets'][0]['ts'] );
		$this->assertSame( 1, $result['totals'][ Severity::FATAL ] );
		$this->assertSame( 1, $result['totals'][ Severity::WARNING ] );
		$this->assertSame( 1, $result['totals'][ Severity::NOTICE ] );
	}

	public function test_unparseable_timestamps_are_dropped(): void {
		$lines = array(
			'PHP Notice:  no timestamp in /a.php on line 1',
			$this->log_line( '30-Apr-2026 11:30:00', 'PHP Warning:  ok in /a.php on line 5' ),
		);
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );

		$result = $this->stats()->summarize( '24h', 'hour', $this->ref() );

		$this->assertSame( 1, $result['totals'][ Severity::WARNING ] );
		$this->assertSame( 0, $result['totals'][ Severity::NOTICE ] );
	}

	public function test_top_signatures_groups_in_window_entries(): void {
		$lines = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$lines[] = $this->log_line( '30-Apr-2026 11:30:00', 'PHP Warning:  same shape ' . $i . ' in /a.php on line 5' );
		}
		$lines[] = $this->log_line( '30-Apr-2026 11:30:00', 'PHP Fatal error:  unique in /b.php on line 9' );
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );

		$result = $this->stats()->summarize( '24h', 'hour', $this->ref() );

		$this->assertCount( 2, $result['top'] );
		$this->assertSame( 5, $result['top'][0]['count'] );
		$this->assertSame( Severity::WARNING, $result['top'][0]['severity'] );
		$this->assertSame( 1, $result['top'][1]['count'] );
		$this->assertSame( Severity::FATAL, $result['top'][1]['severity'] );
	}

	public function test_top_signatures_capped_at_ten(): void {
		$lines = array();
		// 12 distinct line numbers ⇒ 12 distinct signatures, each count=1.
		for ( $i = 1; $i <= 12; $i++ ) {
			$lines[] = $this->log_line( '30-Apr-2026 11:30:00', 'PHP Warning:  line ' . $i . ' in /a.php on line ' . $i );
		}
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );

		$result = $this->stats()->summarize( '24h', 'hour', $this->ref() );

		$this->assertCount( LogStats::TOP_N, $result['top'] );
	}

	public function test_day_bucket_for_seven_day_range(): void {
		// Reference: 2026-04-30 12:00:00 UTC. Window = [23-Apr 12:00, 30-Apr 12:00].
		// Grid = 8 day slots from 23-Apr 00:00 to 01-May 00:00 (partial at both ends).
		$lines = array(
			$this->log_line( '30-Apr-2026 11:30:00', 'PHP Warning:  today in /a.php on line 5' ),      // last bucket, index 7.
			$this->log_line( '23-Apr-2026 12:00:00', 'PHP Notice:  start edge in /a.php on line 1' ),  // index 0.
			$this->log_line( '23-Apr-2026 11:59:59', 'PHP Notice:  too old in /a.php on line 1' ),     // dropped.
		);
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );

		$result = $this->stats()->summarize( '7d', 'day', $this->ref() );

		$this->assertCount( 8, $result['buckets'] );
		$this->assertSame( 1, $result['buckets'][0][ Severity::NOTICE ] );
		$this->assertSame( 1, $result['buckets'][7][ Severity::WARNING ] );
		$this->assertSame( 1, $result['totals'][ Severity::WARNING ] );
		$this->assertSame( 1, $result['totals'][ Severity::NOTICE ] );
	}

	public function test_cache_hit_short_circuits_compute(): void {
		file_put_contents( $this->log_path, $this->log_line( '30-Apr-2026 11:30:00', 'PHP Warning:  hit in /a.php on line 5' ) . "\n" );

		$cached = array(
			'range'   => '24h',
			'bucket'  => 'hour',
			'buckets' => array(),
			'totals'  => array(),
			'top'     => array(),
		);
		Functions\when( 'get_transient' )->justReturn( $cached );

		$called = 0;
		Functions\when( 'set_transient' )->alias(
			function () use ( &$called ) {
				$called++;
				return true;
			}
		);

		$result = $this->stats()->summarize( '24h', 'hour', $this->ref() );

		$this->assertSame( $cached, $result );
		$this->assertSame( 0, $called, 'set_transient must not run on cache hit.' );
	}

	public function test_cache_key_changes_when_mtime_changes(): void {
		file_put_contents( $this->log_path, $this->log_line( '30-Apr-2026 11:30:00', 'PHP Warning:  v1 in /a.php on line 5' ) . "\n" );
		touch( $this->log_path, 1_700_000_000 );

		$keys = array();
		Functions\when( 'set_transient' )->alias(
			function ( $key ) use ( &$keys ) {
				$keys[] = $key;
				return true;
			}
		);

		$stats = $this->stats();
		$stats->summarize( '24h', 'hour', $this->ref() );

		// Append a byte and bump mtime — cache key must change so the
		// stale entry is implicitly invalidated.
		file_put_contents( $this->log_path, "\n", FILE_APPEND );
		touch( $this->log_path, 1_700_000_999 );

		$stats->summarize( '24h', 'hour', $this->ref() );

		$this->assertCount( 2, $keys );
		$this->assertNotSame( $keys[0], $keys[1] );
	}

	public function test_unknown_range_throws(): void {
		$this->expectException( LogStatsException::class );
		$this->stats()->summarize( '999h', 'hour', $this->ref() );
	}

	public function test_unknown_bucket_throws(): void {
		$this->expectException( LogStatsException::class );
		$this->stats()->summarize( '24h', 'minute', $this->ref() );
	}

	public function test_totals_do_not_depend_on_bucket_size(): void {
		// Reference 12:00 UTC. Under the old day-snapped window, "24h / day"
		// meant "today since midnight" and dropped everything from yesterday
		// afternoon; hour and day views must now agree on the window.
		$lines = array(
			$this->log_line( '30-Apr-2026 11:00:00', 'PHP Warning:  today in /a.php on line 5' ),
			$this->log_line( '29-Apr-2026 20:00:00', 'PHP Warning:  yesterday evening in /a.php on line 5' ),
			$this->log_line( '29-Apr-2026 13:00:00', 'PHP Notice:  yesterday afternoon in /a.php on line 1' ),
			$this->log_line( '29-Apr-2026 11:00:00', 'PHP Notice:  outside in /a.php on line 1' ),
		);
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );

		$hour = $this->stats()->summarize( '24h', 'hour', $this->ref() );
		$day  = $this->stats()->summarize( '24h', 'day', $this->ref() );

		$this->assertSame( 2, $hour['totals'][ Severity::WARNING ] );
		$this->assertSame( 1, $hour['totals'][ Severity::NOTICE ] );
		$this->assertSame( $hour['totals'], $day['totals'] );
		$this->assertCount( 2, $day['buckets'] );
		$this->assertSame( 2, $day['buckets'][0][ Severity::WARNING ] + $day['buckets'][1][ Severity::WARNING ] );
	}

	public function test_buckets_carry_absolute_timestamps_in_order(): void {
		$result = $this->stats()->summarize( '24h', 'hour', $this->ref() );

		$prev = null;
		foreach ( $result['buckets'] as $bucket ) {
			$this->assertIsInt( $bucket['ts'] );
			if ( null !== $prev ) {
				$this->assertGreaterThan( $prev, $bucket['ts'] );
			}
			$prev = $bucket['ts'];
		}
	}

	private function stats(): LogStats {
		return new LogStats( new FileLogSource( $this->log_path, $this->guard ) );
	}

	private function ref(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-04-30 12:00:00', new DateTimeZone( 'UTC' ) );
	}

	private function log_line( string $timestamp, string $message ): string {
		return '[' . $timestamp . ' UTC] ' . $message;
	}

	/**
	 * Best-effort recursive directory removal.
	 *
	 * @param string $dir Path to remove.
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
