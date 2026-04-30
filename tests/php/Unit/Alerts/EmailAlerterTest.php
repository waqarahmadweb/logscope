<?php
/**
 * Tests for the email alert dispatcher.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Alerts;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Logscope\Alerts\EmailAlerter;
use Logscope\Log\Group;
use Logscope\Log\Severity;
use Logscope\Tests\TestCase;

/**
 * Unit coverage for the email backend: enable gate, wp_mail call shape,
 * filter integration, and header-injection refusal.
 *
 * @coversDefaultClass \Logscope\Alerts\EmailAlerter
 */
final class EmailAlerterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	public function test_name_is_email(): void {
		$alerter = new EmailAlerter( true, 'ops@example.com' );
		$this->assertSame( 'email', $alerter->name() );
	}

	public function test_is_enabled_requires_flag_and_recipient(): void {
		$this->assertFalse( ( new EmailAlerter( false, 'ops@example.com' ) )->is_enabled() );
		$this->assertFalse( ( new EmailAlerter( true, '' ) )->is_enabled() );
		$this->assertTrue( ( new EmailAlerter( true, 'ops@example.com' ) )->is_enabled() );
	}

	public function test_dispatch_short_circuits_when_disabled(): void {
		Functions\expect( 'wp_mail' )->never();

		$alerter = new EmailAlerter( false, 'ops@example.com' );
		$this->assertFalse( $alerter->dispatch( $this->fixture_group() ) );
	}

	public function test_dispatch_calls_wp_mail_with_expected_subject_and_recipient(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );

		$captured = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) use ( &$captured ) {
				$captured['to']      = $to;
				$captured['subject'] = $subject;
				$captured['body']    = $body;
				return true;
			}
		);

		$alerter = new EmailAlerter( true, 'ops@example.com' );
		$result  = $alerter->dispatch( $this->fixture_group() );

		$this->assertTrue( $result );
		$this->assertSame( 'ops@example.com', $captured['to'] );
		$this->assertStringContainsString( '[Logscope]', $captured['subject'] );
		$this->assertStringContainsString( 'Fatal error', $captured['subject'] );
		$this->assertStringContainsString( 'Acme', $captured['subject'] );
		$this->assertStringContainsString( 'undefined function nope()', $captured['subject'] );
		$this->assertStringContainsString( '<table', $captured['body'] );
		$this->assertStringContainsString( '/var/www/wp-content/plugins/example/foo.php:42', $captured['body'] );
	}

	public function test_dispatch_applies_subject_filter(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );

		Filters\expectApplied( 'logscope/email_subject' )
			->once()
			->andReturn( 'OVERRIDDEN SUBJECT' );

		$captured = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject ) use ( &$captured ) {
				$captured['subject'] = $subject;
				return true;
			}
		);

		$alerter = new EmailAlerter( true, 'ops@example.com' );
		$alerter->dispatch( $this->fixture_group() );

		$this->assertSame( 'OVERRIDDEN SUBJECT', $captured['subject'] );
	}

	public function test_dispatch_applies_body_filter_with_html_and_plain_keys(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );

		Filters\expectApplied( 'logscope/email_body' )
			->once()
			->andReturn(
				array(
					'html'  => '<p>custom html</p>',
					'plain' => 'custom plain',
				)
			);

		$captured = array();
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $body ) use ( &$captured ) {
				$captured['body'] = $body;
				return true;
			}
		);

		$alerter = new EmailAlerter( true, 'ops@example.com' );
		$alerter->dispatch( $this->fixture_group() );

		$this->assertSame( '<p>custom html</p>', $captured['body'] );
	}

	public function test_dispatch_refuses_header_injection_in_recipient(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\expect( 'wp_mail' )->never();

		$alerter = new EmailAlerter( true, "ops@example.com\r\nBcc: attacker@evil.test" );
		$this->assertFalse( $alerter->dispatch( $this->fixture_group() ) );
	}

	public function test_dispatch_registers_phpmailer_init_action_for_alt_body(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'wp_mail' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );

		Actions\expectAdded( 'phpmailer_init' )->once();
		Actions\expectRemoved( 'phpmailer_init' )->once();

		$alerter = new EmailAlerter( true, 'ops@example.com' );
		$alerter->dispatch( $this->fixture_group() );
	}

	public function test_dispatch_truncates_long_message_in_subject(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
		Functions\when( 'home_url' )->justReturn( 'https://acme.test' );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );

		$captured = '';
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject ) use ( &$captured ) {
				$captured = $subject;
				return true;
			}
		);

		$long_message = str_repeat( 'A very long message ', 20 );
		$group        = new Group( 'sigxyz', Severity::FATAL, '/foo.php', 1, $long_message, 1, null, null );

		$alerter = new EmailAlerter( true, 'ops@example.com' );
		$alerter->dispatch( $group );

		$this->assertStringContainsString( '…', $captured );
	}

	private function fixture_group(): Group {
		return new Group(
			'sigabc',
			Severity::FATAL,
			'/var/www/wp-content/plugins/example/foo.php',
			42,
			'Uncaught Error: Call to undefined function nope() in foo.php',
			17,
			'27-Apr-2026 12:00:00 UTC',
			'27-Apr-2026 14:00:00 UTC'
		);
	}
}
