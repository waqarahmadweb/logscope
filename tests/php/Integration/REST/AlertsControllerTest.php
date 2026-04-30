<?php
/**
 * Integration tests for the /alerts controller.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Integration\REST;

use Brain\Monkey\Functions;
use Logscope\Alerts\AlertCoordinator;
use Logscope\Alerts\AlertDeduplicator;
use Logscope\Alerts\AlertDispatcherInterface;
use Logscope\REST\AlertsController;
use Logscope\Tests\TestCase;
use Mockery;
use WP_Error;
use WP_REST_Response;

/**
 * End-to-end coverage for POST /alerts/test: 400 when nothing is enabled,
 * 200 with per-dispatcher results when at least one backend is enabled,
 * dedup is bypassed, and register_routes registers the right shape.
 */
final class AlertsControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_handle_test_returns_400_when_no_dispatchers_enabled(): void {
		$email   = $this->fake_dispatcher( 'email', false );
		$webhook = $this->fake_dispatcher( 'webhook', false );

		$controller = new AlertsController( $this->coord( array( $email, $webhook ) ) );
		$result     = $controller->handle_test();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_no_alerters_enabled', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_handle_test_dispatches_to_enabled_only_and_returns_results(): void {
		$email   = $this->fake_dispatcher( 'email', true );
		$webhook = $this->fake_dispatcher( 'webhook', false );
		$email->shouldReceive( 'dispatch' )->once()->andReturn( true );
		$webhook->shouldReceive( 'dispatch' )->never();

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->never();
		$dedup->shouldReceive( 'record_sent' )->never();
		$dedup->shouldReceive( 'clear' )->once();

		$coord      = new AlertCoordinator( array( $email, $webhook ), $dedup );
		$controller = new AlertsController( $coord );

		$result = $controller->handle_test();

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$body = $result->get_data();

		$this->assertCount( 2, $body['results'] );
		$this->assertSame( 'email', $body['results'][0]['dispatcher'] );
		$this->assertSame( 'sent', $body['results'][0]['outcome'] );
		$this->assertSame( 'webhook', $body['results'][1]['dispatcher'] );
		$this->assertSame( 'skipped', $body['results'][1]['outcome'] );
	}

	public function test_handle_test_bypasses_dedup_so_two_consecutive_clicks_both_send(): void {
		$email = $this->fake_dispatcher( 'email', true );
		$email->shouldReceive( 'dispatch' )->twice()->andReturn( true );

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->never();
		$dedup->shouldReceive( 'clear' )->twice();

		$coord      = new AlertCoordinator( array( $email ), $dedup );
		$controller = new AlertsController( $coord );

		$controller->handle_test();
		$result = $controller->handle_test();

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 'sent', $result->get_data()['results'][0]['outcome'] );
	}

	public function test_register_routes_registers_post_alerts_test(): void {
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

		$dedup      = Mockery::mock( AlertDeduplicator::class );
		$controller = new AlertsController( new AlertCoordinator( array(), $dedup ) );
		$controller->register_routes();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'logscope/v1', $captured[0]['namespace'] );
		$this->assertSame( '/alerts/test', $captured[0]['route'] );
		$this->assertSame( 'POST', $captured[0]['args']['methods'] );
	}

	private function coord( array $dispatchers ): AlertCoordinator {
		$dedup = Mockery::mock( AlertDeduplicator::class );
		return new AlertCoordinator( $dispatchers, $dedup );
	}

	private function fake_dispatcher( string $name, bool $enabled ): Mockery\MockInterface {
		$mock = Mockery::mock( AlertDispatcherInterface::class );
		$mock->shouldReceive( 'name' )->zeroOrMoreTimes()->andReturn( $name );
		$mock->shouldReceive( 'is_enabled' )->zeroOrMoreTimes()->andReturn( $enabled );
		return $mock;
	}
}
