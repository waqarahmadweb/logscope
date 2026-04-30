<?php
/**
 * Unit tests for MuteStore.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Log;

use Brain\Monkey\Functions;
use Logscope\Log\MuteStore;
use Logscope\Tests\TestCase;

final class MuteStoreTest extends TestCase {

	/**
	 * Mutable in-memory option store wired through stubbed
	 * `get_option` / `update_option` aliases.
	 *
	 * @var array{values: array<string, mixed>}
	 */
	private array $store;

	protected function setUp(): void {
		parent::setUp();

		$this->store = array( 'values' => array() );

		Functions\when( 'get_option' )->alias(
			function ( string $key, $fallback = false ) {
				return array_key_exists( $key, $this->store['values'] ) ? $this->store['values'][ $key ] : $fallback;
			}
		);

		Functions\when( 'update_option' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $autoload mirrors the WP signature so MuteStore::add(..., false) matches the alias.
			function ( string $key, $value, $autoload = null ) {
				$this->store['values'][ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text ): string {
				return preg_replace( '/<[^>]+>/', '', $text ) ?? '';
			}
		);
	}

	public function test_add_persists_record_under_signature_key(): void {
		$store = new MuteStore();

		$store->add( 'sig-abc', 'Known noisy plugin', 7 );

		$persisted = $this->store['values'][ MuteStore::OPTION_KEY ];
		$this->assertArrayHasKey( 'sig-abc', $persisted );
		$this->assertSame( 'sig-abc', $persisted['sig-abc']['signature'] );
		$this->assertSame( 'Known noisy plugin', $persisted['sig-abc']['reason'] );
		$this->assertSame( 7, $persisted['sig-abc']['muted_by'] );
		$this->assertGreaterThan( 0, $persisted['sig-abc']['muted_at'] );
	}

	public function test_add_strips_html_from_reason(): void {
		$store = new MuteStore();

		$store->add( 'sig-abc', 'Noisy <script>alert(1)</script> plugin', 1 );

		$this->assertSame( 'Noisy alert(1) plugin', $this->store['values'][ MuteStore::OPTION_KEY ]['sig-abc']['reason'] );
	}

	public function test_add_updates_existing_record_in_place(): void {
		$store = new MuteStore();

		$store->add( 'sig-abc', 'first reason', 1 );
		$first = $this->store['values'][ MuteStore::OPTION_KEY ]['sig-abc'];

		$store->add( 'sig-abc', 'updated reason', 2 );
		$second = $this->store['values'][ MuteStore::OPTION_KEY ]['sig-abc'];

		$this->assertCount( 1, $this->store['values'][ MuteStore::OPTION_KEY ] );
		$this->assertSame( 'updated reason', $second['reason'] );
		$this->assertSame( 2, $second['muted_by'] );
		$this->assertGreaterThanOrEqual( $first['muted_at'], $second['muted_at'] );
	}

	public function test_add_ignores_empty_signature(): void {
		$store = new MuteStore();

		$store->add( '', 'whatever', 1 );

		$this->assertArrayNotHasKey( MuteStore::OPTION_KEY, $this->store['values'] );
	}

	public function test_add_clamps_negative_user_id_to_zero(): void {
		$store = new MuteStore();

		$store->add( 'sig-abc', 'reason', -42 );

		$this->assertSame( 0, $this->store['values'][ MuteStore::OPTION_KEY ]['sig-abc']['muted_by'] );
	}

	public function test_remove_deletes_record_and_reports_true(): void {
		$store = new MuteStore();
		$store->add( 'sig-abc', 'r', 1 );

		$this->assertTrue( $store->remove( 'sig-abc' ) );
		$this->assertArrayNotHasKey( 'sig-abc', $this->store['values'][ MuteStore::OPTION_KEY ] );
	}

	public function test_remove_returns_false_for_unknown_signature(): void {
		$store = new MuteStore();

		$this->assertFalse( $store->remove( 'sig-missing' ) );
	}

	public function test_list_returns_records_as_numeric_array(): void {
		$store = new MuteStore();
		$store->add( 'sig-a', 'one', 1 );
		$store->add( 'sig-b', 'two', 1 );

		$list = $store->list();

		$this->assertCount( 2, $list );
		$this->assertSame( array( 0, 1 ), array_keys( $list ) );
	}

	public function test_is_muted_reports_membership(): void {
		$store = new MuteStore();
		$store->add( 'sig-abc', 'r', 1 );

		$this->assertTrue( $store->is_muted( 'sig-abc' ) );
		$this->assertFalse( $store->is_muted( 'sig-other' ) );
		$this->assertFalse( $store->is_muted( '' ) );
	}

	public function test_signatures_returns_keys_only(): void {
		$store = new MuteStore();
		$store->add( 'sig-a', 'one', 1 );
		$store->add( 'sig-b', 'two', 1 );

		$this->assertSame( array( 'sig-a', 'sig-b' ), $store->signatures() );
	}

	public function test_load_drops_corrupted_entries(): void {
		$this->store['values'][ MuteStore::OPTION_KEY ] = array(
			'sig-good' => array(
				'signature' => 'sig-good',
				'reason'    => 'fine',
				'muted_at'  => 100,
				'muted_by'  => 1,
			),
			'sig-bad'  => 'not-an-array',
			''         => array( 'reason' => 'empty key' ),
		);

		$store = new MuteStore();

		$this->assertSame( array( 'sig-good' ), $store->signatures() );
	}

	public function test_load_returns_empty_when_option_is_not_array(): void {
		$this->store['values'][ MuteStore::OPTION_KEY ] = 'corrupted';

		$store = new MuteStore();

		$this->assertSame( array(), $store->list() );
		$this->assertFalse( $store->is_muted( 'anything' ) );
	}
}
