<?php
/**
 * Integration tests for the GET /logs controller.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tests need raw filesystem access for tmp fixtures and best-effort cleanup.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Integration\REST;

use Brain\Monkey\Functions;
use Logscope\Log\FileLogSource;
use Logscope\Log\LogRepository;
use Logscope\Log\Severity;
use Logscope\REST\LogsController;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class LogsControllerTest extends TestCase {

	private string $root;

	private string $log_path;

	private LogsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-rest-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root     = $resolved;
		$this->log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';

		$guard            = new PathGuard( array( $this->root ) );
		$source           = new FileLogSource( $this->log_path, $guard );
		$this->controller = new LogsController( new LogRepository( $source ), $source, $guard );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_index_returns_paginated_entries_with_total_headers(): void {
		// 75 fatals across two pages of 50, plus warnings as background noise.
		$lines = array();
		for ( $i = 0; $i < 75; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 12:00:%02d UTC] PHP Fatal error:  boom %d in /var/www/main.php:%d',
				$i % 60,
				$i,
				$i + 1
			);
		}
		for ( $i = 0; $i < 30; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 13:00:%02d UTC] PHP Warning:  meh %d in /var/www/x.php on line %d',
				$i % 60,
				$i,
				$i
			);
		}
		file_put_contents( $this->log_path, implode( "\n", $lines ) );

		$request  = new WP_REST_Request(
			array(
				'page'     => 2,
				'per_page' => 50,
				'severity' => array( Severity::FATAL ),
			)
		);
		$response = $this->controller->handle_index( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$body    = $response->get_data();
		$headers = $response->get_headers();

		$this->assertSame( 75, $body['total'] );
		$this->assertSame( 2, $body['page'] );
		$this->assertSame( 50, $body['per_page'] );
		$this->assertSame( 2, $body['total_pages'] );
		$this->assertCount( 25, $body['items'] );

		foreach ( $body['items'] as $item ) {
			$this->assertSame( Severity::FATAL, $item['severity'] );
			$this->assertArrayHasKey( 'message', $item );
			$this->assertArrayHasKey( 'file', $item );
			$this->assertArrayHasKey( 'line', $item );
			$this->assertArrayHasKey( 'raw', $item );
		}

		$this->assertSame( '75', $headers['X-WP-Total'] );
		$this->assertSame( '2', $headers['X-WP-TotalPages'] );
	}

	public function test_index_returns_groups_when_grouped_param_is_true(): void {
		$lines = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 12:00:%02d UTC] PHP Notice:  Cannot find post %d in /var/www/a.php on line 1',
				$i,
				$i + 1
			);
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 13:00:%02d UTC] PHP Warning:  Undefined index "k" in /var/www/b.php on line 2',
				$i
			);
		}
		file_put_contents( $this->log_path, implode( "\n", $lines ) );

		$request  = new WP_REST_Request( array( 'grouped' => true ) );
		$response = $this->controller->handle_index( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$body = $response->get_data();

		$this->assertCount( 2, $body['items'] );
		$this->assertArrayHasKey( 'signature', $body['items'][0] );
		$this->assertSame( 5, $body['items'][0]['count'] );
		$this->assertSame( 2, $body['items'][1]['count'] );
	}

	public function test_index_returns_400_for_invalid_regex(): void {
		file_put_contents( $this->log_path, "[27-Apr-2026 12:00:00 UTC] PHP Notice:  ok in /a.php on line 1\n" );

		$request = new WP_REST_Request( array( 'q' => '[' ) );
		$result  = $this->controller->handle_index( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_bad_query', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_index_returns_empty_set_when_log_missing(): void {
		$request  = new WP_REST_Request();
		$response = $this->controller->handle_index( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$body = $response->get_data();

		$this->assertSame( array(), $body['items'] );
		$this->assertSame( 0, $body['total'] );
		$this->assertSame( 1, $body['total_pages'] );
	}

	public function test_severity_param_accepts_comma_separated_string(): void {
		$lines = array(
			'[27-Apr-2026 12:00:00 UTC] PHP Fatal error:  one in /a.php:1',
			'[27-Apr-2026 12:00:01 UTC] PHP Warning:  two in /a.php on line 1',
			'[27-Apr-2026 12:00:02 UTC] PHP Notice:  three in /a.php on line 1',
		);
		file_put_contents( $this->log_path, implode( "\n", $lines ) );

		$request  = new WP_REST_Request( array( 'severity' => 'fatal,warning' ) );
		$response = $this->controller->handle_index( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 2, $response->get_data()['total'] );
	}

	public function test_register_routes_calls_register_rest_route_with_namespace_and_path(): void {
		$collection_routes = null;
		$download_routes   = null;

		Functions\expect( 'register_rest_route' )
			->twice()
			->with(
				LogsController::REST_NAMESPACE,
				\Mockery::type( 'string' ),
				\Mockery::type( 'array' )
			)
			->andReturnUsing(
				function ( string $ns, string $route, array $config ) use ( &$collection_routes, &$download_routes ) {
					if ( LogsController::ROUTE === $route ) {
						$collection_routes = $config;
					} elseif ( LogsController::ROUTE_DOWNLOAD === $route ) {
						$download_routes = $config;
					}
					return true;
				}
			);

		$this->controller->register_routes();

		$this->assertIsArray( $collection_routes );
		$methods = array_map(
			static function ( array $route ): string {
				return $route['methods'];
			},
			$collection_routes
		);
		$this->assertContains( 'GET', $methods );
		$this->assertContains( 'DELETE', $methods );

		$this->assertIsArray( $download_routes );
		$this->assertSame( 'GET', $download_routes[0]['methods'] );
	}

	public function test_clear_without_confirm_returns_400(): void {
		file_put_contents( $this->log_path, "[27-Apr-2026 12:00:00 UTC] PHP Notice:  ok in /a.php on line 1\n" );

		$request = new WP_REST_Request();
		$result  = $this->controller->handle_clear( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_confirmation_required', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$this->assertFileExists( $this->log_path );
	}

	public function test_clear_when_log_missing_returns_404(): void {
		$request = new WP_REST_Request( array( 'confirm' => true ) );
		$result  = $this->controller->handle_clear( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_log_missing', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_clear_renames_log_to_archived_filename(): void {
		file_put_contents( $this->log_path, "[27-Apr-2026 12:00:00 UTC] PHP Notice:  ok in /a.php on line 1\n" );

		$request  = new WP_REST_Request( array( 'confirm' => true ) );
		$response = $this->controller->handle_clear( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$body = $response->get_data();

		$this->assertTrue( $body['cleared'] );
		$this->assertMatchesRegularExpression(
			'/^debug\.log\.cleared-\d{8}-\d{6}$/',
			$body['archived_as']
		);

		$this->assertFileDoesNotExist( $this->log_path );
		$this->assertFileExists( $this->root . DIRECTORY_SEPARATOR . $body['archived_as'] );
	}

	public function test_archive_path_for_appends_cleared_suffix(): void {
		$path = LogsController::archive_path_for( '/var/log/debug.log', '20260427-123456' );

		$this->assertSame( '/var/log/debug.log.cleared-20260427-123456', $path );
	}

	public function test_download_when_log_missing_returns_404(): void {
		$request = new WP_REST_Request();
		$result  = $this->controller->handle_download( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_log_missing', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_download_headers_for_returns_attachment_headers(): void {
		$headers = LogsController::download_headers_for( '/var/log/debug.log', 1234 );

		$this->assertSame( 'text/plain; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="debug.log"', $headers['Content-Disposition'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( '1234', $headers['Content-Length'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	public function test_download_headers_for_strips_quote_and_control_chars_from_filename(): void {
		$headers = LogsController::download_headers_for( '/var/log/debug"evil".log', 0 );

		$this->assertSame( 'attachment; filename="debugevil.log"', $headers['Content-Disposition'] );
	}

	public function test_download_headers_for_falls_back_when_filename_is_all_stripped(): void {
		$headers = LogsController::download_headers_for( '/var/log/"""', 0 );

		$this->assertSame( 'attachment; filename="debug.log"', $headers['Content-Disposition'] );
	}

	public function test_severity_comma_string_drops_unknown_tokens(): void {
		$lines = array(
			'[27-Apr-2026 12:00:00 UTC] PHP Fatal error:  one in /a.php:1',
			'[27-Apr-2026 12:00:01 UTC] PHP Warning:  two in /a.php on line 1',
			'[27-Apr-2026 12:00:02 UTC] PHP Notice:  three in /a.php on line 1',
		);
		file_put_contents( $this->log_path, implode( "\n", $lines ) );

		$request  = new WP_REST_Request( array( 'severity' => 'fatal,bogus,warning' ) );
		$response = $this->controller->handle_index( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 2, $response->get_data()['total'] );
	}

	public function test_download_streams_file_contents(): void {
		$body = "log line 1\nlog line 2\n";
		file_put_contents( $this->log_path, $body );

		$request = new WP_REST_Request( array( '_logscope_skip_exit_for_tests' => true ) );

		ob_start();
		$result = $this->controller->handle_download( $request );
		$output = ob_get_clean();

		$this->assertNull( $result );
		$this->assertSame( $body, $output );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . DIRECTORY_SEPARATOR . $entry;

			if ( is_link( $path ) || is_file( $path ) ) {
				@unlink( $path );
				continue;
			}

			$this->rrmdir( $path );
		}

		@rmdir( $dir );
	}
}
