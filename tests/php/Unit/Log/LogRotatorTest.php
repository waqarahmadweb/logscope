<?php
/**
 * Unit tests for LogRotator.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tests need raw filesystem access for tmp fixtures and best-effort cleanup;
// WP_Filesystem and structured error handling are inappropriate here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Unit\Log;

use Logscope\Log\FileLogSource;
use Logscope\Log\LogRotator;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;

final class LogRotatorTest extends TestCase {

	private string $root;

	private PathGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-rotator-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root  = $resolved;
		$this->guard = new PathGuard( array( $this->root ) );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_noop_when_file_missing(): void {
		$path    = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		$source  = new FileLogSource( $path, $this->guard );
		$rotator = new LogRotator( $source, $this->guard, 1024, 5 );

		$result = $rotator->rotate();

		$this->assertTrue( $result['skipped'] );
		$this->assertNull( $result['archived_to'] );
		$this->assertSame( array(), $result['pruned'] );
	}

	public function test_noop_when_size_below_threshold(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $path, str_repeat( 'A', 100 ) );

		$source  = new FileLogSource( $path, $this->guard );
		$rotator = new LogRotator( $source, $this->guard, 1024, 5 );

		$result = $rotator->rotate();

		$this->assertTrue( $result['skipped'] );
		$this->assertNull( $result['archived_to'] );
		$this->assertFileExists( $path );
	}

	public function test_archives_when_size_at_or_above_threshold(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $path, str_repeat( 'A', 2048 ) );

		$source  = new FileLogSource( $path, $this->guard );
		$rotator = new LogRotator( $source, $this->guard, 1024, 5 );

		$result = $rotator->rotate();

		$this->assertFalse( $result['skipped'] );
		$this->assertIsString( $result['archived_to'] );
		$this->assertFileExists( $result['archived_to'] );
		$this->assertFileDoesNotExist( $path );
		$this->assertMatchesRegularExpression(
			'/debug\.log\.archived-\d{8}-\d{6}$/',
			$result['archived_to']
		);
		$this->assertSame( array(), $result['pruned'] );
	}

	public function test_prunes_oldest_archive_beyond_retention_cap(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $path, str_repeat( 'A', 2048 ) );

		// Seed 5 pre-existing archives with strictly ascending mtimes so
		// the oldest is unambiguous.
		$existing = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$archive = $this->root . DIRECTORY_SEPARATOR . 'debug.log.archived-2026010' . ( $i + 1 ) . '-000000';
			file_put_contents( $archive, 'old' );
			touch( $archive, 1000 + $i );
			$existing[] = $archive;
		}

		$source  = new FileLogSource( $path, $this->guard );
		$rotator = new LogRotator( $source, $this->guard, 1024, 5 );

		$result = $rotator->rotate();

		$this->assertFalse( $result['skipped'] );
		$this->assertCount( 1, $result['pruned'] );
		$this->assertSame( $existing[0], $result['pruned'][0] );
		$this->assertFileDoesNotExist( $existing[0] );

		for ( $i = 1; $i < 5; $i++ ) {
			$this->assertFileExists( $existing[ $i ] );
		}

		// Newly created archive is the 5th retained sibling.
		$this->assertFileExists( $result['archived_to'] );
	}

	public function test_no_prune_when_within_cap(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $path, str_repeat( 'A', 2048 ) );

		// 3 pre-existing archives + 1 new archive = 4, under the cap of 5.
		for ( $i = 0; $i < 3; $i++ ) {
			$archive = $this->root . DIRECTORY_SEPARATOR . 'debug.log.archived-2026010' . ( $i + 1 ) . '-000000';
			file_put_contents( $archive, 'old' );
			touch( $archive, 1000 + $i );
		}

		$source  = new FileLogSource( $path, $this->guard );
		$rotator = new LogRotator( $source, $this->guard, 1024, 5 );

		$result = $rotator->rotate();

		$this->assertFalse( $result['skipped'] );
		$this->assertSame( array(), $result['pruned'] );
	}

	public function test_noop_when_threshold_or_cap_zero(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $path, str_repeat( 'A', 2048 ) );

		$source = new FileLogSource( $path, $this->guard );

		$zero_size = ( new LogRotator( $source, $this->guard, 0, 5 ) )->rotate();
		$this->assertTrue( $zero_size['skipped'] );
		$this->assertFileExists( $path );

		$zero_cap = ( new LogRotator( $source, $this->guard, 1024, 0 ) )->rotate();
		$this->assertTrue( $zero_cap['skipped'] );
		$this->assertFileExists( $path );
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

			if ( is_link( $path ) || is_file( $path ) ) {
				@unlink( $path );
				continue;
			}

			$this->rrmdir( $path );
		}

		@rmdir( $dir );
	}
}
