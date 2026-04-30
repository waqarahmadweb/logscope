<?php
/**
 * Integration tests for the /diagnostics controller.
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
use Logscope\REST\DiagnosticsController;
use Logscope\Support\DiagnosticsService;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;
use WP_REST_Response;

/**
 * End-to-end coverage of GET /diagnostics. Drives the controller
 * against a real {@see DiagnosticsService} over a real fixture path so
 * the wiring between the service and the response shape is exercised.
 */
final class DiagnosticsControllerTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-diag-rest-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root = $resolved;
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_handle_index_returns_snapshot_for_existing_log(): void {
		$log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $log_path, "boot\n" );

		$controller = $this->controller_with_path( $log_path, true, true );
		$response   = $controller->handle_index();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertTrue( $data['wp_debug'] );
		$this->assertTrue( $data['wp_debug_log'] );
		$this->assertSame( $log_path, $data['log_path'] );
		$this->assertTrue( $data['exists'] );
		$this->assertTrue( $data['parent_writable'] );
		$this->assertSame( strlen( "boot\n" ), $data['file_size'] );
		$this->assertGreaterThan( 0, $data['modified_at'] );
	}

	public function test_handle_index_reports_missing_file_when_log_absent(): void {
		$log_path = $this->root . DIRECTORY_SEPARATOR . 'never-created.log';

		$controller = $this->controller_with_path( $log_path, false, false );
		$response   = $controller->handle_index();

		$data = $response->get_data();
		$this->assertFalse( $data['wp_debug'] );
		$this->assertFalse( $data['wp_debug_log'] );
		$this->assertFalse( $data['exists'] );
		$this->assertTrue( $data['parent_writable'] );
		$this->assertSame( 0, $data['file_size'] );
		$this->assertSame( 0, $data['modified_at'] );
	}

	public function test_register_routes_registers_get_diagnostics(): void {
		$controller = $this->controller_with_path( '', false, false );

		$captured = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( string $ns, string $route, array $args ) use ( &$captured ) {
				$captured[] = array(
					'namespace' => $ns,
					'route'     => $route,
					'args'      => $args,
				);
				return true;
			}
		);

		$controller->register_routes();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'logscope/v1', $captured[0]['namespace'] );
		$this->assertSame( '/diagnostics', $captured[0]['route'] );
		$this->assertSame( 'GET', $captured[0]['args'][0]['methods'] );
	}

	private function controller_with_path( string $log_path, bool $wp_debug, bool $wp_debug_log ): DiagnosticsController {
		$guard       = new PathGuard( array( $this->root ) );
		$diagnostics = new DiagnosticsService( $guard, $log_path, $wp_debug, $wp_debug_log );

		return new DiagnosticsController( $diagnostics );
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
