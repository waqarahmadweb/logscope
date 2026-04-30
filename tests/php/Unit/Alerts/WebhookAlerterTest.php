<?php
/**
 * Tests for the webhook alert dispatcher.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// json_encode is fine here: tests stub wp_json_encode and need to round-trip
// the same payload for assertions.
// phpcs:disable WordPress.WP.AlternativeFunctions.json_encode_json_encode

namespace Logscope\Tests\Unit\Alerts;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Logscope\Alerts\WebhookAlerter;
use Logscope\Log\Group;
use Logscope\Log\Severity;
use Logscope\Tests\TestCase;

/**
 * Unit coverage for the webhook backend: enable gate, URL validation,
 * scheme allowlist, payload shape, filter integration, and response
 * handling.
 *
 * @coversDefaultClass \Logscope\Alerts\WebhookAlerter
 */
final class WebhookAlerterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	public function test_name_is_webhook(): void {
		$this->assertSame( 'webhook', ( new WebhookAlerter( true, 'https://example.com/hook' ) )->name() );
	}

	public function test_is_enabled_requires_flag_and_url(): void {
		$this->assertFalse( ( new WebhookAlerter( false, 'https://example.com/hook' ) )->is_enabled() );
		$this->assertFalse( ( new WebhookAlerter( true, '' ) )->is_enabled() );
		$this->assertTrue( ( new WebhookAlerter( true, 'https://example.com/hook' ) )->is_enabled() );
	}

	public function test_dispatch_short_circuits_when_disabled(): void {
		Functions\expect( 'wp_remote_post' )->never();
		$alerter = new WebhookAlerter( false, 'https://example.com/hook' );
		$this->assertFalse( $alerter->dispatch( $this->fixture_group() ) );
	}

	public function test_dispatch_refuses_invalid_url(): void {
		Functions\when( 'wp_http_validate_url' )->justReturn( false );
		Functions\expect( 'wp_remote_post' )->never();

		$alerter = new WebhookAlerter( true, 'not a url' );
		$this->assertFalse( $alerter->dispatch( $this->fixture_group() ) );
	}

	public function test_dispatch_refuses_non_http_scheme(): void {
		// `wp_http_validate_url` may accept a `file://` URL on some
		// configurations; the dispatcher's own scheme allowlist must
		// still reject it.
		Functions\when( 'wp_http_validate_url' )->justReturn( 'file:///etc/passwd' );
		Functions\when( 'wp_parse_url' )->justReturn( 'file' );
		Functions\expect( 'wp_remote_post' )->never();

		$alerter = new WebhookAlerter( true, 'file:///etc/passwd' );
		$this->assertFalse( $alerter->dispatch( $this->fixture_group() ) );
	}

	public function test_dispatch_calls_wp_remote_post_with_expected_shape(): void {
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'wp_parse_url' )->justReturn( 'https' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$captured = array();
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured['url']  = $url;
				$captured['args'] = $args;
				return array( 'response' => array( 'code' => 200 ) );
			}
		);

		$alerter = new WebhookAlerter( true, 'https://example.com/hook' );
		$result  = $alerter->dispatch( $this->fixture_group() );

		$this->assertTrue( $result );
		$this->assertSame( 'https://example.com/hook', $captured['url'] );
		$this->assertSame( 5, $captured['args']['timeout'] );
		$this->assertSame( 0, $captured['args']['redirection'] );
		$this->assertTrue( $captured['args']['blocking'] );
		$this->assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );

		$payload = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'Acme', $payload['site'] );
		$this->assertSame( 'https://acme.test', $payload['url'] );
		$this->assertSame( Severity::FATAL, $payload['severity'] );
		$this->assertSame( 'sigabc', $payload['signature'] );
		$this->assertSame( 17, $payload['count'] );
		$this->assertSame( '/var/www/foo.php', $payload['file'] );
		$this->assertSame( 42, $payload['line'] );
	}

	public function test_dispatch_applies_payload_filter(): void {
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'wp_parse_url' )->justReturn( 'https' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		Filters\expectApplied( 'logscope/webhook_payload' )
			->once()
			->andReturn( array( 'text' => 'slack-shaped' ) );

		$captured = '';
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured = $args['body'];
				return array( 'response' => array( 'code' => 200 ) );
			}
		);

		$alerter = new WebhookAlerter( true, 'https://example.com/hook' );
		$alerter->dispatch( $this->fixture_group() );

		$decoded = json_decode( $captured, true );
		$this->assertSame( array( 'text' => 'slack-shaped' ), $decoded );
	}

	public function test_dispatch_reverts_to_default_payload_on_non_array_filter_return(): void {
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'wp_parse_url' )->justReturn( 'https' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		Filters\expectApplied( 'logscope/webhook_payload' )
			->once()
			->andReturn( 'oops not an array' );

		$captured = '';
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured = $args['body'];
				return array( 'response' => array( 'code' => 200 ) );
			}
		);

		$alerter = new WebhookAlerter( true, 'https://example.com/hook' );
		$alerter->dispatch( $this->fixture_group() );

		$decoded = json_decode( $captured, true );
		$this->assertSame( 'sigabc', $decoded['signature'] );
	}

	public function test_dispatch_returns_false_on_wp_error_response(): void {
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'wp_parse_url' )->justReturn( 'https' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_remote_post' )->justReturn( 'wp_error_sentinel' );
		Functions\when( 'is_wp_error' )->justReturn( true );

		$alerter = new WebhookAlerter( true, 'https://example.com/hook' );
		$this->assertFalse( $alerter->dispatch( $this->fixture_group() ) );
	}

	public function test_dispatch_returns_false_on_non_2xx_response(): void {
		Functions\when( 'wp_http_validate_url' )->returnArg();
		Functions\when( 'wp_parse_url' )->justReturn( 'https' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data );
			}
		);
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => 500 ) ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );

		$alerter = new WebhookAlerter( true, 'https://example.com/hook' );
		$this->assertFalse( $alerter->dispatch( $this->fixture_group() ) );
	}

	private function fixture_group(): Group {
		return new Group(
			'sigabc',
			Severity::FATAL,
			'/var/www/foo.php',
			42,
			'Uncaught Error: Call to undefined function nope() in foo.php',
			17,
			'27-Apr-2026 12:00:00 UTC',
			'27-Apr-2026 14:00:00 UTC'
		);
	}
}
