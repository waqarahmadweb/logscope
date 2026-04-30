<?php
/**
 * Background scanner that feeds new fatal entries to the alert pipeline.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Cron;

use Logscope\Alerts\AlertCoordinator;
use Logscope\Log\Entry;
use Logscope\Log\FileLogSource;
use Logscope\Log\LogGrouper;
use Logscope\Log\LogParser;
use Logscope\Log\Severity;

/**
 * Reads the slice of the log appended since the last scan, filters to
 * fatal + parse-error entries, groups them, and dispatches the result
 * through {@see AlertCoordinator}. The scan cursor lives in the
 * `logscope_last_scanned_byte` option; the run timestamp is persisted
 * to `logscope_last_scanned_at` so the Settings UI can show "Last scan
 * at …" without a second source of truth.
 *
 * Rotation handling: when the current file is shorter than the stored
 * cursor (truncate / archive on the previous tick), the cursor is
 * reset to 0 and the new file is scanned from the start. The shrink
 * itself is signalled in the result with `rotated => true` for tests
 * and observability.
 */
final class LogScanner {

	/**
	 * Option key holding the byte offset already consumed by a prior scan.
	 */
	public const OPT_LAST_BYTE = 'logscope_last_scanned_byte';

	/**
	 * Option key holding the unix timestamp of the most recent scan.
	 */
	public const OPT_LAST_AT = 'logscope_last_scanned_at';

	/**
	 * Option key holding the count of groups dispatched on the latest run.
	 * Persisted so the Settings UI can show "N fatals dispatched" without
	 * inventing a second per-run summary store.
	 */
	public const OPT_LAST_DISPATCHED = 'logscope_last_scanned_dispatched';

	/**
	 * Byte source providing size + bounded-range reads.
	 *
	 * @var FileLogSource
	 */
	private FileLogSource $source;

	/**
	 * Coordinator the scanner feeds when new fatals are found.
	 *
	 * @var AlertCoordinator
	 */
	private AlertCoordinator $coordinator;

	/**
	 * Constructor.
	 *
	 * @param FileLogSource    $source      Validated byte source.
	 * @param AlertCoordinator $coordinator Alert pipeline.
	 */
	public function __construct( FileLogSource $source, AlertCoordinator $coordinator ) {
		$this->source      = $source;
		$this->coordinator = $coordinator;
	}

	/**
	 * Performs one scan tick. Returns a structured summary so the cron
	 * callback, the test suite, and (later) WP-CLI all consume the same
	 * shape rather than re-deriving it from option reads.
	 *
	 * @return array{bytes_read:int, groups_dispatched:int, rotated:bool, skipped:bool}
	 */
	public function scan(): array {
		$size = $this->source->size();
		$last = (int) get_option( self::OPT_LAST_BYTE, 0 );

		$rotated = false;
		if ( $size < $last ) {
			// File rotated/truncated since last scan — reset cursor and
			// re-read the new file from the start. The shrink itself is
			// not an error; the dropped bytes were already dispatched on
			// the previous tick.
			$last    = 0;
			$rotated = true;
		}

		if ( $size === $last ) {
			update_option( self::OPT_LAST_AT, time() );
			update_option( self::OPT_LAST_DISPATCHED, 0 );
			if ( $rotated ) {
				update_option( self::OPT_LAST_BYTE, $size );
			}
			return array(
				'bytes_read'        => 0,
				'groups_dispatched' => 0,
				'rotated'           => $rotated,
				'skipped'           => true,
			);
		}

		$max   = $size - $last;
		$chunk = $this->source->read_chunk( $last, $max );

		$entries = LogParser::parse( $chunk );
		$entries = self::filter_alertable( $entries );

		$groups = LogGrouper::group( $entries );

		if ( ! empty( $groups ) ) {
			$this->coordinator->dispatch_for_groups( $groups );
		}

		$dispatched = count( $groups );
		update_option( self::OPT_LAST_BYTE, $size );
		update_option( self::OPT_LAST_AT, time() );
		update_option( self::OPT_LAST_DISPATCHED, $dispatched );

		return array(
			'bytes_read'        => strlen( $chunk ),
			'groups_dispatched' => $dispatched,
			'rotated'           => $rotated,
			'skipped'           => false,
		);
	}

	/**
	 * Restricts a parsed entry list to severities that warrant an alert.
	 * Warnings, notices, deprecations, and unknown lines are not on the
	 * alert path — only outright failures.
	 *
	 * @param Entry[] $entries Parsed entries.
	 * @return Entry[]
	 */
	private static function filter_alertable( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			if ( Severity::FATAL === $entry->severity || Severity::PARSE === $entry->severity ) {
				$out[] = $entry;
			}
		}
		return $out;
	}
}
