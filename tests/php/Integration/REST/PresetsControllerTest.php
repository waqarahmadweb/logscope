<?php
/**
 * Integration tests for the /presets controller.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Integration\REST;

use Brain\Monkey\Functions;
use Logscope\REST\PresetsController;
use Logscope\Settings\PresetStore;
use Logscope\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * End-to-end coverage of GET / POST / DELETE on /presets, exercising
 * a real PresetStore against a stubbed user-meta layer so the
 * controller-to-store wiring (current-user resolution, idempotent
 * save, 404 on unknown name, route registration) is covered.
 */
final class PresetsControllerTest extends TestCase {

	/**
	 * In-memory user-meta store wired through stubbed get/update_user_meta.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta;

	private int $current_user_id;

	private PresetsController $controller;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();

		$this->meta            = array();
		$this->current_user_id = 7;

		Functions\when( 'get_user_meta' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $single mirrors the WP signature.
			function ( int $user_id, string $key, bool $single = false ) {
				return $this->meta[ $user_id ][ $key ] ?? '';
			}
		);

		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) {
				$this->meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'get_current_user_id' )->alias(
			function () {
				return $this->current_user_id;
			}
		);

		$this->controller = new PresetsController( new PresetStore() );
	}

	public function test_get_returns_empty_for_user_with_no_presets(): void {
		$response = $this->controller->handle_get();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'items' => array() ), $response->get_data() );
	}

	public function test_post_persists_and_returns_full_list(): void {
		$response = $this->controller->handle_post(
			new WP_REST_Request(
				array(
					'name'    => 'Akismet only',
					'filters' => array(
						'severity' => array( 'fatal' ),
						'source'   => 'plugins/akismet',
						'viewMode' => 'grouped',
					),
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$items = $response->get_data()['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'Akismet only', $items[0]['name'] );
		$this->assertSame( 'plugins/akismet', $items[0]['filters']['source'] );
	}

	public function test_post_overwrites_existing_preset_by_name(): void {
		$this->controller->handle_post(
			new WP_REST_Request(
				array(
					'name'    => 'My preset',
					'filters' => array( 'q' => 'first' ),
				)
			)
		);
		$response = $this->controller->handle_post(
			new WP_REST_Request(
				array(
					'name'    => 'My preset',
					'filters' => array( 'q' => 'second' ),
				)
			)
		);

		$items = $response->get_data()['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'second', $items[0]['filters']['q'] );
	}

	public function test_post_returns_400_for_missing_name(): void {
		$result = $this->controller->handle_post(
			new WP_REST_Request( array( 'filters' => array() ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_invalid_preset_name', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_endpoints_return_401_when_unauthenticated(): void {
		$this->current_user_id = 0;

		$get = $this->controller->handle_get();
		$this->assertInstanceOf( WP_Error::class, $get );
		$this->assertSame( 401, $get->get_error_data()['status'] );

		$post = $this->controller->handle_post(
			new WP_REST_Request( array( 'name' => 'x' ) )
		);
		$this->assertInstanceOf( WP_Error::class, $post );
		$this->assertSame( 401, $post->get_error_data()['status'] );

		$req = new WP_REST_Request();
		$req->set_param( 'name', 'x' );
		$delete = $this->controller->handle_delete( $req );
		$this->assertInstanceOf( WP_Error::class, $delete );
		$this->assertSame( 401, $delete->get_error_data()['status'] );
	}

	public function test_delete_removes_preset_and_returns_remaining_list(): void {
		$this->controller->handle_post(
			new WP_REST_Request(
				array(
					'name'    => 'a',
					'filters' => array(),
				)
			)
		);
		$this->controller->handle_post(
			new WP_REST_Request(
				array(
					'name'    => 'b',
					'filters' => array(),
				)
			)
		);

		$req = new WP_REST_Request();
		$req->set_param( 'name', 'a' );
		$response = $this->controller->handle_delete( $req );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$items = $response->get_data()['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'b', $items[0]['name'] );
	}

	public function test_delete_returns_404_for_unknown_name(): void {
		$req = new WP_REST_Request();
		$req->set_param( 'name', 'nope' );

		$result = $this->controller->handle_delete( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_preset_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_isolated_per_user(): void {
		$this->controller->handle_post(
			new WP_REST_Request(
				array(
					'name'    => 'mine',
					'filters' => array(),
				)
			)
		);

		$this->current_user_id = 99;
		$response              = $this->controller->handle_get();

		$this->assertSame( array(), $response->get_data()['items'] );
	}

	public function test_register_routes_registers_collection_and_item(): void {
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

		$this->controller->register_routes();

		$this->assertCount( 2, $captured );
		$this->assertSame( '/presets', $captured[0]['route'] );
		$this->assertSame( 'GET', $captured[0]['args'][0]['methods'] );
		$this->assertSame( 'POST', $captured[0]['args'][1]['methods'] );
		$this->assertSame( '/presets/(?P<name>[^/]+)', $captured[1]['route'] );
		$this->assertSame( 'DELETE', $captured[1]['args']['methods'] );
	}
}
