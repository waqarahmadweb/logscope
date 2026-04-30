<?php
/**
 * Validated request DTO for log queries.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

use DateTimeImmutable;

/**
 * Carries one fully-validated log query: severity filter, date
 * window, regex over the message, source-slug filter, grouped flag,
 * and pagination. Validation happens in the constructor so a bad
 * request fails fast; downstream code can trust every field.
 *
 * Public properties because PHP 8.0 lacks `readonly`. Mutating after
 * construction sidesteps validation, so don't.
 */
final class LogQuery {

	public const MAX_REGEX_LENGTH = 200;
	public const MIN_PER_PAGE     = 1;
	public const MAX_PER_PAGE     = 500;

	/**
	 * Severity constants to include, or `null` for "any severity."
	 *
	 * @var string[]|null
	 */
	public ?array $severities;

	/**
	 * Inclusive lower bound for entry timestamps, or `null` for unbounded.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $from;

	/**
	 * Inclusive upper bound for entry timestamps, or `null` for unbounded.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $to;

	/**
	 * Validated regex (without delimiters) to match against the entry
	 * message, or `null` for no regex filter.
	 *
	 * @var string|null
	 */
	public ?string $regex;

	/**
	 * Source slug to match exactly (e.g. `plugins/akismet`), or `null`
	 * for no source filter.
	 *
	 * @var string|null
	 */
	public ?string $source;

	/**
	 * When `true`, results are returned as `Group[]`; otherwise `Entry[]`.
	 *
	 * @var bool
	 */
	public bool $grouped;

	/**
	 * 1-based page index.
	 *
	 * @var int
	 */
	public int $page;

	/**
	 * Items per page, clamped to `[MIN_PER_PAGE, MAX_PER_PAGE]`.
	 *
	 * @var int
	 */
	public int $per_page;

	/**
	 * Byte offset at which the previous tail read ended. When set,
	 * the repository reads only bytes from this offset to EOF and
	 * skips pagination — tail mode wants every newly-appended entry.
	 *
	 * @var int|null
	 */
	public ?int $since_byte;

	/**
	 * When `true`, muted-signature filtering is bypassed and results
	 * include entries (or groups) whose signature is in the
	 * `MuteStore`. The default `false` is the production posture: the
	 * Logs view hides muted noise by default, and the management UI
	 * opts in via `?include_muted=true`.
	 *
	 * @var bool
	 */
	public bool $include_muted;

	/**
	 * Builds and validates a query. Throws on out-of-range pagination,
	 * malformed/oversize regex, or unparseable date strings.
	 *
	 * @param string[]|null $severities    Severity constants, or null.
	 * @param string|null   $from          Lower bound, `Y-m-d` or `Y-m-d H:i:s`.
	 * @param string|null   $to            Upper bound, same format.
	 * @param string|null   $regex         Pattern body without delimiters.
	 * @param string|null   $source        Source slug.
	 * @param bool          $grouped       Group results by signature.
	 * @param int           $page          1-based page index.
	 * @param int           $per_page      Items per page.
	 * @param int|null      $since_byte    Tail-mode byte offset, or null.
	 * @param bool          $include_muted Bypass mute filter when true.
	 *
	 * @throws LogQueryException When validation fails.
	 */
	public function __construct(
		?array $severities,
		?string $from,
		?string $to,
		?string $regex,
		?string $source,
		bool $grouped,
		int $page,
		int $per_page,
		?int $since_byte = null,
		bool $include_muted = false
	) {
		$this->severities = self::sanitise_severities( $severities );
		$this->from       = self::parse_bound( $from, 'from' );
		$this->to         = self::parse_bound( $to, 'to' );
		$this->regex      = self::sanitise_regex( $regex );
		$this->source     = ( null === $source || '' === $source ) ? null : $source;
		$this->grouped    = $grouped;

		if ( $page < 1 ) {
			throw new LogQueryException( 'Page must be 1 or greater.' );
		}

		if ( $per_page < self::MIN_PER_PAGE || $per_page > self::MAX_PER_PAGE ) {
			// LogQueryException is internal; the REST controller maps it to
			// a sanitised 400 response rather than echoing the raw text.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new LogQueryException(
				sprintf(
					'per_page must be between %d and %d.',
					self::MIN_PER_PAGE,
					self::MAX_PER_PAGE
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( null !== $since_byte && $since_byte < 0 ) {
			throw new LogQueryException( 'since_byte must be 0 or greater.' );
		}

		$this->page          = $page;
		$this->per_page      = $per_page;
		$this->since_byte    = $since_byte;
		$this->include_muted = $include_muted;
	}

	/**
	 * Wraps the validated regex body in delimiters ready for `preg_match`.
	 * Returns `null` when no regex filter was set.
	 *
	 * @return string|null
	 */
	public function compiled_regex(): ?string {
		if ( null === $this->regex ) {
			return null;
		}

		return '~' . str_replace( '~', '\~', $this->regex ) . '~u';
	}

	/**
	 * Filters out unknown severity constants and returns null for
	 * "no severity filter" cases (null input or empty array).
	 *
	 * @param string[]|null $severities Caller-provided list.
	 * @return string[]|null
	 */
	private static function sanitise_severities( ?array $severities ): ?array {
		if ( null === $severities || array() === $severities ) {
			return null;
		}

		$valid    = Severity::all();
		$filtered = array();
		foreach ( $severities as $severity ) {
			if ( is_string( $severity ) && in_array( $severity, $valid, true ) ) {
				$filtered[] = $severity;
			}
		}

		return array() === $filtered ? null : array_values( array_unique( $filtered ) );
	}

	/**
	 * Parses an inclusive date bound. Accepts `Y-m-d` (treated as
	 * midnight) and `Y-m-d H:i:s`.
	 *
	 * @param string|null $value Caller input.
	 * @param string      $label Field name for error messages.
	 * @return DateTimeImmutable|null
	 *
	 * @throws LogQueryException When the value is non-empty but unparseable.
	 */
	private static function parse_bound( ?string $value, string $label ): ?DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}

		// `Y-m-d` is intentionally suffixed with `|` so missing time
		// components reset to 00:00:00 rather than inheriting the
		// current time of day (PHP's createFromFormat default), which
		// would make the date-range filter flaky after noon UTC.
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d|' ) as $format ) {
			$parsed = DateTimeImmutable::createFromFormat( $format, $value );
			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		// LogQueryException is internal; the REST controller maps it to
		// a sanitised 400 response rather than echoing the raw text.
		throw new LogQueryException( sprintf( 'Invalid %s date: %s', $label, $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Validates a regex pattern body: enforces length cap and tries
	 * compiling it (with our delimiters) so an invalid pattern fails
	 * before it reaches `preg_match` in the hot path.
	 *
	 * @param string|null $regex Caller pattern, no delimiters.
	 * @return string|null
	 *
	 * @throws LogQueryException When the pattern is too long or invalid.
	 */
	private static function sanitise_regex( ?string $regex ): ?string {
		if ( null === $regex || '' === $regex ) {
			return null;
		}

		if ( strlen( $regex ) > self::MAX_REGEX_LENGTH ) {
			// LogQueryException is internal; the REST controller maps it to
			// a sanitised 400 response rather than echoing the raw text.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new LogQueryException(
				sprintf( 'Regex must be %d characters or fewer.', self::MAX_REGEX_LENGTH )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$compiled = '~' . str_replace( '~', '\~', $regex ) . '~u';

		// Suppress the warning that `preg_match` raises on bad patterns;
		// the false return is the contract we care about.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === @preg_match( $compiled, '' ) ) {
			throw new LogQueryException( 'Invalid regular expression.' );
		}

		return $regex;
	}
}
