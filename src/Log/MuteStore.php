<?php
/**
 * Persistent store of muted log signatures.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * Wraps the `logscope_muted_signatures` option with a typed CRUD surface.
 *
 * Storage shape (JSON-friendly): an associative array keyed by
 * signature so existence checks and re-mute updates are O(1) without a
 * second index, with each entry holding `signature`, `reason`,
 * `muted_at` (Unix timestamp), and `muted_by` (user id, 0 for
 * system-driven calls). Re-muting an existing signature updates the
 * record in place rather than appending a duplicate.
 *
 * The class is intentionally WordPress-aware (reads/writes via
 * `get_option` / `update_option`) — Logscope's other persistence
 * services follow the same convention and a parallel "pure" abstraction
 * would not pay for itself given the single caller surface.
 */
final class MuteStore {

	/**
	 * Option key holding the muted-signature map.
	 */
	public const OPTION_KEY = 'logscope_muted_signatures';

	/**
	 * Adds or updates a mute record for the given signature.
	 *
	 * @param string $signature Signature hash from {@see LogGrouper::signature()}.
	 * @param string $reason    Free-form admin note. Stored verbatim after
	 *                          `wp_strip_all_tags` so a careless paste cannot
	 *                          inject markup into the management UI.
	 * @param int    $user_id   Acting user id; 0 for non-user contexts.
	 * @return void
	 */
	public function add( string $signature, string $reason, int $user_id ): void {
		if ( '' === $signature ) {
			return;
		}

		$records               = $this->load();
		$records[ $signature ] = array(
			'signature' => $signature,
			'reason'    => wp_strip_all_tags( $reason ),
			'muted_at'  => time(),
			'muted_by'  => $user_id < 0 ? 0 : $user_id,
		);

		update_option( self::OPTION_KEY, $records, false );
	}

	/**
	 * Removes the mute record for the given signature. No-op when the
	 * signature is not currently muted.
	 *
	 * @param string $signature Signature to unmute.
	 * @return bool True when a record was removed.
	 */
	public function remove( string $signature ): bool {
		$records = $this->load();
		if ( ! isset( $records[ $signature ] ) ) {
			return false;
		}

		unset( $records[ $signature ] );
		update_option( self::OPTION_KEY, $records, false );
		return true;
	}

	/**
	 * Returns the full list of mute records as a numerically-indexed
	 * array (the keyed shape is an internal implementation detail; the
	 * REST surface is a list).
	 *
	 * @return list<array{signature:string, reason:string, muted_at:int, muted_by:int}>
	 */
	public function list(): array {
		return array_values( $this->load() );
	}

	/**
	 * Returns true when the signature has a mute record.
	 *
	 * @param string $signature Signature to test.
	 * @return bool
	 */
	public function is_muted( string $signature ): bool {
		if ( '' === $signature ) {
			return false;
		}

		$records = $this->load();
		return isset( $records[ $signature ] );
	}

	/**
	 * Returns the set of muted signatures as a flat string list. Used
	 * by `LogRepository` to filter without instantiating the full
	 * record array on each entry.
	 *
	 * @return list<string>
	 */
	public function signatures(): array {
		return array_keys( $this->load() );
	}

	/**
	 * Loads the stored map, defending against corruption from a prior
	 * version by dropping any non-array entries rather than throwing.
	 *
	 * @return array<string, array{signature:string, reason:string, muted_at:int, muted_by:int}>
	 */
	private function load(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $signature => $record ) {
			if ( ! is_string( $signature ) || '' === $signature ) {
				continue;
			}
			if ( ! is_array( $record ) ) {
				continue;
			}

			$out[ $signature ] = array(
				'signature' => $signature,
				'reason'    => isset( $record['reason'] ) && is_string( $record['reason'] ) ? $record['reason'] : '',
				'muted_at'  => isset( $record['muted_at'] ) && is_numeric( $record['muted_at'] ) ? (int) $record['muted_at'] : 0,
				'muted_by'  => isset( $record['muted_by'] ) && is_numeric( $record['muted_by'] ) ? (int) $record['muted_by'] : 0,
			);
		}

		return $out;
	}
}
