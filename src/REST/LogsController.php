<?php
/**
 * REST controller for the /logs collection.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\REST;

use Logscope\Log\Entry;
use Logscope\Log\FileLogSource;
use Logscope\Log\Frame;
use Logscope\Log\Group;
use Logscope\Log\LogGrouper;
use Logscope\Log\LogQuery;
use Logscope\Log\LogQueryException;
use Logscope\Log\LogRepository;
use Logscope\Log\PagedResult;
use Logscope\Log\Severity;
use Logscope\Log\SourceClassifier;
use Logscope\Log\StackTraceParser;
use Logscope\Support\PathGuard;
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

	public const ROUTE          = '/logs';
	public const ROUTE_DOWNLOAD = '/logs/download';

	/**
	 * Default page size when the caller does not supply `per_page`.
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Soft-delete suffix template used to archive `debug.log` on clear.
	 * `gmdate( 'Ymd-His' )` is interpolated at call time.
	 */
	private const CLEAR_SUFFIX_FORMAT = '.cleared-%s';

	/**
	 * Repository the controller queries.
	 *
	 * @var LogRepository
	 */
	private LogRepository $repository;

	/**
	 * Underlying log source — used by clear and download to reach the
	 * resolved file path without re-running PathGuard validation.
	 *
	 * @var FileLogSource
	 */
	private FileLogSource $source;

	/**
	 * Path validator scoped to the same allowlist the source was built
	 * against. The clear route uses it to confirm the parent directory
	 * is writable before attempting the rename.
	 *
	 * @var PathGuard
	 */
	private PathGuard $guard;

	/**
	 * Builds the controller around a ready-to-query repository, the file
	 * source it wraps (clear and download read the raw path from it),
	 * and the path guard scoped to the same allowlist (used to confirm
	 * the parent directory is writable before the soft-delete rename).
	 *
	 * @param LogRepository $repository Configured repository.
	 * @param FileLogSource $source     Source the repository wraps.
	 * @param PathGuard     $guard      Validator for parent-writable check.
	 */
	public function __construct( LogRepository $repository, FileLogSource $source, PathGuard $guard ) {
		$this->repository = $repository;
		$this->source     = $source;
		$this->guard      = $guard;
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
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_clear' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->clear_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_DOWNLOAD,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_download' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Returns the args schema for `DELETE /logs`. Public so tests can
	 * assert against it without dispatching through core.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function clear_args(): array {
		return array(
			'confirm' => array(
				'type'    => 'boolean',
				'default' => false,
			),
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
			'page'          => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page'      => array(
				'type'    => 'integer',
				'default' => self::DEFAULT_PER_PAGE,
				'minimum' => LogQuery::MIN_PER_PAGE,
				'maximum' => LogQuery::MAX_PER_PAGE,
			),
			'severity'      => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => Severity::all(),
				),
			),
			'from'          => array(
				'type' => 'string',
			),
			'to'            => array(
				'type' => 'string',
			),
			'q'             => array(
				'type'      => 'string',
				'maxLength' => LogQuery::MAX_REGEX_LENGTH,
			),
			'grouped'       => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'source'        => array(
				'type' => 'string',
			),
			'since'         => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'include_muted' => array(
				'type'    => 'boolean',
				'default' => false,
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
	 * `DELETE /logs` handler. Soft-deletes the active log by renaming it
	 * to `<basename>.cleared-YYYYMMDD-HHMMSS`, preserving the file for
	 * post-mortem inspection while immediately freeing the live log path
	 * for new writes. The destructive verb requires `?confirm=true` so a
	 * mistaken navigation cannot wipe the log; AGENTS.md §11 enshrines
	 * the soft-delete decision.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function handle_clear( WP_REST_Request $request ) {
		if ( true !== (bool) $request->get_param( 'confirm' ) ) {
			return $this->error(
				'logscope_rest_confirmation_required',
				__( 'Pass confirm=true to clear the log.', 'logscope' ),
				400
			);
		}

		if ( ! $this->source->exists() ) {
			return $this->error(
				'logscope_rest_log_missing',
				__( 'There is no log file to clear.', 'logscope' ),
				404
			);
		}

		$path = $this->source->path();
		if ( ! $this->guard->is_writable_parent_of( $path ) ) {
			return $this->error(
				'logscope_rest_log_not_writable',
				__( 'The log directory is not writable by the server.', 'logscope' ),
				403
			);
		}

		$archive_path = self::archive_path_for( $path, gmdate( 'Ymd-His' ) );

		// PathGuard validated the parent directory; the suffix is built
		// internally from gmdate() and the existing basename, so the
		// rename target sits inside the same allowlisted directory and
		// cannot be influenced by the request.
		if ( ! @rename( $path, $archive_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
			return $this->error(
				'logscope_rest_clear_failed',
				__( 'The log could not be archived.', 'logscope' ),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'cleared'     => true,
				'archived_as' => basename( $archive_path ),
			)
		);
	}

	/**
	 * `GET /logs/download` handler. Streams the active log with
	 * attachment headers so browsers prompt a save dialog. The body is
	 * the raw file rather than a JSON wrapper, so we send headers
	 * directly and `exit` after `readfile()` to bypass core's REST
	 * response serialiser.
	 *
	 * @internal The `_logscope_skip_exit_for_tests` request parameter is
	 *           honoured as an in-process testing seam so the unit suite
	 *           can drive the streaming path under PHPUnit's already-
	 *           flushed output buffer. The route is gated by the
	 *           `logscope_manage` capability, but if that gate is ever
	 *           weakened this seam must be removed or hidden behind a
	 *           build-time guard — it is not a public contract.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|\WP_Error|null
	 */
	public function handle_download( WP_REST_Request $request ) {
		if ( ! $this->source->exists() ) {
			return $this->error(
				'logscope_rest_log_missing',
				__( 'There is no log file to download.', 'logscope' ),
				404
			);
		}

		$path      = $this->source->path();
		$size      = $this->source->size();
		$test_mode = true === $request->get_param( '_logscope_skip_exit_for_tests' );

		if ( ! $test_mode ) {
			foreach ( self::download_headers_for( $path, $size ) as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		// Streaming the file directly keeps memory bounded for large
		// debug logs; readfile uses an internal 8KB buffer.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		if ( $test_mode ) {
			return null;
		}

		exit;
	}

	/**
	 * Returns the response headers a download response should carry.
	 * Kept static and pure so the unit suite can assert the contract
	 * without invoking PHP's `header()`.
	 *
	 * @param string $path Resolved log path.
	 * @param int    $size File size in bytes.
	 * @return array<string, string>
	 */
	public static function download_headers_for( string $path, int $size ): array {
		// Strip characters that would break the quoted-string form of
		// Content-Disposition. PathGuard guarantees the directory is
		// allowlisted, but the basename can in principle still contain
		// `"`, `\r`, or `\n` if a sibling was renamed by something other
		// than Logscope; sanitising here means a malformed file on disk
		// cannot produce a malformed header.
		$filename = preg_replace( '/[\x00-\x1F"\\\\]+/', '', basename( $path ) );
		if ( '' === (string) $filename ) {
			$filename = 'debug.log';
		}

		return array(
			'Content-Type'           => 'text/plain; charset=utf-8',
			'Content-Disposition'    => sprintf( 'attachment; filename="%s"', $filename ),
			'X-Content-Type-Options' => 'nosniff',
			'Content-Length'         => (string) $size,
			'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
		);
	}

	/**
	 * Builds the archive target for a soft-delete given the source path
	 * and a UTC timestamp string. Pure helper, exposed for tests.
	 *
	 * @param string $path      Resolved log path.
	 * @param string $timestamp UTC stamp in `Ymd-His` form.
	 * @return string
	 */
	public static function archive_path_for( string $path, string $timestamp ): string {
		return $path . sprintf( self::CLEAR_SUFFIX_FORMAT, $timestamp );
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
		$severities    = $this->normalise_severity_param( $params['severity'] ?? null );
		$from          = $this->nullable_string( $params['from'] ?? null );
		$to            = $this->nullable_string( $params['to'] ?? null );
		$regex         = $this->nullable_string( $params['q'] ?? null );
		$source        = $this->nullable_string( $params['source'] ?? null );
		$grouped       = (bool) ( $params['grouped'] ?? false );
		$page          = (int) ( $params['page'] ?? 1 );
		$per_page      = (int) ( $params['per_page'] ?? self::DEFAULT_PER_PAGE );
		$since         = isset( $params['since'] ) && '' !== $params['since']
			? (int) $params['since']
			: null;
		$include_muted = (bool) ( $params['include_muted'] ?? false );

		return new LogQuery( $severities, $from, $to, $regex, $source, $grouped, $page, $per_page, $since, $include_muted );
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
			'last_byte'   => $result->last_byte,
			'rotated'     => $result->rotated,
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

		// The args schema's enum constraint only fires for the array form;
		// the comma-separated string path bypasses it, so we match the
		// schema's contract here by dropping unknown tokens. LogQuery also
		// filters internally, but enforcing at the boundary keeps the
		// wire shape and the schema in lockstep.
		$known = Severity::all();
		$out   = array();
		foreach ( $value as $token ) {
			if ( is_string( $token ) && in_array( $token, $known, true ) ) {
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
		// Only fatals and parse errors carry a stack trace in WP's
		// debug-log format; running StackTraceParser over a notice or
		// warning is a 50× wasted regex sweep on a typical noisy log.
		// Gating here keeps the per-row cost off the warning-heavy
		// majority while still emitting frames where the UI needs them.
		$frames = array();
		if ( Severity::FATAL === $entry->severity || Severity::PARSE === $entry->severity ) {
			foreach ( StackTraceParser::parse( $entry->raw ) as $frame ) {
				$frames[] = self::shape_frame( $frame );
			}
		}

		return array(
			'severity'  => $entry->severity,
			'timestamp' => $entry->timestamp,
			'timezone'  => $entry->timezone,
			'message'   => $entry->message,
			'file'      => $entry->file,
			'line'      => $entry->line,
			'source'    => SourceClassifier::classify( $entry->file ),
			'raw'       => $entry->raw,
			'frames'    => $frames,
			// Signature lets the list-view "Mute (N)" action collapse a
			// selection down to its distinct mute keys without a server
			// round-trip per row.
			'signature' => LogGrouper::signature( $entry ),
		);
	}

	/**
	 * Shapes one Frame for JSON output. Public so a future filter
	 * pipeline can mutate frames without round-tripping through the
	 * private serialiser.
	 *
	 * @param Frame $frame Parsed frame.
	 * @return array<string, mixed>
	 */
	public static function shape_frame( Frame $frame ): array {
		return array(
			'file'   => $frame->file,
			'line'   => $frame->line,
			'class'  => $frame->class,
			'method' => $frame->method,
			'args'   => $frame->args,
			'raw'    => $frame->raw,
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
