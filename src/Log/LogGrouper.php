<?php
/**
 * Signature-based grouping of parsed log entries.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

defined( 'ABSPATH' ) || exit;

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
	 * Per-Entry signature memo. When any signature is muted, list-mode
	 * queries recompute the signature for every entry pre-pagination and
	 * again at serialisation — the WeakMap makes the second pass free
	 * without widening the Entry DTO, and entries garbage-collect out.
	 *
	 * @var \WeakMap<Entry, string>|null
	 */
	private static ?\WeakMap $signature_memo = null;

	/**
	 * Computes the signature for one entry. Exposed so the future
	 * repository can key on signatures without first running the full
	 * group reduction.
	 *
	 * @param Entry $entry Parsed entry.
	 * @return string md5 hex digest.
	 */
	public static function signature( Entry $entry ): string {
		if ( null === self::$signature_memo ) {
			self::$signature_memo = new \WeakMap();
		}

		if ( isset( self::$signature_memo[ $entry ] ) ) {
			return self::$signature_memo[ $entry ];
		}

		$key = implode(
			'|',
			array(
				$entry->severity,
				$entry->file ?? '',
				null === $entry->line ? '' : (string) $entry->line,
				self::normalise_message( $entry->message ),
			)
		);

		$signature = md5( $key );

		self::$signature_memo[ $entry ] = $signature;

		return $signature;
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

		// Unix first/last per signature, tracked alongside the Group so
		// each occurrence costs one timestamp parse (not a re-parse of the
		// group's stored strings) and the sort below is integer compares.
		$windows = array();

		foreach ( $entries as $entry ) {
			$signature = self::signature( $entry );

			if ( ! isset( $groups[ $signature ] ) ) {
				$groups[ $signature ]  = new Group(
					$signature,
					$entry->severity,
					$entry->file,
					$entry->line,
					$entry->message,
					0,
					null,
					null
				);
				$windows[ $signature ] = array(
					'first' => null,
					'last'  => null,
				);
			}

			$group = $groups[ $signature ];
			++$group->count;

			if ( null !== $entry->timestamp ) {
				$incoming = self::parse_timestamp_unix( $entry->timestamp );
				if ( null !== $incoming ) {
					$window = $windows[ $signature ];
					if ( null === $window['first'] || $incoming < $window['first'] ) {
						$window['first']   = $incoming;
						$group->first_seen = $entry->timestamp;
					}
					if ( null === $window['last'] || $incoming > $window['last'] ) {
						$window['last']   = $incoming;
						$group->last_seen = $entry->timestamp;
					}
					$windows[ $signature ] = $window;
				}
			}
		}

		$groups = array_values( $groups );

		usort(
			$groups,
			static function ( Group $a, Group $b ) use ( $windows ): int {
				if ( $a->count !== $b->count ) {
					return $b->count <=> $a->count;
				}

				$a_last = $windows[ $a->signature ]['last'] ?? null;
				$b_last = $windows[ $b->signature ]['last'] ?? null;

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
	 * Parses a WP-format timestamp into unix seconds, or returns null
	 * when the input doesn't match the format. The lexical order of the
	 * WP format is not the calendar order (months sort alphabetically),
	 * so raw string comparison is unsafe and we go through the parser.
	 *
	 * @param string $timestamp Raw timestamp text.
	 * @return int|null
	 */
	private static function parse_timestamp_unix( string $timestamp ): ?int {
		if ( '' === $timestamp ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( self::WP_TIMESTAMP_FORMAT, $timestamp );

		return false === $parsed ? null : $parsed->getTimestamp();
	}
}
