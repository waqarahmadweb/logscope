<?php
/**
 * Unit tests for SettingsSchema.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use Logscope\Settings\SettingsSchema;
use Logscope\Tests\TestCase;

final class SettingsSchemaTest extends TestCase {

	public function test_keys_returns_declared_field_names(): void {
		$schema = new SettingsSchema();

		$this->assertSame(
			array(
				'log_path',
				'tail_interval',
				'alert_email_enabled',
				'alert_email_to',
				'alert_webhook_enabled',
				'alert_webhook_url',
				'alert_dedup_window',
				'cron_scan_enabled',
				'cron_scan_interval_minutes',
				'retention_enabled',
				'retention_max_size_mb',
				'retention_max_archives',
			),
			$schema->keys()
		);
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

	public function test_tail_interval_sanitizer_truncates_float_string(): void {
		$schema = new SettingsSchema();

		// is_numeric( '3.5' ) is true; the (int) cast truncates to 3
		// rather than falling back to the default 1.
		$this->assertSame( 3, $schema->sanitize( 'tail_interval', '3.5' ) );
	}

	public function test_tail_interval_sanitizer_truncates_float(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 4, $schema->sanitize( 'tail_interval', 4.9 ) );
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
		// Numeric strings pass: get_option() returns LONGTEXT-stored ints
		// as strings, and Settings::get() casts them back. Rejecting them
		// here would silently revert every integer setting on reload.
		$this->assertTrue( $schema->matches_type( 'tail_interval', '5' ) );
		$this->assertTrue( $schema->matches_type( 'tail_interval', '0' ) );
		$this->assertFalse( $schema->matches_type( 'tail_interval', '5.5' ) );
		$this->assertFalse( $schema->matches_type( 'tail_interval', 'oops' ) );
		$this->assertFalse( $schema->matches_type( 'tail_interval', '' ) );
		$this->assertFalse( $schema->matches_type( 'tail_interval', null ) );
	}

	public function test_alert_email_enabled_coerces_truthy_inputs(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'alert_email_enabled', true ) );
		$this->assertSame( 1, $schema->sanitize( 'alert_email_enabled', '1' ) );
		$this->assertSame( 1, $schema->sanitize( 'alert_email_enabled', 'true' ) );
		$this->assertSame( 1, $schema->sanitize( 'alert_email_enabled', 'on' ) );
		$this->assertSame( 0, $schema->sanitize( 'alert_email_enabled', false ) );
		$this->assertSame( 0, $schema->sanitize( 'alert_email_enabled', '' ) );
		$this->assertSame( 0, $schema->sanitize( 'alert_email_enabled', null ) );
	}

	public function test_alert_email_to_runs_through_sanitize_email(): void {
		Functions\when( 'sanitize_email' )->alias(
			static function ( $value ) {
				return false === strpos( $value, '@' ) ? '' : $value;
			}
		);

		$schema = new SettingsSchema();

		$this->assertSame( 'ops@example.com', $schema->sanitize( 'alert_email_to', '  ops@example.com  ' ) );
		$this->assertSame( '', $schema->sanitize( 'alert_email_to', 'not-an-email' ) );
		$this->assertSame( '', $schema->sanitize( 'alert_email_to', '' ) );
	}

	public function test_alert_webhook_url_enforces_http_https_scheme(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				if ( 0 === strpos( $url, 'http://' ) ) {
					return 'http';
				}
				if ( 0 === strpos( $url, 'https://' ) ) {
					return 'https';
				}
				if ( 0 === strpos( $url, 'file://' ) ) {
					return 'file';
				}

				return null;
			}
		);

		$schema = new SettingsSchema();

		$this->assertSame( 'https://example.com/hook', $schema->sanitize( 'alert_webhook_url', 'https://example.com/hook' ) );
		$this->assertSame( 'http://example.com/hook', $schema->sanitize( 'alert_webhook_url', 'http://example.com/hook' ) );
		$this->assertSame( '', $schema->sanitize( 'alert_webhook_url', 'file:///etc/passwd' ) );
		$this->assertSame( '', $schema->sanitize( 'alert_webhook_url', '' ) );
	}

	public function test_alert_dedup_window_floors_at_60_seconds(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 60, $schema->sanitize( 'alert_dedup_window', 5 ) );
		$this->assertSame( 60, $schema->sanitize( 'alert_dedup_window', '0' ) );
		$this->assertSame( 600, $schema->sanitize( 'alert_dedup_window', 600 ) );
		$this->assertSame( 300, $schema->sanitize( 'alert_dedup_window', 'oops' ) );
	}

	public function test_cron_scan_enabled_coerces_truthy_inputs(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'cron_scan_enabled', true ) );
		$this->assertSame( 1, $schema->sanitize( 'cron_scan_enabled', '1' ) );
		$this->assertSame( 1, $schema->sanitize( 'cron_scan_enabled', 'on' ) );
		$this->assertSame( 0, $schema->sanitize( 'cron_scan_enabled', false ) );
		$this->assertSame( 0, $schema->sanitize( 'cron_scan_enabled', null ) );
	}

	public function test_cron_scan_interval_minutes_clamps_to_supported_range(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'cron_scan_interval_minutes', 0 ) );
		$this->assertSame( 1, $schema->sanitize( 'cron_scan_interval_minutes', -42 ) );
		$this->assertSame( 5, $schema->sanitize( 'cron_scan_interval_minutes', 5 ) );
		$this->assertSame( 1440, $schema->sanitize( 'cron_scan_interval_minutes', 9999 ) );
		$this->assertSame( 5, $schema->sanitize( 'cron_scan_interval_minutes', 'oops' ) );
	}

	public function test_retention_enabled_coerces_truthy_inputs(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'retention_enabled', true ) );
		$this->assertSame( 1, $schema->sanitize( 'retention_enabled', '1' ) );
		$this->assertSame( 1, $schema->sanitize( 'retention_enabled', 'on' ) );
		$this->assertSame( 0, $schema->sanitize( 'retention_enabled', false ) );
		$this->assertSame( 0, $schema->sanitize( 'retention_enabled', null ) );
	}

	public function test_retention_max_size_mb_clamps_to_supported_range(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'retention_max_size_mb', 0 ) );
		$this->assertSame( 1, $schema->sanitize( 'retention_max_size_mb', -10 ) );
		$this->assertSame( 50, $schema->sanitize( 'retention_max_size_mb', 50 ) );
		$this->assertSame( 1024, $schema->sanitize( 'retention_max_size_mb', 99999 ) );
		$this->assertSame( 50, $schema->sanitize( 'retention_max_size_mb', 'oops' ) );
	}

	public function test_retention_max_archives_clamps_to_supported_range(): void {
		$schema = new SettingsSchema();

		$this->assertSame( 1, $schema->sanitize( 'retention_max_archives', 0 ) );
		$this->assertSame( 1, $schema->sanitize( 'retention_max_archives', -3 ) );
		$this->assertSame( 5, $schema->sanitize( 'retention_max_archives', 5 ) );
		$this->assertSame( 50, $schema->sanitize( 'retention_max_archives', 9999 ) );
		$this->assertSame( 5, $schema->sanitize( 'retention_max_archives', 'oops' ) );
	}
}
