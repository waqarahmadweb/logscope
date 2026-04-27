<?php
/**
 * Unit tests for SourceClassifier.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Log;

use Logscope\Log\SourceClassifier;
use Logscope\Tests\TestCase;

final class SourceClassifierTest extends TestCase {

	public function test_null_input_returns_null(): void {
		$this->assertNull( SourceClassifier::classify( null ) );
	}

	public function test_empty_string_returns_null(): void {
		$this->assertNull( SourceClassifier::classify( '' ) );
	}

	public function test_classifies_plugin_path(): void {
		$this->assertSame(
			'plugins/akismet',
			SourceClassifier::classify( '/var/www/wp-content/plugins/akismet/akismet.php' )
		);
	}

	public function test_classifies_theme_path(): void {
		$this->assertSame(
			'themes/twentytwentyfour',
			SourceClassifier::classify( '/var/www/wp-content/themes/twentytwentyfour/functions.php' )
		);
	}

	public function test_classifies_mu_plugin_path(): void {
		$this->assertSame(
			'mu-plugins/object-cache',
			SourceClassifier::classify( '/var/www/wp-content/mu-plugins/object-cache/loader.php' )
		);
	}

	public function test_mu_plugin_match_takes_precedence_over_plugin(): void {
		// `mu-plugins` literally contains `plugins` — without ordering
		// or specificity, the wrong rule could win. Lock the behaviour.
		$this->assertSame(
			'mu-plugins/foo',
			SourceClassifier::classify( '/var/www/wp-content/mu-plugins/foo/main.php' )
		);
	}

	public function test_classifies_wp_includes_as_core(): void {
		$this->assertSame(
			SourceClassifier::CORE,
			SourceClassifier::classify( '/var/www/wp-includes/template-loader.php' )
		);
	}

	public function test_classifies_wp_admin_as_core(): void {
		$this->assertSame(
			SourceClassifier::CORE,
			SourceClassifier::classify( '/var/www/wp-admin/menu.php' )
		);
	}

	public function test_returns_null_for_unrecognised_path(): void {
		$this->assertNull( SourceClassifier::classify( '/tmp/random.php' ) );
	}

	public function test_handles_windows_separators(): void {
		$this->assertSame(
			'plugins/akismet',
			SourceClassifier::classify( 'C:\\xampp\\htdocs\\wp-content\\plugins\\akismet\\akismet.php' )
		);
	}
}
