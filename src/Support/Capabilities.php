<?php
/**
 * Capability helper.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Support;

/**
 * Single source of truth for the capability required to manage Logscope.
 * Centralizing here lets site owners remap via the
 * `logscope/required_capability` filter without touching callers.
 */
final class Capabilities {

	/**
	 * Default capability granted to administrators on activation.
	 */
	public const DEFAULT_CAPABILITY = 'logscope_manage';

	/**
	 * Resolves the capability required to manage Logscope, honoring the
	 * `logscope/required_capability` filter. Falls back to the default if
	 * the filter returns a non-string or empty value so a misbehaving
	 * filter cannot silently disable authorization.
	 *
	 * @return string
	 */
	public static function required(): string {
		/**
		 * Filters the capability required to access Logscope features.
		 *
		 * @param string $capability Default `logscope_manage`.
		 */
		$filtered = apply_filters( 'logscope/required_capability', self::DEFAULT_CAPABILITY );

		if ( ! is_string( $filtered ) || '' === $filtered ) {
			return self::DEFAULT_CAPABILITY;
		}

		return $filtered;
	}

	/**
	 * Returns true when the given user (or current user if null) has the
	 * Logscope management capability.
	 *
	 * @param int|null $user_id Optional user id; null uses the current user.
	 * @return bool
	 */
	public static function has_manage_cap( ?int $user_id = null ): bool {
		$capability = self::required();

		if ( null === $user_id ) {
			return (bool) current_user_can( $capability );
		}

		return (bool) user_can( $user_id, $capability );
	}
}
