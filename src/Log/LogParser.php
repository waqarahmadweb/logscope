<?php
/**
 * Parses raw `debug.log` text into structured Entry objects.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * Pure parser: text in, `Entry[]` out. No state, no side effects, no
 * filesystem access. This is the single most-tested class in the
 * project — every WordPress install on every host has slightly
 * different log noise, and the parser is what lets the rest of the
 * pipeline pretend the input is uniform.
 */
final class LogParser {

	/**
	 * Map of WP log severity tokens to internal `Severity` constants.
	 * Order matters: `Strict Standards` is matched before `Standards`
	 * could be consumed by anything else. Token strings come straight
	 * from the WP/PHP runtime and are not localised.
	 */
	private const SEVERITY_MAP = array(
		'Fatal error'      => Severity::FATAL,
		'Parse error'      => Severity::PARSE,
		'Warning'          => Severity::WARNING,
		'Notice'           => Severity::NOTICE,
		'Deprecated'       => Severity::DEPRECATED,
		'Strict Standards' => Severity::STRICT,
	);

	/**
	 * Splits a chunk of log text into entries.
	 *
	 * Lines starting with a `[DD-Mon-YYYY HH:MM:SS [TZ]]` timestamp
	 * begin a new entry; any other line is treated as a continuation
	 * (stack-trace row, "thrown in ..." tail, blank separator) and
	 * appended to the previous entry's `raw` field. Orphan
	 * continuation rows at the start of a chunk — likely a chunk
	 * boundary mid-entry — are dropped; the repository layer is
	 * responsible for stitching across chunks if it needs to.
	 *
	 * @param string $chunk Raw log text.
	 * @return Entry[]
	 */
	public static function parse( string $chunk ): array {
		if ( '' === $chunk ) {
			return array();
		}

		$lines = preg_split( '/\R/', $chunk );
		if ( false === $lines ) {
			return array();
		}

		$entries = array();
		$current = null;

		$last_index = count( $lines ) - 1;

		foreach ( $lines as $index => $line ) {
			$is_last = $last_index === $index;

			// Trailing newline produces an empty final element from
			// `preg_split` — ignore it rather than treating it as a
			// continuation row.
			if ( '' === $line && $is_last ) {
				continue;
			}

			$start = self::match_entry_start( $line );

			if ( null === $start ) {
				if ( null !== $current ) {
					$current->raw .= "\n" . $line;
				}
				continue;
			}

			if ( null !== $current ) {
				$entries[] = $current;
			}

			$current = self::build_entry( $start, $line );
		}

		if ( null !== $current ) {
			$entries[] = $current;
		}

		return $entries;
	}

	/**
	 * Attempts to match the `[timestamp] [PHP ]severity: message` shape
	 * at the start of a line. Returns the captured groups on success or
	 * `null` if the line is a continuation.
	 *
	 * @param string $line Single log line.
	 * @return array<string, string>|null
	 */
	private static function match_entry_start( string $line ): ?array {
		$pattern = '/^\[(?P<ts>\d{2}-[A-Za-z]{3}-\d{4}\s+\d{2}:\d{2}:\d{2})(?:\s+(?P<tz>[A-Za-z]+))?\]\s*(?P<rest>.*)$/';

		if ( 1 !== preg_match( $pattern, $line, $matches ) ) {
			return null;
		}

		return array(
			'ts'   => $matches['ts'],
			'tz'   => $matches['tz'] ?? '',
			'rest' => $matches['rest'],
		);
	}

	/**
	 * Builds an `Entry` from a successfully matched leading line.
	 *
	 * @param array<string, string> $start Captures from `match_entry_start`.
	 * @param string                $line  Original line (preserved as `raw`).
	 * @return Entry
	 */
	private static function build_entry( array $start, string $line ): Entry {
		$timezone   = '' === $start['tz'] ? null : $start['tz'];
		$rest       = $start['rest'];
		$severity   = Severity::UNKNOWN;
		$message    = $rest;
		$rest_trim  = ltrim( $rest );
		$has_php    = 0 === strncmp( $rest_trim, 'PHP ', 4 );
		$rest_after = $has_php ? ltrim( substr( $rest_trim, 4 ) ) : $rest_trim;

		foreach ( self::SEVERITY_MAP as $token => $constant ) {
			$token_len = strlen( $token );
			if ( 0 !== strncmp( $rest_after, $token, $token_len ) ) {
				continue;
			}

			$tail = substr( $rest_after, $token_len );
			if ( '' === $tail || ':' === $tail[0] ) {
				$severity = $constant;
				$message  = ltrim( substr( $tail, 1 ) );
				break;
			}
		}

		// Timestamp parsed but no severity token — keep the message as-is
		// (typical of `error_log("...")` calls and other custom output).
		// The leading "PHP " (if any) is not stripped because we never
		// recognised a severity to treat it as a prefix of.

		list( $file, $line_no ) = self::extract_file_and_line( $message );

		return new Entry(
			$severity,
			$start['ts'],
			$timezone,
			$message,
			$file,
			$line_no,
			$line
		);
	}

	/**
	 * Pulls a `file.php` path and 1-based source line out of a message
	 * line. Recognises both forms WordPress emits:
	 *
	 *   - `... in /path/to/file.php:42`           (uncaught exceptions)
	 *   - `... in /path/to/file.php on line 42`   (warnings, notices)
	 *
	 * Returns `[null, null]` when no location is present. Stack-trace
	 * frame paths in continuation lines are intentionally ignored;
	 * those are `StackTraceParser`'s job.
	 *
	 * @param string $message Message text from the leading line.
	 * @return array{0: ?string, 1: ?int}
	 */
	private static function extract_file_and_line( string $message ): array {
		$pattern = '/\bin\s+(?P<file>.+?\.php)(?::(?P<line1>\d+)|\s+on\s+line\s+(?P<line2>\d+))/';

		if ( 1 !== preg_match( $pattern, $message, $matches ) ) {
			return array( null, null );
		}

		$line_no = isset( $matches['line1'] ) && '' !== $matches['line1']
			? (int) $matches['line1']
			: ( isset( $matches['line2'] ) && '' !== $matches['line2'] ? (int) $matches['line2'] : null );

		return array( $matches['file'], $line_no );
	}
}
