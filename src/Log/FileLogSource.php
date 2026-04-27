<?php
/**
 * File-backed implementation of LogSourceInterface.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

use Logscope\Support\InvalidPathException;
use Logscope\Support\PathGuard;

/**
 * Reads a `debug.log`-style file in bounded chunks via `fopen`/`fseek`/
 * `fread`. Never `file_get_contents` — production logs can be hundreds
 * of megabytes and the viewer paginates server-side.
 *
 * The constructor validates the *parent directory* through PathGuard
 * (rather than the file itself) because a freshly activated WordPress
 * install will not yet have a `debug.log`. After parent validation,
 * the file path is reassembled from the validated dirname plus the
 * input's `basename()`, so any traversal segments in the original
 * input cannot influence the final path.
 */
final class FileLogSource implements LogSourceInterface {

	/**
	 * Canonical absolute path to the log file. Always sits inside one of
	 * the PathGuard's allowed roots; the file itself may or may not exist.
	 *
	 * @var string
	 */
	private string $resolved_path;

	/**
	 * Validates the candidate path's parent directory and stores the
	 * recombined safe path. Rejection happens at construction so misuse
	 * surfaces immediately rather than on first read.
	 *
	 * @param string    $raw_path Untrusted absolute path to the log file.
	 * @param PathGuard $guard    Validator scoped to the caller's allowlist.
	 * @throws InvalidPathException When the path is malformed or the parent
	 *                              directory is outside the allowlist.
	 */
	public function __construct( string $raw_path, PathGuard $guard ) {
		if ( '' === $raw_path ) {
			throw new InvalidPathException( 'Path is empty.' );
		}

		if ( false !== strpos( $raw_path, "\0" ) ) {
			throw new InvalidPathException( 'Path contains a null byte.' );
		}

		$basename = basename( $raw_path );
		if ( '' === $basename || '.' === $basename || '..' === $basename ) {
			throw new InvalidPathException( 'Path does not name a file.' );
		}

		$dirname = dirname( $raw_path );
		if ( '' === $dirname || '.' === $dirname ) {
			throw new InvalidPathException( 'Path has no parent directory.' );
		}

		$resolved_dir = $guard->resolve( $dirname );

		$this->resolved_path = $resolved_dir . DIRECTORY_SEPARATOR . $basename;
	}

	/**
	 * Returns the canonical resolved path (file may or may not exist).
	 */
	public function path(): string {
		return $this->resolved_path;
	}

	/**
	 * True when the file is present and readable by the PHP process.
	 */
	public function exists(): bool {
		return is_file( $this->resolved_path ) && is_readable( $this->resolved_path );
	}

	/**
	 * File size in bytes, or 0 when the file is missing or unreadable.
	 *
	 * @return int
	 */
	public function size(): int {
		if ( ! $this->exists() ) {
			return 0;
		}

		$size = filesize( $this->resolved_path );
		if ( false === $size ) {
			return 0;
		}

		return $size;
	}

	/**
	 * Reads up to `$max_bytes` starting at `$from_byte`. Empty string on
	 * missing source, non-positive bounds, or seek past EOF.
	 *
	 * @param int $from_byte Zero-based absolute offset.
	 * @param int $max_bytes Upper bound on returned length.
	 * @return string
	 */
	public function read_chunk( int $from_byte, int $max_bytes ): string {
		if ( $from_byte < 0 || $max_bytes <= 0 ) {
			return '';
		}

		if ( ! $this->exists() ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_read_fopen
		$handle = fopen( $this->resolved_path, 'rb' );
		if ( false === $handle ) {
			return '';
		}

		try {
			if ( -1 === fseek( $handle, $from_byte ) ) {
				return '';
			}

			// FileLogSource is the byte-level source; WP_Filesystem is the
			// wrong tool because we are streaming bounded chunks of a
			// known-validated file, not performing a privileged write.
			$chunk = fread( $handle, $max_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

			return false === $chunk ? '' : $chunk;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}
}
