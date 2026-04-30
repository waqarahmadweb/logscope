<?php
/**
 * Size-based archival + pruning of the active log file.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

use Logscope\Support\PathGuard;

/**
 * Archives the live `debug.log` once it grows beyond a configured size,
 * then prunes the oldest archives so the directory does not accumulate
 * unbounded history. The class is deliberately stateless — every tick
 * reads the source's current size and rederives the archive list, so
 * concurrent writes by WordPress between ticks cannot leave the rotator
 * holding a stale view.
 *
 * Archive naming: `<basename>.archived-YYYYMMDD-HHMMSS` in UTC. Second
 * resolution is sufficient because the cron cadence is daily-or-slower
 * (Phase 14.3) and a same-second collision would only cause a noop on
 * the colliding tick — the next run picks up the still-oversized file.
 *
 * The rotator never throws on filesystem failure: a missing file, a
 * non-writable parent, or a failed `rename` all return a noop result so
 * the cron callback finishes cleanly. Errors that the admin needs to
 * see surface through the (separate) settings diagnostics path.
 */
final class LogRotator {

	/**
	 * Byte source naming the live log file. Same instance the scanner
	 * and reader use, so size accounting agrees across services.
	 *
	 * @var FileLogSource
	 */
	private FileLogSource $source;

	/**
	 * Path validator scoped to the caller's allowlist; gates both the
	 * pre-rename writability check and the prune `unlink` loop.
	 *
	 * @var PathGuard
	 */
	private PathGuard $guard;

	/**
	 * Threshold in bytes above which a rotation is performed. Conversion
	 * from the SettingsSchema MB value happens at the wiring site so
	 * unit tests can pass small thresholds without integer-MB rounding.
	 *
	 * @var int
	 */
	private int $max_size_bytes;

	/**
	 * Maximum number of archive files retained beneath the live log's
	 * directory. Older archives (by mtime) are unlinked.
	 *
	 * @var int
	 */
	private int $max_archives;

	/**
	 * Constructor.
	 *
	 * @param FileLogSource $source         Live log source.
	 * @param PathGuard     $guard          Path validator.
	 * @param int           $max_size_bytes Rotation threshold in bytes.
	 * @param int           $max_archives   Archive retention count.
	 */
	public function __construct(
		FileLogSource $source,
		PathGuard $guard,
		int $max_size_bytes,
		int $max_archives
	) {
		$this->source         = $source;
		$this->guard          = $guard;
		$this->max_size_bytes = $max_size_bytes;
		$this->max_archives   = $max_archives;
	}

	/**
	 * Performs one rotation tick.
	 *
	 * @return array{archived_to: ?string, pruned: string[], skipped: bool}
	 */
	public function rotate(): array {
		$noop = array(
			'archived_to' => null,
			'pruned'      => array(),
			'skipped'     => true,
		);

		if ( $this->max_size_bytes <= 0 || $this->max_archives <= 0 ) {
			return $noop;
		}

		if ( ! $this->source->exists() ) {
			return $noop;
		}

		$size = $this->source->size();
		if ( $size < $this->max_size_bytes ) {
			return $noop;
		}

		$path     = $this->source->path();
		$dir      = dirname( $path );
		$basename = basename( $path );
		$target   = $dir . DIRECTORY_SEPARATOR . $basename . '.archived-' . gmdate( 'Ymd-His' );

		if ( ! $this->guard->is_writable_parent_of( $target ) ) {
			return $noop;
		}

		if ( file_exists( $target ) ) {
			// Same-second collision — let the next tick try again rather
			// than overwriting a freshly created sibling archive.
			return $noop;
		}

		// Rotator is the filesystem-boundary writer; WP_Filesystem is the
		// wrong tool here because we are renaming/unlinking inside an
		// already-validated allowlisted directory, not performing a
		// privileged write through a user-facing flow. The `@` is
		// intentional: a failed rename collapses to the structured noop
		// rather than letting an E_WARNING surface from the cron tick.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $path, $target ) ) {
			return $noop;
		}

		$pruned = $this->prune_archives( $dir, $basename );

		return array(
			'archived_to' => $target,
			'pruned'      => $pruned,
			'skipped'     => false,
		);
	}

	/**
	 * Deletes archive siblings beyond the retention cap, oldest first.
	 *
	 * @param string $dir      Directory holding the live log + archives.
	 * @param string $basename Basename of the live log (e.g. `debug.log`).
	 * @return string[] Paths that were unlinked.
	 */
	private function prune_archives( string $dir, string $basename ): array {
		$pattern = $dir . DIRECTORY_SEPARATOR . $basename . '.archived-*';
		$matches = glob( $pattern );
		if ( false === $matches || array() === $matches ) {
			return array();
		}

		$dated = array();
		foreach ( $matches as $candidate ) {
			$mtime = filemtime( $candidate );
			if ( false === $mtime ) {
				continue;
			}
			$dated[] = array(
				'path'  => $candidate,
				'mtime' => $mtime,
			);
		}

		if ( count( $dated ) <= $this->max_archives ) {
			return array();
		}

		usort(
			$dated,
			static function ( array $a, array $b ): int {
				return $a['mtime'] <=> $b['mtime'];
			}
		);

		$excess = count( $dated ) - $this->max_archives;
		$pruned = array();

		for ( $i = 0; $i < $excess; $i++ ) {
			$victim = $dated[ $i ]['path'];

			// `@` is intentional: a failed `unlink` is non-fatal —
			// the next tick's prune retries from the still-current
			// archive list, and surfacing a per-file E_WARNING into
			// the cron callback would not change that.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( @unlink( $victim ) ) {
				$pruned[] = $victim;
			}
		}

		return $pruned;
	}
}
