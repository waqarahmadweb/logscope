<?php
/**
 * Unit tests for DiagnosticsService.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tests need raw filesystem access for tmp fixtures and best-effort cleanup;
// WP_Filesystem and structured error handling are inappropriate here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Unit\Support;

use Logscope\Support\DiagnosticsService;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;

final class DiagnosticsServiceTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-diagnostics-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root = $resolved;
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_both_flags_off_returns_all_false_snapshot(): void {
		$guard       = new PathGuard( array( $this->root ) );
		$diagnostics = new DiagnosticsService( $guard, '', false, false );

		$snapshot = $diagnostics->snapshot();

		$this->assertFalse( $snapshot['wp_debug'] );
		$this->assertFalse( $snapshot['wp_debug_log'] );
		$this->assertSame( '', $snapshot['log_path'] );
		$this->assertFalse( $snapshot['exists'] );
		$this->assertFalse( $snapshot['parent_writable'] );
		$this->assertSame( 0, $snapshot['file_size'] );
		$this->assertSame( 0, $snapshot['modified_at'] );
	}

	public function test_flags_on_but_file_missing_reports_writable_parent(): void {
		$guard    = new PathGuard( array( $this->root ) );
		$log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';

		$diagnostics = new DiagnosticsService( $guard, $log_path, true, true );

		$snapshot = $diagnostics->snapshot();

		$this->assertTrue( $snapshot['wp_debug'] );
		$this->assertTrue( $snapshot['wp_debug_log'] );
		$this->assertSame( $log_path, $snapshot['log_path'] );
		$this->assertFalse( $snapshot['exists'] );
		$this->assertTrue( $snapshot['parent_writable'] );
		$this->assertSame( 0, $snapshot['file_size'] );
		$this->assertSame( 0, $snapshot['modified_at'] );
	}

	public function test_existing_file_populates_size_and_mtime(): void {
		$guard    = new PathGuard( array( $this->root ) );
		$log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $log_path, "first line\n" );

		$diagnostics = new DiagnosticsService( $guard, $log_path, true, true );

		$snapshot = $diagnostics->snapshot();

		$this->assertTrue( $snapshot['exists'] );
		$this->assertTrue( $snapshot['parent_writable'] );
		$this->assertSame( strlen( "first line\n" ), $snapshot['file_size'] );
		$this->assertGreaterThan( 0, $snapshot['modified_at'] );
		$this->assertLessThanOrEqual( time() + 1, $snapshot['modified_at'] );
	}

	public function test_path_outside_allowlist_is_reported_as_missing(): void {
		$guard = new PathGuard( array( $this->root ) );

		// Resolved path is real but outside the configured root.
		$outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-outside-' . bin2hex( random_bytes( 4 ) ) . '.log';
		file_put_contents( $outside, "x\n" );

		try {
			$diagnostics = new DiagnosticsService( $guard, $outside, true, true );
			$snapshot    = $diagnostics->snapshot();

			$this->assertFalse( $snapshot['exists'] );
			$this->assertFalse( $snapshot['parent_writable'] );
			$this->assertSame( 0, $snapshot['file_size'] );
			$this->assertSame( 0, $snapshot['modified_at'] );
		} finally {
			@unlink( $outside );
		}
	}

	public function test_from_environment_reads_undefined_constants_as_false(): void {
		// In the unit-test bootstrap WP_DEBUG / WP_DEBUG_LOG are not
		// defined, so the factory must produce an instance whose snapshot
		// reflects an off-host. (Constants cannot be undefined inside a
		// PHPUnit process, which is why production wiring uses the static
		// factory while the test cases above pass flags directly).
		$guard = new PathGuard( array( $this->root ) );

		$diagnostics = DiagnosticsService::from_environment( $guard, '' );
		$snapshot    = $diagnostics->snapshot();

		$this->assertFalse( $snapshot['wp_debug'] );
		$this->assertFalse( $snapshot['wp_debug_log'] );
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
