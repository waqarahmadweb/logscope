<?php
/**
 * Unit tests for LogGrouper.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Log;

use Logscope\Log\Entry;
use Logscope\Log\LogGrouper;
use Logscope\Log\Severity;
use Logscope\Tests\TestCase;

final class LogGrouperTest extends TestCase {

	public function test_empty_input_returns_empty_array(): void {
		$this->assertSame( array(), LogGrouper::group( array() ) );
	}

	public function test_identical_entries_collapse_to_single_group(): void {
		$e1     = $this->entry( Severity::WARNING, '/x.php', 1, 'foo', '27-Apr-2026 12:00:00' );
		$e2     = $this->entry( Severity::WARNING, '/x.php', 1, 'foo', '27-Apr-2026 12:00:01' );
		$groups = LogGrouper::group( array( $e1, $e2 ) );

		$this->assertCount( 1, $groups );
		$this->assertSame( 2, $groups[0]->count );
	}

	public function test_different_severities_do_not_merge(): void {
		$groups = LogGrouper::group(
			array(
				$this->entry( Severity::WARNING, '/x.php', 1, 'foo' ),
				$this->entry( Severity::NOTICE, '/x.php', 1, 'foo' ),
			)
		);

		$this->assertCount( 2, $groups );
	}

	public function test_different_files_do_not_merge(): void {
		$groups = LogGrouper::group(
			array(
				$this->entry( Severity::WARNING, '/a.php', 1, 'foo' ),
				$this->entry( Severity::WARNING, '/b.php', 1, 'foo' ),
			)
		);

		$this->assertCount( 2, $groups );
	}

	public function test_different_lines_do_not_merge(): void {
		$groups = LogGrouper::group(
			array(
				$this->entry( Severity::WARNING, '/x.php', 1, 'foo' ),
				$this->entry( Severity::WARNING, '/x.php', 2, 'foo' ),
			)
		);

		$this->assertCount( 2, $groups );
	}

	public function test_messages_differing_only_in_numbers_merge(): void {
		$groups = LogGrouper::group(
			array(
				$this->entry( Severity::WARNING, '/x.php', 1, 'Cannot find post 1234' ),
				$this->entry( Severity::WARNING, '/x.php', 1, 'Cannot find post 9999' ),
				$this->entry( Severity::WARNING, '/x.php', 1, 'Cannot find post 5' ),
			)
		);

		$this->assertCount( 1, $groups );
		$this->assertSame( 3, $groups[0]->count );
	}

	public function test_messages_differing_only_in_single_quoted_strings_merge(): void {
		$groups = LogGrouper::group(
			array(
				$this->entry( Severity::WARNING, '/x.php', 1, "Undefined index 'foo'" ),
				$this->entry( Severity::WARNING, '/x.php', 1, "Undefined index 'bar'" ),
			)
		);

		$this->assertCount( 1, $groups );
		$this->assertSame( 2, $groups[0]->count );
	}

	public function test_messages_differing_only_in_double_quoted_strings_merge(): void {
		$groups = LogGrouper::group(
			array(
				$this->entry( Severity::WARNING, '/x.php', 1, 'Undefined index "foo"' ),
				$this->entry( Severity::WARNING, '/x.php', 1, 'Undefined index "qux"' ),
			)
		);

		$this->assertCount( 1, $groups );
	}

	public function test_messages_differing_only_in_hex_addresses_merge(): void {
		$groups = LogGrouper::group(
			array(
				$this->entry( Severity::WARNING, '/x.php', 1, 'Object at 0xdeadbeef' ),
				$this->entry( Severity::WARNING, '/x.php', 1, 'Object at 0xCAFEBABE' ),
			)
		);

		$this->assertCount( 1, $groups );
	}

	public function test_groups_sorted_by_count_descending(): void {
		$entries = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$entries[] = $this->entry( Severity::WARNING, '/a.php', 1, 'first' );
		}
		for ( $i = 0; $i < 10; $i++ ) {
			$entries[] = $this->entry( Severity::WARNING, '/b.php', 1, 'second' );
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$entries[] = $this->entry( Severity::WARNING, '/c.php', 1, 'third' );
		}

		$groups = LogGrouper::group( $entries );

		$this->assertCount( 3, $groups );
		$this->assertSame( 10, $groups[0]->count );
		$this->assertSame( 5, $groups[1]->count );
		$this->assertSame( 2, $groups[2]->count );
	}

	public function test_first_and_last_seen_track_min_and_max_timestamp(): void {
		$entries = array(
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', '27-Apr-2026 12:00:30' ),
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', '27-Apr-2026 12:00:00' ),
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', '27-Apr-2026 12:00:59' ),
		);

		$groups = LogGrouper::group( $entries );

		$this->assertCount( 1, $groups );
		$this->assertSame( '27-Apr-2026 12:00:00', $groups[0]->first_seen );
		$this->assertSame( '27-Apr-2026 12:00:59', $groups[0]->last_seen );
	}

	public function test_first_and_last_seen_compare_across_months(): void {
		// March/May/August all start with different letters but the WP
		// format is not lexically sortable; this guards against a
		// regression where we accidentally string-compare timestamps.
		$entries = array(
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', '15-Aug-2026 12:00:00' ),
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', '15-May-2026 12:00:00' ),
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', '15-Mar-2026 12:00:00' ),
		);

		$groups = LogGrouper::group( $entries );

		$this->assertSame( '15-Mar-2026 12:00:00', $groups[0]->first_seen );
		$this->assertSame( '15-Aug-2026 12:00:00', $groups[0]->last_seen );
	}

	public function test_entries_without_timestamps_still_grouped_but_do_not_set_window(): void {
		$entries = array(
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', null ),
			$this->entry( Severity::WARNING, '/x.php', 1, 'foo', null ),
		);

		$groups = LogGrouper::group( $entries );

		$this->assertCount( 1, $groups );
		$this->assertSame( 2, $groups[0]->count );
		$this->assertNull( $groups[0]->first_seen );
		$this->assertNull( $groups[0]->last_seen );
	}

	public function test_sample_message_is_first_observed_message(): void {
		$entries = array(
			$this->entry( Severity::WARNING, '/x.php', 1, 'Cannot find post 1234' ),
			$this->entry( Severity::WARNING, '/x.php', 1, 'Cannot find post 9999' ),
		);

		$groups = LogGrouper::group( $entries );

		$this->assertSame( 'Cannot find post 1234', $groups[0]->sample_message );
	}

	public function test_signature_is_stable_for_equivalent_entries(): void {
		$e1 = $this->entry( Severity::WARNING, '/x.php', 1, 'Cannot find post 1234' );
		$e2 = $this->entry( Severity::WARNING, '/x.php', 1, 'Cannot find post 9999' );

		$this->assertSame( LogGrouper::signature( $e1 ), LogGrouper::signature( $e2 ) );
	}

	public function test_thousand_varied_lines_collapse_to_expected_signatures(): void {
		// Five templates × 200 random instantiations each = 1000 entries
		// that should fold to exactly 5 groups, sorted by descending
		// count. Counts are made unequal so the sort is testable.
		$templates = array(
			array( Severity::WARNING, '/wp/a.php', 10, 'Undefined index "%s"', 350 ),
			array( Severity::NOTICE, '/wp/b.php', 20, 'Cannot find post %d', 250 ),
			array( Severity::DEPRECATED, '/wp/c.php', 30, "Function %s is deprecated, use '%s' instead", 200 ),
			array( Severity::FATAL, '/wp/d.php', 40, 'Object 0x%x freed twice', 150 ),
			array( Severity::PARSE, '/wp/e.php', 50, 'syntax error near %d', 50 ),
		);

		$entries = array();
		foreach ( $templates as $template ) {
			list( $severity, $file, $line, $format, $count ) = $template;
			for ( $i = 0; $i < $count; $i++ ) {
				$message   = sprintf(
					$format,
					'sub_' . random_int( 0, 999999 ),
					random_int( 0, 999999 )
				);
				$entries[] = $this->entry( $severity, $file, $line, $message );
			}
		}

		shuffle( $entries );

		$groups = LogGrouper::group( $entries );

		$this->assertCount( 5, $groups );
		$this->assertSame(
			array( 350, 250, 200, 150, 50 ),
			array_map(
				static fn ( $g ) => $g->count,
				$groups
			)
		);
	}

	private function entry(
		string $severity,
		?string $file,
		?int $line,
		string $message,
		?string $timestamp = '27-Apr-2026 12:00:00'
	): Entry {
		return new Entry(
			$severity,
			$timestamp,
			null === $timestamp ? null : 'UTC',
			$message,
			$file,
			$line,
			$message
		);
	}
}
