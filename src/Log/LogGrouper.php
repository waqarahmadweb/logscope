<?php
/**
 * Signature-based grouping of parsed log entries.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

use DateTimeImmutable;

/**
 * Collapses parsed entries into groups of "the same error happening
 * over and over." Two entries land in the same group iff their
 * normalised shape — severity, file, line, and message-with-variable-
 * substrings-stripped — is identical.
 *
 * The signature is intentionally lossy: quoted strings, numbers, and
 * hex addresses are replaced with placeholders so that "Cannot find
 * post 1234" and "Cannot find post 9999" merge. The trade-off is that
 * a small number of distinct errors with very similar shapes can
 * collide, but the win on the grouped-view UI is much larger than the
 * downside.
 */
final class LogGrouper {

	/**
	 * WordPress timestamp format used by `Entry::$timestamp`.
	 */
	private const WP_TIMESTAMP_FORMAT = 'd-M-Y H:i:s';

	/**
	 * Computes the signature for one entry. Exposed so the future
	 * repository can key on signatures without first running the full
	 * group reduction.
	 *
	 * @param Entry $entry Parsed entry.
	 * @return string md5 hex digest.
	 */
	public static function signature( Entry $entry ): string {
		$key = implode(
			'|',
			array(
				$entry->severity,
				$entry->file ?? '',
				null === $entry->line ? '' : (string) $entry->line,
				self::normalise_message( $entry->message ),
			)
		);

		return md5( $key );
	}

	/**
	 * Reduces an entry list to groups, sorted by descending count
	 * (ties broken by most-recent `last_seen`, then by signature for
	 * stable order across runs).
	 *
	 * @param Entry[] $entries Parsed entries.
	 * @return Group[]
	 */
	public static function group( array $entries ): array {
		$groups = array();

		foreach ( $entries as $entry ) {
			$signature = self::signature( $entry );

			if ( ! isset( $groups[ $signature ] ) ) {
				$groups[ $signature ] = new Group(
					$signature,
					$entry->severity,
					$entry->file,
					$entry->line,
					$entry->message,
					0,
					null,
					null
				);
			}

			$group = $groups[ $signature ];
			++$group->count;

			if ( null !== $entry->timestamp ) {
				self::extend_window( $group, $entry->timestamp );
			}
		}

		$groups = array_values( $groups );

		usort(
			$groups,
			static function ( Group $a, Group $b ): int {
				if ( $a->count !== $b->count ) {
					return $b->count <=> $a->count;
				}

				$a_last = self::parse_timestamp( $a->last_seen );
				$b_last = self::parse_timestamp( $b->last_seen );

				if ( null !== $a_last && null !== $b_last && $a_last !== $b_last ) {
					return $b_last <=> $a_last;
				}

				return strcmp( $a->signature, $b->signature );
			}
		);

		return $groups;
	}

	/**
	 * Replaces volatile substrings in a message with placeholders so
	 * that messages differing only in variable content collapse to the
	 * same shape. Order matters: hex first (otherwise the digit rule
	 * eats the address bytes), then quoted strings (which themselves
	 * may contain digits), then bare digits last.
	 *
	 * @param string $message Original message text.
	 * @return string Normalised shape.
	 */
	private static function normalise_message( string $message ): string {
		$shape = preg_replace( '/0x[0-9a-fA-F]+/', '0xN', $message );
		$shape = preg_replace( "/'[^']*'/", "'?'", $shape ?? $message );
		$shape = preg_replace( '/"[^"]*"/', '"?"', $shape ?? $message );
		$shape = preg_replace( '/\d+/', 'N', $shape ?? $message );

		return $shape ?? $message;
	}

	/**
	 * Updates a group's first/last window with a freshly observed
	 * timestamp. Skips silently when the timestamp can't be parsed in
	 * the WP format — keeps the group count accurate without
	 * polluting the window with garbage.
	 *
	 * @param Group  $group     Group to update in place.
	 * @param string $timestamp Raw WP-format timestamp string.
	 */
	private static function extend_window( Group $group, string $timestamp ): void {
		$incoming = self::parse_timestamp( $timestamp );
		if ( null === $incoming ) {
			return;
		}

		$current_first = self::parse_timestamp( $group->first_seen );
		$current_last  = self::parse_timestamp( $group->last_seen );

		if ( null === $current_first || $incoming < $current_first ) {
			$group->first_seen = $timestamp;
		}

		if ( null === $current_last || $incoming > $current_last ) {
			$group->last_seen = $timestamp;
		}
	}

	/**
	 * Parses a WP-format timestamp into a DateTimeImmutable, or
	 * returns null when the input is null or doesn't match the format.
	 * The lexical order of the WP format is not the calendar order
	 * (Apr / Aug sort as A-B but March/May sort differently), so raw
	 * string comparison is unsafe and we go through the date parser.
	 *
	 * @param string|null $timestamp Raw timestamp text.
	 * @return DateTimeImmutable|null
	 */
	private static function parse_timestamp( ?string $timestamp ): ?DateTimeImmutable {
		if ( null === $timestamp || '' === $timestamp ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( self::WP_TIMESTAMP_FORMAT, $timestamp );

		return false === $parsed ? null : $parsed;
	}
}
