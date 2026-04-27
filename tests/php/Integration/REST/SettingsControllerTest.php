<?php
/**
 * Integration tests for the /settings controller.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Integration\REST;

use Brain\Monkey\Functions;
use Logscope\REST\SettingsController;
use Logscope\Settings\Settings;
use Logscope\Settings\SettingsSchema;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsControllerTest extends TestCase {

	private SettingsController $controller;

	/**
	 * Simulated wp_options store, keyed by option name. Mocked out below
	 * to back get_option / update_option for the controller under test.
	 *
	 * @var array<string, mixed>
	 */
	private array $store;

	/**
	 * Sandbox directory used as the PathGuard allowlist root for the
	 * test-path probe cases. Created in setUp(), torn down in tearDown().
	 *
	 * @var string
	 */
	private string $sandbox;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );

		$this->store = array(
			'logscope_log_path'      => '',
			'logscope_tail_interval' => 3,
		);

		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				return array_key_exists( $key, $this->store ) ? $this->store[ $key ] : $fallback;
			}
		);

		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->store[ $key ] = $value;
				return true;
			}
		);

		$this->sandbox = (string) realpath( sys_get_temp_dir() ) . DIRECTORY_SEPARATOR . 'logscope-settings-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->sandbox, 0777, true );

		$this->controller = new SettingsController(
			new Settings( new SettingsSchema() ),
			new PathGuard( array( $this->sandbox ) )
		);
	}

	protected function tearDown(): void {
		if ( isset( $this->sandbox ) && is_dir( $this->sandbox ) ) {
			$this->rrmdir( $this->sandbox );
		}
		parent::tearDown();
	}

	private function rrmdir( string $dir ): void {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	public function test_get_returns_full_settings_shape(): void {
		$this->store['logscope_log_path']      = '/var/log/debug.log';
		$this->store['logscope_tail_interval'] = 7;

		$response = $this->controller->handle_get();

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'log_path'      => '/var/log/debug.log',
				'tail_interval' => 7,
			),
			$response->get_data()
		);
	}

	public function test_post_persists_known_keys_and_returns_new_state(): void {
		$request  = new WP_REST_Request(
			array(
				'log_path'      => '  /tmp/custom.log  ',
				'tail_interval' => 5,
			)
		);
		$response = $this->controller->handle_post( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );

		$this->assertSame( '/tmp/custom.log', $this->store['logscope_log_path'] );
		$this->assertSame( 5, $this->store['logscope_tail_interval'] );

		$this->assertSame(
			array(
				'log_path'      => '/tmp/custom.log',
				'tail_interval' => 5,
			),
			$response->get_data()
		);
	}

	public function test_post_coerces_invalid_tail_interval_to_one(): void {
		$request  = new WP_REST_Request( array( 'tail_interval' => 0 ) );
		$response = $this->controller->handle_post( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 1, $this->store['logscope_tail_interval'] );
		$this->assertSame( 1, $response->get_data()['tail_interval'] );
	}

	public function test_post_rejects_unknown_keys_with_400(): void {
		$request = new WP_REST_Request(
			array(
				'log_path'        => '/tmp/x.log',
				'rogue_setting'   => 'oops',
				'another_unknown' => 1,
			)
		);
		$result  = $this->controller->handle_post( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_unknown_setting', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( array( 'rogue_setting', 'another_unknown' ), $data['unknown'] );

		// Even though log_path was a known key, the entire request must
		// be rejected so a partial save does not silently succeed.
		$this->assertSame( '', $this->store['logscope_log_path'] );
	}

	public function test_post_with_empty_body_is_a_no_op_and_returns_full_state(): void {
		$request  = new WP_REST_Request( array() );
		$response = $this->controller->handle_post( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'log_path'      => '',
				'tail_interval' => 3,
			),
			$response->get_data()
		);
	}

	public function test_post_is_atomic_when_sanitizer_throws(): void {
		// Schema double that sanitises log_path normally but throws when
		// asked to sanitise tail_interval. Exercises the live
		// logscope_rest_invalid_setting path and proves phase-1 failure
		// does not leak phase-2 writes (the AC for the atomicity fix).
		$throwing_schema = new class() extends SettingsSchema {
			public function sanitize( string $key, $value ) {
				if ( 'tail_interval' === $key ) {
					throw new \InvalidArgumentException( 'tail_interval is currently rejected for testing.' );
				}

				return parent::sanitize( $key, $value );
			}
		};

		$controller = new SettingsController(
			new Settings( $throwing_schema ),
			new PathGuard( array( $this->sandbox ) )
		);

		$request = new WP_REST_Request(
			array(
				'log_path'      => '/tmp/should-not-persist.log',
				'tail_interval' => 5,
			)
		);

		$result = $controller->handle_post( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_invalid_setting', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		// Neither key landed in wp_options — the sanitiser failure on
		// tail_interval must not leave log_path partially persisted.
		$this->assertSame( '', $this->store['logscope_log_path'] );
		$this->assertSame( 3, $this->store['logscope_tail_interval'] );
	}

	public function test_register_routes_calls_register_rest_route_for_settings_and_test_path(): void {
		$captured = array();

		Functions\expect( 'register_rest_route' )
			->twice()
			->andReturnUsing(
				function ( string $ns, string $route, array $config ) use ( &$captured ) {
					$captured[ $route ] = $config;
					return true;
				}
			);

		$this->controller->register_routes();

		$this->assertArrayHasKey( SettingsController::ROUTE, $captured );
		$settings_routes = $captured[ SettingsController::ROUTE ];
		$methods         = array_map(
			static function ( array $route ): string {
				return $route['methods'];
			},
			$settings_routes
		);
		$this->assertContains( 'GET', $methods );
		$this->assertContains( 'POST', $methods );

		$this->assertArrayHasKey( SettingsController::ROUTE_TEST_PATH, $captured );
		$this->assertSame( 'POST', $captured[ SettingsController::ROUTE_TEST_PATH ]['methods'] );
		$this->assertArrayHasKey( 'path', $captured[ SettingsController::ROUTE_TEST_PATH ]['args'] );
	}

	public function test_test_path_accepts_existing_file_inside_allowlist(): void {
		$file = $this->sandbox . DIRECTORY_SEPARATOR . 'debug.log';
		file_put_contents( $file, '' );

		$response = $this->controller->handle_test_path(
			new WP_REST_Request( array( 'path' => $file ) )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( realpath( $file ), $data['resolved'] );
		$this->assertTrue( $data['exists'] );
		$this->assertTrue( $data['readable'] );
		$this->assertNull( $data['reason'] );
	}

	public function test_test_path_accepts_nonexistent_file_when_parent_is_writable(): void {
		$candidate = $this->sandbox . DIRECTORY_SEPARATOR . 'will-be-created.log';

		$response = $this->controller->handle_test_path(
			new WP_REST_Request( array( 'path' => $candidate ) )
		);

		$data = $response->get_data();
		$this->assertTrue( $data['ok'], 'Parent-dir writable should yield ok=true.' );
		$this->assertFalse( $data['exists'] );
		// The candidate itself does not exist, so `writable` (file-level)
		// is honestly false; `parent_writable` is the field that tells
		// the admin the path will be creatable on first write.
		$this->assertFalse( $data['writable'] );
		$this->assertTrue( $data['parent_writable'] );
		$this->assertNull( $data['reason'] );
	}

	public function test_test_path_rejects_nonexistent_file_when_parent_is_outside_allowlist(): void {
		// Parent is outside the allowlist -> the missing-path branch must
		// not silently green-light it. Guards against the fallback firing
		// on any MissingPathException without the parent-writability gate.
		$candidate = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-not-allowed-' . bin2hex( random_bytes( 4 ) ) . '.log';

		$response = $this->controller->handle_test_path(
			new WP_REST_Request( array( 'path' => $candidate ) )
		);

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertFalse( $data['parent_writable'] );
		$this->assertIsString( $data['reason'] );
	}

	public function test_test_path_rejects_dot_dot_traversal(): void {
		$response = $this->controller->handle_test_path(
			new WP_REST_Request( array( 'path' => '../../../etc/passwd' ) )
		);

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertNull( $data['resolved'] );
		$this->assertIsString( $data['reason'] );
		$this->assertNotSame( '', $data['reason'] );
	}

	public function test_test_path_rejects_path_outside_allowlist(): void {
		$outside = (string) realpath( sys_get_temp_dir() );

		$response = $this->controller->handle_test_path(
			new WP_REST_Request( array( 'path' => $outside ) )
		);

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertStringContainsString( 'outside', strtolower( (string) $data['reason'] ) );
	}

	public function test_test_path_rejects_empty_string(): void {
		$response = $this->controller->handle_test_path(
			new WP_REST_Request( array( 'path' => '   ' ) )
		);

		$data = $response->get_data();
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'Path is empty.', $data['reason'] );
	}
}
