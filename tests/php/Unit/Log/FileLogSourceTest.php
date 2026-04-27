<?php
/**
 * Unit tests for FileLogSource.
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
use Logscope\Support\InvalidPathException;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;

final class FileLogSourceTest extends TestCase {

	private string $root;

	private PathGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-filesource-' . bin2hex( random_bytes( 6 ) );
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

	public function test_path_returns_canonical_absolute_path(): void {
		$source = new FileLogSource( $this->root . DIRECTORY_SEPARATOR . 'debug.log', $this->guard );

		$this->assertSame( $this->root . DIRECTORY_SEPARATOR . 'debug.log', $source->path() );
	}

	public function test_exists_false_when_file_missing(): void {
		$source = new FileLogSource( $this->root . DIRECTORY_SEPARATOR . 'debug.log', $this->guard );

		$this->assertFalse( $source->exists() );
	}

	public function test_exists_true_when_file_present(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $path, 'x' );

		$source = new FileLogSource( $path, $this->guard );

		$this->assertTrue( $source->exists() );
	}

	public function test_size_zero_when_missing(): void {
		$source = new FileLogSource( $this->root . DIRECTORY_SEPARATOR . 'absent.log', $this->guard );

		$this->assertSame( 0, $source->size() );
	}

	public function test_size_and_read_chunk_against_5mb_fixture(): void {
		$path  = $this->root . DIRECTORY_SEPARATOR . 'big.log';
		$bytes = 5 * 1024 * 1024;
		$this->write_pattern( $path, $bytes );

		$source = new FileLogSource( $path, $this->guard );

		$this->assertSame( $bytes, $source->size() );

		$head = $source->read_chunk( 0, 16 );
		$this->assertSame( 16, strlen( $head ) );
		$this->assertSame( $this->expected_pattern_slice( 0, 16 ), $head );

		$mid_offset = 1234567;
		$mid        = $source->read_chunk( $mid_offset, 256 );
		$this->assertSame( 256, strlen( $mid ) );
		$this->assertSame( $this->expected_pattern_slice( $mid_offset, 256 ), $mid );
	}

	public function test_read_chunk_truncates_near_eof(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'small.log';
		file_put_contents( $path, str_repeat( 'A', 100 ) );

		$source = new FileLogSource( $path, $this->guard );

		$chunk = $source->read_chunk( 90, 1024 );
		$this->assertSame( 10, strlen( $chunk ) );
		$this->assertSame( str_repeat( 'A', 10 ), $chunk );
	}

	public function test_read_chunk_returns_empty_past_eof(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'small.log';
		file_put_contents( $path, 'abc' );

		$source = new FileLogSource( $path, $this->guard );

		$this->assertSame( '', $source->read_chunk( 100, 1024 ) );
	}

	public function test_read_chunk_returns_empty_when_missing(): void {
		$source = new FileLogSource( $this->root . DIRECTORY_SEPARATOR . 'absent.log', $this->guard );

		$this->assertSame( '', $source->read_chunk( 0, 1024 ) );
	}

	public function test_read_chunk_returns_empty_for_negative_offset(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'small.log';
		file_put_contents( $path, 'abc' );

		$source = new FileLogSource( $path, $this->guard );

		$this->assertSame( '', $source->read_chunk( -1, 1024 ) );
	}

	public function test_read_chunk_returns_empty_for_zero_max_bytes(): void {
		$path = $this->root . DIRECTORY_SEPARATOR . 'small.log';
		file_put_contents( $path, 'abc' );

		$source = new FileLogSource( $path, $this->guard );

		$this->assertSame( '', $source->read_chunk( 0, 0 ) );
	}

	public function test_constructor_rejects_empty_path(): void {
		$this->expectException( InvalidPathException::class );
		new FileLogSource( '', $this->guard );
	}

	public function test_constructor_rejects_null_byte(): void {
		$this->expectException( InvalidPathException::class );
		new FileLogSource( $this->root . "\0/etc/passwd", $this->guard );
	}

	public function test_constructor_rejects_parent_outside_allowlist(): void {
		$other = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-filesource-other-' . bin2hex( random_bytes( 6 ) );
		mkdir( $other, 0777, true );

		try {
			$this->expectException( InvalidPathException::class );
			new FileLogSource( $other . DIRECTORY_SEPARATOR . 'debug.log', $this->guard );
		} finally {
			@rmdir( $other );
		}
	}

	public function test_constructor_rejects_traversal_in_dirname(): void {
		$this->expectException( InvalidPathException::class );
		new FileLogSource( $this->root . '/sub/../../etc/debug.log', $this->guard );
	}

	public function test_constructor_strips_traversal_via_basename_when_dirname_valid(): void {
		// `basename()` returns just the filename, so even if the input
		// included extra path noise after the validated dirname, the
		// reassembled path stays inside the allowlist.
		$source = new FileLogSource( $this->root . DIRECTORY_SEPARATOR . 'debug.log', $this->guard );

		$this->assertSame(
			$this->root . DIRECTORY_SEPARATOR . 'debug.log',
			$source->path()
		);
	}

	public function test_constructor_rejects_path_naming_only_a_directory(): void {
		// basename of "/foo/" is "foo" on PHP — that would point at the
		// directory rather than a file. Once the source is constructed,
		// exists() correctly reports false because is_file() will fail.
		$source = new FileLogSource( $this->root . DIRECTORY_SEPARATOR . 'somefile', $this->guard );
		$this->assertFalse( $source->exists() );
	}

	private function write_pattern( string $path, int $bytes ): void {
		$handle = fopen( $path, 'wb' );
		self::assertNotFalse( $handle );

		$chunk     = '';
		$alphabet  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$alpha_len = strlen( $alphabet );
		for ( $i = 0; $i < 1024; $i++ ) {
			$chunk .= $alphabet[ $i % $alpha_len ];
		}

		$written = 0;
		while ( $written < $bytes ) {
			$remaining = $bytes - $written;
			$piece     = $remaining >= 1024 ? $chunk : substr( $chunk, 0, $remaining );
			$result    = fwrite( $handle, $piece );
			self::assertNotFalse( $result );
			$written += $result;
		}

		fclose( $handle );
	}

	private function expected_pattern_slice( int $offset, int $length ): string {
		$alphabet  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$alpha_len = strlen( $alphabet );
		$out       = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$pos_in_chunk = ( $offset + $i ) % 1024;
			$out         .= $alphabet[ $pos_in_chunk % $alpha_len ];
		}
		return $out;
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
