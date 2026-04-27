<?php
/**
 * Unit tests for SettingsSchema.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Settings;

use InvalidArgumentException;
use Logscope\Settings\SettingsSchema;
use Logscope\Tests\TestCase;

final class SettingsSchemaTest extends TestCase {

	public function test_keys_returns_declared_field_names(): void {
		$schema = new SettingsSchema();

		$this->assertSame( array( 'log_path', 'tail_interval' ), $schema->keys() );
	}

	public function test_has_returns_true_for_known_and_false_for_unknown(): void {
		$schema = new SettingsSchema();

		$this->assertTrue( $schema->has( 'log_path' ) );
		$this->assertFalse( $schema->has( 'nope' ) );
	}

	public function test_field_throws_for_unknown_key(): void {
		$schema = new SettingsSchema();

		$this->expectException( InvalidArgumentException::class );
		$schema->field( 'nope' );
	}

	public function test_option_key_returns_underlying_wp_option_name(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 'logscope_log_path', $schema->option_key( 'log_path' ) );
		$this->assertSame( 'logscope_tail_interval', $schema->option_key( 'tail_interval' ) );
	}

	public function test_default_for_returns_declared_defaults(): void {
		$schema = new SettingsSchema();

		$this->assertSame( '', $schema->default_for( 'log_path' ) );
		$this->assertSame( 3, $schema->default_for( 'tail_interval' ) );
	}

	public function test_log_path_sanitizer_trims_whitespace_and_strips_null_bytes(): void {
		$schema = new SettingsSchema();

		$this->assertSame( '/var/log/debug.log', $schema->sanitize( 'log_path', '  /var/log/debug.log  ' ) );
		$this->assertSame( '/var/log/debug.log', $schema->sanitize( 'log_path', "/var/log/debug.log\0" ) );
	}

	public function test_log_path_sanitizer_returns_empty_string_for_non_string(): void {
		$schema = new SettingsSchema();

		$this->assertSame( '', $schema->sanitize( 'log_path', 42 ) );
		$this->assertSame( '', $schema->sanitize( 'log_path', null ) );
		$this->assertSame( '', $schema->sanitize( 'log_path', array( 'x' ) ) );
	}

	public function test_tail_interval_sanitizer_coerces_zero_to_one(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'tail_interval', 0 ) );
	}

	public function test_tail_interval_sanitizer_coerces_negative_to_one(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'tail_interval', -7 ) );
	}

	public function test_tail_interval_sanitizer_accepts_positive_int(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 5, $schema->sanitize( 'tail_interval', 5 ) );
	}

	public function test_tail_interval_sanitizer_parses_numeric_string(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 10, $schema->sanitize( 'tail_interval', '10' ) );
	}

	public function test_tail_interval_sanitizer_falls_back_for_non_numeric(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'tail_interval', 'abc' ) );
		$this->assertSame( 1, $schema->sanitize( 'tail_interval', null ) );
		$this->assertSame( 1, $schema->sanitize( 'tail_interval', array( 1 ) ) );
	}

	public function test_sanitize_throws_for_unknown_key(): void {
		$schema = new SettingsSchema();

		$this->expectException( InvalidArgumentException::class );
		$schema->sanitize( 'nope', 'whatever' );
	}

	public function test_matches_type_string(): void {
		$schema = new SettingsSchema();

		$this->assertTrue( $schema->matches_type( 'log_path', '' ) );
		$this->assertFalse( $schema->matches_type( 'log_path', 0 ) );
		$this->assertFalse( $schema->matches_type( 'log_path', null ) );
	}

	public function test_matches_type_integer(): void {
		$schema = new SettingsSchema();

		$this->assertTrue( $schema->matches_type( 'tail_interval', 5 ) );
		$this->assertFalse( $schema->matches_type( 'tail_interval', '5' ) );
		$this->assertFalse( $schema->matches_type( 'tail_interval', null ) );
	}
}
