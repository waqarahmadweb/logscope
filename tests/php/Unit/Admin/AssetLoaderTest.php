<?php
/**
 * Unit tests for the admin AssetLoader.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Admin;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Logscope\Admin\AssetLoader;
use Logscope\Admin\Menu;
use Logscope\Admin\PageRenderer;
use Logscope\Settings\Settings;
use Logscope\Settings\SettingsSchema;
use Logscope\Tests\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export

final class AssetLoaderTest extends TestCase {

	/**
	 * Temporary asset.php / index.js / index.css we point the loader at by
	 * defining LOGSCOPE_PLUGIN_FILE to a fake plugin file in the same dir.
	 * The constant can only be defined once per process, so we use a
	 * stable per-class dir; setUp wipes the build subdir between tests.
	 *
	 * @var string
	 */
	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );

		if ( ! defined( 'LOGSCOPE_PLUGIN_FILE' ) ) {
			$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-asset-suite';
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			define( 'LOGSCOPE_PLUGIN_FILE', $dir . '/logscope.php' );
		}

		$this->tmp_dir = dirname( (string) constant( 'LOGSCOPE_PLUGIN_FILE' ) );

		// Reset the build dir between tests so file_exists checks are deterministic.
		$build_dir = $this->tmp_dir . '/assets/build';
		if ( is_dir( $build_dir ) ) {
			$this->rrmdir( $build_dir );
		}
		mkdir( $build_dir, 0777, true );

		Functions\when( 'plugin_dir_path' )->alias(
			static function ( string $file ): string {
				return rtrim( dirname( $file ), '/\\' ) . '/';
			}
		);
		Functions\when( 'plugin_dir_url' )->alias(
			static function ( string $file ): string {
				return 'http://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			$this->rrmdir( $this->tmp_dir );
		}
		parent::tearDown();
	}

	private function rrmdir( string $dir ): void {
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function make_settings( int $tail_interval = 3 ): Settings {
		// Settings is `final`, so we use a real instance and stub the
		// underlying `get_option` lookup rather than a Mockery double.
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( $tail_interval ) {
				return 'logscope_tail_interval' === $key ? $tail_interval : $fallback;
			}
		);

		return new Settings( new SettingsSchema() );
	}

	private function make_menu_with_hook( string $hook ): Menu {
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'add_submenu_page' )->once()->andReturn( $hook );

		$menu = new Menu( new PageRenderer() );
		$menu->register();

		return $menu;
	}

	/**
	 * Writes a synthetic `index.asset.php` for the loader to consume.
	 *
	 * @param array $payload Asset map (`dependencies`, `version`).
	 */
	private function write_asset_file( array $payload ): void {
		file_put_contents(
			$this->tmp_dir . '/assets/build/index.asset.php',
			'<?php return ' . var_export( $payload, true ) . ';'
		);
		file_put_contents( $this->tmp_dir . '/assets/build/index.js', '/* fake */' );
	}

	public function test_enqueue_is_noop_on_other_screens(): void {
		$menu = $this->make_menu_with_hook( 'tools_page_logscope' );
		$this->write_asset_file(
			array(
				'dependencies' => array(),
				'version'      => 'x',
			)
		);

		$loader = new AssetLoader( $menu, $this->make_settings() );

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_localize_script' )->never();

		$loader->enqueue( 'index.php' );

		$this->assertTrue( true );
	}

	public function test_enqueue_skips_when_asset_file_missing(): void {
		$menu = $this->make_menu_with_hook( 'tools_page_logscope' );
		// No asset file written.

		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		( new AssetLoader( $menu, $this->make_settings() ) )->enqueue( 'tools_page_logscope' );

		$this->assertTrue( true );
	}

	public function test_enqueue_loads_script_with_deps_from_asset_file(): void {
		$menu = $this->make_menu_with_hook( 'tools_page_logscope' );
		$this->write_asset_file(
			array(
				'dependencies' => array( 'wp-element', 'wp-components' ),
				'version'      => 'abc123',
			)
		);

		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				AssetLoader::HANDLE,
				\Mockery::pattern( '#assets/build/index\.js$#' ),
				array( 'wp-element', 'wp-components' ),
				'abc123',
				true
			);
		// No CSS file → no style enqueue.
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_set_script_translations' )->once()->with( AssetLoader::HANDLE, 'logscope' );

		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'current_user_can' )->andReturn( true );
		Functions\expect( 'rest_url' )
			->twice()
			->andReturnUsing(
				static function ( string $path = '' ): string {
					return 'http://example.test/wp-json/' . ltrim( $path, '/' );
				}
			);
		Functions\expect( 'wp_create_nonce' )->once()->with( 'wp_rest' )->andReturn( 'NONCE' );
		Functions\expect( 'wp_localize_script' )
			->once()
			->with(
				AssetLoader::HANDLE,
				AssetLoader::LOCALIZE_OBJECT,
				\Mockery::on(
					static function ( $payload ): bool {
						return is_array( $payload )
							&& 'http://example.test/wp-json/logscope/v1/' === $payload['restUrl']
							&& 'http://example.test/wp-json/' === $payload['restRoot']
							&& 'NONCE' === $payload['nonce']
							&& true === $payload['canManage']
							&& 5 === $payload['tailInterval'];
					}
				)
			);

		( new AssetLoader( $menu, $this->make_settings( 5 ) ) )->enqueue( 'tools_page_logscope' );
	}

	public function test_enqueue_includes_style_when_css_present(): void {
		$menu = $this->make_menu_with_hook( 'tools_page_logscope' );
		$this->write_asset_file(
			array(
				'dependencies' => array(),
				'version'      => 'v1',
			)
		);
		file_put_contents( $this->tmp_dir . '/assets/build/index.css', '/* css */' );

		Functions\expect( 'wp_enqueue_script' )->once();
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with(
				AssetLoader::HANDLE,
				\Mockery::pattern( '#assets/build/index\.css$#' ),
				array(),
				'v1'
			);
		Functions\expect( 'wp_set_script_translations' )->once();
		Functions\expect( 'wp_localize_script' )->once();
		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'current_user_can' )->andReturn( false );
		Functions\expect( 'rest_url' )->andReturn( 'http://x/' );
		Functions\expect( 'wp_create_nonce' )->andReturn( 'N' );

		( new AssetLoader( $menu, $this->make_settings() ) )->enqueue( 'tools_page_logscope' );
	}

	public function test_localized_payload_reflects_can_manage_false(): void {
		$menu = $this->make_menu_with_hook( 'tools_page_logscope' );

		Filters\expectApplied( 'logscope/required_capability' )->andReturn( 'logscope_manage' );
		Functions\expect( 'current_user_can' )->once()->andReturn( false );
		Functions\expect( 'rest_url' )->andReturn( 'http://x/' );
		Functions\expect( 'wp_create_nonce' )->andReturn( 'N' );

		$payload = ( new AssetLoader( $menu, $this->make_settings( 7 ) ) )->localized_payload();

		$this->assertFalse( $payload['canManage'] );
		$this->assertSame( 7, $payload['tailInterval'] );
	}
}
