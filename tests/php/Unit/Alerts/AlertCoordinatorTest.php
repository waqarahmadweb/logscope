<?php
/**
 * Tests for AlertCoordinator.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Alerts;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Logscope\Alerts\AlertCoordinator;
use Logscope\Alerts\AlertDeduplicator;
use Logscope\Alerts\AlertDispatcherInterface;
use Logscope\Log\Group;
use Logscope\Log\Severity;
use Logscope\Tests\TestCase;
use Mockery;

/**
 * Unit coverage for the coordinator: enable gate, dedup, before_alert
 * filter short-circuit, alert_sent action, and the test-alert bypass.
 *
 * @coversDefaultClass \Logscope\Alerts\AlertCoordinator
 */
final class AlertCoordinatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_disabled_dispatcher_is_skipped_without_dispatch(): void {
		$dispatcher = $this->fake_dispatcher( 'email', false, true );
		$dispatcher->shouldReceive( 'dispatch' )->never();

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->never();
		$dedup->shouldReceive( 'record_sent' )->never();

		$coord = new AlertCoordinator( array( $dispatcher ), $dedup );
		$out   = $coord->dispatch_for_groups( array( $this->fixture_group( 'sigA' ) ) );

		$this->assertSame( 'skipped', $out[0]['outcome'] );
	}

	public function test_deduped_signature_is_skipped_without_dispatch(): void {
		$dispatcher = $this->fake_dispatcher( 'email', true, true );
		$dispatcher->shouldReceive( 'dispatch' )->never();

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->once()->with( 'email', 'sigA' )->andReturn( false );
		$dedup->shouldReceive( 'record_sent' )->never();

		$coord = new AlertCoordinator( array( $dispatcher ), $dedup );
		$out   = $coord->dispatch_for_groups( array( $this->fixture_group( 'sigA' ) ) );

		$this->assertSame( 'deduped', $out[0]['outcome'] );
	}

	public function test_successful_dispatch_records_sent_and_fires_action(): void {
		$dispatcher = $this->fake_dispatcher( 'email', true, true );
		$dispatcher->shouldReceive( 'dispatch' )->once()->andReturn( true );

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->once()->andReturn( true );
		$dedup->shouldReceive( 'record_sent' )->once()->with( 'email', 'sigA' );

		Actions\expectDone( 'logscope/alert_sent' )->once();

		$coord = new AlertCoordinator( array( $dispatcher ), $dedup );
		$out   = $coord->dispatch_for_groups( array( $this->fixture_group( 'sigA' ) ) );

		$this->assertSame( 'sent', $out[0]['outcome'] );
	}

	public function test_failed_dispatch_does_not_record_sent(): void {
		$dispatcher = $this->fake_dispatcher( 'email', true, true );
		$dispatcher->shouldReceive( 'dispatch' )->once()->andReturn( false );

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->once()->andReturn( true );
		$dedup->shouldReceive( 'record_sent' )->never();

		$coord = new AlertCoordinator( array( $dispatcher ), $dedup );
		$out   = $coord->dispatch_for_groups( array( $this->fixture_group( 'sigA' ) ) );

		$this->assertSame( 'failed', $out[0]['outcome'] );
	}

	public function test_before_alert_filter_can_short_circuit_dispatch(): void {
		$dispatcher = $this->fake_dispatcher( 'email', true, true );
		$dispatcher->shouldReceive( 'dispatch' )->never();

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->never();

		Filters\expectApplied( 'logscope/before_alert' )->once()->andReturn( false );

		$coord = new AlertCoordinator( array( $dispatcher ), $dedup );
		$out   = $coord->dispatch_for_groups( array( $this->fixture_group( 'sigA' ) ) );

		$this->assertSame( 'skipped', $out[0]['outcome'] );
	}

	public function test_distinct_dispatchers_have_independent_dedup_per_signature(): void {
		$email   = $this->fake_dispatcher( 'email', true, true );
		$webhook = $this->fake_dispatcher( 'webhook', true, true );
		$email->shouldReceive( 'dispatch' )->never();        // deduped.
		$webhook->shouldReceive( 'dispatch' )->once()->andReturn( true );

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->with( 'email', 'sigA' )->andReturn( false );
		$dedup->shouldReceive( 'should_send' )->with( 'webhook', 'sigA' )->andReturn( true );
		$dedup->shouldReceive( 'record_sent' )->with( 'webhook', 'sigA' )->once();

		$coord = new AlertCoordinator( array( $email, $webhook ), $dedup );
		$out   = $coord->dispatch_for_groups( array( $this->fixture_group( 'sigA' ) ) );

		$this->assertSame( 'deduped', $out[0]['outcome'] );
		$this->assertSame( 'sent', $out[1]['outcome'] );
	}

	public function test_bypass_dedup_skips_should_send_check_and_clears_mark_on_success(): void {
		$dispatcher = $this->fake_dispatcher( 'email', true, true );
		$dispatcher->shouldReceive( 'dispatch' )->once()->andReturn( true );

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$dedup->shouldReceive( 'should_send' )->never();
		$dedup->shouldReceive( 'record_sent' )->never();
		$dedup->shouldReceive( 'clear' )->once()->with( 'email', 'sigA' );

		$coord = new AlertCoordinator( array( $dispatcher ), $dedup );
		$out   = $coord->dispatch_one( $dispatcher, $this->fixture_group( 'sigA' ), true );

		$this->assertSame( 'sent', $out['outcome'] );
	}

	public function test_dispatchers_returns_registration_order(): void {
		$a = $this->fake_dispatcher( 'a', true, true );
		$b = $this->fake_dispatcher( 'b', true, true );

		$dedup = Mockery::mock( AlertDeduplicator::class );
		$coord = new AlertCoordinator( array( $a, $b ), $dedup );

		$this->assertSame( array( $a, $b ), $coord->dispatchers() );
	}

	private function fake_dispatcher( string $name, bool $enabled, bool $allow_name_calls ): Mockery\MockInterface {
		$mock = Mockery::mock( AlertDispatcherInterface::class );
		$mock->shouldReceive( 'name' )->zeroOrMoreTimes()->andReturn( $name );
		$mock->shouldReceive( 'is_enabled' )->zeroOrMoreTimes()->andReturn( $enabled );
		return $mock;
	}

	private function fixture_group( string $signature ): Group {
		return new Group(
			$signature,
			Severity::FATAL,
			'/foo.php',
			1,
			'msg',
			1,
			null,
			null
		);
	}
}
