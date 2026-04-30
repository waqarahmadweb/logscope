<?php
/**
 * REST controller for the /diagnostics endpoint.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

use Logscope\Support\DiagnosticsService;
use WP_REST_Response;

/**
 * Thin adapter that exposes {@see DiagnosticsService::snapshot()} over
 * REST. Capability-gated through the shared {@see RestController} base,
 * so an unauthenticated caller gets a 401 rather than a fingerprint of
 * the host's debug-flag posture.
 *
 * The route is read-only and side-effect-free, but the snapshot does
 * leak filesystem layout (the resolved log path) and `WP_DEBUG`
 * configuration — both already visible to anyone with the manage
 * capability through the existing settings surface, so the access bar
 * matches.
 */
final class DiagnosticsController extends RestController {

	public const ROUTE = '/diagnostics';

	/**
	 * Diagnostics service the controller delegates to.
	 *
	 * @var DiagnosticsService
	 */
	private DiagnosticsService $diagnostics;

	/**
	 * Constructor.
	 *
	 * @param DiagnosticsService $diagnostics Snapshot builder.
	 */
	public function __construct( DiagnosticsService $diagnostics ) {
		$this->diagnostics = $diagnostics;
	}

	/**
	 * Registers GET /diagnostics.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_index' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * GET /diagnostics handler — returns the current host snapshot.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_index(): WP_REST_Response {
		return new WP_REST_Response( $this->diagnostics->snapshot() );
	}
}
