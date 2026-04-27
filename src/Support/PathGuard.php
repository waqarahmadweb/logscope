<?php
/**
 * Filesystem path validator with allowlist + traversal protection.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Support;

/**
 * Validates filesystem paths against a fixed allowlist of root directories.
 *
 * Rejection happens in two stages: raw-string checks (null bytes, `..`
 * segments) before any filesystem call, then `realpath()` canonicalisation
 * with an allowlist containment check. Because `realpath()` resolves
 * symlinks, a symlink that escapes the allowlist is caught by the
 * containment check rather than by a separate symlink-specific code path.
 *
 * The class is intentionally WordPress-free so it can be unit tested
 * against temporary directories on any platform. Production callers obtain
 * production roots via {@see PathGuard::default_roots()}.
 */
final class PathGuard {

	/**
	 * Canonicalised, trailing-separator-stripped allowlist roots.
	 *
	 * @var string[]
	 */
	private array $allowed_roots;

	/**
	 * Builds a guard from a list of absolute root directories.
	 *
	 * @param string[] $allowed_roots Absolute directory paths. Each is
	 *                                canonicalised once on construction;
	 *                                non-existent or unreadable roots are
	 *                                silently dropped so a misconfigured
	 *                                root cannot accidentally widen the
	 *                                allowlist by `realpath()` returning
	 *                                false at validation time.
	 */
	public function __construct( array $allowed_roots ) {
		$normalised = array();

		foreach ( $allowed_roots as $root ) {
			if ( ! is_string( $root ) || '' === $root ) {
				continue;
			}

			$resolved = realpath( $root );
			if ( false === $resolved ) {
				continue;
			}

			$normalised[] = rtrim( $resolved, DIRECTORY_SEPARATOR );
		}

		$this->allowed_roots = array_values( array_unique( $normalised ) );
	}

	/**
	 * Returns the production root list (ABSPATH and WP_CONTENT_DIR when
	 * defined). Kept static and outside the constructor so tests can build
	 * a guard with arbitrary tmp roots without depending on WordPress.
	 *
	 * @return string[]
	 */
	public static function default_roots(): array {
		$roots = array();

		if ( defined( 'ABSPATH' ) ) {
			$roots[] = (string) constant( 'ABSPATH' );
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = (string) constant( 'WP_CONTENT_DIR' );
		}

		return $roots;
	}

	/**
	 * Returns the canonicalised absolute path, or throws if the candidate
	 * is malformed, missing, or escapes the allowlist.
	 *
	 * @param string $raw_path Untrusted path, typically from settings or REST.
	 * @return string Canonical absolute path on disk.
	 * @throws InvalidPathException When validation fails.
	 */
	public function resolve( string $raw_path ): string {
		if ( '' === $raw_path ) {
			throw new InvalidPathException( 'Path is empty.' );
		}

		if ( false !== strpos( $raw_path, "\0" ) ) {
			throw new InvalidPathException( 'Path contains a null byte.' );
		}

		if ( $this->has_dot_dot_segment( $raw_path ) ) {
			throw new InvalidPathException( 'Path contains a parent-directory segment.' );
		}

		$resolved = realpath( $raw_path );
		if ( false === $resolved ) {
			throw new InvalidPathException( 'Path does not exist or is not accessible.' );
		}

		if ( ! $this->is_inside_allowed_root( $resolved ) ) {
			throw new InvalidPathException( 'Path is outside the allowed directories.' );
		}

		return $resolved;
	}

	/**
	 * True when the path resolves successfully and is readable.
	 *
	 * @param string $raw_path Untrusted path.
	 * @return bool
	 */
	public function is_readable( string $raw_path ): bool {
		try {
			$resolved = $this->resolve( $raw_path );
		} catch ( InvalidPathException $e ) {
			return false;
		}

		return is_readable( $resolved );
	}

	/**
	 * True when the path resolves successfully and is writable.
	 *
	 * @param string $raw_path Untrusted path.
	 * @return bool
	 */
	public function is_writable( string $raw_path ): bool {
		try {
			$resolved = $this->resolve( $raw_path );
		} catch ( InvalidPathException $e ) {
			return false;
		}

		// PathGuard is the filesystem-boundary validator; WP_Filesystem is
		// the wrong tool here because we are answering "can the PHP process
		// write this exact path?" rather than performing a privileged write.
		return is_writable( $resolved ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/**
	 * True when `$raw_path`'s parent directory resolves successfully and
	 * is writable by the PHP process. Used by callers that need to write
	 * a sibling of an existing file (log clear's soft-delete rename, the
	 * future "Test path" settings probe) without the file itself
	 * necessarily existing yet.
	 *
	 * @param string $raw_path Untrusted path whose parent directory we test.
	 * @return bool
	 */
	public function is_writable_parent_of( string $raw_path ): bool {
		if ( '' === $raw_path || false !== strpos( $raw_path, "\0" ) ) {
			return false;
		}

		$parent = dirname( $raw_path );
		if ( '' === $parent || '.' === $parent ) {
			return false;
		}

		try {
			$resolved_parent = $this->resolve( $parent );
		} catch ( InvalidPathException $e ) {
			return false;
		}

		// PathGuard is the filesystem-boundary validator; WP_Filesystem is
		// the wrong tool here because we are answering "can the PHP process
		// write to this parent directory?" rather than performing a write.
		return is_writable( $resolved_parent ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/**
	 * Returns the configured allowlist roots (post-canonicalisation).
	 *
	 * Exposed for diagnostic surfaces such as the future "Test path"
	 * settings button so error messages can name the actual roots.
	 *
	 * @return string[]
	 */
	public function allowed_roots(): array {
		return $this->allowed_roots;
	}

	/**
	 * Checks whether the raw path contains a `..` segment, splitting on
	 * both `/` and `\` so Windows-style paths are also caught. Embedded
	 * dots inside a filename (e.g. `foo..bar`) are not segments and pass.
	 *
	 * @param string $raw_path Raw input.
	 * @return bool
	 */
	private function has_dot_dot_segment( string $raw_path ): bool {
		$segments = preg_split( '#[\\\\/]+#', $raw_path );
		if ( false === $segments ) {
			return true;
		}

		foreach ( $segments as $segment ) {
			if ( '..' === $segment ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when the resolved path is one of the allowed roots or sits
	 * beneath one. Comparison is exact-prefix on canonical paths so a
	 * sibling like `/var/wwwroot-evil/foo` cannot match root `/var/www`.
	 *
	 * @param string $resolved Canonical absolute path.
	 * @return bool
	 */
	private function is_inside_allowed_root( string $resolved ): bool {
		foreach ( $this->allowed_roots as $root ) {
			if ( $resolved === $root ) {
				return true;
			}

			if ( 0 === strpos( $resolved, $root . DIRECTORY_SEPARATOR ) ) {
				return true;
			}
		}

		return false;
	}
}
