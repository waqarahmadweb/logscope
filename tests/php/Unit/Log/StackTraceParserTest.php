<?php
/**
 * Unit tests for StackTraceParser.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Log;

use Logscope\Log\StackTraceParser;
use Logscope\Tests\TestCase;

final class StackTraceParserTest extends TestCase {

	public function test_empty_input_returns_empty_array(): void {
		$this->assertSame( array(), StackTraceParser::parse( '' ) );
	}

	public function test_parses_single_bare_function_frame(): void {
		$frames = StackTraceParser::parse( '#0 /var/www/include.php(106): include()' );

		$this->assertCount( 1, $frames );
		$this->assertSame( '/var/www/include.php', $frames[0]->file );
		$this->assertSame( 106, $frames[0]->line );
		$this->assertNull( $frames[0]->class );
		$this->assertSame( 'include', $frames[0]->method );
		$this->assertSame( '', $frames[0]->args );
	}

	public function test_parses_instance_method_call(): void {
		$frames = StackTraceParser::parse( '#0 /var/www/x.php(42): MyClass->myMethod()' );

		$this->assertSame( 'MyClass', $frames[0]->class );
		$this->assertSame( 'myMethod', $frames[0]->method );
	}

	public function test_parses_static_method_call(): void {
		$frames = StackTraceParser::parse( '#0 /var/www/x.php(42): MyClass::staticMethod()' );

		$this->assertSame( 'MyClass', $frames[0]->class );
		$this->assertSame( 'staticMethod', $frames[0]->method );
	}

	public function test_parses_namespaced_class(): void {
		$frames = StackTraceParser::parse( '#0 /var/www/x.php(42): Logscope\\Log\\LogParser::parse()' );

		$this->assertSame( 'Logscope\\Log\\LogParser', $frames[0]->class );
		$this->assertSame( 'parse', $frames[0]->method );
	}

	public function test_captures_args_as_raw_string_without_evaluation(): void {
		$frames = StackTraceParser::parse( "#0 /var/www/x.php(42): MyClass->method('arg', 42, Array)" );

		$this->assertSame( "'arg', 42, Array", $frames[0]->args );
	}

	public function test_captures_object_placeholder_in_args(): void {
		$frames = StackTraceParser::parse( '#0 /var/www/x.php(42): MyClass->method(Object(Foo\\Bar))' );

		$this->assertSame( 'Object(Foo\\Bar)', $frames[0]->args );
	}

	public function test_parses_internal_function_frame(): void {
		$frames = StackTraceParser::parse( '#0 [internal function]: MyClass->callback(Array)' );

		$this->assertCount( 1, $frames );
		$this->assertNull( $frames[0]->file );
		$this->assertNull( $frames[0]->line );
		$this->assertSame( 'MyClass', $frames[0]->class );
		$this->assertSame( 'callback', $frames[0]->method );
		$this->assertSame( 'Array', $frames[0]->args );
	}

	public function test_parses_main_terminator(): void {
		$frames = StackTraceParser::parse( '#5 {main}' );

		$this->assertCount( 1, $frames );
		$this->assertNull( $frames[0]->file );
		$this->assertNull( $frames[0]->line );
		$this->assertNull( $frames[0]->class );
		$this->assertNull( $frames[0]->method );
		$this->assertNull( $frames[0]->args );
		$this->assertSame( '#5 {main}', $frames[0]->raw );
	}

	public function test_parses_real_fatal_trace_in_order(): void {
		$trace = <<<'LOG'
[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  Uncaught Error: boom in /var/www/main.php:42
Stack trace:
#0 /var/www/wp-includes/template-loader.php(106): include()
#1 /var/www/wp-blog-header.php(19): require_once('/var/www/wp-inc...')
#2 [internal function]: WP_Hook->apply_filters('', Array)
#3 /var/www/wp-includes/plugin.php(517): WP_Hook->do_action(Array)
#4 {main}
  thrown in /var/www/main.php on line 42
LOG;

		$frames = StackTraceParser::parse( $trace );

		$this->assertCount( 5, $frames );

		$this->assertSame( '/var/www/wp-includes/template-loader.php', $frames[0]->file );
		$this->assertSame( 106, $frames[0]->line );
		$this->assertSame( 'include', $frames[0]->method );

		$this->assertSame( 'require_once', $frames[1]->method );
		$this->assertSame( "'/var/www/wp-inc...'", $frames[1]->args );

		$this->assertNull( $frames[2]->file );
		$this->assertSame( 'WP_Hook', $frames[2]->class );
		$this->assertSame( 'apply_filters', $frames[2]->method );

		$this->assertSame( 'WP_Hook', $frames[3]->class );
		$this->assertSame( 'do_action', $frames[3]->method );

		$this->assertNull( $frames[4]->file );
		$this->assertNull( $frames[4]->method );
	}

	public function test_skips_non_frame_lines(): void {
		$text = "Stack trace:\n#0 /var/www/x.php(1): foo()\n  thrown in /var/www/x.php on line 1";

		$frames = StackTraceParser::parse( $text );

		$this->assertCount( 1, $frames );
		$this->assertSame( 'foo', $frames[0]->method );
	}

	public function test_preserves_frame_order(): void {
		$text   = "#2 /a.php(1): c()\n#0 /a.php(1): a()\n#1 /a.php(1): b()";
		$frames = StackTraceParser::parse( $text );

		$this->assertSame( 'c', $frames[0]->method );
		$this->assertSame( 'a', $frames[1]->method );
		$this->assertSame( 'b', $frames[2]->method );
	}

	public function test_handles_windows_path_with_parens_inside(): void {
		$frames = StackTraceParser::parse( '#0 C:\\Program Files (x86)\\app\\main.php(42): foo()' );

		$this->assertCount( 1, $frames );
		$this->assertSame( 'C:\\Program Files (x86)\\app\\main.php', $frames[0]->file );
		$this->assertSame( 42, $frames[0]->line );
		$this->assertSame( 'foo', $frames[0]->method );
	}

	public function test_raw_field_preserves_full_line(): void {
		$line   = '#0 /var/www/x.php(42): MyClass->myMethod()';
		$frames = StackTraceParser::parse( $line );

		$this->assertSame( $line, $frames[0]->raw );
	}
}
