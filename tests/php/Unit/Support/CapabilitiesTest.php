<?php
/**
 * Unit tests for the Capabilities helper.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Support;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Logscope\Support\Capabilities;
use Logscope\Tests\TestCase;

final class CapabilitiesTest extends TestCase {

	public function test_required_returns_default_when_no_filter_applied(): void {
		Filters\expectApplied( 'logscope/required_capability' )
			->once()
			->with( Capabilities::DEFAULT_CAPABILITY )
			->andReturn( Capabilities::DEFAULT_CAPABILITY );

		$this->assertSame( 'logscope_manage', Capabilities::required() );
	}

	public function test_required_honors_filter_override(): void {
		Filters\expectApplied( 'logscope/required_capability' )
			->once()
			->andReturn( 'manage_options' );

		$this->assertSame( 'manage_options', Capabilities::required() );
	}

	public function test_required_falls_back_when_filter_returns_empty_string(): void {
		Filters\expectApplied( 'logscope/required_capability' )
			->once()
			->andReturn( '' );

		$this->assertSame( Capabilities::DEFAULT_CAPABILITY, Capabilities::required() );
	}

	public function test_required_falls_back_when_filter_returns_non_string(): void {
		Filters\expectApplied( 'logscope/required_capability' )
			->once()
			->andReturn( null );

		$this->assertSame( Capabilities::DEFAULT_CAPABILITY, Capabilities::required() );
	}

	public function test_has_manage_cap_delegates_to_current_user_can_when_no_user_id(): void {
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'logscope_manage' )
			->andReturn( true );

		$this->assertTrue( Capabilities::has_manage_cap() );
	}

	public function test_has_manage_cap_returns_false_when_current_user_lacks_cap(): void {
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'logscope_manage' )
			->andReturn( false );

		$this->assertFalse( Capabilities::has_manage_cap() );
	}

	public function test_has_manage_cap_delegates_to_user_can_when_user_id_given(): void {
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'user_can' )
			->once()
			->with( 42, 'logscope_manage' )
			->andReturn( true );

		$this->assertTrue( Capabilities::has_manage_cap( 42 ) );
	}
}
