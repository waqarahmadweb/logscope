<?php
/**
 * Unit tests for the PathGuard validator.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tests need raw filesystem access for tmp fixtures and best-effort cleanup;
// WP_Filesystem and structured error handling are inappropriate here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Unit\Support;

use Logscope\Support\InvalidPathException;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;

final class PathGuardTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-pathguard-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root = $resolved;
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_resolve_returns_canonical_path_for_file_inside_allowed_root(): void {
		$file = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $file, "line\n" );

		$guard    = new PathGuard( array( $this->root ) );
		$resolved = $guard->resolve( $file );

		$this->assertSame( realpath( $file ), $resolved );
	}

	public function test_resolve_accepts_root_itself(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->assertSame( $this->root, $guard->resolve( $this->root ) );
	}

	public function test_resolve_rejects_empty_path(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->expectException( InvalidPathException::class );
		$guard->resolve( '' );
	}

	public function test_resolve_rejects_null_byte(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->expectException( InvalidPathException::class );
		$guard->resolve( $this->root . "\0/etc/passwd" );
	}

	public function test_resolve_rejects_dot_dot_segment_before_touching_filesystem(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->expectException( InvalidPathException::class );
		$this->expectExceptionMessage( 'parent-directory' );
		$guard->resolve( $this->root . '/../../../etc/passwd' );
	}

	public function test_resolve_rejects_windows_style_dot_dot_segment(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->expectException( InvalidPathException::class );
		$guard->resolve( $this->root . '\\..\\evil' );
	}

	public function test_resolve_allows_filename_containing_double_dots(): void {
		$file = $this->root . DIRECTORY_SEPARATOR . 'foo..bar.log';
		file_put_contents( $file, '' );

		$guard = new PathGuard( array( $this->root ) );

		$this->assertSame( realpath( $file ), $guard->resolve( $file ) );
	}

	public function test_resolve_rejects_missing_file(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->expectException( InvalidPathException::class );
		$this->expectExceptionMessage( 'does not exist' );
		$guard->resolve( $this->root . DIRECTORY_SEPARATOR . 'nope.log' );
	}

	public function test_resolve_rejects_path_outside_allowed_root(): void {
		$other = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-pathguard-other-' . bin2hex( random_bytes( 6 ) );
		mkdir( $other, 0777, true );
		$file = $other . DIRECTORY_SEPARATOR . 'evil.log';
		file_put_contents( $file, '' );

		try {
			$guard = new PathGuard( array( $this->root ) );

			$this->expectException( InvalidPathException::class );
			$this->expectExceptionMessage( 'outside the allowed' );
			$guard->resolve( $file );
		} finally {
			@unlink( $file );
			@rmdir( $other );
		}
	}

	public function test_resolve_rejects_sibling_root_with_shared_prefix(): void {
		$sibling = $this->root . '-evil';
		mkdir( $sibling, 0777, true );
		$file = $sibling . DIRECTORY_SEPARATOR . 'x.log';
		file_put_contents( $file, '' );

		try {
			$guard = new PathGuard( array( $this->root ) );

			$this->expectException( InvalidPathException::class );
			$guard->resolve( $file );
		} finally {
			@unlink( $file );
			@rmdir( $sibling );
		}
	}

	public function test_resolve_rejects_symlink_escaping_allowed_root(): void {
		if ( ! function_exists( 'symlink' ) ) {
			self::markTestSkipped( 'symlink() unavailable on this platform.' );
		}

		$outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-pathguard-outside-' . bin2hex( random_bytes( 6 ) );
		mkdir( $outside, 0777, true );
		$target = $outside . DIRECTORY_SEPARATOR . 'real.log';
		file_put_contents( $target, '' );

		$link = $this->root . DIRECTORY_SEPARATOR . 'evil-link.log';
		if ( ! @symlink( $target, $link ) ) {
			@unlink( $target );
			@rmdir( $outside );
			self::markTestSkipped( 'symlink() not permitted in this environment.' );
		}

		try {
			$guard = new PathGuard( array( $this->root ) );

			$this->expectException( InvalidPathException::class );
			$this->expectExceptionMessage( 'outside the allowed' );
			$guard->resolve( $link );
		} finally {
			@unlink( $link );
			@unlink( $target );
			@rmdir( $outside );
		}
	}

	public function test_constructor_drops_non_existent_roots(): void {
		$guard = new PathGuard(
			array(
				$this->root,
				$this->root . DIRECTORY_SEPARATOR . 'does-not-exist',
				'',
			)
		);

		$this->assertSame( array( $this->root ), $guard->allowed_roots() );
	}

	public function test_is_readable_true_for_existing_file_inside_root(): void {
		$file = $this->root . DIRECTORY_SEPARATOR . 'r.log';
		file_put_contents( $file, '' );

		$guard = new PathGuard( array( $this->root ) );

		$this->assertTrue( $guard->is_readable( $file ) );
	}

	public function test_is_readable_false_for_missing_file(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->assertFalse( $guard->is_readable( $this->root . DIRECTORY_SEPARATOR . 'nope.log' ) );
	}

	public function test_is_readable_false_for_path_outside_root(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->assertFalse( $guard->is_readable( $this->root . '/../../../etc/passwd' ) );
	}

	public function test_is_writable_true_for_writable_file_inside_root(): void {
		$file = $this->root . DIRECTORY_SEPARATOR . 'w.log';
		file_put_contents( $file, '' );

		$guard = new PathGuard( array( $this->root ) );

		$this->assertTrue( $guard->is_writable( $file ) );
	}

	public function test_is_writable_false_when_path_rejected(): void {
		$guard = new PathGuard( array( $this->root ) );

		$this->assertFalse( $guard->is_writable( '' ) );
	}

	public function test_default_roots_returns_defined_constants(): void {
		$roots = PathGuard::default_roots();

		$this->assertIsArray( $roots );
		// In the unit-test bootstrap neither WP constant is defined, so the
		// list is empty. The method's contract is "return whatever is
		// defined", which for the test environment is nothing. Production
		// integration covers the populated case.
		$this->assertSame( array(), $roots );
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
