<?php
/**
 * Unit tests for LogQuery validation.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Log;

use Brain\Monkey\Functions;
use Logscope\Log\LogQuery;
use Logscope\Log\LogQueryException;
use Logscope\Tests\TestCase;

final class LogQueryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Validation messages are translated (they surface in REST 400
		// bodies), so the throwing paths need the i18n stub.
		Functions\when( '__' )->returnArg( 1 );
	}

	public function test_since_byte_zero_is_accepted(): void {
		$query = new LogQuery( null, null, null, null, null, false, 1, 50, 0 );
		$this->assertSame( 0, $query->since_byte );
	}

	public function test_since_byte_null_is_accepted(): void {
		$query = new LogQuery( null, null, null, null, null, false, 1, 50, null );
		$this->assertNull( $query->since_byte );
	}

	public function test_negative_since_byte_throws(): void {
		$this->expectException( LogQueryException::class );
		new LogQuery( null, null, null, null, null, false, 1, 50, -1 );
	}

	public function test_date_only_from_is_start_of_day(): void {
		$query = new LogQuery( null, '2026-09-10', null, null, null, false, 1, 50 );
		$this->assertSame( '2026-09-10 00:00:00', $query->from->format( 'Y-m-d H:i:s' ) );
	}

	public function test_date_only_to_is_end_of_day(): void {
		$query = new LogQuery( null, null, '2026-09-10', null, null, false, 1, 50 );
		$this->assertSame( '2026-09-10 23:59:59', $query->to->format( 'Y-m-d H:i:s' ) );
	}

	public function test_explicit_time_on_to_is_kept(): void {
		$query = new LogQuery( null, null, '2026-09-10 08:15:00', null, null, false, 1, 50 );
		$this->assertSame( '2026-09-10 08:15:00', $query->to->format( 'Y-m-d H:i:s' ) );
	}

	public function test_same_day_from_and_to_covers_whole_day(): void {
		$query = new LogQuery( null, '2026-09-10', '2026-09-10', null, null, false, 1, 50 );
		$this->assertTrue( $query->from < $query->to );
		$this->assertSame( '2026-09-10', $query->to->format( 'Y-m-d' ) );
	}
}
