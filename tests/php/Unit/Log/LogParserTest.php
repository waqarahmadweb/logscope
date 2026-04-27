<?php
/**
 * Unit tests for LogParser.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Log;

use Logscope\Log\LogParser;
use Logscope\Log\Severity;
use Logscope\Tests\TestCase;

final class LogParserTest extends TestCase {

	public function test_empty_input_returns_empty_array(): void {
		$this->assertSame( array(), LogParser::parse( '' ) );
	}

	public function test_parses_fatal_error(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  Uncaught Error: boom in /var/www/wp-content/plugins/x/main.php:42';
		$entries = LogParser::parse( $line );

		$this->assertCount( 1, $entries );
		$entry = $entries[0];
		$this->assertSame( Severity::FATAL, $entry->severity );
		$this->assertSame( '27-Apr-2026 12:34:56', $entry->timestamp );
		$this->assertSame( 'UTC', $entry->timezone );
		$this->assertSame( 'Uncaught Error: boom in /var/www/wp-content/plugins/x/main.php:42', $entry->message );
		$this->assertSame( '/var/www/wp-content/plugins/x/main.php', $entry->file );
		$this->assertSame( 42, $entry->line );
		$this->assertSame( $line, $entry->raw );
	}

	public function test_parses_parse_error(): void {
		$line    = "[27-Apr-2026 12:34:56 UTC] PHP Parse error:  syntax error, unexpected '}' in /tmp/foo.php on line 7";
		$entries = LogParser::parse( $line );

		$this->assertCount( 1, $entries );
		$this->assertSame( Severity::PARSE, $entries[0]->severity );
		$this->assertSame( '/tmp/foo.php', $entries[0]->file );
		$this->assertSame( 7, $entries[0]->line );
	}

	public function test_parses_warning(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] PHP Warning:  Undefined array key "foo" in /tmp/bar.php on line 100';
		$entries = LogParser::parse( $line );

		$this->assertSame( Severity::WARNING, $entries[0]->severity );
	}

	public function test_parses_notice(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] PHP Notice:  Undefined variable $bar in /tmp/baz.php on line 5';
		$entries = LogParser::parse( $line );

		$this->assertSame( Severity::NOTICE, $entries[0]->severity );
	}

	public function test_parses_deprecated(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] PHP Deprecated:  Function strftime() is deprecated in /tmp/qux.php on line 1';
		$entries = LogParser::parse( $line );

		$this->assertSame( Severity::DEPRECATED, $entries[0]->severity );
	}

	public function test_parses_strict_standards(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] PHP Strict Standards: foo in /tmp/q.php on line 1';
		$entries = LogParser::parse( $line );

		$this->assertSame( Severity::STRICT, $entries[0]->severity );
	}

	public function test_timestamp_without_timezone(): void {
		$line    = '[27-Apr-2026 12:34:56] PHP Warning:  oops in /tmp/x.php on line 1';
		$entries = LogParser::parse( $line );

		$this->assertCount( 1, $entries );
		$this->assertSame( '27-Apr-2026 12:34:56', $entries[0]->timestamp );
		$this->assertNull( $entries[0]->timezone );
	}

	public function test_unsevered_error_log_call_is_classified_as_unknown(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] just a string from error_log()';
		$entries = LogParser::parse( $line );

		$this->assertCount( 1, $entries );
		$this->assertSame( Severity::UNKNOWN, $entries[0]->severity );
		$this->assertSame( 'just a string from error_log()', $entries[0]->message );
		$this->assertNull( $entries[0]->file );
		$this->assertNull( $entries[0]->line );
	}

	public function test_multi_line_fatal_attaches_stack_trace_to_previous_entry(): void {
		$chunk = <<<'LOG'
[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  Uncaught Error: boom in /var/www/main.php:42
Stack trace:
#0 /var/www/include.php(106): include()
#1 /var/www/header.php(19): require_once('/var/www/inc...')
#2 {main}
  thrown in /var/www/main.php on line 42
LOG;

		$entries = LogParser::parse( $chunk );

		$this->assertCount( 1, $entries );
		$this->assertSame( Severity::FATAL, $entries[0]->severity );
		$this->assertStringContainsString( 'Stack trace:', $entries[0]->raw );
		$this->assertStringContainsString( '#0 /var/www/include.php(106)', $entries[0]->raw );
		$this->assertStringContainsString( 'thrown in /var/www/main.php on line 42', $entries[0]->raw );
		$this->assertSame( '/var/www/main.php', $entries[0]->file );
		$this->assertSame( 42, $entries[0]->line );
	}

	public function test_multiple_entries_separated_by_continuation_blocks(): void {
		$chunk = <<<'LOG'
[27-Apr-2026 12:34:56 UTC] PHP Notice:  one in /tmp/a.php on line 1
[27-Apr-2026 12:34:57 UTC] PHP Warning:  two in /tmp/b.php on line 2
Stack trace:
#0 /tmp/b.php(2): foo()
[27-Apr-2026 12:34:58 UTC] PHP Fatal error:  three in /tmp/c.php:3
LOG;

		$entries = LogParser::parse( $chunk );

		$this->assertCount( 3, $entries );
		$this->assertSame( Severity::NOTICE, $entries[0]->severity );
		$this->assertSame( Severity::WARNING, $entries[1]->severity );
		$this->assertSame( Severity::FATAL, $entries[2]->severity );

		// The stack-trace continuation belongs to entry 2, not 3.
		$this->assertStringContainsString( '#0 /tmp/b.php(2)', $entries[1]->raw );
		$this->assertStringNotContainsString( '#0 /tmp/b.php(2)', $entries[2]->raw );
	}

	public function test_orphan_continuation_at_chunk_start_is_dropped(): void {
		// A chunk that begins mid-entry (split mid-stack-trace from the
		// previous chunk). The orphan rows have no leading entry to attach
		// to and are dropped — chunk stitching is the repository's job.
		$chunk = <<<'LOG'
#0 /var/www/leftover.php(5): foo()
  thrown in /var/www/leftover.php on line 5
[27-Apr-2026 12:35:00 UTC] PHP Warning:  fresh in /tmp/x.php on line 1
LOG;

		$entries = LogParser::parse( $chunk );

		$this->assertCount( 1, $entries );
		$this->assertSame( Severity::WARNING, $entries[0]->severity );
		$this->assertStringNotContainsString( 'leftover', $entries[0]->raw );
	}

	public function test_truncated_chunk_keeps_partial_continuation_with_last_entry(): void {
		// A chunk that ends mid-stack-trace. The last entry retains the
		// partial trace; the repository will be responsible for ordering
		// chunks and continuing the trace if the next chunk extends it.
		$chunk = <<<'LOG'
[27-Apr-2026 12:35:00 UTC] PHP Fatal error:  boom in /tmp/x.php:1
Stack trace:
#0 /tmp/x.php(1): f()
#1 /tmp/y.p
LOG;

		$entries = LogParser::parse( $chunk );

		$this->assertCount( 1, $entries );
		$this->assertSame( Severity::FATAL, $entries[0]->severity );
		$this->assertStringContainsString( '#1 /tmp/y.p', $entries[0]->raw );
	}

	public function test_handles_crlf_line_endings(): void {
		$chunk = "[27-Apr-2026 12:34:56 UTC] PHP Notice:  one in /tmp/a.php on line 1\r\n"
			. "[27-Apr-2026 12:34:57 UTC] PHP Warning:  two in /tmp/b.php on line 2\r\n";

		$entries = LogParser::parse( $chunk );

		$this->assertCount( 2, $entries );
		$this->assertSame( Severity::NOTICE, $entries[0]->severity );
		$this->assertSame( Severity::WARNING, $entries[1]->severity );
	}

	public function test_file_with_colon_line_form(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  boom in /var/www/main.php:99';
		$entries = LogParser::parse( $line );

		$this->assertSame( '/var/www/main.php', $entries[0]->file );
		$this->assertSame( 99, $entries[0]->line );
	}

	public function test_message_without_in_clause_has_null_file_and_line(): void {
		$line    = '[27-Apr-2026 12:34:56 UTC] PHP Warning:  generic warning with no path';
		$entries = LogParser::parse( $line );

		$this->assertNull( $entries[0]->file );
		$this->assertNull( $entries[0]->line );
	}

	public function test_severity_token_without_php_prefix(): void {
		// Some error_log() callers prepend the severity manually without
		// the "PHP " token. Recognise the severity anyway.
		$line    = '[27-Apr-2026 12:34:56 UTC] Warning:  manual in /tmp/m.php on line 1';
		$entries = LogParser::parse( $line );

		$this->assertSame( Severity::WARNING, $entries[0]->severity );
	}
}
