<?php
/**
 * End-to-end integration of the cron action -> LogScanner -> AlertCoordinator
 * pipeline. Drives the scanner via do_action('logscope_scan_fatals') the
 * same way WP-Cron would, and asserts the alert pipeline observes the
 * groups the scanner extracts from a fixture log.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tmp fixture filesystem access is intentional here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Integration\Cron;

use Brain\Monkey\Functions;
use Logscope\Alerts\AlertCoordinator;
use Logscope\Alerts\AlertDeduplicator;
use Logscope\Alerts\AlertDispatcherInterface;
use Logscope\Cron\LogScanner;
use Logscope\Log\FileLogSource;
use Logscope\Log\Group;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;

/**
 * Closes the loop the unit tests leave open: the unit suite isolates the
 * scanner from the coordinator with a mock; this test wires a real
 * AlertCoordinator, registers the action listener the way `Plugin`
 * registers it at boot, and triggers the scan via `do_action()` so a
 * regression that disconnects the action callback shows up as a
 * silently-zero alert count rather than passing through unit tests.
 */
final class LogScannerIntegrationTest extends TestCase {

	private string $root;

	private string $log_path;

	private PathGuard $guard;

	/**
	 * Mutable single-element container holding the captured groups
	 * list. Wrapped in an object so the dispatcher can append from
	 * inside its `dispatch()` and the test can read the result without
	 * relying on PHP property-by-reference semantics.
	 *
	 * @var \stdClass
	 */
	private \stdClass $capture;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-cron-int-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root     = $resolved;
		$this->log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';
		$this->guard    = new PathGuard( array( $this->root ) );
		$this->capture  = (object) array( 'groups' => array() );

		$this->stub_options();
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_do_action_drives_scanner_and_dispatches_two_groups(): void {
		file_put_contents(
			$this->log_path,
			"[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  Uncaught Error: boom in /var/www/a.php:1\n"
			. "[27-Apr-2026 12:34:57 UTC] PHP Fatal error:  Uncaught Error: bang in /var/www/b.php:2\n"
		);

		$scanner = $this->build_scanner();

		// Mirrors Plugin::register_hooks() — the cron callback is the
		// scanner's scan() method bound to the action. A regression that
		// disconnects that wiring would surface here as a no-op.
		add_action(
			'logscope_scan_fatals',
			static function () use ( $scanner ): void {
				$scanner->scan();
			}
		);

		do_action( 'logscope_scan_fatals' );

		$this->assertCount( 2, $this->capture->groups );
		$this->assertSame( filesize( $this->log_path ), get_option( LogScanner::OPT_LAST_BYTE, 0 ) );
		$this->assertSame( 2, get_option( LogScanner::OPT_LAST_DISPATCHED, 0 ) );
	}

	public function test_second_invocation_is_a_noop_when_no_new_bytes(): void {
		file_put_contents(
			$this->log_path,
			"[27-Apr-2026 12:34:56 UTC] PHP Fatal error:  boom in /var/www/a.php:1\n"
		);

		$scanner = $this->build_scanner();
		add_action(
			'logscope_scan_fatals',
			static function () use ( $scanner ): void {
				$scanner->scan();
			}
		);

		do_action( 'logscope_scan_fatals' );
		$this->assertCount( 1, $this->capture->groups );

		// Second tick with no appended bytes must not re-dispatch.
		do_action( 'logscope_scan_fatals' );
		$this->assertCount( 1, $this->capture->groups );
		$this->assertSame( 0, get_option( LogScanner::OPT_LAST_DISPATCHED, -1 ) );
	}

	private function build_scanner(): LogScanner {
		$source      = new FileLogSource( $this->log_path, $this->guard );
		$dispatcher  = $this->capturing_dispatcher();
		$dedup       = new AlertDeduplicator( 60 );
		$coordinator = new AlertCoordinator( array( $dispatcher ), $dedup );

		return new LogScanner( $source, $coordinator );
	}

	private function capturing_dispatcher(): AlertDispatcherInterface {
		return new class( $this->capture ) implements AlertDispatcherInterface {
			/**
			 * Shared mutable container — the test reads `$capture->groups`
			 * after the action fires.
			 *
			 * @var \stdClass
			 */
			private \stdClass $capture;

			public function __construct( \stdClass $capture ) {
				$this->capture = $capture;
			}

			public function name(): string {
				return 'capture';
			}

			public function is_enabled(): bool {
				return true;
			}

			public function dispatch( Group $group ): bool {
				$this->capture->groups[] = $group;
				return true;
			}
		};
	}

	/**
	 * In-memory option store backing the scanner's cursor + timestamp +
	 * dispatched-count writes. Brain Monkey aliases route every
	 * get_option / update_option call through it.
	 */
	private function stub_options(): void {
		$store = array();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $fallback;
			}
		);

		Functions\when( 'update_option' )->alias(
			static function ( string $key, $value ) use ( &$store ): bool {
				$store[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$this->stub_action_dispatch();
	}

	/**
	 * Brain Monkey records `add_action` / `do_action` calls but does not
	 * actually invoke registered callbacks — the harness is geared for
	 * unit-level assertions. To exercise the producer-consumer wiring
	 * end-to-end ("do_action drives the scanner"), we need real-fire
	 * semantics. Aliasing `add_action` + `do_action` onto a per-test
	 * callback registry gives us that without depending on the full WP
	 * test scaffold. Scoped to this integration test so other tests'
	 * action expectations are not affected.
	 */
	private function stub_action_dispatch(): void {
		$registry = array();

		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$registry ): bool {
				$registry[ $hook ][] = $callback;
				return true;
			}
		);

		Functions\when( 'do_action' )->alias(
			static function ( string $hook, ...$args ) use ( &$registry ): void {
				if ( ! isset( $registry[ $hook ] ) ) {
					return;
				}
				foreach ( $registry[ $hook ] as $callback ) {
					$callback( ...$args );
				}
			}
		);
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
