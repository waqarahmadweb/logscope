<?php
/**
 * Unit tests for the admin Menu registrar.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Logscope\Admin\Menu;
use Logscope\Admin\PageRenderer;
use Logscope\Tests\TestCase;

final class MenuTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	public function test_register_calls_add_submenu_page_under_tools_with_logscope_capability(): void {
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );

		$renderer = new PageRenderer();
		$menu     = new Menu( $renderer );

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'tools.php',
				'Logscope',
				'Logscope',
				'logscope_manage',
				'logscope',
				array( $renderer, 'render' )
			)
			->andReturn( 'tools_page_logscope' );

		$menu->register();

		$this->assertSame( 'tools_page_logscope', $menu->hook_suffix() );
	}

	public function test_register_honors_required_capability_filter_override(): void {
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'manage_options' );

		$menu = new Menu( new PageRenderer() );

		Functions\expect( 'add_submenu_page' )
			->once()
			->with(
				'tools.php',
				\Mockery::any(),
				\Mockery::any(),
				'manage_options',
				'logscope',
				\Mockery::any()
			)
			->andReturn( 'tools_page_logscope' );

		$menu->register();
	}

	public function test_hook_suffix_is_empty_when_add_submenu_returns_non_string(): void {
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );

		$menu = new Menu( new PageRenderer() );

		Functions\expect( 'add_submenu_page' )->once()->andReturn( false );

		$menu->register();

		$this->assertSame( '', $menu->hook_suffix() );
	}
}
