<?php
/**
 * REST controller for the /logs collection.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

use Logscope\Log\Entry;
use Logscope\Log\Group;
use Logscope\Log\LogQuery;
use Logscope\Log\LogQueryException;
use Logscope\Log\LogRepository;
use Logscope\Log\PagedResult;
use Logscope\Log\Severity;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes the parsed log to authenticated callers as a paginated, filtered
 * collection. The controller is a thin adapter between WP REST request
 * shapes and {@see LogRepository}: it validates and coerces query
 * parameters, builds a {@see LogQuery}, runs the query, and serialises
 * `Entry`/`Group` DTOs into stable JSON shapes. Pagination totals are
 * surfaced via the `X-WP-Total` and `X-WP-TotalPages` response headers,
 * matching core's collection convention so the React client can reuse
 * the same handling it would use for any other WP REST list.
 */
final class LogsController extends RestController {

	public const ROUTE = '/logs';

	/**
	 * Default page size when the caller does not supply `per_page`.
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Repository the controller queries.
	 *
	 * @var LogRepository
	 */
	private LogRepository $repository;

	/**
	 * Builds the controller around a ready-to-query repository. The
	 * repository encapsulates path resolution and parser wiring; the
	 * controller stays free of filesystem concerns.
	 *
	 * @param LogRepository $repository Configured repository.
	 */
	public function __construct( LogRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Registers the GET /logs route. The args schema doubles as
	 * machine-readable documentation and as core's input validator —
	 * out-of-range or wrong-type params are rejected before our handler
	 * runs, so the handler only sees coerced primitives.
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
	 * Returns the args schema for `GET /logs`. Kept public so it can be
	 * asserted against in tests without dispatching through core.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function index_args(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => self::DEFAULT_PER_PAGE,
				'minimum' => LogQuery::MIN_PER_PAGE,
				'maximum' => LogQuery::MAX_PER_PAGE,
			),
			'severity' => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => Severity::all(),
				),
			),
			'from'     => array(
				'type' => 'string',
			),
			'to'       => array(
				'type' => 'string',
			),
			'q'        => array(
				'type'      => 'string',
				'maxLength' => LogQuery::MAX_REGEX_LENGTH,
			),
			'grouped'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'source'   => array(
				'type' => 'string',
			),
		);
	}

	/**
	 * GET /logs handler. Translates `LogQueryException` to a 400 response
	 * so a malformed regex or out-of-band date fails as a sanitised
	 * client error rather than a 500.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_index( WP_REST_Request $request ) {
		try {
			$query = $this->build_query( (array) $request->get_params() );
		} catch ( LogQueryException $e ) {
			return $this->error( 'logscope_rest_bad_query', $e->getMessage(), 400 );
		}

		$result = $this->repository->query( $query );

		$response = new WP_REST_Response( $this->serialize_result( $result, $query->grouped ) );
		$response->header( 'X-WP-Total', (string) $result->total );
		$response->header( 'X-WP-TotalPages', (string) $result->total_pages );

		return $response;
	}

	/**
	 * Builds a `LogQuery` from the request parameters. Public for tests.
	 *
	 * @param array<string, mixed> $params Raw request params.
	 * @return LogQuery
	 *
	 * @throws LogQueryException When validation in LogQuery rejects the input.
	 */
	public function build_query( array $params ): LogQuery {
		$severities = $this->normalise_severity_param( $params['severity'] ?? null );
		$from       = $this->nullable_string( $params['from'] ?? null );
		$to         = $this->nullable_string( $params['to'] ?? null );
		$regex      = $this->nullable_string( $params['q'] ?? null );
		$source     = $this->nullable_string( $params['source'] ?? null );
		$grouped    = (bool) ( $params['grouped'] ?? false );
		$page       = (int) ( $params['page'] ?? 1 );
		$per_page   = (int) ( $params['per_page'] ?? self::DEFAULT_PER_PAGE );

		return new LogQuery( $severities, $from, $to, $regex, $source, $grouped, $page, $per_page );
	}

	/**
	 * Serialises a paged result into the response body shape. Public so
	 * tests can assert the wire format directly.
	 *
	 * @param PagedResult $result  The page.
	 * @param bool        $grouped Whether the result holds groups or entries.
	 * @return array<string, mixed>
	 */
	public function serialize_result( PagedResult $result, bool $grouped ): array {
		$items = array();
		foreach ( $result->items as $item ) {
			if ( $grouped && $item instanceof Group ) {
				$items[] = $this->shape_group( $item );
			} elseif ( $item instanceof Entry ) {
				$items[] = $this->shape_entry( $item );
			}
		}

		return array(
			'items'       => $items,
			'total'       => $result->total,
			'page'        => $result->page,
			'per_page'    => $result->per_page,
			'total_pages' => $result->total_pages,
		);
	}

	/**
	 * Coerces a `severity` query parameter into the array shape that
	 * `LogQuery` expects. Accepts an array directly (REST core normalises
	 * `?severity[]=...`) or a comma-separated string for clients that
	 * prefer the flat form.
	 *
	 * @param mixed $value Raw param value.
	 * @return string[]|null
	 */
	private function normalise_severity_param( $value ): ?array {
		if ( null === $value || '' === $value || array() === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$value = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$out = array();
		foreach ( $value as $token ) {
			if ( is_string( $token ) && '' !== $token ) {
				$out[] = $token;
			}
		}

		return array() === $out ? null : $out;
	}

	/**
	 * Returns the value as a non-empty string or null. Empty strings are
	 * collapsed so `LogQuery` does not have to special-case them.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function nullable_string( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Shapes one `Entry` for JSON output. Field order is incidental; the
	 * shape is the contract the React client consumes.
	 *
	 * @param Entry $entry Parsed entry.
	 * @return array<string, mixed>
	 */
	private function shape_entry( Entry $entry ): array {
		return array(
			'severity'  => $entry->severity,
			'timestamp' => $entry->timestamp,
			'timezone'  => $entry->timezone,
			'message'   => $entry->message,
			'file'      => $entry->file,
			'line'      => $entry->line,
			'raw'       => $entry->raw,
		);
	}

	/**
	 * Shapes one `Group` for JSON output.
	 *
	 * @param Group $group Group row.
	 * @return array<string, mixed>
	 */
	private function shape_group( Group $group ): array {
		return array(
			'signature'      => $group->signature,
			'severity'       => $group->severity,
			'file'           => $group->file,
			'line'           => $group->line,
			'sample_message' => $group->sample_message,
			'count'          => $group->count,
			'first_seen'     => $group->first_seen,
			'last_seen'      => $group->last_seen,
		);
	}
}
