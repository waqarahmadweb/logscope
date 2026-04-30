<?php
/**
 * Unit tests for the cron LogScanner.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tmp fixture filesystem access is intentional here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Unit\Cron;

use Brain\Monkey\Functions;
use Logscope\Alerts\AlertCoordinator;
use Logscope\Cron\LogScanner;
use Logscope\Log\FileLogSource;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;
use Mockery;

/**
 * Covers the scan loop, idempotent no-op on a quiet log, and the
 * rotation path. Coordinator interaction is asserted through a
 * Mockery double standing in for `AlertCoordinator`.
 */
final class LogScannerTest extends TestCase {

	private string $root;

	private string $log_path;

	private PathGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-scanner-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root     = $resolved;
		$this->log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		$this->guard    = new PathGuard( array( $this->root ) );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_scan_dispatches_two_groups_for_two_distinct_fatals(): void {
		$this->write_log(
			"[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  Uncaught Error: boom in /var/www/a.php:1\n"
			. "[27-Apr-2026 12:34:57 UTC] PHP Fatal error:  Uncaught Error: bang in /var/www/b.php:2\n"
		);

		$store = $this->option_store();
		$this->stub_options( $store );

		$coordinator = Mockery::mock( AlertCoordinator::class );
		$coordinator->shouldReceive( 'dispatch_for_groups' )
			->once()
			->with( Mockery::on( static fn( $groups ) => is_array( $groups ) && 2 === count( $groups ) ) )
			->andReturn( array() );

		$scanner = new LogScanner( $this->make_source(), $coordinator );
		$result  = $scanner->scan();

		$this->assertFalse( $result['skipped'] );
		$this->assertFalse( $result['rotated'] );
		$this->assertSame( 2, $result['groups_dispatched'] );
		$this->assertSame( filesize( $this->log_path ), $store['values'][ LogScanner::OPT_LAST_BYTE ] );
		$this->assertSame( 2, $store['values'][ LogScanner::OPT_LAST_DISPATCHED ] );
		$this->assertIsInt( $store['values'][ LogScanner::OPT_LAST_AT ] );
	}

	public function test_scan_is_noop_when_no_new_bytes(): void {
		$this->write_log( "[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  boom in /var/www/a.php:1\n" );

		$store                                        = $this->option_store();
		$store['values'][ LogScanner::OPT_LAST_BYTE ] = filesize( $this->log_path );
		$this->stub_options( $store );

		$coordinator = Mockery::mock( AlertCoordinator::class );
		$coordinator->shouldReceive( 'dispatch_for_groups' )->never();

		$scanner = new LogScanner( $this->make_source(), $coordinator );
		$result  = $scanner->scan();

		$this->assertTrue( $result['skipped'] );
		$this->assertSame( 0, $result['groups_dispatched'] );
		$this->assertSame( 0, $store['values'][ LogScanner::OPT_LAST_DISPATCHED ] );
	}

	public function test_scan_treats_shrunk_file_as_rotation_and_rescans_from_zero(): void {
		// New file is shorter than the persisted cursor — i.e. the
		// previous file was archived/truncated between ticks.
		$this->write_log( "[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  fresh in /var/www/c.php:1\n" );

		$store                                        = $this->option_store();
		$store['values'][ LogScanner::OPT_LAST_BYTE ] = filesize( $this->log_path ) + 9999;
		$this->stub_options( $store );

		$coordinator = Mockery::mock( AlertCoordinator::class );
		$coordinator->shouldReceive( 'dispatch_for_groups' )
			->once()
			->with( Mockery::on( static fn( $groups ) => 1 === count( $groups ) ) )
			->andReturn( array() );

		$scanner = new LogScanner( $this->make_source(), $coordinator );
		$result  = $scanner->scan();

		$this->assertTrue( $result['rotated'] );
		$this->assertSame( 1, $result['groups_dispatched'] );
		$this->assertSame( filesize( $this->log_path ), $store['values'][ LogScanner::OPT_LAST_BYTE ] );
	}

	public function test_scan_filters_out_non_fatal_severities(): void {
		$this->write_log(
			"[27-Apr-2026 12:34:56 UTC] PHP Notice:  trivial in /var/www/a.php on line 1\n"
			. "[27-Apr-2026 12:34:57 UTC] PHP Warning:  also trivial in /var/www/b.php on line 2\n"
			. "[27-Apr-2026 12:34:58 UTC] PHP Fatal error:  real in /var/www/c.php:3\n"
		);

		$store = $this->option_store();
		$this->stub_options( $store );

		$coordinator = Mockery::mock( AlertCoordinator::class );
		$coordinator->shouldReceive( 'dispatch_for_groups' )
			->once()
			->with( Mockery::on( static fn( $groups ) => 1 === count( $groups ) ) )
			->andReturn( array() );

		$scanner = new LogScanner( $this->make_source(), $coordinator );
		$result  = $scanner->scan();

		$this->assertSame( 1, $result['groups_dispatched'] );
	}

	private function make_source(): FileLogSource {
		return new FileLogSource( $this->log_path, $this->guard );
	}

	private function write_log( string $contents ): void {
		file_put_contents( $this->log_path, $contents );
	}

	/**
	 * Returns a by-reference container shared between the get/update
	 * stubs, so writes during a `scan()` call are observable to the
	 * test assertions.
	 *
	 * @return array{values: array<string, mixed>}
	 */
	private function option_store(): array {
		return array( 'values' => array() );
	}

	/**
	 * Wires Brain Monkey aliases for `get_option` and `update_option`
	 * onto the supplied store.
	 *
	 * @param array{values: array<string, mixed>} $store Mutable option container.
	 */
	private function stub_options( array &$store ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$store ) {
				return array_key_exists( $key, $store['values'] ) ? $store['values'][ $key ] : $fallback;
			}
		);

		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$store ) {
				$store['values'][ $key ] = $value;
				return true;
			}
		);
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
