<?php
/**
 * Interface for alert dispatch backends.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Alerts;

use Logscope\Log\Group;

/**
 * Contract for an alert backend (email, webhook, future Slack/Discord
 * formatters, …). The {@see AlertCoordinator} fans a single grouped error
 * out to every enabled dispatcher and applies dedup per-dispatcher so a
 * webhook can fire while email is rate-limited and vice versa.
 *
 * Implementations must be side-effect-free under construction so they can
 * be wired into the DI container before the user has configured them; the
 * coordinator decides whether to call {@see AlertDispatcherInterface::dispatch()}
 * by checking {@see AlertDispatcherInterface::is_enabled()} first.
 */
interface AlertDispatcherInterface {

	/**
	 * Stable, machine-readable identifier used by the deduplicator to keep
	 * separate windows per backend, and by the test endpoint to surface
	 * per-backend results. Lowercase ASCII, no spaces (e.g. `email`,
	 * `webhook`).
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Whether the user has enabled this backend in settings. The
	 * coordinator skips disabled dispatchers without invoking dispatch.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * Sends one alert for the given grouped error. Implementations MUST
	 * NOT throw on transport failure — return false instead so the
	 * coordinator can record the outcome and continue with the remaining
	 * dispatchers. Throwing is reserved for genuine programming errors.
	 *
	 * @param Group $group Grouped error to send.
	 * @return bool True on success, false on transport failure.
	 */
	public function dispatch( Group $group ): bool;
}
