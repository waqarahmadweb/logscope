<?php
/**
 * Integration tests for the /logs/mute controller.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Integration\REST;

use Brain\Monkey\Functions;
use Logscope\Log\MuteStore;
use Logscope\REST\MuteController;
use Logscope\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * End-to-end coverage of GET / POST / DELETE on /logs/mute. The store
 * itself is exercised through real `MuteStore` calls against a stubbed
 * options layer rather than a Mockery double — the integration value
 * is in confirming controller-to-store wiring (param extraction,
 * idempotent updates, 404 on unknown signature, route registration).
 */
final class MuteControllerTest extends TestCase {

	/**
	 * In-memory option store wired through stubbed get/update_option.
	 *
	 * @var array{values: array<string, mixed>}
	 */
	private array $store;

	private MuteController $controller;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();

		$this->store = array( 'values' => array() );

		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				return array_key_exists( $key, $this->store['values'] ) ? $this->store['values'][ $key ] : $fallback;
			}
		);

		Functions\when( 'update_option' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $autoload mirrors the WP signature so MuteStore::add(..., false) matches the alias.
			function ( string $key, $value, $autoload = null ) {
				$this->store['values'][ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text ): string {
				return preg_replace( '/<[^>]+>/', '', $text ) ?? '';
			}
		);

		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$this->controller = new MuteController( new MuteStore() );
	}

	public function test_get_returns_empty_list_initially(): void {
		$response = $this->controller->handle_get();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'items' => array() ), $response->get_data() );
	}

	public function test_post_persists_record_and_returns_full_list(): void {
		$request = new WP_REST_Request(
			array(
				'signature' => 'sig-abc',
				'reason'    => 'Known noisy plugin',
			)
		);

		$response = $this->controller->handle_post( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$items = $response->get_data()['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'sig-abc', $items[0]['signature'] );
		$this->assertSame( 'Known noisy plugin', $items[0]['reason'] );
		$this->assertSame( 42, $items[0]['muted_by'] );
	}

	public function test_post_is_idempotent_on_repeat_signature(): void {
		$first  = $this->controller->handle_post(
			new WP_REST_Request(
				array(
					'signature' => 'sig-abc',
					'reason'    => 'first',
				)
			)
		);
		$second = $this->controller->handle_post(
			new WP_REST_Request(
				array(
					'signature' => 'sig-abc',
					'reason'    => 'second',
				)
			)
		);

		$this->assertCount( 1, $first->get_data()['items'] );
		$this->assertCount( 1, $second->get_data()['items'] );
		$this->assertSame( 'second', $second->get_data()['items'][0]['reason'] );
	}

	public function test_post_returns_400_when_signature_missing_or_empty(): void {
		$result_missing = $this->controller->handle_post( new WP_REST_Request( array() ) );
		$result_empty   = $this->controller->handle_post( new WP_REST_Request( array( 'signature' => '   ' ) ) );

		foreach ( array( $result_missing, $result_empty ) as $result ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'logscope_rest_invalid_signature', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	public function test_post_accepts_missing_reason_as_empty_string(): void {
		$response = $this->controller->handle_post(
			new WP_REST_Request( array( 'signature' => 'sig-abc' ) )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( '', $response->get_data()['items'][0]['reason'] );
	}

	public function test_delete_removes_record_and_returns_remaining_list(): void {
		$this->controller->handle_post(
			new WP_REST_Request(
				array(
					'signature' => 'sig-abc',
					'reason'    => 'r',
				)
			)
		);

		$request = new WP_REST_Request();
		$request->set_param( 'signature', 'sig-abc' );
		$response = $this->controller->handle_delete( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array(), $response->get_data()['items'] );
	}

	public function test_delete_returns_404_for_unknown_signature(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'signature', 'sig-missing' );

		$result = $this->controller->handle_delete( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'logscope_rest_signature_not_muted', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
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
		$this->assertSame( 'logscope/v1', $captured[0]['namespace'] );
		$this->assertSame( '/logs/mute', $captured[0]['route'] );
		// Collection registers GET + POST as a list of method handlers.
		$this->assertSame( 'GET', $captured[0]['args'][0]['methods'] );
		$this->assertSame( 'POST', $captured[0]['args'][1]['methods'] );

		$this->assertSame( '/logs/mute/(?P<signature>[^/]+)', $captured[1]['route'] );
		$this->assertSame( 'DELETE', $captured[1]['args']['methods'] );
	}
}
