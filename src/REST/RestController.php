<?php
/**
 * Abstract base for Logscope REST controllers.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

use Logscope\Support\Capabilities;
use WP_Error;

/**
 * Centralises the cross-cutting concerns every Logscope REST route needs:
 * the `logscope/v1` namespace constant, capability + authentication
 * enforcement via {@see RestController::permission_callback()}, and a
 * uniform {@see RestController::error()} factory that maps internal
 * conditions to `WP_Error` instances with the right HTTP status.
 *
 * Cookie-authenticated REST requests are validated for nonce by core
 * (`rest_cookie_check_errors` on the `rest_authentication_errors` filter),
 * so this base does not re-verify `X-WP-Nonce` itself. Application
 * passwords and other auth schemes plug into the same core pipeline and
 * remain compatible.
 */
abstract class RestController {

	/**
	 * REST namespace shared by every Logscope route.
	 */
	public const REST_NAMESPACE = 'logscope/v1';

	/**
	 * Subclasses implement this to register their routes via
	 * `register_rest_route()`. Each `permission_callback` should be
	 * `array( $this, 'permission_callback' )` so authorization stays
	 * uniform across the surface.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Standard REST permission callback: 401 when no user is
	 * authenticated, 403 when the authenticated user lacks the Logscope
	 * management capability, true otherwise. Returning a `WP_Error`
	 * (rather than `false`) lets the two conditions surface as distinct
	 * HTTP statuses; core's default coerces both into a single 401/403
	 * depending on the auth state which is harder to reason about from
	 * the client.
	 *
	 * @return bool|WP_Error True on success, WP_Error otherwise.
	 */
	public function permission_callback() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'logscope_rest_unauthenticated',
				__( 'You must be authenticated to access this endpoint.', 'logscope' ),
				array( 'status' => 401 )
			);
		}

		if ( ! Capabilities::has_manage_cap() ) {
			return new WP_Error(
				'logscope_rest_forbidden',
				__( 'You do not have permission to access Logscope.', 'logscope' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Builds a `WP_Error` shaped for REST consumption. The `status` data
	 * key drives the HTTP response code via core's REST dispatcher.
	 *
	 * @param string $code    Machine-readable error code (snake_case).
	 * @param string $message Human-readable, translated message.
	 * @param int    $status  HTTP status code; defaults to 400.
	 * @param array  $extra   Optional additional data merged into the WP_Error data array.
	 * @return WP_Error
	 */
	protected function error( string $code, string $message, int $status = 400, array $extra = array() ): WP_Error {
		$data = array_merge( $extra, array( 'status' => $status ) );

		return new WP_Error( $code, $message, $data );
	}
}
