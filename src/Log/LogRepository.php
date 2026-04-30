<?php
/**
 * Facade over LogSource + LogParser + LogGrouper.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

use DateTimeImmutable;

/**
 * Single point of access for filtered, paginated log views. Reads
 * bytes through the injected source, parses them, applies the
 * query's filters, optionally groups, and slices a page out of the
 * result. The REST controller wraps this directly.
 *
 * The whole-file read is bounded by `MAX_BYTES_PER_QUERY` — for
 * pathological multi-GB logs we read only the trailing window and
 * the parser naturally drops the orphan continuation at the cutoff.
 * Chunked offset-based iteration is a future optimisation and would
 * change the constructor surface, not the query API.
 */
final class LogRepository {

	/**
	 * Upper bound on bytes read per query. Picked large enough to
	 * cover normal sites (months of debug.log on a healthy install
	 * is well under this) and small enough that PHP's default 128MB
	 * memory_limit isn't at risk during parsing.
	 */
	public const MAX_BYTES_PER_QUERY = 50 * 1024 * 1024;

	/**
	 * Underlying byte source.
	 *
	 * @var LogSourceInterface
	 */
	private LogSourceInterface $source;

	/**
	 * Mute store consulted on every non-tail query (Phase 14.6). Optional
	 * because legacy callers — and the test fixtures — construct the
	 * repository with a single argument; absent store means "no muting,"
	 * preserving pre-14.6 behaviour.
	 *
	 * @var MuteStore|null
	 */
	private ?MuteStore $mute_store;

	/**
	 * Builds a repository over the given byte source.
	 *
	 * @param LogSourceInterface $source     Validated, ready-to-read source.
	 * @param MuteStore|null     $mute_store Optional mute store; when null,
	 *                                       muted-signature filtering is
	 *                                       skipped entirely.
	 */
	public function __construct( LogSourceInterface $source, ?MuteStore $mute_store = null ) {
		$this->source     = $source;
		$this->mute_store = $mute_store;
	}

	/**
	 * Runs a query and returns a single page of results.
	 *
	 * @param LogQuery $query Validated query.
	 * @return PagedResult
	 */
	public function query( LogQuery $query ): PagedResult {
		$last_byte = $this->source->exists() ? $this->source->size() : 0;

		// File-shrink detection: if the caller's tail cursor is strictly
		// past current EOF, the log was rotated or cleared between
		// polls. We re-read the whole new file (subject to MAX_BYTES)
		// and signal `rotated=true` so the client can replace its list
		// rather than appending stale-cursor zero-length deltas.
		$rotated = null !== $query->since_byte && $query->since_byte > $last_byte;
		$since   = $rotated ? null : $query->since_byte;

		$entries = $this->load_entries( $since, $last_byte );
		$entries = $this->apply_filters( $entries, $query );

		// Tail mode: skip grouping and pagination — the client wants
		// every newly-appended entry in chronological order, smallest
		// possible response so polling stays cheap.
		if ( null !== $query->since_byte ) {
			$count = count( $entries );
			return new PagedResult(
				$entries,
				$count,
				1,
				max( 1, $count ),
				1,
				$last_byte,
				$rotated
			);
		}

		$muted_signatures = $this->resolve_muted_signatures( $query );

		if ( $query->grouped ) {
			$groups = LogGrouper::group( $entries );

			if ( array() !== $muted_signatures ) {
				$groups = $this->filter_groups_by_mute( $groups, $muted_signatures );
			}

			return $this->paginate( $groups, $query, $last_byte );
		}

		if ( array() !== $muted_signatures ) {
			$entries = $this->filter_entries_by_mute( $entries, $muted_signatures );
		}

		// Newest-first: WP appends to the log so reverse of file order
		// approximates timestamp-desc without a per-entry sort cost.
		$entries = array_reverse( $entries );

		return $this->paginate( $entries, $query, $last_byte );
	}

	/**
	 * Returns the muted-signature lookup map used by the filter, or an
	 * empty array when muting is bypassed for this query (no store
	 * configured, `include_muted=true`, or the store is empty).
	 *
	 * @param LogQuery $query Active query.
	 * @return array<string, true>
	 */
	private function resolve_muted_signatures( LogQuery $query ): array {
		if ( null === $this->mute_store || $query->include_muted ) {
			return array();
		}

		$signatures = $this->mute_store->signatures();
		if ( array() === $signatures ) {
			return array();
		}

		return array_fill_keys( $signatures, true );
	}

	/**
	 * Drops entries whose signature is in the muted lookup.
	 *
	 * @param Entry[]             $entries Filtered entries.
	 * @param array<string, true> $muted   Muted-signature lookup.
	 * @return Entry[]
	 */
	private function filter_entries_by_mute( array $entries, array $muted ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			$signature = LogGrouper::signature( $entry );
			if ( isset( $muted[ $signature ] ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Drops groups whose signature is in the muted lookup.
	 *
	 * @param Group[]             $groups Grouped result.
	 * @param array<string, true> $muted  Muted-signature lookup.
	 * @return Group[]
	 */
	private function filter_groups_by_mute( array $groups, array $muted ): array {
		$out = array();
		foreach ( $groups as $group ) {
			if ( isset( $muted[ $group->signature ] ) ) {
				continue;
			}
			$out[] = $group;
		}
		return $out;
	}

	/**
	 * Returns the distinct source slugs present in the current log
	 * (post-filter), useful for populating the source-filter dropdown.
	 * Wraps `query()` indirectly to stay consistent with the same
	 * read budget and parser behaviour.
	 *
	 * @return string[] Sorted, deduplicated source slugs.
	 */
	public function distinct_sources(): array {
		$size    = $this->source->exists() ? $this->source->size() : 0;
		$entries = $this->load_entries( null, $size );
		$sources = array();

		foreach ( $entries as $entry ) {
			$source = SourceClassifier::classify( $entry->file );
			if ( null !== $source ) {
				$sources[ $source ] = true;
			}
		}

		$out = array_keys( $sources );
		sort( $out );

		return $out;
	}

	/**
	 * Reads the (possibly tail-clipped) log bytes and parses them.
	 * When `$since` is non-null, reads only bytes from that offset
	 * forward — the tail-mode fast path.
	 *
	 * @param int|null $since Byte offset to start at, or null for full read.
	 * @param int      $size  Pre-fetched source size (avoids a second stat).
	 * @return Entry[]
	 */
	private function load_entries( ?int $since, int $size ): array {
		if ( ! $this->source->exists() || 0 === $size ) {
			return array();
		}

		if ( null !== $since ) {
			// `since` past EOF is a no-op (the file may have been rotated
			// or cleared between polls); a `since` ahead of the file we
			// can see is treated as "nothing new since you last asked."
			if ( $since >= $size ) {
				return array();
			}
			$offset = $since;
		} else {
			$offset = $size > self::MAX_BYTES_PER_QUERY
				? $size - self::MAX_BYTES_PER_QUERY
				: 0;
		}

		$max   = $size - $offset;
		$chunk = $this->source->read_chunk( $offset, $max );

		return LogParser::parse( $chunk );
	}

	/**
	 * Applies the query's severity, date, source, and regex filters
	 * to the parsed entry list. Entries with unparseable timestamps
	 * are dropped only when a date bound is set; otherwise they pass.
	 *
	 * @param Entry[]  $entries Parsed entries.
	 * @param LogQuery $query   Validated query.
	 * @return Entry[]
	 */
	private function apply_filters( array $entries, LogQuery $query ): array {
		$severities = $query->severities;
		$source     = $query->source;
		$regex      = $query->compiled_regex();
		$has_date   = null !== $query->from || null !== $query->to;

		$out = array();

		foreach ( $entries as $entry ) {
			if ( null !== $severities && ! in_array( $entry->severity, $severities, true ) ) {
				continue;
			}

			if ( null !== $source && SourceClassifier::classify( $entry->file ) !== $source ) {
				continue;
			}

			if ( null !== $regex && 1 !== preg_match( $regex, $entry->message ) ) {
				continue;
			}

			if ( $has_date && ! $this->date_in_range( $entry->timestamp, $query->from, $query->to ) ) {
				continue;
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Slices a page out of items and wraps it in a PagedResult.
	 *
	 * @param Entry[]|Group[] $items     All items after filtering.
	 * @param LogQuery        $query     Source query for page/per_page.
	 * @param int             $last_byte Source size at read time.
	 * @return PagedResult
	 */
	private function paginate( array $items, LogQuery $query, int $last_byte ): PagedResult {
		$total       = count( $items );
		$total_pages = $total > 0 ? (int) ceil( $total / $query->per_page ) : 1;
		$start       = ( $query->page - 1 ) * $query->per_page;
		$slice       = array_slice( $items, $start, $query->per_page );

		return new PagedResult( $slice, $total, $query->page, $query->per_page, $total_pages, $last_byte );
	}

	/**
	 * True when the entry's timestamp falls inside the inclusive
	 * `[from, to]` window. Entries with unparseable timestamps return
	 * false so the caller can drop them when a date bound exists.
	 *
	 * @param string|null            $timestamp Raw WP-format timestamp.
	 * @param DateTimeImmutable|null $from      Lower bound or null.
	 * @param DateTimeImmutable|null $to        Upper bound or null.
	 * @return bool
	 */
	private function date_in_range(
		?string $timestamp,
		?DateTimeImmutable $from,
		?DateTimeImmutable $to
	): bool {
		if ( null === $timestamp ) {
			return false;
		}

		$parsed = DateTimeImmutable::createFromFormat( 'd-M-Y H:i:s', $timestamp );
		if ( false === $parsed ) {
			return false;
		}

		if ( null !== $from && $parsed < $from ) {
			return false;
		}

		if ( null !== $to && $parsed > $to ) {
			return false;
		}

		return true;
	}
}
