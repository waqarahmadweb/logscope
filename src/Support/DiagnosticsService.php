<?php
/**
 * Snapshot of host diagnostics relevant to the log viewer.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Support;

/**
 * Builds a structured, all-typed snapshot describing the host environment
 * the plugin is running in: whether `WP_DEBUG` / `WP_DEBUG_LOG` are on,
 * the resolved log path the plugin would tail, whether the file exists,
 * its size and modification time, and whether the parent directory is
 * writable so a fresh log file could be created on demand.
 *
 * The snapshot is consumed by the REST `/diagnostics` endpoint, the
 * onboarding banner, and the reason-aware empty-log state. Every field is
 * a typed boolean / int / string — `null` is never returned, so consumers
 * can branch on simple truthiness without defensive type checks.
 *
 * The boolean debug flags are passed in via the constructor rather than
 * read from `WP_DEBUG` / `WP_DEBUG_LOG` directly, because PHP constants
 * cannot be undefined or re-defined inside a single PHPUnit process — so
 * unit tests need a deterministic seam. Production wiring calls
 * {@see DiagnosticsService::from_environment()} which reads the constants
 * the same way the rest of the plugin does.
 */
final class DiagnosticsService {

	/**
	 * Filesystem validator used to confirm that the log path resolves
	 * inside the configured allowlist before any `filesize()` /
	 * `filemtime()` call. Paths outside the allowlist are reported as
	 * `exists:false` rather than throwing, so the diagnostics surface
	 * never propagates a path-validation failure to the caller.
	 *
	 * @var PathGuard
	 */
	private PathGuard $guard;

	/**
	 * Untrusted candidate log path — typically the configured
	 * `logscope_log_path` option, falling back to `WP_CONTENT_DIR/debug.log`.
	 *
	 * @var string
	 */
	private string $log_path;

	/**
	 * Whether `WP_DEBUG` is defined and truthy.
	 *
	 * @var bool
	 */
	private bool $wp_debug;

	/**
	 * Whether `WP_DEBUG_LOG` is defined and enables logging (either
	 * literal `true` or a non-empty string path override).
	 *
	 * @var bool
	 */
	private bool $wp_debug_log;

	/**
	 * Whether `WP_DEBUG_DISPLAY` is defined and truthy. Surfaced so the
	 * Settings UI can warn the admin when errors are being emitted into
	 * the response body — a security and usability hazard on a public site.
	 *
	 * @var bool
	 */
	private bool $wp_debug_display;

	/**
	 * Constructor.
	 *
	 * @param PathGuard $guard            Allowlist-aware path validator.
	 * @param string    $log_path         Candidate log path; the same value
	 *                                    `FileLogSource` was constructed with so
	 *                                    the snapshot reflects the file the
	 *                                    plugin would actually read.
	 * @param bool      $wp_debug         Whether `WP_DEBUG` is on.
	 * @param bool      $wp_debug_log     Whether `WP_DEBUG_LOG` is enabled.
	 * @param bool      $wp_debug_display Whether `WP_DEBUG_DISPLAY` is on.
	 */
	public function __construct(
		PathGuard $guard,
		string $log_path,
		bool $wp_debug,
		bool $wp_debug_log,
		bool $wp_debug_display = false
	) {
		$this->guard            = $guard;
		$this->log_path         = $log_path;
		$this->wp_debug         = $wp_debug;
		$this->wp_debug_log     = $wp_debug_log;
		$this->wp_debug_display = $wp_debug_display;
	}

	/**
	 * Builds an instance from the live PHP environment by reading
	 * `WP_DEBUG` and `WP_DEBUG_LOG` through `defined()` + `constant()`,
	 * the same pattern {@see PathGuard::default_roots()} uses. Kept
	 * static so unit tests can build instances with arbitrary flag
	 * values without touching globals.
	 *
	 * @param PathGuard $guard    Path validator.
	 * @param string    $log_path Candidate log path.
	 * @return self
	 */
	public static function from_environment( PathGuard $guard, string $log_path ): self {
		$wp_debug = defined( 'WP_DEBUG' ) && (bool) constant( 'WP_DEBUG' );

		$wp_debug_log = false;
		if ( defined( 'WP_DEBUG_LOG' ) ) {
			$value = constant( 'WP_DEBUG_LOG' );
			if ( true === $value || ( is_string( $value ) && '' !== $value ) ) {
				$wp_debug_log = true;
			}
		}

		$wp_debug_display = defined( 'WP_DEBUG_DISPLAY' ) && (bool) constant( 'WP_DEBUG_DISPLAY' );

		return new self( $guard, $log_path, $wp_debug, $wp_debug_log, $wp_debug_display );
	}

	/**
	 * Returns the current diagnostics snapshot.
	 *
	 * Field contract:
	 *
	 *   - `wp_debug`        — `WP_DEBUG` constant is defined and truthy.
	 *   - `wp_debug_log`    — `WP_DEBUG_LOG` is defined and either `true`
	 *                          or a non-empty string (a path override).
	 *   - `wp_debug_display` — `WP_DEBUG_DISPLAY` is defined and truthy.
	 *                          Should be `false` on production sites; the
	 *                          Settings UI surfaces a warning when it isn't.
	 *   - `log_path`        — the candidate path the plugin would tail.
	 *                          Empty string when no path is configured
	 *                          and `WP_CONTENT_DIR` is undefined.
	 *   - `exists`          — log file resolves inside the allowlist and
	 *                          is readable.
	 *   - `parent_writable` — parent directory exists, is inside the
	 *                          allowlist, and is writable by PHP.
	 *   - `file_size`       — size in bytes; `0` when missing.
	 *   - `modified_at`     — Unix timestamp of last modification; `0`
	 *                          when missing.
	 *
	 * @return array{wp_debug:bool, wp_debug_log:bool, wp_debug_display:bool, log_path:string, exists:bool, parent_writable:bool, file_size:int, modified_at:int}
	 */
	public function snapshot(): array {
		$exists      = false;
		$file_size   = 0;
		$modified_at = 0;

		if ( '' !== $this->log_path && $this->guard->is_readable( $this->log_path ) ) {
			try {
				$resolved    = $this->guard->resolve( $this->log_path );
				$size_result = filesize( $resolved );
				$mtime       = filemtime( $resolved );

				$exists      = true;
				$file_size   = false === $size_result ? 0 : (int) $size_result;
				$modified_at = false === $mtime ? 0 : (int) $mtime;
			} catch ( InvalidPathException $e ) {
				$exists = false;
			} catch ( MissingPathException $e ) {
				$exists = false;
			}
		}

		return array(
			'wp_debug'         => $this->wp_debug,
			'wp_debug_log'     => $this->wp_debug_log,
			'wp_debug_display' => $this->wp_debug_display,
			'log_path'         => $this->log_path,
			'exists'           => $exists,
			'parent_writable'  => '' !== $this->log_path && $this->guard->is_writable_parent_of( $this->log_path ),
			'file_size'        => $file_size,
			'modified_at'      => $modified_at,
		);
	}
}
