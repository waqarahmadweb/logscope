<?php
/**
 * Per-user filter preset store.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Settings;

/**
 * Wraps the `logscope_filter_presets` user-meta key with a typed CRUD
 * surface. Each user owns their own list rather than sharing a global
 * option — on a multi-admin site, presets are personal triage tools
 * and surfacing a colleague's "Marc's noisy plugin investigation"
 * preset to everyone would be friction without value.
 *
 * Storage shape (JSON-friendly): a numerically-indexed list of
 * `{name, filters: {severity, from, to, q, source, viewMode}}`. A name
 * uniquely identifies a preset within a user's list — `save()`
 * overwrites by name rather than appending a duplicate.
 *
 * Allowed `filters` keys are restricted at the boundary so a future
 * filter dimension cannot land in user meta without a schema bump and
 * an old version of the plugin reading the row cannot trip over an
 * unfamiliar key.
 */
final class PresetStore {

	/**
	 * User-meta key holding the preset list.
	 */
	public const META_KEY = 'logscope_filter_presets';

	/**
	 * Closed list of filter keys that may be persisted in a preset.
	 * Anything outside this list is silently dropped at the boundary.
	 */
	public const ALLOWED_FILTER_KEYS = array(
		'severity',
		'from',
		'to',
		'q',
		'source',
		'viewMode',
	);

	/**
	 * Maximum preset name length. Picked at 80 chars to fit a human
	 * label without inviting paragraph-length names that would break
	 * the dropdown layout.
	 */
	public const MAX_NAME_LENGTH = 80;

	/**
	 * Returns the preset list for the given user, or an empty list
	 * when the user has no presets or the row is corrupt.
	 *
	 * @param int $user_id Acting user id; 0 returns an empty list.
	 * @return list<array{name:string, filters:array<string, mixed>}>
	 */
	public function list( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			$normalised = $this->normalise_entry( $entry );
			if ( null !== $normalised ) {
				$out[] = $normalised;
			}
		}

		return $out;
	}

	/**
	 * Saves a preset for the given user, overwriting any existing
	 * entry with the same name.
	 *
	 * @param int                  $user_id Acting user id.
	 * @param string               $name    Caller-supplied label.
	 * @param array<string, mixed> $filters Caller-supplied filter shape.
	 * @return bool True on success.
	 */
	public function save( int $user_id, string $name, array $filters ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$trimmed = trim( $name );
		if ( '' === $trimmed ) {
			return false;
		}

		if ( strlen( $trimmed ) > self::MAX_NAME_LENGTH ) {
			$trimmed = substr( $trimmed, 0, self::MAX_NAME_LENGTH );
		}

		$normalised_filters = $this->sanitise_filters( $filters );

		$current = $this->list( $user_id );
		$next    = array();
		$placed  = false;

		foreach ( $current as $existing ) {
			if ( $existing['name'] === $trimmed ) {
				$next[] = array(
					'name'    => $trimmed,
					'filters' => $normalised_filters,
				);
				$placed = true;
				continue;
			}
			$next[] = $existing;
		}

		if ( ! $placed ) {
			$next[] = array(
				'name'    => $trimmed,
				'filters' => $normalised_filters,
			);
		}

		update_user_meta( $user_id, self::META_KEY, $next );
		return true;
	}

	/**
	 * Removes a preset by name. Returns true when a record was removed.
	 *
	 * @param int    $user_id Acting user id.
	 * @param string $name    Preset name.
	 * @return bool
	 */
	public function delete( int $user_id, string $name ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$current = $this->list( $user_id );
		$next    = array();
		$removed = false;

		foreach ( $current as $existing ) {
			if ( $existing['name'] === $name ) {
				$removed = true;
				continue;
			}
			$next[] = $existing;
		}

		if ( ! $removed ) {
			return false;
		}

		update_user_meta( $user_id, self::META_KEY, $next );
		return true;
	}

	/**
	 * Coerces one stored entry into the canonical shape, or returns
	 * `null` when the entry is unrecoverable.
	 *
	 * @param mixed $entry Possibly-corrupt persisted entry.
	 * @return array{name:string, filters:array<string, mixed>}|null
	 */
	private function normalise_entry( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$name = isset( $entry['name'] ) && is_string( $entry['name'] ) ? trim( $entry['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}

		$filters = isset( $entry['filters'] ) && is_array( $entry['filters'] )
			? $entry['filters']
			: array();

		return array(
			'name'    => $name,
			'filters' => $this->sanitise_filters( $filters ),
		);
	}

	/**
	 * Drops keys outside {@see PresetStore::ALLOWED_FILTER_KEYS} and
	 * coerces leaf values to scalar/array shapes that survive a JSON
	 * round trip cleanly. Severity is stored as a list of strings;
	 * everything else is stringified.
	 *
	 * @param array<string, mixed> $filters Raw filter map.
	 * @return array<string, mixed>
	 */
	private function sanitise_filters( array $filters ): array {
		$out = array();
		foreach ( self::ALLOWED_FILTER_KEYS as $key ) {
			if ( ! array_key_exists( $key, $filters ) ) {
				continue;
			}
			$value = $filters[ $key ];

			if ( 'severity' === $key ) {
				if ( ! is_array( $value ) ) {
					continue;
				}
				$severities = array();
				foreach ( $value as $severity ) {
					if ( is_string( $severity ) && '' !== $severity ) {
						$severities[] = $severity;
					}
				}
				$out[ $key ] = array_values( array_unique( $severities ) );
				continue;
			}

			if ( null === $value || is_array( $value ) ) {
				continue;
			}

			$out[ $key ] = (string) $value;
		}

		return $out;
	}
}
