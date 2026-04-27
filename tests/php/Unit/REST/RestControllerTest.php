<?php
/**
 * Unit tests for the abstract RestController base.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\REST;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Logscope\REST\RestController;
use Logscope\Tests\TestCase;
use WP_Error;

final class RestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	public function test_permission_callback_returns_401_when_user_not_logged_in(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( false );

		$controller = new StubRestController();
		$result     = $controller->permission_callback();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_unauthenticated', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_permission_callback_returns_403_when_user_lacks_capability(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( true );
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'logscope_manage' )
			->andReturn( false );

		$controller = new StubRestController();
		$result     = $controller->permission_callback();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permission_callback_returns_true_when_user_has_capability(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( true );
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'logscope_manage' )
			->andReturn( true );

		$controller = new StubRestController();

		$this->assertTrue( $controller->permission_callback() );
	}

	public function test_error_builds_wp_error_with_expected_status_and_extra_data(): void {
		$controller = new StubRestController();

		$err = $controller->expose_error( 'logscope_bad_thing', 'Bad thing happened.', 422, array( 'field' => 'page' ) );

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'logscope_bad_thing', $err->get_error_code() );
		$this->assertSame( 'Bad thing happened.', $err->get_error_message() );
		$data = $err->get_error_data();
		$this->assertSame( 422, $data['status'] );
		$this->assertSame( 'page', $data['field'] );
	}

	public function test_namespace_constant_is_logscope_v1(): void {
		$this->assertSame( 'logscope/v1', RestController::REST_NAMESPACE );
	}
}
