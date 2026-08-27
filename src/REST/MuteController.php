<?php
/**
 * REST controller for the /logs/mute surface.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

defined( 'ABSPATH' ) || exit;

use Logscope\Log\MuteStore;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CRUD endpoints over {@see MuteStore}. Three routes:
 *
 *   - GET    /logs/mute            → full list of mute records.
 *   - POST   /logs/mute            → add or update a record (idempotent).
 *   - DELETE /logs/mute/<signature> → unmute by signature path segment.
 *
 * Signatures are scoped to the URL path on DELETE rather than the body
 * because DELETE bodies are unevenly supported by middleware. The route
 * uses a permissive regex (`[^/]+`) since signatures are
 * implementation-defined hashes and the store applies its own
 * empty-string check internally.
 */
final class MuteController extends RestController {

	public const ROUTE_COLLECTION = '/logs/mute';
	public const ROUTE_ITEM       = '/logs/mute/(?P<signature>[^/]+)';

	/**
	 * Backing store.
	 *
	 * @var MuteStore
	 */
	private MuteStore $store;

	/**
	 * Constructor.
	 *
	 * @param MuteStore $store Mute store.
	 */
	public function __construct( MuteStore $store ) {
		$this->store = $store;
	}

	/**
	 * Registers GET/POST/DELETE on the mute routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_COLLECTION,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					// Bound + sanitize the body at the boundary so a stored
					// record can't carry unbounded or tag-laden input.
					'args'                => array(
						'signature' => array(
							'type'              => 'string',
							'required'          => true,
							'maxLength'         => 255,
							'sanitize_callback' => 'sanitize_text_field',
							// Signatures are md5 hex from LogGrouper — refuse
							// arbitrary strings so the persisted option can
							// only ever hold well-formed keys.
							'validate_callback' => array( self::class, 'is_valid_signature' ),
						),
						'reason'    => array(
							'type'              => 'string',
							'required'          => false,
							'maxLength'         => 500,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ITEM,
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'signature' => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 255,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET handler — returns the current mute list.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get(): WP_REST_Response {
		return $this->items_response( $this->store->list() );
	}

	/**
	 * POST handler — adds or updates a mute record. Idempotent: re-muting
	 * the same signature updates the record in place rather than 409-ing.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_post( WP_REST_Request $request ) {
		$signature = $request->get_param( 'signature' );
		if ( ! is_string( $signature ) || '' === trim( $signature ) ) {
			return $this->error(
				'logscope_rest_invalid_signature',
				__( 'A non-empty `signature` is required to mute an entry.', 'logscope' ),
				400
			);
		}

		$reason_raw = $request->get_param( 'reason' );
		$reason     = is_string( $reason_raw ) ? trim( $reason_raw ) : '';

		$user_id = get_current_user_id();

		if ( ! $this->store->add( trim( $signature ), $reason, (int) $user_id ) ) {
			return $this->error(
				'logscope_rest_mute_limit',
				__( 'The mute list is full — unmute something before adding more.', 'logscope' ),
				400
			);
		}

		return $this->items_response( $this->store->list() );
	}

	/**
	 * Validates the md5-hex signature shape at the REST boundary.
	 * Public static so `validate_callback` can reference it.
	 *
	 * @param mixed $value Raw param value.
	 * @return bool
	 */
	public static function is_valid_signature( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $value );
	}

	/**
	 * DELETE handler — removes a mute record by signature path segment.
	 * Returns 404 if the signature was not muted.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_delete( WP_REST_Request $request ) {
		$signature = $request->get_param( 'signature' );
		if ( ! is_string( $signature ) || '' === $signature ) {
			return $this->error(
				'logscope_rest_invalid_signature',
				__( 'A non-empty signature is required.', 'logscope' ),
				400
			);
		}

		if ( ! $this->store->remove( $signature ) ) {
			return $this->error(
				'logscope_rest_signature_not_muted',
				__( 'No mute record exists for that signature.', 'logscope' ),
				404
			);
		}

		return $this->items_response( $this->store->list() );
	}
}
