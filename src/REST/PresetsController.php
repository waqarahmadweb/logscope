<?php
/**
 * REST controller for the /presets surface.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

defined( 'ABSPATH' ) || exit;

use Logscope\Settings\PresetStore;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CRUD endpoints over {@see PresetStore}, scoped to the current user:
 *
 *   - GET    /presets         → list of the caller's saved presets.
 *   - POST   /presets         → save (or overwrite) a preset by name.
 *   - DELETE /presets/<name>  → delete by URL-encoded name.
 *
 * "Current user" semantics live entirely in this controller — the
 * store takes a user id rather than reaching into `wp_get_current_user`
 * itself so its tests stay deterministic. The capability check still
 * runs through `RestController::permission_callback()` so a logged-in
 * subscriber cannot reach the routes.
 */
final class PresetsController extends RestController {

	public const ROUTE_COLLECTION = '/presets';
	public const ROUTE_ITEM       = '/presets/(?P<name>[^/]+)';

	/**
	 * Backing store.
	 *
	 * @var PresetStore
	 */
	private PresetStore $store;

	/**
	 * Constructor.
	 *
	 * @param PresetStore $store Preset store.
	 */
	public function __construct( PresetStore $store ) {
		$this->store = $store;
	}

	/**
	 * Registers GET/POST/DELETE on the preset routes.
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
					// Bound + sanitize the name at the boundary (mirrors the
					// store's own cap); `filters` is constrained to an object,
					// with the store's allowlist doing the per-key validation.
					'args'                => array(
						'name'    => array(
							'type'              => 'string',
							'required'          => true,
							'maxLength'         => PresetStore::MAX_NAME_LENGTH,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filters' => array(
							'type'     => 'object',
							'required' => false,
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
					'name' => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => PresetStore::MAX_NAME_LENGTH,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET handler — returns the current user's preset list.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_get() {
		$user_id = $this->current_user_id();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return $this->items_response( $this->store->list( $user_id ) );
	}

	/**
	 * Resolves the acting user id or a 401. `permission_callback()`
	 * already requires authentication, but presets key off the id, so a
	 * cap granted outside a real user context (id 0) must still refuse.
	 *
	 * @return int|\WP_Error
	 */
	private function current_user_id() {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->error(
				'logscope_rest_unauthenticated',
				__( 'You must be authenticated to manage presets.', 'logscope' ),
				401
			);
		}

		return $user_id;
	}

	/**
	 * POST handler — saves a preset (idempotent overwrite by name).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_post( WP_REST_Request $request ) {
		$user_id = $this->current_user_id();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$name = $request->get_param( 'name' );
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			return $this->error(
				'logscope_rest_invalid_preset_name',
				__( 'A non-empty `name` is required to save a preset.', 'logscope' ),
				400
			);
		}

		$filters = $request->get_param( 'filters' );
		if ( ! is_array( $filters ) ) {
			$filters = array();
		}

		if ( ! $this->store->save( $user_id, $name, $filters ) ) {
			return $this->error(
				'logscope_rest_save_failed',
				__( 'Could not save the preset.', 'logscope' ),
				400
			);
		}

		return $this->items_response( $this->store->list( $user_id ) );
	}

	/**
	 * DELETE handler — removes a preset by name. Returns 404 when the
	 * caller has no preset by that name.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_delete( WP_REST_Request $request ) {
		$user_id = $this->current_user_id();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$name = $request->get_param( 'name' );
		if ( ! is_string( $name ) || '' === $name ) {
			return $this->error(
				'logscope_rest_invalid_preset_name',
				__( 'A non-empty preset name is required.', 'logscope' ),
				400
			);
		}

		if ( ! $this->store->delete( $user_id, $name ) ) {
			return $this->error(
				'logscope_rest_preset_not_found',
				__( 'No preset by that name.', 'logscope' ),
				404
			);
		}

		return $this->items_response( $this->store->list( $user_id ) );
	}
}
