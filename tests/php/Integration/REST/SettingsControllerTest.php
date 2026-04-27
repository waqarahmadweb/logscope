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

		$this->controller = new SettingsController( new Settings( new SettingsSchema() ) );
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

		$controller = new SettingsController( new Settings( $throwing_schema ) );

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

	public function test_register_routes_calls_register_rest_route_with_get_and_post(): void {
		$captured = null;

		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				SettingsController::REST_NAMESPACE,
				SettingsController::ROUTE,
				\Mockery::type( 'array' )
			)
			->andReturnUsing(
				function ( string $ns, string $route, array $config ) use ( &$captured ) {
					$captured = $config;
					return true;
				}
			);

		$this->controller->register_routes();

		$this->assertIsArray( $captured );
		$methods = array_map(
			static function ( array $route ): string {
				return $route['methods'];
			},
			$captured
		);
		$this->assertContains( 'GET', $methods );
		$this->assertContains( 'POST', $methods );
	}
}
