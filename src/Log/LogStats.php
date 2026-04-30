<?php
/**
 * Time-bucketed aggregations over the current log file.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds dashboard-shaped summaries — per-severity counts bucketed over
 * a time window plus a top-N signature list — so the Stats tab can
 * render without re-parsing the whole log every paint.
 *
 * Reuses {@see LogParser} and {@see LogGrouper} directly rather than
 * routing through {@see LogRepository::query()}, because the repository
 * is shaped around pagination + filter application; stats want the full
 * (in-budget) entry stream and apply only window membership.
 *
 * Mute filtering is intentionally **not** applied: stats are the ground
 * truth of error volume, and muting is a Logs-view noise control. A
 * muted signature should still show up in totals so the admin sees
 * what's actually happening on the site.
 *
 * Results are cached in a transient keyed by (size, mtime, range,
 * bucket) with a 60s TTL. mtime change implicitly invalidates the
 * cache (different key), and the TTL bounds staleness for quiet logs
 * where mtime would otherwise stay frozen across many tab reopenings.
 */
final class LogStats {

	/**
	 * Upper bound on bytes read per stats query. Mirrors
	 * {@see LogRepository::MAX_BYTES_PER_QUERY} so a single tab open
	 * cannot blow the PHP memory_limit.
	 */
	public const MAX_BYTES_PER_QUERY = 50 * 1024 * 1024;

	/**
	 * Transient TTL. The parsed result for a given (size, mtime, range,
	 * bucket) is deterministic, so TTL only matters when the file is
	 * append-only quiet but the real wall-clock window has rolled — a
	 * minute is short enough that the rolled buckets reappear quickly.
	 */
	public const CACHE_TTL_SECONDS = 60;

	/**
	 * Transient key prefix. Combined with the 32-char md5 of the cache
	 * inputs, the final key is well under WordPress's 172-character
	 * transient-key limit.
	 */
	private const TRANSIENT_PREFIX = 'logscope_stats_';

	private const RANGE_SECONDS = array(
		'24h' => 86400,
		'7d'  => 604800,
		'30d' => 2592000,
	);

	private const BUCKET_SECONDS = array(
		'hour' => 3600,
		'day'  => 86400,
	);

	/**
	 * Maximum number of top signatures returned in the `top` field.
	 */
	public const TOP_N = 10;

	/**
	 * Underlying byte source.
	 *
	 * @var LogSourceInterface
	 */
	private LogSourceInterface $source;

	/**
	 * Builds a stats service over the given source.
	 *
	 * @param LogSourceInterface $source Validated log source.
	 */
	public function __construct( LogSourceInterface $source ) {
		$this->source = $source;
	}

	/**
	 * Returns the supported range tokens.
	 *
	 * @return string[]
	 */
	public static function ranges(): array {
		return array_keys( self::RANGE_SECONDS );
	}

	/**
	 * Returns the supported bucket tokens.
	 *
	 * @return string[]
	 */
	public static function buckets(): array {
		return array_keys( self::BUCKET_SECONDS );
	}

	/**
	 * Builds the bucketed summary for the given range + bucket.
	 *
	 * @param string                 $range  One of {@see self::ranges()}.
	 * @param string                 $bucket One of {@see self::buckets()}.
	 * @param DateTimeImmutable|null $now    Reference time (UTC). When null,
	 *                                       uses the wall clock; tests may
	 *                                       inject a fixed value for
	 *                                       deterministic bucket layout.
	 *
	 * @return array{
	 *     range: string,
	 *     bucket: string,
	 *     buckets: array<int, array<string, mixed>>,
	 *     totals: array<string, int>,
	 *     top: array<int, array{signature: string, count: int, severity: string, sample: string}>
	 * }
	 *
	 * @throws LogStatsException When the range or bucket token is unknown.
	 */
	public function summarize( string $range, string $bucket, ?DateTimeImmutable $now = null ): array {
		if ( ! isset( self::RANGE_SECONDS[ $range ] ) ) {
			throw new LogStatsException( 'Unknown range.' );
		}
		if ( ! isset( self::BUCKET_SECONDS[ $bucket ] ) ) {
			throw new LogStatsException( 'Unknown bucket.' );
		}

		$now_utc = $this->normalise_now( $now );

		$cache_key = $this->cache_key( $range, $bucket, $now_utc );
		if ( null !== $cache_key ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = $this->compute( $range, $bucket, $now_utc );

		if ( null !== $cache_key ) {
			set_transient( $cache_key, $result, self::CACHE_TTL_SECONDS );
		}

		return $result;
	}

	/**
	 * Parses entries, filters to the window, and folds them into the
	 * pre-allocated bucket grid.
	 *
	 * @param string            $range   Validated range token.
	 * @param string            $bucket  Validated bucket token.
	 * @param DateTimeImmutable $now_utc Reference time in UTC.
	 *
	 * @return array<string, mixed>
	 */
	private function compute( string $range, string $bucket, DateTimeImmutable $now_utc ): array {
		$bucket_seconds = self::BUCKET_SECONDS[ $bucket ];
		$range_seconds  = self::RANGE_SECONDS[ $range ];
		$bucket_count   = (int) ( $range_seconds / $bucket_seconds );

		$end_ts    = $this->snap_down( $now_utc, $bucket_seconds )->getTimestamp() + $bucket_seconds;
		$start_ts  = $end_ts - ( $bucket_count * $bucket_seconds );
		$buckets   = $this->empty_bucket_grid( $start_ts, $bucket_count, $bucket_seconds );
		$totals    = $this->empty_severity_map();
		$in_window = array();

		$entries = $this->load_entries();

		foreach ( $entries as $entry ) {
			$ts = $this->entry_timestamp( $entry );
			if ( null === $ts ) {
				continue;
			}
			$delta = $ts - $start_ts;
			if ( $delta < 0 || $delta >= $range_seconds ) {
				continue;
			}
			$index    = (int) ( $delta / $bucket_seconds );
			$severity = $entry->severity;
			if ( ! isset( $buckets[ $index ][ $severity ] ) ) {
				continue;
			}
			++$buckets[ $index ][ $severity ];
			++$totals[ $severity ];
			$in_window[] = $entry;
		}

		return array(
			'range'   => $range,
			'bucket'  => $bucket,
			'buckets' => $buckets,
			'totals'  => $totals,
			'top'     => $this->top_signatures( $in_window ),
		);
	}

	/**
	 * Reads the (possibly tail-clipped) log bytes and parses them.
	 * Mirrors {@see LogRepository::load_entries()} for the no-`since`
	 * path so stats inherit the same byte budget without re-implementing
	 * a parallel reader.
	 *
	 * @return Entry[]
	 */
	private function load_entries(): array {
		if ( ! $this->source->exists() ) {
			return array();
		}

		$size = $this->source->size();
		if ( 0 === $size ) {
			return array();
		}

		$offset = $size > self::MAX_BYTES_PER_QUERY
			? $size - self::MAX_BYTES_PER_QUERY
			: 0;
		$max    = $size - $offset;
		$chunk  = $this->source->read_chunk( $offset, $max );

		return LogParser::parse( $chunk );
	}

	/**
	 * Folds the in-window entries through {@see LogGrouper::group()} and
	 * returns the first {@see self::TOP_N} groups in a slim,
	 * dashboard-friendly shape.
	 *
	 * @param Entry[] $entries Entries already filtered to the window.
	 * @return array<int, array{signature: string, count: int, severity: string, sample: string}>
	 */
	private function top_signatures( array $entries ): array {
		if ( array() === $entries ) {
			return array();
		}

		$groups = LogGrouper::group( $entries );
		$slice  = array_slice( $groups, 0, self::TOP_N );

		$out = array();
		foreach ( $slice as $group ) {
			$out[] = array(
				'signature' => $group->signature,
				'count'     => $group->count,
				'severity'  => $group->severity,
				'sample'    => $group->sample_message,
			);
		}

		return $out;
	}

	/**
	 * Builds an empty grid of bucket slots, each carrying its absolute
	 * UTC timestamp and a zero per severity.
	 *
	 * @param int $start_ts       Window start (unix seconds, UTC).
	 * @param int $bucket_count   Number of buckets to allocate.
	 * @param int $bucket_seconds Bucket length in seconds.
	 * @return array<int, array<string, mixed>>
	 */
	private function empty_bucket_grid( int $start_ts, int $bucket_count, int $bucket_seconds ): array {
		$grid = array();
		for ( $i = 0; $i < $bucket_count; $i++ ) {
			$grid[ $i ] = array_merge(
				array( 'ts' => $start_ts + ( $i * $bucket_seconds ) ),
				$this->empty_severity_map()
			);
		}
		return $grid;
	}

	/**
	 * Returns a fresh severity → 0 map covering every defined severity,
	 * so consumers never have to coalesce a missing key to zero.
	 *
	 * @return array<string, int>
	 */
	private function empty_severity_map(): array {
		$map = array();
		foreach ( Severity::all() as $severity ) {
			$map[ $severity ] = 0;
		}
		return $map;
	}

	/**
	 * Parses an entry's WP-format timestamp into a unix timestamp,
	 * treating bare timestamps (no TZ token) as UTC since WordPress
	 * always logs in UTC.
	 *
	 * @param Entry $entry Parsed entry.
	 * @return int|null Unix seconds or null when unparseable.
	 */
	private function entry_timestamp( Entry $entry ): ?int {
		if ( null === $entry->timestamp ) {
			return null;
		}
		$parsed = DateTimeImmutable::createFromFormat(
			'd-M-Y H:i:s',
			$entry->timestamp,
			new DateTimeZone( 'UTC' )
		);
		if ( false === $parsed ) {
			return null;
		}
		return $parsed->getTimestamp();
	}

	/**
	 * Snaps a moment down to the start of its enclosing bucket. Hour
	 * buckets snap to `:00:00`; day buckets snap to `00:00:00 UTC`.
	 *
	 * @param DateTimeImmutable $moment         Reference moment (UTC).
	 * @param int               $bucket_seconds Bucket length.
	 * @return DateTimeImmutable
	 */
	private function snap_down( DateTimeImmutable $moment, int $bucket_seconds ): DateTimeImmutable {
		$ts      = $moment->getTimestamp();
		$snapped = $ts - ( $ts % $bucket_seconds );
		return ( new DateTimeImmutable( '@' . $snapped ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Coerces the optional caller `$now` into a UTC moment, falling back
	 * to the current wall clock when absent.
	 *
	 * @param DateTimeImmutable|null $now Caller-supplied reference moment.
	 * @return DateTimeImmutable
	 */
	private function normalise_now( ?DateTimeImmutable $now ): DateTimeImmutable {
		if ( null === $now ) {
			return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}
		return $now->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Builds the transient key for the current source state, or null
	 * when the source is missing (no point caching a "log absent"
	 * verdict — it's already cheap and would otherwise pin a stale key
	 * once the file appears).
	 *
	 * @param string            $range   Validated range token.
	 * @param string            $bucket  Validated bucket token.
	 * @param DateTimeImmutable $now_utc Reference time, included so a
	 *                                   bucket-boundary roll mints a new
	 *                                   key even when the file itself is
	 *                                   silent.
	 * @return string|null
	 */
	private function cache_key( string $range, string $bucket, DateTimeImmutable $now_utc ): ?string {
		if ( ! $this->source->exists() ) {
			return null;
		}

		$size  = $this->source->size();
		$mtime = $this->source_mtime();
		// Anchor the key on the snapped bucket boundary so every bucket
		// inside the same window shares a cache slot, but a roll into
		// the next bucket invalidates implicitly.
		$bucket_seconds = self::BUCKET_SECONDS[ $bucket ];
		$anchor         = $this->snap_down( $now_utc, $bucket_seconds )->getTimestamp();

		$digest = md5( $size . '|' . $mtime . '|' . $range . '|' . $bucket . '|' . $anchor );

		return self::TRANSIENT_PREFIX . $digest;
	}

	/**
	 * Returns the source file's mtime via the {@see LogSourceInterface}
	 * extension if available, otherwise 0. Most callers won't define the
	 * optional `mtime()` method, so we tolerate its absence.
	 *
	 * @return int
	 */
	private function source_mtime(): int {
		if ( method_exists( $this->source, 'mtime' ) ) {
			$mtime = $this->source->mtime();
			return is_int( $mtime ) ? $mtime : 0;
		}
		return 0;
	}
}
