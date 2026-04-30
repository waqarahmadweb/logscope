<?php
/**
 * Alert dispatcher coordinator with per-dispatcher dedup.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Alerts;

use Logscope\Log\Group;

/**
 * Fans a grouped error out to every registered dispatcher, applying dedup
 * per-dispatcher so a webhook can fire while email is rate-limited (and
 * vice versa). Disabled dispatchers and deduped signatures are skipped
 * without invoking {@see AlertDispatcherInterface::dispatch()}.
 *
 * Two filterable hooks bracket each dispatch:
 *   - `logscope/before_alert` — filter; return `false` to skip this
 *     dispatcher for this group (gives extensions a custom-mute hook
 *     that doesn't have to mutate the deduplicator).
 *   - `logscope/alert_sent`   — action; fires only on a successful
 *     dispatch with `(group, dispatcher_name)`.
 */
final class AlertCoordinator {

	/**
	 * Registered dispatchers.
	 *
	 * @var AlertDispatcherInterface[]
	 */
	private array $dispatchers;

	/**
	 * Shared deduplicator.
	 *
	 * @var AlertDeduplicator
	 */
	private AlertDeduplicator $dedup;

	/**
	 * Constructor.
	 *
	 * @param AlertDispatcherInterface[] $dispatchers Ordered list of dispatchers.
	 * @param AlertDeduplicator          $dedup       Shared deduplicator.
	 */
	public function __construct( array $dispatchers, AlertDeduplicator $dedup ) {
		$this->dispatchers = $dispatchers;
		$this->dedup       = $dedup;
	}

	/**
	 * Dispatches alerts for every group across every enabled dispatcher.
	 * Returns a per-call summary: each entry carries the dispatcher
	 * name, the source group's signature, and the outcome
	 * (`sent` / `deduped` / `skipped` / `failed`).
	 *
	 * @param Group[] $groups Grouped errors to dispatch.
	 * @return array<int, array{dispatcher:string, signature:string, outcome:string}>
	 */
	public function dispatch_for_groups( array $groups ): array {
		$results = array();
		foreach ( $groups as $group ) {
			foreach ( $this->dispatchers as $dispatcher ) {
				$results[] = $this->dispatch_one( $dispatcher, $group );
			}
		}

		return $results;
	}

	/**
	 * Sends one dispatcher×group pair, honouring the enabled gate, the
	 * `logscope/before_alert` filter, the dedup window, the dispatcher's
	 * own success/failure return, and the `logscope/alert_sent` action.
	 *
	 * The bypass branch (used by the test-alert endpoint) skips dedup
	 * entirely and clears any existing window mark on send so a
	 * subsequent real fatal in the dedup window is not silently
	 * suppressed by the test.
	 *
	 * @param AlertDispatcherInterface $dispatcher Dispatcher to invoke.
	 * @param Group                    $group      Source group.
	 * @param bool                     $bypass_dedup Skip dedup (test-alert path).
	 * @return array{dispatcher:string, signature:string, outcome:string}
	 */
	public function dispatch_one( AlertDispatcherInterface $dispatcher, Group $group, bool $bypass_dedup = false ): array {
		$name      = $dispatcher->name();
		$signature = $group->signature;
		$result    = static function ( string $outcome ) use ( $name, $signature ): array {
			return array(
				'dispatcher' => $name,
				'signature'  => $signature,
				'outcome'    => $outcome,
			);
		};

		if ( ! $dispatcher->is_enabled() ) {
			return $result( 'skipped' );
		}

		/**
		 * Filter whether to send the alert. Return `false` to skip this
		 * dispatcher for this group — useful for extensions that want
		 * custom mute rules (per-source, per-time-window, …) without
		 * having to mutate the deduplicator.
		 *
		 * @param bool                     $send       Whether to send.
		 * @param Group                    $group      Source group.
		 * @param AlertDispatcherInterface $dispatcher Target dispatcher.
		 */
		$should_send = (bool) apply_filters( 'logscope/before_alert', true, $group, $dispatcher );
		if ( ! $should_send ) {
			return $result( 'skipped' );
		}

		if ( ! $bypass_dedup && ! $this->dedup->should_send( $name, $signature ) ) {
			return $result( 'deduped' );
		}

		$ok = $dispatcher->dispatch( $group );
		if ( ! $ok ) {
			return $result( 'failed' );
		}

		if ( $bypass_dedup ) {
			// Test-alert path: clear any existing mark so a subsequent
			// real fatal in the same window can fire normally rather
			// than being silently suppressed by the test send.
			$this->dedup->clear( $name, $signature );
		} else {
			$this->dedup->record_sent( $name, $signature );
		}

		/**
		 * Fires after a successful dispatch.
		 *
		 * @param Group  $group           Source group.
		 * @param string $dispatcher_name Stable backend identifier.
		 */
		do_action( 'logscope/alert_sent', $group, $name );

		return $result( 'sent' );
	}

	/**
	 * Returns the registered dispatchers in registration order. Used by
	 * the test-alert endpoint to enumerate enabled backends without
	 * exposing the array property directly.
	 *
	 * @return AlertDispatcherInterface[]
	 */
	public function dispatchers(): array {
		return $this->dispatchers;
	}
}
