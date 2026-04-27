<?php
/**
 * REST controller for the /settings collection.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

use InvalidArgumentException;
use Logscope\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes the Logscope settings as a single REST resource. `GET` returns
 * the full settings shape; `POST` accepts a partial body, validates each
 * key against the schema, persists through {@see Settings::set()}, and
 * returns the new full shape.
 *
 * Why a single resource (rather than per-field PUTs): the settings
 * surface is small and each save is naturally atomic at the UI level.
 * Returning the full shape on both verbs lets the React client treat the
 * response as authoritative state without a follow-up GET.
 */
final class SettingsController extends RestController {

	public const ROUTE = '/settings';

	/**
	 * Settings facade used to read and write each field.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Schema-driven settings facade.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the GET + POST /settings routes.
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
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * GET /settings handler. Returns the full settings shape.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->all() );
	}

	/**
	 * POST /settings handler. Accepts a partial body, rejects unknown keys
	 * with a 400, sanitises and persists each known key through the
	 * Settings facade, then returns the new full settings shape so the
	 * client can update its local store without a follow-up GET.
	 *
	 * Why inspect params manually rather than rely on the args schema:
	 * the args mechanism validates each declared param independently and
	 * silently ignores extras, but the AC requires a 400 for unknown
	 * keys. `WP_REST_Request::get_params()` already aggregates URL,
	 * body, and JSON-body parameters in core, so reading from there
	 * works for both query-string POSTs and JSON bodies.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_post( WP_REST_Request $request ) {
		$body = (array) $request->get_params();

		$schema  = $this->settings->schema();
		$unknown = array();
		foreach ( array_keys( $body ) as $key ) {
			if ( ! is_string( $key ) || ! $schema->has( $key ) ) {
				$unknown[] = (string) $key;
			}
		}

		if ( array() !== $unknown ) {
			return $this->error(
				'logscope_rest_unknown_setting',
				sprintf(
					/* translators: %s is a comma-separated list of unknown setting keys. */
					__( 'Unknown setting(s): %s.', 'logscope' ),
					implode( ', ', $unknown )
				),
				400,
				array( 'unknown' => $unknown )
			);
		}

		// Two-phase apply so a sanitiser failure on a later key cannot
		// leave earlier keys partially persisted. Phase 1 sanitises the
		// whole body; phase 2 only writes once every value is known to
		// be safe. The unknown-key gate above already filters keys, so
		// the only thing the catch can fire on today is a future
		// schema-side validation that throws on bad values.
		$sanitized = array();
		try {
			foreach ( $body as $key => $value ) {
				$sanitized[ (string) $key ] = $schema->sanitize( (string) $key, $value );
			}
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'logscope_rest_invalid_setting', $e->getMessage(), 400 );
		}

		foreach ( $sanitized as $key => $value ) {
			$this->settings->set( $key, $value );
		}

		return new WP_REST_Response( $this->settings->all() );
	}
}
