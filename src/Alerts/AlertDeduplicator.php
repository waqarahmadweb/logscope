<?php
/**
 * Signature-keyed dedup for alert dispatchers.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Alerts;

/**
 * Transient-backed dedup window keyed by `(dispatcher_name, signature)`.
 * Prevents a single recurring fatal from triggering hundreds of emails
 * (or webhooks) in rapid succession.
 *
 * Each (dispatcher, signature) pair gets its own window so an email
 * silenced on one signature does not silence the webhook on the same
 * signature, and vice versa. The window length is configurable so admins
 * with quieter sites can lengthen it without touching code.
 *
 * Why transients (not options): a transient is the canonical
 * WordPress primitive for "remember this for N seconds and then forget."
 * On installs with object caching it stays in-memory; without it, it
 * lives in `wp_options` with an `_transient_timeout_*` sibling that the
 * cron sweep prunes. The dedup state is intentionally ephemeral — losing
 * it (cache flush, restart) at worst means one extra alert fires, not a
 * stuck mute.
 */
class AlertDeduplicator {

	/**
	 * Transient key prefix. Combined with dispatcher name + signature
	 * hash, the final key stays well under WordPress's 172-character
	 * transient-key limit (`logscope_alert_<name>_<32-hex>` ≈ 60 chars).
	 */
	private const TRANSIENT_PREFIX = 'logscope_alert_';

	/**
	 * Floor on the dedup window. Anything shorter than 60 seconds defeats
	 * the purpose of dedup on a real-world site — a noisy fatal can fire
	 * thousands of times per minute.
	 */
	private const MIN_WINDOW_SECONDS = 60;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	private int $window_seconds;

	/**
	 * Constructor.
	 *
	 * @param int $window_seconds Window length in seconds; coerced to the
	 *                            floor when given a smaller value.
	 */
	public function __construct( int $window_seconds = 300 ) {
		$this->window_seconds = $window_seconds < self::MIN_WINDOW_SECONDS
			? self::MIN_WINDOW_SECONDS
			: $window_seconds;
	}

	/**
	 * Returns the active window length in seconds.
	 *
	 * @return int
	 */
	public function window_seconds(): int {
		return $this->window_seconds;
	}

	/**
	 * Returns true if the given (dispatcher, signature) pair has not been
	 * recorded inside the current window — i.e. the dispatcher should
	 * send the alert.
	 *
	 * @param string $dispatcher_name Stable backend identifier.
	 * @param string $signature       Group signature hash.
	 * @return bool
	 */
	public function should_send( string $dispatcher_name, string $signature ): bool {
		$key = $this->key_for( $dispatcher_name, $signature );

		return false === get_transient( $key );
	}

	/**
	 * Records that an alert was sent for the given (dispatcher, signature)
	 * pair so subsequent calls to {@see AlertDeduplicator::should_send()}
	 * return false until the window expires.
	 *
	 * @param string $dispatcher_name Stable backend identifier.
	 * @param string $signature       Group signature hash.
	 * @return void
	 */
	public function record_sent( string $dispatcher_name, string $signature ): void {
		$key = $this->key_for( $dispatcher_name, $signature );

		set_transient( $key, 1, $this->window_seconds );
	}

	/**
	 * Removes the dedup mark for a (dispatcher, signature) pair. Used by
	 * the test-alert endpoint so an admin who hits "Send test alert"
	 * twice in quick succession is not silently rate-limited; the test
	 * surface is the one place we always want to fire.
	 *
	 * @param string $dispatcher_name Stable backend identifier.
	 * @param string $signature       Group signature hash.
	 * @return void
	 */
	public function clear( string $dispatcher_name, string $signature ): void {
		delete_transient( $this->key_for( $dispatcher_name, $signature ) );
	}

	/**
	 * Builds the transient key. Sanitises the dispatcher name to
	 * `[a-z0-9_]` so a misbehaving extension cannot inject characters
	 * that would break the key shape.
	 *
	 * @param string $dispatcher_name Stable backend identifier.
	 * @param string $signature       Group signature hash.
	 * @return string
	 */
	private function key_for( string $dispatcher_name, string $signature ): string {
		$safe_name = strtolower( (string) preg_replace( '/[^a-z0-9_]/i', '', $dispatcher_name ) );
		if ( '' === $safe_name ) {
			$safe_name = 'unknown';
		}

		return self::TRANSIENT_PREFIX . $safe_name . '_' . $signature;
	}
}
