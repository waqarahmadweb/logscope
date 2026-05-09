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
use Logscope\Support\InvalidPathException;
use Logscope\Support\MissingPathException;
use Logscope\Support\PathGuard;
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

	public const ROUTE           = '/settings';
	public const ROUTE_TEST_PATH = '/settings/test-path';

	/**
	 * Settings facade used to read and write each field.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Path validator for the side-effect-free `test-path` probe.
	 *
	 * @var PathGuard
	 */
	private PathGuard $path_guard;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings   Schema-driven settings facade.
	 * @param PathGuard $path_guard Path validator used by the test-path probe.
	 */
	public function __construct( Settings $settings, PathGuard $path_guard ) {
		$this->settings   = $settings;
		$this->path_guard = $path_guard;
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

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_TEST_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_test_path' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(
					'path' => array(
						'type'     => 'string',
						'required' => true,
					),
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

		// WP core appends internal params like `_locale`, `_wpnonce`, and
		// `_method` to admin REST requests. They are not settings — strip
		// any underscore-prefixed key before the unknown-setting gate so a
		// legitimate save isn't rejected with a 400.
		foreach ( array_keys( $body ) as $key ) {
			if ( is_string( $key ) && '' !== $key && '_' === $key[0] ) {
				unset( $body[ $key ] );
			}
		}

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

	/**
	 * POST /settings/test-path. Side-effect-free probe used by the Settings
	 * UI: takes an untrusted candidate path, runs it through {@see PathGuard}
	 * (or the parent-directory check when the file does not exist yet), and
	 * returns a structured verdict the React panel renders inline.
	 *
	 * Always returns 200 with a verdict body — a malformed candidate is a
	 * valid answer to "test this path", not a request error. The only 400
	 * path is a missing or non-string `path` parameter, which the args
	 * schema already rejects before we run.
	 *
	 * Response shape:
	 *   - ok:              true when the path (or its parent, if the file
	 *                      does not exist) resolves inside the allowlist.
	 *   - resolved:        canonical absolute path when ok and the file
	 *                      exists; null otherwise (including the
	 *                      not-yet-created branch, since `realpath` of a
	 *                      missing file returns false).
	 *   - exists:          whether the candidate itself exists on disk.
	 *   - readable:        true when the existing candidate is readable.
	 *   - writable:        true when the existing candidate is writable.
	 *                      Always false when `exists` is false — see
	 *                      `parent_writable` for the not-yet-created case.
	 *   - parent_writable: true when the parent directory of the candidate
	 *                      is writable. Used by the UI to tell the admin
	 *                      that a missing-but-permitted path will be
	 *                      created on first write without the field name
	 *                      lying about which inode is writable.
	 *   - allowed_roots:   the configured allowlist roots so the panel can
	 *                      name them in error copy.
	 *   - reason:          human-readable rejection cause when ok is
	 *                      false; null otherwise. Exception messages from
	 *                      PathGuard are passed through verbatim — they
	 *                      never carry secrets and are translation-free by
	 *                      design (the UI layer translates the canonical
	 *                      strings).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_test_path( WP_REST_Request $request ): WP_REST_Response {
		$raw = $request->get_param( 'path' );
		if ( ! is_string( $raw ) ) {
			$raw = '';
		}
		$raw = trim( $raw );

		$verdict = array(
			'ok'              => false,
			'resolved'        => null,
			'exists'          => false,
			'readable'        => false,
			'writable'        => false,
			'parent_writable' => false,
			'allowed_roots'   => $this->path_guard->allowed_roots(),
			'reason'          => null,
		);

		if ( '' === $raw ) {
			$verdict['reason'] = 'Path is empty.';
			return new WP_REST_Response( $verdict );
		}

		try {
			$resolved            = $this->path_guard->resolve( $raw );
			$verdict['ok']       = true;
			$verdict['resolved'] = $resolved;
			$verdict['exists']   = true;
			$verdict['readable'] = $this->path_guard->is_readable( $raw );
			$verdict['writable'] = $this->path_guard->is_writable( $raw );

			return new WP_REST_Response( $verdict );
		} catch ( MissingPathException $e ) {
			// Common fresh-install case: the admin is configuring a
			// custom log location that will be created on first write.
			// Fall back to validating the parent directory through
			// PathGuard's allowlist + writability checks, so the green
			// "ok" answer still implies the directory is permitted.
			if ( $this->path_guard->is_writable_parent_of( $raw ) ) {
				$verdict['ok']              = true;
				$verdict['parent_writable'] = true;

				return new WP_REST_Response( $verdict );
			}

			$verdict['reason'] = $e->getMessage();
			return new WP_REST_Response( $verdict );
		} catch ( InvalidPathException $e ) {
			$verdict['reason'] = $e->getMessage();
			return new WP_REST_Response( $verdict );
		}
	}
}
