<?php
/**
 * REST controller for the /alerts surface.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

use Logscope\Alerts\AlertCoordinator;
use Logscope\Log\Group;
use Logscope\Log\Severity;
use WP_REST_Response;

/**
 * Exposes the test-alert endpoint used by the Settings UI's "Send test
 * alert" button.
 *
 * Why a synthetic group rather than picking the latest real fatal: the
 * test surface should be deterministic, not dependent on whatever
 * happens to be in the log. The synthetic payload also carries an
 * obvious "test alert" marker so the on-call recipient doesn't mistake
 * it for a real incident.
 *
 * Bypasses dedup so an admin clicking the button twice gets two test
 * sends; the coordinator clears the dedup mark on each test send so
 * a real fatal in the dedup window is not silently suppressed by the
 * test.
 */
final class AlertsController extends RestController {

	public const ROUTE_TEST = '/alerts/test';

	/**
	 * Synthetic signature embedded in test sends. Stable across calls so
	 * a router on the receiving end can reliably filter test traffic out
	 * of dashboards.
	 */
	private const TEST_SIGNATURE = 'logscope-test-alert';

	/**
	 * Coordinator used to fan the test alert across enabled dispatchers.
	 *
	 * @var AlertCoordinator
	 */
	private AlertCoordinator $coordinator;

	/**
	 * Constructor.
	 *
	 * @param AlertCoordinator $coordinator Alert coordinator.
	 */
	public function __construct( AlertCoordinator $coordinator ) {
		$this->coordinator = $coordinator;
	}

	/**
	 * Registers the POST /alerts/test route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_TEST,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_test' ),
				'permission_callback' => array( $this, 'permission_callback' ),
			)
		);
	}

	/**
	 * POST /alerts/test handler. Dispatches a synthetic alert to every
	 * enabled backend, bypassing dedup. Returns a 400 when no backend is
	 * enabled rather than a successful empty response so the UI can
	 * surface "nothing to send to" rather than silently claim success.
	 *
	 * Response body shape:
	 *   - results: array of `{dispatcher, signature, outcome}` (one per
	 *              registered dispatcher; outcome is `sent` / `skipped`
	 *              / `failed` per `AlertCoordinator::dispatch_one()`).
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_test() {
		$enabled_count = 0;
		foreach ( $this->coordinator->dispatchers() as $dispatcher ) {
			if ( $dispatcher->is_enabled() ) {
				++$enabled_count;
			}
		}

		if ( 0 === $enabled_count ) {
			return $this->error(
				'logscope_rest_no_alerters_enabled',
				__( 'Enable at least one alert backend (email or webhook) before sending a test alert.', 'logscope' ),
				400
			);
		}

		$group = $this->build_test_group();

		$results = array();
		foreach ( $this->coordinator->dispatchers() as $dispatcher ) {
			$results[] = $this->coordinator->dispatch_one( $dispatcher, $group, true );
		}

		return new WP_REST_Response( array( 'results' => $results ) );
	}

	/**
	 * Builds the synthetic group used for test sends. Severity is
	 * deliberately `fatal` so the test exercises the same code paths as
	 * a real fatal would (formatting, wire shape, recipient handling),
	 * but the message and file are clearly marked as test traffic.
	 *
	 * @return Group
	 */
	private function build_test_group(): Group {
		$now = gmdate( 'd-M-Y H:i:s' ) . ' UTC';

		return new Group(
			self::TEST_SIGNATURE,
			Severity::FATAL,
			'logscope/test',
			0,
			__( 'This is a test alert from Logscope. If you can read this, the alert pipeline is working.', 'logscope' ),
			1,
			$now,
			$now
		);
	}
}
