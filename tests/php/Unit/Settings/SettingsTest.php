<?php
/**
 * Unit tests for the Settings facade.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use Logscope\Settings\Settings;
use Logscope\Settings\SettingsSchema;
use Logscope\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function test_get_returns_stored_value_when_type_matches(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_tail_interval', 3 )
			->andReturn( 5 );

		$settings = new Settings( new SettingsSchema() );

		$this->assertSame( 5, $settings->get( 'tail_interval' ) );
	}

	public function test_get_coerces_numeric_string_for_integer_field(): void {
		// wp_options.option_value is LONGTEXT, so a value written as int 5
		// comes back from get_option() as the string "5". Without coercion
		// the type check would reject it and the field would silently revert
		// to the schema default on every reload.
		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_tail_interval', 3 )
			->andReturn( '5' );

		$settings = new Settings( new SettingsSchema() );

		$this->assertSame( 5, $settings->get( 'tail_interval' ) );
	}

	public function test_get_returns_default_when_stored_value_is_wrong_type(): void {
		// Older versions of the plugin may have stored a non-numeric string.
		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_tail_interval', 3 )
			->andReturn( 'oops' );

		$settings = new Settings( new SettingsSchema() );

		$this->assertSame( 3, $settings->get( 'tail_interval' ) );
	}

	public function test_get_returns_default_when_option_missing(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_log_path', '' )
			->andReturn( '' );

		$settings = new Settings( new SettingsSchema() );

		$this->assertSame( '', $settings->get( 'log_path' ) );
	}

	public function test_get_throws_for_unknown_key(): void {
		$settings = new Settings( new SettingsSchema() );

		$this->expectException( InvalidArgumentException::class );
		$settings->get( 'nope' );
	}

	public function test_set_sanitizes_before_persisting_and_returns_sanitized_value(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( 'logscope_tail_interval', 1 )
			->andReturn( true );

		$settings = new Settings( new SettingsSchema() );

		$this->assertSame( 1, $settings->set( 'tail_interval', 0 ) );
	}

	public function test_set_strips_null_bytes_from_log_path(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( 'logscope_log_path', '/var/log/debug.log' )
			->andReturn( true );

		$settings = new Settings( new SettingsSchema() );

		$this->assertSame(
			'/var/log/debug.log',
			$settings->set( 'log_path', "  /var/log/debug.log\0  " )
		);
	}

	public function test_set_throws_for_unknown_key(): void {
		$settings = new Settings( new SettingsSchema() );

		$this->expectException( InvalidArgumentException::class );
		$settings->set( 'nope', 'whatever' );
	}

	public function test_all_returns_full_settings_shape(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_log_path', '' )
			->andReturn( '/var/log/debug.log' );

		// Mirror real WP behaviour: integer-typed options come back from
		// get_option() as numeric strings because wp_options.option_value
		// is LONGTEXT. Settings::get() coerces them back to int.
		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_tail_interval', 3 )
			->andReturn( '7' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_alert_email_enabled', 0 )
			->andReturn( '0' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_alert_email_to', '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_alert_webhook_enabled', 0 )
			->andReturn( 0 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_alert_webhook_url', '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_alert_dedup_window', 1800 )
			->andReturn( 1800 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_cron_scan_enabled', 0 )
			->andReturn( 0 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_cron_scan_interval_minutes', 5 )
			->andReturn( 5 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_retention_enabled', 0 )
			->andReturn( 0 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_retention_max_size_mb', 50 )
			->andReturn( 50 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_retention_max_archives', 5 )
			->andReturn( 5 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_default_per_page', 50 )
			->andReturn( 50 );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_default_severity_filter', '' )
			->andReturn( '' );

		Functions\expect( 'get_option' )
			->once()
			->with( 'logscope_timestamp_tz', 'site' )
			->andReturn( 'site' );

		$settings = new Settings( new SettingsSchema() );

		$this->assertSame(
			array(
				'log_path'                   => '/var/log/debug.log',
				'tail_interval'              => 7,
				'alert_email_enabled'        => 0,
				'alert_email_to'             => '',
				'alert_webhook_enabled'      => 0,
				'alert_webhook_url'          => '',
				'alert_dedup_window'         => 1800,
				'cron_scan_enabled'          => 0,
				'cron_scan_interval_minutes' => 5,
				'retention_enabled'          => 0,
				'retention_max_size_mb'      => 50,
				'default_per_page'           => 50,
				'default_severity_filter'    => '',
				'timestamp_tz'               => 'site',
				'retention_max_archives'     => 5,
			),
			$settings->all()
		);
	}
}
