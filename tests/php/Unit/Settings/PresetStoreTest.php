<?php
/**
 * Unit tests for PresetStore.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use Logscope\Settings\PresetStore;
use Logscope\Tests\TestCase;

final class PresetStoreTest extends TestCase {

	/**
	 * Mutable in-memory user-meta store wired through stubbed
	 * `get_user_meta` / `update_user_meta` aliases.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta;

	protected function setUp(): void {
		parent::setUp();

		$this->meta = array();

		Functions\when( 'get_user_meta' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $single mirrors the WP signature.
			function ( int $user_id, string $key, bool $single = false ) {
				$row = $this->meta[ $user_id ][ $key ] ?? '';
				return $row;
			}
		);

		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ) {
				$this->meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
	}

	public function test_list_returns_empty_for_user_with_no_presets(): void {
		$store = new PresetStore();
		$this->assertSame( array(), $store->list( 1 ) );
	}

	public function test_save_persists_and_round_trips(): void {
		$store = new PresetStore();

		$store->save(
			1,
			'Akismet only',
			array(
				'severity' => array( 'fatal', 'fatal', '' ),
				'q'        => 'foo',
				'source'   => 'plugins/akismet',
				'viewMode' => 'grouped',
				'unknown'  => 'dropped',
			)
		);

		$list = $store->list( 1 );
		$this->assertCount( 1, $list );
		$this->assertSame( 'Akismet only', $list[0]['name'] );
		$this->assertSame( array( 'fatal' ), $list[0]['filters']['severity'] );
		$this->assertSame( 'foo', $list[0]['filters']['q'] );
		$this->assertSame( 'plugins/akismet', $list[0]['filters']['source'] );
		$this->assertSame( 'grouped', $list[0]['filters']['viewMode'] );
		$this->assertArrayNotHasKey( 'unknown', $list[0]['filters'] );
	}

	public function test_save_overwrites_existing_preset_by_name(): void {
		$store = new PresetStore();

		$store->save( 1, 'My preset', array( 'q' => 'first' ) );
		$store->save( 1, 'My preset', array( 'q' => 'second' ) );

		$list = $store->list( 1 );
		$this->assertCount( 1, $list );
		$this->assertSame( 'second', $list[0]['filters']['q'] );
	}

	public function test_save_trims_and_truncates_long_names(): void {
		$store = new PresetStore();

		$long = str_repeat( 'a', 200 );
		$store->save( 1, '   ' . $long . '   ', array() );

		$list = $store->list( 1 );
		$this->assertCount( 1, $list );
		$this->assertSame( PresetStore::MAX_NAME_LENGTH, strlen( $list[0]['name'] ) );
	}

	public function test_save_rejects_empty_name(): void {
		$store = new PresetStore();

		$this->assertFalse( $store->save( 1, '   ', array() ) );
		$this->assertSame( array(), $store->list( 1 ) );
	}

	public function test_save_rejects_non_positive_user_id(): void {
		$store = new PresetStore();

		$this->assertFalse( $store->save( 0, 'name', array() ) );
		$this->assertFalse( $store->save( -1, 'name', array() ) );
	}

	public function test_delete_removes_record_and_reports_true(): void {
		$store = new PresetStore();
		$store->save( 1, 'a', array() );
		$store->save( 1, 'b', array() );

		$this->assertTrue( $store->delete( 1, 'a' ) );
		$list = $store->list( 1 );
		$this->assertCount( 1, $list );
		$this->assertSame( 'b', $list[0]['name'] );
	}

	public function test_delete_returns_false_for_unknown_name(): void {
		$store = new PresetStore();
		$store->save( 1, 'a', array() );

		$this->assertFalse( $store->delete( 1, 'missing' ) );
	}

	public function test_list_drops_corrupt_entries(): void {
		$this->meta[1][ PresetStore::META_KEY ] = array(
			array(
				'name'    => 'good',
				'filters' => array( 'q' => 'x' ),
			),
			'not-an-array',
			array(
				'name'    => '',
				'filters' => array(),
			),
			// Missing name key entirely.
			array( 'filters' => array() ),
		);

		$store = new PresetStore();
		$list  = $store->list( 1 );

		$this->assertCount( 1, $list );
		$this->assertSame( 'good', $list[0]['name'] );
	}

	public function test_severity_filter_drops_non_string_values(): void {
		$store = new PresetStore();
		$store->save(
			1,
			'mixed',
			array( 'severity' => array( 'fatal', 42, null, 'warning' ) )
		);

		$list = $store->list( 1 );
		$this->assertSame( array( 'fatal', 'warning' ), $list[0]['filters']['severity'] );
	}

	public function test_array_typed_string_filter_is_dropped(): void {
		$store = new PresetStore();
		$store->save( 1, 'bad', array( 'q' => array( 'oops' ) ) );

		$list = $store->list( 1 );
		$this->assertArrayNotHasKey( 'q', $list[0]['filters'] );
	}
}
