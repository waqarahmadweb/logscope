<?php
/**
 * Integration tests for the /stats controller.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tests need raw filesystem access for tmp fixtures and best-effort cleanup;
// WP_Filesystem and structured error handling are inappropriate here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Integration\REST;

use Brain\Monkey\Functions;
use Logscope\Log\FileLogSource;
use Logscope\Log\LogStats;
use Logscope\Log\Severity;
use Logscope\REST\StatsController;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * End-to-end coverage of GET /stats. Drives the controller against a
 * real {@see LogStats} over a real fixture log so the wiring between
 * request params, the service, and the response shape is exercised.
 *
 * The reference time inside the service uses the wall clock, so the
 * fixture log is written with timestamps relative to "now" — the test
 * does not assert on bucket *indexes* (those depend on when the test
 * runs), only on the totals and on the response envelope shape.
 */
final class StatsControllerTest extends TestCase {

	private string $root;

	private string $log_path;

	private StatsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-stats-rest-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root     = $resolved;
		$this->log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$guard            = new PathGuard( array( $this->root ) );
		$source           = new FileLogSource( $this->log_path, $guard );
		$this->controller = new StatsController( new LogStats( $source ) );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_default_params_return_envelope_shape(): void {
		$response = $this->controller->handle_index( $this->request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( '24h', $data['range'] );
		$this->assertSame( 'hour', $data['bucket'] );
		$this->assertCount( 24, $data['buckets'] );
		$this->assertArrayHasKey( 'totals', $data );
		$this->assertArrayHasKey( 'top', $data );
	}

	public function test_seven_day_range_with_day_bucket_returns_seven_buckets(): void {
		$response = $this->controller->handle_index(
			$this->request(
				array(
					'range'  => '7d',
					'bucket' => 'day',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( '7d', $data['range'] );
		$this->assertSame( 'day', $data['bucket'] );
		$this->assertCount( 7, $data['buckets'] );
	}

	public function test_thirty_day_range_with_day_bucket_returns_thirty_buckets(): void {
		$response = $this->controller->handle_index(
			$this->request(
				array(
					'range'  => '30d',
					'bucket' => 'day',
				)
			)
		);

		$data = $response->get_data();
		$this->assertCount( 30, $data['buckets'] );
	}

	public function test_recent_entries_count_in_totals(): void {
		// Write entries timestamped a few minutes ago so they fall into
		// the 24h hour-bucket window regardless of when the test runs.
		$ts    = gmdate( 'd-M-Y H:i:s', time() - 300 );
		$lines = array(
			'[' . $ts . ' UTC] PHP Warning:  recent in /a.php on line 5',
			'[' . $ts . ' UTC] PHP Fatal error:  recent fatal in /b.php on line 9',
		);
		file_put_contents( $this->log_path, implode( "\n", $lines ) . "\n" );

		$response = $this->controller->handle_index( $this->request() );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['totals'][ Severity::WARNING ] );
		$this->assertSame( 1, $data['totals'][ Severity::FATAL ] );
		$this->assertCount( 2, $data['top'] );
	}

	public function test_args_schema_enumerates_supported_tokens(): void {
		$args = $this->controller->index_args();

		$this->assertSame( array( '24h', '7d', '30d' ), $args['range']['enum'] );
		$this->assertSame( array( 'hour', 'day' ), $args['bucket']['enum'] );
		$this->assertSame( '24h', $args['range']['default'] );
		$this->assertSame( 'hour', $args['bucket']['default'] );
	}

	public function test_unknown_range_returns_bad_query_error(): void {
		// Bypasses the schema by going straight at the handler — the
		// `LogStatsException` catch is the safety net for that case.
		$response = $this->controller->handle_index(
			$this->request( array( 'range' => '999h' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'logscope_rest_bad_query', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	private function request( array $params = array() ): WP_REST_Request {
		$defaults = array(
			'range'  => '24h',
			'bucket' => 'hour',
		);
		return new WP_REST_Request( array_merge( $defaults, $params ) );
	}

	/**
	 * Best-effort recursive directory removal.
	 *
	 * @param string $dir Path to remove.
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}
