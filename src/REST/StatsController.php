<?php
/**
 * REST controller for the /stats endpoint.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

use Logscope\Log\LogStats;
use Logscope\Log\LogStatsException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Thin adapter between WP REST request shape and {@see LogStats}. The
 * controller validates `range` and `bucket` against the allowed enums
 * (the schema rejects out-of-band tokens before our handler runs), then
 * forwards to the service and wraps the result in a `WP_REST_Response`.
 *
 * The Stats tab refetches on range/bucket change rather than on filter
 * change, so this route deliberately ignores the Logs-tab filter
 * surface — stats are the ground truth of error volume across the
 * window, not a filtered sub-view. A click-through from the top-N
 * table populates the Logs FilterBar instead, which is where filtering
 * belongs.
 */
final class StatsController extends RestController {

	public const ROUTE = '/stats';

	/**
	 * Stats service the controller delegates to.
	 *
	 * @var LogStats
	 */
	private LogStats $stats;

	/**
	 * Builds the controller around a configured stats service.
	 *
	 * @param LogStats $stats Stats aggregator over the live log source.
	 */
	public function __construct( LogStats $stats ) {
		$this->stats = $stats;
	}

	/**
	 * Registers GET /stats. The args schema's enum constraint rejects
	 * unknown range or bucket tokens before our handler runs, so the
	 * `LogStatsException` catch below is a defence-in-depth fallback for
	 * direct invocations that bypass the schema.
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
					'args'                => $this->index_args(),
				),
			)
		);
	}

	/**
	 * Returns the args schema for `GET /stats`. Public so tests can
	 * assert against the contract without dispatching through core.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function index_args(): array {
		return array(
			'range'  => array(
				'type'    => 'string',
				'default' => '24h',
				'enum'    => LogStats::ranges(),
			),
			'bucket' => array(
				'type'    => 'string',
				'default' => 'hour',
				'enum'    => LogStats::buckets(),
			),
		);
	}

	/**
	 * GET /stats handler.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_index( WP_REST_Request $request ) {
		$range  = (string) $request->get_param( 'range' );
		$bucket = (string) $request->get_param( 'bucket' );

		try {
			$payload = $this->stats->summarize( $range, $bucket );
		} catch ( LogStatsException $e ) {
			return $this->error( 'logscope_rest_bad_query', $e->getMessage(), 400 );
		}

		return new WP_REST_Response( $payload );
	}
}
