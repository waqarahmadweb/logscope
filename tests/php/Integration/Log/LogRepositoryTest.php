<?php
/**
 * Integration tests for LogRepository — end-to-end through a real
 * file source, parser, grouper, and pagination.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// Tests need raw filesystem access for tmp fixtures and best-effort cleanup;
// WP_Filesystem and structured error handling are inappropriate here.
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.PHP.NoSilencedErrors

namespace Logscope\Tests\Integration\Log;

use Brain\Monkey\Functions;
use Logscope\Log\Entry;
use Logscope\Log\FileLogSource;
use Logscope\Log\LogGrouper;
use Logscope\Log\LogParser;
use Logscope\Log\LogQuery;
use Logscope\Log\LogRepository;
use Logscope\Log\MuteStore;
use Logscope\Log\Severity;
use Logscope\Support\PathGuard;
use Logscope\Tests\TestCase;

final class LogRepositoryTest extends TestCase {

	private string $root;

	private string $log_path;

	private LogRepository $repo;

	protected function setUp(): void {
		parent::setUp();

		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logscope-repo-' . bin2hex( random_bytes( 6 ) );
		mkdir( $base, 0777, true );

		$resolved = realpath( $base );
		self::assertIsString( $resolved );

		$this->root     = $resolved;
		$this->log_path = $this->root . DIRECTORY_SEPARATOR . 'debug.log';

		$guard      = new PathGuard( array( $this->root ) );
		$source     = new FileLogSource( $this->log_path, $guard );
		$this->repo = new LogRepository( $source );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
		parent::tearDown();
	}

	public function test_missing_log_returns_empty_paged_result(): void {
		$query  = $this->query();
		$result = $this->repo->query( $query );

		$this->assertSame( array(), $result->items );
		$this->assertSame( 0, $result->total );
		$this->assertSame( 1, $result->total_pages );
	}

	public function test_returns_page_2_of_50_with_severity_fatal_filter(): void {
		// Acceptance criterion from ROADMAP step 3.6: integration test
		// reads a fixture log and returns page 2 of 50 with
		// severity=Fatal applied.
		$lines = array();
		// 75 fatals so they span two pages of 50 (page 1 = 50, page 2 = 25).
		for ( $i = 0; $i < 75; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 12:00:%02d UTC] PHP Fatal error:  boom %d in /var/www/main.php:%d',
				$i % 60,
				$i,
				$i + 1
			);
		}
		// Some warnings as background noise that the filter must drop.
		for ( $i = 0; $i < 30; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 13:00:%02d UTC] PHP Warning:  meh %d in /var/www/x.php on line %d',
				$i % 60,
				$i,
				$i
			);
		}
		$this->write_log( implode( "\n", $lines ) );

		$query = new LogQuery(
			array( Severity::FATAL ),
			null,
			null,
			null,
			null,
			false,
			2,
			50
		);

		$result = $this->repo->query( $query );

		$this->assertSame( 75, $result->total );
		$this->assertSame( 2, $result->page );
		$this->assertSame( 50, $result->per_page );
		$this->assertSame( 2, $result->total_pages );
		$this->assertCount( 25, $result->items );

		foreach ( $result->items as $item ) {
			self::assertInstanceOf( Entry::class, $item );
			$this->assertSame( Severity::FATAL, $item->severity );
		}
	}

	public function test_grouped_query_returns_groups_sorted_by_count(): void {
		$lines = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 12:00:%02d UTC] PHP Notice:  Cannot find post %d in /var/www/a.php on line 1',
				$i,
				$i + 1
			);
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$lines[] = sprintf(
				'[27-Apr-2026 13:00:%02d UTC] PHP Warning:  Undefined index "k" in /var/www/b.php on line 2',
				$i
			);
		}
		$this->write_log( implode( "\n", $lines ) );

		$query = new LogQuery( null, null, null, null, null, true, 1, 50 );

		$result = $this->repo->query( $query );

		$this->assertCount( 2, $result->items );
		$this->assertSame( 10, $result->items[0]->count );
		$this->assertSame( 3, $result->items[1]->count );
	}

	public function test_source_filter_matches_classified_path(): void {
		$lines = array(
			'[27-Apr-2026 12:00:00 UTC] PHP Notice:  one in /var/www/wp-content/plugins/akismet/main.php on line 1',
			'[27-Apr-2026 12:00:01 UTC] PHP Notice:  two in /var/www/wp-content/plugins/jetpack/main.php on line 1',
			'[27-Apr-2026 12:00:02 UTC] PHP Notice:  three in /var/www/wp-content/plugins/akismet/other.php on line 1',
		);
		$this->write_log( implode( "\n", $lines ) );

		$query = new LogQuery( null, null, null, null, 'plugins/akismet', false, 1, 50 );

		$result = $this->repo->query( $query );

		$this->assertSame( 2, $result->total );
		foreach ( $result->items as $item ) {
			$this->assertStringContainsString( '/plugins/akismet/', $item->file );
		}
	}

	public function test_regex_filter_matches_message(): void {
		$lines = array(
			'[27-Apr-2026 12:00:00 UTC] PHP Notice:  apple in /var/www/x.php on line 1',
			'[27-Apr-2026 12:00:01 UTC] PHP Notice:  banana in /var/www/x.php on line 1',
			'[27-Apr-2026 12:00:02 UTC] PHP Notice:  apricot in /var/www/x.php on line 1',
		);
		$this->write_log( implode( "\n", $lines ) );

		$query = new LogQuery( null, null, null, '^ap', null, false, 1, 50 );

		$result = $this->repo->query( $query );

		$this->assertSame( 2, $result->total );
	}

	public function test_date_range_filter_excludes_outside_window(): void {
		$lines = array(
			'[26-Apr-2026 12:00:00 UTC] PHP Notice:  early in /var/www/x.php on line 1',
			'[27-Apr-2026 12:00:00 UTC] PHP Notice:  middle in /var/www/x.php on line 1',
			'[28-Apr-2026 12:00:00 UTC] PHP Notice:  late in /var/www/x.php on line 1',
		);
		$this->write_log( implode( "\n", $lines ) );

		$query = new LogQuery( null, '2026-04-27', '2026-04-27 23:59:59', null, null, false, 1, 50 );

		$result = $this->repo->query( $query );

		$this->assertSame( 1, $result->total );
		$this->assertStringContainsString( 'middle', $result->items[0]->message );
	}

	public function test_distinct_sources_lists_unique_classified_slugs(): void {
		$lines = array(
			'[27-Apr-2026 12:00:00 UTC] PHP Notice:  a in /var/www/wp-content/plugins/akismet/x.php on line 1',
			'[27-Apr-2026 12:00:01 UTC] PHP Notice:  b in /var/www/wp-content/plugins/akismet/y.php on line 1',
			'[27-Apr-2026 12:00:02 UTC] PHP Notice:  c in /var/www/wp-content/themes/twentytwentyfour/x.php on line 1',
			'[27-Apr-2026 12:00:03 UTC] PHP Notice:  d in /var/www/wp-includes/template-loader.php on line 1',
		);
		$this->write_log( implode( "\n", $lines ) );

		$sources = $this->repo->distinct_sources();

		$this->assertSame(
			array( 'core', 'plugins/akismet', 'themes/twentytwentyfour' ),
			$sources
		);
	}

	public function test_tail_since_zero_returns_all_entries_with_last_byte(): void {
		$lines    = array(
			'[27-Apr-2026 12:00:00 UTC] PHP Notice:  one in /var/www/x.php on line 1',
			'[27-Apr-2026 12:00:01 UTC] PHP Notice:  two in /var/www/x.php on line 1',
		);
		$contents = implode( "\n", $lines ) . "\n";
		$this->write_log( $contents );

		$query  = new LogQuery( null, null, null, null, null, false, 1, 50, 0 );
		$result = $this->repo->query( $query );

		$this->assertCount( 2, $result->items );
		$this->assertSame( strlen( $contents ), $result->last_byte );
		$this->assertFalse( $result->rotated );
		// Tail mode skips the newest-first reversal and returns entries
		// in chronological (file) order so the client can prepend them.
		$this->assertStringContainsString( 'one', $result->items[0]->message );
		$this->assertStringContainsString( 'two', $result->items[1]->message );
	}

	public function test_tail_since_mid_file_returns_only_new_entries(): void {
		$first = "[27-Apr-2026 12:00:00 UTC] PHP Notice:  one in /var/www/x.php on line 1\n";
		$this->write_log( $first );
		$mid = strlen( $first );

		$second = "[27-Apr-2026 12:00:01 UTC] PHP Notice:  two in /var/www/x.php on line 1\n";
		file_put_contents( $this->log_path, $second, FILE_APPEND );

		$query  = new LogQuery( null, null, null, null, null, false, 1, 50, $mid );
		$result = $this->repo->query( $query );

		$this->assertCount( 1, $result->items );
		$this->assertStringContainsString( 'two', $result->items[0]->message );
		$this->assertSame( $mid + strlen( $second ), $result->last_byte );
		$this->assertFalse( $result->rotated );
	}

	public function test_tail_since_at_eof_returns_no_entries(): void {
		$contents = "[27-Apr-2026 12:00:00 UTC] PHP Notice:  one in /var/www/x.php on line 1\n";
		$this->write_log( $contents );
		$size = strlen( $contents );

		$query  = new LogQuery( null, null, null, null, null, false, 1, 50, $size );
		$result = $this->repo->query( $query );

		$this->assertSame( array(), $result->items );
		$this->assertSame( $size, $result->last_byte );
		$this->assertFalse( $result->rotated );
	}

	public function test_tail_since_past_eof_signals_rotation_and_returns_full_file(): void {
		// Simulate a log rotation: the caller's cursor is way past the
		// new file's EOF, so the repository should detect the file
		// shrunk, signal `rotated=true`, and return the whole new file.
		$rotated_contents = "[27-Apr-2026 13:00:00 UTC] PHP Notice:  fresh in /var/www/x.php on line 1\n";
		$this->write_log( $rotated_contents );

		$query  = new LogQuery( null, null, null, null, null, false, 1, 50, 50_000 );
		$result = $this->repo->query( $query );

		$this->assertTrue( $result->rotated );
		$this->assertCount( 1, $result->items );
		$this->assertStringContainsString( 'fresh', $result->items[0]->message );
		$this->assertSame( strlen( $rotated_contents ), $result->last_byte );
	}

	public function test_non_tail_response_carries_last_byte_for_both_list_and_grouped(): void {
		$contents = "[27-Apr-2026 12:00:00 UTC] PHP Notice:  one in /var/www/x.php on line 1\n";
		$this->write_log( $contents );
		$size = strlen( $contents );

		$list_result = $this->repo->query( $this->query() );
		$this->assertSame( $size, $list_result->last_byte );

		$grouped_query  = new LogQuery( null, null, null, null, null, true, 1, 50 );
		$grouped_result = $this->repo->query( $grouped_query );
		$this->assertSame( $size, $grouped_result->last_byte );
	}

	public function test_muted_signature_excluded_from_default_query(): void {
		$this->stub_option_layer();

		$contents = implode(
			"\n",
			array(
				'[27-Apr-2026 12:00:00 UTC] PHP Fatal error:  noisy thing in /var/www/x.php:10',
				'[27-Apr-2026 12:00:01 UTC] PHP Fatal error:  quiet thing in /var/www/y.php:11',
			)
		);
		$this->write_log( $contents );

		$noisy_signature = LogGrouper::signature( LogParser::parse( $contents )[0] );

		$store = new MuteStore();
		$store->add( $noisy_signature, 'noise', 1 );

		$repo = $this->repo_with_mute_store( $store );

		// Ungrouped: muted entries are dropped.
		$result = $repo->query( $this->query() );
		$this->assertCount( 1, $result->items );
		$this->assertStringContainsString( 'quiet', $result->items[0]->message );

		// Grouped: muted groups are dropped entirely.
		$grouped = $repo->query( new LogQuery( null, null, null, null, null, true, 1, 50 ) );
		$this->assertCount( 1, $grouped->items );
		$this->assertNotSame( $noisy_signature, $grouped->items[0]->signature );
	}

	public function test_include_muted_flag_bypasses_filter(): void {
		$this->stub_option_layer();

		$contents = implode(
			"\n",
			array(
				'[27-Apr-2026 12:00:00 UTC] PHP Fatal error:  noisy thing in /var/www/x.php:10',
				'[27-Apr-2026 12:00:01 UTC] PHP Fatal error:  quiet thing in /var/www/y.php:11',
			)
		);
		$this->write_log( $contents );

		$noisy_signature = LogGrouper::signature( LogParser::parse( $contents )[0] );

		$store = new MuteStore();
		$store->add( $noisy_signature, 'noise', 1 );

		$repo = $this->repo_with_mute_store( $store );

		$query  = new LogQuery(
			null,
			null,
			null,
			null,
			null,
			false,
			1,
			50,
			null,
			true
		);
		$result = $repo->query( $query );

		$this->assertCount( 2, $result->items );
	}

	public function test_repository_without_mute_store_skips_filtering(): void {
		$contents = "[27-Apr-2026 12:00:00 UTC] PHP Fatal error:  whatever in /var/www/x.php:10\n";
		$this->write_log( $contents );

		// Default constructor: $mute_store is null, so filtering is a no-op
		// even when LogQuery::$include_muted is false.
		$result = $this->repo->query( $this->query() );

		$this->assertCount( 1, $result->items );
	}

	public function test_ungrouped_results_are_newest_first(): void {
		$lines = array(
			'[27-Apr-2026 12:00:00 UTC] PHP Notice:  oldest in /var/www/x.php on line 1',
			'[27-Apr-2026 12:00:01 UTC] PHP Notice:  middle in /var/www/x.php on line 1',
			'[27-Apr-2026 12:00:02 UTC] PHP Notice:  newest in /var/www/x.php on line 1',
		);
		$this->write_log( implode( "\n", $lines ) );

		$result = $this->repo->query( $this->query() );

		$this->assertStringContainsString( 'newest', $result->items[0]->message );
		$this->assertStringContainsString( 'oldest', $result->items[2]->message );
	}

	private function write_log( string $contents ): void {
		file_put_contents( $this->log_path, $contents );
	}

	/**
	 * Builds a fresh repository wired with the given mute store. Caller
	 * is responsible for stubbing `get_option` / `update_option` /
	 * `wp_strip_all_tags` if the store has not already been seeded.
	 *
	 * @param MuteStore $store Pre-populated store.
	 */
	private function repo_with_mute_store( MuteStore $store ): LogRepository {
		$guard  = new PathGuard( array( $this->root ) );
		$source = new FileLogSource( $this->log_path, $guard );
		return new LogRepository( $source, $store );
	}

	/**
	 * Wires Brain Monkey aliases for the option layer + sanitiser
	 * `MuteStore` reaches into. Held in a per-test in-memory bucket so
	 * the asserts can call `add()` directly during arrange.
	 */
	private function stub_option_layer(): void {
		$values = array();

		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $fallback;
			}
		);

		Functions\when( 'update_option' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $autoload mirrors the WP signature so MuteStore::add(..., false) matches the alias.
			static function ( string $key, $value, $autoload = null ) use ( &$values ) {
				$values[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text ): string {
				return preg_replace( '/<[^>]+>/', '', $text ) ?? '';
			}
		);
	}

	private function query(): LogQuery {
		return new LogQuery( null, null, null, null, null, false, 1, 50 );
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

			if ( is_link( $path ) || is_file( $path ) ) {
				@unlink( $path );
				continue;
			}

			$this->rrmdir( $path );
		}

		@rmdir( $dir );
	}
}
