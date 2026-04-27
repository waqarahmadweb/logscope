<?php
/**
 * Unit tests for LogQuery validation.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Log;

use Logscope\Log\LogQuery;
use Logscope\Log\LogQueryException;
use Logscope\Tests\TestCase;

final class LogQueryTest extends TestCase {

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
}
